<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-PREDICTION-RANK  順位・統合・公開候補
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 7章／詳細設計書 11.1・6章
 *
 *   ・帯の中の全適格馬が complete なら SCORE、partial を含むなら EVIDENCE。
 *     AIの申告で complete 帯を EVIDENCE に変えさせません。
 *     EVIDENCE では点数を順位キーに使いません。
 *   ・順位点 rank_score = 100 × (K − r + 1) ÷ K。K は凍結帯の頭数。
 *     統合点は両roleの最大値。
 *   ・両AIの検証済み原公開候補だと証明できる場合だけ +5。
 *   ・同点は「両AIの原公開一致あり」→「独立根拠群数の多い順」→「馬番の昇順」。
 *   ・統合後にライブ取消を除き、残った馬の凍結帯と active_count から
 *     構造配分を調整します。人気は詰め直しません。
 *   ・評価後の適格馬不足は D は B のみ、B は D のみ、A は B→D。
 *     B・D の不足を A で埋めません。
 */
final class BaganrikiRanking
{
    /**
     * 順位点 100×(K−r+1)÷K。K は凍結帯の頭数。
     *
     * @ANCHOR-20261020-PREDICTION-EXACT  10月20日の確認結果②への対応。
     *   K が 3 や 7 のとき 100×(K−r+1)÷K は割り切れません。
     *   float で持つと 33.333… のような値になり、
     *   統合点の大小や同点の判定が丸めに左右されます。
     *   分数（分子・分母）のまま持ち、比較はたすき掛けの整数で行います。
     *   下の rankScore() は画面と監査のための数値で、判定には使いません。
     */
    public static function rankScoreParts(int $K, int $r): array
    {
        if ($K <= 0) { return ['n' => 0, 'd' => 1]; }
        return ['n' => 100 * ($K - $r + 1), 'd' => $K];
    }

    /** 分数どうしの大小（-1 / 0 / 1）。分母は正の整数です */
    public static function cmpScore(array $a, array $b): int
    {
        return ($a['n'] * $b['d']) <=> ($b['n'] * $a['d']);
    }

    /** 分数へ整数を足す（+5 の加点など） */
    public static function addScore(array $a, int $plus): array
    {
        return ['n' => $a['n'] + $plus * $a['d'], 'd' => $a['d']];
    }

    /**
     * 両roleの順位から統合点を出します。
     * @param array $per  role => [馬番 => ['r'=>順位, 'K'=>帯頭数,
     *                              'public'=>bool（検証済み原公開候補か）,
     *                              'groups'=>独立根拠群数]]
     * @param array $proofHash role => 加点を証明する応答hash（無ければ null）
     */
    public static function merge(array $per, array $proofHash = []): array
    {
        // @ANCHOR-20261020-PREDICTION-EXACT  点は分数のまま持ちます（max も比較も整数で）
        $acc = [];
        foreach ($per as $role => $rows) {
            foreach ($rows as $num => $v) {
                $num = (int) $num;
                $p = self::rankScoreParts((int) $v['K'], (int) $v['r']);
                if (!isset($acc[$num])) {
                    $acc[$num] = ['num' => $num, 'parts' => $p, 'public_roles' => [],
                                  'groups' => (int) ($v['groups'] ?? 0), 'bonus' => 0.0,
                                  'public_proof_hash' => null];
                } else {
                    if (self::cmpScore($p, $acc[$num]['parts']) > 0) { $acc[$num]['parts'] = $p; }
                    $acc[$num]['groups'] = max($acc[$num]['groups'], (int) ($v['groups'] ?? 0));
                }
                if (!empty($v['public'])) { $acc[$num]['public_roles'][] = $role; }
            }
        }
        $roles = array_keys($per);
        foreach ($acc as $num => &$a) {
            // 両AIの検証済み原公開候補だと証明できたときだけ +5
            $both = true;
            foreach ($roles as $r) {
                if (!in_array($r, $a['public_roles'], true)) { $both = false; break; }
            }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-PROOF  証明が無ければ +5 しません
            // ══════════════════════════════════════════════════════════
            // 【10月20日005のご指摘5-2。そのとおりでした】
            //   これまでは「両roleで public=true」だけで +5 していて、
            //   <b>proofHash の有無も正当性も条件にしていませんでした。</b>
            //   検証済みの原応答から取った証明が、その馬について
            //   両roleぶんそろっているときだけ加点します。
            $proven = $both && count($roles) >= 2
                    && self::proofForHorse($proofHash, $roles, (int) $num) !== null;
            if ($proven) {
                $a['bonus'] = 5.0;
                $a['parts'] = self::addScore($a['parts'], 5);
                $a['public_proof_hash'] = self::proofForHorse($proofHash, $roles, (int) $num);
            } elseif ($both && count($roles) >= 2) {
                $a['no_bonus_reason'] = '両roleの原公開を証明できないため加点しません';
            }
            $a['both_public'] = $both && count($roles) >= 2;
            $a['bonus_proven'] = $proven;
            // 表示用の数値（判定には使いません）。
            // PHPは割り切れると整数を返すので、表示用は必ず小数にそろえます。
            $a['score'] = (float) $a['parts']['n'] / (float) $a['parts']['d'];
        }
        unset($a);
        $rows = array_values($acc);
        usort($rows, static function (array $x, array $y): int {
            $c = self::cmpScore($y['parts'], $x['parts']);   // 点の高い順
            if ($c !== 0) { return $c; }
            if ($x['both_public'] !== $y['both_public']) { return $y['both_public'] <=> $x['both_public']; }
            if ($x['groups'] !== $y['groups']) { return $y['groups'] <=> $x['groups']; }
            return $x['num'] <=> $y['num'];
        });
        return $rows;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PROOF  その馬の「両roleの原公開」の証明を取り出す
     *
     * 受け取る形は2つとも通します。
     *   $proofHash[role][馬番] = 64桁の16進
     *   $proofHash[馬番][role] = 64桁の16進
     * 全roleぶんそろっていて、どれも64桁の16進のときだけ配列を返します。
     * 1つでも欠けていれば null を返し、呼び出し側は加点しません。
     */
    public static function proofForHorse($proofHash, array $roles, int $num): ?array
    {
        if (!is_array($proofHash) || $proofHash === []) { return null; }
        $out = [];
        foreach ($roles as $r) {
            $v = $proofHash[$r][$num] ?? ($proofHash[$num][$r] ?? null);
            if (!is_string($v) || !preg_match('/^[0-9a-f]{64}$/', $v)) { return null; }
            $out[$r] = $v;
        }
        return ($out === []) ? null : $out;
    }

    public static function fillShortfall(array $quota, array $avail): array
    {
        $q = ['A' => (int) $quota['A'], 'B' => (int) $quota['B'], 'D' => (int) $quota['D']];
        $short = [];
        $from = ['D' => ['B'], 'B' => ['D'], 'A' => ['B', 'D']];
        foreach (['D', 'B', 'A'] as $band) {
            $need = $q[$band] - (int) ($avail[$band] ?? 0);
            if ($need <= 0) { continue; }
            $moved = 0;
            foreach ($from[$band] as $to) {
                while ($moved < $need) {
                    $used = $q[$to];
                    if ($used >= (int) ($avail[$to] ?? 0)) { break; }
                    $q[$to]++; $q[$band]--; $moved++;
                }
            }
            $short[] = ['band' => $band, 'requested' => (int) $quota[$band],
                        'eligible' => (int) ($avail[$band] ?? 0),
                        'shortfall' => $need - $moved,
                        'moved_to' => $from[$band], 'moved' => $moved];
        }
        return ['quota' => $q, 'shortfalls' => $short];
    }

    /**
     * 公開期限前の取消を反映して、公開quotaを作り直します。
     * 人気は詰め直しません（凍結帯のまま）。
     *
     * @param array $frozenBand 馬番 => 'A'|'B'|'D'（凍結時の帯）
     * @param array $cancelled  取消の馬番
     * @param array $quota1st   凍結時の1st配分
     */
    public static function afterCancellation(array $frozenBand, array $cancelled,
                                             array $quota1st,
                                             ?string $allocationMode = null): array
    {
        $alive = [];
        foreach ($frozenBand as $num => $band) {
            if (in_array((int) $num, array_map('intval', $cancelled), true)) { continue; }
            $alive[$band] = ($alive[$band] ?? 0) + 1;
        }
        $avail = ['A' => $alive['A'] ?? 0, 'B' => $alive['B'] ?? 0, 'D' => $alive['D'] ?? 0];
        $active = array_sum($avail);

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-RECALC  最新の頭数から C と基本配分を引き直す
        // ══════════════════════════════════════════════════════════════
        // 【10月20日005のご指摘4。本物の不具合でした】
        //   これまでは旧 quota の不足移動しかしておらず、
        //   <b>頭数が減っても目標数 C と基本配分を引き直していませんでした。</b>
        //     16→15 … 3/3/1 のまま（正しくは 3/2/1）
        //     14→13 … 3/2/1 のまま（正しくは 3/2/0）
        //      9→8  … 3/2/0 のまま（正しくは 2/2/0）
        //   凍結帯は維持します（人気を詰め直しません）。
        //   引き直すのは「最新の頭数に応じた目標数と基本配分」だけです。
        $base = null; $moves = [];
        if ($allocationMode !== null) {
            $cap = BaganrikiAllocation::v04TargetCount($active);
            if ($cap <= 0) {
                // 全頭取消。候補を作りません。
                return ['active_count' => 0, 'band_alive' => $avail,
                        'target_count' => 0, 'base_quota' => null,
                        'live_quota' => null, 'quota_adjustments' => [],
                        'shortfalls' => [], 'all_cancelled' => true,
                        'note' => '全頭取消のため候補を作りません'];
            }
            $t = ($cap <= 3)
                ? [$cap, 0, 0]
                : (AiController::V04_QUOTA_BY_TARGET[$cap][$allocationMode] ?? null);
            if ($t !== null) {
                $base = ['A' => $t[0], 'B' => $t[1], 'D' => $t[2]];
                // 実在しない帯の枠を D→B→A の順に移します。
                // 実在数は「凍結帯で生き残っている頭数」で見ます。
                $q = [$t[0], $t[1], $t[2]];
                $cp = [$avail['A'], $avail['B'], $avail['D']];
                $idx = ['A' => 0, 'B' => 1, 'D' => 2];
                foreach ([['D', ['B', 'A']], ['B', ['D', 'A']], ['A', ['B', 'D']]] as $pair) {
                    [$from, $tos] = $pair;
                    $i = $idx[$from];
                    while ($q[$i] > $cp[$i]) {
                        $moved = false;
                        foreach ($tos as $to) {
                            $j = $idx[$to];
                            if ($q[$j] < $cp[$j]) {
                                $q[$i]--; $q[$j]++;
                                $moves[] = ['requested_band' => $from, 'actual_band' => $to,
                                            'count' => 1, 'reason' => 'BAND_CAPACITY'];
                                $moved = true; break;
                            }
                        }
                        if (!$moved) {
                            $q[$i]--;
                            $moves[] = ['requested_band' => $from, 'actual_band' => null,
                                        'count' => 1, 'reason' => 'BAND_CAPACITY'];
                        }
                    }
                }
                $quota1st = ['A' => $q[0], 'B' => $q[1], 'D' => $q[2]];
            }
        }

        $r = self::fillShortfall($quota1st, $avail);
        return ['active_count' => $active, 'band_alive' => $avail,
                'target_count' => ($allocationMode === null) ? null
                                  : BaganrikiAllocation::v04TargetCount($active),
                'base_quota' => $base,
                'live_quota' => $r['quota'], 'quota_adjustments' => $moves,
                'shortfalls' => $r['shortfalls'], 'all_cancelled' => false,
                'note' => '取消で人気を詰め直していません（凍結帯のまま）。'
                        . '目標数と基本配分は最新の頭数から引き直しています'];
    }
}

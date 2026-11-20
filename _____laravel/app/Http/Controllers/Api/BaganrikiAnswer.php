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
 * BaganrikiAnswer  新しい予想を画面の形にするときの部品（補充・理由文・状態）
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BaganrikiAnswer
{
    /**
     * @ANCHOR-20261108-PREDICTION-EARLYFILL  1st・2nd とも、使える馬を返せないまま終わったか
     *   終わった＝送信中・予約中の試行が無い。使えない＝失敗・結果不明、または受け取ったが採否確定で使える馬が0頭。
     *   どちらか一方でも使える馬があれば false（その場合は公開を待つ）。読めないときも false（今までどおり待つ）。
     */
    public static function predictionBothRolesFailed(int $snapshotId): bool
    {
        try {
            foreach (['1st', '2nd'] as $role) {
                $atts = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $snapshotId)
                          ->where('role', $role)->get(['status']);
                if ($atts->isEmpty()) { return false; }
                $received = false;
                foreach ($atts as $a) {
                    if (in_array($a->status, [BaganrikiWorker::RESERVED, BaganrikiWorker::SENT], true)) { return false; }
                    if ($a->status === BaganrikiWorker::RECEIVED) { $received = true; }
                }
                if ($received) {
                    $settled = \DB::table('baganriki_audit_events')->where('snapshot_id', $snapshotId)
                                 ->where('kind', 'R122_ROLE_SETTLED')
                                 ->where('diagnostics', 'like', '%"role":"' . $role . '"%')
                                 ->orderByDesc('id')->first(['diagnostics']);
                    if ($settled === null) { return false; }
                    $d = json_decode((string) $settled->diagnostics, true);
                    if (!is_array($d)) { return false; }
                    if (($d['resolve_ok'] ?? false) === true && (int) ($d['merged'] ?? 0) > 0) { return false; }
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 発走時刻（日本時間）をマイクロ秒で。読めなければ null */
    public static function predictionAnchorUs(string $date, string $st): ?int
    {
        $st = trim($st);
        if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $st)) { return null; }
        if (strlen($st) <= 5) { $st .= ':00'; }
        if (strlen($st) === 7) { $st = '0' . $st; }
        return BaganrikiTime::us($date . 'T' . $st . '+09:00');
    }

    /**
     * 新しい予想を出せないレース。旧方式の保存済みがあれば、それを旧方式の保存済みの返し方で見せます。
     *   'first' / 'second' が null の側は、呼び出し側が旧方式の処理へ進みます（保存済みを返すだけ）。
     *   保存済みが無い側は undetermined で返し、旧方式のAIは呼びません
     *   （2nd は統合結果まで保存済みのときだけ旧方式へ。1stだけ保存済みで2ndへ進むと DeepSeek を呼ぶため）。
     */
    public static function predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race, string $reason): array
    {
        $und = AiController::predictionUndetermined($date, $kaisuu, $basho, $day, $race, $reason);
        $has1 = \DB::table('t_horse_odds_finder_ai_analysis')
                  ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
                  ->where('day', $day)->where('race', $race)->exists();
        if (!$has1) { return $und; }
        $hasMerge = \DB::table('t_horse_odds_finder_ai_analysis2')
                      ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
                      ->where('day', $day)->where('race', $race)->exists()
                 && \DB::table('t_horse_odds_finder_ai_merge_result')
                      ->where('date', $date)->where('kaisuu', (int) $kaisuu)->where('basho_code', $basho)
                      ->where('day', (int) $day)->where('race', (int) $race)->exists();
        return ['state' => 'LEGACY_SAVED', 'first' => null, 'second' => $hasMerge ? null : $und['second']];
    }

    public static function predictionNotReady($date, $kaisuu, $basho, $day, $race, string $why): array
    {
        $base = ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day,
                 'race' => $race, 'analysis_text' => '', 'not_ready' => true,
                 'prediction_method' => 'new', 'prediction_state' => $why];
        return ['state' => $why, 'first' => $base, 'second' => $base];
    }

    public static function predictionUndetermined($date, $kaisuu, $basho, $day, $race, string $reason): array
    {
        $base = ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day,
                 'race' => $race, 'analysis_text' => '',
                 'prediction_method' => 'new', 'prediction_state' => 'UNAVAILABLE'];
        return ['state' => 'UNAVAILABLE', 'first' => $base,
                'second' => $base + ['merged_horses' => [], 'upset_race' => null, 'upset_race_status' => '不明',
                                     'status' => 'undetermined', 'status_reason' => $reason]];
    }

    /** 枠（A・B・D の頭数）を読みます。読めなければ全部0 */
    public static function predictionQuotaOf($pubQuota, $snapQuota): array
    {
        foreach ([$pubQuota, $snapQuota] as $raw) {
            $q = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (!is_array($q)) { continue; }
            if (isset($q[0])) { return ['A' => (int) $q[0], 'B' => (int) ($q[1] ?? 0), 'D' => (int) ($q[2] ?? 0)]; }
            if (array_key_exists('A', $q) || array_key_exists('B', $q) || array_key_exists('D', $q)) {
                return ['A' => (int) ($q['A'] ?? 0), 'B' => (int) ($q['B'] ?? 0), 'D' => (int) ($q['D'] ?? 0)];
            }
        }
        return ['A' => 0, 'B' => 0, 'D' => 0];
    }

    /**
     * 足りない頭数を、凍結した出走馬から人気順で選びます。
     * @param array $have すでに出す馬（'num','band' を持つ行）
     * @return array [['num' => 馬番, 'band' => 帯], …]
     */
    public static function predictionFillRows(array $in, array $quota, array $have, array $cancelled,
                                              array $aiPos = []): array
    {
        $pool = ['A' => [], 'B' => [], 'D' => []];
        $taken = [];
        foreach ($have as $h) { $taken[(int) $h['num']] = true; }
        foreach ((array) ($in['runners'] ?? []) as $r) {
            $no = (int) ($r['horse_no'] ?? 0);
            $b = (string) ($r['band'] ?? '');
            if ($no <= 0 || !isset($pool[$b]) || isset($taken[$no]) || in_array($no, $cancelled, true)) { continue; }
            $pool[$b][] = ['no' => $no, 'rank' => is_numeric($r['win_rank'] ?? null) ? (int) $r['win_rank'] : 99,
                           'ai' => $aiPos[$no] ?? 999];
        }
        foreach ($pool as $b => $list) {
            usort($list, static fn($x, $y) => ($x['ai'] <=> $y['ai']) ?: ($x['rank'] <=> $y['rank']) ?: ($x['no'] <=> $y['no']));
            $pool[$b] = $list;
        }
        $haveN = ['A' => 0, 'B' => 0, 'D' => 0];
        foreach ($have as $h) { $b = (string) ($h['band'] ?? ''); if (isset($haveN[$b])) { $haveN[$b]++; } }
        $out = [];
        // @ANCHOR-20261115-PREDICTION-REFILL  合計は枠の合計（頭数）を超えない
        //   11/15 京都12R：公開のときに D の不足を A の根拠のある馬で埋めていたため、帯ごとに数えると D が1頭足りず、
        //   補充で1頭足して 8頭（頭数7）になっていました。帯ごとの不足に加えて、全体の残りの数でも止めます。
        $room = max(0, array_sum(array_map('intval', $quota)) - count($have));
        $takeFrom = static function (string $b, int $n) use (&$pool, &$out, &$room): int {
            $got = 0;
            while ($got < $n && $room > 0 && $pool[$b] !== []) {
                $room--;
                $x = array_shift($pool[$b]);
                $out[] = ['num' => $x['no'], 'band' => $b];
                $got++;
            }
            return $n - $got;   // まだ足りない数
        };
        $order = ['A' => ['A', 'B', 'D'], 'B' => ['B', 'D'], 'D' => ['D', 'B']];
        foreach (['A', 'B', 'D'] as $b) {
            $need = max(0, (int) ($quota[$b] ?? 0) - $haveN[$b]);
            foreach ($order[$b] as $from) {
                if ($need <= 0) { break; }
                $need = $takeFrom($from, $need);
            }
        }
        return $out;
    }

    /** 出典一覧の数値（無い・数値でなければ null） */
    public static function predictionRegNum(array $reg, string $ref): ?float
    {
        $v = $reg[$ref]['value'] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }

    /** 選出理由（凍結した入力の値と、確かめた根拠の系統だけで作る決まった形の文） */
    public static function predictionReason(array $reg, int $no, array $families): string
    {
        $parts = [];
        $labels = [];
        foreach (array_keys(AiController::PREDICTION_FAMILY_LABEL) as $f) {
            if (in_array($f, $families, true)) { $labels[] = AiController::PREDICTION_FAMILY_LABEL[$f]; }
        }
        if ($labels !== []) {
            // @ANCHOR-20261108-PREDICTION-NOLABEL  「確かめた根拠：」の文字は出さない（11/7 豊田さんの指示）
            $parts[] = implode('、', $labels) . '（' . count($labels) . '系統）';
        }
        $fmt = static fn(?float $v) => ($v === null) ? null : sprintf('%.1f', $v);
        $w6  = $fmt(AiController::predictionRegNum($reg, 'h' . $no . '_win_odds_6'));
        $w21 = $fmt(AiController::predictionRegNum($reg, 'h' . $no . '_win_odds_21'));
        if ($w6 !== null) {
            $parts[] = '6分前の単勝 ' . $w6 . '倍' . ($w21 !== null ? '（21分前 ' . $w21 . '倍）' : '');
        }
        $p1 = $fmt(AiController::predictionRegNum($reg, 'h' . $no . '_place_min_6'));
        $p2 = $fmt(AiController::predictionRegNum($reg, 'h' . $no . '_place_max_6'));
        if ($p1 !== null && $p2 !== null) { $parts[] = '複勝 ' . $p1 . '〜' . $p2 . '倍'; }
        $fin = [];
        for ($i = 1; $i <= 3; $i++) {
            $v = $reg['h' . $no . '_past_finish_' . $i]['value'] ?? null;
            if (is_numeric($v)) { $fin[] = ((int) $v) . '着'; }
        }
        if ($fin !== []) { $parts[] = '近' . count($fin) . '走 ' . implode('・', $fin); }
        return ($parts === []) ? '' : implode('。', $parts) . '。';
    }
}

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
 * @ANCHOR-20261020-REFACTOR  v0.4-X / 新方式 の配分
 * ═══════════════════════════════════════════════════════════════════════════
 * ここに入っているもの
 *   ・目標頭数 C（N から決まる）
 *   ・3帯 quota(A/B/D) と、新方式 の構造調整（実在しない帯の枠を移す）
 *   ・2nd の配分
 *   ・6分前人気から帯（A=1〜6／B=7〜10／D=11以下）
 */
final class BaganrikiAllocation
{
    /** @ANCHOR-20261013-V04-CORE  候補数（付録F target_count と同一） */
    public static function v04TargetCount(int $N): int
    {
        // @ANCHOR-20261014-ABNORMAL-INPUT  負の頭数で例外を投げない
        //   異常入力試験（AB-T05）で見つかりました。出走頭数は呼び出し側で
        //   DB照合済みですが、例外が外へ出るとレースが止まります。
        //   0頭として扱い、\Log::error で表に出します。
        if ($N < 0) {
            \Log::error('[V04_TARGET] 出走頭数が負（@ANCHOR-20261014-ABNORMAL-INPUT）', [
                'N' => $N, '対処' => '0頭として扱う（目標数0・候補を作らない）',
            ]);
            return 0;
        }
        if ($N <= 8) return min($N, 4);
        if ($N <= 13) return 5;
        if ($N <= 15) return 6;
        return 7;
    }

    /**
     * @ANCHOR-20261013-V04-CORE  3帯 quota(A,B,D)（付録F allocation と同一）
     *
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-ALLOC  新方式（2026-10-20 受領）で足したこと
     * ═══════════════════════════════════════════════════════════════════════
     * ①<b>allocation_mode が null（UNRESOLVED）なら配分を作りません。</b>
     *   【旧】MIXED_PROVISIONAL を MIXED に読み替えて配分表を引いていました。
     *   新方式 は「その他の複数modeは UNRESOLVED、allocation_mode=null、
     *   selection_enabled=false」です。MIXEDの流用は禁止されました。
     * ②<b>構造上いない帯の枠を移します（構造調整）。</b>
     *   A=1〜6人気、B=7〜10、D=11以下。N が小さいと B や D に馬がいません。
     *   不足帯を D → B → A の順に処理し、D は B→A、B は D→A、A は B→D へ移します。
     *   移動は quota_adjustments に requested_band／actual_band／count／reason で残し、
     *   <b>reason=BAND_CAPACITY を省略しません</b>（契約5章）。
     *   例：N=10・VOLATILE なら C5 の基本 2/2/1 から D が無いので 2/3/0。
     * ③<b>2nd の配分を出します。</b>
     *   C<=5 は 1st と同じ。C>5 は B・D を保ったまま A=5−B−D、cap=5。
     *   （C7混戦・波乱の2ndが 1/3/1 になります）
     * ④N<=3 でも C/0/0 を返します（詳細設計書6章の表）。
     *   旧実装は quota=null でした。A帯の実在頭数が C なので意味は同じですが、
     *   表に合わせて値を返します。
     */
    public static function v04Allocation(int $N, array $classification): array
    {
        $cap  = self::v04TargetCount($N);
        $mode = $classification['allocation_mode'] ?? null;

        // ① 選出しない状態では配分を作りません
        if ($mode === null) {
            // @ANCHOR-20261020-PREDICTION-PROMPT  base_quota は INPUT_JSON の必須キー
            return ['target_count' => $cap, 'quota' => null, 'quota_2nd' => null,
                    'base_quota' => null,
                    'quota_adjustments' => [],
                    'quota_note'   => 'allocation_mode=null（UNRESOLVED）のため配分を作りません'
                                    . '（@ANCHOR-20261020-R122-ALLOC）',
                    '使用配分モード' => null];
        }
        if ($cap <= 0) {
            return ['target_count' => 0, 'quota' => null, 'quota_2nd' => null,
                    'base_quota' => null,
                    'quota_adjustments' => [],
                    'quota_note'   => 'N=0（全頭取消）のため候補を作りません',
                    '使用配分モード' => $mode];
        }
        if ($cap <= 3) {
            // ④ N<=3 は C/0/0
            $q3 = ['A' => $cap, 'B' => 0, 'D' => 0];
            return ['target_count' => $cap, 'quota' => $q3, 'quota_2nd' => $q3,
                    'base_quota' => $q3,
                    'quota_adjustments' => [],
                    'quota_note'   => 'N<=3: 実在馬の帯（A）だけで目標数まで',
                    '使用配分モード' => $mode];
        }
        $q = AiController::V04_QUOTA_BY_TARGET[$cap][$mode] ?? null;
        if ($q === null) {
            return ['target_count' => $cap, 'quota' => null, 'quota_2nd' => null,
                    'base_quota' => null,
                    'quota_adjustments' => [],
                    'quota_note'   => "配分表に target_count={$cap} / mode={$mode} が無い",
                    '使用配分モード' => $mode];
        }
        if (array_sum($q) !== $cap) {
            throw new \RuntimeException("quota sum mismatch: {$cap} vs " . array_sum($q));
        }
        // @ANCHOR-20261020-PREDICTION-PROMPT  構造調整の前の表の値を base_quota として残す
        $qBase = ['A' => $q[0], 'B' => $q[1], 'D' => $q[2]];

        // ② 構造調整（実在しない帯の枠を移す）
        [$q, $moves] = self::v04StructuralAdjust($N, $q);

        // ③ 2nd の配分
        $q2 = self::v04SecondQuota($N, $q);

        return [
            'target_count' => $cap,
            'quota'        => ['A' => $q[0], 'B' => $q[1], 'D' => $q[2]],
            'quota_2nd'    => ['A' => $q2[0], 'B' => $q2[1], 'D' => $q2[2]],
            'base_quota'   => $qBase,
            'quota_adjustments' => $moves,
            'quota_note'   => 'SHADOW ONLY; do not force Tier-C to fill physically impossible bands',
            '使用配分モード' => $mode,
        ];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-ALLOC  帯ごとの実在頭数（A=1〜6人気／B=7〜10／D=11以下）
     */
    public static function v04BandCapacity(int $N): array
    {
        return [max(0, min(6, $N)), max(0, min(10, $N) - 6), max(0, $N - 10)];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-ALLOC  構造上の実在馬不足だけを調整します。
     *   評価後の適格馬不足（D は B のみ、B は D のみ、A は B→D）は別の規則で、
     *   応答検証のあとに行います。ここでは混ぜません。
     *
     * @return array [調整後の[A,B,D], 移動の記録]
     */
    public static function v04StructuralAdjust(int $N, array $q): array
    {
        $cap   = self::v04BandCapacity($N);
        $idx   = ['A' => 0, 'B' => 1, 'D' => 2];
        $moves = [];
        foreach ([['D', ['B', 'A']], ['B', ['D', 'A']], ['A', ['B', 'D']]] as $pair) {
            [$from, $tos] = $pair;
            $i = $idx[$from];
            while ($q[$i] > $cap[$i]) {
                $moved = false;
                foreach ($tos as $to) {
                    $j = $idx[$to];
                    if ($q[$j] < $cap[$j]) {
                        $q[$i]--; $q[$j]++;
                        $moves[] = ['requested_band' => $from, 'actual_band' => $to,
                                    'count' => 1, 'reason' => 'BAND_CAPACITY'];
                        $moved = true;
                        break;
                    }
                }
                if (!$moved) {
                    // 移す先も無い（全体の実在頭数が目標数に届かない）
                    $q[$i]--;
                    $moves[] = ['requested_band' => $from, 'actual_band' => null,
                                'count' => 1, 'reason' => 'BAND_CAPACITY'];
                }
            }
        }
        return [$q, $moves];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-ALLOC  2ndの配分
     *   C<=5 は 1st と同じ。C>5 は B・D を保ったまま A=5−B−D、cap=5。
     */
    public static function v04SecondQuota(int $N, array $q1): array
    {
        if (self::v04TargetCount($N) <= 5) { return $q1; }
        $a = 5 - $q1[1] - $q1[2];
        if ($a < 0) { $a = 0; }
        return [$a, $q1[1], $q1[2]];
    }

    /** @ANCHOR-20261013-V04-CORE  6分前人気 → 帯（A=1〜6／B=7〜10／D=11以下） */
    public static function v04Band(int $pop): string
    {
        if ($pop >= 1 && $pop <= 6)  return 'A';
        if ($pop >= 7 && $pop <= 10) return 'B';
        return 'D';
    }
}

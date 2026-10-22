<?php
// AiController.php から機械的に抜き出した r12.2 の判定（検証用）
class Log { public static function error($m,$c=[]){} }
final class AC {
public const V04_SIGNAL_WEIGHTS = [
        'X1' => 2, 'X2' => 1, 'X3' => 1, 'X4' => 1, 'X5' => 1, 'X6' => 1, 'Z' => -2,
    ];
    public const V04_MODE_FIRM_MAX   = 1;   // S<=1 → FIRM
    public const V04_MODE_MIXED_MAX  = 3;   // 2<=S<=3 → MIXED、S>=4 → VOLATILE
    /**
     * 不明が3信号以上なら mode=UNKNOWN、という旧規則の閾値。
     *
     * @ANCHOR-20261020-R122-MODE  r12.2 では使いません。
     *   r12.2 は「可能なmodeが一つならそのmode、複数ならUNKNOWN」だけで決めます
     *   （server_contract_r12.2 5章／詳細設計書5.2）。不明の件数は条件に入りません。
     *   定数は旧ログとの照合のために残します。
     */
    public const V04_UNKNOWN_COUNT_AT_LEAST = 3;


    public const V04_QUOTA_BY_TARGET = [
        7 => ['FIRM' => [5, 2, 0], 'MIXED' => [3, 3, 1], 'VOLATILE' => [3, 3, 1]],
        6 => ['FIRM' => [4, 2, 0], 'MIXED' => [3, 2, 1], 'VOLATILE' => [3, 2, 1]],
        5 => ['FIRM' => [4, 1, 0], 'MIXED' => [3, 2, 0], 'VOLATILE' => [2, 2, 1]],
        4 => ['FIRM' => [3, 1, 0], 'MIXED' => [2, 2, 0], 'VOLATILE' => [2, 1, 1]],
    ];


    public static function v04ScoreMode(int $s): string
    {
        if ($s <= self::V04_MODE_FIRM_MAX)  return 'FIRM';
        if ($s <= self::V04_MODE_MIXED_MAX) return 'MIXED';
        return 'VOLATILE';
    }


    public static function v04Classify(array $flags): array
    {
        $w = self::V04_SIGNAL_WEIGHTS;
        if (array_keys($w) !== array_keys($flags)) {
            // 付録Fの assert 相当。信号キーの取り違えを早い段階で落とす。
            $flags = array_merge(array_fill_keys(array_keys($w), 'UNKNOWN'),
                                 array_intersect_key($flags, $w));
        }
        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-ABNORMAL-INPUT  知らない値で例外を投げない
        // ══════════════════════════════════════════════════════════════════
        // 【旧実装の問題】
        //   TRUE / FALSE / UNKNOWN 以外の値が1つでも入ると
        //   InvalidArgumentException を投げていました。
        //   10月14日（5回目）のご指摘で追加した異常入力試験（AB-T02）で
        //   見つかりました。信号は v04DeriveFlags() が作るので通常は
        //   3値しか入りませんが、例外が外へ出るとレースが止まります。
        //   「候補不足や欠損でレースを止めない」という方針
        //   （@ANCHOR-20260928-NO-RACE-STOP）に反します。
        // 【この変更】
        //   知らない値は【不明（UNKNOWN）】に倒し、\Log::error で必ず表に出します。
        //   仕様の禁止事項「不明値を0点・不振・条件未達として扱うこと」を守るため、
        //   FALSE には倒しません。例外は投げません。
        $badTri = [];
        foreach ($flags as $k => $v) {
            if (!in_array($v, ['TRUE', 'FALSE', 'UNKNOWN'], true)) {
                $badTri[$k] = var_export($v, true);
                $flags[$k]  = 'UNKNOWN';
            }
        }
        if (!empty($badTri)) {
            \Log::error('[V04_CLASSIFY] 知らない信号値を不明として扱った（@ANCHOR-20261014-ABNORMAL-INPUT）', [
                '対象'   => $badTri,
                '対処'   => 'UNKNOWNへ倒した（FALSEには倒さない）',
                '影響'   => 'mode=UNKNOWN になり、配分はMIXED_PROVISIONALで記録のみ',
            ]);
        }

        $unknown = [];
        $fixed   = 0;
        foreach ($flags as $k => $v) {
            if ($v === 'UNKNOWN') { $unknown[] = $k; continue; }
            if ($v === 'TRUE')    $fixed += $w[$k];
        }

        $sMin = $fixed; $sMax = $fixed;
        foreach ($unknown as $k) {
            // ZのUNKNOWNはマイナス方向に作用する → S_min では Z=1、S_max では Z=0
            $sMin += min(0, $w[$k]);
            $sMax += max(0, $w[$k]);
        }

        // 不明の全組合せ（2^u、u<=7なので最大128通り）で判定が揺れるか確認する
        $modes = [];
        $u     = count($unknown);
        for ($mask = 0; $mask < (1 << $u); $mask++) {
            $val = $fixed;
            foreach ($unknown as $i => $k) { if ($mask & (1 << $i)) $val += $w[$k]; }
            $modes[self::v04ScoreMode((int) $val)] = true;
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-R122-MODE  r12.2 の mode 判定（2026-10-20 受領の指定）
        // ══════════════════════════════════════════════════════════════════
        // 【旧実装】
        //   $mode = ($u >= self::V04_UNKNOWN_COUNT_AT_LEAST || count($modes) > 1)
        //       ? 'UNKNOWN' : array_key_first($modes);
        //   'allocation_mode' => ($mode === 'UNKNOWN') ? 'MIXED_PROVISIONAL' : $mode,
        //
        //   ①「不明が3信号以上なら UNKNOWN」は r12.2 の条件にありません。
        //   ②「UNKNOWN なら配分は MIXED を流用」は r12.2 が明確に禁止しています。
        //      配分用modeを使えるのは、不明がX6だけで S0 が 1 か 3 のときだけです。
        //      それ以外の UNKNOWN は allocation_mode=null・選出しない（UNRESOLVED）。
        //
        // 【r12.2 の規則】server_contract_r12.2 5章／詳細設計書5.2
        //   可能なmodeが一つ            → そのmode / CONFIRMED   / 配分も同じmode
        //   不明がX6だけで S0=1         → UNKNOWN  / PROVISIONAL / 配分MIXED
        //   不明がX6だけで S0=3         → UNKNOWN  / PROVISIONAL / 配分VOLATILE
        //   それ以外で複数modeにまたがる → UNKNOWN  / UNRESOLVED  / 配分なし・選出しない
        //
        //   S0 は X6 を除いた重み付き合計。X6以外が全部確定しているときだけ出せます。
        //   PROVISIONAL では星（display_rating）を出しません。
        $mode = (count($modes) === 1) ? array_key_first($modes) : 'UNKNOWN';

        // S0 … X6 以外が全部確定しているときだけ値になります
        $s0 = 0; $s0ok = true;
        foreach ($flags as $k => $v) {
            if ($k === 'X6') continue;
            if ($v === 'UNKNOWN') { $s0ok = false; break; }
            if ($v === 'TRUE')    $s0 += $w[$k];
        }
        $S0 = $s0ok ? $s0 : null;

        $onlyX6 = (count($unknown) === 1 && $unknown[0] === 'X6');

        if (count($modes) === 1) {
            $modeStatus = 'CONFIRMED';
            $allocMode  = $mode;
            $policyId   = 'BASE_TRI_STATE_V1';
            $selEnabled = true;
        } elseif ($onlyX6 && ($S0 === 1 || $S0 === 3)) {
            $modeStatus = 'PROVISIONAL';
            $allocMode  = ($S0 === 1) ? 'MIXED' : 'VOLATILE';
            $policyId   = 'X6_ONLY_UPPER_MODE_V1';
            $selEnabled = true;
        } else {
            $modeStatus = 'UNRESOLVED';
            $allocMode  = null;
            $policyId   = 'BASE_TRI_STATE_V1';
            $selEnabled = false;
        }

        // 星は、取り得る S が全部同じ星になるときだけ出します。
        // PROVISIONAL は契約で null 固定です。
        $stars = [];
        for ($mask = 0; $mask < (1 << $u); $mask++) {
            $val = $fixed;
            foreach ($unknown as $i => $k) { if ($mask & (1 << $i)) $val += $w[$k]; }
            $stars[self::v04StarOfS((int) $val)] = true;
        }
        $displayRating = ($modeStatus === 'CONFIRMED' && count($stars) === 1)
            ? array_key_first($stars) : null;

        return [
            'mode'             => $mode,
            'S'               => empty($unknown) ? $fixed : null,
            'S_min'            => $sMin,
            'S_max'            => $sMax,
            'S0'               => $S0,
            'unknown_features' => $unknown,
            'mode_has_unknown' => !empty($unknown),
            // @ANCHOR-20261020-R122-MODE  配分に使うmode。停止時は null です。
            'allocation_mode'  => $allocMode,
            'mode_status'      => $modeStatus,
            'mode_policy_id'   => $policyId,
            'selection_enabled' => $selEnabled,
            'display_rating'   => $displayRating,
            '取り得るmode'     => array_keys($modes),
        ];
    }

    /**
     * @ANCHOR-20261020-R122-MODE  S の値から星（1〜5）
     *   r12.1 の検証経路（SummaryAiAnalysisCompensate::starOfS）と同じ対応です。
     */
    public static function v04StarOfS(int $s): int
    {
        if ($s <= 0)  return 1;
        if ($s === 1) return 2;
        if ($s <= 3)  return 3;
        if ($s <= 5)  return 4;
        return 5;
    }


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


    public static function v04Allocation(int $N, array $classification): array
    {
        $cap  = self::v04TargetCount($N);
        $mode = $classification['allocation_mode'] ?? null;

        // ① 選出しない状態では配分を作りません
        if ($mode === null) {
            return ['target_count' => $cap, 'quota' => null, 'quota_2nd' => null,
                    'quota_adjustments' => [],
                    'quota_note'   => 'allocation_mode=null（UNRESOLVED）のため配分を作りません'
                                    . '（@ANCHOR-20261020-R122-ALLOC）',
                    '使用配分モード' => null];
        }
        if ($cap <= 0) {
            return ['target_count' => 0, 'quota' => null, 'quota_2nd' => null,
                    'quota_adjustments' => [],
                    'quota_note'   => 'N=0（全頭取消）のため候補を作りません',
                    '使用配分モード' => $mode];
        }
        if ($cap <= 3) {
            // ④ N<=3 は C/0/0
            $q3 = ['A' => $cap, 'B' => 0, 'D' => 0];
            return ['target_count' => $cap, 'quota' => $q3, 'quota_2nd' => $q3,
                    'quota_adjustments' => [],
                    'quota_note'   => 'N<=3: 実在馬の帯（A）だけで目標数まで',
                    '使用配分モード' => $mode];
        }
        $q = self::V04_QUOTA_BY_TARGET[$cap][$mode] ?? null;
        if ($q === null) {
            return ['target_count' => $cap, 'quota' => null, 'quota_2nd' => null,
                    'quota_adjustments' => [],
                    'quota_note'   => "配分表に target_count={$cap} / mode={$mode} が無い",
                    '使用配分モード' => $mode];
        }
        if (array_sum($q) !== $cap) {
            throw new \RuntimeException("quota sum mismatch: {$cap} vs " . array_sum($q));
        }

        // ② 構造調整（実在しない帯の枠を移す）
        [$q, $moves] = self::v04StructuralAdjust($N, $q);

        // ③ 2nd の配分
        $q2 = self::v04SecondQuota($N, $q);

        return [
            'target_count' => $cap,
            'quota'        => ['A' => $q[0], 'B' => $q[1], 'D' => $q[2]],
            'quota_2nd'    => ['A' => $q2[0], 'B' => $q2[1], 'D' => $q2[2]],
            'quota_adjustments' => $moves,
            'quota_note'   => 'SHADOW ONLY; do not force Tier-C to fill physically impossible bands',
            '使用配分モード' => $mode,
        ];
    }

    /**
     * @ANCHOR-20261020-R122-ALLOC  帯ごとの実在頭数（A=1〜6人気／B=7〜10／D=11以下）
     */
    public static function v04BandCapacity(int $N): array
    {
        return [max(0, min(6, $N)), max(0, min(10, $N) - 6), max(0, $N - 10)];
    }

    /**
     * @ANCHOR-20261020-R122-ALLOC  構造上の実在馬不足だけを調整します。
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
     * @ANCHOR-20261020-R122-ALLOC  2ndの配分
     *   C<=5 は 1st と同じ。C>5 は B・D を保ったまま A=5−B−D、cap=5。
     */
    public static function v04SecondQuota(int $N, array $q1): array
    {
        if (self::v04TargetCount($N) <= 5) { return $q1; }
        $a = 5 - $q1[1] - $q1[2];
        if ($a < 0) { $a = 0; }
        return [$a, $q1[1], $q1[2]];
    }


}

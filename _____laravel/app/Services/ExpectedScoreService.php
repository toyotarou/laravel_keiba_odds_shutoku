<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * ExpectedScoreService
 *
 * 20261028 追加：よっしー資料「馬眼力_改良期待数値_数式と計算例_v0.1_20261025.zip」（v0.1.4）の
 * ExpectedValueV01.php を、このプロジェクトの名前の付け方（App\Services\〇〇Service）に合わせて置いたもの。
 * 変えたのは namespace とクラス名の2行だけで、中身（数式・検査・定数）は資料のまま。
 * 呼び出し元：app/Console/Commands/SummaryExpectedScore.php
 *
 * 馬眼力 改良期待数値 v0.1 の純粋な数式部分。
 * Laravel への組込み、DB取得、時刻検証、候補抽出、校正係数の学習は呼出側の責務。
 * 既存の AiController や r12.2 の選出・JSON Schema を書き換えない。
 * PHP 8.0+ 用の参考実装。実装修正版 v0.1.4。PHP実機では未実行。
 */
final class ExpectedScoreService
{
    /**
     * odds6_by_rank は6分前単勝オッズの昇順リスト。
     * odds21_at_rank は「6分前順位が $rank の同じ馬」の21分前オッズ。
     * 同値時の順位、時刻、取消の検査はアダプターで先に実施する。
     * parameters.json は説明用。以下の定数はv0.1固定で、JSONを自動読込しない。
     */
    public static function distanceSquared(array $current, array $historical, $rank): float
    {
        if (!is_int($rank)) {
            throw new InvalidArgumentException('rank must be an integer without coercion.');
        }
        $allowedClasses = ['NEW', 'MAIDEN', 'C1', 'C2', 'C3', 'OP', 'L', 'G3', 'G2', 'G1'];
        foreach ([$current, $historical] as $row) {
            if (!isset($row['odds6_by_rank'], $row['odds21_at_rank'], $row['distance_metres'],
                       $row['class_group'], $row['surface'], $row['race_type'])
                || !is_array($row['odds6_by_rank']) || count($row['odds6_by_rank']) === 0
                || array_keys($row['odds6_by_rank']) !== range(0, count($row['odds6_by_rank']) - 1)
                || !in_array($row['class_group'], $allowedClasses, true)
                || !in_array($row['surface'], ['TURF', 'DIRT'], true)
                || $row['race_type'] !== 'FLAT') {
                throw new InvalidArgumentException('Invalid feature row.');
            }
            $previous = 0.0;
            foreach ($row['odds6_by_rank'] as $odd) {
                if (!is_numeric($odd) || !is_finite((float) $odd) || (float) $odd <= 0.0
                    || (float) $odd < $previous) {
                    throw new InvalidArgumentException('Odds must be finite, positive and sorted.');
                }
                $previous = (float) $odd;
            }
            foreach (['odds21_at_rank', 'distance_metres'] as $key) {
                if (!is_numeric($row[$key]) || !is_finite((float) $row[$key]) || (float) $row[$key] <= 0.0) {
                    throw new InvalidArgumentException('Invalid positive feature.');
                }
            }
        }
        $n = count($current['odds6_by_rank']);
        if ($n !== count($historical['odds6_by_rank']) || $rank < 1 || $rank > $n
            || $current['surface'] !== $historical['surface']
            || $current['race_type'] !== $historical['race_type']) {
            throw new InvalidArgumentException('Incompatible race or rank.');
        }
        $c = array_map('floatval', $current['odds6_by_rank']);
        $h = array_map('floatval', $historical['odds6_by_rank']);
        $profile = 0.0;
        for ($r = 0; $r < $n - 1; $r++) {
            $difference = (log($c[$r + 1]) - log($c[$r])) - (log($h[$r + 1]) - log($h[$r]));
            $profile += ($difference / 0.35) ** 2;
        }
        $profile = $n > 1 ? $profile / ($n - 1) : 0.0;
        $idx = $rank - 1;
        $price = ((log($c[$idx]) - log($h[$idx])) / 0.70) ** 2;
        $cMove = log((float) $current['odds21_at_rank']) - log($c[$idx]);
        $hMove = log((float) $historical['odds21_at_rank']) - log($h[$idx]);
        $move = (($cMove - $hMove) / 0.35) ** 2;
        $distance = (((float) $current['distance_metres'] - (float) $historical['distance_metres']) / 400.0) ** 2;
        $class = $current['class_group'] === $historical['class_group'] ? 0.0 : 1.0;
        $d2 = ($profile + $price + $move + $distance + $class) / 5.0;
        if (!is_finite($d2)) {
            throw new InvalidArgumentException('Non-finite distance result.');
        }
        return $d2;
    }

    public static function baseline($m, $b3, $b5): array
    {
        // Check the original values even when the caller uses weak typing.
        if (!is_int($m) || !is_int($b3) || !is_int($b5)
            || $m < 0 || $b3 < 0 || $b3 > $b5 || $b5 > $m) {
            throw new InvalidArgumentException('Require 0 <= b3 <= b5 <= m.');
        }
        return [
            'm' => $m, 'b3' => $b3, 'b5' => $b5,
            'p0_3' => $m === 0 ? null : ($b3 + 1.0) / ($m + 2.0),
            'p0_5' => $m === 0 ? null : ($b5 + 1.0) / ($m + 2.0),
        ];
    }

    public static function weight(
        $distanceSquared, $ageDays,
        $bandwidth = 1.0, $halfLifeDays = 365.0
    ): float {
        foreach ([$distanceSquared, $ageDays, $bandwidth, $halfLifeDays] as $v) {
            if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v)) {
                throw new InvalidArgumentException('Non-finite weight input.');
            }
        }
        if ($distanceSquared < 0.0 || $ageDays < 0.0
            || $bandwidth <= 0.0 || $halfLifeDays <= 0.0) {
            throw new InvalidArgumentException('Invalid weight parameter.');
        }
        // Squaring a tiny positive bandwidth can underflow to zero.
        // A standardized distance avoids that zero denominator.
        $standardized = sqrt($distanceSquared) / $bandwidth;
        return exp(-0.5 * $standardized * $standardized
            - log(2.0) * ($ageDays / $halfLifeDays));
    }

    private static function calibrate(float $p, float $a, float $b): float
    {
        $p = max(0.000001, min(0.999999, $p));
        $z = $a * log($p / (1.0 - $p)) + $b;
        if ($z >= 0.0) {
            return 1.0 / (1.0 + exp(-$z));
        }
        $e = exp($z);
        return $e / (1.0 + $e);
    }

    /** Neumaier compensated sum of at most 200 validated, nonnegative terms. */
    private static function compensatedSum(array $values): float
    {
        $sum = 0.0;
        $correction = 0.0;
        foreach ($values as $value) {
            $next = $sum + $value;
            if ($sum >= $value) {
                $correction += ($sum - $next) + $value;
            } else {
                $correction += ($value - $next) + $sum;
            }
            $sum = $next;
        }
        return $sum + $correction;
    }

    /**
     * $samples: 選択済み近傍。各要素に race_id:string, w:float, y3:int, y5:int。
     * 同じrace_idは1回だけ。3着内なら必ず5着内でもある。
     * $calibration: null または ['a'=>正数,'b'=>有限数]。
     * 実データへの適用時は別途、版・学習終了時刻・対象範囲を照合する。
     */
    public static function calculate(
        $m, $b3, $b5, array $samples,
        $lambda = 20.0, ?array $calibration = null
    ): array {
        if ((!is_int($lambda) && !is_float($lambda))
            || !is_finite((float) $lambda) || $lambda <= 0.0) {
            throw new InvalidArgumentException('lambda must be finite and > 0.');
        }
        $lambda = (float) $lambda;
        $base = self::baseline($m, $b3, $b5);
        if (count($samples) > $m || count($samples) > 200) {
            throw new InvalidArgumentException('Neighbour count exceeds pool or K=200.');
        }
        $a = 1.0;
        $b = 0.0;
        if ($calibration !== null) {
            if (!isset($calibration['a'], $calibration['b'])
                || !is_numeric($calibration['a']) || !is_numeric($calibration['b'])) {
                throw new InvalidArgumentException('Invalid calibration coefficients.');
            }
            $a = (float) $calibration['a'];
            $b = (float) $calibration['b'];
            if (!is_finite($a) || !is_finite($b)
                || $a < 0.1 || $a > 10.0 || $b < -10.0 || $b > 10.0) {
                throw new InvalidArgumentException('Calibration coefficients out of draft bounds.');
            }
        }
        $seen = [];
        $positiveCount = 0;
        $sampleY3 = 0;
        $sampleY5 = 0;
        $positiveSamples = [];
        $maxWeight = 0.0;
        foreach ($samples as $s) {
            if (!is_array($s) || !isset($s['race_id'], $s['w'], $s['y3'], $s['y5'])
                || !is_string($s['race_id']) || $s['race_id'] === ''
                || !is_numeric($s['w'])
                || !in_array($s['y3'], [0, 1], true)
                || !in_array($s['y5'], [0, 1], true)
                || $s['y3'] > $s['y5']) {
                throw new InvalidArgumentException('Invalid neighbour row.');
            }
            if (isset($seen[$s['race_id']])) {
                throw new InvalidArgumentException('Duplicate historical race_id.');
            }
            $seen[$s['race_id']] = true;
            $sampleY3 += $s['y3'];
            $sampleY5 += $s['y5'];
            $w = (float) $s['w'];
            if (!is_finite($w) || $w < 0.0 || $w > 1.0) {
                throw new InvalidArgumentException('Weight must be between 0 and 1.');
            }
            if ($w <= 0.0) {
                continue;
            }
            $positiveCount++;
            $positiveSamples[] = [$w, $s['y3'], $s['y5']];
            $maxWeight = max($maxWeight, $w);
        }
        if ($sampleY3 > $b3 || ($sampleY5 - $sampleY3) > ($b5 - $b3)
            || (count($samples) - $sampleY5) > ($m - $b5)) {
            throw new InvalidArgumentException('Neighbour outcomes are inconsistent with the baseline pool.');
        }
        // Scale only for stable diagnostics/weighted means; W is NOT normalized.
        $weights = [];
        $successes3 = [];
        $successes5 = [];
        $scaledWeights = [];
        $scaledSquares = [];
        $scaledSuccesses3 = [];
        $scaledSuccesses5 = [];
        foreach ($positiveSamples as [$w, $y3, $y5]) {
            $scaled = $w / $maxWeight;
            $weights[] = $w;
            $successes3[] = $w * $y3;
            $successes5[] = $w * $y5;
            $scaledWeights[] = $scaled;
            $scaledSquares[] = $scaled * $scaled;
            $scaledSuccesses3[] = $scaled * $y3;
            $scaledSuccesses5[] = $scaled * $y5;
        }
        // Ordinary += can make 100 * 0.1 fall below the W=10 support boundary.
        $wSum = self::compensatedSum($weights);
        $a3 = self::compensatedSum($successes3);
        $a5 = self::compensatedSum($successes5);
        $scaledSum = self::compensatedSum($scaledWeights);
        $scaledSquaredSum = self::compensatedSum($scaledSquares);
        $scaledA3 = self::compensatedSum($scaledSuccesses3);
        $scaledA5 = self::compensatedSum($scaledSuccesses5);
        $applyCalibration = $calibration !== null && $m > 0 && $wSum > 0.0;
        $share = $wSum / ($wSum + $lambda);
        // Do not use 1-share: it loses tiny positive prior contributions.
        $priorShare = $lambda / ($wSum + $lambda);
        $out = [
            'formula_version' => 'baganriki-expected-v0.1-draft-20261025',
            'implementation_version' => 'baganriki-expected-impl-v0.1.4',
            'comparison_only' => true,
            'validated_for_live' => false,
            'baseline' => $base,
            'neighbour_count' => $positiveCount,
            'W' => $wSum, 'A3' => $a3, 'A5' => $a5,
            'n_eff' => $scaledSquaredSum > 0.0 ? $scaledSum * $scaledSum / $scaledSquaredSum : 0.0,
            'local_weight_share' => $share,
            'lambda' => $lambda,
            'calibration_applied' => $applyCalibration,
        ];
        if ($m === 0) {
            return array_merge($out, [
                'support_status' => 'UNAVAILABLE_BASELINE',
                'p3_raw' => null, 'p5_raw' => null,
                'p3_cal' => null, 'p5_cal' => null,
                'trial_score_3' => null, 'trial_score_5' => null,
                'score_basis' => 'UNAVAILABLE',
            ]);
        }
        // Algebraically the same shrinkage formula, without lambda*p0 underflow.
        $p3 = $wSum === 0.0 ? $base['p0_3']
            : $share * ($scaledA3 / $scaledSum) + $priorShare * $base['p0_3'];
        $p5 = $wSum === 0.0 ? $base['p0_5']
            : $share * ($scaledA5 / $scaledSum) + $priorShare * $base['p0_5'];
        // Valid nonnegative convex inputs can overshoot 1 by floating roundoff only.
        $p3 = min(1.0, max(0.0, $p3));
        $p5 = min(1.0, max(0.0, $p5));
        $q3 = $applyCalibration ? self::calibrate($p3, $a, $b) : null;
        $q5 = $applyCalibration ? self::calibrate($p5, $a, $b) : null;
        $status = 'COMPARISON_READY';
        if ($wSum === 0.0) {
            $status = 'BASELINE_ONLY';
        } elseif ($m < 100 || $positiveCount < 20 || $wSum < 10.0 || $out['n_eff'] < 15.0) {
            $status = 'LOW_SUPPORT';
        }
        return array_merge($out, [
            'support_status' => $status,
            'p3_raw' => $p3, 'p5_raw' => $p5,
            'p3_cal' => $q3, 'p5_cal' => $q5,
            'trial_score_3' => round(100.0 * ($q3 ?? $p3), 1, PHP_ROUND_HALF_UP),
            'trial_score_5' => round(100.0 * ($q5 ?? $p5), 1, PHP_ROUND_HALF_UP),
            'score_basis' => $applyCalibration ? 'CALIBRATION_APPLIED' : 'RAW_UNCALIBRATED',
        ]);
    }
}

<?php
declare(strict_types=1);

namespace App\Services\Baganriki;

use InvalidArgumentException;

/** Numeric API adapter, audit revision v0.1.4. No DB or production route changes. */
final class ExpectedScorePresenterV01
{
    public const DISPLAY_VERSION = 'baganriki-expected-display-v0.1.2';
    public const FORMULA_VERSION = 'baganriki-expected-v0.1-draft-20261025';
    public const IMPLEMENTATION_VERSION = 'baganriki-expected-impl-v0.1.4';
    private const AVAILABLE = ['COMPARISON_READY', 'LOW_SUPPORT', 'BASELINE_ONLY'];
    private const UNAVAILABLE = [
        'UNAVAILABLE_INPUT', 'UNAVAILABLE_BASELINE', 'OUT_OF_SCOPE', 'CALCULATION_ERROR', 'WITHDRAWN',
    ];

    private static function identity(array $identity): array
    {
        foreach (['race_id', 'horse_id', 'snapshot_id', 'frozen_at'] as $key) {
            if (!isset($identity[$key]) || !is_string($identity[$key])
                || trim($identity[$key]) === '' || trim($identity[$key]) !== $identity[$key]) {
                throw new InvalidArgumentException('Invalid identity: ' . $key);
            }
        }
        foreach (['horse_number', 'popularity_rank'] as $key) {
            if (!isset($identity[$key]) || !is_int($identity[$key])
                || $identity[$key] < 1 || $identity[$key] > 99) {
                throw new InvalidArgumentException('Invalid identity: ' . $key);
            }
        }
        if (($identity['source_minutes_before'] ?? null) !== 6
            || !in_array($identity['runner_status'] ?? null, ['ACTIVE', 'WITHDRAWN'], true)) {
            throw new InvalidArgumentException('Invalid snapshot slot or runner status.');
        }
        // Canonical JST. Syntax alone would accept 2026-02-30 or 25:61:00.
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+09:00\z/D',
                        $identity['frozen_at'], $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new InvalidArgumentException('frozen_at must be a real JST date/time.');
        }
        return [
            'race_id' => $identity['race_id'], 'horse_id' => $identity['horse_id'],
            'horse_number' => $identity['horse_number'], 'popularity_rank' => $identity['popularity_rank'],
            'snapshot_id' => $identity['snapshot_id'], 'source_minutes_before' => 6,
            'frozen_at' => $identity['frozen_at'], 'runner_status' => $identity['runner_status'],
        ];
    }

    private static function number($value, float $maximum): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || $value < 0 || $value > $maximum) {
            throw new InvalidArgumentException('Invalid numeric result.');
        }
        return $value == 0 ? 0.0 : (float) $value;
    }

    private static function score($value): float
    {
        $score = self::number($value, 100.0);
        $rounded = round($score, 1, PHP_ROUND_HALF_UP);
        if (abs($score - $rounded) > 1e-9) {
            throw new InvalidArgumentException('Score must already be rounded to 1 decimal.');
        }
        return $rounded;
    }

    /**
     * Bind the identity when calculating, before storing/retrieving the result.
     * ExpectedValueV01 must be loaded/autoloaded by the application.
     * The caller remains responsible for extracting the correct inputs for this horse.
     */
    public static function calculateForHorse(
        array $identity, $m, $b3, $b5, array $samples,
        $lambda = 20.0, ?array $calibration = null
    ): array {
        $source = self::identity($identity);
        if ($source['runner_status'] === 'WITHDRAWN') {
            $result = [
                'formula_version' => self::FORMULA_VERSION,
                'implementation_version' => self::IMPLEMENTATION_VERSION,
                'comparison_only' => true, 'validated_for_live' => false,
                'p3_raw' => null, 'p5_raw' => null, 'p3_cal' => null, 'p5_cal' => null,
                'trial_score_3' => null, 'trial_score_5' => null,
                'calibration_applied' => false, 'score_basis' => 'UNAVAILABLE',
                'support_status' => 'WITHDRAWN',
            ];
        } else {
            $result = ExpectedValueV01::calculate($m, $b3, $b5, $samples, $lambda, $calibration);
        }
        $result['source_identity'] = $source;
        return $result;
    }

    public static function unavailable(array $identity, string $status = 'UNAVAILABLE_INPUT'): array
    {
        $identity = self::identity($identity);
        if (!in_array($status, self::UNAVAILABLE, true)) {
            throw new InvalidArgumentException('Invalid unavailable status.');
        }
        if ($identity['runner_status'] === 'WITHDRAWN') $status = 'WITHDRAWN';
        elseif ($status === 'WITHDRAWN') throw new InvalidArgumentException('Runner status mismatch.');
        return array_merge($identity, [
            'display_schema_version' => self::DISPLAY_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'implementation_version' => self::IMPLEMENTATION_VERSION,
            'comparison_only' => true, 'validated_for_live' => false,
            'main_target' => 'TOP5', 'unit' => 'score_0_100',
            'expectation_value' => null, 'top3_expectation_value' => null,
            'expectation_value_text' => '—', 'top3_expectation_value_text' => '—',
            'score_basis' => 'UNAVAILABLE', 'support_status' => $status,
        ]);
    }

    public static function present(array $calculation, array $identity): array
    {
        $identity = self::identity($identity);
        if (($calculation['formula_version'] ?? null) !== self::FORMULA_VERSION
            || ($calculation['implementation_version'] ?? null) !== self::IMPLEMENTATION_VERSION
            || ($calculation['comparison_only'] ?? null) !== true
            || ($calculation['validated_for_live'] ?? null) !== false
            || !isset($calculation['source_identity']) || !is_array($calculation['source_identity'])) {
            throw new InvalidArgumentException('Unexpected calculation version, mode or missing source identity.');
        }
        $source = self::identity($calculation['source_identity']);
        foreach ($identity as $key => $value) {
            if ($key !== 'runner_status' && $source[$key] !== $value) {
                throw new InvalidArgumentException('Calculation belongs to another horse or snapshot.');
            }
        }
        // A cancellation after freeze invalidates display; never revive the stored numeric result.
        if ($identity['runner_status'] === 'WITHDRAWN') return self::unavailable($identity, 'WITHDRAWN');
        if ($source['runner_status'] !== 'ACTIVE') throw new InvalidArgumentException('Cannot revive a withdrawn result.');
        foreach (['trial_score_3', 'trial_score_5', 'p3_raw', 'p5_raw', 'p3_cal', 'p5_cal',
                  'calibration_applied', 'score_basis', 'support_status'] as $key) {
            if (!array_key_exists($key, $calculation)) throw new InvalidArgumentException('Missing calculation field: ' . $key);
        }
        $status = $calculation['support_status'];
        $basis = $calculation['score_basis'];
        if (in_array($status, self::UNAVAILABLE, true)) {
            if ($basis !== 'UNAVAILABLE' || $calculation['calibration_applied'] !== false) {
                throw new InvalidArgumentException('Invalid unavailable basis.');
            }
            foreach (['trial_score_3', 'trial_score_5', 'p3_raw', 'p5_raw', 'p3_cal', 'p5_cal'] as $key) {
                if ($calculation[$key] !== null) throw new InvalidArgumentException('Unavailable result contains a number.');
            }
            return self::unavailable($identity, $status);
        }
        if (!in_array($status, self::AVAILABLE, true)
            || !in_array($basis, ['RAW_UNCALIBRATED', 'CALIBRATION_APPLIED'], true)
            || ($status === 'BASELINE_ONLY' && $basis !== 'RAW_UNCALIBRATED')) {
            throw new InvalidArgumentException('Inconsistent calculation status.');
        }
        $p3 = self::number($calculation['p3_raw'], 1.0);
        $p5 = self::number($calculation['p5_raw'], 1.0);
        if ($p3 > $p5) throw new InvalidArgumentException('Raw TOP3 cannot exceed TOP5.');
        if ($basis === 'CALIBRATION_APPLIED') {
            if ($calculation['calibration_applied'] !== true) throw new InvalidArgumentException('Calibration flag mismatch.');
            $p3 = self::number($calculation['p3_cal'], 1.0);
            $p5 = self::number($calculation['p5_cal'], 1.0);
            if ($p3 > $p5) throw new InvalidArgumentException('Calibrated TOP3 cannot exceed TOP5.');
        } elseif ($calculation['calibration_applied'] !== false
                  || $calculation['p3_cal'] !== null || $calculation['p5_cal'] !== null) {
            throw new InvalidArgumentException('Raw result contains calibration values.');
        }
        $top3 = self::score($calculation['trial_score_3']);
        $top5 = self::score($calculation['trial_score_5']);
        if ($top3 > $top5
            || abs($top3 - round(100.0 * $p3, 1, PHP_ROUND_HALF_UP)) > 1e-9
            || abs($top5 - round(100.0 * $p5, 1, PHP_ROUND_HALF_UP)) > 1e-9) {
            throw new InvalidArgumentException('Display score does not match calculation probability.');
        }
        return array_merge($identity, [
            'display_schema_version' => self::DISPLAY_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'implementation_version' => self::IMPLEMENTATION_VERSION,
            'comparison_only' => true, 'validated_for_live' => false,
            'main_target' => 'TOP5', 'unit' => 'score_0_100',
            'expectation_value' => $top5, 'top3_expectation_value' => $top3,
            'expectation_value_text' => number_format($top5, 1, '.', ''),
            'top3_expectation_value_text' => number_format($top3, 1, '.', ''),
            'score_basis' => $basis, 'support_status' => $status,
        ]);
    }
}


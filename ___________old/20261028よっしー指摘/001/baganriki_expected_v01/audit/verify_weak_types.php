<?php
// Deliberately weak caller: guards must not depend on declare(strict_types=1).
declare(strict_types=0);
require_once __DIR__ . '/../ExpectedValueV01.php';
require_once __DIR__ . '/../display/ExpectedScorePresenterV01.php';
use App\Services\Baganriki\ExpectedValueV01;
use App\Services\Baganriki\ExpectedScorePresenterV01;
$identity = [
    'race_id' => 'DEMO_RACE_001', 'horse_id' => 'DEMO_HORSE_02',
    'horse_number' => 2, 'popularity_rank' => 7,
    'snapshot_id' => 'DEMO_SNAPSHOT_6MIN', 'source_minutes_before' => 6,
    'frozen_at' => '2026-10-25T09:44:00+09:00', 'runner_status' => 'ACTIVE',
];
$features = [
    'odds6_by_rank' => [2.0, 3.0, 6.0, 12.0], 'odds21_at_rank' => 7.2,
    'distance_metres' => 1600, 'class_group' => 'C2', 'surface' => 'TURF', 'race_type' => 'FLAT',
];
$cases = [
    ['valid_integer_baseline', true, function () {
        $b = ExpectedValueV01::baseline(198, 19, 39);
        if ($b['p0_3'] !== 0.1 || $b['p0_5'] !== 0.2) throw new RuntimeException('Baseline mismatch.');
    }],
    ['valid_integer_rank', true, function () use ($features) {
        if (ExpectedValueV01::distanceSquared($features, $features, 3) !== 0.0) {
            throw new RuntimeException('Distance mismatch.');
        }
    }],
    ['float_count', false, fn() => ExpectedValueV01::baseline(198.7, 19, 39)],
    ['string_count', false, fn() => ExpectedValueV01::baseline('198', 19, 39)],
    ['boolean_count', false, fn() => ExpectedValueV01::baseline(true, 0, 0)],
    ['calculate_float_count', false, fn() => ExpectedValueV01::calculate(198.7, 19, 39, [])],
    ['float_rank', false, fn() => ExpectedValueV01::distanceSquared($features, $features, 3.7)],
    ['boolean_rank', false, fn() => ExpectedValueV01::distanceSquared($features, $features, true)],
    ['wrapper_float_count', false, fn() => ExpectedScorePresenterV01::calculateForHorse($identity, 198.7, 19, 39, [])],
    ['boolean_lambda', false, fn() => ExpectedValueV01::calculate(198, 19, 39, [], true)],
    ['wrapper_boolean_lambda', false, fn() => ExpectedScorePresenterV01::calculateForHorse($identity, 198, 19, 39, [], true)],
    ['boolean_age', false, fn() => ExpectedValueV01::weight(0, true)],
];
$passed = 0;
$failed = 0;
foreach ($cases as [$name, $accept, $call]) {
    try {
        $call();
        if (!$accept) throw new RuntimeException('Invalid input was accepted.');
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } catch (InvalidArgumentException $e) {
        if (!$accept) {
            $passed++;
            echo 'PASS ' . $name . ' (expected rejection)' . PHP_EOL;
        } else {
            $failed++;
            echo 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL;
        }
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL;
    }
}
echo $passed . ' passed; ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);


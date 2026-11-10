<?php
declare(strict_types=1);
require_once __DIR__ . '/ExpectedValueV01.php';

use App\Services\Baganriki\ExpectedValueV01;

/** Fixture runner for PHP 8.0+. Does not access production data or external services. */
function assertSubset($actual, $expected, string $path): void
{
    if (is_array($expected)) {
        if (!is_array($actual)) {
            throw new RuntimeException($path . ': expected array.');
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                throw new RuntimeException($path . ': missing ' . $key);
            }
            assertSubset($actual[$key], $value, $path . '.' . $key);
        }
        return;
    }
    if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
        // Relative tolerance; an absolute 1e-9 floor would accept 0 for 1e-21.
        $tolerance = 1e-9 * abs((float) $expected);
        if (!is_finite((float) $actual) || abs($actual - $expected) > $tolerance) {
            throw new RuntimeException($path . ': numeric mismatch.');
        }
        return;
    }
    if ($actual !== $expected) {
        throw new RuntimeException($path . ': value/type mismatch.');
    }
}
function invokeCase(array $case)
{
    $p = $case['arguments'];
    switch ($case['method']) {
        case 'baseline':
            return ExpectedValueV01::baseline($p['m'], $p['b3'], $p['b5']);
        case 'calculate':
            return ExpectedValueV01::calculate(
                $p['m'], $p['b3'], $p['b5'], $p['samples'],
                $p['lambda'], $p['calibration']
            );
        case 'weight':
            return ExpectedValueV01::weight(
                $p['distance_squared'], $p['age_days'],
                $p['bandwidth'], $p['half_life_days']
            );
        case 'distanceSquared':
            return ExpectedValueV01::distanceSquared($p['current'], $p['historical'], $p['rank']);
        default:
            throw new RuntimeException('Unknown fixture method.');
    }
}
$raw = file_get_contents(__DIR__ . '/test_vectors.json');
if ($raw === false) {
    throw new RuntimeException('Cannot read test_vectors.json');
}
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$failed = 0;
$passed = 0;
foreach ($data['cases'] as $case) {
    try {
        $actual = invokeCase($case);
        if (isset($case['expect_error'])) {
            throw new RuntimeException('Expected InvalidArgumentException was not thrown.');
        }
        assertSubset($actual, $case['expected'], $case['id']);
        $passed++;
        echo 'PASS ' . $case['id'] . PHP_EOL;
    } catch (InvalidArgumentException $e) {
        if (($case['expect_error'] ?? '') === 'InvalidArgumentException') {
            $passed++;
            echo 'PASS ' . $case['id'] . ' (expected rejection)' . PHP_EOL;
        } else {
            $failed++;
            echo 'FAIL ' . $case['id'] . ': ' . $e->getMessage() . PHP_EOL;
        }
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $case['id'] . ': ' . $e->getMessage() . PHP_EOL;
    }
}
echo $passed . ' passed; ' . $failed . ' failed.' . PHP_EOL;
echo 'These checks validate formulas only, not predictive accuracy or production readiness.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);


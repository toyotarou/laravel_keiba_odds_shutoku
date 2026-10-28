<?php
declare(strict_types=1);
require_once __DIR__ . '/ExpectedScorePresenterV01.php';
require_once __DIR__ . '/../ExpectedValueV01.php';
use App\Services\Baganriki\ExpectedScorePresenterV01;

function verifyExactFields($actual, $expected, string $path): void
{
    if (is_array($expected)) {
        if (!is_array($actual) || count($actual) !== count($expected)) {
            throw new RuntimeException($path . ': object shape mismatch.');
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) throw new RuntimeException($path . ': missing key.');
            verifyExactFields($actual[$key], $value, $path . '.' . $key);
        }
    } elseif (is_int($expected) || is_float($expected)) {
        if ((!is_int($actual) && !is_float($actual)) || abs($actual - $expected) > 1e-9) {
            throw new RuntimeException($path . ': numeric type/value mismatch.');
        }
    } elseif ($actual !== $expected) {
        throw new RuntimeException($path . ': value/type mismatch.');
    }
}
$raw = file_get_contents(__DIR__ . '/display_test_vectors.json');
if ($raw === false) throw new RuntimeException('Fixture file missing.');
$fixtures = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$passed = 0;
$failed = 0;
foreach ($fixtures['server_cases'] as $case) {
    try {
        $calculation = $case['calculation'];
        if (isset($case['math_arguments'])) {
            $a = $case['math_arguments'];
            $calculation = ExpectedScorePresenterV01::calculateForHorse(
                $case['calculation_identity'], $a['m'], $a['b3'], $a['b5'], $a['samples'],
                $a['lambda'], $a['calibration']
            );
        }
        $result = ExpectedScorePresenterV01::present($calculation, $case['identity']);
        if (!$case['expected_accept']) throw new RuntimeException('Expected rejection did not occur.');
        verifyExactFields($result, $case['expected'], $case['id']);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        verifyExactFields(json_decode($json, true, 512, JSON_THROW_ON_ERROR), $case['expected'], $case['id'] . '.json');
        $passed++;
        echo 'PASS ' . $case['id'] . PHP_EOL;
    } catch (InvalidArgumentException $e) {
        if (!$case['expected_accept']) {
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
exit($failed === 0 ? 0 : 1);


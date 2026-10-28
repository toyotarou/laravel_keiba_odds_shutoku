<?php
declare(strict_types=1);
require_once __DIR__ . '/../ExpectedValueV01.php';
require_once __DIR__ . '/ExpectedScorePresenterV01.php';

use App\Services\Baganriki\ExpectedScorePresenterV01;

// Synthetic demonstration only. Do not use these samples as production data.
$samples = [];
for ($i = 0; $i < 100; $i++) {
    $samples[] = [
        'race_id' => sprintf('synthetic_%03d', $i),
        'w' => 0.8,
        'y3' => $i < 15 ? 1 : 0,
        'y5' => $i < 35 ? 1 : 0,
    ];
}
$identity = [
    'race_id' => 'DEMO_RACE_001',
    'horse_id' => 'DEMO_HORSE_02',
    'horse_number' => 2,
    'popularity_rank' => 7,
    'snapshot_id' => 'DEMO_SNAPSHOT_6MIN',
    'source_minutes_before' => 6,
    'runner_status' => 'ACTIVE',
    'frozen_at' => '2026-10-25T09:44:00+09:00',
];
$calculation = ExpectedScorePresenterV01::calculateForHorse($identity, 198, 19, 39, $samples);
$payload = ExpectedScorePresenterV01::present($calculation, $identity);
echo json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
) . PHP_EOL;

// Laravel controller equivalent (adapt namespaces and existing authorization):
// return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);


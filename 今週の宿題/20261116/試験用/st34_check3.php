<?php
// 20261116 段階3・4の確認（【3】だけを、試験の時計を凍結の直後に合わせてやり直す）：本文 _06（3指標の選出連動）を入れた後の123で、版・本文・凍結・アプリへの答えを確かめる。
// DBには書きません（凍結は dryRun、【5】は取り消す前提の読み取りだけ）。AIへは送りません。
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiTime as T;
use App\Http\Controllers\Api\BaganrikiDispatch as D;
use App\Http\Controllers\Api\BaganrikiInput;
use App\Http\Controllers\Api\BaganrikiPrompt;
use App\Http\Controllers\Api\BaganrikiFrozenInput;
use App\Http\Controllers\Api\BaganrikiRaceMetrics;
use App\Http\Controllers\Api\BaganrikiMetricsSelection as MS;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\BaganrikiWorker as W;
class_exists(AiController::class);
$E = '/var/www/horse_odds_finder/storage/app/baganriki/prompts/expected/prompts/';
$sha = fn($s) => hash('sha256', $s);

$c = app(AiController::class);
$ref = new ReflectionMethod(AiController::class, 'expectedAssemble'); $ref->setAccessible(true);
echo "【3】11/15 の全レースで、新しい本文 _06 の入力を組み立てる（早期凍結：6分前のバッチの1秒後。保存しない）\n";
$sum = []; $offs = [];
foreach (DB::table('t_horse_odds_finder_races')->where('date', '2026-11-15')->orderBy('basho')->orderBy('race')->get() as $r) {
    $rid = AiController::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, (int) $r->race);
    $anchor = T::us('2026-11-15T' . T::normalizeRaceStartClock((string) $r->start_time) . '+09:00');
    $first = null;
    foreach (DB::table('baganriki_acquisition_batches')->where('race_id', $rid)->where('slot', 6)->where('committed', 1)->get(['available_at']) as $b) {
        $at = T::us((string) AiController::dbTimeToRfc3339($b->available_at)); if ($at !== null && ($first === null || $at < $first)) { $first = $at; }
    }
    if ($first === null) { $sum['6分前のバッチなし'][] = "{$r->basho}_{$r->race}R"; continue; }
    $fz = T::freezeFor($anchor, $first + 1000000, T::TIME_CONTRACT_V2);
    W::setTestNow($fz + 1000000);   // 凍結した直後の時刻で組み立てる（時計を合わせないと E3・E5 が「返事の後に計算した」扱いになる）
    try {
    $g = BaganrikiInput::gatherPredictionContext($r->date, $r->kaisuu, $r->basho, $r->day, $r->race, ['input_freeze_us' => $fz]);
    if (!$g['ok']) { $sum['gather:' . $g['code']][] = "{$r->basho}_{$r->race}R"; continue; }
    $p1 = BaganrikiPrompt::assemble($g['ctx'], '1st'); $p2 = BaganrikiPrompt::assemble($g['ctx'], '2nd');
    if (!$p1['ok'] || !$p2['ok']) { $sum['assemble:' . ($p1['code'] ?? $p2['code'])][] = "{$r->basho}_{$r->race}R"; continue; }
    $ex = $ref->invoke(null, $p1, $p2, $g['ctx']);
    if (!$ex['ok']) { $sum['expected:' . $ex['reason']][] = "{$r->basho}_{$r->race}R"; continue; }
    $in = $ex['common'];   // 版は E3・E5 を付けた共通の入力に入る
    $k = (($in['prompt_text_revision'] ?? null) === 'COMPACT_TEXT_20261109_06' && MS::enabled($in) && BaganrikiRaceMetrics::enabled($in)
          && ($in['time_contract']['version'] ?? null) === T::TIME_CONTRACT_V2) ? 'OK（_06・選出連動あり・3指標あり・時刻契約v2）' : '★版がおかしい ' . ($in['prompt_text_revision'] ?? '-');
    $sum[$k][] = "{$r->basho}_{$r->race}R";
    $offs[] = intdiv($fz - $anchor, 1000000);
    } finally { W::setTestNow(null); }
}
foreach ($sum as $k => $l) { echo "  {$k} : " . count($l) . (str_starts_with($k, 'OK') ? '' : '（' . implode(' ', $l) . '）') . "\n"; }
if ($offs) { echo '  凍結の時刻（発走の何秒前）：最も早い ' . min($offs) . ' ／ 最も遅い ' . max($offs) . ' ／ 平均 ' . round(array_sum($offs) / count($offs), 1) . "\n"; }


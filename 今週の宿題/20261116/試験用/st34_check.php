<?php
// 20261116 段階3・4の確認：本文 _06（3指標の選出連動）を入れた後の123で、版・本文・凍結・アプリへの答えを確かめる。
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
class_exists(AiController::class);
$E = '/var/www/horse_odds_finder/storage/app/baganriki/prompts/expected/prompts/';
$sha = fn($s) => hash('sha256', $s);

echo "【1】本文の版\n";
echo '  本番の版: ' . D::activeRevision() . ' ／ 早期凍結: ' . var_export(T::earlyFreezeActive(), true) . "\n";
$w1 = $sha(file_get_contents($E . 'prompt_1st.txt') . "\n\n" . file_get_contents($E . 'SOURCE_DICT_V3_ADDENDUM_V1.txt'));
$w2 = $sha(file_get_contents($E . 'prompt_2nd.txt') . "\n\n" . file_get_contents($E . 'SOURCE_TABLE_V1_ADDENDUM_V2.txt'));
$t1 = D::system('1st', 'SOURCE_DICT_V3'); $t2 = D::system('2nd', 'SOURCE_TABLE_V1');
echo '  _06 1st ' . ($sha($t1) === $w1 ? 'OK' : '★NG') . ' ／ 2nd ' . ($sha($t2) === $w2 ? 'OK' : '★NG')
   . ' ／ 新しい書き方が入っている ' . ((str_contains($t1, '1stのレース指標') && str_contains($t1, 'RACE_METRICS_SELECTION_V1') && str_contains($t2, '複勝時系列の確認はサーバーが行う') && !str_contains($t1, '表示用の段階評価')) ? 'OK' : '★NG') . "\n";
// 11/15 に実際に送った本文（_05）と、保存した原本が同じか
$sent = [];
foreach (DB::table('baganriki_ai_attempts as a')->join('baganriki_snapshots as s', 's.id', '=', 'a.snapshot_id')->where('s.race_id', 'like', '20261115-%')
           ->where('s.generation', 0)->whereNotNull('a.sent_request')->get(['a.role', 'a.sent_request']) as $r) {
    $j = json_decode((string) $r->sent_request, true);
    $sys = null;
    foreach ((array) ($j['messages'] ?? []) as $m) { if (($m['role'] ?? null) === 'system') { $sys = $m['content'] ?? null; break; } }
    if (!is_string($sys) && is_string($j['system'] ?? null)) { $sys = $j['system']; }
    if (is_string($sys)) { $sent[$r->role][$sha($sys)] = true; }
}
$a1 = $sha(D::system('1st', 'SOURCE_DICT_V3', 'COMPACT_TEXT_20261109_05')); $a2 = $sha(D::system('2nd', 'SOURCE_TABLE_V1', 'COMPACT_TEXT_20261109_05'));
echo '  _05 の原本 1st ' . (isset($sent['1st'][$a1]) ? 'OK（11/15に送った本文と同じ）' : ($sent ? '★送った本文と違う' : '（送った本文を読めず。照合値だけ確認：' . substr($a1, 0, 12) . '）'))
   . ' ／ 2nd ' . (isset($sent['2nd'][$a2]) ? 'OK' : ($sent ? '★違う' : '（' . substr($a2, 0, 12) . '）')) . "\n";
echo '  _04 の原本 1st ' . ($sha(D::system('1st', 'SOURCE_DICT_V3', 'COMPACT_TEXT_20261109_04')) === '18f97c15417c843589c95b853e7814f04e1708c9cbfd9903dd64acb3f67a85bc' ? 'OK' : '★NG')
   . ' ／ 2nd ' . ($sha(D::system('2nd', 'SOURCE_TABLE_V1', 'COMPACT_TEXT_20261109_04')) === '3f91c0a8844e0c74333d5c6fce72bf9201da96e1e5e84301283884ddff85f1be' ? 'OK' : '★NG') . "\n";
try { D::system('1st', 'SOURCE_DICT_V3', 'COMPACT_TEXT_20261109_99'); echo "  未知の版 ★通ってしまった\n"; } catch (\Throwable $e) { echo '  未知の版は拒否 OK（' . $e->getMessage() . "）\n"; }
foreach (['COMPACT_TEXT_20261109_04', 'COMPACT_TEXT_20261109_05', 'COMPACT_TEXT_20261109_06'] as $v) {
    echo "  {$v}：3指標 " . var_export(BaganrikiRaceMetrics::enabled(['prompt_text_revision' => $v]), true) . ' ／ 選出連動 ' . var_export(MS::enabled(['prompt_text_revision' => $v]), true) . "\n";
}

echo "【2】保存済みの予想（_04・_05）の3指標の表示（11/14・15）\n";
$cnt = []; $err = 0;
foreach (DB::table('baganriki_publications as p')->join('baganriki_snapshots as s', 's.id', '=', 'p.snapshot_id')
           ->where('p.published_at', '>=', '2026-11-14')->get(['p.evidence', 's.*']) as $r) {
    try {
        $in = BaganrikiFrozenInput::forInspection($r, '1st');
        $v = BaganrikiRaceMetrics::publicValues($in, json_decode($r->evidence, true)['race_metrics'] ?? null);
        foreach ($v as $k => $x) { if ($x !== null) { $cnt[$k] = ($cnt[$k] ?? 0) + 1; } }
    } catch (\Throwable $e) { $err++; }
}
echo '  値が出た件数 ' . json_encode($cnt, JSON_UNESCAPED_UNICODE) . ' ／ 読めなかった ' . $err . "（前回：波乱度37・下位進入度40・大穴進入度32 なら変わっていない）\n";

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
    $g = BaganrikiInput::gatherPredictionContext($r->date, $r->kaisuu, $r->basho, $r->day, $r->race, ['input_freeze_us' => $fz]);
    if (!$g['ok']) { $sum['gather:' . $g['code']][] = "{$r->basho}_{$r->race}R"; continue; }
    $p1 = BaganrikiPrompt::assemble($g['ctx'], '1st'); $p2 = BaganrikiPrompt::assemble($g['ctx'], '2nd');
    if (!$p1['ok'] || !$p2['ok']) { $sum['assemble:' . ($p1['code'] ?? $p2['code'])][] = "{$r->basho}_{$r->race}R"; continue; }
    $ex = $ref->invoke(null, $p1, $p2, $g['ctx']);
    if (!$ex['ok']) { $sum['expected:' . $ex['reason']][] = "{$r->basho}_{$r->race}R"; continue; }
    $in = $p1['input'];
    $k = (($in['prompt_text_revision'] ?? null) === 'COMPACT_TEXT_20261109_06' && MS::enabled($in) && BaganrikiRaceMetrics::enabled($in)
          && ($in['time_contract']['version'] ?? null) === T::TIME_CONTRACT_V2) ? 'OK（_06・選出連動あり・3指標あり・時刻契約v2）' : '★版がおかしい ' . ($in['prompt_text_revision'] ?? '-');
    $sum[$k][] = "{$r->basho}_{$r->race}R";
    $offs[] = intdiv($fz - $anchor, 1000000);
}
foreach ($sum as $k => $l) { echo "  {$k} : " . count($l) . (str_starts_with($k, 'OK') ? '' : '（' . implode(' ', $l) . '）') . "\n"; }
if ($offs) { echo '  凍結の時刻（発走の何秒前）：最も早い ' . min($offs) . ' ／ 最も遅い ' . max($offs) . ' ／ 平均 ' . round(array_sum($offs) / count($offs), 1) . "\n"; }

echo "【4】アプリへの答え（11/15 の公開を1件使い、3指標で1頭変更した記録があったことにして作る。保存しない）\n";
$ans = new ReflectionMethod(AiController::class, 'predictionPublishedAnswer'); $ans->setAccessible(true);
$row = DB::table('baganriki_publications as p')->join('baganriki_snapshots as s', 's.id', '=', 'p.snapshot_id')
    ->where('s.race_id', 'like', '20261115-%')->where('s.generation', 0)->orderBy('s.race_id')->first(['p.*', 's.race_id']);
$snap = DB::table('baganriki_snapshots')->where('race_id', $row->race_id)->where('generation', 0)->first();
$cands = json_decode((string) $row->candidates, true);
[$dt, $bs, $kai, $day, $rc] = (function ($rid) { $p = explode('-', $rid); return [substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2), $p[2] ?? '', $p[1] ?? '', $p[3] ?? '', (int) ($p[4] ?? 0)]; })($row->race_id);
$race = DB::table('t_horse_odds_finder_races')->where('date', $dt)->where('race', $rc)->get()->first(fn($r) => AiController::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, (int) $r->race) === $row->race_id);
$args = [$race->date, $race->kaisuu, $race->basho, $race->day, $race->race];
$plain = $ans->invoke($c, $snap, $row, [], ...$args);
$hs = $plain['second']['merged_horses'];
echo '  記録なし（今の _05 の公開）：指標反映の印 ' . count(array_filter($hs, fn($h) => !empty($h['metrics_slot']))) . '頭 ／ metrics_selection ' . json_encode($plain['first']['prediction']['metrics_selection'] ?? 'キーなし') . "（0頭・null なら OK）\n";
$last = end($cands);
$fake = clone $row;
$ev = json_decode((string) $row->evidence, true);
$ev['metrics_selection'] = ['policy' => 'RACE_METRICS_SELECTION_V1', 'status' => 'APPLIED', 'reason' => 'ONE_SLOT_ADJUSTMENT', 'added' => (int) $last['horse_no'], 'removed' => 99, 'source_band' => 'A', 'target_band' => 'D'];
$fake->evidence = json_encode($ev);
$fa = $ans->invoke($c, $snap, $fake, [], ...$args);
$mh = array_values(array_filter($fa['second']['merged_horses'], fn($h) => !empty($h['metrics_slot'])));
echo '  記録あり（' . $last['horse_no'] . "番を入れたことにする）：印 " . count($mh) . '頭' . ($mh ? '（' . $mh[0]['num'] . '番）' : '') . ' ／ 理由の先頭 ' . ($mh ? mb_substr($mh[0]['reason'], 0, 34) : '-') . "\n";
echo '  metrics_selection ' . json_encode($fa['first']['prediction']['metrics_selection'], JSON_UNESCAPED_UNICODE) . "\n";
echo '  analysis_text の該当行 ' . (preg_grep('/^馬番：' . $last['horse_no'] . '、/u', explode("\n", $fa['first']['analysis_text'])) ? 'あり' : '★なし') . ' ／ おすすめ度は変わらない ' . (($mh[0]['score'] ?? -1) === (array_values(array_filter($hs, fn($h) => $h['num'] === (int) $last['horse_no']))[0]['score'] ?? -2) ? 'OK' : '★NG') . "\n";

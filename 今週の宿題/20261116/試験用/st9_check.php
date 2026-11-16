<?php
// 20261116 なる11/16 質問2 A：保存表の経路の「結果の確認記録」の確認（4点）。
//   確認記録は /tmp の試験用のファイルに作る（本番の記録には書かない）。DB の結果を変える試験は1つの取引の中で行い、必ず取り消す。AIへは送らない。
//   引数 real を付けたときだけ、本番の確認記録を作る（最初の1回。今の実時刻で確認する）。
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
use Illuminate\Support\Facades\DB;
class_exists(AiController::class);
$hist = new ReflectionMethod(AiController::class, 'expectedHistory'); $hist->setAccessible(true);
$ok = 0; $ng = 0;
$chk = function (bool $c, string $l) use (&$ok, &$ng) { $c ? $ok++ : $ng++; echo '  ' . ($c ? 'OK ' : '★NG ') . $l . "\n"; };
$saved = function (string $t) use ($hist) {
    AiController::$expectedOldHistoryForce = false;
    $h = $hist->invoke(null, $t); AiController::$expectedOldHistoryForce = null;
    return [count($h ?? []), AiController::$expectedSavedHistoryAudit];
};
if (($argv[1] ?? '') === 'real') {
    echo "【本番の確認記録を作る（今の実時刻で確認）】\n";
    AiController::$expectedSavedLedgerPath = null;
    $r = AiController::expectedResultsConfirm();
    echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    $r2 = AiController::expectedResultsConfirm();
    $chk(($r2['new'] ?? -1) === 0 && ($r2['corrected'] ?? -1) === 0 && ($r2['same'] ?? 0) === ($r['checked'] ?? -1), 'すぐにもう一度確認しても、新しい版は0（同じ内容は時刻を変えない）');
    foreach (['2026-11-16', '2026-11-21'] as $t) { [$n, $a] = $saved($t); echo "  対象日 {$t}：使える過去レース {$n} ／ " . json_encode($a['excluded'], JSON_UNESCAPED_UNICODE) . "\n"; }
    echo "合計 OK {$ok} / NG {$ng}\n";
    exit;
}
$A = '/tmp/st9_ledger_A.json'; $B = '/tmp/st9_ledger_B.json';
foreach ([$A, $B, $A . '.lock', $B . '.lock'] as $f) { @unlink($f); }

echo "【1】対象日の 0:00 より前／以降の確認（試験用の確認記録）\n";
AiController::$expectedSavedLedgerPath = $A;
$ra = AiController::expectedResultsConfirm('2026-11-20T23:45:00+09:00');
echo '  記録A（11/20 23:45 に確認）' . json_encode(array_intersect_key($ra, array_flip(['checked', 'new', 'same', 'corrected', 'incomplete'])), JSON_UNESCAPED_UNICODE) . "\n";
[$nA, $aA] = $saved('2026-11-21');
$chk($nA > 0 && ($aA['excluded'] ?? []) === [], "対象日 11/21：0:00 より前に確認した記録で、保存表の経路の {$nA} レースを全部使う（外した " . json_encode($aA['excluded']) . '）');
AiController::$expectedSavedLedgerPath = $B;
$rb = AiController::expectedResultsConfirm('2026-11-21T00:10:00+09:00');
[$nB, $aB] = $saved('2026-11-21');
$chk($nB === 0 && ($aB['excluded']['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) === $nA, '対象日 11/21：0:00 以降（00:10）に確認した記録は使わない（RESULT_CONFIRMED_AFTER_TARGET ' . ($aB['excluded']['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) . '）');
[$nB2] = $saved('2026-11-22');
$chk($nB2 === $nA, "その記録も、翌日（11/22）の対象日には使う（{$nB2}）");

echo "【2】同じ内容を確認し直しても、最初の確認時刻を変えない\n";
AiController::$expectedSavedLedgerPath = $A;
$before = json_decode(file_get_contents($A), true);
$ra2 = AiController::expectedResultsConfirm('2026-11-21T08:00:00+09:00');
$after = json_decode(file_get_contents($A), true);
$chk(($ra2['new'] ?? -1) === 0 && ($ra2['corrected'] ?? -1) === 0 && $before === $after, "11/21 08:00 に確認し直す → 新しい版 0・記録は1バイトも変わらない（同じ {$ra2['same']}）");

echo "【3】結果の内容を訂正したら、新しい版と確認時刻を足す（訂正版が前から使えたことにはしない）\n";
$row = DB::table('t_horse_odds_finder_race_result_history as h')->join('t_horse_odds_finder_expected_score as e', function ($j) {
        $j->on('e.date', '=', 'h.date')->on('e.basho', '=', 'h.basho_code')->on('e.race', '=', 'h.race')->on('e.num', '=', 'h.num'); })
    ->where('h.date', '2026-11-15')->where('h.finishing_position', 1)->where('e.roster_json', 'like', '%"odds_get_timing_6":"2%')->orderBy('h.id')->first(['h.id', 'h.date', 'h.kaisuu', 'h.basho_code', 'h.day', 'h.race', 'h.num']);
$rid = AiController::buildPredictionRaceId(substr((string) $row->date, 0, 10), $row->kaisuu, $row->basho_code, $row->day, (int) $row->race);
DB::beginTransaction();
try {
    DB::table('t_horse_odds_finder_race_result_history')->where('id', $row->id)->update(['finishing_status' => '失格', 'finishing_position' => 0]);   // 試験の訂正（取り消す）
    $rc = AiController::expectedResultsConfirm('2026-11-21T09:00:00+09:00');
    $led = json_decode(file_get_contents($A), true);
    $v = $led['races'][$rid] ?? [];
    $chk(($rc['corrected'] ?? 0) === 1 && count($v) === 2 && $v[0]['confirmed_at'] === '2026-11-20T23:45:00.000000+09:00' && $v[1]['confirmed_at'] === '2026-11-21T09:00:00.000000+09:00',
        "{$rid} の {$row->num}番を失格に訂正 → 新しい版が1つ増え、最初の版の時刻は 11/20 23:45 のまま・新しい版は 11/21 09:00");
    [$n21, $a21] = $saved('2026-11-21');
    $chk($n21 === $nA - 1 && ($a21['excluded']['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) === 1, '対象日 11/21：訂正した版（11/21 09:00 確認）は使わない（1件外れる）');
    [$n22] = $saved('2026-11-22');
    $chk($n22 === $nA, '対象日 11/22：訂正した版を使う');
} finally { DB::rollBack(); }
[$nBack, $aBack] = $saved('2026-11-21');
$chk(($aBack['excluded']['RESULT_DIGEST_MISMATCH'] ?? 0) === 1, 'DB を元に戻すと、最新の版（訂正版）と内容が違うので、そのレースは照合値不一致で外れる（RESULT_DIGEST_MISMATCH）');

echo "【4】確認記録が無いときは、保存表の経路の過去レースを使わない（理由を残す）\n";
AiController::$expectedSavedLedgerPath = '/tmp/st9_no_such_ledger.json';
[$n0, $a0] = $saved('2026-11-21');
$chk($n0 === 0 && ($a0['excluded']['NO_RESULT_LEDGER'] ?? 0) > 0, '確認記録が無い → 0 レース（NO_RESULT_LEDGER ' . ($a0['excluded']['NO_RESULT_LEDGER'] ?? 0) . '）');
AiController::$expectedSavedLedgerPath = null;
foreach ([$A, $B, $A . '.lock', $B . '.lock'] as $f) { @unlink($f); }
echo "合計 OK {$ok} / NG {$ng}\n";

<?php
// 20261116 なる11/16 残件の確認（2点だけ）：確認時刻は「内容を確かめ終えた後」の実時刻にする。
//   試験の時計は、処理の開始（23:59）と内容の確認完了（翌日 00:01）で進める。確認記録は /tmp の試験用ファイル。
//   DB は読むだけ（書き込まない）。AIへは送らない。
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
class_exists(AiController::class);
$hist = new ReflectionMethod(AiController::class, 'expectedHistory'); $hist->setAccessible(true);
$ok = 0; $ng = 0;
$chk = function (bool $c, string $l) use (&$ok, &$ng) { $c ? $ok++ : $ng++; echo '  ' . ($c ? 'OK ' : '★NG ') . $l . "\n"; };
$saved = function (string $t) use ($hist) {
    AiController::$expectedOldHistoryForce = false;
    $h = $hist->invoke(null, $t); AiController::$expectedOldHistoryForce = null;
    return [count($h ?? []), AiController::$expectedSavedHistoryAudit['excluded'] ?? []];
};
// 時計：呼ばれた回数も数える（内容の確認が終わった後に1回だけ呼ばれること）
$calls = 0;
$clockAt = function (string $iso) use (&$calls) { return function () use ($iso, &$calls) { $calls++; return new DateTimeImmutable($iso); }; };
$X = '/tmp/st10_ledger_X.json'; $Y = '/tmp/st10_ledger_Y.json';
foreach ([$X, $Y, "$X.lock", "$Y.lock"] as $f) { @unlink($f); }

echo "【①-1 新しい版】処理の開始 11/21 23:59 → 内容の確認完了 11/22 00:01（試験の時計を進める）\n";
AiController::$expectedSavedLedgerPath = $Y; $calls = 0;
AiController::$expectedSavedClock = $clockAt('2026-11-22T00:01:00+09:00');
$r = AiController::expectedResultsConfirm('2026-11-21T23:59:00+09:00');
$led = json_decode(file_get_contents($Y), true);
$times = array_unique(array_map(fn($v) => end($v)['confirmed_at'], $led['races']));
echo '  ' . json_encode(array_intersect_key($r, array_flip(['started_at', 'confirmed_at', 'checked', 'new', 'corrected'])), JSON_UNESCAPED_UNICODE) . "\n";
$chk($r['started_at'] === '2026-11-21T23:59:00.000000+09:00' && $r['confirmed_at'] === '2026-11-22T00:01:00.000000+09:00' && array_values($times) === ['2026-11-22T00:01:00.000000+09:00'] && $calls === 1,
    "新しい版 {$r['new']} 件の確認時刻は、開始の 23:59 ではなく、確認完了の 11/22 00:01（時計を呼んだのは検査の後の1回だけ）");
[$n22, $e22] = $saved('2026-11-22'); [$n23] = $saved('2026-11-23');
$chk($n22 === 0 && ($e22['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) === $n23 && $n23 > 0, "対象日 11/22 の予想からは外れる（RESULT_CONFIRMED_AFTER_TARGET " . ($e22['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) . "）・11/23 からは使う（{$n23}）");

echo "【①-2 訂正の版】基準の記録（11/20 23:41 確認）→ 1レースの記録の版を「前の内容」に置き換え（＝その後に結果が訂正された状態）→ 開始 11/21 23:59・確認完了 11/22 00:01\n";
// なる11/16：人工データや訂正を DB に書き込む試験は本番DBで行わない。DB は読むだけにし、試験用の確認記録の側を「訂正の前の内容」にして同じ状態を作る
AiController::$expectedSavedLedgerPath = $X;
AiController::$expectedSavedClock = $clockAt('2026-11-20T23:41:00+09:00');
AiController::expectedResultsConfirm('2026-11-20T23:40:00+09:00');
$ledX = json_decode(file_get_contents($X), true);
[$m0] = $saved('2026-11-23');
$rid = array_key_first($ledX['races']);
foreach (array_keys($ledX['races']) as $k) { if (substr($k, 0, 8) === '20261115') { $rid = $k; break; } }
$ledX['races'][$rid][0]['digest'] = hash('sha256', 'before-correction:' . $rid);   // 訂正の前の内容だった版
file_put_contents($X, json_encode($ledX, JSON_UNESCAPED_UNICODE));
$calls = 0;
AiController::$expectedSavedClock = $clockAt('2026-11-22T00:01:00+09:00');
$rc = AiController::expectedResultsConfirm('2026-11-21T23:59:00+09:00');
$v = json_decode(file_get_contents($X), true)['races'][$rid] ?? [];
echo '  ' . json_encode(array_intersect_key($rc, array_flip(['started_at', 'confirmed_at', 'new', 'same', 'corrected'])), JSON_UNESCAPED_UNICODE) . "\n";
$chk(($rc['corrected'] ?? 0) === 1 && count($v) === 2 && $v[0]['confirmed_at'] === '2026-11-20T23:41:00.000000+09:00' && $v[1]['confirmed_at'] === '2026-11-22T00:01:00.000000+09:00' && $calls === 1,
    "{$rid} の訂正版の確認時刻は 11/22 00:01（開始の 23:59 ではない）・前の版は 11/20 23:41 のまま");
[$m22, $f22] = $saved('2026-11-22'); [$m23, $f23] = $saved('2026-11-23');
$chk(($f22['RESULT_DIGEST_MISMATCH'] ?? 0) + ($f22['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) === 1 && ($f22['RESULT_CONFIRMED_AFTER_TARGET'] ?? 0) === 1 && $m23 === $m0,
    '訂正版は 11/22 の予想から外れ（RESULT_CONFIRMED_AFTER_TARGET 1）、11/23 からは使う（' . $m23 . '）');

echo "【② 同じ内容の再確認】記録Y を 11/22 08:00 開始・08:01 確認完了で確認し直す\n";
AiController::$expectedSavedLedgerPath = $Y;
$before = file_get_contents($Y); $mt = filemtime($Y); $calls = 0;
clearstatcache(); sleep(1);
AiController::$expectedSavedClock = $clockAt('2026-11-22T08:01:00+09:00');
$r2 = AiController::expectedResultsConfirm('2026-11-22T08:00:00+09:00');
clearstatcache();
echo '  ' . json_encode(array_intersect_key($r2, array_flip(['started_at', 'confirmed_at', 'checked', 'new', 'same', 'corrected'])), JSON_UNESCAPED_UNICODE) . "\n";
$chk(($r2['new'] ?? -1) === 0 && ($r2['corrected'] ?? -1) === 0 && $r2['confirmed_at'] === null && file_get_contents($Y) === $before && filemtime($Y) === $mt,
    "同じ内容 {$r2['same']} 件：新しい版0・記録の中身も更新日時も変わらない（確認時刻は 11/22 00:01 のまま）");

AiController::$expectedSavedLedgerPath = null; AiController::$expectedSavedClock = null;
foreach ([$X, $Y, "$X.lock", "$Y.lock"] as $f) { @unlink($f); }
echo "合計 OK {$ok} / NG {$ng}\n";

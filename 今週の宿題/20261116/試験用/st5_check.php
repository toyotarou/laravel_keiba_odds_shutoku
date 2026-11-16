<?php
// 20261116 段階5-1：作業2（古い過去レース）の直し（なる02 の1・2・4）の確認。設定（contract.json）は無効のまま。AIへは送りません。
// 書き込むのは結果の確認台帳（storage/app/baganriki/expected_history/old_results_confirmed_v1.json）1つだけ。既にあれば書きません。
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiExpectedPacket as P;
class_exists(AiController::class);
$build = new ReflectionMethod(AiController::class, 'expectedOldHistoryBuild'); $build->setAccessible(true);
$hist = new ReflectionMethod(AiController::class, 'expectedHistory'); $hist->setAccessible(true);
$prep = new ReflectionMethod(P::class, 'prepare'); $prep->setAccessible(true);
$short = fn(array $ids) => implode(' ', array_slice($ids, 0, 8)) . (count($ids) > 8 ? ' …ほか' . (count($ids) - 8) : '');

echo "【0】本番の設定：古い過去レース " . var_export(AiController::expectedOldHistoryEnabled(), true) . "（false なら無効のまま）\n";

echo "【1】6/1〜10/23 の古い過去レースを組み立てる（台帳を当てる前）\n";
$b = $build->invoke(null, AiController::EXPECTED_OLD_FROM, AiController::EXPECTED_OLD_BEFORE);
echo '  組み立てられた ' . count($b['races']) . " レース\n";
foreach ($b['audit']['excluded'] as $why => $n) { echo "  外した：{$why} {$n}（" . $short($b['audit']['excluded_races'][$why]) . "）\n"; }
$same = []; foreach ($b['races'] as $r) { if ($r['odds21_equals_odds6']) { $same[substr($r['race_id'], 0, 8)] = ($same[substr($r['race_id'], 0, 8)] ?? 0) + 1; } }
echo '  全頭の21分前＝6分前のレース（日付ごと）' . json_encode($same) . "\n";

echo "【2】結果の確認台帳を作る（今の実時刻を「結果を確かめた時刻」にする）\n";
$w = AiController::expectedOldLedgerWrite();
echo '  ' . json_encode($w, JSON_UNESCAPED_UNICODE) . "\n";
$L = AiController::expectedOldLedger();
echo '  読み直し ' . ($L === null ? '★読めない' : 'OK（確かめた時刻 ' . $L['confirmed_at'] . ' ／ 使えるレース ' . count($L['races']) . ' ／ 不具合で外すレース ' . count($L['excluded_ids']) . '）') . "\n";

AiController::$expectedOldHistoryForce = true;
foreach (['2026-11-15', '2026-11-21'] as $t) {
    echo "【3】対象日 {$t} の過去レース（試験だけ有効にする）\n";
    \Cache::forget('expected_oldhist:v2:' . AiController::EXPECTED_OLD_FROM . ':' . AiController::EXPECTED_OLD_BEFORE);
    $all = $hist->invoke(null, $t);
    $a = AiController::$expectedOldHistoryAudit;
    $old = array_values(array_filter($all ?? [], fn($r) => ($r['history_policy'] ?? null) === AiController::EXPECTED_HISTORY_POLICY));
    echo '  全部 ' . count($all ?? []) . ' レース（うち古い過去レース ' . count($old) . '）／ 採用 ' . $a['adopted'] . ' ／ 期間 ' . $a['from'] . '〜' . $a['before'] . "未満\n";
    foreach ($a['excluded'] as $why => $n) { echo "  外した：{$why} {$n}\n"; }
    $bad = []; $day = new DateTimeImmutable($t . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));
    foreach ($old as $r) {
        try { $prep->invoke(null, $r, true); } catch (\Throwable $e) { $bad[$e->getMessage()] = ($bad[$e->getMessage()] ?? 0) + 1; continue; }
        if (new DateTimeImmutable($r['result_available_at']) >= $day) { $bad['結果の時刻が対象日より後'] = ($bad['結果の時刻が対象日より後'] ?? 0) + 1; }
        if (str_contains((string) $r['result_available_at'], '23:59:59')) { $bad['仮の時刻 23:59:59 が残っている'] = ($bad['仮の時刻 23:59:59 が残っている'] ?? 0) + 1; }
    }
    echo '  過去レースの検査（prepare）で落ちたもの ' . ($bad ? json_encode($bad, JSON_UNESCAPED_UNICODE) : 'なし') . "\n";
    if ($old) { echo '  例 ' . $old[0]['race_id'] . ' 結果の時刻 ' . $old[0]['result_available_at'] . ' ／ ' . ($old[0]['result_time_status'] ?? '-') . "\n"; }
}
AiController::$expectedOldHistoryForce = null;

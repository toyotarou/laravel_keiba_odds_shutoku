<?php
// 20261116 段階2の確認：3指標の補正を、11/14・15の保存済みの凍結入力と採用済みの回答に当てたら、最終の公開候補がどう変わるか（保存しない・AIへ送らない）
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiCandidateSelection as S;
use App\Http\Controllers\Api\BaganrikiMetricsSelection as MS;
use App\Http\Controllers\Api\BaganrikiPublishedRead as PR;
use Illuminate\Support\Facades\DB;
class_exists(AiController::class);
$c = app(AiController::class);
$cf = new ReflectionMethod(PR::class, 'checkFilled'); $cf->setAccessible(true);
echo '本番の設定（_05 の予想では無効のはず）: ' . var_export(MS::$force, true) . "\n";
foreach (array_slice($argv, 1) ?: ['2026-11-14', '2026-11-15'] as $d) {
    $why = []; $applied = []; $same = 0; $diffNoApply = []; $ng = []; $guard = ['ok' => 0, 'ng' => 0]; $slow = []; $mism = [];
    foreach (DB::table('baganriki_publications as p')->join('baganriki_snapshots as s', 's.id', '=', 'p.snapshot_id')
               ->where('s.race_id', 'like', str_replace('-', '', $d) . '-%')->where('s.generation', 0)->orderBy('s.race_id')->get(['s.*', 'p.candidates']) as $snap) {
        $read = function ($role) use ($c, $snap) {
            $a = $c->acceptedHorsesOf((int) $snap->id, $role);
            $rk = (($a['ok'] ?? false) && ($a['complete'] ?? false) === true) ? $c->markResponseUsed((int) $snap->id, $role, $a) : null;
            return [$a, $rk];
        };
        $t0 = microtime(true); fwrite(STDERR, "\r\033[K  … " . substr($snap->race_id, 9) . " 計算中\r");
        $live = DB::table('baganriki_live_states')->where('snapshot_id', $snap->id)->first();
        $cancel = array_map('intval', (array) json_decode((string) ($live->cancellations ?? '[]'), true));
        $active = array_values(array_diff(array_map('intval', (array) json_decode((string) $snap->frozen_nums, true)), $cancel));
        sort($active);
        try {
            MS::$force = false; $off = S::submittedPublication($snap, $active, $read);
            MS::$force = true;  $on  = S::submittedPublication($snap, $active, $read);
        } catch (\Throwable $e) { $ng[] = substr($snap->race_id, 9) . ':' . mb_substr($e->getMessage(), 0, 40); continue; }
        finally { MS::$force = null; }
        $ms = $on['metrics_selection'] ?? null;
        $name = substr($snap->race_id, 9);
        $sec = microtime(true) - $t0; if ($sec > 5) { $slow[] = $name . sprintf(' %.0f秒', $sec); }
        if (($ms['reason'] ?? null) === 'BASE_SELECTION_MISMATCH') { $mism[] = $name . ' 補正なしの候補[' . implode(',', array_map(fn($r) => $r['horse_no'] . ($r['band'] ?? '?') . (($r['fill'] ?? false) ? '補' : ''), $off['candidates'])) . '] 割り当て ' . json_encode($on['quota'] ?? null) . ' 補充 ' . json_encode($on['fill'] ?? null); }
        $why[$ms['reason'] ?? '記録なし'] = ($why[$ms['reason'] ?? '記録なし'] ?? 0) + 1;
        $b = array_column($off['candidates'], 'horse_no'); $a = array_column($on['candidates'], 'horse_no');
        $stored = array_column(json_decode((string) $snap->candidates, true), 'horse_no');
        if ($b !== $stored) { $ng[] = $name . ':補正なしの再計算が保存済みの公開と違う'; }
        if (($ms['status'] ?? null) === 'APPLIED') {
            $owners = []; foreach ($on['candidates'] as $row) { $owners[$row['horse_no']] = $row['band']; }
            try { $cf->invoke(null, $on['quota'], $owners, $on['fill'], $on['shortfalls'], $ms); $guard['ok']++; } catch (\Throwable $e) { $guard['ng']++; $ng[] = $name . ':照合NG ' . $e->getMessage(); }
            try { $cf->invoke(null, $on['quota'], $owners, $on['fill'], $on['shortfalls'], null); $ng[] = $name . ':補正の記録なしでも照合が通ってしまった'; } catch (\Throwable $e) { }
            $mv = $ms['metrics'];
            $applied[] = sprintf('%s %s帯→%s帯（%d番を外し%d番を入れる）波乱%s・下位%s・大穴%s 前[%s]→後[%s] サーバ確認の根拠 %s', $name, $ms['source_band'], $ms['target_band'], $ms['removed'], $ms['added'],
                $mv['wave'] ?? '-', $mv['lower'] ?? '-', $mv['big'] ?? '-', implode(',', $b), implode(',', $a), json_encode($ms['server_place_check'] ? ['field' => $ms['server_place_check']['field'], 'points' => array_values(array_filter($ms['server_place_check']['checks'], fn($c) => $c['result'] === 'SATISFIED'))[0]['points'] ?? null] : null, JSON_UNESCAPED_UNICODE));
        } elseif ($a === $b) { $same++; } else { $diffNoApply[] = $name; }
    }
    fwrite(STDERR, "\r\033[K");
    ksort($why);
    echo "{$d}\n  判定の内訳: " . json_encode($why, JSON_UNESCAPED_UNICODE) . "\n";
    echo '  変わらない: ' . $same . ' ／ 補正あり: ' . count($applied) . ' ／ 補正なしなのに候補が違う: ' . count($diffNoApply) . ' ／ 補正ありの照合 OK ' . $guard['ok'] . ' NG ' . $guard['ng'] . "\n";
    foreach ($applied as $l) { echo '  ・' . $l . "\n"; }
    foreach ($mism as $l) { echo '  ・一致しない: ' . $l . "\n"; }
    if ($slow) { echo '  時間がかかったレース: ' . implode(' ／ ', $slow) . "\n"; }
    if ($ng) { echo '  止まったもの: ' . implode(' ／ ', array_slice($ng, 0, 8)) . "\n"; }
}

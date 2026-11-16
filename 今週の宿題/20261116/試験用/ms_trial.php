<?php
// 20261116 段階2の確認その2：実際の11/14・15は波乱度が高いレースが無く、補正が一度も起きなかった。
// そこで1stの3指標の値だけを「波乱5・大穴5」「波乱5・下位5」に差し替えて、実データで補正の流れ（サーバによる複勝の確認・入れ替え・照合）が正しく動くかを見る（保存しない・AIへ送らない）
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
$trials = ['大穴' => ['wave' => 5, 'lower' => null, 'big' => 5], '下位' => ['wave' => 5, 'lower' => 5, 'big' => null]];
foreach (array_slice($argv, 1) ?: ['2026-11-14', '2026-11-15'] as $d) {
    $why = []; $applied = []; $ng = []; $guard = ['ok' => 0, 'ng' => 0]; $count = 0;
    foreach (DB::table('baganriki_publications as p')->join('baganriki_snapshots as s', 's.id', '=', 'p.snapshot_id')
               ->where('s.race_id', 'like', str_replace('-', '', $d) . '-%')->where('s.generation', 0)->orderBy('s.race_id')->get(['s.*', 'p.candidates']) as $snap) {
        $name = substr($snap->race_id, 9);
        fwrite(STDERR, "\r\033[K  … " . $name . " 計算中");
        $read = function ($role) use ($c, $snap) {
            $a = $c->acceptedHorsesOf((int) $snap->id, $role);
            $rk = (($a['ok'] ?? false) && ($a['complete'] ?? false) === true) ? $c->markResponseUsed((int) $snap->id, $role, $a) : null;
            return [$a, $rk];
        };
        $live = DB::table('baganriki_live_states')->where('snapshot_id', $snap->id)->first();
        $cancel = array_map('intval', (array) json_decode((string) ($live->cancellations ?? '[]'), true));
        $active = array_values(array_diff(array_map('intval', (array) json_decode((string) $snap->frozen_nums, true)), $cancel));
        sort($active);
        $stored = array_column(json_decode((string) $snap->candidates, true), 'horse_no');
        $count++;
        foreach ($trials as $label => $vals) {
            try {
                MS::$force = true; MS::$testValues = $vals;
                $on = S::submittedPublication($snap, $active, $read);
            } catch (\Throwable $e) { $ng[] = $name . ' ' . $label . ':' . mb_substr($e->getMessage(), 0, 60); continue; }
            finally { MS::$force = null; MS::$testValues = null; }
            $ms = $on['metrics_selection'] ?? null;
            $key = $label . ':' . ($ms['reason'] ?? ($ms['status'] ?? '記録なし'));
            $why[$key] = ($why[$key] ?? 0) + 1;
            $a = array_column($on['candidates'], 'horse_no');
            if (($ms['status'] ?? null) !== 'APPLIED') {
                if ($a !== $stored) { $ng[] = $name . ' ' . $label . ':補正なしなのに候補が保存済みと違う'; }
                continue;
            }
            $owners = []; foreach ($on['candidates'] as $row) { $owners[$row['horse_no']] = $row['band']; }
            try { $cf->invoke(null, $on['quota'], $owners, $on['fill'], $on['shortfalls'], $ms); $guard['ok']++; } catch (\Throwable $e) { $guard['ng']++; $ng[] = $name . ':照合NG ' . mb_substr($e->getMessage(), 0, 60); }
            try { $cf->invoke(null, $on['quota'], $owners, $on['fill'], $on['shortfalls'], null); $ng[] = $name . ':補正の記録なしでも照合が通ってしまった'; } catch (\Throwable $e) { }
            if (count($a) !== count($stored)) { $ng[] = $name . ' ' . $label . ':頭数が変わった'; }
            if (in_array($ms['removed'], $a, true) || !in_array($ms['added'], $a, true)) { $ng[] = $name . ' ' . $label . ':入れ替えが候補に反映されていない'; }
            $applied[] = sprintf('%s 試し%s %s帯→%s帯（%d番を外し%d番を入れる）前[%s]→後[%s] 割り当て %s→%s サーバ確認の根拠 %s', $name, $label, $ms['source_band'], $ms['target_band'], $ms['removed'], $ms['added'],
                implode(',', $stored), implode(',', $a), json_encode($ms['base_quota']), json_encode($ms['effective_quota']), json_encode($ms['server_place_check'] ? ['field' => $ms['server_place_check']['field'], 'points' => array_values(array_filter($ms['server_place_check']['checks'], fn($c) => $c['result'] === 'SATISFIED'))[0]['points'] ?? null] : null, JSON_UNESCAPED_UNICODE));
        }
    }
    fwrite(STDERR, "\r\033[K");
    ksort($why);
    echo "{$d}（{$count}レース × 2通りの試し）\n  判定の内訳: " . json_encode($why, JSON_UNESCAPED_UNICODE) . "\n";
    echo '  補正あり: ' . count($applied) . ' ／ 補正ありの照合 OK ' . $guard['ok'] . ' NG ' . $guard['ng'] . ' ／ おかしいもの ' . count($ng) . "\n";
    foreach ($applied as $l) { echo '  ・' . $l . "\n"; }
    if ($ng) { echo '  おかしいもの: ' . implode(' ／ ', array_slice($ng, 0, 10)) . "\n"; }
}

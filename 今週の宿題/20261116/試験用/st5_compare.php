<?php
// 20261116 段階5-2：作業2（古い過去レース）を足すと、最終の公開候補がどう変わるか（なる02 の3）。保存しない・AIへ送らない・設定は無効のまま。
//   ・起点は保存済みの凍結入力（DB から材料を集め直さない）。E3・E5 の過去レースの部分だけを計算し直す。
//   ・まず「今の過去レースだけ」で計算し直して、保存済みの凍結入力と1バイトも違わない（snapshot_hash が同じ）ことを確かめる。
//   ・次に古い過去レースを足して計算し直し、変わったのが E3・E5 の部分（expected_v01）だけであることを確かめる。
//   ・その入力で、本番と同じ submittedPublication（UNVERIFIED 行の補い・両AIの統合・取消・帯の間の補い・3指標の補正まで）を通し、最終の公開候補を比べる。
//   ・古い過去レースの結果は、確認台帳を作った 11/16 より前には使えなかったもの。ここでは効果の試算として使う（EFFECT_TRIAL_NOT_AVAILABLE_THEN）。
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiCandidateSelection as S;
use App\Http\Controllers\Api\BaganrikiExpectedBridge as Bridge;
use App\Http\Controllers\Api\BaganrikiFrozenInput as FI;
use App\Http\Controllers\Api\BaganrikiPrompt;
use Illuminate\Support\Facades\DB;
class_exists(AiController::class);
$c = app(AiController::class);
$hist = new ReflectionMethod(AiController::class, 'expectedHistory'); $hist->setAccessible(true);
$cur = new ReflectionMethod(AiController::class, 'expectedCurrent'); $cur->setAccessible(true);
echo '本番の設定：古い過去レース ' . var_export(AiController::expectedOldHistoryEnabled(), true) . "（false なら無効のまま）\n";
foreach (array_slice($argv, 1) ?: ['2026-11-14', '2026-11-15'] as $d) {
    AiController::$expectedOldHistoryForce = false;
    $hOld = $hist->invoke(null, $d);
    AiController::$expectedOldHistoryForce = true; AiController::$expectedOldHistoryEffectTrial = true;
    $hNew = $hist->invoke(null, $d);
    $audit = AiController::$expectedOldHistoryAudit;
    AiController::$expectedOldHistoryForce = null; AiController::$expectedOldHistoryEffectTrial = false;
    $nOld = count(array_filter($hNew, fn($r) => ($r['history_policy'] ?? null) === AiController::EXPECTED_HISTORY_POLICY));
    echo "{$d}：過去レース 今 " . count($hOld) . ' → 足した後 ' . count($hNew) . "（古い過去レース {$nOld}。印 " . ($hNew ? (end($hNew)['result_time_status'] ?? '-') : '-') . "）\n";
    $t = ['races' => 0, 'reproduced' => 0, 'not_reproduced' => [], 'only_e_changed' => 0, 'other_changed' => [], 'packet_changed' => 0,
          'final_same_as_stored' => 0, 'final_diff_stored' => [], 'final_changed' => [], 'errors' => []];
    $dec = ['old' => [], 'new' => []]; $roleChanged = 0;
    foreach (DB::table('baganriki_publications as p')->join('baganriki_snapshots as s', 's.id', '=', 'p.snapshot_id')
               ->where('s.race_id', 'like', str_replace('-', '', $d) . '-%')->where('s.generation', 0)->orderBy('s.race_id')->get(['s.*', 'p.candidates']) as $snap) {
        $name = substr($snap->race_id, 9); $t['races']++;
        fwrite(STDERR, "\r\033[K  … {$name} 計算中");
        try {
            // 保存済みの凍結入力から、E3・E5 を付ける前の共通部分を取り出す
            $in = FI::forInspection($snap, '1st');
            $base = $in;
            foreach (['expected_v01', 'snapshot_hash', 'ai_role', 'role_cap', 'role_quota'] as $k) { unset($base[$k]); }
            $current = $cur->invoke(null, $base, []);
            $old = Bridge::attach($base, $current, $hOld)['common'];
            $hashOld = BaganrikiPrompt::commonHash($old);
            if (!$hashOld['ok'] || $hashOld['hash'] !== $snap->snapshot_hash) { $t['not_reproduced'][] = $name; continue; }
            $t['reproduced']++;
            // 古い過去レース（取得時刻なし）は、方針が有効なときだけ検査を通る。試験の間だけ有効にする
            AiController::$expectedOldHistoryForce = true;
            try { $att = Bridge::attach($base, $current, $hNew); } finally { AiController::$expectedOldHistoryForce = null; }
            $new = $att['common'];
            if ($t['races'] === 1) { echo '  （1レース目の E3・E5 の監査）' . mb_substr(json_encode(array_diff_key($att['audit'], ['expected_proof' => 1]), JSON_UNESCAPED_UNICODE), 0, 600) . "\n"; }
            $diffKeys = [];
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
                if (json_encode($old[$k] ?? null) !== json_encode($new[$k] ?? null)) { $diffKeys[] = $k; }
            }
            if ($diffKeys === [] ) { $t['only_e_changed']++; }
            elseif ($diffKeys === ['expected_v01']) { $t['only_e_changed']++; $t['packet_changed']++; }
            else { $t['other_changed'][] = $name . ':' . implode(',', $diffKeys); continue; }
            $newPacket = $new['expected_v01'];
        } catch (\Throwable $e) { $t['errors'][] = $name . ' 組み立て:' . mb_substr($e->getMessage(), 0, 60); continue; }

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
        try {
            S::$testInputOverride = null;
            $o = S::submittedPublication($snap, $active, $read); $rolesOld = S::$lastProjected;
            S::$testInputOverride = static function (array $input, string $role) use ($newPacket) { $input['expected_v01'] = $newPacket; return $input; };
            $n = S::submittedPublication($snap, $active, $read); $rolesNew = S::$lastProjected;
        } catch (\Throwable $e) { $t['errors'][] = $name . ' 選出:' . mb_substr($e->getMessage(), 0, 60); continue; }
        finally { S::$testInputOverride = null; }
        foreach (['old' => $rolesOld, 'new' => $rolesNew] as $k => $roles) {
            foreach ($roles as $r) { foreach ((array) ($r['expected_reviews'] ?? []) as $band => $rv) { $x = $rv['decision'] ?? '-'; $dec[$k][$x] = ($dec[$k][$x] ?? 0) + 1; } }
        }
        foreach ($rolesOld as $i => $r) { if (($r['public_candidates'] ?? null) !== ($rolesNew[$i]['public_candidates'] ?? null)) { $roleChanged++; } }
        $a = array_column($o['candidates'], 'horse_no'); $b = array_column($n['candidates'], 'horse_no');
        if ($a === $stored) { $t['final_same_as_stored']++; } else { $t['final_diff_stored'][] = $name; }
        if ($a !== $b) { $t['final_changed'][] = sprintf('%s 前[%s]→後[%s]', $name, implode(',', $a), implode(',', $b)); }
    }
    fwrite(STDERR, "\r\033[K");
    echo "  レース {$t['races']} ／ 保存済みの凍結入力を1バイトも違わず作り直せた {$t['reproduced']}" . ($t['not_reproduced'] ? '（できなかった：' . implode(' ', $t['not_reproduced']) . '）' : '') . "\n";
    echo "  古い過去レースを足して、変わったのが E3・E5 の部分だけ {$t['only_e_changed']}（うち E3・E5 の中身が変わった {$t['packet_changed']}）" . ($t['other_changed'] ? ' ／ ★ほかも変わった：' . implode(' ', $t['other_changed']) : '') . "\n";
    echo "  足す前の最終候補が保存済みの公開と同じ {$t['final_same_as_stored']}" . ($t['final_diff_stored'] ? '（違う：' . implode(' ', $t['final_diff_stored']) . '）' : '') . "\n";
    echo '  帯ごとの判定（両役割の合計。単位＝役割×帯）足す前 ' . json_encode($dec['old']) . ' ／ 足した後 ' . json_encode($dec['new']) . "\n";
    echo "  役割ごとの候補が変わった数（単位＝役割） {$roleChanged}\n";
    echo '  最終の公開候補が変わったレース（単位＝レース） ' . count($t['final_changed']) . "\n";
    foreach ($t['final_changed'] as $l) { echo "  ・{$l}\n"; }
    if ($t['errors']) { echo '  ★うまくいかなかった ' . implode(' ／ ', $t['errors']) . "\n"; }
    echo '  古い過去レースの監査 ' . json_encode(['adopted' => $audit['adopted'] ?? null, 'excluded' => $audit['excluded'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";
}

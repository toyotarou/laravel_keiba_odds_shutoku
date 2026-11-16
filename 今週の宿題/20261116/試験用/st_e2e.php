<?php
// 20261116 なる11/16 仕上げ②③の確認（本番の記録は書き換えない：全部を1つの取引の中で行い、最後に必ず取り消す（rollback）。AIへは送らない）
//   ③ 入替が成立した候補 → 公開記録の保存（本番と同じ publishPrediction）→ 保存した記録の読み出し（BaganrikiPublishedRead::load）
//      → アプリへ返す答え（本番と同じ predictionAnswerFor）を、B帯へ入れる例（11/15 の実データ・指標だけ試しの値）と、
//      D帯へ入れる例（人工データ：D帯の馬の複勝を 12＞9＞6 に置き換える）で1件ずつ通す。
//   ② 凍結時の仕様の記録が無い・一致しない場合は補正しない。凍結の処理が仕様の記録を残す。
require '/var/www/horse_odds_finder/vendor/autoload.php';
$app = require '/var/www/horse_odds_finder/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiCandidateSelection as S;
use App\Http\Controllers\Api\BaganrikiMetricsSelection as MS;
use App\Http\Controllers\Api\BaganrikiPublishedRead as PR;
use App\Http\Controllers\Api\BaganrikiWorker as W;
use App\Http\Controllers\Api\BaganrikiTime as T;
use Illuminate\Support\Facades\DB;
class_exists(AiController::class);
$c = app(AiController::class);
$V6 = 'COMPACT_TEXT_20261109_06';
$pubCount0 = DB::table('baganriki_publications')->count();
$pubHash0 = md5((string) DB::table('baganriki_publications')->orderBy('id')->get()->toJson());
$evCount0 = DB::table('baganriki_audit_events')->count();
$ok = 0; $ng = 0;
$chk = function (bool $cond, string $label) use (&$ok, &$ng) { $cond ? $ok++ : $ng++; echo '    ' . ($cond ? 'OK ' : '★NG ') . $label . "\n"; };
$raceArgs = function (string $rid) {
    $p = explode('-', $rid); $d = substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2);
    return DB::table('t_horse_odds_finder_races')->where('date', $d)->where('race', (int) $p[4])->get()
        ->first(fn($r) => AiController::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, (int) $r->race) === $rid);
};
$clearState = function () { S::$testInputOverride = null; MS::$testValues = null; MS::$force = null; W::setTestNow(null); };
// 本番と同じ流れで、1レースを公開し直す（この取引の中だけ）
$republish = function (object $snap, object $stored) use ($c) {
    DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->delete();
    DB::table('baganriki_publication_intents')->where('snapshot_id', $snap->id)->delete();
    // 公開の版も「まだ公開していない」状態に戻す（この取引の中だけ。最後に取り消す）
    DB::table('baganriki_live_states')->where('snapshot_id', $snap->id)->update(['latest_publication_id' => null, 'publication_revision' => 0]);
    W::setTestNow(T::us(AiController::dbTimeToRfc3339($stored->published_at)));
    return $c->publishPrediction((int) $snap->id);
};
$addSpec = function (object $snap, array $spec) {
    DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', MS::SPEC_EVENT)->delete();
    DB::table('baganriki_audit_events')->insert(['race_id' => $snap->race_id, 'snapshot_id' => $snap->id, 'kind' => MS::SPEC_EVENT,
        'happened_at' => now(), 'old_revision' => null, 'new_revision' => null, 'diagnostics' => json_encode($spec), 'related_id' => null]);
};
// D帯の例の人工データ：D帯の馬の place_max の 12・9・6分前を 9.0・8.0・7.0 にする（それ以外は実データのまま）
$artD = function (array $input) {
    $d = [];
    foreach ((array) $input['runners'] as $r) { if (($r['band'] ?? null) === 'D') { $d[(int) $r['horse_no']] = true; } }
    foreach ($input['source_registry'] as $k => $e) {
        if (($e['field'] ?? null) === 'place_max' && isset($d[$e['horse_nos'][0] ?? 0]) && in_array($e['slot_offset'] ?? null, [12, 9, 6], true)) {
            $input['source_registry'][$k]['value'] = ['12' => 9.0, '9' => 8.0, '6' => 7.0][(string) $e['slot_offset']];
        }
    }
    return $input;
};

$cases = [
    ['B帯へ入れる例（11/15 福島5R の実データ。指標だけ「波乱5・下位5」の試しの値）', '20261115-3-03-4-5', ['wave' => 5, 'lower' => 5, 'big' => null], false, 'B'],
    ['D帯へ入れる例（人工データ：D帯の馬の複勝を 12＞9＞6 に置き換え。指標は「波乱5・大穴5」の試しの値）', null, ['wave' => 5, 'lower' => null, 'big' => 5], true, 'D'],
];
DB::beginTransaction();
try {
    foreach ($cases as [$title, $rid, $vals, $art, $band]) {
        echo "【{$title}】\n";
        $mk = function (array $input, string $role) use ($V6, $art, $artD) { $input['prompt_text_revision'] = $V6; return $art ? $artD($input) : $input; };
        // D帯の例は、入替が成立するレースを 11/15 から探す（この時点では保存しない）
        if ($rid === null) {
            foreach (DB::table('baganriki_snapshots')->where('race_id', 'like', '20261115-%')->where('generation', 0)->orderBy('race_id')->get() as $s) {
                $live = DB::table('baganriki_live_states')->where('snapshot_id', $s->id)->first();
                $cancel = array_map('intval', (array) json_decode((string) ($live->cancellations ?? '[]'), true));
                $active = array_values(array_diff(array_map('intval', (array) json_decode((string) $s->frozen_nums, true)), $cancel)); sort($active);
                $read = function ($role) use ($c, $s) { $a = $c->acceptedHorsesOf((int) $s->id, $role);
                    return [$a, (($a['ok'] ?? false) && ($a['complete'] ?? false) === true) ? $c->markResponseUsed((int) $s->id, $role, $a) : null]; };
                try { S::$testInputOverride = $mk; MS::$testValues = $vals; MS::$force = true; $t = S::submittedPublication($s, $active, $read); }
                catch (\Throwable $e) { continue; } finally { $clearState(); }
                if (($t['metrics_selection']['status'] ?? null) === 'APPLIED' && $t['metrics_selection']['target_band'] === 'D') { $rid = $s->race_id; break; }
            }
            if ($rid === null) { echo "    ★D帯へ入れられるレースが見つからない\n"; $ng++; continue; }
        }
        $snap = DB::table('baganriki_snapshots')->where('race_id', $rid)->where('generation', 0)->first();
        $stored = DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->first();
        $storedNos = array_column(json_decode((string) $stored->candidates, true), 'horse_no');
        echo "    レース {$rid} ／ 保存済みの公開（_05） [" . implode(',', $storedNos) . "]\n";

        // ② 凍結時の仕様の記録が無い → 補正しない
        S::$testInputOverride = $mk; MS::$testValues = $vals;
        DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', MS::SPEC_EVENT)->delete();
        $r0 = $republish($snap, $stored);
        $p0 = DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->first();
        $m0 = json_decode((string) ($p0->evidence ?? ''), true)['metrics_selection'] ?? null;
        $chk(($r0['ok'] ?? false) && ($m0['reason'] ?? null) === 'SPEC_NOT_FROZEN' && array_column($r0['candidates'], 'horse_no') === $storedNos,
            '凍結時の仕様の記録が無い → 補正しない（理由 ' . ($m0['reason'] ?? ($r0['code'] ?? '-') . ' ' . mb_substr((string) ($r0['reason'] ?? ''), 0, 80)) . '）・候補は保存済みと同じ');
        // ② 仕様が一致しない（凍結の後でパラメータが変わった）→ 補正しない
        $addSpec($snap, ['policy' => MS::POLICY, 'prompt_text_revision' => $V6, 'enabled' => true, 'params_sha256' => str_repeat('0', 64)]);
        $r1 = $republish($snap, $stored);
        $m1 = json_decode((string) (DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->first()->evidence ?? ''), true)['metrics_selection'] ?? null;
        $chk(($r1['ok'] ?? false) && ($m1['reason'] ?? null) === 'SPEC_MISMATCH' && array_column($r1['candidates'], 'horse_no') === $storedNos,
            '凍結時の仕様と今の仕様が一致しない → 補正しない（理由 ' . ($m1['reason'] ?? ($r1['code'] ?? '-')) . '）');

        // ③ 凍結時の仕様の記録が正しい → 入替 → 保存
        $addSpec($snap, MS::specFor($V6));
        $r = $republish($snap, $stored);
        $pub = DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->first();
        if ($pub === null) { $chk(false, '公開できない：' . ($r['code'] ?? '-') . ' ' . mb_substr((string) ($r['reason'] ?? ''), 0, 80)); $clearState(); continue; }
        $ev = json_decode((string) $pub->evidence, true); $ms = $ev['metrics_selection'] ?? null;
        $chk(($r['ok'] ?? false) && ($ms['status'] ?? null) === 'APPLIED' && ($ms['target_band'] ?? null) === $band,
            "公開（publishPrediction）{$band}帯への入替が成立：" . ($ms['source_band'] ?? '-') . '帯の' . ($ms['removed'] ?? '-') . '番を外し、' . ($ms['target_band'] ?? '-') . '帯の' . ($ms['added'] ?? '-') . '番を入れる（' . ($r['code'] ?? 'ok') . '）');
        if (($ms['status'] ?? null) !== 'APPLIED') { continue; }
        $saved = array_column(json_decode((string) $pub->candidates, true), 'horse_no');
        $chk($saved === $ms['after'], '保存した候補 [' . implode(',', $saved) . '] ＝ 入替の after [' . implode(',', $ms['after']) . ']');
        $chk(count($saved) === count($storedNos), '総数が変わらない（' . count($saved) . '頭）');
        $chk(($ms['frozen_spec']['params_sha256'] ?? null) === MS::paramsSha() && ($ms['server_place_check']['horse_no'] ?? null) === $ms['added'],
            '公開記録に、使った凍結済みの仕様（照合値 ' . substr(MS::paramsSha(), 0, 12) . '…）とサーバ確認の根拠が残っている');
        $quotaSaved = json_decode((string) $pub->publication_quota, true);
        echo '    保存した配分 ' . json_encode($quotaSaved) . ' ／ 入替の配分 ' . json_encode($ms['effective_quota']) . "\n";

        // ③ 読み出し（保存した記録の照合・再実行）
        $live = DB::table('baganriki_live_states')->where('snapshot_id', $snap->id)->first();
        try { $view = PR::load($snap, $live); $chk(true, '保存した記録の読み出し（BaganrikiPublishedRead::load の照合）が通る'); }
        catch (\Throwable $e) { $chk(false, '保存した記録の読み出し：' . $e->getMessage()); }
        // ③ アプリへ返す答え（本番と同じ入口）
        $row = $raceArgs($rid);
        AiController::$answerTestGeneration = 0;   // 対象レースの判定だけ飛ばす（読むのは本番と同じ世代0）
        try { $ans = $c->predictionAnswerFor($row->date, $row->kaisuu, $row->basho, $row->day, $row->race); } finally { AiController::$answerTestGeneration = null; }
        if (($ans['second']['not_ready'] ?? false) === true || $ans === null) { echo '    答え：' . json_encode($ans === null ? null : array_intersect_key($ans['second'] ?? [], array_flip(['not_ready', 'reason', 'status'])), JSON_UNESCAPED_UNICODE) . "\n"; }
        $hs = (array) ($ans['second']['merged_horses'] ?? []);
        $nums = array_map(fn($h) => (int) $h['num'], $hs); $sortedA = $nums; sort($sortedA); $sortedB = $ms['after']; sort($sortedB);
        $chk($sortedA === $sortedB, 'アプリへの答えの馬 [' . implode(',', $nums) . '] ＝ 入替の after（並びは画面の順）');
        $marked = array_values(array_filter($hs, fn($h) => !empty($h['metrics_slot'])));
        $chk(count($marked) === 1 && (int) $marked[0]['num'] === $ms['added'], '「指標反映」の印は入った ' . $ms['added'] . '番の1頭だけ');
        $chk(!array_filter($hs, fn($h) => !empty($h['metrics_slot']) && !empty($h['fill_slot'])), '「指標反映」と「補充」の印が同じ馬に重ならない');
        // 点数：入替が無い場合の同じ馬の点数と同じ（指標反映を理由に足さない）
        S::$testInputOverride = $mk; MS::$testValues = $vals; MS::$force = false;
        $readR = function ($role) use ($c, $snap) { $a = $c->acceptedHorsesOf((int) $snap->id, $role);
            return [$a, (($a['ok'] ?? false) && ($a['complete'] ?? false) === true) ? $c->markResponseUsed((int) $snap->id, $role, $a) : null]; };
        $cancel = array_map('intval', (array) json_decode((string) ($live->cancellations ?? '[]'), true));
        $active = array_values(array_diff(array_map('intval', (array) json_decode((string) $snap->frozen_nums, true)), $cancel)); sort($active);
        $noAdj = S::submittedPublication($snap, $active, $readR); MS::$force = null;
        $scoreOf = []; foreach (json_decode((string) $pub->candidates, true) as $cRow) { $scoreOf[$cRow['horse_no']] = $cRow; }
        $same = true; foreach ($noAdj['candidates'] as $cRow) { if (isset($scoreOf[$cRow['horse_no']]) && json_encode($scoreOf[$cRow['horse_no']]) !== json_encode($cRow)) { $same = false; } }
        $addedRow = $scoreOf[$ms['added']] ?? [];
        $ansAdded = $marked[0] ?? [];
        $chk($same && (int) ($ansAdded['score'] ?? -1) === (int) ($addedRow['score'] ?? $addedRow['public_score'] ?? $ansAdded['score'] ?? -2),
            '点数：共通の馬の行は入替なしと同じ、入った馬の答えの点数は保存した行のまま（' . json_encode(array_intersect_key($addedRow, array_flip(['score', 'public_score', 'tier', 'band']))) . '）');
        // ③ 再実行で二重に入れ替わらない
        $again = $c->publishPrediction((int) $snap->id);
        $n = DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->count();
        $chk($n === 1 && array_column((array) ($again['candidates'] ?? []), 'horse_no') === $saved,
            '同じ入力・回答・凍結済み仕様で再実行：公開は1件のまま・候補は同じ（' . (($again['reused'] ?? false) ? '保存済みを再利用' : (string) ($again['code'] ?? '')) . '）');
        $clearState();
    }
    // ② 凍結の処理が仕様の記録を残す（REPLAY の凍結を1件作って確かめる。取り消す）
    echo "【凍結の処理が、選出仕様の記録を残すか（11/15 京都1R を試験用の世代で凍結し直す。取り消す）】\n";
    $r = DB::table('t_horse_odds_finder_races')->where('date', '2026-11-15')->where('basho', '08')->where('race', 1)->first();
    $ridF = AiController::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, (int) $r->race);
    DB::table('baganriki_snapshots')->where('race_id', $ridF)->where('generation', 900)->update(['generation' => 991]);   // 既にある試験用の世代をよける（取り消す）
    // 試験の時計を、そのレースの T−290秒（凍結できる時刻）に合わせる（合わせないと E3・E5 が「返事の後に計算した」扱いになる）
    W::setTestNow(T::us('2026-11-15T' . T::normalizeRaceStartClock((string) $r->start_time) . '+09:00') - 290 * 1000000);
    try { $o = $c->freezePredictionInput($r->date, $r->kaisuu, $r->basho, $r->day, $r->race, [], 'REPLAY', false); } finally { W::setTestNow(null); }
    if (!($o['ok'] ?? false)) { echo '    凍結できない理由：' . ($o['code'] ?? '-') . ' ' . mb_substr((string) ($o['reason'] ?? ''), 0, 120) . "\n"; }
    $sid = DB::table('baganriki_snapshots')->where('race_id', AiController::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, (int) $r->race))->where('generation', 900)->orderByDesc('id')->value('id');
    $sp = $sid ? DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', MS::SPEC_EVENT)->get() : collect();
    $spec = $sp->count() === 1 ? json_decode($sp[0]->diagnostics, true) : null;
    $chk(($o['ok'] ?? false) || !empty($o['skipped']) ? ($spec !== null && $spec['enabled'] === true && $spec['prompt_text_revision'] === $V6 && $spec['params_sha256'] === MS::paramsSha()) : false,
        '凍結（' . (($o['ok'] ?? false) ? 'OK' : ($o['code'] ?? '-')) . '）で記録 ' . json_encode($spec ? array_intersect_key($spec, array_flip(['policy', 'prompt_text_revision', 'enabled'])) + ['params_sha256' => substr($spec['params_sha256'], 0, 12) . '…'] : null, JSON_UNESCAPED_UNICODE));
} finally {
    $clearState();
    DB::rollBack();
}
$chk(DB::table('baganriki_publications')->count() === $pubCount0 && md5((string) DB::table('baganriki_publications')->orderBy('id')->get()->toJson()) === $pubHash0
     && DB::table('baganriki_audit_events')->count() === $evCount0, '取り消した後、公開記録・監査の記録は試験の前と同じ（旧版の保存結果は変わらない）');
echo "合計 OK {$ok} / NG {$ng}\n";

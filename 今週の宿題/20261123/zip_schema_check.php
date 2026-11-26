<?php
/**
 * なるの元ファイル（ZIP）の「回答の形」（output_schema.json）に替えると、実際のAIの返事の採否がどう変わるかを数えます。
 *   ・AIへは送りません（0円）。DBは読むだけ（検査は「試しの検査」＝保存しない。念のため毎回ロールバック）
 *   ・使う返事：11/21〜23 の本番の返事（旧版・136件）と、11/25 朝の非公開試験の返事（E5専用版・4件）
 *   ・base … いま123に入っている形で検査
 *   ・zip  … 一時フォルダに写しを作り、回答の形だけ ZIP のものに替えて検査
 *            （旧版の返事には「ZIPで増えた決まり」だけを旧版の形に足したもの＝10項目100点のまま）
 *   ・compare … 2つの結果を比べて表示
 * 使い方： php zip_schema_check.php base ／ zip ／ compare
 */
$mode = $argv[1] ?? '';
$resultFile = static fn(string $m): string => "/tmp/zip_schema_check_{$m}.json";
const OLD_VERSION = 'baganriki-v041-r12-2-w2-e35-r15-20261104';
const NEW_VERSION = 'baganriki-v041-r12-2-w2-e5-r15-score8-e5-r5-20261125';

/** 旧版（10項目100点）の形に、ZIPで増えた決まりだけを足したもの */
function hybridSchema(array $old, array $zip): array
{
    $h = $zip;
    $h['$id'] = $old['$id'];
    foreach (['prompt_version', 'schema_version'] as $k) { $h['properties'][$k] = $old['properties'][$k]; }
    $oi = $old['properties']['horses']['items'];
    foreach (['scores', 'score_evidence', 'observed_cap', 'observed_sum', 'total_score'] as $f) {
        $h['properties']['horses']['items']['properties'][$f] = $oi['properties'][$f];
    }
    $all = $h['properties']['horses']['items']['allOf'];
    // [0] 一部採点：どれか1つは null（V3・V4 も数える）。満点の上限 82 は8項目用なので外す
    if (($all[0]['if']['properties']['score_status']['const'] ?? null) !== 'partial'
        || ($all[1]['if']['properties']['score_status']['const'] ?? null) !== 'complete') {
        throw new RuntimeException('ZIPの形の並びが想定と違います');
    }
    foreach (['V3', 'V4'] as $k) {
        $all[0]['then']['properties']['scores']['anyOf'][] = ['properties' => [$k => ['type' => 'null']]];
        $all[1]['then']['properties']['scores']['properties'][$k] = ['type' => 'integer'];
        $all[] = ['if' => ['properties' => ['scores' => ['properties' => [$k => ['type' => 'integer']]]]],
                  'then' => ['properties' => ['score_evidence' => ['properties' => [$k => ['properties' => ['source_refs' => ['minItems' => 1]]]]]]]];
    }
    unset($all[0]['then']['properties']['observed_cap'], $all[0]['then']['properties']['observed_sum']);
    $all[1]['then']['properties']['observed_cap'] = ['const' => 100];
    $h['properties']['horses']['items']['allOf'] = $all;
    return $h;
}

if ($mode === 'selftest') {   // 手元での確認用（形の組み立てだけ）
    $h = hybridSchema(json_decode(file_get_contents($argv[2]), true), json_decode(file_get_contents($argv[3]), true));
    echo count($h['properties']['horses']['items']['allOf']), ' ', count($h['properties']['horses']['items']['properties']['scores']['required']), "\n";
    exit(0);
}

if ($mode === 'compare') {
    $base = json_decode((string) @file_get_contents($resultFile('base')), true);
    $zip  = json_decode((string) @file_get_contents($resultFile('zip')), true);
    if (!is_array($base) || !is_array($zip)) { echo "結果ファイルがありません（base と zip を先に動かしてください）\n"; exit(1); }
    echo "==== 比べた結果（base＝今の形／zip＝ZIPの形） ====\n";
    echo 'zip の検査が読んだ場所: ' . ($zip['dir'] ?? '?') . "\n";
    $sum = []; $changed = []; $newFind = []; $stageChanged = [];
    foreach ($base['rows'] as $id => $b) {
        $z = $zip['rows'][$id] ?? null;
        $g = ($b['version'] === NEW_VERSION ? 'E5専用版' : '旧版') . ' ' . $b['role'];
        $sum[$g] ??= ['replies' => 0, 'base_eligible' => 0, 'zip_eligible' => 0, 'base_kept' => 0, 'zip_kept' => 0, 'replies_changed' => 0, 'replies_lost_horses' => 0, 'missing' => 0];
        $sum[$g]['replies']++;
        if ($z === null) { $sum[$g]['missing']++; continue; }
        $sum[$g]['base_eligible'] += count($b['eligible']); $sum[$g]['zip_eligible'] += count($z['eligible']);
        $sum[$g]['base_kept'] += $b['kept']; $sum[$g]['zip_kept'] += $z['kept'];
        $same = $b['stage'] === $z['stage'] && $b['code'] === $z['code'] && $b['eligible'] === $z['eligible'] && $b['kept'] === $z['kept'];
        if (!$same) {
            $sum[$g]['replies_changed']++;
            if (count($z['eligible']) < count($b['eligible'])) { $sum[$g]['replies_lost_horses']++; }
            $changed[] = sprintf('  %s %s %s  %s/%s 残った行 %d→%d 候補になれる馬 %d→%d%s', $b['race_id'], $b['role'], $g,
                $b['stage'] . ':' . $b['code'], $z['stage'] . ':' . $z['code'], $b['kept'], $z['kept'], count($b['eligible']), count($z['eligible']),
                ($z['reason'] ?? '') !== ($b['reason'] ?? '') ? '  理由: ' . mb_substr((string) ($z['reason'] ?? ''), 0, 110) : '');
        }
        foreach ($z['findings'] as $key => $n) {
            $more = $n - ($b['findings'][$key] ?? 0);
            if ($more > 0) { $newFind[$g][$key] ??= [0, 0]; $newFind[$g][$key][0]++; $newFind[$g][$key][1] += $more; }
        }
    }
    foreach ($sum as $g => $s) {
        echo sprintf("[%s] 返事 %d件／採否や頭数が変わった返事 %d件（うち候補になれる馬が減った返事 %d件）／残った行 %d→%d／候補になれる馬 %d→%d%s\n",
            $g, $s['replies'], $s['replies_changed'], $s['replies_lost_horses'], $s['base_kept'], $s['zip_kept'], $s['base_eligible'], $s['zip_eligible'],
            $s['missing'] ? '／zip側に結果なし ' . $s['missing'] . '件' : '');
    }
    echo "---- 変わった返事（先頭40件） ----\n";
    foreach (array_slice($changed, 0, 40) as $line) { echo $line, "\n"; }
    if ($changed === []) { echo "  なし\n"; }
    echo "---- ZIPの形で新しく増えた指摘（多い順・版と役ごとに先頭25種） 「場所｜コード｜項目」 返事の数／件数 ----\n";
    foreach ($newFind as $g => $list) {
        uasort($list, static fn($a, $b) => $b[1] <=> $a[1]);
        echo "[{$g}] " . count($list) . "種\n";
        foreach (array_slice($list, 0, 25, true) as $key => [$replies, $n]) { echo sprintf("  %-90s 返事%d／%d件\n", $key, $replies, $n); }
    }
    if ($newFind === []) { echo "  なし\n"; }
    exit(0);
}

if (!in_array($mode, ['base', 'zip'], true)) { echo "使い方: php zip_schema_check.php base|zip|compare\n"; exit(1); }

$root = '/var/www/horse_odds_finder';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$NS = 'App\\Http\\Controllers\\Api\\';
$real = rtrim(storage_path('app/baganriki/prompts/expected'), '/');

if ($mode === 'zip') {
    $tmp = '/tmp/zip_schema_check_dir';
    $copy = static function (string $from, string $to) use (&$copy): void {
        if (is_dir($from)) {
            if (!is_dir($to) && !mkdir($to, 0755, true)) { throw new RuntimeException('フォルダを作れません ' . $to); }
            foreach (scandir($from) as $name) { if ($name !== '.' && $name !== '..') { $copy($from . '/' . $name, $to . '/' . $name); } }
        } elseif (!copy($from, $to)) { throw new RuntimeException('写せません ' . $from); }
    };
    $copy($real, $tmp);
    $zipSchemaText = file_get_contents('/tmp/zip_output_schema.json');
    $zipSchema = json_decode((string) $zipSchemaText, true, 512, JSON_THROW_ON_ERROR);
    $oldSchema = json_decode(file_get_contents($real . '/schemas/output_schema.json'), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($tmp . '/schemas/output_schema.json', json_encode(hybridSchema($oldSchema, $zipSchema), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($tmp . '/schemas/e5_score8/output_schema.json', $zipSchemaText);
    foreach (['BAGANRIKI_PREDICTION_EXPECTED_DIR' => $tmp] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
}
$dir = ($NS . 'BaganrikiExpectedFiles')::dir();
echo "検査が読む場所: {$dir}\n";
if ($mode === 'zip' && $dir !== '/tmp/zip_schema_check_dir') { echo "一時フォルダに切り替わっていません。中止します\n"; exit(1); }
if ($mode === 'base' && $dir !== $real) { echo "本番のフォルダではありません。中止します\n"; exit(1); }

$controller = app($NS . 'AiController');
$dryRaw = new ReflectionProperty($NS . 'AiController', 'dryInspectRaw');
$dryRaw->setAccessible(true);
$attempts = DB::table('baganriki_ai_attempts as a')->join('baganriki_snapshots as s', 's.id', '=', 'a.snapshot_id')
    ->where('a.attempt_no', 0)->where('a.status', 'RECEIVED')->where('s.created_at', '>=', '2026-11-20 00:00:00')
    ->where(static function ($q) {
        $q->where(static function ($x) { $x->where('s.release_version', OLD_VERSION)->where('s.generation', 0); })
          ->orWhere(static function ($x) { $x->where('s.release_version', NEW_VERSION)->where('s.generation', 900); });
    })
    ->orderBy('a.id')->get(['a.id', 'a.snapshot_id', 'a.role', 's.race_id', 's.release_version']);
$before = [DB::table('baganriki_validation_results')->count(), DB::table('baganriki_audit_events')->count(), DB::table('baganriki_publications')->count()];
$rows = []; $t0 = microtime(true);
foreach ($attempts as $a) {
    $raw = (string) DB::table('baganriki_ai_attempts')->where('id', $a->id)->value('raw_response');
    if ($raw === '') { continue; }
    DB::beginTransaction();
    try {
        $dryRaw->setValue($controller, $raw);
        $r = $controller->inspectAiResponse((int) $a->snapshot_id, (string) $a->role, 0);
    } catch (Throwable $e) {
        $r = ['stage' => 'exception', 'code' => get_class($e), 'reason' => $e->getMessage()];
    } finally {
        $dryRaw->setValue($controller, null);
        DB::rollBack();
    }
    $eligible = [];
    foreach ((array) ($r['normal_kept'] ?? []) as $h) {
        if (is_array($h) && ($h['eligibility'] ?? '') === 'ELIGIBLE' && is_int($h['horse_no'] ?? null)) { $eligible[] = $h['horse_no']; }
    }
    sort($eligible);
    $findings = [];
    foreach ((array) ($r['diag']['findings'] ?? []) as $f) {
        $code = (string) ($f['code'] ?? '?'); $item = '';
        if (str_starts_with($code, 'EXPECTED_SCHEMA') || $code === 'EXPECTED_RANK_SHAPE' || $code === 'EXPECTED_SCORE_PROOF_FIELDS' || $code === 'EXPECTED_EXTRA_ROW_FIELD') {
            $reason = (string) ($f['reason'] ?? ''); $at = strrpos($reason, ' ');
            $item = preg_replace('/(?<=[\[,])\d+(?=[,\]])/', '*', $at === false ? $reason : substr($reason, $at + 1));
        }
        $key = (string) ($f['scope'] ?? '?') . '｜' . $code . '｜' . $item;
        $findings[$key] = ($findings[$key] ?? 0) + 1;
    }
    $rows[(string) $a->id] = ['race_id' => (string) $a->race_id, 'role' => (string) $a->role, 'version' => (string) $a->release_version,
        'stage' => (string) ($r['stage'] ?? ''), 'code' => (string) ($r['code'] ?? ''), 'reason' => mb_substr((string) ($r['reason'] ?? ''), 0, 200),
        'kept' => count((array) ($r['normal_kept'] ?? [])), 'eligible' => $eligible, 'findings' => $findings];
}
$after = [DB::table('baganriki_validation_results')->count(), DB::table('baganriki_audit_events')->count(), DB::table('baganriki_publications')->count()];
file_put_contents($resultFile($mode), json_encode(['dir' => $dir, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
$stages = [];
foreach ($rows as $row) { $k = $row['stage'] . ':' . $row['code']; $stages[$k] = ($stages[$k] ?? 0) + 1; }
echo sprintf("%s: 返事 %d件を検査（%.0f秒）  結果の内訳 %s\n", $mode, count($rows), microtime(true) - $t0, json_encode($stages, JSON_UNESCAPED_UNICODE));
$groups = [];
foreach ($rows as $row) {
    $g = ($row['version'] === NEW_VERSION ? 'E5専用版' : '旧版') . ' ' . $row['role'];
    $groups[$g] ??= [0, 0, 0];
    $groups[$g][0]++; $groups[$g][1] += $row['kept']; $groups[$g][2] += count($row['eligible']);
}
foreach ($groups as $g => [$n, $kept, $eligible]) { echo sprintf("  [%s] 返事 %d件／残った行 %d／候補になれる馬 %d\n", $g, $n, $kept, $eligible); }
foreach ($rows as $row) { if ($row['stage'] !== 'ok') { echo '  検査が最後まで進まなかった返事: ' . $row['race_id'] . ' ' . $row['role'] . ' ' . $row['stage'] . ':' . $row['code'] . ' ' . mb_substr($row['reason'], 0, 90) . "\n"; } }
echo 'DBの件数（検査結果・記録・公開）前 ' . implode('/', $before) . ' → 後 ' . implode('/', $after) . ($before === $after ? '  変わっていません' : '  ★変わりました') . "\n";

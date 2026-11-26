<?php
/**
 * ZIPの「回答の形」で増えた決まりのうち、実際のAIの返事がどれに引っかかるかを、決まりごとに数えます。
 *   ・AIへは送りません（0円）。DBは読むだけ（何も書きません）
 *   ・使う返事：11/21〜23 の本番の返事（旧版）と、11/25 朝の非公開試験の返事（E5専用版）
 *   ・数えるのは「今の形では通るのに、ZIPの形では通らない」ものだけ
 * 使い方： php zip_rule_check.php            （123で）
 *          php zip_rule_check.php selftest <旧版の形> <ZIPの形> <Apiフォルダ>   （手元の確認用）
 */
const OLD_VERSION = 'baganriki-v041-r12-2-w2-e35-r15-20261104';
const NEW_VERSION = 'baganriki-v041-r12-2-w2-e5-r15-score8-e5-r5-20261125';

/** 旧版（10項目100点）の形に、ZIPで増えた決まりだけを足したもの（zip_schema_check.php と同じ） */
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

function short(mixed $v, int $n = 78): string
{
    $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $s = str_replace(['"properties":', '"', '{', '}'], ['', '', '', ''], (string) $s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

/**
 * 調べる決まりの一覧。[名前, ZIPの決まり, 今の決まり（同じ場所にあれば）]
 *   horse … 馬1頭の行に当てる／top … 返事全体に当てる
 */
function probes(array $old, array $zip): array
{
    $horse = []; $top = [];
    $oh = $old['properties']['horses']['items']; $zh = $zip['properties']['horses']['items'];
    $oldAll = array_map('json_encode', $oh['allOf'] ?? []);
    foreach ($zh['allOf'] as $i => $entry) {
        if (in_array(json_encode($entry), $oldAll, true)) { continue; }           // 今もある決まり
        $prev = $i < 2 ? ($oh['allOf'][$i] ?? null) : null;                        // 0＝一部採点・1＝全部採点は、今の決まりを厳しくしたもの
        $horse[] = ['馬の行 ' . short($entry['if'] ?? $entry, 60) . ' → ' . short($entry['then'] ?? '', 70), $entry, $prev];
    }
    foreach (($zh['properties']['family_review']['properties'] ?? []) as $family => $def) {
        foreach (($def['allOf'] ?? []) as $entry) {
            $wrap = ['properties' => ['family_review' => ['properties' => [$family => $entry]]]];
            $horse[] = ['5系統の確認 ' . $family . ' ' . short($entry['if'] ?? '', 40) . ' → ' . short($entry['then'] ?? '', 50) . (isset($entry['else']) ? ' ／他 ' . short($entry['else'], 30) : ''), $wrap, null];
        }
    }
    $ze = $zh['properties']['evidence']['items']['allOf'] ?? []; $oe = $oh['properties']['evidence']['items']['allOf'] ?? [];
    foreach ($ze as $i => $entry) {
        if (isset($oe[$i]) && json_encode($oe[$i]) === json_encode($entry)) { continue; }
        $horse[] = ['根拠の行（市場：支持加速は決まった観測文・出典3件、その他は決まった観測文・metric=null）',
            ['properties' => ['evidence' => ['items' => ['allOf' => [$entry]]]]],
            isset($oe[$i]) ? ['properties' => ['evidence' => ['items' => ['allOf' => [$oe[$i]]]]]] : null];
    }
    if (isset($zh['properties']['horse_no']['maximum'])) {
        $horse[] = ['馬番は18まで', ['properties' => ['horse_no' => ['maximum' => $zh['properties']['horse_no']['maximum']]]], null];
    }
    $oldTop = array_map('json_encode', $old['allOf'] ?? []);
    foreach ($zip['allOf'] as $entry) {
        if (in_array(json_encode($entry), $oldTop, true)) { continue; }
        $top[] = ['返事全体 ' . short($entry['if'] ?? '', 60) . ' → ' . short($entry['then'] ?? '', 70), $entry, null];
    }
    foreach (['A', 'B', 'D'] as $band) {
        $entries = $zip['properties']['expected_reviews']['properties'][$band]['allOf'] ?? [];
        $oldEntries = array_map('json_encode', $old['properties']['expected_reviews']['properties'][$band]['allOf'] ?? []);
        $new = array_values(array_filter($entries, static fn($e) => !in_array(json_encode($e), $oldEntries, true)));
        if ($new !== []) { $top[] = ['境目の2頭が同じ馬でないこと ' . $band . '帯', ['properties' => ['expected_reviews' => ['properties' => [$band => ['allOf' => $new]]]]], null]; }
    }
    $top[] = ['snapshot_hash は小文字16進64文字', ['properties' => ['snapshot_hash' => $zip['properties']['snapshot_hash']]], ['properties' => ['snapshot_hash' => $old['properties']['snapshot_hash']]]];
    foreach (['band_ranks', 'evidence_band_ranks', 'selection_ranks'] as $f) {
        $top[] = [$f . '（各帯18頭まで・馬番18まで）', ['properties' => [$f => $zip['properties'][$f]]], ['properties' => [$f => $old['properties'][$f]]]];
    }
    $top[] = ['public_candidates（馬番18まで）', ['properties' => ['public_candidates' => $zip['properties']['public_candidates']]], ['properties' => ['public_candidates' => $old['properties']['public_candidates']]]];
    return ['horse' => $horse, 'top' => $top];
}

$selftest = ($argv[1] ?? '') === 'selftest';
if ($selftest) {
    foreach (['BaganrikiJson', 'BaganrikiOutputSchema', 'BaganrikiExpectedPacket', 'BaganrikiText', 'BaganrikiDecimal'] as $class) {
        $file = rtrim($argv[4], '/') . '/' . $class . '.php';
        if (is_file($file)) { require_once $file; }
    }
    $oldSchema = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $zipSchema = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
} else {
    $root = '/var/www/horse_odds_finder';
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $real = rtrim(storage_path('app/baganriki/prompts/expected'), '/');
    $oldSchema = json_decode(file_get_contents($real . '/schemas/output_schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $zipSchema = json_decode(file_get_contents('/tmp/zip_output_schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $selfMade = json_decode(file_get_contents($real . '/schemas/e5_score8/output_schema.json'), true, 512, JSON_THROW_ON_ERROR);
}
$NS = 'App\\Http\\Controllers\\Api\\';
$issue = new ReflectionMethod($NS . 'BaganrikiOutputSchema', 'issue');
$issue->setAccessible(true);
$fails = static function (array $schema, mixed $value, array $map, array $path) use ($issue): ?array {
    try { return $issue->invokeArgs(null, [$schema, $value, &$map, $path]); }
    catch (Throwable $e) { return ['（検査が止まった ' . $e->getMessage() . '）']; }
};
$pattern = static fn(array $path): string => preg_replace('/(?<=[\[,])\d+(?=[,\]])/', '*', (string) json_encode(array_values($path), JSON_UNESCAPED_UNICODE));

$sets = [
    '旧版' => probes($oldSchema, hybridSchema($oldSchema, $zipSchema)),
];
if (!$selftest) { $sets['E5専用版'] = probes($selfMade, $zipSchema); }

if ($selftest) {
    echo '馬の行の決まり ' . count($sets['旧版']['horse']) . '／返事全体の決まり ' . count($sets['旧版']['top']) . "\n";
    $raw = '{"snapshot_hash":"ABC","target_count":7,"selection_enabled":true,"role_cap":7,"ai_role":"1st","public_candidates":[1],"expected_reviews":{"A":{"incumbent":2,"challenger":2,"decision":"KEEP"}},'
         . '"horses":[{"horse_no":1,"score_status":"partial","total_score":null,"scores":{"H1":3,"H2":null},"score_evidence":{"H1":{"source_refs":[],"reason":"x"}},"observed_cap":18,"observed_sum":3,'
         . '"tier":"A","eligibility":"ELIGIBLE","rank_basis":"SCORE","public_candidate":true,"nonselect_reason":null,'
         . '"family_review":{"market":{"status":"SUPPORTED","source_refs":[],"evidence_ids":[],"reason":"r"}},'
         . '"evidence":[{"family":"market","observation":"人気が上がった","market_claim":{"kind":"OTHER","metric":"win_odds"},"source_refs":["a"],"facts":[]}],"evidence_rank_evidence_ids":["e1"]}]}';
    $decoded = ($NS . 'BaganrikiJson')::decode($raw);
    if (!$decoded['ok']) { echo "読めません\n"; exit(1); }
    $rows = [['id' => 1, 'role' => '1st', 'race_id' => 'test', 'group' => '旧版', 'reply' => $decoded['value'], 'map' => $decoded['map']]];
} else {
    $attempts = DB::table('baganriki_ai_attempts as a')->join('baganriki_snapshots as s', 's.id', '=', 'a.snapshot_id')
        ->where('a.attempt_no', 0)->where('a.status', 'RECEIVED')->where('s.created_at', '>=', '2026-11-20 00:00:00')
        ->where(static function ($q) {
            $q->where(static function ($x) { $x->where('s.release_version', OLD_VERSION)->where('s.generation', 0); })
              ->orWhere(static function ($x) { $x->where('s.release_version', NEW_VERSION)->where('s.generation', 900); });
        })
        ->orderBy('a.id')->get(['a.id', 'a.role', 's.race_id', 's.release_version']);
    $rows = [];
    foreach ($attempts as $a) {
        $raw = (string) DB::table('baganriki_ai_attempts')->where('id', $a->id)->value('raw_response');
        try {
            $ex = ($NS . 'BaganrikiResponseFrame')::extract($raw);
            if (!($ex['ok'] ?? false)) { continue; }
            [$reply, $map] = ($NS . 'BaganrikiReplyTidy')::apply((array) $ex['json'], $ex['map'] ?? null);
        } catch (Throwable $e) { continue; }
        $rows[] = ['id' => (int) $a->id, 'role' => (string) $a->role, 'race_id' => (string) $a->race_id,
                   'group' => $a->release_version === NEW_VERSION ? 'E5専用版' : '旧版', 'reply' => $reply, 'map' => $map];
    }
}

$count = [];   // [group role][label] => ['replies'=>, 'hits'=>, 'where'=>[pattern=>n]]
$totals = [];  // [group role] => ['replies'=>, 'horses'=>, 'horses_hit'=>, 'replies_top_hit'=>]
foreach ($rows as $row) {
    $g = $row['group'] . ' ' . $row['role']; $reply = $row['reply']; $map = $row['map'];
    $totals[$g] ??= ['replies' => 0, 'horses' => 0, 'horses_hit' => 0, 'replies_top_hit' => 0];
    $totals[$g]['replies']++;
    $seen = []; $topHit = false;
    foreach ($sets[$row['group']]['top'] as [$label, $zipRule, $nowRule]) {
        $bad = $fails($zipRule, $reply, $map, []);
        if ($bad === null || ($nowRule !== null && $fails($nowRule, $reply, $map, []) !== null)) { continue; }
        $count[$g][$label] ??= ['replies' => 0, 'hits' => 0, 'where' => []];
        $count[$g][$label]['replies']++; $count[$g][$label]['hits']++;
        $w = $pattern($bad); $count[$g][$label]['where'][$w] = ($count[$g][$label]['where'][$w] ?? 0) + 1;
        $topHit = true;
    }
    if ($topHit) { $totals[$g]['replies_top_hit']++; }
    $horses = is_array($reply['horses'] ?? null) ? $reply['horses'] : [];
    foreach ($horses as $i => $horseRow) {
        if (!is_array($horseRow) || !is_int($i)) { continue; }
        $totals[$g]['horses']++; $hit = false;
        foreach ($sets[$row['group']]['horse'] as [$label, $zipRule, $nowRule]) {
            $bad = $fails($zipRule, $horseRow, $map, ['horses', $i]);
            if ($bad === null || ($nowRule !== null && $fails($nowRule, $horseRow, $map, ['horses', $i]) !== null)) { continue; }
            $count[$g][$label] ??= ['replies' => 0, 'hits' => 0, 'where' => []];
            if (!isset($seen[$label])) { $count[$g][$label]['replies']++; $seen[$label] = true; }
            $count[$g][$label]['hits']++;
            $w = $pattern($bad); $count[$g][$label]['where'][$w] = ($count[$g][$label]['where'][$w] ?? 0) + 1;
            $hit = true;
        }
        if ($hit) { $totals[$g]['horses_hit']++; }
    }
}
echo "==== ZIPの形で増えた決まりに、実際の返事が引っかかった数（今の形では通るものだけ） ====\n";
foreach ($totals as $g => $t) {
    echo sprintf("[%s] 返事 %d件・馬の行 %d行／新しい決まりに引っかかった行 %d行／返事全体の決まりに引っかかった返事 %d件\n", $g, $t['replies'], $t['horses'], $t['horses_hit'], $t['replies_top_hit']);
    $list = $count[$g] ?? [];
    uasort($list, static fn($a, $b) => $b['hits'] <=> $a['hits']);
    foreach ($list as $label => $c) {
        arsort($c['where']);
        echo sprintf("  %4d件（返事%3d件） %s\n", $c['hits'], $c['replies'], $label);
        foreach (array_slice($c['where'], 0, 3, true) as $w => $n) { echo sprintf("          場所 %s ×%d\n", $w, $n); }
    }
    if ($list === []) { echo "  引っかかったものはありません\n"; }
}

<?php
namespace App\Console\Commands\AiAnalysisCompensate;

use App\Http\Controllers\Api\AiController;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 両AIの本文・入出力の決まり（契約）の確認（--prediction-prompt-○○・--prediction-cases など）
 *
 * keiba:ai-analysis-compensate（SummaryAiAnalysisCompensate）の一部です。
 * 中身は元の SummaryAiAnalysisCompensate.php から、1文字も変えずに移しました。
 */
trait PredictionPromptContractChecks
{
    /**
     * @ANCHOR-20261020-R122CASES
     * 先方が同梱した合成試験（validationフォルダ）の期待値と突き合わせます。
     *   mode_cases.json                729件（X1〜X5・Zの三値を全部並べたもの）
     *   acquisition_boundary_cases.json  4件（30秒／120秒ちょうどと直後）
     * DBも外部APIも使いません。読み取りだけです。
     */
    private function runPredictionCases(string $dir): void
    {
        $this->line('');
        $this->line('══════════════════════════════════════════════════════════');
        $this->line(' 新方式 合成試験の突き合わせ（先方の期待値との比較）');
        $this->line('  対象フォルダ: ' . $dir);
        $this->line('  （DBも外部APIも使いません・費用0円）');
        $this->line('══════════════════════════════════════════════════════════');
        $this->line('');

        $fail = 0;

        // ── mode_cases.json ────────────────────────────────────────
        $p1 = rtrim($dir, '/') . '/mode_cases.json';
        if (!is_file($p1)) {
            $this->error('  見つかりません: ' . $p1);
            $fail++;
        } else {
            $d = json_decode((string) file_get_contents($p1), true);
            $cases = $d['cases'] ?? [];
            $keys = ['mode','mode_status','allocation_mode','mode_policy_id',
                     'selection_enabled','S','S_min','S_max','display_rating'];
            $ng = 0; $shown = 0;
            foreach ($cases as $c) {
                $r = self::decideModeFromSignals($c['signals'] ?? []);
                foreach ($keys as $k) {
                    if (!array_key_exists($k, $c['expected'] ?? [])) { continue; }
                    if ($c['expected'][$k] !== $r[$k]) {
                        $ng++;
                        if ($shown < 5) {
                            $shown++;
                            $this->error(sprintf('    %s %s 期待=%s 実際=%s  信号=%s',
                                $c['id'] ?? '?', $k,
                                var_export($c['expected'][$k], true),
                                var_export($r[$k], true),
                                json_encode($c['signals'] ?? [])));
                        }
                        break;
                    }
                }
            }
            $this->line(sprintf('  mode_cases.json            : %4d件 / 不一致 %d件 → %s',
                count($cases), $ng, $ng === 0 ? '合格' : '失敗'));
            if ($ng > 0) { $fail++; }
        }

        // ── acquisition_boundary_cases.json ────────────────────────
        $p2 = rtrim($dir, '/') . '/acquisition_boundary_cases.json';
        if (!is_file($p2)) {
            $this->error('  見つかりません: ' . $p2);
            $fail++;
        } else {
            $d = json_decode((string) file_get_contents($p2), true);
            $cases = $d['cases'] ?? [];
            $ng = 0;
            foreach ($cases as $c) {
                // 予定時刻は与えられていないので、input_freeze_at から発走時刻を戻して作ります
                $frz = self::parseTimeUs($c['input_freeze_at']);
                if ($frz === null) { $ng++; continue; }
                $anchor = $frz + (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC) * 1000000;
                $schUs  = self::slotScheduledUs($anchor, (int) $c['slot']);
                $sch    = gmdate('Y-m-d\TH:i:s', intdiv($schUs, 1000000))
                        . sprintf('.%06d', $schUs % 1000000) . '+00:00';
                $r = self::acceptAcquisitionBatch((int) $c['slot'], [
                    'slot_scheduled_at'    => $sch,
                    'request_started_at'   => $c['request_started_at'],
                    'response_received_at' => $c['response_received_at'],
                    'available_at'         => $c['available_at'],
                    'input_freeze_at'      => $c['input_freeze_at'],
                ]);
                $got = $r['ok'] ? 'PASS' : 'FAIL';
                $ok  = ($got === ($c['expected'] ?? ''));
                if (!$ok) { $ng++; }
                $this->line(sprintf('    %-16s slot=%-2d 期待=%-4s 実際=%-4s %s  %s',
                    $c['id'] ?? '?', (int) $c['slot'], $c['expected'] ?? '', $got,
                    $ok ? '一致' : '不一致', $r['reason'] ?? ''));
            }
            $this->line(sprintf('  acquisition_boundary_cases : %4d件 / 不一致 %d件 → %s',
                count($cases), $ng, $ng === 0 ? '合格' : '失敗'));
            if ($ng > 0) { $fail++; }
        }

        $this->line('');
        if ($fail === 0) {
            $this->info('  すべて先方の期待値と一致しました。');
        } else {
            $this->error('  一致しなかった試験があります。上の行をご確認ください。');
        }
        $this->line('');
        $this->warn('※この試験はSchemaと判定規則の突き合わせです。');
        $this->warn('　実DB・Queue・実AI・Flutterの受入は別に行います（契約14章）。');
        $this->line('');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  配布された両AI本文を storage へ入れる
     * ═══════════════════════════════════════════════════════════════════════
     * 本文はコードへ書き写しません。配布ファイルをそのまま置き、
     * 置いた直後に中身（マーカー・版・差し込み記号）を確かめます。
     */
    private function runPromptInstall(string $srcDir): void
    {
        self::loadPredictionClasses();
        $srcDir = rtrim($srcDir, '/');
        $dst = \App\Http\Controllers\Api\BaganrikiPrompt::dir();
        $this->info('══ 新方式 両AI本文の設置 ══');
        $this->line('  配布元: ' . $srcDir);
        $this->line('  置き場: ' . $dst);
        if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
            $this->error('  置き場を作れません: ' . $dst);
            return;
        }
        $ng = 0;
        foreach (\App\Http\Controllers\Api\BaganrikiPrompt::FILES as $role => $name) {
            $s = $srcDir . '/' . $name;
            if (!is_file($s)) {
                $this->error("  × {$name} が配布元にありません");
                $ng++;
                continue;
            }
            $body = file_get_contents($s);
            if (strncmp((string) $body, "\xEF\xBB\xBF", 3) === 0) {
                $body = substr((string) $body, 3);
                $this->line("  － {$name} の先頭のBOMを外しました（hashが変わるため）");
            }
            file_put_contents($dst . '/' . $name, $body);
            $this->line(sprintf('  ○ %-18s %7d バイト  sha256=%s',
                $name, strlen((string) $body), hash('sha256', (string) $body)));
        }
        $this->line('');
        foreach (['1st', '2nd', 'common'] as $role) {
            $b = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody($role);
            if ($b['ok']) {
                $this->line(sprintf('  確認 %-7s 行数%4d  バイト%7d  sha256=%s',
                    $role, $b['lines'], $b['bytes'], $b['sha256']));
            } else {
                $this->error('  確認 ' . $role . ' × ' . $b['reason']);
                $ng++;
            }
        }
        $this->line('');
        if ($ng === 0) {
            $this->info('  設置できました。.env に BAGANRIKI_PREDICTION_PROMPT=1 を入れると'
                      . '新しい本文で送ります（既定は 0 で今までと同じ）。');
        } else {
            $this->error('  設置に失敗した項目が ' . $ng . ' 件あります。');
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  送信文の検査（AIへは送りません・費用0円）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：10月20日003の確認結果①②／server_contract_r12.2.txt 5章・6章・9章
     *
     * 6つに分けて確かめます。
     *   §1 配布本文3本が、そのまま system として使える状態か
     *   §2 snapshot_hash の作り方が先方の実装と1バイト単位で同じか
     *      （先方の参照入力14本の snapshot_hash を再現できるか）
     *   §3 生の取得payloadから INPUT_JSON を組み立て、
     *      先方の参照入力と全キー一致するか（7ケース×2role）
     *   §4 組み立てた INPUT_JSON が同梱スキーマに適合するか
     *   §5 送信の一式（system＋user）の自己点検
     *   §6 本番DBの保存済みレースで組み立てられるか（--prediction-prompt-real）
     */
    private function runPromptCheck(string $root): void
    {
        self::loadPredictionClasses();
        $root = rtrim($root, '/');
        $pDir = is_dir($root . '/prompts')  ? $root . '/prompts'  : $root;
        $sDir = is_dir($root . '/schemas')  ? $root . '/schemas'  : $root;
        $vDir = is_dir($root . '/validation') ? $root . '/validation' : $root;
        $dump = (string) ($this->option('prediction-prompt-dump') ?: '');

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 送信文の検査（AI呼び出し0回・費用0円・DB書込なし）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  資材      : ' . $root);
        $this->line('  本文置き場: ' . \App\Http\Controllers\Api\BaganrikiPrompt::dir());
        $this->line('');

        $ngTotal = 0;

        // ───────────────────────────────────────────────────────────────
        // §1 配布本文
        // ───────────────────────────────────────────────────────────────
        $this->info('── §1 配布本文3本の確認 ──');
        foreach (\App\Http\Controllers\Api\BaganrikiPrompt::FILES as $role => $name) {
            $b = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody($role);
            $src = $pDir . '/' . $name;
            $same = (is_file($src) && $b['ok'])
                  ? (hash('sha256', (string) file_get_contents($src)) === $b['sha256']
                     || hash('sha256', ltrim((string) file_get_contents($src), "\xEF\xBB\xBF")) === $b['sha256'])
                  : false;
            if (!$b['ok']) {
                $this->error(sprintf('  × %-18s %s', $name, $b['reason']));
                $ngTotal++;
                continue;
            }
            $this->line(sprintf('  ○ %-18s 行%4d バイト%7d 配布と同一=%s',
                $name, $b['lines'], $b['bytes'], $same ? 'はい' : 'いいえ'));
            foreach ($b['checks'] as $k => $v) {
                if ($v !== true) { $this->error('      × ' . $k); $ngTotal++; }
            }
            if (!$same) {
                $this->error('      × 置いた本文と配布ファイルのSHA-256が違います');
                $ngTotal++;
            }
        }
        // 1st/2nd がそれぞれ共通本文を内包していること（連結してはいけない根拠）
        $b1 = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody('1st');
        $b2 = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody('2nd');
        $bc = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody('common');
        if ($b1['ok'] && $b2['ok'] && $bc['ok']) {
            $head = mb_substr((string) $bc['body'], 0, 120);
            $in1 = (mb_strpos((string) $b1['body'], $head) !== false);
            $in2 = (mb_strpos((string) $b2['body'], $head) !== false);
            $this->line('  － 共通本文の冒頭が 1st に含まれる: ' . ($in1 ? 'はい' : 'いいえ'));
            $this->line('  － 共通本文の冒頭が 2nd に含まれる: ' . ($in2 ? 'はい' : 'いいえ'));
            $this->line('     → どちらも「はい」なので、common_prompt.txt を後ろへ'
                      . '連結しません（連結すると同じ規則が二重になります）');
            if (!$in1 || !$in2) { $ngTotal++; }
        }
        $this->line('');

        // ───────────────────────────────────────────────────────────────
        // §2 snapshot_hash の作り方
        // ───────────────────────────────────────────────────────────────
        $this->info('── §2 snapshot_hash の作り方の照合（先方の参照入力） ──');
        $refs = glob($vDir . '/normal/*_input.json') ?: [];
        sort($refs);
        if ($refs === []) {
            $this->error('  × ' . $vDir . '/normal/*_input.json が見つかりません');
            $ngTotal++;
        }
        $h2ok = 0; $h2ng = 0;
        foreach ($refs as $f) {
            $in = json_decode((string) file_get_contents($f), true);
            if (!is_array($in)) { $this->error('  × 読めません: ' . basename($f)); $ngTotal++; continue; }
            $h = \App\Http\Controllers\Api\BaganrikiPrompt::commonHash($in);
            $got = (string) ($h['hash'] ?? '');
            $exp = (string) ($in['snapshot_hash'] ?? '');
            if ($got === $exp && $exp !== '') { $h2ok++; }
            else {
                $h2ng++;
                $this->error(sprintf('  × %s 得た値 %s / 期待値 %s',
                    basename($f), substr($got, 0, 16), substr($exp, 0, 16)));
            }
        }
        $this->line(sprintf('  一致 %d 件 / 不一致 %d 件（対象 %d 件）',
            $h2ok, $h2ng, count($refs)));
        $this->line('  対象   INPUT_JSON から ai_role・role_cap・role_quota・snapshot_hash を外した残り');
        $this->line('  並び   全階層でキーを文字列として昇順');
        $this->line('  書式   区切りは "," と ":"（余白なし）／非ASCIIはエスケープしない');
        $this->line('  方式名 ' . \App\Http\Controllers\Api\BaganrikiPrompt::HASH_VERSION);
        $ngTotal += $h2ng;
        $this->line('');

        // ───────────────────────────────────────────────────────────────
        // §3 生payloadからの組み立てと参照入力との突き合わせ
        // ───────────────────────────────────────────────────────────────
        $this->info('── §3 取得payloadから INPUT_JSON を組み立てて参照入力と照合 ──');
        $this->line('  【与えたもの】発走時刻・投票締切・batch_id・coverage の文面・');
        $this->line('                スロットなしの登録（past_performance）');
        $this->line('                … これらは payload から導けない入力です');
        $this->line('  【導いたもの】N・runners・帯・signals・S・S_min・S_max・mode・');
        $this->line('                mode_status・allocation_mode・mode_policy_id・');
        $this->line('                target_count・base_quota・quota・quota_adjustments・');
        $this->line('                role_cap・role_quota・selection_enabled・display_rating・');
        $this->line('                cutoff_at・input_freeze_at・時刻契約の期限・');
        $this->line('                field_catalog・input_indices・snapshot_hash・');
        $this->line('                取得監査の payload_hash と3つの実時刻');
        $this->line('');
        $cases = [];
        foreach ($refs as $f) {
            if (!preg_match('/^(.+)_(1st|2nd)_input\.json$/', basename($f), $m)) continue;
            $cases[$m[1]][$m[2]] = $f;
        }
        $c3ok = 0; $c3ng = 0; $assembled = [];
        foreach ($cases as $case => $byRole) {
            foreach (['1st', '2nd'] as $role) {
                if (!isset($byRole[$role])) continue;
                $ref = json_decode((string) file_get_contents($byRole[$role]), true);
                $ctx = $this->promptCaseContext($vDir, $case, $ref);
                if ($ctx === null) {
                    $this->error("  × {$case} {$role} 生payloadが見つかりません");
                    $c3ng++; $ngTotal++;
                    continue;
                }
                $b = \App\Http\Controllers\Api\BaganrikiPrompt::buildInput($ctx, $role);
                if (!$b['ok']) {
                    $this->error("  × {$case} {$role} 組み立て失敗 "
                               . $b['code'] . ' ' . $b['reason']);
                    $c3ng++; $ngTotal++;
                    continue;
                }
                $keys = array_unique(array_merge(array_keys($ref), array_keys($b['input'])));
                $diff = $this->diffPromptInput($b['input'], $ref);
                if ($diff === []) {
                    $c3ok++;
                    // @ANCHOR-20261020-PREDICTION-PROMPT  件数は数えて出します（34と書いた誤りの再発防止）
                    $this->line(sprintf('  ○ %-26s %s  全%dキー一致  snapshot_hash=%s',
                        $case, $role, count($keys),
                        substr((string) $b['input']['snapshot_hash'], 0, 16)));
                } else {
                    $c3ng++; $ngTotal += count($diff);
                    $this->error(sprintf('  × %-26s %s  違い %d 件', $case, $role, count($diff)));
                    foreach (array_slice($diff, 0, 8) as $d) {
                        $this->error('      - ' . $d);
                    }
                }
                $assembled[$case . '|' . $role] = $b['input'];
            }
        }
        $this->line(sprintf('  一致 %d 件 / 不一致 %d 件', $c3ok, $c3ng));
        $this->line('');

        // ───────────────────────────────────────────────────────────────
        // §4 スキーマ適合
        // ───────────────────────────────────────────────────────────────
        $this->info('── §4 同梱スキーマへの適合 ──');
        $inSchema = @json_decode((string) @file_get_contents($sDir . '/input_schema.json'), true);
        $srSchema = @json_decode((string) @file_get_contents($sDir . '/source_registry_schema.json'), true);
        if (!is_array($inSchema) || !is_array($srSchema)) {
            $this->error('  × スキーマを読めません: ' . $sDir);
            $ngTotal++;
        } else {
            $this->line('  使うスキーマ: input_schema.json / source_registry_schema.json');
            $this->line('  （検査する語彙: type・const・enum・required・properties・items・'
                      . 'additionalProperties・minItems・maxItems・uniqueItems・'
                      . 'minimum・maximum・minLength・pattern・anyOf・oneOf・allOf・if/then）');
            $s4ng = 0;
            foreach ($assembled as $name => $in) {
                $e = self::jsCheck($in, $inSchema, '');
                $e2 = self::jsCheck($in['source_registry'], $srSchema, 'source_registry');
                $all = array_merge($e, $e2);
                if ($all === []) continue;
                $s4ng++;
                $this->error('  × ' . $name . ' 違反 ' . count($all) . ' 件');
                foreach (array_slice($all, 0, 6) as $x) $this->error('      - ' . $x);
            }
            $this->line(sprintf('  自作した入力 %d 件のうち 違反あり %d 件',
                count($assembled), $s4ng));
            $ngTotal += $s4ng;
            // 参照入力も同じ検査器で見ます（検査器そのものの確かさの目安）
            $s4r = 0;
            foreach ($refs as $f) {
                $in = json_decode((string) file_get_contents($f), true);
                $all = array_merge(self::jsCheck($in, $inSchema, ''),
                                   self::jsCheck($in['source_registry'], $srSchema, 'source_registry'));
                if ($all !== []) {
                    $s4r++;
                    $this->warn('  △ 先方の参照入力でも違反: ' . basename($f)
                              . ' ' . count($all) . ' 件（検査器側の読み違いの可能性）');
                    foreach (array_slice($all, 0, 4) as $x) $this->warn('      - ' . $x);
                }
            }
            $this->line('  先方の参照入力 ' . count($refs) . ' 件のうち 違反あり ' . $s4r . ' 件');
        }
        $this->line('');

        // ───────────────────────────────────────────────────────────────
        // §5 送信の一式
        // ───────────────────────────────────────────────────────────────
        $this->info('── §5 送信の一式（system＋INPUT_JSON）の自己点検 ──');
        $s5ng = 0;
        foreach ($cases as $case => $byRole) {
            foreach (['1st', '2nd'] as $role) {
                if (!isset($byRole[$role])) continue;
                $ref = json_decode((string) file_get_contents($byRole[$role]), true);
                $ctx = $this->promptCaseContext($vDir, $case, $ref);
                if ($ctx === null) continue;
                $asm = \App\Http\Controllers\Api\BaganrikiPrompt::assemble($ctx, $role);
                if (!$asm['ok']) {
                    $s5ng++; $ngTotal++;
                    $this->error("  × {$case} {$role} " . $asm['code'] . ' ' . $asm['reason']);
                    continue;
                }
                $this->line(sprintf('  ○ %-26s %s  system %6dバイト／user %6dバイト'
                                  . '／入れ子%2d／自己点検 %d件',
                    $case, $role, strlen((string) $asm['system']), $asm['user_bytes'],
                    \App\Http\Controllers\Api\BaganrikiPrompt::depthOf($asm['input']),
                    count($asm['issues'])));
                if ($dump !== '') {
                    if (!is_dir($dump)) @mkdir($dump, 0775, true);
                    file_put_contents($dump . '/' . $case . '_' . $role . '_system.txt',
                                      (string) $asm['system']);
                    file_put_contents($dump . '/' . $case . '_' . $role . '_input.json',
                                      (string) $asm['user']);
                }
            }
        }
        if ($dump !== '') $this->line('  書き出し先: ' . $dump);
        $this->line('');

        // ───────────────────────────────────────────────────────────────
        // §6 本番DBでの組み立て
        // ───────────────────────────────────────────────────────────────
        if ((bool) $this->option('prediction-prompt-real')) {
            $this->checkRealPrompt($dump);
        } else {
            $this->line('── §6 本番DBでの組み立ては --prediction-prompt-real を付けたときだけ行います ──');
        }
        $this->line('');

        $this->info('════════════════════════════════════════════════════════');
        if ($ngTotal === 0) {
            $this->info(' すべて一致しました（不一致 0 件）');
        } else {
            $this->error(' 不一致・違反 合計 ' . $ngTotal . ' 件');
        }
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PROMPT  合成ケースの文脈を生payloadから作ります
     */
    private function promptCaseContext(string $vDir, string $case, array $ref): ?array
    {
        self::loadPredictionClasses();
        $files = [];
        foreach (\App\Http\Controllers\Api\BaganrikiTime::INPUT_SLOTS as $slot) {
            $f = $vDir . '/raw_payloads/' . $case . '_s' . $slot . '.json';
            if (is_file($f)) $files[$slot] = $f;
        }
        if ($files === []) return null;

        $registry = [];
        $audit = [];
        $anchorUs = \App\Http\Controllers\Api\BaganrikiTime::us(
            $ref['time_contract']['anchor_at'] ?? null);
        if ($anchorUs === null) return null;

        foreach (\App\Http\Controllers\Api\BaganrikiTime::INPUT_SLOTS as $slot) {
            if (!isset($files[$slot])) {
                $audit[(string) $slot] = ['status' => 'UNAVAILABLE',
                    'reason' => 'このスロットの取得payloadがありません'];
                continue;
            }
            $bytes = (string) file_get_contents($files[$slot]);
            $pl = json_decode($bytes, true);
            $recs = (array) ($pl['records'] ?? []);
            $obs = null; $av = null;
            foreach ($recs as $r) {
                $registry[(string) $r['source_ref']] = $r;
                $o = \App\Http\Controllers\Api\BaganrikiTime::us($r['observed_at'] ?? null);
                $a = \App\Http\Controllers\Api\BaganrikiTime::us($r['available_at'] ?? null);
                if ($o !== null && ($obs === null || $o > $obs)) $obs = $o;
                if ($a !== null && ($av  === null || $a > $av))  $av  = $a;
            }
            $sch = \App\Http\Controllers\Api\BaganrikiTime::slotScheduled($anchorUs, $slot);
            $audit[(string) $slot] = [
                'status'               => 'ADOPTED',
                'batch_id'             => (string) ($ref['collection_audit'][(string) $slot]['batch_id'] ?? ''),
                'payload_hash'         => hash('sha256', $bytes),
                'slot_scheduled_at'    => \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($sch),
                'source_updated_at'    => null,
                'request_started_at'   => \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($sch),
                'response_received_at' => ($obs === null) ? null
                                        : \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($obs),
                'available_at'         => ($av === null) ? null
                                        : \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($av),
            ];
        }
        // スロットなしの登録（過去走の指数など）は payload に入っていないので与えます
        foreach ((array) ($ref['source_registry'] ?? []) as $k => $e) {
            if (($e['slot_offset'] ?? null) === null) $registry[(string) $k] = $e;
        }

        return [
            'race_id'         => (string) ($ref['race_id'] ?? ''),
            'anchor_at'       => (string) ($ref['time_contract']['anchor_at'] ?? ''),
            'sales_close_at'  => (string) ($ref['time_contract']['sales_close_at'] ?? ''),
            'registry'        => $registry,
            'audit'           => $audit,
            'coverage'        => (array) ($ref['coverage'] ?? []),
            'entry_nums'      => array_map(static fn($r) => (int) $r['horse_no'],
                                           (array) ($ref['runners'] ?? [])),
            'cancelled'       => [],
            'legacy_gap_type' => $ref['legacy_gap_type'] ?? null,
            'similar'         => null,
        ];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PROMPT  2つの INPUT_JSON の違いを1行ずつにします
     *   値そのものの比較はキー昇順のJSONで行います（キーの並びは意味を持たない）。
     */
    private function diffPromptInput(array $got, array $exp): array
    {
        $out = [];
        $keys = array_values(array_unique(array_merge(array_keys($exp), array_keys($got))));
        sort($keys, SORT_STRING);
        foreach ($keys as $k) {
            $a = array_key_exists($k, $got) ? $got[$k] : '(キー無し)';
            $b = array_key_exists($k, $exp) ? $exp[$k] : '(キー無し)';
            $ja = \App\Http\Controllers\Api\BaganrikiPrompt::canonical($a);
            $jb = \App\Http\Controllers\Api\BaganrikiPrompt::canonical($b);
            if ($ja === $jb) continue;
            if ($k === 'source_registry' || $k === 'input_indices'
                || $k === 'field_catalog' || $k === 'collection_audit') {
                foreach ($this->diffPromptInputDeep((array) $a, (array) $b, $k) as $x) {
                    $out[] = $x;
                }
                continue;
            }
            $out[] = sprintf('%s 得た値 %s / 期待値 %s', $k,
                mb_strimwidth((string) $ja, 0, 160, '…'),
                mb_strimwidth((string) $jb, 0, 160, '…'));
        }
        return $out;
    }

    /** 大きな入れ物は中の要素ごとに違いを出します */
    private function diffPromptInputDeep(array $got, array $exp, string $prefix): array
    {
        $out = [];
        $keys = array_values(array_unique(array_merge(array_keys($exp), array_keys($got))));
        sort($keys, SORT_STRING);
        foreach ($keys as $k) {
            $ja = array_key_exists($k, $got)
                ? \App\Http\Controllers\Api\BaganrikiPrompt::canonical($got[$k]) : '(キー無し)';
            $jb = array_key_exists($k, $exp)
                ? \App\Http\Controllers\Api\BaganrikiPrompt::canonical($exp[$k]) : '(キー無し)';
            if ($ja === $jb) continue;
            $out[] = sprintf('%s.%s 得た値 %s / 期待値 %s', $prefix, $k,
                mb_strimwidth((string) $ja, 0, 140, '…'),
                mb_strimwidth((string) $jb, 0, 140, '…'));
        }
        return $out;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PROMPT  本番DBの保存済みレースで組み立てを試します
     *   既定では「投票締切の正本が無い」「取得監査の実時刻が無い」で止まります。
     *   --prediction-prompt-assume を付けると、その2つを仮置きして送信文の形まで出します
     *   （本番送信には使いません。どこまで出来ているかを示すためです）。
     */
    private function checkRealPrompt(string $dump): void
    {
        self::loadPredictionClasses();
        $this->info('── §6 本番DBの保存済みレースでの組み立て ──');
        $dates = array_values(array_filter(array_map('trim',
            explode(',', (string) ($this->option('date') ?: date('Y-m-d'))))));
        $assume = (bool) $this->option('prediction-prompt-assume');
        $override = [];
        if ($assume) {
            $override['assume_audit'] = true;
            $this->warn('  【仮】取得監査の実時刻を予定時刻で埋めます（本番送信には使いません）');
        }
        $offset = (int) ($this->option('prediction-prompt-sales-offset') ?: 0);
        if ($offset > 0) {
            $this->warn('  【仮】投票締切を「発走の' . $offset . '秒前」として扱います'
                      . '（正本ではありません。契約11.2のため本番送信はしません）');
        }
        $ctrl = app(\App\Http\Controllers\Api\AiController::class);
        $tbl = ['合計' => 0];
        $byCode = [];
        $rows = [];
        foreach ($dates as $date) {
            foreach (\DB::table('t_horse_odds_finder_races')->where('date', $date)
                        ->orderBy('basho')->orderBy('race')
                        ->get(['date', 'kaisuu', 'basho', 'day', 'race']) as $r) {
                $ov = $override;
                if ($offset > 0) {
                    $st = \DB::table('t_horse_odds_finder_races')
                            ->where('date', $r->date)->where('kaisuu', $r->kaisuu)
                            ->where('basho', $r->basho)->where('day', $r->day)
                            ->where('race', (int) $r->race)->value('start_time');
                    $us = \App\Http\Controllers\Api\BaganrikiTime::us(
                        $r->date . 'T' . (strlen((string) $st) === 5 ? $st . ':00' : $st) . '+09:00');
                    if ($us !== null) {
                        $ov['sales_close_at'] = \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339(
                            $us - $offset * 1000000);
                    }
                }
                // @ANCHOR-20261020-PREDICTION-ONEBUILD  ここは「作り直す側」です。
                //   実レースの入力がどう組み上がるかを見る調査なので、
                //   固定入力を作らずに組み立てます。
                $asm = $ctrl->assembleFreshPredictionRequest($r->date, $r->kaisuu, $r->basho,
                                               $r->day, $r->race, '1st', $ov);
                $tbl['合計']++;
                $code = $asm['ok'] ? 'OK' : (string) $asm['code'];
                $byCode[$code] = ($byCode[$code] ?? 0) + 1;
                if ($asm['ok']) {
                    $rows[] = sprintf('  ○ %-22s N=%2d %-9s %-11s user%7dバイト hash=%s',
                        $asm['race_id'], (int) ($asm['input']['N'] ?? 0),
                        (string) ($asm['input']['mode'] ?? ''),
                        (string) ($asm['input']['mode_status'] ?? ''),
                        (int) $asm['user_bytes'], substr((string) $asm['snapshot_hash'], 0, 12));
                    if ($dump !== '') {
                        if (!is_dir($dump)) @mkdir($dump, 0775, true);
                        file_put_contents($dump . '/real_' . $asm['race_id'] . '_1st_input.json',
                                          (string) $asm['user']);
                    }
                }
            }
        }
        $this->line('  対象 ' . $tbl['合計'] . ' レース');
        ksort($byCode);
        foreach ($byCode as $code => $n) {
            $this->line(sprintf('    %-28s %4d レース', $code, $n));
        }
        foreach (array_slice($rows, 0, 12) as $x) $this->line($x);
        if (count($rows) > 12) $this->line('    …ほか ' . (count($rows) - 12) . ' レース');
        $this->line('');
        $this->line('  【止まる理由の意味】');
        $this->line('    SALES_CLOSE_UNRESOLVED   投票締切の正本がDBに無い（契約11.2で公開しない）');
        $this->line('    ACQUISITION_AUDIT_MISSING 取得監査の実時刻がDBに無い（お願い②A案の記録待ち）');
        $this->line('    ODDS_SERIES_INCOMPLETE   6分前の単勝がそろっていない');
        $this->line('    ENTRY_MISSING            出馬表の馬番が無い');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  JSON Schema（2020-12の必要な語彙だけ）
     * ═══════════════════════════════════════════════════════════════════════
     * 外部ライブラリを入れずに済ませるため、同梱スキーマで実際に使われている
     * 語彙だけを実装しています。知らない語彙は無視せず、warn を返します。
     * 連想配列へ復号したデータを見るので、空の {} と [] は区別しません。
     *
     * @return array 違反の説明（空なら適合）
     */
    public static function jsCheck($data, $schema, string $path): array
    {
        if ($schema === true || $schema === []) return [];
        if ($schema === false) return [self::jsAt($path) . ' ここには何も置けません'];
        if (!is_array($schema)) return [];
        $e = [];
        $at = self::jsAt($path);

        foreach (['$schema', '$id', 'title', 'description', 'format',
                  'examples', 'default', 'comment', '$comment'] as $ignore) {
            unset($schema[$ignore]);
        }

        if (array_key_exists('const', $schema)) {
            if (self::jsJson($data) !== self::jsJson($schema['const'])) {
                $e[] = $at . ' const 不一致 得た値 ' . self::jsShort($data)
                     . ' / 期待値 ' . self::jsShort($schema['const']);
            }
        }
        if (isset($schema['enum'])) {
            $ok = false;
            foreach ($schema['enum'] as $x) {
                if (self::jsJson($data) === self::jsJson($x)) { $ok = true; break; }
            }
            if (!$ok) $e[] = $at . ' enum に無い値 ' . self::jsShort($data);
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $ok = false;
            foreach ($types as $t) { if (self::jsType($data, (string) $t)) { $ok = true; break; } }
            if (!$ok) {
                $e[] = $at . ' type 不一致（期待 ' . implode('|', $types)
                     . '／得た値 ' . self::jsShort($data) . '）';
            }
        }
        if (isset($schema['pattern']) && is_string($data)) {
            $re = '/' . str_replace('/', '\\/', (string) $schema['pattern']) . '/u';
            if (!preg_match($re, $data)) {
                $e[] = $at . ' pattern 不一致 ' . self::jsShort($data);
            }
        }
        if (isset($schema['minLength']) && is_string($data)
            && mb_strlen($data) < (int) $schema['minLength']) {
            $e[] = $at . ' minLength 未満';
        }
        if (isset($schema['minimum']) && (is_int($data) || is_float($data))
            && $data < $schema['minimum']) {
            $e[] = $at . ' minimum 未満 ' . self::jsShort($data);
        }
        if (isset($schema['maximum']) && (is_int($data) || is_float($data))
            && $data > $schema['maximum']) {
            $e[] = $at . ' maximum 超過 ' . self::jsShort($data);
        }
        if (is_array($data)) {
            $isList = (array_keys($data) === range(0, count($data) - 1));
            if (isset($schema['minItems']) && $isList
                && count($data) < (int) $schema['minItems']) {
                $e[] = $at . ' minItems 未満（' . count($data) . '件）';
            }
            if (isset($schema['maxItems']) && $isList
                && count($data) > (int) $schema['maxItems']) {
                $e[] = $at . ' maxItems 超過（' . count($data) . '件）';
            }
            if (!empty($schema['uniqueItems']) && $isList) {
                $seen = [];
                foreach ($data as $x) {
                    $j = self::jsJson($x);
                    if (isset($seen[$j])) { $e[] = $at . ' uniqueItems 違反'; break; }
                    $seen[$j] = true;
                }
            }
            if (isset($schema['items']) && $isList) {
                foreach ($data as $i => $x) {
                    foreach (self::jsCheck($x, $schema['items'], $path . '[' . $i . ']') as $x2) {
                        $e[] = $x2;
                    }
                }
            }
            if (isset($schema['required'])) {
                foreach ((array) $schema['required'] as $k) {
                    if (!array_key_exists($k, $data) && !array_key_exists((int) $k, $data)) {
                        $e[] = $at . ' required キーがありません: ' . $k;
                    }
                }
            }
            $props = (array) ($schema['properties'] ?? []);
            foreach ($props as $k => $sub) {
                $hit = array_key_exists($k, $data) ? $k
                     : (is_numeric($k) && array_key_exists((int) $k, $data) ? (int) $k : null);
                if ($hit === null) continue;
                foreach (self::jsCheck($data[$hit], $sub, $path . '/' . $k) as $x2) $e[] = $x2;
            }
            if (array_key_exists('additionalProperties', $schema) && !$isList) {
                $ap = $schema['additionalProperties'];
                foreach ($data as $k => $v) {
                    if (array_key_exists((string) $k, $props)) continue;
                    if ($ap === false) {
                        $e[] = $at . ' 余分なキー: ' . $k;
                        continue;
                    }
                    if (is_array($ap)) {
                        foreach (self::jsCheck($v, $ap, $path . '/' . $k) as $x2) $e[] = $x2;
                    }
                }
            }
        }
        if (isset($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                foreach (self::jsCheck($data, $sub, $path) as $x2) $e[] = $x2;
            }
        }
        if (isset($schema['anyOf'])) {
            $ok = false; $first = null;
            foreach ($schema['anyOf'] as $sub) {
                $r = self::jsCheck($data, $sub, $path);
                if ($r === []) { $ok = true; break; }
                if ($first === null) $first = $r;
            }
            if (!$ok) {
                $e[] = $at . ' anyOf のどれにも合いません（最初の候補の理由: '
                     . implode('／', array_slice((array) $first, 0, 2)) . '）';
            }
        }
        if (isset($schema['oneOf'])) {
            $hit = 0; $first = null;
            foreach ($schema['oneOf'] as $sub) {
                $r = self::jsCheck($data, $sub, $path);
                if ($r === []) $hit++;
                elseif ($first === null) $first = $r;
            }
            if ($hit !== 1) {
                $e[] = $at . ' oneOf に合うのが ' . $hit . ' 個（1個でなければなりません。'
                     . '最初の候補の理由: ' . implode('／', array_slice((array) $first, 0, 2)) . '）';
            }
        }
        if (isset($schema['if'])) {
            $cond = self::jsCheck($data, $schema['if'], $path);
            if ($cond === [] && isset($schema['then'])) {
                foreach (self::jsCheck($data, $schema['then'], $path) as $x2) $e[] = $x2;
            }
            if ($cond !== [] && isset($schema['else'])) {
                foreach (self::jsCheck($data, $schema['else'], $path) as $x2) $e[] = $x2;
            }
        }
        $known = ['const', 'enum', 'type', 'pattern', 'minLength', 'minimum', 'maximum',
                  'minItems', 'maxItems', 'uniqueItems', 'items', 'required',
                  'properties', 'additionalProperties', 'allOf', 'anyOf', 'oneOf',
                  'if', 'then', 'else'];
        foreach (array_keys($schema) as $k) {
            if (!in_array((string) $k, $known, true)) {
                $e[] = $at . ' この検査器が知らない語彙があります: ' . $k;
            }
        }
        return $e;
    }

    /** 型の判定（連想配列へ復号しているので object と array は中身で見ます） */
    private static function jsType($d, string $t): bool
    {
        switch ($t) {
            case 'object':
                if (!is_array($d)) return false;
                if ($d === []) return true;                 // 空は {} と [] を区別できない
                return (array_keys($d) !== range(0, count($d) - 1));
            case 'array':
                if (!is_array($d)) return false;
                if ($d === []) return true;
                return (array_keys($d) === range(0, count($d) - 1));
            case 'string':  return is_string($d);
            case 'integer': return is_int($d) || (is_float($d) && $d == (int) $d);
            case 'number':  return is_int($d) || is_float($d);
            case 'boolean': return is_bool($d);
            case 'null':    return $d === null;
        }
        return true;
    }

    private static function jsAt(string $p): string
    {
        return ($p === '') ? '(root)' : $p;
    }

    private static function jsJson($v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                      | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function jsShort($v): string
    {
        return mb_strimwidth(self::jsJson($v), 0, 60, '…');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-AUDIT  取得監査の記録の確認（書き込みは残しません）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：10月20日003の確認結果①（実物と再現手順）／お願い②A案
     *
     *   ① テーブルの有無と、いま入っている件数
     *   ② 実際に入る値で1行書いてみて、読み戻せるかを確かめる
     *      → <b>必ずロールバックします。</b>1行も残りません。
     *   ③ 保存済みレースのうち、採用できる監査行がそろっているレース数
     *
     * AI呼び出しは0回、DBへ残る書き込みも0件です。
     */
    private function runAcquisitionAuditCheck(): void
    {
        self::loadPredictionClasses();
        $T = 'baganriki_acquisition_batches';
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 取得監査の記録の確認（DBへ残る書き込みは0件）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('');

        // ── ① テーブル ────────────────────────────────────────────────
        $this->info('── ① テーブル ──');
        if (!\Schema::hasTable($T)) {
            $this->error("  × {$T} がありません。--prediction-install で作ってください。");
            return;
        }
        $this->line("  ○ {$T} あり");
        $cols = \Schema::getColumnListing($T);
        $need = ['race_id', 'slot', 'batch_id', 'slot_scheduled_at', 'source_updated_at',
                 'request_started_at', 'response_received_at', 'available_at',
                 'raw_payload', 'payload_hash', 'validation_result', 'rejection_reason',
                 'committed', 'created_at'];
        $lack = array_values(array_diff($need, $cols));
        if ($lack !== []) {
            $this->error('  × 足りない列: ' . implode('・', $lack));
            return;
        }
        $this->line('  ○ 必要な列は全部あります（' . count($need) . '列）');
        $total = \DB::table($T)->count();
        $this->line('  いま入っている件数: ' . $total . ' 件');
        if ($total > 0) {
            foreach (\DB::table($T)->selectRaw('slot, validation_result, committed, COUNT(*) n')
                        ->groupBy('slot', 'validation_result', 'committed')
                        ->orderBy('slot')->get() as $r) {
                $this->line(sprintf('    slot=%-3s %-9s committed=%d  %d 件',
                    $r->slot, (string) $r->validation_result, (int) $r->committed, (int) $r->n));
            }
        }
        $this->line('');

        // ── ② 1行書いて読み戻す（必ずロールバック）────────────────────
        $this->info('── ② 実際に入る値で1行書いて読み戻す（ロールバックします）──');
        $ok = true;
        $batchId = 'AUDITCHECK-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $raw = json_encode([['num' => 1, 'tan' => '2.1', 'fuku_min' => '1.3', 'fuku_max' => '1.6']],
                           JSON_UNESCAPED_UNICODE);
        $row = [
            'race_id'              => '20261018-4-08-7-9',
            'slot'                 => 6,
            'batch_id'             => $batchId,
            'slot_scheduled_at'    => '2026-10-18 15:04:00.000000',
            'source_updated_at'    => null,
            'request_started_at'   => '2026-10-18 15:04:00.123456',
            'response_received_at' => '2026-10-18 15:04:02.654321',
            'available_at'         => '2026-10-18 15:04:03.000001',
            'raw_payload'          => $raw,
            'payload_hash'         => hash('sha256', $raw),
            'validation_result'    => 'ADOPTED',
            'rejection_reason'     => null,
            'committed'            => 1,
            'created_at'           => date('Y-m-d H:i:s.u'),
        ];
        try {
            \DB::beginTransaction();
            \DB::table($T)->insert($row);
            $back = \DB::table($T)->where('batch_id', $batchId)->first();
            if ($back === null) {
                $this->error('  × 書いた行を読み戻せません');
                $ok = false;
            } else {
                $cmp = [
                    'race_id'              => (string) $back->race_id,
                    'slot'                 => (int) $back->slot,
                    'payload_hash'         => (string) $back->payload_hash,
                    'raw_payload'          => (string) $back->raw_payload,
                    'validation_result'    => (string) $back->validation_result,
                    'committed'            => (int) $back->committed,
                ];
                $exp = [
                    'race_id'              => $row['race_id'],
                    'slot'                 => 6,
                    'payload_hash'         => $row['payload_hash'],
                    'raw_payload'          => $raw,
                    'validation_result'    => 'ADOPTED',
                    'committed'            => 1,
                ];
                foreach ($exp as $k => $want) {
                    $got = $cmp[$k];
                    if ($got === $want) {
                        $this->line(sprintf('  ○ %-20s %s', $k,
                            mb_strimwidth((string) $got, 0, 54, '…')));
                    } else {
                        $ok = false;
                        $this->error(sprintf('  × %-20s 得た値 %s / 期待値 %s', $k,
                            mb_strimwidth((string) $got, 0, 40, '…'),
                            mb_strimwidth((string) $want, 0, 40, '…')));
                    }
                }
                // マイクロ秒が丸められていないこと（DATETIME(6) の確認）
                foreach (['request_started_at' => '.123456',
                          'response_received_at' => '.654321',
                          'available_at' => '.000001'] as $k => $frac) {
                    $v = (string) $back->{$k};
                    if (strpos($v, $frac) !== false) {
                        $this->line(sprintf('  ○ %-20s %s（マイクロ秒が落ちていません）', $k, $v));
                    } else {
                        $ok = false;
                        $this->error(sprintf('  × %-20s %s（マイクロ秒が落ちています）', $k, $v));
                    }
                }
                if ($back->source_updated_at !== null) {
                    $ok = false;
                    $this->error('  × source_updated_at が null ではありません');
                } else {
                    $this->line('  ○ source_updated_at  null（取得元の更新時刻は取れないので埋めません）');
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('  × 書き込みで例外: ' . $e->getMessage());
        } finally {
            \DB::rollBack();
        }
        $after = \DB::table($T)->count();
        if ($after === $total) {
            $this->line('  ○ ロールバック後の件数 ' . $after . ' 件（試し書きは残っていません）');
        } else {
            $ok = false;
            $this->error('  × 件数が変わりました ' . $total . ' → ' . $after);
        }
        $this->line('');

        // ── ③ 保存済みレースの状況 ────────────────────────────────────
        $this->info('── ③ 保存済みレースで採用できる監査行があるか ──');
        $dates = array_values(array_filter(array_map('trim',
            explode(',', (string) ($this->option('date') ?: date('Y-m-d'))))));
        $have = 0; $none = 0;
        foreach ($dates as $date) {
            foreach (\DB::table('t_horse_odds_finder_races')->where('date', $date)
                        ->get(['date', 'kaisuu', 'basho', 'day', 'race']) as $r) {
                $raceId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
                    $r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
                $n = \DB::table($T)->where('race_id', $raceId)
                        ->where('committed', 1)->whereIn('slot', [21, 18, 15, 12, 9, 6])->count();
                if ($n > 0) $have++; else $none++;
            }
        }
        $this->line('  対象日   : ' . implode(' / ', $dates));
        $this->line('  監査行あり: ' . $have . ' レース');
        $this->line('  監査行なし: ' . $none . ' レース');
        if ($none > 0) {
            $this->warn('  ※ 監査行は、これから取得するレースから貯まります。');
            $this->warn('  　 過去に保存したレースの実時刻は残っていないので、');
            $this->warn('  　 あとから作ることはしません（作り話になるため）。');
        }
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info($ok ? ' 記録の経路は使える状態です（DBへ残る書き込み0件）'
                        : ' 使えない項目があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-INVALID  異常例32本の突き合わせ
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：10月20日004の確認結果 §5／validation/invalid_cases.json
     *
     * 「正常入力が通る」ことは、正しいものを誤って弾いていない確認にしかなりません。
     * 不正を弾けることは、不正例で確かめる必要があります。
     *
     *   入力 23本（Schemaで弾く16本／意味検証で弾く7本）
     *   出力  9本（Schemaで弾く 5本／意味検証で弾く4本）
     *
     * Schema で弾くものと意味検証で弾くものを分けて判定し、
     * 先方の expected_schema / expected_contract と1件ずつ突き合わせます。
     * DBも外部APIも使いません（費用0円・書き込み0件）。
     */
    private function runInvalidResponseCases(string $root): void
    {
        self::loadPredictionClasses();
        $root = rtrim($root, '/');
        $vDir = is_dir($root . '/validation') ? $root . '/validation' : $root;
        $sDir = is_dir($root . '/schemas')    ? $root . '/schemas'
              : dirname($vDir) . '/schemas';
        $base = dirname($vDir);

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 異常例の突き合わせ（Schema と 意味検証 を分けて判定）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  資材      : ' . $vDir);
        $this->line('');

        $casesFile = $vDir . '/invalid_cases.json';
        if (!is_file($casesFile)) {
            $this->error('  × invalid_cases.json がありません: ' . $casesFile);
            return;
        }
        $cases = (array) (json_decode((string) file_get_contents($casesFile), true)['cases'] ?? []);
        $inS  = json_decode((string) @file_get_contents($sDir . '/input_schema.json'), true);
        $outS = json_decode((string) @file_get_contents($sDir . '/output_schema.json'), true);
        if (!is_array($inS) || !is_array($outS)) {
            $this->error('  × Schemaを読めません: ' . $sDir);
            return;
        }

        // ── 固定入力を snapshot_hash で引けるようにします ───────────
        //   本番では、応答が名乗る snapshot_hash で固定入力を引き当て、
        //   識別・版・mode・配分の「おうむ返し」を照合します。
        //   対になる入力が invalid_cases.json に書かれていないので、
        //   ここでも同じやり方（hashで引く）で照合します。
        $byHash = [];
        foreach (glob($vDir . '/normal/*_input.json') ?: [] as $f) {
            $x = json_decode((string) file_get_contents($f), true);
            if (!is_array($x) || !isset($x['snapshot_hash'], $x['ai_role'])) continue;
            $byHash[(string) $x['snapshot_hash']][(string) $x['ai_role']] = $x;
        }

        $ngS = 0; $ngC = 0; $miss = 0;
        $rows = [];
        foreach ($cases as $c) {
            $id   = (string) ($c['id'] ?? '');
            $kind = (string) ($c['kind'] ?? '');
            $file = (string) ($c['file'] ?? '');
            $path = (strpos($file, '/') === 0) ? $file : $base . '/' . $file;
            if (!is_file($path)) $path = $vDir . '/' . basename(dirname($file)) . '/' . basename($file);
            if (!is_file($path)) {
                $this->error("  × {$id} ファイルがありません: {$file}");
                $miss++;
                continue;
            }
            $doc = json_decode((string) file_get_contents($path), true);
            if (!is_array($doc)) {
                $this->error("  × {$id} JSONとして読めません");
                $miss++;
                continue;
            }

            // ── Schema で弾けるか ────────────────────────────────────
            $schemaErr = ($kind === 'input')
                ? self::jsCheck($doc, $inS, '')
                : self::jsCheck($doc, $outS, '');
            $gotSchema = ($schemaErr === []) ? 'PASS' : 'FAIL';

            // ── 意味検証で弾けるか ──────────────────────────────────
            $contractErr = ($kind === 'input')
                ? self::checkInputContract($doc)
                : self::checkOutputContract($doc);
            // 出力は、固定入力を引けたら「おうむ返し」も照合します
            if ($kind === 'output') {
                $h  = (string) ($doc['snapshot_hash'] ?? '');
                $rl = (string) ($doc['ai_role'] ?? '');
                $src = $byHash[$h][$rl] ?? null;
                if (is_array($src)) {
                    $env = \App\Http\Controllers\Api\BaganrikiResponse::checkEnvelope($doc, [
                        'prompt_version'  => $src['prompt_version'],
                        'schema_version'  => $src['schema_version'],
                        'race_id'         => $src['race_id'],
                        'ai_role'         => $src['ai_role'],
                        'snapshot_hash'   => $src['snapshot_hash'],
                        'mode'            => $src['mode'],
                        'mode_status'     => $src['mode_status'],
                        'allocation_mode' => $src['allocation_mode'],
                        'mode_policy_id'  => $src['mode_policy_id'],
                        'role_cap'        => $src['role_cap'],
                        'role_quota'      => $src['role_quota'],
                    ]);
                    if (!$env['ok']) $contractErr[] = 'おうむ返し: ' . $env['reason'];
                }
            }
            // Schemaで落ちたものは契約上も不採用です（両方FAILで扱います）
            $gotContract = ($schemaErr === [] && $contractErr === []) ? 'PASS' : 'FAIL';

            $expS = (string) ($c['expected_schema']   ?? '');
            $expC = (string) ($c['expected_contract'] ?? '');
            $okS  = ($gotSchema   === $expS);
            $okC  = ($gotContract === $expC);
            if (!$okS) $ngS++;
            if (!$okC) $ngC++;

            $why = ($schemaErr !== []) ? $schemaErr[0]
                 : (($contractErr !== []) ? $contractErr[0] : '（弾けていません）');
            $rows[] = [$kind, $id, $expS, $gotSchema, $okS, $expC, $gotContract, $okC, $why];
        }

        $this->info('── 入力 ──');
        $this->line(sprintf('  %-28s %-9s %-9s %s', 'ケース', 'Schema', '意味検証', '弾いた理由'));
        foreach ($rows as $r) {
            if ($r[0] !== 'input') continue;
            $this->printInvalidRow($r);
        }
        $this->line('');
        $this->info('── 出力 ──');
        foreach ($rows as $r) {
            if ($r[0] !== 'output') continue;
            $this->printInvalidRow($r);
        }
        $this->line('');
        $n = count($rows);
        $this->line(sprintf('  対象 %d 件（入力 %d ／ 出力 %d）',
            $n,
            count(array_filter($rows, static fn($r) => $r[0] === 'input')),
            count(array_filter($rows, static fn($r) => $r[0] === 'output'))));
        $this->line('  Schemaの判定が期待と違ったもの   : ' . $ngS . ' 件');
        $this->line('  意味検証の判定が期待と違ったもの : ' . $ngC . ' 件');
        if ($miss > 0) $this->line('  読めなかったファイル             : ' . $miss . ' 件');
        // ───────────────────────────────────────────────────────────
        // @ANCHOR-20261020-PREDICTION-CHAIN  本番の応答処理へ通す（ご指摘6-2・6-3）
        // ───────────────────────────────────────────────────────────
        //   Schema と意味検証だけでなく、本番で通す順番
        //     extract → checkEnvelope → isolateHorses
        //   へ実際に通します。固定入力は snapshot_hash で引きます。
        //   <b>応答が名乗る role は照合相手を選ぶのに使いません。</b>
        //   本番は予約済みの role と固定 snapshot を正とするので、
        //   ここでも 1st・2nd の両方を正として突き合わせ、
        //   どちらとも合わなければ不採用にします。
        $this->line('');
        $this->info('── 本番の応答処理へ通す（extract→封筒照合→馬ごとの隔離）──');
        $chainNg = 0;
        $run = function (array $doc, string $name, bool $expectReject, ?string $bindRole = null)
                use ($byHash, &$chainNg) {
            $raw = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN
                 . json_encode($doc, JSON_UNESCAPED_UNICODE)
                 . \App\Http\Controllers\Api\BaganrikiResponse::END;
            $ex = \App\Http\Controllers\Api\BaganrikiResponse::extract($raw);
            if (!$ex['ok']) { return ['stage' => 'extract', 'reason' => $ex['reason'] ?? '']; }
            $res = $ex['json'];
            $h   = (string) ($res['snapshot_hash'] ?? '');
            $cands = $byHash[$h] ?? [];
            if ($cands === []) {
                return ['stage' => 'envelope', 'reason' => '固定入力を snapshot_hash で引けません'];
            }
            // 予約済みの role を正とします。応答の自己申告では選びません。
            //   正常出力はファイル名に role が入っているので、そのroleに固定します。
            //   異常出力は対になる入力が示されていないので、両方の役を試し、
            //   どちらとも合わないときだけ不採用にします（甘いほうへ倒します）。
            $tryRoles = ($bindRole !== null) ? [$bindRole] : ['1st', '2nd'];
            $envOk = false; $envWhy = ''; $src0 = null;
            foreach ($tryRoles as $role) {
                $src = $cands[$role] ?? null;
                if ($src === null) continue;
                $e = \App\Http\Controllers\Api\BaganrikiResponse::checkEnvelope($res, [
                    'prompt_version'  => $src['prompt_version'],
                    'schema_version'  => $src['schema_version'],
                    'race_id'         => $src['race_id'],
                    'ai_role'         => $src['ai_role'],
                    'snapshot_hash'   => $src['snapshot_hash'],
                    'mode'            => $src['mode'],
                    'mode_status'     => $src['mode_status'],
                    'allocation_mode' => $src['allocation_mode'],
                    'mode_policy_id'  => $src['mode_policy_id'],
                    'role_cap'        => $src['role_cap'],
                    'role_quota'      => $src['role_quota'],
                ]);
                if ($e['ok']) { $envOk = true; $src0 = $src; break; }
                if ($envWhy === '') $envWhy = (string) $e['reason'];
            }
            if (!$envOk) { return ['stage' => 'envelope', 'reason' => $envWhy]; }
            $frozen = array_map(static fn($x) => (int) $x['horse_no'],
                                (array) ($src0['runners'] ?? []));
            $iso = \App\Http\Controllers\Api\BaganrikiResponse::isolateHorses(
                (array) ($res['horses'] ?? []), $frozen);
            return ['stage' => 'ok', 'kept' => count($iso['kept']),
                    'isolated' => count($iso['isolated']),
                    'missing' => count($iso['missing']), 'frozen' => count($frozen)];
        };
        foreach ($cases as $c) {
            if (($c['kind'] ?? '') !== 'output') continue;
            $path = $vDir . '/invalid/' . basename((string) $c['file']);
            if (!is_file($path)) continue;
            $doc = json_decode((string) file_get_contents($path), true);
            if (!is_array($doc)) continue;
            $r = $run($doc, (string) $c['id'], true);
            if ($r['stage'] === 'ok') {
                // 封筒を通ったものは、馬の隔離で落ちていることを確かめます
                $caught = ($r['isolated'] > 0 || $r['missing'] > 0);
                if ($caught) {
                    $this->line(sprintf('  ○ %-28s 馬ごとの隔離で検出（隔離%d・欠落%d）',
                        $c['id'], $r['isolated'], $r['missing']));
                } else {
                    $this->line(sprintf('  － %-28s 本番の3段では落ちません'
                        . '（Schema／意味検証で落とします）', $c['id']));
                }
            } else {
                $this->line(sprintf('  ○ %-28s %s で不採用  %s',
                    $c['id'], $r['stage'], mb_strimwidth((string) ($r['reason'] ?? ''), 0, 54, '…')));
            }
        }
        $this->line('');
        $this->line('  正常な出力14本を同じ経路へ通します（正常馬が失われないこと）');
        $nOk = 0; $nNg = 0;
        foreach (glob($vDir . '/normal/*_output.json') ?: [] as $f) {
            $doc = json_decode((string) file_get_contents($f), true);
            if (!is_array($doc)) continue;
            $bind = null;
            if (preg_match('/_(1st|2nd)_output\.json$/', basename($f), $bm)) {
                $bind = $bm[1];
            }
            $r = $run($doc, basename($f), false, $bind);
            if ($r['stage'] !== 'ok') {
                $nNg++; $chainNg++;
                $this->error(sprintf('  × %-34s %s で落ちました  %s',
                    basename($f), $r['stage'], mb_strimwidth((string) ($r['reason'] ?? ''), 0, 44, '…')));
                continue;
            }
            if ($r['isolated'] > 0 || $r['missing'] > 0) {
                $nNg++; $chainNg++;
                $this->error(sprintf('  × %-34s 正常な馬が失われました（隔離%d・欠落%d）',
                    basename($f), $r['isolated'], $r['missing']));
                continue;
            }
            $nOk++;
        }
        $this->line(sprintf('    通った %d 本 / 落ちた %d 本（全頭そろったまま）', $nOk, $nNg));
        $ngC += $chainNg;

        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        if ($ngS === 0 && $ngC === 0 && $miss === 0) {
            $this->info(' すべて先方の期待どおりに弾けました（不一致 0 件）');
        } else {
            $this->error(' 期待と違うものがあります。上の × をご確認ください');
        }
        $this->info('════════════════════════════════════════════════════════');
        $this->line('');
        $this->line('※ Schemaで弾くものと意味検証で弾くものを分けて判定しています。');
        $this->line('※ Schemaで落ちたものは、契約上も不採用（FAIL）として扱います。');
        $this->line('※ 出力の検査は、入力を対にできない前提で「出力だけで分かる矛盾」を見ています。');
    }

    /** 1行表示 */
    private function printInvalidRow(array $r): void
    {
        [$kind, $id, $expS, $gotS, $okS, $expC, $gotC, $okC, $why] = $r;
        $line = sprintf('  %s %-28s %-4s(期待%-4s) %-4s(期待%-4s)  %s',
            ($okS && $okC) ? '○' : '×', $id, $gotS, $expS, $gotC, $expC,
            mb_strimwidth((string) $why, 0, 70, '…'));
        if ($okS && $okC) $this->line($line); else $this->error($line);
    }

    /**
     * @ANCHOR-20261020-PREDICTION-INVALID  入力の意味検証
     *   Schemaでは分からない「数字の辻褄」を見ます。
     *   @return array 違反の説明（空なら通過）
     */
    public static function checkInputContract(array $in): array
    {
        $e = [];
        $BP = '\App\Http\Controllers\Api\BaganrikiPrompt';
        $BT = '\App\Http\Controllers\Api\BaganrikiTime';
        $BS = '\App\Http\Controllers\Api\BaganrikiSignals';
        $BA = '\App\Http\Controllers\Api\BaganrikiAllocation';

        // ── 版 ────────────────────────────────────────────────────────
        if (($in['schema_version'] ?? null) !== $BP::SCHEMA_VERSION) $e[] = '版: schema_version が違います';
        if (($in['prompt_version'] ?? null) !== $BP::PROMPT_VERSION) $e[] = '版: prompt_version が違います';
        if (($in['snapshot_hash_version'] ?? null) !== $BP::HASH_VERSION) $e[] = '版: snapshot_hash_version が違います';
        if (($in['learning_stage'] ?? null) !== 'COLLECT_ONLY') $e[] = '学習: learning_stage は COLLECT_ONLY だけです';
        if (array_key_exists('model_version', $in) && $in['model_version'] !== null) $e[] = '学習: model_version は null だけです';

        // ── 時刻の辻褄 ────────────────────────────────────────────────
        $tc = (array) ($in['time_contract'] ?? []);
        $anchor = $BT::us($tc['anchor_at'] ?? null);
        $cut    = $BT::us($in['cutoff_at'] ?? null);
        $frz    = $BT::us($in['input_freeze_at'] ?? null);
        $sales  = $BT::us($tc['sales_close_at'] ?? null);
        $pub    = $BT::us($tc['publish_deadline'] ?? null);
        $rep    = $BT::us($tc['reply_deadline'] ?? null);
        if ($anchor === null) $e[] = '時刻: anchor_at を読めません';
        if ($cut === null)    $e[] = '時刻: cutoff_at を読めません';
        if ($frz === null)    $e[] = '時刻: input_freeze_at を読めません';
        if ($anchor !== null && $cut !== null && $cut !== $BT::cutoff($anchor)) {
            $e[] = '時刻: cutoff_at が 発走−360秒 ではありません';
        }
        if ($cut !== null && $frz !== null && $frz !== $cut + 30 * 1000000) {
            $e[] = '時刻: input_freeze_at が cutoff_at＋30秒 ではありません';
        }
        if ($sales !== null && $pub !== null && $pub !== $BT::publishDeadline($sales)) {
            $e[] = '時刻: publish_deadline が 投票締切−60秒 ではありません';
        }
        if ($sales !== null && $rep !== null && $rep !== $BT::replyDeadline($sales)) {
            $e[] = '時刻: reply_deadline が 公開期限−30秒 ではありません';
        }

        // ── 取得監査 ─────────────────────────────────────────────────
        $ca = (array) ($in['collection_audit'] ?? []);
        $six = $ca['6'] ?? ($ca[6] ?? null);
        if (!is_array($six) || ($six['status'] ?? '') !== 'ADOPTED') {
            $e[] = '取得: 6分前が ADOPTED ではありません';
        }
        foreach ($BT::INPUT_SLOTS as $slot) {
            $a = $ca[(string) $slot] ?? ($ca[$slot] ?? null);
            if (!is_array($a) || ($a['status'] ?? '') !== 'ADOPTED') continue;
            $sch = $BT::us($a['slot_scheduled_at'] ?? null);
            $req = $BT::us($a['request_started_at'] ?? null);
            $res = $BT::us($a['response_received_at'] ?? null);
            $av  = $BT::us($a['available_at'] ?? null);
            $src = $BT::us($a['source_updated_at'] ?? null);
            if ($anchor !== null && $sch !== null && $sch !== $BT::slotScheduled($anchor, $slot)) {
                $e[] = "取得{$slot}: slot_scheduled_at が 発走−{$slot}分 ではありません";
            }
            if ($sch !== null && $req !== null && $req < $sch) {
                $e[] = "取得{$slot}: 予定時刻より前に要求を出しています";
            }
            if ($req !== null && $res !== null && $res < $req) {
                $e[] = "取得{$slot}: 受信が要求より前になっています";
            }
            if ($res !== null && $av !== null && $av < $res) {
                $e[] = "取得{$slot}: 利用可能が受信より前になっています";
            }
            $grace = ($BT::GRACE_MS[$slot] ?? 120000) * 1000;
            if ($sch !== null && $av !== null && $av > $sch + $grace) {
                $e[] = "取得{$slot}: 取得猶予を超えています";
            }
            if ($frz !== null && $av !== null && $av > $frz) {
                $e[] = "取得{$slot}: 情報上限（input_freeze_at）より後の値です";
            }
            if ($src !== null && $res !== null && $src > $res) {
                $e[] = "取得{$slot}: 取得元の更新時刻が受信より未来です";
            }
        }

        // ── 信号と mode ──────────────────────────────────────────────
        $sig = (array) ($in['signals'] ?? []);
        $tri = static function ($v) {
            if ($v === true)  return 'TRUE';
            if ($v === false) return 'FALSE';
            return 'UNKNOWN';
        };
        $flags = [];
        foreach (['X1', 'X2', 'X3', 'X4', 'X5', 'X6', 'Z'] as $k) {
            $flags[$k] = $tri($sig[$k] ?? null);
        }
        $N = (int) ($in['N'] ?? 0);
        if ($N >= 7 && ($sig['X6'] ?? null) !== null) {
            $e[] = 'X6: N>=7 では null だけです（決着事項①B案）';
        }
        if ($N > 0 && $N < 7 && ($sig['X6'] ?? null) !== false) {
            $e[] = 'X6: N<7 では false だけです（決着事項①B案）';
        }
        $cls = $BS::v04Classify($flags);
        foreach ([['S', 'S'], ['S_min', 'S_min'], ['S_max', 'S_max']] as [$k, $kk]) {
            if (array_key_exists($k, $in) && $in[$k] !== $cls[$kk]) {
                $e[] = "mode: {$k} が信号から計算した値と違います（"
                     . json_encode($in[$k]) . ' / ' . json_encode($cls[$kk]) . '）';
            }
        }
        foreach (['mode', 'mode_status', 'allocation_mode', 'mode_policy_id',
                  'selection_enabled', 'display_rating'] as $k) {
            if (array_key_exists($k, $in) && $in[$k] !== $cls[$k]) {
                $e[] = "mode: {$k} が信号から決まる値と違います（"
                     . json_encode($in[$k]) . ' / ' . json_encode($cls[$k]) . '）';
            }
        }

        // ── 配分 ─────────────────────────────────────────────────────
        if ($N > 0) {
            $al = $BA::v04Allocation($N, $cls);
            foreach (['target_count', 'base_quota', 'quota'] as $k) {
                $want = ($k === 'target_count') ? $al['target_count']
                      : (($k === 'base_quota') ? ($al['base_quota'] ?? null) : $al['quota']);
                if (array_key_exists($k, $in)
                    && json_encode($in[$k]) !== json_encode($want)) {
                    $e[] = "配分: {$k} が N と mode から決まる値と違います（"
                         . json_encode($in[$k]) . ' / ' . json_encode($want) . '）';
                }
            }
            $role = (string) ($in['ai_role'] ?? '1st');
            $wantRq = ($cls['selection_enabled'] === false) ? null
                    : (($role === '2nd') ? $al['quota_2nd'] : $al['quota']);
            if (array_key_exists('role_quota', $in)
                && json_encode($in['role_quota']) !== json_encode($wantRq)) {
                $e[] = '配分: role_quota が指定と違います（'
                     . json_encode($in['role_quota']) . ' / ' . json_encode($wantRq) . '）';
            }
            $wantCap = ($cls['selection_enabled'] === false) ? 0
                     : (($role === '2nd') ? min(5, (int) $al['target_count'])
                                          : (int) $al['target_count']);
            if (array_key_exists('role_cap', $in) && (int) $in['role_cap'] !== $wantCap) {
                $e[] = '配分: role_cap が指定と違います（'
                     . json_encode($in['role_cap']) . ' / ' . $wantCap . '）';
            }
        }

        // ── 凍結集合 ─────────────────────────────────────────────────
        $runners = (array) ($in['runners'] ?? []);
        if ($N > 0 && count($runners) !== $N) {
            $e[] = '頭数: runners の件数が N と違います';
        }
        $ranks = [];
        foreach ($runners as $r) {
            $ranks[] = (int) ($r['win_rank'] ?? 0);
            $want = $BA::v04Band((int) ($r['win_rank'] ?? 0));
            if (($r['band'] ?? null) !== $want) {
                $e[] = '帯: 馬番 ' . ($r['horse_no'] ?? '?') . ' の帯が人気と合いません';
            }
        }
        sort($ranks);
        if ($ranks !== range(1, count($ranks))) {
            $e[] = '順位: 凍結人気が 1..N の連番になっていません';
        }

        return $e;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-INVALID  出力の意味検証
     *   対になる入力を使わず、出力だけで分かる矛盾を見ます。
     *   @return array 違反の説明（空なら通過）
     */
    public static function checkOutputContract(array $res): array
    {
        $e = [];
        $BP = '\App\Http\Controllers\Api\BaganrikiPrompt';
        $BS = '\App\Http\Controllers\Api\BaganrikiSignals';
        $BA = '\App\Http\Controllers\Api\BaganrikiAllocation';

        if (($res['schema_version'] ?? null) !== $BP::SCHEMA_VERSION) $e[] = '版: schema_version が違います';
        if (($res['prompt_version'] ?? null) !== $BP::PROMPT_VERSION) $e[] = '版: prompt_version が違います';
        if (($res['learning_stage'] ?? null) !== 'COLLECT_ONLY')      $e[] = '学習: learning_stage は COLLECT_ONLY だけです';

        // 入力専用のキーを返していないか（契約9章）
        foreach (['input_freeze_at', 'collection_audit', 'signals', 'S', 'S_min',
                  'S_max', 'buy_recommended', 'source_registry', 'field_catalog',
                  'input_indices', 'coverage', 'time_contract', 'cutoff_at',
                  'base_quota', 'runners'] as $k) {
            if (array_key_exists($k, $res)) $e[] = '出力: 入力専用のキーを返しています（' . $k . '）';
        }

        // mode 一式の内部の辻褄
        $ms = $res['mode_status'] ?? null;
        $am = $res['allocation_mode'] ?? null;
        $mp = $res['mode_policy_id'] ?? null;
        $md = $res['mode'] ?? null;
        $sel = $res['selection_enabled'] ?? null;
        if ($ms === 'PROVISIONAL') {
            if ($md !== 'UNKNOWN')                   $e[] = 'mode: 暫定では正式modeは UNKNOWN のままです';
            if ($mp !== 'X6_ONLY_UPPER_MODE_V1')     $e[] = 'mode: 暫定の mode_policy_id が違います';
            if (!in_array($am, ['MIXED', 'VOLATILE'], true)) $e[] = 'mode: 暫定の allocation_mode が違います';
        }
        if ($ms === 'CONFIRMED') {
            if ($mp !== 'BASE_TRI_STATE_V1')         $e[] = 'mode: 確定の mode_policy_id が違います';
            if ($am !== $md)                         $e[] = 'mode: 確定では allocation_mode は正式modeと同じです';
        }
        if ($ms === 'UNRESOLVED') {
            if ($am !== null)                        $e[] = 'mode: 判定不能では allocation_mode は null です';
            if ($sel !== false)                      $e[] = 'mode: 判定不能では selection_enabled は false です';
        }

        // role と枠
        $role = $res['ai_role'] ?? null;
        if (!in_array($role, ['1st', '2nd'], true)) $e[] = 'role: ai_role が 1st / 2nd ではありません';
        $rq  = $res['role_quota'] ?? null;
        $cap = $res['role_cap'] ?? null;
        if ($sel === false) {
            if ($cap !== 0 || $rq !== null) $e[] = 'role: 選出停止では role_cap=0・role_quota=null です';
        } else {
            if (!is_array($rq)) {
                $e[] = 'role: role_quota がありません';
            } else {
                $sum = (int) ($rq['A'] ?? 0) + (int) ($rq['B'] ?? 0) + (int) ($rq['D'] ?? 0);
                if ($sum !== (int) $cap) $e[] = 'role: role_quota の合計が role_cap と合いません';
            }
            if ($role === '2nd' && (int) $cap > 5) $e[] = 'role: 2ndの role_cap が5を超えています';
            $tc = $res['target_count'] ?? null;
            if ($role === '1st' && $tc !== null && (int) $cap !== (int) $tc) {
                $e[] = 'role: 1stの role_cap は target_count と同じです';
            }
            if ($role === '2nd' && $tc !== null && (int) $cap !== min(5, (int) $tc)) {
                $e[] = 'role: 2ndの role_cap は min(5, target_count) です';
            }
        }

        // 全頭と順位
        $horses = (array) ($res['horses'] ?? []);
        $br = (array) ($res['band_ranks'] ?? []);
        $er = (array) ($res['evidence_band_ranks'] ?? []);
        $inBr = []; foreach ($br as $b => $list) foreach ((array) $list as $n) $inBr[(int) $n] = (string) $b;
        $inEr = []; foreach ($er as $b => $list) foreach ((array) $list as $n) $inEr[(int) $n] = (string) $b;
        foreach ($horses as $h) {
            $no = (int) ($h['horse_no'] ?? 0);
            $el = (string) ($h['eligibility'] ?? '');
            if ($el === 'ELIGIBLE') {
                if (!isset($inBr[$no])) $e[] = '全頭: 適格馬 ' . $no . ' が band_ranks にいません';
                if (!isset($inEr[$no])) $e[] = '全頭: 適格馬 ' . $no . ' が evidence_band_ranks にいません';
                if (isset($inBr[$no]) && $inBr[$no] !== (string) ($h['band'] ?? ''))
                    $e[] = '全頭: 馬番 ' . $no . ' の帯と順位の帯が違います';
            } else {
                if (isset($inBr[$no]) || isset($inEr[$no]))
                    $e[] = '全頭: 非適格馬 ' . $no . ' が順位に入っています';
                if (($h['rank_basis'] ?? null) !== 'NOT_RANKED')
                    $e[] = '全頭: 非適格馬 ' . $no . ' の rank_basis は NOT_RANKED です';
            }
        }
        foreach (array_keys($inBr) as $no) {
            $found = false;
            foreach ($horses as $h) if ((int) ($h['horse_no'] ?? 0) === $no) { $found = true; break; }
            if (!$found) $e[] = '全頭: band_ranks の馬番 ' . $no . ' が horses にいません';
        }

        // 公開候補は帯の先頭から飛ばさずに取る
        if (is_array($rq)) {
            $eligByBand = [];
            foreach ($horses as $h) {
                if ((string) ($h['eligibility'] ?? '') !== 'ELIGIBLE') continue;
                $eligByBand[(string) ($h['band'] ?? '')][] = (int) $h['horse_no'];
            }
            $want = [];
            foreach (['A', 'B', 'D'] as $b) {
                $list = array_values(array_filter((array) ($br[$b] ?? []),
                    static fn($n) => in_array((int) $n, $eligByBand[$b] ?? [], true)));
                $take = min((int) ($rq[$b] ?? 0), count($list));
                for ($i = 0; $i < $take; $i++) $want[] = (int) $list[$i];
            }
            $got = array_map('intval', (array) ($res['public_candidates'] ?? []));
            if ($got !== $want) {
                $e[] = '公開候補: 帯の先頭から枠数ぶん取っていません（得た値 '
                     . json_encode($got) . ' / 期待値 ' . json_encode($want) . '）';
            }
            foreach ($horses as $h) {
                $no = (int) ($h['horse_no'] ?? 0);
                $isPub = (bool) ($h['public_candidate'] ?? false);
                if ($isPub !== in_array($no, $want, true)) {
                    $e[] = '公開候補: 馬番 ' . $no . ' の public_candidate が公開リストと合いません';
                }
                if ($isPub && ($h['nonselect_reason'] ?? null) !== null) {
                    $e[] = '公開候補: 選出馬 ' . $no . ' に nonselect_reason が付いています';
                }
                if (!$isPub && !is_array($h['nonselect_reason'] ?? null)) {
                    $e[] = '公開候補: 非選出馬 ' . $no . ' の nonselect_reason がオブジェクトではありません';
                }
            }
        }

        return $e;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-FP  元値の指紋（固定対象を一意に表す方法）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：10月20日005のご指摘8
     *
     * ■ 前の方法で何が足りなかったか
     *   ・NULL を文字列 '\N' で表していたので、実値の '\N' と区別できない。
     *   ・区切り 0x1F を値の中でエスケープしていないので、値が区切りを
     *     含むと列の境目が曖昧になる。
     *   ・型を書いていない。主キー id を行のhashに入れていない。
     *   ・固定した主キー集合を指定しておらず、件数・最小・最大・XOR では
     *     同じ行集合だと言い切れない（[1,2,5,8] と [1,3,4,8] は全部同じ）。
     *   ・一貫した読取（スナップショット）の指定がない。
     *
     * ■ この実装
     *   ・<b>長さ付きの表現</b>にします。各列を
     *       N:            NULL
     *       V<バイト数>:<値>  実値
     *     と書くので、NULL と空文字と区切り入りの値が全部区別できます。
     *   ・行の先頭に主キー id を同じ書き方で入れます。
     *   ・<b>主キー順に全行を流し</b>、SHA-256 を逐次計算します（連結しません）。
     *   ・主キーの並びだけを別に SHA-256 して、<b>固定した集合そのもの</b>も
     *     一意に表します。件数・最小・最大では足りないというご指摘に対応します。
     *   ・<b>一貫した読取</b>のため、REPEATABLE READ のトランザクションを
     *     開いて読み、最後にロールバックします（読むだけ・書き込み0件）。
     *   ・結果はファイルへ書き出し、次回はそのファイルと比べられます。
     *
     * 使い方
     *   --prediction-fingerprint            いまの指紋を出す
     *   --prediction-fingerprint=<path>     そのファイルと突き合わせる
     */
    private function runFingerprintCheck($arg): void
    {
        $targets = [
            't_horse_odds_finder_odds' =>
                ['date','kaisuu','basho','day','race','num','minutes_before_start',
                 'odds','fuku_min','fuku_max'],
            't_horse_odds_finder_horses' =>
                ['date','kaisuu','basho','day','race','waku','num','name'],
            't_horse_odds_finder_races' =>
                ['date','kaisuu','basho','day','race','start_time','num_horses'],
            't_horse_odds_finder_race_results' =>
                ['date','kaisuu','basho','day','race','num','result'],
        ];
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 元値の指紋（読むだけ・DBへの書き込み0件）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  方法     : 列ごとに「N」または「V<バイト数>:<値>」で書き、');
        $this->line('             主キー id を先頭に入れて、主キー順に SHA-256 を逐次計算');
        $this->line('  読取     : REPEATABLE READ の一貫した読取（最後にロールバック）');
        $this->line('');

        $now = [];
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-FPFAIL
        // ══════════════════════════════════════════════════════════════
        //   【旧】表が無いときや読取で落ちたときに警告だけ出して先へ進み、
        //     最後に「すべて一致しました」と言える経路がありました。
        //   <b>必須の対象が欠けた比較は、合格にしてはいけません。</b>
        //   欠けと失敗を数えて、1件でもあれば不合格にします。
        $fpMissing = []; $fpError = [];
        \DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        \DB::beginTransaction();
        try {
            foreach ($targets as $table => $cols) {
                if (!\Schema::hasTable($table)) {
                    // @ANCHOR-20261020-PREDICTION-FPFAIL  欠けは不合格です
                    $fpMissing[] = $table;
                    $this->error('  × ' . $table . ' がありません（必須の対象です）');
                    continue;
                }
                $rowH = hash_init('sha256');
                $keyH = hash_init('sha256');
                $n = 0; $min = null; $max = null;
                \DB::table($table)->orderBy('id')->select(array_merge(['id'], $cols))
                   ->chunk(2000, function ($rows) use (&$rowH, &$keyH, &$n, &$min, &$max, $cols) {
                        foreach ($rows as $r) {
                            $id = (int) $r->id;
                            if ($min === null || $id < $min) $min = $id;
                            if ($max === null || $id > $max) $max = $id;
                            $line = self::fpField((string) $id);
                            foreach ($cols as $c) {
                                $line .= self::fpField($r->{$c} ?? null);
                            }
                            hash_update($rowH, $line . "\n");
                            hash_update($keyH, $id . "\n");
                            $n++;
                        }
                   });
                $now[$table] = [
                    'rows' => $n, 'id_min' => $min, 'id_max' => $max,
                    'rows_sha256' => hash_final($rowH),
                    'keys_sha256' => hash_final($keyH),
                    'columns' => array_merge(['id'], $cols),
                ];
                $this->line(sprintf('  %-34s %6d行  id %s〜%s', $table, $n,
                    $min ?? '-', $max ?? '-'));
                $this->line('      行の指紋 ' . $now[$table]['rows_sha256']);
                $this->line('      主キーの指紋 ' . $now[$table]['keys_sha256']);
            }
        } catch (\Throwable $e) {
            // @ANCHOR-20261020-PREDICTION-FPFAIL  読取の失敗も不合格です
            $fpError[] = $e->getMessage();
            $this->error('  × 読み取りに失敗しました: ' . $e->getMessage());
        } finally {
            \DB::rollBack();
        }
        if ($fpMissing !== [] || $fpError !== []) {
            $this->line('');
            $this->error('  指紋を出せなかった対象があります'
                       . '（欠け ' . count($fpMissing) . ' 件／失敗 '
                       . count($fpError) . ' 件）。この比較は合格にしません。');
        }
        $this->line('');

        // ── 前回のファイルと突き合わせ ────────────────────────────────
        $path = is_string($arg) ? $arg : '';
        if ($path !== '' && is_file($path)) {
            $prev = json_decode((string) file_get_contents($path), true);
            $this->info('── 前回との突き合わせ ──');
            $this->line('  前回のファイル: ' . $path);
            $this->line('  前回の実行時刻: ' . (string) ($prev['generated_at'] ?? '不明'));
            $ng = 0;
            // 前回にあって今回に無い対象も見ます
            foreach (array_keys((array) ($prev['tables'] ?? [])) as $t0) {
                if (!array_key_exists($t0, $now)) {
                    $ng++;
                    $this->error('  × ' . $t0 . ' が今回ありません');
                }
            }
            foreach ($now as $t => $v) {
                $p = $prev['tables'][$t] ?? null;
                if ($p === null) {
                    // @ANCHOR-20261020-PREDICTION-FPFAIL  前回に無い対象も不合格です
                    $ng++;
                    $this->error('  × ' . $t . ' が前回のファイルにありません'
                               . '（比べられないので合格にしません）');
                    continue;
                }
                $same = ($p['rows_sha256'] ?? '') === $v['rows_sha256']
                     && ($p['keys_sha256'] ?? '') === $v['keys_sha256']
                     && ($p['rows'] ?? -1) === $v['rows'];
                if ($same) {
                    $this->line(sprintf('  ○ %-34s 変わっていません（%d行）', $t, $v['rows']));
                } else {
                    $ng++;
                    $this->error(sprintf('  × %-34s 変わっています', $t));
                    $this->error(sprintf('      行数 %s → %s', $p['rows'] ?? '?', $v['rows']));
                    $this->error('      行の指紋 ' . substr((string) ($p['rows_sha256'] ?? ''), 0, 16)
                               . ' → ' . substr($v['rows_sha256'], 0, 16));
                    if (($p['keys_sha256'] ?? '') !== $v['keys_sha256']) {
                        $this->error('      主キーの集合が変わっています（行の増減）');
                    } else {
                        $this->error('      主キーの集合は同じです（既存の値の書き換え）');
                    }
                }
            }
            $this->line('');
            $ng += count($fpMissing) + count($fpError);
            if ($ng === 0) {
                $this->info('  すべて一致しました（元値は動いていません）');
            } else {
                $this->error('  一致を確認できなかった対象が ' . $ng . ' 件あります');
            }
            $this->line('');
        } elseif ($path !== '') {
            $this->warn('  突き合わせ先が見つかりません: ' . $path);
        }

        $out = storage_path('logs/r122_fingerprint_' . date('Ymd_His') . '.json');
        file_put_contents($out, (string) json_encode([
            'generated_at' => date('c'),
            'method'       => 'per-row-length-prefixed-sha256-ordered-by-pk-v1',
            'note'         => '列は N（NULL）か V<バイト数>:<値>。行の先頭に主キー id。'
                            . '主キー順に逐次SHA-256。主キーの並びも別にSHA-256。',
            'tables'       => $now,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('  書き出しました: ' . $out);
        $this->line('  次回はこれを渡して突き合わせます:');
        $this->line('    php artisan keiba:ai-analysis-compensate --prediction-fingerprint=' . $out);
    }

    /**
     * @ANCHOR-20261020-PREDICTION-FP  1つの列を、取り違えようのない形にします
     *   NULL            → 'N|'
     *   それ以外        → 'V<バイト数>:<値>|'
     * 長さを前に置くので、値が区切りや改行を含んでいても境目が決まります。
     */
    private static function fpField($v): string
    {
        if ($v === null) { return 'N|'; }
        $s = (string) $v;
        return 'V' . strlen($s) . ':' . $s . '|';
    }
}

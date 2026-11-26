<?php
namespace App\Console\Commands\AiAnalysisCompensate;

use App\Http\Controllers\Api\AiController;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * E3・E5を使う版の確認（--prediction-expected-○○ の中身）
 *
 * keiba:ai-analysis-compensate（SummaryAiAnalysisCompensate）の一部です。
 * 中身は元の SummaryAiAnalysisCompensate.php から、1文字も変えずに移しました。
 */
trait PredictionExpectedVersionChecks
{
    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-CHECK  予想の版の凍結と、送る原文の準備だけを試します。
     *   AIへは送りません（費用0円）。トランザクションの中で行い、最後に必ず元に戻します。
     *   保存済みの 11/8 のレースを、試験の世代（REPLAY＝900）で凍結し直し、
     *   ・E3・E5 を付けた版になっているか、比べられる馬が何頭か（比べられない理由も）
     *   ・1st は名前で引ける形（SOURCE_DICT_V3）、2nd は表の形（SOURCE_TABLE_V1）で、元に戻すと1バイトも違わないか
     *   ・1st の system に3指標の頼み書きが入っているか
     *   を表示します。
     */
    private function runPredictionExpectedCheck(): void
    {
        // $races = [['2026-11-08', '5', '05', '2', 6], ['2026-11-08', '5', '08', '2', 8], ['2026-11-08', '3', '03', '2', 11]];
        // 11/9 19:13：上の2レースは世代900がもう固定済みで作り直されないので、まだ固定していない次の非公開試験の2レースで確かめます（最後に元に戻すので、試験の邪魔はしません）
        $races = [['2026-11-08', '5', '05', '2', 3], ['2026-11-08', '5', '08', '2', 5]];
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $A = \App\Http\Controllers\Api\AiController::class;
        $c = app($A);
        $prep = new \ReflectionMethod($A, 'expectedPrepare');
        $prep->setAccessible(true);
        $ng = 0;
        \DB::beginTransaction();
        try {
            foreach ($races as [$d, $k, $b, $dy, $rc]) {
                // その日の凍結時刻（発走5分前の3秒後）に時計を合わせて作り直します（試験のときだけ。本番では使いません）
                $rr = \DB::table('t_horse_odds_finder_races')->where('date', $d)->where('kaisuu', $k)->where('basho', $b)
                        ->where('day', $dy)->where('race', $rc)->first();
                $st = \App\Http\Controllers\Api\BaganrikiTime::normalizeRaceStartClock((string) ($rr->start_time ?? ''));
                $anchorUs = (int) \App\Http\Controllers\Api\BaganrikiTime::us($d . 'T' . $st . '+09:00');
                \App\Http\Controllers\Api\BaganrikiWorker::setTestNow($anchorUs + \App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC * 1000000 + 3000000);
                $f = $c->freezePredictionInput($d, $k, $b, $dy, $rc, [], 'REPLAY');
                $this->line('');
                $this->line("==== {$f['race_id']} 凍結: " . ($f['ok'] ? 'OK' : 'NG') . ' ' . ($f['code'] ?? '') . ' ' . ($f['reason'] ?? ''));
                if (!$f['ok']) { $ng++; continue; }
                $snap = \DB::table('baganriki_snapshots')->where('race_id', $f['race_id'])->where('generation', 900)->first();
                $isExp = \App\Http\Controllers\Api\BaganrikiCandidateSelection::isExpected($snap->release_version);
                $this->line('  版: ' . ($isExp ? 'E3・E5版' : '違う版 ' . $snap->release_version));
                if (!$isExp) { $ng++; continue; }
                $in = json_decode((string) $snap->input_json_1st, true);
                $rows = (array) ($in['expected_v01']['rows'] ?? []);
                $pairs = count(array_filter((array) ($in['source_registry'] ?? []), static fn($e) => is_array($e) && ($e['scope'] ?? '') === 'PAIR'));
                $sig = count(array_filter((array) ($in['source_registry'] ?? []), static fn($e) => is_array($e) && ($e['dependency_role'] ?? '') === 'SIGNAL' && str_starts_with((string) ($e['field'] ?? ''), 'past_')));
                $this->line('  断層の材料（隣り合う2頭の比）: ' . $pairs . '件 ／ 能力の根拠に使える過去走の実測値: ' . $sig . '件');
                $why = []; $e3 = 0; $e5 = 0; $m = null;
                foreach ($rows as $row) {
                    if (($row['E3'] ?? null) !== null) { $e3++; }
                    if (($row['E5'] ?? null) !== null) { $e5++; }
                    $m = $row['M'] ?? $m;
                    $key = (string) ($row['status'] ?? '') . (($row['reason'] ?? null) !== null && $row['reason'] !== $row['status'] ? ' ' . $row['reason'] : '');
                    $why[$key] = ($why[$key] ?? 0) + 1;
                }
                $this->line('  E3・E5 の行: ' . count($rows) . '頭（E3が出た馬 ' . $e3 . '頭・E5が出た馬 ' . $e5 . '頭、基準にした過去レース M=' . ($m ?? '-') . '）');
                foreach ($why as $kk => $n) { $this->line("    {$kk}: {$n}頭"); }
                // 過去レースの使われ方（よっしー⑤：比べられない理由）
                $au = \DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', 'PREDICTION_EXPECTED_AUDIT')->first();
                $aj = $au ? json_decode((string) $au->diagnostics, true) : null;
                if (is_array($aj)) {
                    $pk = $aj['packet'] ?? $aj;
                    $this->line('  過去レース：使えた ' . count((array) ($pk['pool_ids'] ?? [])) . 'レース／外した理由 '
                        . json_encode($pk['excluded'] ?? [], JSON_UNESCAPED_UNICODE) . '／キー ' . implode(',', array_keys($aj)));
                }
                $A::$testExpectedNowUs = (int) \App\Http\Controllers\Api\BaganrikiTime::us((string) $in['input_freeze_at']) + 5000000;
                foreach (['1st', '2nd'] as $role) {
                    $p = $prep->invoke(null, $snap, $role, null);
                    if (!$p['ok']) { $ng++; $this->error("  {$role} 準備: NG {$p['reason']}"); continue; }
                    $pr = $p['prepared'];
                    // よっしー③「1レース分の送信例」：最初のレースだけ、送る原文を /tmp に書き出します（試験用。あとで消します）
                    if ($f['race_id'] === '20261108-5-05-2-6') {
                        @file_put_contents("/tmp/expected_send_example_{$role}_user.json", $pr['user_json']);
                        @file_put_contents("/tmp/expected_send_example_{$role}_system.txt", $pr['system_text']);
                    }
                    $this->line("  {$role} 準備: OK 形式={$pr['format']} 本文=" . strlen($pr['user_json'])
                        . 'バイト（元 ' . strlen((string) $snap->{'input_json_' . $role}) . 'バイト）'
                        . ' 指標の指示=' . (strpos($pr['system_text'], 'race_metrics') !== false ? 'あり' : 'なし')
                        . ' 名前で引ける形の説明=' . (strpos($pr['system_text'], '【送信形式 SOURCE_DICT_V3】') !== false ? 'あり' : 'なし'));
                }
                $A::$testExpectedNowUs = null;
            }
        } catch (\Throwable $e) {
            $ng++;
            $this->error('例外: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
            $A::$testExpectedNowUs = null;
            \App\Http\Controllers\Api\BaganrikiWorker::setTestNow(null);
        }
        $this->line('');
        $ng === 0 ? $this->info('==== 結果: すべてOK（DBは元に戻しました）') : $this->error("==== 結果: NG {$ng}件（DBは元に戻しました）");
    }

    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-MEASURE  E3・E5版の 1st の返事に、実際に何秒かかるかを測ります（よっしー⑤「時間は実測」）
     *   非公開試験の世代900（11/8 東京6R）の送る原文を作り、締切を気にせず Claude に1回だけ送ります。
     *   予想の保存・公開はしません。返事の原文は /tmp/expected_measure_1st_raw.txt に置きます（調べたらあとで消します）。
     */
    private function runPredictionExpectedMeasure(): void
    {
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $NS = '\\App\\Http\\Controllers\\Api\\';
        $W = $NS . 'BaganrikiWorker'; $T = $NS . 'BaganrikiTime'; $A = $NS . 'AiController';
        $snap = \DB::table('baganriki_snapshots')->where('race_id', '20261108-5-05-2-6')->where('generation', 900)->first();
        if ($snap === null) { $this->error('世代900の凍結がありません（先に --prediction-expected-private を1回動かしてください）'); return; }
        $in = json_decode((string) $snap->input_json_1st, true);
        $prep = new \ReflectionMethod($A, 'expectedPrepare'); $prep->setAccessible(true);
        $A::$testExpectedNowUs = (int) $T::us((string) $in['input_freeze_at']) + 5000000;
        try {
            $p = $prep->invoke(null, $snap, '1st', null);
        } finally {
            $A::$testExpectedNowUs = null;
        }
        if (!$p['ok']) { $this->error('原文を作れません：' . $p['reason']); return; }
        $pr = $p['prepared'];
        $model = (string) (env('BAGANRIKI_PREDICTION_1ST_MODEL', '') ?: 'claude-haiku-5-5');
        $maxTok = (int) (env('BAGANRIKI_PREDICTION_1ST_MAX_TOKENS', '') ?: 65536);
        $this->line("送る：形式={$pr['format']} system=" . strlen($pr['system_text']) . 'バイト user=' . strlen($pr['user_json']) . "バイト モデル={$model} 上限トークン={$maxTok}");
        $t0 = microtime(true);
        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders([
                    'x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
                ])->timeout(420)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model, 'max_tokens' => $maxTok, 'thinking' => ['type' => 'disabled'],
                    'system' => $pr['system_text'], 'messages' => [['role' => 'user', 'content' => $pr['user_json']]],
                ]);
        } catch (\Throwable $e) {
            $this->error(sprintf('送信エラー（%.0f秒）：%s', microtime(true) - $t0, $e->getMessage()));
            return;
        }
        $sec = microtime(true) - $t0;
        $j = $res->json();
        $raw = '';
        foreach ((array) ($j['content'] ?? []) as $blk) { if (($blk['type'] ?? '') === 'text') { $raw .= (string) $blk['text']; } }
        @file_put_contents('/tmp/expected_measure_1st_raw.txt', $raw);
        $u = (array) ($j['usage'] ?? []);
        $this->line(sprintf('返事：%.0f秒 状態=%d 終わり方=%s 返事=%dバイト 入力トークン=%s 出力トークン=%s', $sec, $res->status(),
            (string) ($j['stop_reason'] ?? '-'), strlen($raw), (string) ($u['input_tokens'] ?? '-'), (string) ($u['output_tokens'] ?? '-')));
        $in1 = (int) ($u['input_tokens'] ?? 0); $out1 = (int) ($u['output_tokens'] ?? 0);
        $hi = $in1 > 100000;
        $usd = $in1 / 1e6 * ($hi ? 0.50 : 0.10) + $out1 / 1e6 * ($hi ? 2.50 : 0.50);
        $this->line(sprintf('料金の目安：%.4fドル（約%.1f円）', $usd, $usd * 150));
        if (!$res->successful()) { $this->error('API のエラー：' . mb_substr((string) $res->body(), 0, 300)); }
    }

    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-PRIVATE  非公開2レース（よっしー⑤）
     *   本番と同じ流れ（凍結 → 両AIへ初回 → 受け取り・検査・必要なら訂正 → 公開 → 画面用の返し方）を、
     *   保存済みの 11/8 のレースで、試験の世代（REPLAY＝900）として実際に動かします。
     *   ・お客様の画面は世代0だけを読むので、ここで作るものは画面に出ません。
     *   ・時計はそのレースの凍結時刻に合わせます（試験のときだけ）。AIの待ち時間は本物の時間で進みます。
     *   ・プロンプトのファイルは「_private」を付けた別名で保存します（本番のファイルを上書きしない）。
     *   ・記録（送った全文・ハッシュ・受け取った原文・採否・公開）はDBに残します。よっしーへの報告に使います。
     *   ・料金がかかります（両AIで1レース数十円程度の見込み）。1日の上限（10ドル）を超えるときは送らずに止まります。
     */
    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-CHECKDAY  その日の全レースで、凍結と両AIへ送る原文の準備が通るかを確かめます（AIへは送らない・0円）
     *   送る原文は「詰めた形を元に戻して、凍結した正本と値・型・順序まで一致するか」を照合してから作ります（一致しなければ送りません）。
     *   ここでは、その照合が実際のレースで止まらないか（よっしーR15.22〜R15.24 で直した型・順序・指数表記の問題が起きていないか）を全レースで見ます。
     *   あわせて、E3・E5 が比べられた頭数と、比べられなかった理由を数えます。レースごとにDBを元に戻します。
     */
    /**
     * @ANCHOR-20261111-CHECK-POOLSTRICT  監査記録から pool_ids を読みます（障害比較 --prediction-expected-jump-compare と同じ判定）
     *   キーが無い・型が配列でない → 例外（確認コマンドは不合格・終了コード1）
     *   キーがあって正しい空配列     → [] を返す（正常な M=0）
     *   最上位の pool_ids を先に見て、無ければ packet.pool_ids を見ます。
     */
    public static function strictPoolIdsFromAudit(array $aj): array
    {
        if (array_key_exists('pool_ids', $aj)) { $pool = $aj['pool_ids']; }
        elseif (is_array($aj['packet'] ?? null) && array_key_exists('pool_ids', $aj['packet'])) { $pool = $aj['packet']['pool_ids']; }
        else { throw new \RuntimeException('監査記録に pool_ids がありません（0件とは区別）'); }
        if (!is_array($pool)) { throw new \RuntimeException('pool_ids の型が不正'); }
        return $pool;
    }

    /** @ANCHOR-20261111-CHECK-POOLSTRICT  試験のときだけ使います（本番は null のまま） */
    public static ?\Closure $testCheckDayTamper = null;   // 当日確認で読んだ監査記録を書き換える（異常の再現用）
    public static ?int $testCheckDayLimit = null;          // 当日確認で見るレース数を先頭から絞る
    public static ?array $testCheckDayLastNg = null;       // 直前の当日確認の NG 行

    /**
     * @ANCHOR-20261111-EXPECTED-JUMP  トランザクションの中でだけ、そのレースの世代900の行を外します（呼び出し側が必ずロールバックする前提）
     */
    private static function hideGen900InTransaction(string $raceId): void
    {
        if (\DB::transactionLevel() < 1) { throw new \RuntimeException('トランザクションの外では外しません'); }
        $sids = \DB::table('baganriki_snapshots')->where('race_id', $raceId)->where('generation', 900)->pluck('id')->all();
        if ($sids === []) { return; }
        $aids = \DB::table('baganriki_ai_attempts')->whereIn('snapshot_id', $sids)->pluck('id')->all();
        if ($aids !== []) { \DB::table('baganriki_validation_results')->whereIn('attempt_id', $aids)->delete(); }
        foreach (['baganriki_publications', 'baganriki_publication_intents', 'baganriki_audit_events', 'baganriki_ai_attempts', 'baganriki_live_states'] as $t) {
            \DB::table($t)->whereIn('snapshot_id', $sids)->delete();
        }
        \DB::table('baganriki_race_runs')->where('race_id', $raceId)->where('generation', 900)->delete();
        \DB::table('baganriki_snapshots')->whereIn('id', $sids)->delete();
    }

    /**
     * @ANCHOR-20261111-EXPECTED-JUMP  障害レースの扱いを直す前（障害も FLAT）と後で、E3・E5 の材料を比べます（なる 11/11 の確認依頼）
     *   レースごと・前後それぞれ別のトランザクションで凍結し、最後に必ずロールバックします。AIへは送りません。
     *   比べるもの：基準集団 pool_ids（障害レースが入っているか）、M、各馬の status・p0_3・p0_5・E3・E5
     */
    private function runPredictionExpectedJumpCompare(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { $this->error('日付は 2026-11-08 の形で指定してください'); return; }
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $NS = '\\App\\Http\\Controllers\\Api\\';
        $A = $NS . 'AiController'; $T = $NS . 'BaganrikiTime'; $W = $NS . 'BaganrikiWorker';
        $c = app($A);
        $jumpIds = [];
        foreach (\DB::table('t_horse_odds_finder_expected_score')->where('class_group', 'JUMP')->where('date', '<', $date)
                   ->select('date', 'kaisuu', 'basho', 'day', 'race')->distinct()->get() as $j) {
            $jumpIds[$A::buildPredictionRaceId(substr((string) $j->date, 0, 10), (string) $j->kaisuu, (string) $j->basho, (string) $j->day, (int) $j->race)] = true;
        }
        $races = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                   ->orderBy('basho')->orderBy('race')->get(['kaisuu', 'basho', 'day', 'race', 'start_time', 'race_name', 'grade', 'course']);
        $this->line("==== {$date} の全レース（" . count($races) . 'レース）で、障害レースの扱いを直す前と後の E3・E5 の材料を比べます（AIへは送りません・0円）');
        $this->line('  ' . $date . ' より前の障害レース（クラス JUMP）：' . count($jumpIds) . '件 ' . implode(' ', array_keys($jumpIds)));
        $tot = ['races' => 0, 'ng' => 0, 'pool_jump_before' => 0, 'pool_jump_after' => 0, 'changed_races' => 0, 'changed_horses' => 0, 'status_before' => [], 'status_after' => []];
        foreach ($races as $r) {
            $tot['races']++;
            $rid = $A::buildPredictionRaceId($date, (string) $r->kaisuu, (string) $r->basho, (string) $r->day, (int) $r->race);
            $st = $T::normalizeRaceStartClock((string) ($r->start_time ?? ''));
            $anchorUs = (int) $T::us($date . 'T' . $st . '+09:00');
            $res = [];
            foreach (['before' => true, 'after' => false] as $mode => $legacy) {
                \DB::beginTransaction();
                try {
                    $A::$expectedLegacyJumpAsFlat = $legacy;
                    self::hideGen900InTransaction($rid);
                    $W::setTestNow($anchorUs + $T::INPUT_FREEZE_OFFSET_SEC * 1000000 + 3000000);
                    $f = $c->freezePredictionInput($date, (string) $r->kaisuu, (string) $r->basho, (string) $r->day, (int) $r->race, [], 'REPLAY');
                    if (!$f['ok']) { $res[$mode] = ['ng' => (string) ($f['code'] ?? '') . ' ' . mb_substr((string) ($f['reason'] ?? ''), 0, 80)]; }
                    else {
                        // @ANCHOR-20261111-CHECK-STRICT  なる 11/11 3章：必要な記録の欠落を「0件」と区別して NG にします
                        $snap = \DB::table('baganriki_snapshots')->where('race_id', $rid)->where('generation', 900)->first();
                        if ($snap === null) { throw new \RuntimeException('凍結の行がありません'); }
                        $in = json_decode((string) $snap->input_json_1st, true);
                        if (!is_array($in) || !is_array($in['expected_v01']['rows'] ?? null) || !is_array($in['runners'] ?? null)) { throw new \RuntimeException('expected_v01.rows または runners がありません'); }
                        $rows = [];
                        foreach ($in['expected_v01']['rows'] as $row) {
                            if (!is_array($row) || !is_int($row['horse_no'] ?? null) || isset($rows[$row['horse_no']])) { throw new \RuntimeException('expected_v01.rows の馬番が不正・重複'); }
                            $rows[$row['horse_no']] = $row;
                        }
                        $runnerNos = array_map(static fn($x) => (int) ($x['horse_no'] ?? -1), $in['runners']);
                        sort($runnerNos); $rowNos = array_keys($rows); sort($rowNos);
                        if ($runnerNos !== $rowNos) { throw new \RuntimeException('expected_v01.rows の馬番が出走馬と一致しません'); }
                        $au = \DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', 'PREDICTION_EXPECTED_AUDIT')->first();
                        $aj = $au ? json_decode((string) $au->diagnostics, true) : null;
                        if (!is_array($aj)) { throw new \RuntimeException('監査記録 PREDICTION_EXPECTED_AUDIT がありません'); }
                        if (array_key_exists('pool_ids', $aj)) { $pool = $aj['pool_ids']; }
                        elseif (is_array($aj['packet'] ?? null) && array_key_exists('pool_ids', $aj['packet'])) { $pool = $aj['packet']['pool_ids']; }
                        else { throw new \RuntimeException('監査記録に pool_ids がありません（0件とは区別）'); }
                        if (!is_array($pool)) { throw new \RuntimeException('pool_ids の型が不正'); }
                        $res[$mode] = ['rows' => $rows, 'pool' => $pool, 'jump' => array_values(array_filter($pool, static fn($id) => isset($jumpIds[$id])))];
                    }
                } catch (\Throwable $e) {
                    $res[$mode] = ['ng' => '例外: ' . mb_substr($e->getMessage(), 0, 100)];
                } finally {
                    \DB::rollBack();
                    $A::$expectedLegacyJumpAsFlat = false;
                    $W::setTestNow(null);
                }
            }
            $line = sprintf('%-20s %s', $rid, mb_substr((string) $r->race_name, 0, 14));
            $isJump = (\App\Console\Commands\SummaryExpectedScore::classGroup((string) ($r->course ?? ''), (string) ($r->grade ?? ''), (string) ($r->race_name ?? ''), null) === 'JUMP');
            if (isset($res['before']['ng']) || isset($res['after']['ng'])) {
                $tot['ng']++;
                $this->line($line . ' NG 前=' . ($res['before']['ng'] ?? 'OK') . ' 後=' . ($res['after']['ng'] ?? 'OK'));
                continue;
            }
            $b = $res['before']; $a = $res['after'];
            foreach ($b['rows'] as $row) { $k = (string) $row['status']; $tot['status_before'][$k] = ($tot['status_before'][$k] ?? 0) + 1; }
            foreach ($a['rows'] as $row) { $k = (string) $row['status']; $tot['status_after'][$k] = ($tot['status_after'][$k] ?? 0) + 1; }
            $tot['pool_jump_before'] += count($b['jump']); $tot['pool_jump_after'] += count($a['jump']);
            $diff = [];
            foreach ($b['rows'] as $no => $rb) {
                $ra = $a['rows'][$no] ?? null;
                if ($ra === null) { $diff[] = $no . ':行なし'; continue; }
                $d = [];
                foreach (['status', 'M', 'p0_3', 'p0_5', 'E3', 'E5'] as $k) {
                    if (json_encode($rb[$k] ?? null) !== json_encode($ra[$k] ?? null)) {
                        $d[] = $k . ' ' . json_encode($rb[$k] ?? null) . '→' . json_encode($ra[$k] ?? null);
                    }
                }
                if ($d) { $diff[] = $no . '番(' . implode(', ', $d) . ')'; }
            }
            if ($diff) { $tot['changed_races']++; $tot['changed_horses'] += count($diff); }
            // @ANCHOR-20261111-CHECK-STRICT  期待する結果を合否条件にします（人が読むだけにしない）
            $why = [];
            if ($a['jump'] !== []) { $why[] = '直した後も基準集団に障害レース'; }
            if ($isJump) {
                foreach ($a['rows'] as $no => $ra) {
                    if (($ra['status'] ?? null) !== 'OUT_OF_SCOPE' || ($ra['E3'] ?? null) !== null || ($ra['E5'] ?? null) !== null) { $why[] = $no . '番が対象外になっていない'; }
                }
            } elseif ($b['jump'] === [] && $diff !== []) {
                $why[] = '障害の混入が無かった平地レースで値が変わった';
            }
            if ($why) { $tot['ng']++; $tot['ng_why'][] = $rid . '：' . implode('、', array_unique($why)); }
            $line .= ' M ' . count($b['pool']) . '→' . count($a['pool']) . ' 障害の混入 ' . count($b['jump']) . '→' . count($a['jump'])
                   . ($b['jump'] ? '（' . implode(',', $b['jump']) . '）' : '') . ' 値が変わった馬 ' . count($diff) . '頭';
            $this->line($line);
            foreach (array_slice($diff, 0, 20) as $dd) { $this->line('      ' . $dd); }
        }
        $this->line('');
        $this->line('==== まとめ');
        $this->line("  レース数 {$tot['races']} ／ NG {$tot['ng']} ／ 基準集団に入った障害レース（延べ） 前 {$tot['pool_jump_before']} → 後 {$tot['pool_jump_after']}");
        $this->line("  値が変わったレース {$tot['changed_races']} ／ 値が変わった馬 {$tot['changed_horses']}頭");
        $this->line('  E3・E5 の状態（前） ' . json_encode($tot['status_before'], JSON_UNESCAPED_UNICODE));
        $this->line('  E3・E5 の状態（後） ' . json_encode($tot['status_after'], JSON_UNESCAPED_UNICODE));
        // if ($tot['ng'] === 0 && $tot['pool_jump_after'] === 0) { ... }   // 旧（0件でも成功表示になりえた）
        // @ANCHOR-20261111-CHECK-STRICT  対象レース0件・NGあり・混入ありは不合格。不合格は終了コード1（例外）で呼び出し元に伝えます
        foreach (($tot['ng_why'] ?? []) as $w) { $this->line('  NG ' . $w); }
        if ($tot['races'] > 0 && $tot['ng'] === 0 && $tot['pool_jump_after'] === 0) { $this->info('==== 結果: 合格（対象 ' . $tot['races'] . 'レース・直した後は障害レースの混入0・障害は全頭対象外・ほかの平地は変化なし。DBはレースごとに元に戻しました）'); }
        else { $this->error('==== 結果: 不合格（対象 ' . $tot['races'] . 'レース・NG ' . $tot['ng'] . '件。DBはレースごとに元に戻しました）'); throw new \RuntimeException('障害レースの比較: 不合格'); }
    }

    /**
     * @ANCHOR-20261111-CHECK-POOLSTRICT  当日確認の pool_ids の扱いを、正常1件・異常を含む7件で確かめます（なる 11/11 夜）
     *   ① 読み出し関数だけの確認（DBに触りません）
     *   ② 当日確認そのものを、指定日の最初の1レースで、監査記録を書き換えて流します（DBはレースごとに元に戻ります）
     *   どれか1つでも期待と違えば、最後に例外を投げます（終了コード1）。
     */
    private function runPredictionExpectedPoolStrictTest(string $date): void
    {
        $ok = 0; $bad = 0;
        $check = function (string $name, bool $pass, string $detail = '') use (&$ok, &$bad) {
            if ($pass) { $ok++; $this->line('  OK  ' . $name . ($detail !== '' ? '（' . $detail . '）' : '')); }
            else { $bad++; $this->error('  NG  ' . $name . ($detail !== '' ? '（' . $detail . '）' : '')); }
        };
        $throws = static function (array $aj): ?string {
            try { self::strictPoolIdsFromAudit($aj); return null; } catch (\RuntimeException $e) { return $e->getMessage(); }
        };
        $this->line('==== ① 読み出し関数（DBに触りません）');
        $check('P-01 最上位の正しい空配列は M=0', self::strictPoolIdsFromAudit(['pool_ids' => []]) === []);
        $check('P-02 packet の正しい空配列は M=0', self::strictPoolIdsFromAudit(['packet' => ['pool_ids' => []]]) === []);
        $check('P-03 中身ありはそのまま', self::strictPoolIdsFromAudit(['packet' => ['pool_ids' => ['a', 'b']]]) === ['a', 'b']);
        $m = $throws(['packet' => ['excluded' => []]]);
        $check('P-04 キー欠落は不合格', $m !== null && str_contains($m, 'pool_ids がありません'), (string) $m);
        $m = $throws(['pool_ids' => null]);
        $check('P-05 null は型不正で不合格', $m !== null && str_contains($m, '型が不正'), (string) $m);
        $m = $throws(['packet' => ['pool_ids' => 'x']]);
        $check('P-06 文字列は型不正で不合格', $m !== null && str_contains($m, '型が不正'), (string) $m);
        $m = $throws(['packet' => 'x']);
        $check('P-07 packet が配列でなくキーも無いときは不合格', $m !== null && str_contains($m, 'pool_ids がありません'), (string) $m);

        $this->line('');
        $this->line('==== ② 当日確認そのもの（' . $date . ' の最初の1レース・AIへは送りません・DBは元に戻します）');
        $cases = [
            ['C-01 書き換えなし → 合格', null, true, null],
            ['C-02 pool_ids を消す → 不合格（終了コード1）', static function (array $aj): array {
                unset($aj['pool_ids']); if (is_array($aj['packet'] ?? null)) { unset($aj['packet']['pool_ids']); } return $aj; }, false, 'pool_ids がありません'],
            ['C-03 pool_ids を文字列にする → 不合格（終了コード1）', static function (array $aj): array {
                if (is_array($aj['packet'] ?? null) && array_key_exists('pool_ids', $aj['packet'])) { $aj['packet']['pool_ids'] = 'x'; } else { $aj['pool_ids'] = 'x'; } return $aj; }, false, '型が不正'],
            ['C-04 pool_ids を正しい空配列にする → 合格・M=0', static function (array $aj): array {
                if (is_array($aj['packet'] ?? null) && array_key_exists('pool_ids', $aj['packet'])) { $aj['packet']['pool_ids'] = []; } else { $aj['pool_ids'] = []; } return $aj; }, true, null],
        ];
        self::$testCheckDayLimit = 1;
        try {
            foreach ($cases as [$name, $tamper, $wantPass, $wantMsg]) {
                self::$testCheckDayTamper = $tamper;
                self::$testCheckDayLastNg = null;
                $passed = true;
                $this->line('  ---- ' . $name);
                try { $this->runPredictionExpectedCheckDay($date); }
                catch (\RuntimeException $e) { $passed = false; }
                $ngText = implode(' / ', (array) self::$testCheckDayLastNg);
                if ($wantPass) {
                    $check($name, $passed && self::$testCheckDayLastNg === [], $ngText);
                } else {
                    $check($name, !$passed && $wantMsg !== null && str_contains($ngText, $wantMsg), $ngText);
                }
            }
        } finally {
            self::$testCheckDayTamper = null;
            self::$testCheckDayLimit = null;
        }
        $this->line('');
        if ($bad === 0) { $this->info("==== 結果: すべてOK（{$ok}件）"); return; }
        $this->error("==== 結果: 不合格（OK {$ok}件・NG {$bad}件）");
        throw new \RuntimeException('pool_ids の確認: 不合格');
    }

    private function runPredictionExpectedCheckDay(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { $this->error('日付は 2026-11-08 の形で指定してください'); return; }
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $NS = '\\App\\Http\\Controllers\\Api\\';
        $A = $NS . 'AiController'; $T = $NS . 'BaganrikiTime'; $W = $NS . 'BaganrikiWorker';
        $c = app($A);
        $prep = new \ReflectionMethod($A, 'expectedPrepare'); $prep->setAccessible(true);
        $races = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                   ->orderBy('basho')->orderBy('race')->get(['kaisuu', 'basho', 'day', 'race', 'start_time']);
        if (self::$testCheckDayLimit !== null) { $races = $races->take(self::$testCheckDayLimit)->values(); }   // @ANCHOR-20261111-CHECK-POOLSTRICT  試験のときだけ
        $this->line("==== {$date} の全レース（" . count($races) . 'レース）で、凍結と送る原文の準備を試します（AIへは送りません・0円）');
        $sum = ['races' => 0, 'freeze_ok' => 0, 'prep_ok' => ['1st' => 0, '2nd' => 0], 'ng' => [], 'status' => [], 'horses' => 0,
                'bytes' => ['1st' => [0, 0], '2nd' => [0, 0]], 'excluded' => []];
        foreach ($races as $r) {
            $sum['races']++;
            $st = $T::normalizeRaceStartClock((string) ($r->start_time ?? ''));
            $anchorUs = (int) $T::us($date . 'T' . $st . '+09:00');
            \DB::beginTransaction();
            $line = '';
            try {
                // @ANCHOR-20261111-EXPECTED-JUMP  前の試験の世代900が残っているレースは、このトランザクションの中でだけ外して凍結し直します（最後に元に戻ります）
                self::hideGen900InTransaction(\App\Http\Controllers\Api\AiController::buildPredictionRaceId($date, (string) $r->kaisuu, (string) $r->basho, (string) $r->day, (int) $r->race));
                $W::setTestNow($anchorUs + $T::INPUT_FREEZE_OFFSET_SEC * 1000000 + 3000000);
                $f = $c->freezePredictionInput($date, (string) $r->kaisuu, (string) $r->basho, (string) $r->day, (int) $r->race, [], 'REPLAY');
                $line = sprintf('%-20s ', (string) ($f['race_id'] ?? ($r->basho . '-' . $r->race)));
                if (!$f['ok']) {
                    $line .= '凍結: NG ' . ($f['code'] ?? '') . ' ' . mb_substr((string) ($f['reason'] ?? ''), 0, 80);
                    $sum['ng'][] = $line;
                } else {
                    $sum['freeze_ok']++;
                    $snap = \DB::table('baganriki_snapshots')->where('race_id', $f['race_id'])->where('generation', 900)->first();
                    // @ANCHOR-20261111-CHECK-STRICT  必要な記録の欠落を NG にします（0件と区別）
                    if ($snap === null) { throw new \RuntimeException('凍結の行がありません'); }
                    $in = json_decode((string) $snap->input_json_1st, true);
                    if (!is_array($in) || !is_array($in['expected_v01']['rows'] ?? null) || !is_array($in['runners'] ?? null)) { throw new \RuntimeException('expected_v01.rows または runners がありません'); }
                    if (count($in['expected_v01']['rows']) !== count($in['runners'])) { throw new \RuntimeException('expected_v01.rows の頭数が出走馬と一致しません'); }
                    $rows = (array) ($in['expected_v01']['rows'] ?? []);
                    $sum['horses'] += count($rows);
                    foreach ($rows as $row) { $k = (string) ($row['status'] ?? '?'); $sum['status'][$k] = ($sum['status'][$k] ?? 0) + 1; }
                    // @ANCHOR-20261111-PREDICTION-EXPECTED-WHY  よっしー11/11 ①：E3・E5 が比較に使えない理由を分けて数えます
                    //   UNAVAILABLE_INPUT … 行の reason（INCOMPLETE_ODDS6／MISSING_CONDITIONS／MISSING_ODDS21／FORMULA_NUMERIC_RANGE／HISTORY_NOT_PROVIDED）
                    //   LOW_SUPPORT       … COMPARISON_READY の条件（M≥100・K≥20・W≥10・n_eff≥15）のうち、どれが足りないか（重複あり）
                    foreach ($rows as $row) {
                        $st = (string) ($row['status'] ?? '?');
                        if ($st === 'UNAVAILABLE_INPUT' || $st === 'OUT_OF_SCOPE') {
                            $rs = (string) ($row['reason'] ?? '?');
                            $sum['why'][$st][$rs] = ($sum['why'][$st][$rs] ?? 0) + 1;
                            $sum['why_race'][$st . ':' . $rs][(string) $f['race_id']] = ($sum['why_race'][$st . ':' . $rs][(string) $f['race_id']] ?? 0) + 1;
                        } elseif ($st === 'LOW_SUPPORT') {
                            foreach (['M<100' => (($row['M'] ?? 0) < 100), 'K<20' => (($row['K'] ?? 0) < 20), 'W<10' => (($row['W'] ?? 0) < 10), 'n_eff<15' => (($row['n_eff'] ?? 0) < 15)] as $lab => $hit) {
                                if ($hit) { $sum['low'][$lab] = ($sum['low'][$lab] ?? 0) + 1; }
                            }
                        }
                        if (array_key_exists('M', $row) && $row['M'] !== null) { $mm = (int) $row['M']; $sum['m_hist'][$mm] = ($sum['m_hist'][$mm] ?? 0) + 1; }
                    }
                    $au = \DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', 'PREDICTION_EXPECTED_AUDIT')->first();
                    $aj = $au ? json_decode((string) $au->diagnostics, true) : null;
                    if (self::$testCheckDayTamper !== null && is_array($aj)) { $aj = (self::$testCheckDayTamper)($aj); }   // @ANCHOR-20261111-CHECK-POOLSTRICT  試験のときだけ
                    if (!is_array($aj)) { throw new \RuntimeException('監査記録 PREDICTION_EXPECTED_AUDIT がありません'); }
                    // @ANCHOR-20261111-CHECK-POOLSTRICT  なる 11/11 夜：pool_ids の欠落・型不正は空配列扱いにせず NG（正しい空配列だけが M=0）
                    $pool = self::strictPoolIdsFromAudit($aj);
                    $pk = is_array($aj) ? ($aj['packet'] ?? $aj) : [];
                    foreach ((array) ($pk['excluded'] ?? []) as $why => $n) { $sum['excluded'][$why] = ($sum['excluded'][$why] ?? 0) + (int) $n; }
                    // $line .= '凍結: OK ' . count($rows) . '頭 M=' . count((array) ($pk['pool_ids'] ?? []));   // 旧（欠落も M=0 と表示していた）
                    $line .= '凍結: OK ' . count($rows) . '頭 M=' . count($pool);
                    $A::$testExpectedNowUs = (int) $T::us((string) $in['input_freeze_at']) + 5000000;
                    foreach (['1st', '2nd'] as $role) {
                        $p = $prep->invoke(null, $snap, $role, null);
                        if (!$p['ok']) { $line .= " {$role}: NG " . mb_substr((string) $p['reason'], 0, 80); $sum['ng'][] = $line; continue; }
                        $pr = $p['prepared'];
                        $sum['prep_ok'][$role]++;
                        $sum['bytes'][$role][0] += strlen($pr['user_json']);
                        $sum['bytes'][$role][1] += strlen((string) $snap->{'input_json_' . $role});
                        $line .= " {$role}: OK {$pr['format']}";
                    }
                    $A::$testExpectedNowUs = null;
                }
            } catch (\Throwable $e) {
                $line .= ' 例外: ' . mb_substr($e->getMessage(), 0, 120);
                $sum['ng'][] = $line;
            } finally {
                \DB::rollBack();
                $A::$testExpectedNowUs = null;
                $W::setTestNow(null);
            }
            $this->line($line);
        }
        $this->line('');
        $this->line('==== まとめ');
        $this->line("  レース数 {$sum['races']} ／ 凍結できた {$sum['freeze_ok']} ／ 1stの準備OK {$sum['prep_ok']['1st']} ／ 2ndの準備OK {$sum['prep_ok']['2nd']}");
        foreach (['1st', '2nd'] as $role) {
            [$w, $o] = $sum['bytes'][$role];
            $this->line(sprintf('  %s：送る本文の合計 %d バイト（元 %d バイト、%.1f%%）', $role, $w, $o, $o > 0 ? $w * 100 / $o : 0));
        }
        $this->line('  E3・E5 の状態（頭数） ' . json_encode($sum['status'], JSON_UNESCAPED_UNICODE) . ' 合計 ' . $sum['horses'] . '頭');
        $this->line('  過去レースを外した理由（延べ） ' . json_encode($sum['excluded'], JSON_UNESCAPED_UNICODE));
        // @ANCHOR-20261111-PREDICTION-EXPECTED-WHY
        $this->line('  比較に使えない理由（材料不足・対象外の内訳・頭数） ' . json_encode($sum['why'] ?? [], JSON_UNESCAPED_UNICODE));
        foreach (($sum['why_race'] ?? []) as $lab => $byRace) { ksort($byRace); $this->line('    ' . $lab . ' のレース別頭数 ' . json_encode($byRace, JSON_UNESCAPED_UNICODE)); }
        $this->line('  類似例が少ない馬で、足りない条件（頭数・重複あり） ' . json_encode($sum['low'] ?? [], JSON_UNESCAPED_UNICODE));
        $mh = $sum['m_hist'] ?? []; ksort($mh);
        $this->line('  比べられた過去レース数 M の分布（M＝その数：頭数） ' . json_encode($mh, JSON_UNESCAPED_UNICODE));
        // if ($sum['ng'] === []) { ... }   // 旧（対象0件でも「すべてOK」になりえた）
        // @ANCHOR-20261111-CHECK-STRICT  対象0件・凍結や準備が全件そろわない・NGありは不合格。不合格は終了コード1（例外）
        self::$testCheckDayLastNg = $sum['ng'];   // @ANCHOR-20261111-CHECK-POOLSTRICT
        $allOk = $sum['races'] > 0 && $sum['ng'] === [] && $sum['freeze_ok'] === $sum['races']
                 && $sum['prep_ok']['1st'] === $sum['races'] && $sum['prep_ok']['2nd'] === $sum['races'];
        if ($allOk) { $this->info('==== 結果: すべてOK（対象 ' . $sum['races'] . 'レース。DBはレースごとに元に戻しました）'); }
        else {
            $this->error('==== 結果: 不合格（対象 ' . $sum['races'] . 'レース・NG ' . count($sum['ng']) . '件。DBはレースごとに元に戻しました）'); foreach ($sum['ng'] as $n) { $this->line('  ' . $n); }
            throw new \RuntimeException('当日確認: 不合格');
        }
    }

    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-SUMMARY  1レースの成否を、段階ごとに分けて数えます（なる11/10 2章・4章・5章）
     *   役割ごと：返事に書いた行数／根拠を書いた馬／検査を通った行数／選出資格あり（ELIGIBLE）／帯ごとの順位の採用元／送り直し
     *   レース：最終候補、E3・E5 の境界判断（AIの判断と、比べられる状態かどうかから決まるサーバの判断）、表示指標の値と null の理由
     *   null の理由：NO_TARGET（対象の人気帯の馬がいない）／ROLE_UNUSED（1st の返事を使えなかった）／AI_NULL（AIが null を返した）
     *               ／CHECK_FAILED（AIは値を出したが検査で外れた）／OK
     *   E3・E5 の境界：候補と次点の両方が COMPARISON_READY でなければ、サーバは KEEP/SWAP を出さない（E_UNAVAILABLE かそれより前の停止理由）。
     *   AIが KEEP/SWAP と書いていたら mismatch=true にします（公開の候補はサーバの判定で決めるので、AIの判断は使いません）。
     */
    private function predictionExpectedRaceSummary(object $snap, array $insByRole): array
    {
        $in = json_decode((string) $snap->input_json_1st, true) ?: [];
        $runners = (array) ($in['runners'] ?? []);
        $rankOf = [];
        foreach ($runners as $r) { if (isset($r['horse_no'])) { $rankOf[(int) $r['horse_no']] = is_int($r['win_rank'] ?? null) ? $r['win_rank'] : null; } }
        $estat = [];
        foreach ((array) ($in['expected_v01']['rows'] ?? []) as $row) {
            $no = (int) ($row['horse_no'] ?? (is_string($row['horse_id'] ?? null) ? substr($row['horse_id'], 1) : 0));
            if ($no > 0) { $estat[$no] = (string) ($row['status'] ?? '?'); }
        }
        $pub = \DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->orderByDesc('id')->first();
        $ev = $pub ? (json_decode((string) $pub->evidence, true) ?: []) : [];
        $out = ['race_id' => $snap->race_id, 'runners' => count($runners), 'roles' => [], 'published' => $pub !== null,
                'final_candidates' => $pub ? array_map(static fn($c) => ['horse_no' => $c['horse_no'] ?? null, 'band' => $c['band'] ?? null],
                                                       (array) (json_decode((string) $pub->candidates, true) ?: [])) : []];
        foreach (['1st', '2nd'] as $role) {
            $ins = $insByRole[$role] ?? [];
            $reply = $ins['reply_json'] ?? null;
            if (!is_array($reply)) {
                $raw = (string) (\DB::table('baganriki_ai_attempts')->where('snapshot_id', $snap->id)->where('role', $role)->where('attempt_no', 0)->value('raw_response') ?? '');
                $a = strpos($raw, '{'); $b = strrpos($raw, '}');
                $reply = ($a !== false && $b !== false) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
            }
            $rows = is_array($reply['horses'] ?? null) ? $reply['horses'] : [];
            $evH = 0; foreach ($rows as $h) { if (is_array($h['evidence'] ?? null) && $h['evidence'] !== []) { $evH++; } }
            $usable = (array) ($ins['normal'] ?? []);
            $elig = 0; foreach ($usable as $h) { if (($h['eligibility'] ?? null) === 'ELIGIBLE') { $elig++; } }
            $reviews = [];
            foreach ((array) ($reply['expected_reviews'] ?? []) as $band => $rv) {
                if (!is_array($rv)) { continue; }
                $i = $rv['incumbent'] ?? null; $j = $rv['challenger'] ?? null;
                $ready = is_int($i) && is_int($j) && ($estat[$i] ?? '') === 'COMPARISON_READY' && ($estat[$j] ?? '') === 'COMPARISON_READY';
                $allowed = (!is_int($i) || !is_int($j)) ? 'NO_BOUNDARY' : ($ready ? 'KEEP/SWAP（E3・E5の比較で決まる）' : 'E_UNAVAILABLE（またはその前の停止理由）');
                $reviews[$band] = ['ai_decision' => $rv['decision'] ?? null, 'incumbent' => $i, 'challenger' => $j,
                                   'e_status' => ['incumbent' => is_int($i) ? ($estat[$i] ?? null) : null, 'challenger' => is_int($j) ? ($estat[$j] ?? null) : null],
                                   'server_allows' => $allowed,
                                   'mismatch' => in_array($rv['decision'] ?? null, ['KEEP', 'SWAP'], true) && !$ready];
            }
            $out['roles'][$role] = ['code' => $ins['code'] ?? null, 'rows_returned' => count($rows), 'rows_with_evidence' => $evH,
                'rows_passed_inspection' => count($usable), 'eligible' => $elig, 'tidied' => count((array) ($ins['tidied'] ?? [])),
                'rank_source' => $ev['rank_source'][$role] ?? null, 'expected_reviews' => $reviews];
        }
        $rm = $ev['race_metrics'] ?? null;
        $ai = $insByRole['1st']['reply_json']['race_metrics'] ?? null;
        $first = $out['roles']['1st'];
        $hasLower = false; $hasBig = false;
        foreach ($rankOf as $rk) { if ($rk !== null && $rk >= 7 && $rk <= 10) { $hasLower = true; } if ($rk !== null && $rk >= 11) { $hasBig = true; } }
        $metrics = [];
        foreach (['wave_level' => true, 'lower_entry' => $hasLower, 'big_gap_entry' => $hasBig] as $k => $target) {
            $val = $rm['values'][$k] ?? null;
            $why = 'OK';
            if ($val === null) {
                if (!$target) { $why = 'NO_TARGET'; }
                elseif (($first['code'] ?? '') === 'AI_FAILURE' || ($first['rows_passed_inspection'] ?? 0) === 0 || $rm === null) { $why = 'ROLE_UNUSED'; }
                elseif (!is_int($ai[$k]['value'] ?? null)) { $why = 'AI_NULL'; }
                else { $why = 'CHECK_FAILED'; }
            }
            $metrics[$k] = ['published' => $val, 'ai' => $ai[$k]['value'] ?? null, 'status' => $rm['status'][$k] ?? null, 'why_null' => $why];
        }
        $out['race_metrics'] = $metrics;
        $u = \DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', 'R122_USAGE')->where('related_id', '1st#0')->value('diagnostics');
        $ud = $u ? json_decode((string) $u, true) : null;
        if (is_array($ud['resent'] ?? null)) {
            $rs = $ud['resent']; foreach ((array) ($rs['sends'] ?? []) as $ri => $sd) { unset($rs['sends'][$ri]['raw_unused']); }
            $out['roles']['1st']['resent'] = $rs;
        }
        return $out;
    }

    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-EXPORT  非公開試験（世代900）の記録を、1レースずつファイルに書き出します（読むだけ）
     *   よっしー11/10「実際の1レース分の送信全文・両AIの回答・補正前後と検査結果」を、なるさんへ渡すためのものです。
     *   置き場所：~/baganriki_export/<race_id>/（DBは変えません。検査は保存済みの返事をもう一度読むだけで、AIへは送りません）
     */
    private function runPredictionExpectedExport(): void
    {
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $NS = '\\App\\Http\\Controllers\\Api\\';
        $c = app($NS . 'AiController');
        $root = rtrim((string) (getenv('HOME') ?: '/tmp'), '/') . '/baganriki_export';
        $json = static fn($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        $snaps = \DB::table('baganriki_snapshots')->where('generation', 900)->orderBy('id')->get();
        if (count($snaps) === 0) { $this->error('世代900の記録がありません（先に --prediction-expected-private を動かしてください）'); return; }
        foreach ($snaps as $snap) {
            $dir = $root . '/' . $snap->race_id;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $this->error("書き出し先を作れません: {$dir}"); continue; }
            $files = [];
            $put = static function (string $name, string $body) use ($dir, &$files) { file_put_contents($dir . '/' . $name, $body); $files[] = $name . '（' . strlen($body) . 'バイト）'; };
            $put('00_frozen_input_1st.json', (string) $snap->input_json_1st);
            $put('00_frozen_input_2nd.json', (string) $snap->input_json_2nd);
            $put('00_snapshot.json', $json(['race_id' => $snap->race_id, 'release_version' => $snap->release_version, 'snapshot_hash' => $snap->snapshot_hash,
                'anchor_at' => $snap->anchor_at, 'cutoff_at' => $snap->cutoff_at, 'input_freeze_at' => $snap->input_freeze_at, 'frozen_at' => $snap->frozen_at,
                'sales_close_at' => $snap->sales_close_at, 'mode' => $snap->mode, 'mode_status' => $snap->mode_status, 'allocation_mode' => $snap->allocation_mode,
                'target_count' => $snap->target_count, 'quota_1st' => $snap->quota_1st, 'quota_2nd' => $snap->quota_2nd,
                'collection_audit' => json_decode((string) $snap->collection_audit, true), 'expected_proof' => json_decode((string) $snap->expected_proof, true)]));
            $insBy = [];
            foreach (['1st' => '1', '2nd' => '2'] as $role => $n) {
                foreach (\DB::table('baganriki_ai_attempts')->where('snapshot_id', $snap->id)->where('role', $role)->orderBy('attempt_no')->get() as $a) {
                    $sent = json_decode((string) ($a->sent_request ?? ''), true);
                    $no = (int) $a->attempt_no;
                    $put("{$n}{$no}_{$role}_send_system.txt", (string) ($sent['system_text'] ?? ''));
                    $put("{$n}{$no}_{$role}_send_user.json", (string) ($sent['user_json'] ?? ''));
                    $put("{$n}{$no}_{$role}_send_info.json", $json(['format' => $sent['format'] ?? null, 'attempt_no' => $no, 'status' => $a->status,
                        'reserved_at' => $a->reserved_at, 'received_at' => $a->received_at, 'complete' => (bool) $a->complete,
                        'system_sha256' => $sent['system_sha256'] ?? null, 'user_sha256' => isset($sent['user_json']) ? hash('sha256', (string) $sent['user_json']) : null,
                        'response_hash' => $a->response_hash, 'failure_reason' => $a->failure_reason]));
                    $put("{$n}{$no}_{$role}_reply_raw.txt", (string) ($a->raw_response ?? ''));
                }
                ($NS . 'BaganrikiResponseInspection')::$salvageWhy = [];
                $ins = $c->inspectAiResponse((int) $snap->id, $role, 0);
                $insBy[$role] = $ins;
                if (array_key_exists('reply_json', $ins)) { $put("{$n}0_{$role}_reply_tidied.json", $json($ins['reply_json'])); }
                $put("{$n}0_{$role}_inspection.json", $json(['code' => $ins['code'] ?? null, 'stage' => $ins['stage'] ?? null, 'reason' => $ins['reason'] ?? null,
                    'complete' => $ins['complete'] ?? null, 'tidied' => $ins['tidied'] ?? [], 'findings' => $ins['diag']['findings'] ?? null,
                    'usable_horses' => $ins['normal'] ?? [], 'salvage_detail' => $ins['salvage_detail'] ?? null,
                    'salvage_why' => ($NS . 'BaganrikiResponseInspection')::$salvageWhy]));
            }
            $put('30_publications.json', $json(\DB::table('baganriki_publications')->where('snapshot_id', $snap->id)->orderBy('id')->get()->map(static function ($p) {
                $p = (array) $p; foreach ($p as $k => $v) { if (is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')) { $d = json_decode($v, true); if ($d !== null) { $p[$k] = $d; } } } return $p; })->all()));
            $usage = \DB::table('baganriki_audit_events')->where('snapshot_id', $snap->id)->where('kind', 'R122_USAGE')->orderBy('id')
                    ->get(['related_id', 'diagnostics'])->map(static fn($e) => ['related_id' => $e->related_id, 'diagnostics' => json_decode((string) $e->diagnostics, true)])->all();
            // 精算の行は、この試験の使用量に書かれた予約番号のものだけ（同じレースの本番の行と混ぜない）
            $holds = array_values(array_filter(array_map(static fn($u) => $u['diagnostics']['budget_hold'] ?? null, $usage)));
            $settle = $holds === [] ? [] : \DB::table('baganriki_audit_events')->where('kind', 'R122_BUDGET_SETTLE')->whereIn('related_id', $holds)->orderBy('id')
                    ->get(['related_id', 'diagnostics'])->map(static fn($e) => ['related_id' => $e->related_id, 'diagnostics' => json_decode((string) $e->diagnostics, true)])->all();
            $total = 0.0; foreach ($settle as $st) { $total += (float) ($st['diagnostics']['amount_usd'] ?? 0); }
            $put('40_usage_and_cost.json', $json(['usage' => $usage, 'settle' => $settle, 'total_usd_conservative' => round($total, 6)]));
            // 送り直しで使わなかった返事の原文は、別のファイルにも出します（なる11/10 7章）
            foreach ($usage as $uu) {
                // 送り直しは 1st・2nd の両方にあるので、ファイル名を役ごとに分けます（20261110 夜。旧は 1st の名前だけでした）
                $ur = (strpos((string) ($uu['related_id'] ?? ''), '2nd') === 0) ? '2nd' : '1st';
                foreach ((array) ($uu['diagnostics']['resent']['sends'] ?? []) as $sd) {
                    // if (is_string($sd['raw_unused'] ?? null)) { $put('11_1st_resend_' . (int) $sd['n'] . '_unused_reply_raw.txt', $sd['raw_unused']); }   // 旧
                    if (is_string($sd['raw_unused'] ?? null)) { $put(($ur === '2nd' ? '21' : '11') . '_' . $ur . '_resend_' . (int) $sd['n'] . '_unused_reply_raw.txt', $sd['raw_unused']); }
                }
            }
            $put('50_summary.json', $json($this->predictionExpectedRaceSummary($snap, $insBy)));
            $this->line("==== {$snap->race_id} → {$dir}");
            foreach ($files as $fl) { $this->line('  ' . $fl); }
        }
        $this->info('==== 書き出しました（DBは変えていません）');
    }

    /**
     * @ANCHOR-20261110-PREDICTION-EXPECTED-FAKE  よっしー11/10夜 ③④ の再現試験（AIへは送りません・費用0円）
     *   ~/baganriki_export/<race_id>/ に書き出した返事の原文を、偽のAI（Http::fake）が返します。
     *   凍結し直した世代900で、本番と同じ送信口（sendInitialAiRequest）→ 送り直し → 検査・採否（resumeReceivedRole）→ 公開（publishWhenReady）→ 料金の記録 を通します。
     *     京都11R：2nd の1回目は2頭しか書かない返事 → 送り直しの返事（本物）を使う
     *     京都6R ：2nd の1回目は根拠を5頭だけに残した返事（検査を通る馬あり）→ 送り直しは全頭書くが出典が全部でたらめ（検査で全頭不採用）→ 1回目を使う
     *     東京9R ：1st・2nd とも、1回目も送り直しも読めない返事 → 予想を出せない。途中で「出せない」と決めないこと・記録が1件だけ・画面の文面
     *     福島11R：両方とも本物の返事（送り直しなし）＋「評価は終わったが候補0頭」の記録の分け方（DBを元に戻す）
     */
    private function runPredictionExpectedFake(): void
    {
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $NS = '\\App\\Http\\Controllers\\Api\\';
        $W = $NS . 'BaganrikiWorker'; $T = $NS . 'BaganrikiTime'; $A = $NS . 'AiController';
        $c = app($A);
        $answer = new \ReflectionMethod($A, 'predictionPublishedAnswer'); $answer->setAccessible(true);
        $screen = new \ReflectionMethod($A, 'predictionAnswerForRace'); $screen->setAccessible(true);
        $record = new \ReflectionMethod($A, 'recordNotPublished'); $record->setAccessible(true);
        $root = rtrim((string) (getenv('HOME') ?: '/tmp'), '/') . '/baganriki_export';
        $races = [
            'K11' => ['2026-11-08', '5', '08', '2', 11],
            'K6'  => ['2026-11-08', '5', '08', '2', 6],
            'T9'  => ['2026-11-08', '5', '05', '2', 9],
            'F11' => ['2026-11-08', '3', '03', '2', 11],
        ];
        $pass = 0; $fail = 0;
        $check = function (string $what, bool $ok, string $got = '') use (&$pass, &$fail) {
            if ($ok) { $pass++; } else { $fail++; }
            $this->line('  ' . ($ok ? '○' : '×') . ' ' . $what . ($got !== '' ? '　［' . $got . '］' : ''));
        };
        // 返事の JSON は、{} と [] の区別を崩さないよう、オブジェクトのまま読み書きします
        $jsonOf = static function (string $raw): ?object {
            $a = strpos($raw, '{'); $b = strrpos($raw, '}');
            if ($a === false || $b === false) { return null; }
            $j = json_decode(substr($raw, $a, $b - $a + 1));
            return is_object($j) ? $j : null;
        };
        $withJson = static function (string $raw, object $j): string {
            $a = strpos($raw, '{'); $b = strrpos($raw, '}');
            return substr($raw, 0, $a) . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . substr($raw, $b + 1);
        };
        $bogus = function ($v) use (&$bogus) {
            if (is_object($v)) {
                $o = clone $v;
                foreach (get_object_vars($o) as $k => $x) {
                    if ($k === 'source_refs' && is_array($x)) { $o->$k = array_map(static fn() => 'zz_not_in_input', $x); }
                    elseif ($k === 'source_ref' && is_string($x)) { $o->$k = 'zz_not_in_input'; }
                    else { $o->$k = $bogus($x); }
                }
                return $o;
            }
            if (is_array($v)) { return array_map($bogus, $v); }
            return $v;
        };
        // 偽のAI：役ごとに、返す返事を順に並べておきます（送られた回数も数えます）
        $queue = ['1st' => [], '2nd' => []]; $sentCount = ['1st' => 0, '2nd' => 0]; $onSend = ['1st' => [], '2nd' => []];
        $mk = static function (string $role, string $text, string $user): array {
            $in = max(1, intdiv(strlen($user), 3)); $outT = max(1, intdiv(strlen($text), 3));
            return $role === '2nd'
                ? ['id' => 'fake', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
                   'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $outT, 'total_tokens' => $in + $outT, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => $in]]
                : ['id' => 'fake', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn',
                   'content' => [['type' => 'text', 'text' => $text]],
                   'usage' => ['input_tokens' => $in, 'output_tokens' => $outT, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]];
        };
        $fakeFor = function (string $role) use (&$queue, &$sentCount, &$onSend, $mk) {
            return function ($request) use ($role, &$queue, &$sentCount, &$onSend, $mk) {
                $n = $sentCount[$role]++;
                if (isset($onSend[$role][$n])) { ($onSend[$role][$n])(); }
                $text = $queue[$role][$n] ?? '（偽のAI：用意した返事がありません）';
                $body = $request->data();
                $user = (string) json_encode($body['messages'] ?? []);
                return \Illuminate\Support\Facades\Http::response($mk($role, $text, $user), 200);
            };
        };
        \Illuminate\Support\Facades\Http::fake([
            'api.deepseek.com/*'  => $fakeFor('2nd'),
            'api.anthropic.com/*' => $fakeFor('1st'),
            '*' => \Illuminate\Support\Facades\Http::response(['error' => 'この試験では外へ送りません'], 599),
        ]);
        // 1st を AnthropicService 経由で送る設定（モデル未指定）のときは、偽のAIが効くか確かめていないので動かしません
        if (trim((string) env('BAGANRIKI_PREDICTION_1ST_MODEL', '')) === '') { $this->error('BAGANRIKI_PREDICTION_1ST_MODEL が空なので、この試験は動かしません'); return; }
        ($NS . 'BaganrikiSendConfig')::$promptFileTag = 'private';
        try {
            foreach ($races as $key => [$d, $k, $b, $dy, $rc]) {
                // 料金の歯止め（偽のAIなので実際にはかかりません）：非公開試験と同じく、その時点の記録＋3ドルまで
                $W::setTestNow(null);
                $rid = sprintf('%s-%s-%s-%s-%s', str_replace('-', '', $d), (int) $k, $b, $dy, $rc);
                $dir = $root . '/' . $rid;
                $old = json_decode((string) @file_get_contents($dir . '/00_snapshot.json'), true);
                $raw1 = (string) @file_get_contents($dir . '/10_1st_reply_raw.txt');
                $raw2 = (string) @file_get_contents($dir . '/20_2nd_reply_raw.txt');
                $this->line('');
                if (!is_array($old) || $raw1 === '' || $raw2 === '' || $jsonOf($raw2) === null) { $this->error("==== {$rid}: 書き出した返事がありません（{$dir}）"); $fail++; continue; }
                $rr = \DB::table('t_horse_odds_finder_races')->where('date', $d)->where('kaisuu', $k)->where('basho', $b)->where('day', $dy)->where('race', $rc)->first();
                $st = $T::normalizeRaceStartClock((string) ($rr->start_time ?? ''));
                $anchorUs = (int) $T::us($d . 'T' . $st . '+09:00');
                $freezeClock = $anchorUs + $T::INPUT_FREEZE_OFFSET_SEC * 1000000 + 3000000;
                $W::setTestNow($freezeClock);
                $cap = number_format($A::spentTodayUsd($W::nowUs()) + 3.0, 4, '.', '');
                foreach (['BAGANRIKI_PREDICTION_BUDGET_USD_DAY' => $cap] as $kk => $vv) { putenv("{$kk}={$vv}"); $_ENV[$kk] = $vv; $_SERVER[$kk] = $vv; }
                $f = $c->freezePredictionInput($d, $k, $b, $dy, $rc, [], 'REPLAY');
                $this->line("==== {$rid}（{$key}）凍結: " . ($f['ok'] ? 'OK' : 'NG') . ' ' . ($f['code'] ?? '') . (($f['skipped'] ?? false) ? '（前に作った世代900が残っています。消してから動かしてください）' : ''));
                if (!$f['ok'] || ($f['skipped'] ?? false)) { $fail++; continue; }
                $snap = \DB::table('baganriki_snapshots')->where('race_id', $rid)->where('generation', 900)->first();
                $sid = (int) $snap->id;
                $fix = static fn(string $r) => str_replace((string) $old['snapshot_hash'], (string) $snap->snapshot_hash, $r);
                $r1 = $fix($raw1); $r2 = $fix($raw2);
                $j2 = $jsonOf($r2);
                $sentCount = ['1st' => 0, '2nd' => 0]; $onSend = ['1st' => [], '2nd' => []];
                $probe = [];
                if ($key === 'K11') {
                    $jj = clone $j2; $jj->horses = array_slice((array) $j2->horses, 0, 2);
                    $queue = ['1st' => [$r1], '2nd' => [$withJson($r2, $jj), $r2]];
                } elseif ($key === 'K6') {
                    $jj = clone $j2; $kept = 0; $hs = [];
                    foreach ((array) $j2->horses as $h0) {
                        $h = clone $h0;
                        if (is_array($h->evidence ?? null) && $h->evidence !== [] && $kept < 5) { $kept++; $hs[] = $h; continue; }
                        $h->evidence = [];
                        $hs[] = $h;
                    }
                    $jj->horses = $hs;
                    $queue = ['1st' => [$r1], '2nd' => [$withJson($r2, $jj), $withJson($r2, $bogus($j2))]];
                } elseif ($key === 'T9') {
                    $bad = '申し訳ありません。この入力では評価を作れませんでした。';
                    $queue = ['1st' => [$bad, $bad], '2nd' => [$bad, $bad]];
                    // 2nd の送り直しの最中（2nd がまだ送信中）に、公開の判定と画面の判定を呼びます
                    $onSend['2nd'][1] = function () use ($c, $sid, $W, $A, &$probe) {
                        $p = $c->publishWhenReady($sid, ['now_us' => $W::nowUs()]);
                        $probe['during_2nd_resend'] = ['publish' => (string) ($p['code'] ?? ''), 'both_failed' => $A::predictionBothRolesFailed($sid),
                            'records' => \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->count()];
                    };
                } else {
                    // $queue = ['1st' => [$r1], '2nd' => [$r2]];   // 旧
                    // @ANCHOR-20261110-PREDICTION-RESEND-LATE  よっしー11/10夜3：2nd の1回目は2頭だけ（期限内）→ 送り直しは正常な全頭（中身は良い）だが、応答期限の5秒後に届く
                    //   → 中身の比べより先に遅着と判定し、期限内の1回目（原文・受領時刻・候補になれる馬）を保つこと
                    $jjF = clone $j2; $jjF->horses = array_slice((array) $j2->horses, 0, 2);
                    $r2first = $withJson($r2, $jjF);
                    $queue = ['1st' => [$r1], '2nd' => [$r2first, $r2]];
                    $onSend['2nd'][1] = function () use ($W, $sid, &$probe) {
                        $sn = \DB::table('baganriki_snapshots')->where('id', $sid)->first();
                        $lv = \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first();
                        $dl = $W::deadlines($sn, $lv);
                        $probe['reply_deadline_us_2nd'] = (int) ($dl['reply'] ?? 0);
                        $W::setTestNow((int) ($dl['reply'] ?? 0) + 5000000);
                    };
                    // @ANCHOR-20261110-PREDICTION-ARRIVED  よっしー11/10夜2 ①：1st の1回目は期限内に届く（候補になれる馬あり）→ 送り直しの最中に応答期限を過ぎる → 送り直しは読めない返事
                    //   → 1回目を使い、1回目が届いた時刻（期限内）で受領できること
                    $onSend['1st'][1] = function () use ($W, $sid, &$probe) {
                        $sn = \DB::table('baganriki_snapshots')->where('id', $sid)->first();
                        $lv = \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first();
                        $dl = $W::deadlines($sn, $lv);
                        $probe['reply_deadline_us'] = (int) ($dl['reply'] ?? 0);
                        $W::setTestNow((int) ($dl['reply'] ?? 0) + 5000000);   // 送り直しの返事は、応答期限の5秒後に届く
                    };
                }
                $settle = function (string $role) use ($A, $c, $sid) {
                    if (!$A::roleSettled($sid, $role)['settled']) { $c->resumeReceivedRole($sid, $role); }
                };
                foreach (['1st', '2nd'] as $role) {
                    $W::setTestNow($freezeClock);
                    $fs = $c->sendInitialAiRequest($sid, $role);
                    if ($key === 'F11' && $role === '1st') { $probe['clock_after_1st_us'] = $W::nowUs(); $probe['fs_1st'] = (string) ($fs['code'] ?? '') . ' ' . (string) ($fs['reason'] ?? ''); }
                    if ($key === 'F11' && $role === '2nd') { $probe['clock_after_2nd_us'] = $W::nowUs(); $probe['fs_2nd'] = (string) ($fs['code'] ?? '') . ' ' . (string) ($fs['reason'] ?? ''); }
                    $this->line(sprintf('  %s 送信: %s ／ 偽のAIへ送られた回数 %d', $role, (string) ($fs['code'] ?? ''), $sentCount[$role]));
                    if ($key === 'T9' && $role === '1st') {
                        $settle('1st');
                        $p = $c->publishWhenReady($sid, ['now_us' => $W::nowUs()]);
                        $probe['after_1st_only'] = ['publish' => (string) ($p['code'] ?? ''), 'both_failed' => $A::predictionBothRolesFailed($sid),
                            'records' => \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->count()];
                    }
                }
                foreach (['1st', '2nd'] as $role) { $settle($role); }
                $lastAt = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('status', $W::RECEIVED)->max('received_at');
                if ($lastAt !== null) { $W::setTestNow(max($W::nowUs(), (int) $W::dbUs((string) $lastAt) + 1000000)); }
                $pb = $c->publishWhenReady($sid, ['now_us' => $W::nowUs()]);
                $this->line('  公開: ' . (string) ($pb['code'] ?? '') . ' 候補 ' . count((array) ($pb['candidates'] ?? [])) . '頭 ' . mb_substr((string) ($pb['reason'] ?? ''), 0, 160));
                $use = [];
                foreach (['1st', '2nd'] as $role) {
                    $u = json_decode((string) \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'R122_USAGE')->where('related_id', $role . '#0')->value('diagnostics'), true);
                    $use[$role] = is_array($u) ? $u : [];
                    $rs = $use[$role]['resent'] ?? null;
                    $ins = $c->inspectAiResponse($sid, $role, 0);
                    $elig = count(array_filter((array) ($ins['normal'] ?? []), static fn($r) => (($r['eligibility'] ?? '') === 'ELIGIBLE')));
                    $this->line(sprintf('  %s 検査: %s ／ 候補になれる馬 %d頭 ／ 採否: %s', $role, (string) ($ins['code'] ?? ''), $elig,
                        json_encode(array_intersect_key($A::roleSettled($sid, $role), ['settled' => 1]), JSON_UNESCAPED_UNICODE)));
                    if (is_array($rs)) {
                        $this->line('    送り直し: 理由=' . (string) ($rs['why'] ?? '') . ' ／ 送った=' . (($rs['done'] ?? false) ? 'はい' : 'いいえ ' . (string) ($rs['skip'] ?? ''))
                            . ' ／ 2回目を使った=' . json_encode($rs['used'] ?? null)
                            . ' ／ 候補になれる馬 1回目=' . json_encode($rs['sends'][0]['eligible'] ?? null) . ' 2回目=' . json_encode($rs['sends'][1]['eligible'] ?? null)
                            . ' ／ 使わなかった返事の原文=' . (isset($rs['sends'][0]['raw_unused']) ? '1回目を保存' : (isset($rs['sends'][1]['raw_unused']) ? '2回目を保存' : 'なし')));
                        $u1 = $rs['sends'][0]['usage'] ?? null; $u2 = $rs['sends'][1]['usage'] ?? null; $ut = $use[$role]['usage'] ?? null;
                        $k1 = $role === '2nd' ? 'completion_tokens' : 'output_tokens';
                        $sumOk = is_array($u1) && is_array($u2) && is_array($ut) && (int) ($ut[$k1] ?? -1) === (int) ($u1[$k1] ?? 0) + (int) ($u2[$k1] ?? 0);
                        $this->line('    使用量: 1回目 ' . json_encode($u1[$k1] ?? null) . ' ＋ 2回目 ' . json_encode($u2[$k1] ?? null) . ' → 記録 ' . json_encode($ut[$k1] ?? null) . '（' . $k1 . '）');
                        $use[$role]['sum_ok'] = $sumOk;
                    }
                }
                $hold = array_values(array_filter([$use['1st']['budget_hold'] ?? null, $use['2nd']['budget_hold'] ?? null]));
                $settleRows = $hold === [] ? collect() : \DB::table('baganriki_audit_events')->where('kind', 'R122_BUDGET_SETTLE')->whereIn('related_id', $hold)->get(['related_id', 'diagnostics']);
                $this->line('  料金の精算: ' . $settleRows->map(static function ($e) { $dj = json_decode((string) $e->diagnostics, true); return (string) $e->related_id . '=' . json_encode($dj['amount_usd'] ?? null); })->implode(' ／ '));
                $rec = \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->get(['related_id', 'diagnostics']);
                $this->line('  出せなかった記録: ' . count($rec) . '件' . (count($rec) > 0 ? ' 種類=' . (string) $rec[0]->related_id : ''));
                // 判定
                if ($key === 'K11') {
                    $rs = $use['2nd']['resent'] ?? [];
                    $check('京都11R：2nd の1回目（2頭だけ）で送り直した', ($rs['done'] ?? false) === true && $sentCount['2nd'] === 2);
                    $check('京都11R：検査を通る馬が多い2回目を使った', ($rs['used'] ?? null) === true && (int) ($rs['sends'][1]['eligible'] ?? 0) > (int) ($rs['sends'][0]['eligible'] ?? 0),
                        '1回目 ' . json_encode($rs['sends'][0]['eligible'] ?? null) . '頭・2回目 ' . json_encode($rs['sends'][1]['eligible'] ?? null) . '頭');
                    $check('京都11R：公開した', in_array((string) ($pb['code'] ?? ''), ['PUBLISHED', 'ALREADY'], true) && count((array) ($pb['candidates'] ?? [])) > 0);
                    $check('京都11R：使用量は2回分を1回ずつ足した', ($use['2nd']['sum_ok'] ?? false) === true);
                    $check('京都11R：料金の精算が両AIぶんある', count($settleRows) === 2);
                    $check('京都11R：出せなかった記録は無い', count($rec) === 0);
                } elseif ($key === 'K6') {
                    $rs = $use['2nd']['resent'] ?? [];
                    $check('京都6R：2nd の1回目（根拠5頭）で送り直した', ($rs['done'] ?? false) === true && $sentCount['2nd'] === 2);
                    $check('京都6R：2回目は全頭書いたが検査で全頭不採用（0頭）', ($rs['sends'][1]['eligible'] ?? null) === 0 && (int) ($rs['sends'][1]['quality']['evidence_horses'] ?? 0) > (int) ($rs['sends'][0]['quality']['evidence_horses'] ?? 0),
                        '根拠を書いた馬 1回目 ' . json_encode($rs['sends'][0]['quality']['evidence_horses'] ?? null) . '頭・2回目 ' . json_encode($rs['sends'][1]['quality']['evidence_horses'] ?? null) . '頭');
                    $check('京都6R：検査を通る馬がいる1回目を残した（2回目は使わない）', ($rs['used'] ?? null) === false && (int) ($rs['sends'][0]['eligible'] ?? 0) > 0,
                        '1回目 ' . json_encode($rs['sends'][0]['eligible'] ?? null) . '頭');
                    $ins2 = $c->inspectAiResponse($sid, '2nd', 0);
                    $check('京都6R：保存・検査されたのは1回目の返事（2nd の候補になれる馬が1頭以上）', count(array_filter((array) ($ins2['normal'] ?? []), static fn($r) => (($r['eligibility'] ?? '') === 'ELIGIBLE'))) > 0);
                    $check('京都6R：公開した', in_array((string) ($pb['code'] ?? ''), ['PUBLISHED', 'ALREADY'], true) && count((array) ($pb['candidates'] ?? [])) > 0);
                    $check('京都6R：使用量は2回分を1回ずつ足した', ($use['2nd']['sum_ok'] ?? false) === true);
                } elseif ($key === 'T9') {
                    $this->line('  途中の判定: ' . json_encode($probe, JSON_UNESCAPED_UNICODE));
                    $check('東京9R：1st だけ終わった時点では「出せない」と決めない', ($probe['after_1st_only']['publish'] ?? '') === 'WAITING' && ($probe['after_1st_only']['both_failed'] ?? true) === false && ($probe['after_1st_only']['records'] ?? 1) === 0);
                    $check('東京9R：2nd の送り直しの最中も「出せない」と決めない', ($probe['during_2nd_resend']['publish'] ?? '') === 'WAITING' && ($probe['during_2nd_resend']['both_failed'] ?? true) === false && ($probe['during_2nd_resend']['records'] ?? 1) === 0);
                    $check('東京9R：両方とも送り直した（各2回）', $sentCount['1st'] === 2 && $sentCount['2nd'] === 2);
                    $check('東京9R：両方の評価がそろわず、公開しない（NO_RESPONSE）', (string) ($pb['code'] ?? '') === 'NO_RESPONSE');
                    $check('東京9R：画面を開く前に、出せなかった記録が1件ある（種類＝AIの評価を確認できない）', count($rec) === 1 && (string) $rec[0]->related_id === 'AI_EVALUATION_UNCONFIRMED');
                    $rd = json_decode((string) ($rec[0]->diagnostics ?? ''), true);
                    $this->line('  記録の中身: ' . mb_substr(json_encode($rd, JSON_UNESCAPED_UNICODE), 0, 700));
                    $check('東京9R：記録に AIごとの状態・検査の判定・送り直しが入っている', is_array($rd['roles']['1st'] ?? null) && array_key_exists('inspection_code', $rd['roles']['1st']) && is_array($rd['roles']['2nd']['resent'] ?? null));
                    $c->publishWhenReady($sid, ['now_us' => $W::nowUs()]);
                    $A::$answerTestGeneration = 900;
                    $s1 = $screen->invoke($c, $rid, $rr, $d, $k, $b, $dy, $rc);
                    $s2 = $screen->invoke($c, $rid, $rr, $d, $k, $b, $dy, $rc);
                    $A::$answerTestGeneration = null;
                    $txt = (string) json_encode($s1, JSON_UNESCAPED_UNICODE);
                    $this->line('  画面に返す中身: ' . mb_substr($txt, 0, 400));
                    $check('東京9R：画面の文面が「AIの評価を確認できなかったため、予想は出せませんでした」', strpos($txt, 'AIの評価を確認できなかったため、予想は出せませんでした') !== false);
                    $fillA = $answer->invoke($c, \DB::table('baganriki_snapshots')->where('id', $sid)->first(), null, [], $d, $k, $b, $dy, $rc);
                    $check('東京9R：馬で埋めない（埋める処理の結果が0頭）', (array) ($fillA['second']['merged_horses'] ?? ['x']) === [] && strpos($txt, '"merged_horses":[{') === false);
                    $check('東京9R：公開の判定2回・画面2回のあとも記録は1件だけ', \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->count() === 1 && json_encode($s1) === json_encode($s2));
                } else {
                    // $check('福島11R：両方とも1回で使える返事（送り直しなし）', $sentCount['1st'] === 1 && $sentCount['2nd'] === 1);   // 旧（試験の期待の誤り：今夜の 1st の返事は波乱度が null なので、1st は送り直しになる）
                    $a1 = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('role', '1st')->where('attempt_no', 0)->first(['status', 'received_at']);
                    $recUs = $a1 ? $W::dbUs((string) $a1->received_at) : null;
                    $this->line('  期限の確認: 応答期限 ' . $T::toJst((int) ($probe['reply_deadline_us'] ?? 0)) . ' ／ 1st の処理が終わった時刻 ' . $T::toJst((int) ($probe['clock_after_1st_us'] ?? 0))
                        . ' ／ 1st の受領時刻 ' . ($recUs === null ? 'なし' : $T::toJst((int) $recUs)) . ' ／ 1st 送信の結果 ' . (string) ($probe['fs_1st'] ?? ''));
                    $check('福島11R：1st の処理が終わった時点では応答期限を過ぎていた（送り直しの返事が期限後に届いた）', (int) ($probe['clock_after_1st_us'] ?? 0) > (int) ($probe['reply_deadline_us'] ?? PHP_INT_MAX));
                    $check('福島11R：それでも、期限内に届いた1回目（候補になれる馬あり）を、1回目が届いた時刻で受領した', $a1 !== null && (string) $a1->status === $W::RECEIVED && $recUs !== null && $recUs <= (int) ($probe['reply_deadline_us'] ?? 0) && (($use['1st']['resent']['used'] ?? null) === false));
                    // $check('福島11R：2nd は1回で使える返事（送り直しなし）', $sentCount['2nd'] === 1 && empty($use['2nd']['resent']));   // 旧（2nd は遅着の場面に変えました）
                    $a2 = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('role', '2nd')->where('attempt_no', 0)->first(['status', 'received_at', 'raw_response', 'response_hash']);
                    $rec2 = $a2 ? $W::dbUs((string) $a2->received_at) : null;
                    $rs2 = $use['2nd']['resent'] ?? [];
                    $ins2F = $c->inspectAiResponse($sid, '2nd', 0);
                    $el2F = count(array_filter((array) ($ins2F['normal'] ?? []), static fn($r) => (($r['eligibility'] ?? '') === 'ELIGIBLE')));
                    $this->line('  2nd の遅着の確認: 応答期限 ' . $T::toJst((int) ($probe['reply_deadline_us_2nd'] ?? 0)) . ' ／ 2nd の処理が終わった時刻 ' . $T::toJst((int) ($probe['clock_after_2nd_us'] ?? 0))
                        . ' ／ 2nd の受領時刻 ' . ($rec2 === null ? 'なし' : $T::toJst((int) $rec2)) . ' ／ 候補になれる馬 1回目 ' . json_encode($rs2['sends'][0]['eligible'] ?? null) . '・2回目 ' . json_encode($rs2['sends'][1]['eligible'] ?? null)
                        . ' ／ 2回目の遅着 ' . json_encode($rs2['sends'][1]['late'] ?? null) . ' ／ 2nd 送信の結果 ' . (string) ($probe['fs_2nd'] ?? ''));
                    $check('福島11R：2nd の送り直し（全頭・中身は良い）は応答期限の後に届いた', $sentCount['2nd'] === 2 && ($rs2['sends'][1]['late'] ?? null) === true
                        && (int) ($rs2['sends'][1]['eligible'] ?? 0) > (int) ($rs2['sends'][0]['eligible'] ?? 0) && (int) ($probe['clock_after_2nd_us'] ?? 0) > (int) ($probe['reply_deadline_us_2nd'] ?? PHP_INT_MAX));
                    $check('福島11R：遅着の2回目は使わず、期限内の1回目を保った（原文・受領時刻・候補になれる馬）', ($rs2['used'] ?? null) === false && $a2 !== null && (string) $a2->status === $W::RECEIVED
                        && $rec2 !== null && $rec2 <= (int) ($probe['reply_deadline_us_2nd'] ?? 0)
                        && hash_equals((string) $a2->response_hash, hash('sha256', $r2first)) && $el2F === (int) ($rs2['sends'][0]['eligible'] ?? -1) && $el2F > 0,
                        '保存した原文＝1回目・候補になれる馬 ' . $el2F . '頭');
                    $check('福島11R：1st は波乱度なしで送り直し、2回目が読めないので1回目（候補になれる馬あり）を使った', $sentCount['1st'] === 2 && (($use['1st']['resent']['used'] ?? null) === false) && (int) ($use['1st']['resent']['sends'][0]['eligible'] ?? 0) > 0);
                    $check('福島11R：公開した', in_array((string) ($pb['code'] ?? ''), ['PUBLISHED', 'ALREADY'], true) && count((array) ($pb['candidates'] ?? [])) > 0);
                    $check('福島11R：出せなかった記録は無い', count($rec) === 0);
                    // 「評価は終わったが候補0頭」の記録の分け方（記録する関数に、候補0頭の公開の結果を渡して確かめ、DBを元に戻します）
                    \DB::beginTransaction();
                    try {
                        \DB::table('baganriki_publications')->where('snapshot_id', $sid)->count();
                        $record->invoke(null, $sid, ['ok' => true, 'code' => 'PUBLISHED', 'candidates' => [], 'reason' => '試験：候補0頭の公開']);
                        $z = \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->get(['related_id']);
                        $record->invoke(null, $sid, ['ok' => false, 'code' => 'DEADLINE_PASSED', 'reason' => '試験：公開済みのあとの期限切れ']);
                        $z2 = \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->count();
                    } finally {
                        \DB::rollBack();
                    }
                    $check('福島11R：候補0頭の公開は「評価は終わったが候補0頭」として記録する（DBは元に戻した）', count($z) === 1 && (string) $z[0]->related_id === 'EVALUATED_NO_ELIGIBLE');
                    // 候補0頭で公開したときの画面（公開の候補を0頭に書き換えて画面の中身を作り、DBを元に戻します）
                    $txt0 = ''; $txtN = '';
                    $A::$answerTestGeneration = 900;
                    try {
                        $txtN = (string) json_encode($screen->invoke($c, $rid, $rr, $d, $k, $b, $dy, $rc), JSON_UNESCAPED_UNICODE);
                        \DB::beginTransaction();
                        try {
                            \DB::table('baganriki_publications')->where('snapshot_id', $sid)->update(['candidates' => '[]']);
                            $txt0 = (string) json_encode($screen->invoke($c, $rid, $rr, $d, $k, $b, $dy, $rc), JSON_UNESCAPED_UNICODE);
                        } finally {
                            \DB::rollBack();
                        }
                    } finally {
                        $A::$answerTestGeneration = null;
                    }
                    $this->line('  候補0頭の公開の画面: ' . mb_substr($txt0, 0, 300));
                    $check('福島11R：候補0頭で公開したときは「AIの評価で根拠を確かめられた馬がいなかったため、予想は出せませんでした」', strpos($txt0, 'AIの評価で根拠を確かめられた馬がいなかったため、予想は出せませんでした') !== false && strpos($txt0, '"merged_horses":[{') === false);
                    $check('福島11R：ふつうの公開では、その文面は出ず、候補の馬が出る', strpos($txtN, '根拠を確かめられた馬がいなかった') === false && strpos($txtN, '"merged_horses":[{') !== false);
                    $check('福島11R：記録は1件だけ（そのあと期限切れを渡しても増えない）', $z2 === 1);
                    // @ANCHOR-20261110-PREDICTION-NOTPUBLISHED-LOCK  よっしー11/10夜2 ②：2つの接続から同時に記録しても1件になること
                    //   A（この処理）がロックの中で「まだ無い」と確かめた直後に、B（別プロセス・別の接続）に同じ記録をさせます。
                    //   B がロック待ち（LOCK WAIT）になったのを接続 C で確かめてから A を確定させ、B の終了後に件数を数えます。最後に、この試験で入れた記録を id で消します。
                    $cidFile = sys_get_temp_dir() . '/baganriki_np_' . getmypid() . '_' . $sid . '.txt';
                    @unlink($cidFile);
                    $codeB = '\\DB::statement("SET SESSION innodb_lock_wait_timeout = 30");'
                           . 'file_put_contents(' . var_export($cidFile, true) . ', (string) \\DB::selectOne("SELECT CONNECTION_ID() c")->c);'
                           . '$m = new \\ReflectionMethod(\\App\\Http\\Controllers\\Api\\AiController::class, "recordNotPublished"); $m->setAccessible(true);'
                           . '$t0 = microtime(true); $m->invoke(null, ' . $sid . ', ["ok" => true, "code" => "PUBLISHED", "candidates" => [], "reason" => "試験B：同時の記録"]);'
                           . 'echo "BRESULT" . json_encode(["sec" => round(microtime(true) - $t0, 2)]) . PHP_EOL;';
                    $cmdB = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(base_path('artisan')) . ' tinker --execute=' . escapeshellarg($codeB);
                    $def = (string) config('database.default');
                    config(['database.connections.baganriki_np_c' => config('database.connections.' . $def)]);
                    $C = \DB::connection('baganriki_np_c');
                    $procB = null; $pipesB = []; $bCid = null; $waitSeen = false;
                    $A::$testAfterNotPublishedCheck = function () use (&$procB, &$pipesB, $cmdB, $cidFile, &$bCid, &$waitSeen, $C) {
                        $procB = proc_open($cmdB, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB, base_path());
                        for ($i = 0; $i < 300 && $bCid === null; $i++) {
                            if (is_file($cidFile) && trim((string) file_get_contents($cidFile)) !== '') { $bCid = (int) trim((string) file_get_contents($cidFile)); break; }
                            usleep(100000);
                        }
                        if ($bCid !== null) {
                            for ($i = 0; $i < 200; $i++) {
                                $r = $C->selectOne("SELECT trx_mysql_thread_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$bCid]);
                                if ($r !== null) { $waitSeen = true; break; }
                                usleep(100000);
                            }
                        }
                        usleep(500000);
                    };
                    try {
                        $record->invoke(null, $sid, ['ok' => true, 'code' => 'PUBLISHED', 'candidates' => [], 'reason' => '試験A：同時の記録']);
                    } finally {
                        $A::$testAfterNotPublishedCheck = null;
                    }
                    $bOut = '';
                    if (is_resource($procB)) { $bOut = (string) stream_get_contents($pipesB[1]); stream_get_contents($pipesB[2]); fclose($pipesB[1]); fclose($pipesB[2]); proc_close($procB); }
                    $bRes = preg_match('/BRESULT(\{.*\})/u', $bOut, $mm) ? json_decode($mm[1], true) : null;
                    $npRows = \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->get(['id', 'diagnostics']);
                    $this->line('  同時の記録: B の接続ID ' . json_encode($bCid) . ' ／ B のロック待ちを確認 ' . json_encode($waitSeen) . ' ／ B の所要 ' . json_encode($bRes['sec'] ?? null) . '秒 ／ 記録 ' . count($npRows) . '件');
                    $check('福島11R：2つの接続から同時に記録しても1件（B はロック待ちのあと追加しなかった）', $bCid !== null && $waitSeen && is_array($bRes) && count($npRows) === 1
                        && strpos((string) $npRows[0]->diagnostics, '試験A') !== false);
                    foreach ($npRows as $nr) { \DB::table('baganriki_audit_events')->where('id', $nr->id)->delete(); }   // この試験で入れた記録だけを消します
                    @unlink($cidFile);
                }
                $W::setTestNow(null);
            }
        } catch (\Throwable $e) {
            $fail++;
            $this->error('例外: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        } finally {
            $W::setTestNow(null);
            $A::$answerTestGeneration = null;
            ($NS . 'BaganrikiSendConfig')::$promptFileTag = null;
        }
        $this->line('');
        $this->info('==== 判定 ' . ($pass + $fail) . '件／合格 ' . $pass . '／不合格 ' . $fail . '（AIへは送っていません。記録は世代900としてDBに残しています。お客様の画面には出ません）');
    }

    private function runPredictionExpectedPrivate(): void
    {
        // 1回目（18:05）で使った東京6R・京都8Rは、1stの枠を使い切ったので、2回目は別の2レースにします
        // 18:16 の2回目（東京7R・京都9R）は、1stのモデル設定の読み違いで止まったので、3回目はさらに別の2レース
        // 19:10 の3回目（東京8R・京都10R）は、断層の材料（隣接2頭の比）が入力に無かったので、材料を足して4回目はさらに別の2レース
        // 19:20 の4回目（東京9R・京都11R）は、過去走が補足（CONTEXT）扱いで能力の根拠が通らなかったので、直して5回目はさらに別の2レース
        // $races = [['2026-11-08', '5', '05', '2', 10], ['2026-11-08', '5', '08', '2', 12]];
        // 19:40：根拠の一部だけ不正な馬を組み直す処理を足したので、これまでの3回分（すべて送信済み＝AIには送らない・0円）を判定し直します
        // 19:45：切り替え前の最終確認。今日の修正を全部入れた状態で、まだ使っていない2レース（東京11R・東京12R）を本番と同じ流れで通します
        // $races = [['2026-11-08', '5', '05', '2', 11], ['2026-11-08', '5', '05', '2', 12]];
        // 20:05：レース指標（1stの race_metrics）の書き方の補足を足したので、まだ使っていない2レース（京都7R・東京5R）で確かめます
        // $races = [['2026-11-08', '5', '08', '2', 7], ['2026-11-08', '5', '05', '2', 5]];
        // 20:15：京都7R・東京5R は送信済み（判定し直すだけ・0円）、京都6R・東京4R は新しく送る（目印の読み方・指標の参照・全頭を書く指示を直した後の確認）
        // $races = [['2026-11-08', '5', '08', '2', 7], ['2026-11-08', '5', '05', '2', 5], ['2026-11-08', '5', '08', '2', 6], ['2026-11-08', '5', '05', '2', 4]];
        // 20:30：指標を整えた後の返事から読むよう直したので京都7R（送信済み・0円）を判定し直し、人気順・過去走なしの補足を足した後の確認で東京3R・京都5Rを新しく送る
        // $races = [['2026-11-08', '5', '08', '2', 7], ['2026-11-08', '5', '05', '2', 3], ['2026-11-08', '5', '08', '2', 5]];
        // 11/10 10:30：2nd の書き損じ（余計な "）を取り除いて読み直す処理と、1st が途中でやめたときの送り直しを足したので、
        //   京都7R・東京3R・京都5R（送信済み・0円）を判定し直し、まだ使っていない2レース（京都3R・東京2R）を新しく送る
        // $races = [['2026-11-08', '5', '08', '2', 7], ['2026-11-08', '5', '05', '2', 3], ['2026-11-08', '5', '08', '2', 5],
        //           ['2026-11-08', '5', '08', '2', 3], ['2026-11-08', '5', '05', '2', 2]];
        // 11/10 12時：よっしー11/10「実際の1レース分の送信全文・両AIの回答・補正前後と検査結果」用に、まだ使っていない京都4R（14頭）を1レースだけ新しく送る
        // $races = [['2026-11-08', '5', '08', '2', 4]];
        // 11/10 12時半：1st が途中でやめたレース（京都7R・京都5R。世代900は消し済み）と、まだ使っていない3レース（京都2R・京都1R・東京1R）で、
        //   1st への「書ききれる量・短く書く」と、読めない返事の送り直しを確かめる
        // $races = [['2026-11-08', '5', '08', '2', 7], ['2026-11-08', '5', '08', '2', 5], ['2026-11-08', '5', '08', '2', 2],
        //           ['2026-11-08', '5', '08', '2', 1], ['2026-11-08', '5', '05', '2', 1]];
        // 11/10 13時：組み直しの抜けと、1st の余計な } を直したあとの、端から端までの確認（世代900を消してある3レース：東京6R・京都6R・京都8R）
        // $races = [['2026-11-08', '5', '05', '2', 6], ['2026-11-08', '5', '08', '2', 6], ['2026-11-08', '5', '08', '2', 8]];
        // 11/10 夕方：なる11/10 の指摘を直した最終版で、送信から公開まで通す（まだ使っていない6レース。世代900は消してから）
        // $races = [['2026-11-08', '5', '05', '2', 9], ['2026-11-08', '5', '08', '2', 9], ['2026-11-08', '3', '03', '2', 10],
        //           ['2026-11-08', '5', '05', '2', 11], ['2026-11-08', '5', '08', '2', 11], ['2026-11-08', '3', '03', '2', 11]];
        // 11/10 夜：2nd の送り直しと、予想を出せなかった記録の確認（世代900は消してある。午後に 2nd が使えなかった東京9R・福島11R を含む4レース）
        // $races = [['2026-11-08', '5', '05', '2', 9], ['2026-11-08', '3', '03', '2', 11], ['2026-11-08', '5', '08', '2', 11], ['2026-11-08', '5', '08', '2', 6]];
        // 11/25：E5専用・8項目の版（よっしー 11/25 R5）を本番へ入れる前の確認。11/8 のオッズは土曜の整理で消えているので、11/23 の2レース（福島12R 16頭・東京11R 12頭）
        // $races = [['2026-11-23', '3', '03', '6', 12], ['2026-11-23', '5', '05', '6', 11]];   // 11/25 朝の試験（世代900が固定済み。PDFから取り出した本文・E3付きの行）
        // 11/25：なるの元ファイル（ZIP）そのものにした後の確認。まだ世代900を作っていない 11/23 の2レース（東京12R 18頭・東京7R 16頭）
        $races = [['2026-11-23', '5', '05', '6', 12], ['2026-11-23', '5', '05', '6', 7]];
        foreach (['BAGANRIKI_PREDICTION_EXPECTED' => '1'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        $NS = '\\App\\Http\\Controllers\\Api\\';
        $W = $NS . 'BaganrikiWorker'; $T = $NS . 'BaganrikiTime'; $A = $NS . 'AiController';
        $c = app($A);
        $answer = new \ReflectionMethod($A, 'predictionPublishedAnswer'); $answer->setAccessible(true);
        ($NS . 'BaganrikiSendConfig')::$promptFileTag = 'private';
        try {
            foreach ($races as [$d, $k, $b, $dy, $rc]) {
                $rr = \DB::table('t_horse_odds_finder_races')->where('date', $d)->where('kaisuu', $k)->where('basho', $b)
                        ->where('day', $dy)->where('race', $rc)->first();
                $st = $T::normalizeRaceStartClock((string) ($rr->start_time ?? ''));
                $anchorUs = (int) $T::us($d . 'T' . $st . '+09:00');
                $W::setTestNow($anchorUs + $T::INPUT_FREEZE_OFFSET_SEC * 1000000 + 3000000);
                // 料金の歯止め：この試験で新しく使えるのは、そのときの記録＋3ドルまで（この処理の中だけ。.env は変えない）
                $cap = number_format($A::spentTodayUsd($W::nowUs()) + 3.0, 4, '.', '');
                foreach (['BAGANRIKI_PREDICTION_BUDGET_USD_DAY' => $cap] as $kk => $vv) { putenv("{$kk}={$vv}"); $_ENV[$kk] = $vv; $_SERVER[$kk] = $vv; }
                $this->line('  （この試験の料金の上限：記録上 ' . $cap . 'ドルまで）');
                $t0 = microtime(true);
                $f = $c->freezePredictionInput($d, $k, $b, $dy, $rc, [], 'REPLAY');
                $this->line('');
                $this->line("==== {$f['race_id']} 凍結: " . ($f['ok'] ? 'OK' : 'NG') . ' ' . ($f['code'] ?? '') . ' ' . ($f['reason'] ?? '')
                            . (($f['skipped'] ?? false) ? '（前に作った世代900を使います）' : ''));
                if (!$f['ok']) { continue; }
                $snap = \DB::table('baganriki_snapshots')->where('race_id', $f['race_id'])->where('generation', 900)->first();
                $sid = (int) $snap->id;
                $freezeClock = $anchorUs + $T::INPUT_FREEZE_OFFSET_SEC * 1000000 + 3000000;
                foreach (['1st', '2nd'] as $role) {
                    // 本番は 1st と 2nd を同時に送るので、試験でも2nd を送る前に時計を凍結直後へ戻します
                    $W::setTestNow($freezeClock);
                    $s0 = microtime(true);
                    $fs = $c->sendInitialAiRequest($sid, $role);
                    $this->line(sprintf('  %s 初回: %s %s（%.0f秒）', $role, (string) ($fs['code'] ?? ''), (string) ($fs['reason'] ?? ''), microtime(true) - $s0));
                }
                foreach (['1st', '2nd'] as $role) {
                    if (!$A::roleSettled($sid, $role)['settled']) {
                        $s0 = microtime(true);
                        $rv = $c->resumeReceivedRole($sid, $role);
                        $this->line(sprintf('  %s 検査・訂正: %s %s（%.0f秒）', $role, (string) ($rv['code'] ?? ''), (string) ($rv['reason'] ?? ''), microtime(true) - $s0));
                    }
                    $at = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('role', $role)->orderBy('attempt_no')->get();
                    foreach (\DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'R122_USAGE')->where('related_id', $role . '#0')->get(['diagnostics']) as $ue) {
                        $ud = json_decode((string) $ue->diagnostics, true);
                        if (!empty($ud['resent'])) {
                            $rs = $ud['resent']; foreach ((array) ($rs['sends'] ?? []) as $ri => $sd) { unset($rs['sends'][$ri]['raw_unused'], $rs['sends'][$ri]['usage']); }
                            $this->line('    送り直し: ' . json_encode($rs, JSON_UNESCAPED_UNICODE));
                        }
                    }
                    foreach ($at as $x) {
                        $sent = json_decode((string) ($x->sent_request ?? ''), true);
                        $this->line(sprintf('    %s 回%d: %s 形式=%s 送信=%sバイト 受信=%sバイト', $role, (int) $x->attempt_no, (string) $x->status,
                            (string) ($sent['format'] ?? '-'), is_array($sent) ? strlen((string) ($sent['user_json'] ?? '')) : '-', strlen((string) ($x->raw_response ?? ''))));
                    }
                }
                // 2回目以降（送信済みで送らなかったとき）でも同じ条件で公開を判定できるよう、時計を「最後の返事が届いた1秒後」に合わせます（AIには送りません）
                $lastAt = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('status', $W::RECEIVED)->max('received_at');
                if ($lastAt !== null) { $W::setTestNow(max($W::nowUs(), (int) $W::dbUs((string) $lastAt) + 1000000)); }
                $pb = $c->publishWhenReady($sid, ['now_us' => $W::nowUs()]);
                $this->line('  公開: ' . (($pb['ok'] ?? false) ? 'OK' : 'NG') . ' ' . (string) ($pb['code'] ?? '') . ' ' . (string) ($pb['reason'] ?? ''));
                // 返事の検査の中身（馬ごとの指摘・整えた内容）を短く出します。料金はかかりません
                $insBy = [];
                foreach (['1st', '2nd'] as $role) {
                    ($NS . 'BaganrikiResponseInspection')::$salvageWhy = [];
                    $ins = $c->inspectAiResponse($sid, $role, 0);
                    $insBy[$role] = $ins;
                    $per = [];
                    foreach ((array) ($ins['diag']['findings'] ?? []) as $fd) {
                        $per[(string) ($fd['target'] ?? '?')][(string) ($fd['code'] ?? '')] = (string) ($fd['scope'] ?? '');
                    }
                    $elig = count(array_filter((array) ($ins['normal'] ?? []), static fn($r) => (($r['eligibility'] ?? '') === 'ELIGIBLE')));
                    $this->line(sprintf('  検査 %s: %s 整え=%d件 使える馬=%d頭（うち候補になれる馬=%d頭、根拠を組み直した馬=%d頭）', $role, (string) ($ins['code'] ?? ''),
                        count((array) ($ins['tidied'] ?? [])), count((array) ($ins['normal'] ?? [])), $elig,
                        count(array_filter((array) ($ins['salvage_detail'] ?? []), static fn($x) => !empty($x['rebuilt'])))));
                    foreach (array_slice((array) ($ins['tidied'] ?? []), 0, 3) as $tn) { $this->line('    整え: ' . $tn); }
                    foreach (array_slice(($NS . 'BaganrikiResponseInspection')::$salvageWhy, 0, 4, true) as $wn => $wy) { $this->line('    組み直せない ' . $wn . '番: ' . mb_substr((string) $wy, 0, 400)); }
                    foreach (array_slice($per, 0, 3, true) as $tg => $codes) {
                        $this->line('    指摘 ' . $tg . ': ' . implode(',', array_map(static fn($k, $v) => $k . '(' . $v . ')', array_keys($codes), $codes)));
                    }
                }
                $pub = \DB::table('baganriki_publications')->where('snapshot_id', $sid)->orderByDesc('id')->first();
                $ans = $answer->invoke($c, \DB::table('baganriki_snapshots')->where('id', $sid)->first(), $pub, [], $d, $k, $b, $dy, $rc);
                $first = $ans['first'] ?? [];
                $this->line('  画面用: 状態=' . (string) ($ans['state'] ?? '') . ' 指標=' . json_encode($first['race_metrics'] ?? null, JSON_UNESCAPED_UNICODE));
                $this->line('  画面用の先頭: ' . mb_substr(str_replace("\n", ' / ', (string) ($first['analysis_text'] ?? '')), 0, 200));
                // @ANCHOR-20261110-PREDICTION-EXPECTED-SUMMARY  段階ごとの数（なる11/10）
                $sm = $this->predictionExpectedRaceSummary(\DB::table('baganriki_snapshots')->where('id', $sid)->first(), $insBy);
                foreach (['1st', '2nd'] as $role) {
                    $rr = $sm['roles'][$role];
                    $this->line(sprintf('  段階 %s: 返事の行 %d／根拠を書いた馬 %d／検査を通った %d／選出資格 %d／順位の採用元 %s／E判定のずれ %d帯', $role,
                        $rr['rows_returned'], $rr['rows_with_evidence'], $rr['rows_passed_inspection'], $rr['eligible'],
                        json_encode($rr['rank_source'], JSON_UNESCAPED_UNICODE), count(array_filter($rr['expected_reviews'], static fn($x) => $x['mismatch']))));
                }
                $np = \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'PREDICTION_NOT_PUBLISHED')->value('diagnostics');
                if ($np !== null) { $this->line('  予想を出せなかった記録: ' . mb_substr((string) $np, 0, 600)); }
                $this->line('  段階 公開: 最終候補 ' . count($sm['final_candidates']) . '頭 ／ 指標 '
                    . implode(' ', array_map(static fn($k, $v) => $k . '=' . ($v['published'] ?? 'null') . ($v['why_null'] !== 'OK' ? '(' . $v['why_null'] . ')' : ''), array_keys($sm['race_metrics']), $sm['race_metrics'])));
                $cost = 0.0;
                foreach (\DB::table('baganriki_audit_events')->where('snapshot_id', $sid)->where('kind', 'like', '%BUDGET_SETTLE%')->get(['diagnostics']) as $e) {
                    $dj = json_decode((string) $e->diagnostics, true); $cost += (float) ($dj['amount_usd'] ?? $dj['actual_usd'] ?? 0);
                }
                $this->line(sprintf('  かかった時間: %.0f秒 ／ 料金の記録: %.4fドル', microtime(true) - $t0, $cost));
                $W::setTestNow(null);
            }
        } catch (\Throwable $e) {
            $this->error('例外: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        } finally {
            $W::setTestNow(null);
            ($NS . 'BaganrikiSendConfig')::$promptFileTag = null;
        }
        $this->line('');
        $this->info('==== 終わりました（記録は世代900としてDBに残しています。お客様の画面には出ません）');
    }
}

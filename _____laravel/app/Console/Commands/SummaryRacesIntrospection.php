<?php

namespace App\Console\Commands;

use App\Services\AnthropicService;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryRacesIntrospection
 *
 * 【概要】
 *   過去のレースデータ（単勝オッズ推移・実際の着順）を Claude API に送信し、
 *   予想と反省（振り返り）を生成して t_horse_odds_finder_race_introspection に保存する。
 *   処理済みレースはスキップ。BATCH_SIZE 件ずつ並列送信し、TPM 制限を回避する。
 *   429/529 エラーは指数バックオフで最大3回リトライする。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】着順・ピックアップ馬の先読み
 *   【ブロック 3】対象レース取得（オッズデータが揃っているもの）
 *   【フェーズ 1】プロンプト収集（処理済みレースはスキップ）
 *   【フェーズ 2】BATCH_SIZE 件ずつ Http::pool() で並列送信
 *   【フェーズ 3】レスポンス処理・DB 保存
 *   【フェーズ 4】429/529 リトライ（指数バックオフ・逐次）
 *   【ブロック 9】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:SummaryRacesIntrospection
 */
class SummaryRacesIntrospection extends Command
{
    protected $signature   = 'keiba:SummaryRacesIntrospection';
    protected $description = 'claude apiを使って、レースの予想と反省をする';

    // 450件を一度に送ると TPM 制限に引っかかるため小さめに設定
    private const BATCH_SIZE      = 5;
    private const BATCH_SLEEP_SEC = 8;

    public function __construct(private AnthropicService $anthropic)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        // 多重起動防止
        $lockFile = sys_get_temp_dir() . '/keiba_summaryRacesIntrospection.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            if ($pid > 0 && posix_kill($pid, 0)) {
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return;
            }
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $startedAt    = microtime(true);
        $totalRaces   = 0;
        $totalSkipped = 0;
        $totalSuccess = 0;
        $totalFailed  = 0;
        $status       = '不明な理由で終了';

        try {

            $this->info('');
            $this->info('========== keiba:SummaryRacesIntrospection 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('バッチサイズ : ' . self::BATCH_SIZE . ' レース');
            $this->info('バッチ間隔   : ' . self::BATCH_SLEEP_SEC . ' 秒');
            $this->info('');

            // ─── 馬眼力の脳みそ（判断基準）の読み込み ──────────────────────────────
            $brainFile = public_path('baganriki_brain/baganriki_brain.txt');
            $brain     = file_exists($brainFile) ? trim(file_get_contents($brainFile)) : '';
            $this->info('  → 馬眼力ブレイン: ' . ($brain !== '' ? '読み込み済み' : 'ファイルなし'));
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // 1〜3着の馬番を先読み（date_kaisuu_basho_code_day_race_着順 → 馬番）
            // ─────────────────────────────────────────────────────────────────
            $raceResult = [];
            $finishingRows = DB::table('t_horse_odds_finder_race_result_history')
                ->whereIn('finishing_position', [1, 2, 3])
                ->orderBy('date')
                ->orderBy('kaisuu')
                ->orderBy('basho_code')
                ->orderBy('day')
                ->orderBy('race')
                ->orderBy('finishing_position')
                ->get();

            foreach ($finishingRows as $finishingRow) {
                $raceResult["{$finishingRow->date}_{$finishingRow->kaisuu}_{$finishingRow->basho_code}_{$finishingRow->day}_{$finishingRow->race}_{$finishingRow->finishing_position}"] = $finishingRow->num;
            }

            $this->info('  → 着順先読み: ' . count($raceResult) . ' 件');
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // AI分析からピックアップ馬を先読み（date_kaisuu_basho_code_day_race → 馬番[]）
            // ─────────────────────────────────────────────────────────────────
            $pickupHorses = [];
            $aiAnalysisRows = DB::table('t_horse_odds_finder_ai_analysis')
                ->orderBy('date')
                ->orderBy('kaisuu')
                ->orderBy('basho_code')
                ->orderBy('day')
                ->orderBy('race')
                ->get();

            foreach ($aiAnalysisRows as $aiAnalysisRow) {
                if (preg_match('/^PICKUP:(.+)$/mu', $aiAnalysisRow->analysis_text, $m)) {
                    $parts = explode('/', trim($m[1]));
                    foreach ($parts as $part) {
                        $cols = explode('|', $part);
                        $pickupHorses["{$aiAnalysisRow->date}_{$aiAnalysisRow->kaisuu}_{$aiAnalysisRow->basho_code}_{$aiAnalysisRow->day}_{$aiAnalysisRow->race}"][] = isset($cols[0]) ? trim($cols[0]) : '';
                    }
                }
            }

            $this->info('  → ピックアップ馬先読み: ' . count($pickupHorses) . ' レース');
            $this->info('');


            // ─────────────────────────────────────────────────────────────────
            // 対象レース取得（odds_tan_before_24 / odds_tan_before_6 が揃っているもの）
            // ─────────────────────────────────────────────────────────────────
            $sql = "
SELECT DISTINCT date, kaisuu, basho, basho_name, day, race, race_name
FROM t_horse_odds_finder_summary
WHERE (date, kaisuu, basho, day, race) NOT IN (
    SELECT DISTINCT date, kaisuu, basho, day, race
    FROM t_horse_odds_finder_summary
    WHERE odds_tan_before_24 IS NULL OR odds_tan_before_24 = ''
    OR odds_tan_before_6  IS NULL OR odds_tan_before_6  = ''
)
ORDER BY date, kaisuu, basho, day, race;
";
            $targetRaces = DB::select($sql);
            $totalRaces  = count($targetRaces);
            $this->info("  → 対象レース: {$totalRaces} 件");
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // プロンプト収集（処理済みレースはスキップ）
            // ─────────────────────────────────────────────────────────────────
            $this->info('[フェーズ1] プロンプト収集中...');

            // ★ 結果はオッズ情報と分離し、独立したセクションとして渡す（混同防止）
            $raceInfoTemplate = "日付:DATE___回数:KAISUU___場所名:BASHO_NAME___レース:RACE___レース名:RACE_NAME___オッズ情報:ODDS_INFO";
            $oddsTemplate     = "馬番:NUM___馬名:NAME___レース開始X分前オッズ:(30)ODDS30|(21)ODDS21|(18)ODDS18|(15)ODDS15|(12)ODDS12|(9)ODDS9|(6)ODDS6";

            $pending = [];

            foreach ($targetRaces as $race) {

                $exists = DB::table('t_horse_odds_finder_race_introspection')
                    ->where('date',       $race->date)
                    ->where('kaisuu',     $race->kaisuu)
                    ->where('basho_code', $race->basho)
                    ->where('day',        $race->day)
                    ->where('race',       $race->race)
                    ->exists();

                if ($exists) {
                    $this->line("  [スキップ] {$race->date} {$race->basho_name} {$race->kaisuu}回{$race->day}日目 {$race->race}R（処理済み）");
                    $totalSkipped++;
                    continue;
                }

                $oddsRows = DB::table('t_horse_odds_finder_summary')
                    ->where('date',   $race->date)
                    ->where('kaisuu', $race->kaisuu)
                    ->where('basho',  $race->basho)
                    ->where('day',    $race->day)
                    ->where('race',   $race->race)
                    ->get();

                $oddsStrings = [];
                foreach ($oddsRows as $oddsRow) {
                    $oddsStrings[] = strtr($oddsTemplate, [
                        'NUM'    => $oddsRow->num,
                        'NAME'   => $oddsRow->horse_name,
                        'ODDS30' => $oddsRow->odds_tan_before_24,
                        'ODDS21' => $oddsRow->odds_tan_before_21,
                        'ODDS18' => $oddsRow->odds_tan_before_18,
                        'ODDS15' => $oddsRow->odds_tan_before_15,
                        'ODDS12' => $oddsRow->odds_tan_before_12,
                        'ODDS9'  => $oddsRow->odds_tan_before_9,
                        'ODDS6'  => $oddsRow->odds_tan_before_6,
                    ]);
                }

                // オッズ情報のみ（結果は含めない）
                $oddsInfoStr = strtr($raceInfoTemplate, [
                    'DATE'       => $race->date,
                    'KAISUU'     => $race->kaisuu,
                    'BASHO_NAME' => $race->basho_name,
                    'RACE'       => $race->race,
                    'RACE_NAME'  => $race->race_name,
                    'ODDS_INFO'  => implode('/', $oddsStrings),
                ]);

                // 実際の着順（独立変数として管理）
                $actualResult1 = $raceResult["{$race->date}_{$race->kaisuu}_{$race->basho}_{$race->day}_{$race->race}_1"] ?? '?';
                $actualResult2 = $raceResult["{$race->date}_{$race->kaisuu}_{$race->basho}_{$race->day}_{$race->race}_2"] ?? '?';
                $actualResult3 = $raceResult["{$race->date}_{$race->kaisuu}_{$race->basho}_{$race->day}_{$race->race}_3"] ?? '?';

                // 着順データが1つでも欠けている場合はスキップ
                // （バリデーション・自動補正の根拠がなく、不正データが入るリスクがある）
                if ($actualResult1 === '?' || $actualResult2 === '?' || $actualResult3 === '?') {
                    Log::warning('SummaryRacesIntrospection: 着順データ不足のためスキップ', [
                        'label' => "{$race->basho_name} {$race->race}R",
                        'r1'    => $actualResult1,
                        'r2'    => $actualResult2,
                        'r3'    => $actualResult3,
                    ]);
                    $this->warn("  [スキップ] {$race->date} {$race->basho_name} {$race->kaisuu}回{$race->day}日目 {$race->race}R — 着順データ不足（r1={$actualResult1}, r2={$actualResult2}, r3={$actualResult3}）");
                    $totalSkipped++;
                    continue;
                }

                // ─────────────────────────────────────────────────────────────────
                // プロンプト組み立て（ピックアップ馬がある場合は予想を差し替え）
                // ─────────────────────────────────────────────────────────────────
                $raceKey = "{$race->date}_{$race->kaisuu}_{$race->basho}_{$race->day}_{$race->race}";

                $hasPickup = isset($pickupHorses[$raceKey]);

                $msgs = [
                    "あなたは競馬のオッズ分析の専門家です。",
                    "",
                    "以下のレースについて、オッズ推移の分析と振り返りを行ってください。",
                    "",
                    "【このタスクの目的】",
                    "予測を当てることではなく、「なぜ間違えたのか」を深く考えることが目的です。",
                    "この分析は、今後のAI競馬予測の精度向上のための「思考の是正」として活用されます。",
                    "予測が当たった場合も、たまたまか根拠があったかを区別してください。",
                    "",
                    "【手順】",
                    "1. 【単勝オッズ推移データ】のみを見て、1〜3着を予測してください。",
                    "2. 【実際のレース着順】を確認し、予測と照合してください。",
                    "3. 外れた馬については、なぜオッズ推移から正しく予測できなかったかを詳しく分析してください。",
                    "",
                    "【出力フォーマット（厳守）】",
                    "このフォーマットは画面表示アプリがそのままパースします。",
                    "見出し（## 予想 / ## 結果 / ## 分析）は一切変更しないでください。",
                    "前置き・後書き・補足コメントは不要です。フォーマット通りに出力してください。",
                    "",
                    "─────────────────────────────",
                    "## 予想",
                    "1着: ○番 馬名",
                    "2着: ○番 馬名",
                    "3着: ○番 馬名",
                    "",
                    "## 結果",
                    "1着: ○番（的中/不的中）",
                    "2着: ○番（的中/不的中）",
                    "3着: ○番（不的中）",
                    "",
                    "## 分析",
                    "分析テキスト（4〜5行の文章。箇条書き不要）",
                    "─────────────────────────────",
                    "",
                    "【## 結果 の書き方（最重要）】",
                    "## 結果 の「○番」には、【実際のレース着順】の馬番をそのまま転記してください。",
                    "「予想した馬番」ではありません。「実際に走って着順がついた馬番」です。",
                    "的中 = その着順の実際の馬番が、## 予想 で予測した同じ着順の馬番と一致する",
                    "不的中 = 一致しない",
                    "",
                    "【記入例】",
                    "  予想: 1着=10番、2着=1番、3着=9番",
                    "  実際: 1着=4番、2着=1番、3着=11番",
                    "  → 正しい ## 結果 の記載:",
                    "    1着: 4番（不的中）   ← 実際の1着は4番。予想は10番なので不一致",
                    "    2着: 1番（的中）     ← 実際の2着は1番。予想も1番なので一致",
                    "    3着: 11番（不的中）  ← 実際の3着は11番。予想は9番なので不一致",
                    "",
                ];

                if ($hasPickup) {
                    $msgs[] = "【ピックアップ馬について】";
                    $msgs[] = "別工程のAI分析でピックアップ馬が選出されています。";
                    $msgs[] = "「## 予想」はオッズ推移での予測ではなく、このピックアップ馬番を使用してください。";
                    $msgs[] = "「## 結果」は通常通り、【実際のレース着順】の馬番を記載し、ピックアップ馬番と照合して的中/不的中を判定してください。";
                    $msgs[] = "ピックアップ馬番: " . implode(", ", $pickupHorses[$raceKey]);
                    $msgs[] = "";
                }

                $msgs[] = "【単勝オッズ推移データ】";
                $msgs[] = $oddsInfoStr;
                $msgs[] = "";
                $msgs[] = "【実際のレース着順】（## 結果 にはこの馬番を転記すること）";
                $msgs[] = "1着: {$actualResult1}番";
                $msgs[] = "2着: {$actualResult2}番";
                $msgs[] = "3着: {$actualResult3}番";

                if ($brain !== '') {
                    $msgs[] = '';
                    $msgs[] = 'あなたの馬眼力ブレインに蓄積された知識と判断基準を最大限に発揮して、今日もベストな予想を頼みます！全力でお願いします！！';
                }

                $prompt = implode("\n", $msgs);

                // プロンプトをファイルに出力
                file_put_contents(
                    '/var/www/horse_odds_finder/public/race_introspection/'
                    . $race->date . '_' . $race->basho_name . '_' . $race->race . 'R.txt',
                    $prompt
                );

                // 実際の着順もセットで保持（バリデーション・自動補正に使用）
                $pending[] = [
                    'race'   => $race,
                    'prompt' => $prompt,
                    'r1'     => $actualResult1,
                    'r2'     => $actualResult2,
                    'r3'     => $actualResult3,
                ];
                $this->line("  [収集] {$race->date} {$race->basho_name} {$race->kaisuu}回{$race->day}日目 {$race->race}R");
            }

            $this->info('');

            if (empty($pending)) {
                $this->info('未処理レースなし。API 送信をスキップします。');
                $status = '全レース処理済み（スキップ）';
                return;
            }

            $this->info(count($pending) . ' 件を API に送信します。');
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // BATCH_SIZE 件ずつ Http::pool() で並列送信 → 結果処理
            // ─────────────────────────────────────────────────────────────────
            $batches      = array_chunk($pending, self::BATCH_SIZE);
            $retryTargets = [];

            foreach ($batches as $batchIndex => $batch) {
                $batchNum   = $batchIndex + 1;
                $batchTotal = count($batches);

                if ($batchIndex > 0) {
                    $this->line("  バッチ間待機: " . self::BATCH_SLEEP_SEC . " 秒...");
                    sleep(self::BATCH_SLEEP_SEC);
                }

                $this->info("[フェーズ2] バッチ {$batchNum}/{$batchTotal} — " . count($batch) . " 件を並列送信中...");

                $responses = $this->anthropic->sendPool(array_column($batch, 'prompt'), $brain !== '' ? $brain : null);

                $this->info("[フェーズ3] バッチ {$batchNum}/{$batchTotal} — 結果処理中...");

                foreach ($batch as $itemIndex => $item) {
                    $race     = $item['race'];
                    $label    = "{$race->date} {$race->basho_name} {$race->kaisuu}回{$race->day}日目 {$race->race}R";
                    $response = $responses[$itemIndex];

                    if ($response instanceof \Throwable) {
                        Log::error('SummaryRacesIntrospection: 接続エラー', [
                            'label' => $label, 'error' => $response->getMessage(),
                        ]);
                        $totalFailed++;
                        continue;
                    }

                    if (in_array($response->status(), [429, 529])) {
                        $this->warn("  [リトライ待ち] {$label} — HTTP {$response->status()}");
                        $retryTargets[] = $item;
                        continue;
                    }

                    if ($response->failed()) {
                        Log::error('SummaryRacesIntrospection: APIエラー', [
                            'label' => $label, 'status' => $response->status(), 'body' => $response->body(),
                        ]);
                        $totalFailed++;
                        continue;
                    }

                    $introspection = $this->anthropic->extractText($response);

                    if ($introspection === '') {
                        Log::error('SummaryRacesIntrospection: レスポンス空', ['label' => $label]);
                        $totalFailed++;
                        continue;
                    }

                    // ─── バリデーション → 自動補正 ───────────────────────────────
                    $introspection = $this->validateAndCorrect(
                        $introspection, $item['r1'], $item['r2'], $item['r3'], $label
                    );
                    if ($introspection === null) {
                        $totalFailed++;
                        continue;
                    }
                    // ─────────────────────────────────────────────────────────────

                    try {
                        $this->storeResult($race, $introspection);
                        $this->info("  [完了] {$label}");
                        $totalSuccess++;
                    } catch (\Throwable $e) {
                        Log::error('SummaryRacesIntrospection: DB格納エラー', [
                            'label' => $label, 'error' => $e->getMessage(),
                        ]);
                        $totalFailed++;
                    }
                }
            }

            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // 429/529 リトライ（指数バックオフ・逐次）
            // ─────────────────────────────────────────────────────────────────
            if (!empty($retryTargets)) {
                $this->info('[フェーズ4] 429/529 リトライ中（' . count($retryTargets) . ' 件）...');

                foreach ($retryTargets as $item) {
                    $race      = $item['race'];
                    $label     = "{$race->basho_name} {$race->race}R";
                    $succeeded = false;
                    $giveUp    = false;

                    for ($attempt = 1; $attempt <= 3; $attempt++) {
                        $waitSec = $attempt * 10;
                        $this->line("  [試行 {$attempt}/3] {$label} — {$waitSec}秒待機...");
                        sleep($waitSec);

                        $response = $this->anthropic->send($item['prompt'], $brain !== '' ? $brain : null);

                        if (in_array($response->status(), [429, 529])) {
                            $this->warn("  [試行 {$attempt}/3] {$label} — まだ HTTP {$response->status()}");
                            continue;
                        }

                        if ($response->failed()) {
                            Log::error('SummaryRacesIntrospection: リトライAPIエラー', [
                                'label' => $label, 'attempt' => $attempt, 'status' => $response->status(),
                            ]);
                            $giveUp = true;
                            $totalFailed++;
                            break;
                        }

                        $introspection = $this->anthropic->extractText($response);

                        if ($introspection === '') {
                            Log::error('SummaryRacesIntrospection: リトライ レスポンス空', [
                                'label' => $label, 'attempt' => $attempt,
                            ]);
                            $giveUp = true;
                            $totalFailed++;
                            break;
                        }

                        // ─── バリデーション → 自動補正 ────────────────────────────
                        $introspection = $this->validateAndCorrect(
                            $introspection, $item['r1'], $item['r2'], $item['r3'], $label
                        );
                        if ($introspection === null) {
                            $giveUp = true;
                            $totalFailed++;
                            break;
                        }
                        // ─────────────────────────────────────────────────────────

                        try {
                            $this->storeResult($race, $introspection);
                            $this->info("  [完了] {$label}");
                            $totalSuccess++;
                            $succeeded = true;
                        } catch (\Throwable $e) {
                            Log::error('SummaryRacesIntrospection: リトライDB格納エラー', [
                                'label' => $label, 'attempt' => $attempt, 'error' => $e->getMessage(),
                            ]);
                            $giveUp = true;
                            $totalFailed++;
                        }
                        break;
                    }

                    if (!$succeeded && !$giveUp) {
                        Log::error('SummaryRacesIntrospection: リトライ上限到達', ['label' => $label]);
                        $totalFailed++;
                    }
                }

                $this->info('');
            }

            $status = '正常終了';

        } finally {
            $elapsed = round(microtime(true) - $startedAt, 1);

            $this->info('終了理由     : ' . $status);
            $this->info('対象レース   : ' . $totalRaces   . ' 件');
            $this->info('スキップ     : ' . $totalSkipped . ' 件');
            $this->info('成功         : ' . $totalSuccess . ' 件');
            $this->info('失敗         : ' . $totalFailed  . ' 件');
            $this->info('処理時間     : ' . $elapsed      . ' 秒');
            $this->info('');
            $this->info('========== keiba:SummaryRacesIntrospection 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "SummaryRacesIntrospection::handle\n{$status}\nレース:{$totalRaces}件、成功:{$totalSuccess}件、失敗:{$totalFailed}件"
            );
        }
    }

    private function storeResult(object $race, string $introspection): void
    {
        DB::table('t_horse_odds_finder_race_introspection')->insert([
            'date'          => $race->date,
            'kaisuu'        => $race->kaisuu,
            'basho'         => $race->basho_name,
            'basho_code'    => $race->basho,
            'day'           => $race->day,
            'race'          => $race->race,
            'race_name'     => $race->race_name,
            'introspection' => $introspection,
        ]);
    }

    /**
     * バリデーション → 自動補正 のパイプライン。
     *
     * 1. validateIntrospection() でフォーマットと的中ラベルを検証
     * 2. 問題なければそのまま返す
     * 3. 問題があれば autoCorrectResultSection() で ## 結果 を強制再構築
     * 4. 再構築後に再検証し、通れば補正済みテキストを返す
     * 5. それでも通らなければ null を返してスキップ（絶対に不正データをDBに入れない）
     *
     * @return string|null 保存して良いテキスト。null の場合はスキップ
     */
    private function validateAndCorrect(
        string $introspection,
        string $r1, string $r2, string $r3,
        string $label
    ): ?string {
        // まずそのまま検証
        if ($this->validateIntrospection($introspection, $r1, $r2, $r3)) {
            return $introspection;
        }

        // 検証失敗 → 自動補正を試みる
        Log::warning('SummaryRacesIntrospection: ## 結果 の検証失敗。自動補正を試みます。', [
            'label' => $label,
            'r1'    => $r1,
            'r2'    => $r2,
            'r3'    => $r3,
        ]);
        $this->warn("  [自動補正] {$label} — ## 結果 が不正なため自動補正します");

        $corrected = $this->autoCorrectResultSection($introspection, $r1, $r2, $r3);
        if ($corrected === null) {
            Log::error('SummaryRacesIntrospection: 自動補正不能（セクション構造が壊れている）', [
                'label' => $label,
                'raw'   => mb_substr($introspection, 0, 500),
            ]);
            $this->error("  [スキップ] {$label} — 自動補正不能。DBには保存しません。");
            return null;
        }

        // 補正後に再検証
        if ($this->validateIntrospection($corrected, $r1, $r2, $r3)) {
            Log::info('SummaryRacesIntrospection: 自動補正成功', ['label' => $label]);
            $this->warn("  [自動補正完了] {$label}");
            return $corrected;
        }

        // 再検証も失敗
        Log::error('SummaryRacesIntrospection: 自動補正後も検証失敗', [
            'label'     => $label,
            'corrected' => mb_substr($corrected, 0, 500),
        ]);
        $this->error("  [スキップ] {$label} — 補正後も検証失敗。DBには保存しません。");
        return null;
    }

    /**
     * APIレスポンスの ## 結果 セクションが正しいかを検証する。
     *
     * 正しい条件:
     *   - ## 予想 / ## 結果 / ## 分析 の3セクションが存在する
     *   - ## 結果 の各着順に「実際の着順の馬番」が記載されている
     *   - 的中/不的中ラベルが予想馬番との比較結果と一致している
     */
    private function validateIntrospection(
        string $introspection,
        string $r1, string $r2, string $r3
    ): bool {
        // 3セクション必須チェック
        if (!str_contains($introspection, '## 予想')) return false;
        if (!str_contains($introspection, '## 結果')) return false;
        if (!str_contains($introspection, '## 分析')) return false;

        // ## 予想 から予測馬番を取得
        $yosoPart = explode('## 予想', $introspection, 2)[1] ?? '';
        if (str_contains($yosoPart, '## 結果')) {
            $yosoPart = explode('## 結果', $yosoPart, 2)[0];
        }
        $predictedNums = [];
        foreach ([1, 2, 3] as $pos) {
            if (preg_match("/{$pos}着:\s*(\d+)番/u", $yosoPart, $m)) {
                $predictedNums[$pos] = $m[1];
            }
        }

        // ## 結果 セクション抽出
        $resultPart = explode('## 結果', $introspection, 2)[1] ?? '';
        if (str_contains($resultPart, '## 分析')) {
            $resultPart = explode('## 分析', $resultPart, 2)[0];
        }

        // 実際の着順馬番と的中ラベルを検証
        $actualResults = [1 => $r1, 2 => $r2, 3 => $r3];
        foreach ($actualResults as $pos => $actualNum) {
            if ($actualNum === '?') continue; // 着順データなしはスキップ

            // 実際の馬番がその着順に記載されているか
            if (!preg_match("/{$pos}着:\s*{$actualNum}番/u", $resultPart)) {
                return false;
            }

            // 的中/不的中ラベルが正しいか
            $predicted = $predictedNums[$pos] ?? null;
            if ($predicted !== null) {
                $isHit         = ($predicted === $actualNum);
                $expectedLabel = $isHit ? '（的中）' : '（不的中）';
                if (!preg_match("/{$pos}着:\s*{$actualNum}番{$expectedLabel}/u", $resultPart)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * ## 結果 セクションを実際の着順データで強制的に正しい内容に書き換える。
     * ## 予想 の馬番と実際の着順を比較して的中/不的中を正確に判定する。
     *
     * @return string|null 補正後テキスト。セクション構造が壊れていて補正不能な場合は null
     */
    private function autoCorrectResultSection(
        string $introspection,
        string $r1, string $r2, string $r3
    ): ?string {
        if (!str_contains($introspection, '## 予想')
            || !str_contains($introspection, '## 結果')
            || !str_contains($introspection, '## 分析')) {
            return null;
        }

        // ## 予想 から予測馬番を取得
        $yosoPart = explode('## 予想', $introspection, 2)[1] ?? '';
        if (str_contains($yosoPart, '## 結果')) {
            $yosoPart = explode('## 結果', $yosoPart, 2)[0];
        }
        $predictedNums = [];
        foreach ([1, 2, 3] as $pos) {
            if (preg_match("/{$pos}着:\s*(\d+)番/u", $yosoPart, $m)) {
                $predictedNums[$pos] = $m[1];
            }
        }

        // ## 結果 を正しい内容で構築
        $actualResults = [1 => $r1, 2 => $r2, 3 => $r3];
        $resultLines   = [];
        foreach ([1, 2, 3] as $pos) {
            $actualNum = $actualResults[$pos];
            if ($actualNum === '?') {
                $resultLines[] = "{$pos}着: ?番（判定不能）";
                continue;
            }
            $predicted     = $predictedNums[$pos] ?? null;
            $isHit         = ($predicted !== null && $predicted === $actualNum);
            $label         = $isHit ? '（的中）' : '（不的中）';
            $resultLines[] = "{$pos}着: {$actualNum}番{$label}";
        }

        $correctedResultSection = implode("\n", $resultLines);

        // ## 結果 ～ ## 分析 の間を新しい内容で置き換え
        $before      = explode('## 結果', $introspection, 2)[0];
        $afterResult = explode('## 結果', $introspection, 2)[1] ?? '';
        $after       = '## 分析' . (explode('## 分析', $afterResult, 2)[1] ?? '');

        return $before . "## 結果\n" . $correctedResultSection . "\n\n" . $after;
    }
}

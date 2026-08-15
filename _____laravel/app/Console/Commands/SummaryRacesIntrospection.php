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
    protected $description = 'claude apiを使って、レースの有力馬選出の振り返りをする';

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
            $isRunning = $pid > 0 && (
                (function_exists('posix_kill') && posix_kill($pid, 0))
                || file_exists("/proc/{$pid}")
            );
            if ($isRunning) {
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
                // 「馬番：X、馬名：...」形式から馬番を抽出
                if (preg_match_all('/^馬番：(\d+)/mu', $aiAnalysisRow->analysis_text, $m) && !empty($m[1])) {
                    $key = "{$aiAnalysisRow->date}_{$aiAnalysisRow->kaisuu}_{$aiAnalysisRow->basho_code}_{$aiAnalysisRow->day}_{$aiAnalysisRow->race}";
                    $pickupHorses[$key] = $m[1];
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

                // 頭立て数からピックアップ頭数を決定
                $horseCount  = $oddsRows->count();
                $pickupCount = $horseCount <= 8 ? 4 : ($horseCount <= 13 ? 5 : 6);

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
                    "目的は結果を当てることではなく、「なぜ間違えたのか」を深く考えることです。",
                    "この分析は、今後のAI競馬予測の精度向上に向けた、馬の選出プロセスの改善に活用されます。",
                    "予測が当たった場合も、偶然か根拠に基づくものかを区別してください。",
                    "",
                    "【手順】",
                    "1. 【単勝オッズ推移データ】のみを見て、有力馬を{$pickupCount}頭ピックアップしてください。",
                    "2. 【実際のレース着順】を確認し、{$pickupCount}頭の選出馬の中に、1着から3着が何頭含まれているか照合してください。",
                    "3. 3頭が含まれていない場合は、なぜオッズ推移から有力馬を正しく見極められなかったのか、詳しく分析してください。",
                    "",
                    "【出力フォーマット（厳守）】",
                    "このフォーマットは画面表示アプリがそのままパースします。",
                    "見出し（## ピックアップ / ## 結果 / ## 分析）は一切変更しないでください。",
                    "前置き・後書き・補足コメントは不要です。フォーマット通りに出力してください。",
                    "",
                    "─────────────────────────────",
                    "## ピックアップ",
                    "○番 馬名",
                    "",
                    "## 結果",
                    "{$pickupCount}頭中Y頭が合致",
                    "",
                    "## 分析",
                    "分析テキスト（4〜5行の文章。箇条書き不要）",
                    "─────────────────────────────",
                    "",
                ];

                if ($hasPickup) {
                    $msgs[] = "【ピックアップ馬について】";
                    $msgs[] = "別工程のAI分析でピックアップ馬が選出されています。";
                    $msgs[] = "ピックアップ馬がある場合は、新たに選出せず、以下の馬番をそのまま使用してください。";
                    $msgs[] = "ピックアップ馬番: " . implode(", ", $pickupHorses[$raceKey]);
                    $msgs[] = "";
                }

                $msgs[] = "【単勝オッズ推移データ】";
                $msgs[] = $oddsInfoStr;
                $msgs[] = "";
                $msgs[] = "【実際の入賞馬】";
                $msgs[] = "{$actualResult1}番、{$actualResult2}番、{$actualResult3}番";

                if ($brain !== '') {
                    $msgs[] = '';
                    $msgs[] = 'あなたの馬眼力ブレインに蓄積された知識と判断基準を最大限に発揮して、今日もベストな分析を頼みます！全力でお願いします！！';
                }

                $prompt = implode("\n", $msgs);

                // プロンプトをファイルに出力
                file_put_contents(
                    "/var/www/horse_odds_finder/public/race_introspection/introspection_{$race->date}_{$race->kaisuu}_{$race->basho}_{$race->day}_{$race->race}.txt",
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
                $status = 'SKIP';
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

            $newsValue = [];
            $newsValue[] = $status;
            if($status != "SKIP"){
                $newsValue[] = "レース:{$totalRaces}件、";
                $newsValue[] = "成功:{$totalSuccess}件、";
                $newsValue[] = "失敗:{$totalFailed}件";
            }
            $news = implode("", $newsValue);
            
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryRacesIntrospection::handle\n{$news}");
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
     * 丸囲み数字（①〜⑳）を通常の半角数字に変換する。
     * AIが「④番」のように出力した場合でも正規表現がマッチするようにするための前処理。
     */
    private function normalizeCircledNumbers(string $text): string
    {
        $map = [
            '①' => '1',  '②' => '2',  '③' => '3',  '④' => '4',  '⑤' => '5',
            '⑥' => '6',  '⑦' => '7',  '⑧' => '8',  '⑨' => '9',  '⑩' => '10',
            '⑪' => '11', '⑫' => '12', '⑬' => '13', '⑭' => '14', '⑮' => '15',
            '⑯' => '16', '⑰' => '17', '⑱' => '18', '⑲' => '19', '⑳' => '20',
        ];
        return strtr($text, $map);
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
     *   - ## ピックアップ / ## 結果 / ## 分析 の3セクションが存在する
     *   - ## ピックアップ に馬番が1頭以上記載されている
     *   - ## 結果 が「X頭中Y頭が合致」形式で、合致数が実際の入賞馬と一致している
     */
    private function validateIntrospection(
        string $introspection,
        string $r1, string $r2, string $r3
    ): bool {
        // 3セクション必須チェック
        if (!str_contains($introspection, '## ピックアップ')) return false;
        if (!str_contains($introspection, '## 結果')) return false;
        if (!str_contains($introspection, '## 分析')) return false;

        // ## ピックアップ から馬番を取得（丸囲み数字も正規化して対応）
        $pickupPart = explode('## ピックアップ', $introspection, 2)[1] ?? '';
        if (str_contains($pickupPart, '## 結果')) {
            $pickupPart = explode('## 結果', $pickupPart, 2)[0];
        }
        $pickupPart = $this->normalizeCircledNumbers($pickupPart);
        preg_match_all('/(\d+)番/u', $pickupPart, $matches);
        $pickupNums = $matches[1] ?? [];

        if (empty($pickupNums)) return false;

        $pickupCount = count($pickupNums);

        // 実際の入賞馬と照合して正しい合致数を算出
        $actualNums = array_filter([$r1, $r2, $r3], fn($n) => $n !== '?');
        $matchCount = count(array_intersect($pickupNums, array_values($actualNums)));

        // ## 結果 セクション抽出
        $resultPart = explode('## 結果', $introspection, 2)[1] ?? '';
        if (str_contains($resultPart, '## 分析')) {
            $resultPart = explode('## 分析', $resultPart, 2)[0];
        }

        // 「X頭中Y頭が合致」形式かつ数値が正しいかチェック
        if (!preg_match('/(\d+)頭中(\d+)頭が合致/u', $resultPart, $m)) {
            return false;
        }
        if ((int)$m[1] !== $pickupCount || (int)$m[2] !== $matchCount) {
            return false;
        }

        return true;
    }

    /**
     * ## 結果 セクションを正しい合致数で強制的に書き換える。
     * ## ピックアップ の馬番と実際の入賞馬を比較して合致数を再計算する。
     *
     * @return string|null 補正後テキスト。セクション構造が壊れていて補正不能な場合は null
     */
    private function autoCorrectResultSection(
        string $introspection,
        string $r1, string $r2, string $r3
    ): ?string {
        if (!str_contains($introspection, '## ピックアップ')
            || !str_contains($introspection, '## 結果')
            || !str_contains($introspection, '## 分析')) {
            return null;
        }

        // ## ピックアップ から馬番を取得（丸囲み数字も正規化して対応）
        $pickupPart = explode('## ピックアップ', $introspection, 2)[1] ?? '';
        if (str_contains($pickupPart, '## 結果')) {
            $pickupPart = explode('## 結果', $pickupPart, 2)[0];
        }
        $pickupPart = $this->normalizeCircledNumbers($pickupPart);
        preg_match_all('/(\d+)番/u', $pickupPart, $matches);
        $pickupNums = $matches[1] ?? [];

        if (empty($pickupNums)) return null;

        $pickupCount = count($pickupNums);
        $actualNums  = array_filter([$r1, $r2, $r3], fn($n) => $n !== '?');
        $matchCount  = count(array_intersect($pickupNums, array_values($actualNums)));

        // ## 結果 を正しい内容で構築
        $correctedResultSection = "{$pickupCount}頭中{$matchCount}頭が合致";

        // ## 結果 ～ ## 分析 の間を新しい内容で置き換え
        $before      = explode('## 結果', $introspection, 2)[0];
        $afterResult = explode('## 結果', $introspection, 2)[1] ?? '';
        $after       = '## 分析' . (explode('## 分析', $afterResult, 2)[1] ?? '');

        return $before . "## 結果\n" . $correctedResultSection . "\n\n" . $after;
    }
}

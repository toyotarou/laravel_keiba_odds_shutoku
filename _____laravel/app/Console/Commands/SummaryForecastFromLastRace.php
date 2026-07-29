<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SummaryForecastFromLastRace
 *
 * 【概要】
 *   指定日に出走する全レースについて、各馬の過去出走履歴を Claude AI に渡し、
 *   「可能性のある馬（候補馬番）」を選出して
 *   t_horse_odds_finder_forecast_from_last_race に格納する。
 *
 *   処理済みレースは冒頭でスキップ（冪等）。
 *   Claude API は Http::pool() で BATCH_SIZE 件ずつ並列送信し、
 *   バッチ間に BATCH_SLEEP_SEC 秒の待機を挟むことで TPM レート制限を回避する。
 *   429/529 は指数バックオフで最大3回リトライする。
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック（YYYY-MM-DD 形式の検証）
 *   【ブロック 2】多重起動防止（日付別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   ─── ガード節 ───────────────────────────────────────────────────────────
 *   【ガード A】対象日のレース存在確認
 *   【ガード A'】馬・騎手スコアを先読み（name → score のハッシュマップ）
 *   【フェーズ 1】プロンプト収集（処理済みレースはスキップ）
 *   【ガード B】pending 空確認
 *   ─── ここから API 呼び出し確定 ─────────────────────────────────────────
 *   【フェーズ 2】BATCH_SIZE 件ずつ Http::pool() で並列送信
 *   【フェーズ 3】各バッチの結果処理・DB 格納（429/529 は retryTargets へ）
 *   【フェーズ 4】429/529 リトライ（指数バックオフ・逐次）
 *   【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:summaryForecastFromLastRace --date=2026-07-25
 *   php artisan keiba:summaryForecastFromLastRace  # 当日
 */
class SummaryForecastFromLastRace extends Command
{
    protected $signature   = 'keiba:summaryForecastFromLastRace {--date= : 対象年月日 (例: 2026-07-25)}';
    protected $description = '直近のレースから、AIを使って、候補馬を選出する';

    // 1バッチで並列送信するレース数（TPMレート制限対策）
    private const BATCH_SIZE      = 8;
    // バッチ間の待機秒数
    private const BATCH_SLEEP_SEC = 3;

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】引数チェック（YYYY-MM-DD 形式の検証）
        // ─────────────────────────────────────────────────────────────────
        $date = $this->option('date') ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date=YYYY-MM-DD の形式で指定してください。例: --date=2026-07-25');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】多重起動防止（日付別ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryForecastFromLastRace_' . str_replace('-', '', $date) . '.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $startedAt    = microtime(true);
        $totalRaces   = 0;
        $totalSkipped = 0;
        $totalSuccess = 0;
        $totalFailed  = 0;
        $status       = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:summaryForecastFromLastRace 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象日付     : ' . $date);
            $this->info('バッチサイズ : ' . self::BATCH_SIZE . ' レース');
            $this->info('バッチ間隔   : ' . self::BATCH_SLEEP_SEC . ' 秒');
            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // ガード節
            // ═════════════════════════════════════════════════════════════

            // ─────────────────────────────────────────────────────────────
            // 【ガード A】対象日のレース存在確認
            // ─────────────────────────────────────────────────────────────
            $races = DB::table('t_horse_odds_finder_races')
                ->where('date', $date)
                ->orderBy('kaisuu')
                ->orderBy('basho')
                ->orderBy('day')
                ->orderBy('race')
                ->get();

            if ($races->isEmpty()) {
                $this->info('[ガードA] 対象日のレースが存在しません。終了します。');
                $status = '対象レースなし（スキップ）';
                return;
            }

            $totalRaces = $races->count();
            $this->info("[ガードA] {$totalRaces} レースを確認。");
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ガード A'】馬・騎手スコアを先読み（name → score のハッシュマップ）
            //   プロンプト生成時に O(1) 参照するための前処理。
            //   騎手名は jockey_scores に記号なしで保存されているため、
            //   参照時も先頭記号を preg_replace で除去してからキーを引く。
            // ─────────────────────────────────────────────────────────────
            $this->info("[ガード A'] 馬・騎手スコアを先読み中...");

            $horseScores = DB::table('t_horse_odds_finder_horse_scores')
                ->get()
                ->mapWithKeys(fn($r) => [$r->name => $r->score])
                ->toArray();

            $jockeyScores = DB::table('t_horse_odds_finder_jockey_scores')
                ->get()
                ->mapWithKeys(fn($r) => [$r->name => $r->score])
                ->toArray();

            $this->info('  → 馬スコア: ' . count($horseScores) . ' 頭 / 騎手スコア: ' . count($jockeyScores) . ' 名');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【フェーズ 1】プロンプト収集（処理済みレースはスキップ）
            // ─────────────────────────────────────────────────────────────
            $this->info('[フェーズ1] プロンプト収集中...');

            $pending = [];

            foreach ($races as $race) {
                $isProcessed = DB::table('t_horse_odds_finder_forecast_from_last_race')
                    ->where('kaisuu', $race->kaisuu)
                    ->where('basho',  $race->basho)
                    ->where('day',    $race->day)
                    ->where('race',   $race->race)
                    ->exists();

                if ($isProcessed) {
                    $this->line("  [スキップ] {$race->basho_name} {$race->race}R（処理済み）");
                    $totalSkipped++;
                    continue;
                }

                $horses = DB::table('t_horse_odds_finder_horses')
                    ->where('kaisuu', $race->kaisuu)
                    ->where('basho',  $race->basho)
                    ->where('day',    $race->day)
                    ->where('race',   $race->race)
                    ->orderBy('num')
                    ->get();

                $horseInfoList     = [];
                $horseInfoTemplate = "馬番:NUM___馬名:NAME___成績:RECORD";
                $historyTemplate   = "(RACE_NUM)日付:DATE|開催場所:BASHO|レース種別:DIST|馬場状態:CONDITION|頭数:NUM_HORSES|人気:POPULARITY|着順:FINISHING_POSITION|馬体重:HORSE_WEIGHT|コーナー順位:CORNER1-CORNER2-CORNER3-CORNER4|ラスト3ハロン:LAST3F|ジョッキー:JOCKEY";

                foreach ($horses as $horse) {
                    $horseHistory = DB::table('t_horse_odds_finder_shutsuba_history')
                        ->where('name', $horse->name)
                        ->orderBy('date', 'desc')
                        ->get();

                    $historyStrings = [];
                    foreach ($horseHistory as $historyIndex => $history) {
                        $historyStr = strtr($historyTemplate, [
                            'RACE_NUM'           => ($historyIndex + 1),
                            'DATE'               => $history->date,
                            'BASHO'              => $history->basho,
                            'DIST'               => $history->dist,
                            'CONDITION'          => $history->condition,
                            'NUM_HORSES'         => $history->num_horses,
                            'POPULARITY'         => $history->popularity,
                            'FINISHING_POSITION' => $history->finishing_position,
                            'HORSE_WEIGHT'       => $history->horse_weight,
                            'CORNER1'            => is_null($history->corner_1) ? '_' : $history->corner_1,
                            'CORNER2'            => is_null($history->corner_2) ? '_' : $history->corner_2,
                            'CORNER3'            => is_null($history->corner_3) ? '_' : $history->corner_3,
                            'CORNER4'            => is_null($history->corner_4) ? '_' : $history->corner_4,
                            'LAST3F'             => $history->last_3f,
                            'JOCKEY'             => $history->jockey,
                        ]);

                        $cleanJockey = preg_replace('/^[▲★☆△◇]+/u', '', trim($history->jockey));
                        if (isset($jockeyScores[$cleanJockey])) {
                            $historyStr .= "|ジョッキー評価:100点中{$jockeyScores[$cleanJockey]}点";
                        }

                        if ($history->finishing_position != 1) {
                            $historyStr .= "|1位との差:{$history->fin_time_diff}";
                        }

                        $historyStrings[] = $historyStr;
                    }

                    $horseInfoStr = strtr($horseInfoTemplate, [
                        'NUM'    => $horse->num,
                        'NAME'   => $horse->name,
                        'RECORD' => implode('/', $historyStrings),
                    ]);

                    if (isset($horseScores[$horse->name])) {
                        $horseInfoStr .= "___馬評価:100点中{$horseScores[$horse->name]}点";
                    }

                    $horseInfoList[] = $horseInfoStr;
                }

                $pickupCount = $race->num_horses <= 8
                    ? 4
                    : ($race->num_horses <= 13
                    ? 5
                    : 6);

                $prompt = 'あなたは競馬オッズ分析の専門家です。' . "\n\n"
                    . 'レース情報' . "\n"
                    . '日付: ' . $race->date . "\n"
                    . '開催: ' . $race->basho_name . "\n"
                    . 'レース: ' . $race->race . ' ' . $race->race_name . "\n"
                    . $race->course . ' / ' . $race->dist . 'm'
                    . (is_null($race->inner_outer) ? '' : ' / ' . $race->inner_outer)
                    . "\n\n"
                    . '下記はこのレースに出走する馬の情報になります。' . "\n"
                    . implode("\n", $horseInfoList) . "\n\n"
                    . "過去の出走情報から判断して、可能性のある馬を{$pickupCount}頭選んでください。" . "\n"
                    . '回答は選んだ馬番を「|」区切りの数字のみで出力してください。' . "\n"
                    . "出力形式の例: " . implode('|', range(1, $pickupCount)) . "\n"
                    . '見出し・太字・箇条書き・説明文・前置きは一切不要です。数字と「|」以外の文字を含めないでください。' . "\n\n";

                $pending[] = ['race' => $race, 'prompt' => $prompt];
                $this->line("  [収集] {$race->basho_name} {$race->race}R（{$race->num_horses}頭 → 候補{$pickupCount}頭）");
            }

            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ガード B】pending 空確認
            // ─────────────────────────────────────────────────────────────
            if (empty($pending)) {
                $this->info('[ガードB] 未処理レースなし。API 送信をスキップします。');
                $status = '全レース処理済み（スキップ）';
                return;
            }

            $this->info('[ガードB] ' . count($pending) . ' 件を API に送信します。');
            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // ガード節をすべて通過 ── ここから API 呼び出しが確定
            // ═════════════════════════════════════════════════════════════

            // ─────────────────────────────────────────────────────────────
            // 【フェーズ 2/3】BATCH_SIZE 件ずつ Http::pool() で並列送信 → 結果処理
            //
            // 1日最大36レースを一度に送ると TPM（Tokens Per Minute）制限に
            // 引っかかる可能性があるため、BATCH_SIZE 件ずつバッチ分割して送信する。
            // バッチ間には BATCH_SLEEP_SEC 秒待機し、トークン消費レートを抑える。
            // ─────────────────────────────────────────────────────────────
            $apiKey       = config('services.anthropic.api_key');
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

                $responses = Http::pool(function ($pool) use ($batch, $apiKey) {
                    return array_map(fn ($item) =>
                        $pool->withHeaders([
                            'x-api-key'         => $apiKey,
                            'anthropic-version' => '2023-06-01',
                            'content-type'      => 'application/json',
                        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                            'model'      => 'claude-haiku-4-5',
                            'max_tokens' => 64,
                            'messages'   => [
                                ['role' => 'user', 'content' => $item['prompt']],
                            ],
                        ])
                    , $batch);
                });

                $this->info("[フェーズ3] バッチ {$batchNum}/{$batchTotal} — 結果処理中...");

                foreach ($batch as $itemIndex => $item) {
                    $race     = $item['race'];
                    $label    = "{$race->basho_name} {$race->race}R";
                    $response = $responses[$itemIndex];

                    if ($response instanceof \Throwable) {
                        Log::error('SummaryForecastFromLastRace: 接続エラー', [
                            'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                            'error' => $response->getMessage(),
                        ]);
                        $totalFailed++;
                        continue;
                    }

                    // 429 Rate Limit / 529 Overloaded → リトライキューへ
                    if (in_array($response->status(), [429, 529])) {
                        $this->warn("  [リトライ待ち] {$label} — HTTP {$response->status()}");
                        $retryTargets[] = $item;
                        continue;
                    }

                    if ($response->failed()) {
                        Log::error('SummaryForecastFromLastRace: APIエラー', [
                            'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                            'status' => $response->status(), 'body' => $response->body(),
                        ]);
                        $totalFailed++;
                        continue;
                    }

                    $rawText      = $response->json('content.0.text') ?? '';
                    $forecastNums = $this->parseForecastNums($rawText);

                    if ($forecastNums === null) {
                        Log::error('SummaryForecastFromLastRace: フォーマット不正', [
                            'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                            'raw' => $rawText,
                        ]);
                        $totalFailed++;
                        continue;
                    }

                    try {
                        $this->storeResult($race, $forecastNums);
                        $this->info("  [完了] {$label} → {$forecastNums}");
                        $totalSuccess++;
                    } catch (\Throwable $e) {
                        Log::error('SummaryForecastFromLastRace: DB格納エラー', [
                            'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                            'error' => $e->getMessage(),
                        ]);
                        $totalFailed++;
                    }
                }
            }

            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【フェーズ 4】429/529 リトライ（指数バックオフ・逐次）
            // ─────────────────────────────────────────────────────────────
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

                        $response = Http::withHeaders([
                            'x-api-key'         => $apiKey,
                            'anthropic-version' => '2023-06-01',
                            'content-type'      => 'application/json',
                        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                            'model'      => 'claude-haiku-4-5',
                            'max_tokens' => 64,
                            'messages'   => [
                                ['role' => 'user', 'content' => $item['prompt']],
                            ],
                        ]);

                        if (in_array($response->status(), [429, 529])) {
                            $this->warn("  [試行 {$attempt}/3] {$label} — まだ HTTP {$response->status()}");
                            continue;
                        }

                        if ($response->failed()) {
                            Log::error('SummaryForecastFromLastRace: リトライAPIエラー', [
                                'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                                'attempt' => $attempt, 'status' => $response->status(),
                            ]);
                            break;
                        }

                        $rawText      = $response->json('content.0.text') ?? '';
                        $forecastNums = $this->parseForecastNums($rawText);

                        if ($forecastNums === null) {
                            Log::error('SummaryForecastFromLastRace: リトライ フォーマット不正', [
                                'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                                'attempt' => $attempt, 'raw' => $rawText,
                            ]);
                            $giveUp = true; // フォーマット不正は再試行しても解消しないため打ち切り
                            $totalFailed++;
                            break;
                        }

                        try {
                            $this->storeResult($race, $forecastNums);
                            $this->info("  [完了] {$label} → {$forecastNums}");
                            $totalSuccess++;
                            $succeeded = true;
                        } catch (\Throwable $e) {
                            Log::error('SummaryForecastFromLastRace: リトライDB格納エラー', [
                                'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                                'attempt' => $attempt, 'error' => $e->getMessage(),
                            ]);
                            $giveUp = true; // DBエラーは再試行しても解消しないため打ち切り
                            $totalFailed++;
                        }
                        break;
                    }

                    if (!$succeeded && !$giveUp) {
                        Log::error('SummaryForecastFromLastRace: リトライ上限到達', [
                            'date' => $race->date, 'basho' => $race->basho_name, 'race' => $race->race,
                        ]);
                        $totalFailed++;
                    }
                }

                $this->info('');
            }

            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $elapsed = round(microtime(true) - $startedAt, 1);

            $this->info('終了理由     : ' . $status);
            $this->info('対象日付     : ' . $date);
            $this->info('対象レース   : ' . $totalRaces   . ' 件');
            $this->info('スキップ     : ' . $totalSkipped . ' 件');
            $this->info('成功         : ' . $totalSuccess . ' 件');
            $this->info('失敗         : ' . $totalFailed  . ' 件');
            $this->info('処理時間     : ' . $elapsed      . ' 秒');
            $this->info('');
            $this->info('========== keiba:summaryForecastFromLastRace 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "SummaryForecastFromLastRace::handle\n{$status}\n対象:{$date}、レース:{$totalRaces}件、成功:{$totalSuccess}件、失敗:{$totalFailed}件"
            );
        }
    }

    private function parseForecastNums(string $rawText): ?string
    {
        // "1|2|3" 形式(数字と「|」のみ)でなければ null を返す
        $trimmed = trim($rawText);
        return preg_match('/^\d+(?:\|\d+)+$/', $trimmed) ? $trimmed : null;
    }

    private function storeResult(object $race, string $forecastNums): void
    {
        DB::table('t_horse_odds_finder_forecast_from_last_race')->insert([
            'date'          => $race->date,
            'kaisuu'        => $race->kaisuu,
            'basho'         => $race->basho,
            'basho_name'    => $race->basho_name,
            'day'           => $race->day,
            'race'          => $race->race,
            'forecast_nums' => $forecastNums,
        ]);
    }
}

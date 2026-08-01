<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SummaryRacesIntrospection extends Command
{
    protected $signature   = 'keiba:SummaryRacesIntrospection';
    protected $description = 'claude apiを使って、レースの予想と反省をする';

    // 450件を一度に送ると TPM 制限に引っかかるため小さめに設定
    private const BATCH_SIZE      = 5;
    private const BATCH_SLEEP_SEC = 8;

    public function handle(): void
    {
        // 多重起動防止
        $lockFile = sys_get_temp_dir() . '/keiba_summaryRacesIntrospection.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
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

            // ─────────────────────────────────────────────────────────────────
            // 1〜3着の馬番を先読み（date_kaisuu_basho_code_day_race_着順 → 馬番）
            // ─────────────────────────────────────────────────────────────────
            $raceResult = [];
            $result2 = DB::table('t_horse_odds_finder_race_result_history')
                ->whereIn('finishing_position', [1, 2, 3])
                ->orderBy('date')
                ->orderBy('kaisuu')
                ->orderBy('basho_code')
                ->orderBy('day')
                ->orderBy('race')
                ->orderBy('finishing_position')
                ->get();

            foreach ($result2 as $v2) {
                $raceResult["{$v2->date}_{$v2->kaisuu}_{$v2->basho_code}_{$v2->day}_{$v2->race}_{$v2->finishing_position}"] = $v2->num;
            }

            $this->info('  → 着順先読み: ' . count($raceResult) . ' 件');
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
            $result     = DB::select($sql);
            $totalRaces = count($result);
            $this->info("  → 対象レース: {$totalRaces} 件");
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // プロンプト収集（処理済みレースはスキップ）
            // ─────────────────────────────────────────────────────────────────
            $this->info('[フェーズ1] プロンプト収集中...');

            $raceInfoTemplate = "日付:DATE___回数:KAISUU___場所名:BASHO_NAME___レース:RACE___レース名:RACE_NAME___オッズ情報:ODDS_INFO___結果:RESULT1, RESULT2, RESULT3";
            $oddsTemplate     = "馬番:NUM___馬名:NAME___レース開始X分前オッズ:(30)ODDS30|(21)ODDS21|(18)ODDS18|(15)ODDS15|(12)ODDS12|(9)ODDS9|(6)ODDS6";

            $pending = [];

            foreach ($result as $k => $v) {

                $exists = DB::table('t_horse_odds_finder_race_introspection')
                    ->where('date',      $v->date)
                    ->where('kaisuu',    $v->kaisuu)
                    ->where('basho_code', $v->basho)
                    ->where('day',       $v->day)
                    ->where('race',      $v->race)
                    ->exists();

                if ($exists) {
                    $this->line("  [スキップ] {$v->basho_name} {$v->race}R（処理済み）");
                    $totalSkipped++;
                    continue;
                }

                $result3 = DB::table('t_horse_odds_finder_summary')
                    ->where('date',   $v->date)
                    ->where('kaisuu', $v->kaisuu)
                    ->where('basho',  $v->basho)
                    ->where('day',    $v->day)
                    ->where('race',   $v->race)
                    ->get();

                $oddsStrings = [];
                foreach ($result3 as $v3) {
                    $oddsStrings[] = strtr($oddsTemplate, [
                        'NUM'    => $v3->num,
                        'NAME'   => $v3->horse_name,
                        'ODDS30' => $v3->odds_tan_before_24,
                        'ODDS21' => $v3->odds_tan_before_21,
                        'ODDS18' => $v3->odds_tan_before_18,
                        'ODDS15' => $v3->odds_tan_before_15,
                        'ODDS12' => $v3->odds_tan_before_12,
                        'ODDS9'  => $v3->odds_tan_before_9,
                        'ODDS6'  => $v3->odds_tan_before_6,
                    ]);
                }

                $raceInfoStr = strtr($raceInfoTemplate, [
                    'DATE'      => $v->date,
                    'KAISUU'    => $v->kaisuu,
                    'BASHO_NAME'=> $v->basho_name,
                    'RACE'      => $v->race,
                    'RACE_NAME' => $v->race_name,
                    'ODDS_INFO' => implode('/', $oddsStrings),
                    'RESULT1'   => $raceResult["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_1"] ?? '?',
                    'RESULT2'   => $raceResult["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_2"] ?? '?',
                    'RESULT3'   => $raceResult["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_3"] ?? '?',
                ]);

                $prompt = implode("\n", [
                    "あなたは競馬のオッズ分析の専門家です。",
                    "",
                    "以下は、あるレースにおける各馬の単勝オッズ推移（レース開始30分前〜6分前）と、実際の着順結果（結果）です。",
                    "",
                    "【重要：このタスクの目的について】",
                    "このタスクは、予測を正しく当てることを目的としていません。",
                    "目的は「なぜ間違えたのか」を深く考えることです。",
                    "正解することよりも、間違えた理由を丁寧に分析することの方がはるかに重要です。",
                    "この分析は、今後のAIによる競馬予測の精度を改善するための「思考の是正」として活用されます。",
                    "予測が当たった場合も、たまたま当たったのか・根拠があって当たったのかを区別してください。",
                    "とにかく「外れた理由の分析」に最大限の思考を注いでください。",
                    "",
                    "【手順】",
                    "1. まず「結果」の部分は見ずに、オッズ推移だけをもとに、あなたが予測する1〜3着を述べてください。",
                    "2. 次に「結果」と照合し、予測が当たった馬・外れた馬を整理してください。",
                    "3. 外れた馬については、なぜオッズ推移から正しく予測できなかったのか、どのような思考が判断を誤らせたのかを詳しく分析してください。",
                    "   （例：オッズが安定していたため過剰人気と見抜けなかった、急落した馬を信頼しすぎた、変動のない穴馬を軽視した など）",
                    "",
                    "【出力フォーマット】",
                    "画面表示アプリでそのままパース・表示するため、以下のMarkdown構造を厳守してください。",
                    "見出し文字列（## 予想 / ## 結果 / ## 分析）は一切変更しないでください。装飾や前置き・後書きも不要です。",
                    "",
                    "## 予想",
                    "1着: ○番 馬名",
                    "2着: ○番 馬名",
                    "3着: ○番 馬名",
                    "",
                    "## 結果",
                    "1着: ○番（的中/不的中）",
                    "2着: ○番（的中/不的中）",
                    "3着: ○番（的中/不的中）",
                    "",
                    "## 分析",
                    "4〜5行の文章で簡潔にまとめてください。箇条書きは不要です。当たった場合もたまたまか根拠があったかに触れつつ、外れた理由の分析を中心に記述してください。",
                    "",
                    "【レースデータ】",
                    $raceInfoStr,
                ]);

                // プロンプトをファイルに出力
                file_put_contents(
                    '/var/www/horse_odds_finder/public/race_introspection/'
                    . $v->date . '_' . $v->basho_name . '_' . $v->race . 'R.txt',
                    $prompt
                );

                $pending[] = ['race' => $v, 'prompt' => $prompt];
                $this->line("  [収集] {$v->basho_name} {$v->race}R");
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
                    return array_map(fn($item) =>
                        $pool->withHeaders([
                            'x-api-key'         => $apiKey,
                            'anthropic-version' => '2023-06-01',
                            'content-type'      => 'application/json',
                        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                            'model'      => 'claude-haiku-4-5',
                            'max_tokens' => 4096,
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

                    $introspection = trim($response->json('content.0.text') ?? '');

                    if ($introspection === '') {
                        Log::error('SummaryRacesIntrospection: レスポンス空', ['label' => $label]);
                        $totalFailed++;
                        continue;
                    }

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

                        $response = Http::withHeaders([
                            'x-api-key'         => $apiKey,
                            'anthropic-version' => '2023-06-01',
                            'content-type'      => 'application/json',
                        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                            'model'      => 'claude-haiku-4-5',
                            'max_tokens' => 4096,
                            'messages'   => [
                                ['role' => 'user', 'content' => $item['prompt']],
                            ],
                        ]);

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

                        $introspection = trim($response->json('content.0.text') ?? '');

                        if ($introspection === '') {
                            Log::error('SummaryRacesIntrospection: リトライ レスポンス空', [
                                'label' => $label, 'attempt' => $attempt,
                            ]);
                            $giveUp = true;
                            $totalFailed++;
                            break;
                        }

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
}

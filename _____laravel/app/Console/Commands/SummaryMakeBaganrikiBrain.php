<?php

namespace App\Console\Commands;

use App\Services\AnthropicService;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryMakeBaganrikiBrain
 *
 * 【概要】
 *   過去のレース予想振り返り（t_horse_odds_finder_race_introspection の ## 分析 セクション）を
 *   30件ずつ Claude API に送信し、オッズ分析の判断基準（脳みそ）を段階的に統合・磨き上げる。
 *   最終結果を baganriki_brain.txt に書き出す。
 *   初回稼働時は既存ファイルなし、2回目以降は保存済み脳みそをプロンプトに組み込む。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】初期化・開始バナー
 *   【ブロック 3】DB から振り返り一覧を取得し ## 分析 セクションを抽出
 *              （オッズが全馬・全時点で一切変動していないレースは判断材料から除外し finish=1 とする）
 *   【ブロック 4】30件ずつループで Claude API に送信・$latestBrainText に蓄積
 *   【ブロック 5】最終結果を baganriki_brain.txt に書き出し・処理済みレコードの finish を 1 に更新
 *   【ブロック 6】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:makeBaganrikiBrain
 */
class SummaryMakeBaganrikiBrain extends Command
{
    protected $signature   = 'keiba:makeBaganrikiBrain';
    protected $description = 'AI判断の基準となる、馬眼力の脳みそを作成する';

    private const DEVIDE_NUM = 30;
    private const BRAIN_DIR  = '/var/www/horse_odds_finder/public/baganriki_brain';
    private const BRAIN_FILE = self::BRAIN_DIR . '/baganriki_brain.txt';

    public function __construct(private AnthropicService $anthropic)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_makeBaganrikiBrain.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            $isRunning = $pid > 0 && (

                (function_exists('posix_kill') && posix_kill($pid, 0))

                || file_exists("/proc/{$pid}")

            );

            if ($isRunning) {
                // PIDが実際に生きているプロセス → 多重起動なので終了
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return;
            }
            // PIDが死んでいる → 前回が強制終了された残骸なので削除して続行
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $startedAt     = microtime(true);
        $totalLoops    = 0;
        $totalFailed   = 0;
        $totalFinished = 0;
        $status        = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:makeBaganrikiBrain 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 3】DB から振り返り一覧を取得し ## 分析 セクションを抽出
            // ─────────────────────────────────────────────────────────────────
            $dbRows = DB::table('t_horse_odds_finder_race_introspection')
                ->where(function ($q) {
                    $q->where('finish', '!=', 1)->orWhereNull('finish');
                })
                ->get();

            $introspections     = [];
            $introspectionMeta  = []; // 各エントリの選出頭数・合致数
            $introspectionIds   = [];
            $zeroMovementIds    = [];

            // ── 全レースの着順データを先読み ──────────────────────────────────
            // t_horse_odds_finder_race_result_history.basho_code = introspection.basho_code
            $allResults = [];
            DB::table('t_horse_odds_finder_race_result_history')
                ->whereIn('finishing_position', [1, 2, 3])
                ->get(['date', 'kaisuu', 'basho_code', 'day', 'race', 'finishing_position', 'num'])
                ->each(function ($r) use (&$allResults) {
                    $key = "{$r->date}_{$r->kaisuu}_{$r->basho_code}_{$r->day}_{$r->race}";
                    $allResults[$key][$r->finishing_position] = (string) $r->num;
                });

            // ── 全レースの6分前オッズを先読み ────────────────────────────────
            // t_horse_odds_finder_summary.basho = introspection.basho_code（同値）
            $allOdds6 = [];
            DB::table('t_horse_odds_finder_summary')
                ->whereNotNull('odds_tan_before_6')
                ->where('odds_tan_before_6', '!=', '')
                ->get(['date', 'kaisuu', 'basho', 'day', 'race', 'num', 'odds_tan_before_6'])
                ->each(function ($r) use (&$allOdds6) {
                    $key = "{$r->date}_{$r->kaisuu}_{$r->basho}_{$r->day}_{$r->race}";
                    $allOdds6[$key][(string) $r->num] = (float) $r->odds_tan_before_6;
                });

            foreach ($dbRows as $dbRow) {
                if (!str_contains($dbRow->introspection, '## 分析')) {
                    continue;
                }

                // 馬眼力はオッズ推移を根拠とする予想アプリのため、
                // 全馬・全時点でオッズが一切動いていないレースは判断材料として不適切として除外する
                if (!$this->raceHasOddsMovement($dbRow)) {
                    $zeroMovementIds[] = $dbRow->id;
                    continue;
                }

                // ## 結果 セクションから選出頭数・合致数を抽出（例: 「6頭中2頭が合致」）
                $pickupTotal = 0;
                $hitCount    = 0;
                if (str_contains($dbRow->introspection, '## 結果')) {
                    $resultPart = explode('## 結果', $dbRow->introspection, 2)[1] ?? '';
                    if (str_contains($resultPart, '## 分析')) {
                        $resultPart = explode('## 分析', $resultPart, 2)[0];
                    }
                    if (preg_match('/(\d+)頭中(\d+)頭が合致/u', $resultPart, $m)) {
                        $pickupTotal = (int) $m[1];
                        $hitCount    = (int) $m[2];
                    }
                }

                // 選出タグ + 分析テキストのみ（概念的なパターン抽出が目的のため馬番・馬名は不要）
                $analysisText = trim(explode('## 分析', $dbRow->introspection, 2)[1]);

                // ── ## ピックアップ から選出馬番を抽出 ──────────────────────────
                $pickedNums = [];
                if (str_contains($dbRow->introspection, '## ピックアップ')) {
                    $pp = explode('## ピックアップ', $dbRow->introspection, 2)[1] ?? '';
                    if (str_contains($pp, '## 結果')) {
                        $pp = explode('## 結果', $pp, 2)[0];
                    }
                    preg_match_all('/(\d+)番/u', $pp, $pm);
                    $pickedNums = array_map('strval', $pm[1] ?? []);
                }

                // ── 選出馬・入賞馬のオッズ文脈を組み立て ──────────────────────
                $raceKey    = "{$dbRow->date}_{$dbRow->kaisuu}_{$dbRow->basho_code}_{$dbRow->day}_{$dbRow->race}";
                $oddsMap    = $allOdds6[$raceKey]   ?? []; // num(string) => odds(float)
                $resultsMap = $allResults[$raceKey] ?? []; // position => num(string)

                $oddsContext = '';

                if (!empty($pickedNums) && !empty($oddsMap)) {
                    $parts = [];
                    foreach ($pickedNums as $n) {
                        $o       = isset($oddsMap[$n]) ? number_format($oddsMap[$n], 1) . '倍' : '?倍';
                        $parts[] = "{$n}番={$o}";
                    }
                    $oddsContext .= '選出馬(6分前オッズ): ' . implode(', ', $parts) . "\n";
                }

                if (!empty($resultsMap)) {
                    $parts          = [];
                    $highOddsHit    = [];
                    $highOddsMissed = [];
                    for ($pos = 1; $pos <= 3; $pos++) {
                        $num = $resultsMap[$pos] ?? null;
                        if ($num === null) continue;
                        $odds    = isset($oddsMap[$num]) ? number_format($oddsMap[$num], 1) . '倍' : '?倍';
                        $parts[] = "{$pos}着={$num}番({$odds})";
                        if (isset($oddsMap[$num]) && $oddsMap[$num] >= 10.0) {
                            if (in_array($num, $pickedNums)) {
                                $highOddsHit[]    = "{$num}番({$odds})";
                            } else {
                                $highOddsMissed[] = "{$num}番({$odds})";
                            }
                        }
                    }
                    $oddsContext .= '入賞馬(6分前オッズ): ' . implode(', ', $parts) . "\n";
                    if (!empty($highOddsHit)) {
                        $oddsContext .= '穴馬的中(10倍以上): ' . implode(', ', $highOddsHit) . "\n";
                    }
                    if (!empty($highOddsMissed)) {
                        $oddsContext .= '穴馬見落とし(10倍以上): ' . implode(', ', $highOddsMissed) . "\n";
                    }
                }

                $enrichedText        = $oddsContext !== '' ? $oddsContext . $analysisText : $analysisText;
                $introspections[]    = "[{$pickupTotal}頭中{$hitCount}頭が合致]\n{$enrichedText}";
                $introspectionMeta[] = ['pickup' => $pickupTotal, 'hit' => $hitCount];
                $introspectionIds[]  = $dbRow->id;
            }

            if (!empty($zeroMovementIds)) {
                $totalZeroMovementExcluded = DB::table('t_horse_odds_finder_race_introspection')
                    ->whereIn('id', $zeroMovementIds)
                    ->update(['finish' => 1]);
                $this->info("オッズ推移ゼロのため除外 : {$totalZeroMovementExcluded} 件");
            }

            $totalIntrospections = count($introspections);

            $this->info("振り返り件数 : {$totalIntrospections} 件");

            if ($totalIntrospections === 0) {
                $this->info('処理対象なし。スキップします。');
                $status = 'SKIP';
                return;
            }

            $batchCount = (int) ceil($totalIntrospections / self::DEVIDE_NUM);

            $this->info("ループ回数   : {$batchCount} 回");
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 4】30件ずつループで Claude API に送信・$latestBrainText に蓄積
            // ─────────────────────────────────────────────────────────────────
            $latestBrainText = '';
            $processedIds   = [];

            for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {

                $batchAnalyses  = array_slice($introspections,    $batchIndex * self::DEVIDE_NUM, self::DEVIDE_NUM);
                $batchMeta      = array_slice($introspectionMeta, $batchIndex * self::DEVIDE_NUM, self::DEVIDE_NUM);
                $batchHits      = array_sum(array_column($batchMeta, 'hit'));
                $batchTotal     = array_sum(array_column($batchMeta, 'pickup'));
                $hitRate        = $batchTotal > 0 ? round($batchHits / $batchTotal * 100, 1) : 0;

                $batchText   = implode("\n\n---\n\n", $batchAnalyses);
                $dataSection = "今回の振り返り内容（選出した馬のうち{$batchHits}/{$batchTotal}頭が入賞・一致率{$hitRate}%）:\n{$batchText}";

                if ($batchIndex === 0) {
                    if (file_exists(self::BRAIN_FILE)) {
                        $savedBrainText  = trim(file_get_contents(self::BRAIN_FILE));
                        $dataSection    .= "\n\n保存されている要約内容:{$savedBrainText}\n\n";
                    }
                } else {
                    $dataSection .= "\n\n前回の要約内容:{$latestBrainText}\n\n";
                }

                // ─── 脳みそ生成専用システムプロンプト ──────────────────────────────
                $brainSystemPrompt = 'あなたは競馬オッズ分析の統計サマリー作成者です。提供された過去レース振り返りデータを統計的に分析し、断層タイプ別・人気帯別の客観的な傾向データをまとめてください。指示的な文章ではなく、数値とパーセンテージを中心とした統計的事実として記述してください。';

                $prompt = implode("\n", [
                    "【タスク】",
                    "以下の競馬レース振り返りデータを統計的に分析し、断層タイプ別・人気帯別の傾向サマリーを生成してください。",
                    "これは次回のAI予想の参考情報として使われる統計データです。指示文ではなく、客観的な事実・傾向として記述してください。",
                    "",
                    "【分析観点】",
                    "・選出{$batchTotal}頭中{$batchHits}頭合致（一致率{$hitRate}%）の内訳: よく当たったケース（2頭以上合致）と外れたケースの違いは何か",
                    "・穴馬的中（10倍以上）が発生したケースの共通点（オッズ推移・流入タイミング）",
                    "・穴馬見落とし（10倍以上）のケースで選べなかった理由",
                    "・1〜3番人気のみ選出した場合の問題点",
                    "",
                    "【出力フォーマット（厳守）】",
                    "## 成功パターン（合致率が高いケースの共通点）",
                    "## 失敗パターン（見落とし・外れのケースの共通点）",
                    "## 穴馬シグナル（10倍以上の馬を選ぶヒントになるオッズ推移の特徴）",
                    "## 注意点（選出時に陥りやすいミスと回避方法）",
                    "※各セクション3〜5行程度。数字（オッズ・倍率・合致率）を含めて具体的に書くこと。",
                    "",
                    "---",
                    "",
                    $dataSection,
                ]);

                $loopStartedAt = microtime(true);

                $this->info("[ループ {$batchIndex}/" . ($batchCount - 1) . "] API 送信中... (今回 " . count($batchAnalyses) . " 件 / 一致率 {$hitRate}% ({$batchHits}/{$batchTotal}) / プロンプト " . number_format(mb_strlen($prompt)) . " 文字)");

                $response = $this->anthropic->send($prompt, $brainSystemPrompt);

                $loopElapsed = round(microtime(true) - $loopStartedAt, 1);

                if ($response->failed()) {
                    Log::error('SummaryMakeBaganrikiBrain: APIエラー', [
                        'loop'   => $batchIndex,
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    $this->error("[ループ {$batchIndex}] APIエラー: " . $response->status() . "（所要 {$loopElapsed} 秒）");
                    $totalFailed++;
                    break;
                }

                $latestBrainText = $this->anthropic->extractText($response);

                if ($latestBrainText === '') {
                    Log::error('SummaryMakeBaganrikiBrain: レスポンス空', ['loop' => $batchIndex]);
                    $this->error("[ループ {$batchIndex}] レスポンスが空でした（所要 {$loopElapsed} 秒）");
                    $totalFailed++;
                    break;
                }

                $batchIds     = array_slice($introspectionIds, $batchIndex * self::DEVIDE_NUM, self::DEVIDE_NUM);
                $processedIds = array_merge($processedIds, $batchIds);

                $inputTokens  = $response->json('usage.input_tokens');
                $outputTokens = $response->json('usage.output_tokens');
                $totalElapsedSoFar = round(microtime(true) - $startedAt, 1);

                $this->info(
                    "[ループ {$batchIndex}] 完了"
                    . "（所要 {$loopElapsed} 秒 / レスポンス " . number_format(mb_strlen($latestBrainText)) . " 文字"
                    . " / トークン 入力{$inputTokens}・出力{$outputTokens}"
                    . " / 累計経過時間 {$totalElapsedSoFar} 秒）"
                );
                $totalLoops++;
            }

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 5】最終結果を baganriki_brain.txt に書き出し・finish を 1 に更新
            // ─────────────────────────────────────────────────────────────────
            if ($latestBrainText !== '') {
                if (!is_dir(self::BRAIN_DIR)) {
                    mkdir(self::BRAIN_DIR, 0755, true);
                }
                file_put_contents(self::BRAIN_FILE, $latestBrainText);
                $this->info('baganriki_brain.txt に書き出しました。');

                if (!empty($processedIds)) {
                    $totalFinished = DB::table('t_horse_odds_finder_race_introspection')
                        ->whereIn('id', $processedIds)
                        ->update(['finish' => 1]);
                    $this->info("finish 更新    : {$totalFinished} 件");
                }
            }

            $status = ($totalFailed > 0) ? '正常終了（一部失敗あり）' : '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 6】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────────
            $elapsed = round(microtime(true) - $startedAt, 1);

            $this->info('');
            $this->info("終了理由     : {$status}");
            $this->info("処理ループ数 : {$totalLoops} 回");
            $this->info("失敗ループ数 : {$totalFailed} 回");
            $this->info("finish更新数 : {$totalFinished} 件");
            $this->info("処理時間     : {$elapsed} 秒");
            $this->info('');
            $this->info('========== keiba:makeBaganrikiBrain 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            $newsValue = [];
            $newsValue[] = $status;
            if($status != "SKIP"){
                $newsValue[] = "ループ:{$totalLoops}回、";
                $newsValue[] = "失敗:{$totalFailed}回、";
                $newsValue[] = "finish更新:{$totalFinished}件";
            }
            $news = implode("", $newsValue);
            
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryMakeBaganrikiBrain::handle\n{$news}");
        }
    }

    /**
     * 対象レースの単勝オッズが、全馬・全時点(30分前〜6分前)で一切変動していないかを判定する。
     * 該当するオッズ行が見つからない場合は安全側に倒し「変動あり」として扱う。
     */
    private function raceHasOddsMovement(object $introspectionRow): bool
    {
        $checkpoints = [
            'odds_tan_before_24', 'odds_tan_before_21', 'odds_tan_before_18',
            'odds_tan_before_15', 'odds_tan_before_12', 'odds_tan_before_9', 'odds_tan_before_6',
        ];

        $oddsRows = DB::table('t_horse_odds_finder_summary')
            ->where('date',   $introspectionRow->date)
            ->where('kaisuu', $introspectionRow->kaisuu)
            ->where('basho',  $introspectionRow->basho_code)
            ->where('day',    $introspectionRow->day)
            ->where('race',   $introspectionRow->race)
            ->get($checkpoints);

        if ($oddsRows->isEmpty()) {
            return true;
        }

        foreach ($oddsRows as $oddsRow) {
            $values = array_filter(
                array_map(fn($checkpoint) => $oddsRow->$checkpoint, $checkpoints),
                fn($value) => $value !== null && $value !== ''
            );

            if (count(array_unique($values)) > 1) {
                return true;
            }
        }

        return false;
    }
}

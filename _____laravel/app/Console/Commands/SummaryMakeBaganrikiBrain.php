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

                $prompt = implode("\n", [
                    "あなたは競馬のオッズ分析の専門家です。",
                    "",
                    "【このファイルの役割】",
                    "あなたが今から作るのは「脳みそファイル」です。",
                    "このファイルは、次のレースを分析するAIが最初に読み込む「目線の基準」になります。",
                    "つまり、このファイルを読んだAIが「どのような目線で有力馬をピックアップするのか」を",
                    "即座に理解できるように書くことが、あなたの唯一の仕事です。",
                    "",
                    "【このシステムの目的（最重要）】",
                    "「着順を当てる」ことが目的ではありません。「回収率を上げる」ことが目的です。",
                    "1〜3番人気ばかりを正確に当てても、オッズが低いため回収率は上がりません。",
                    "レースの頭数に応じて（8頭以下は4頭・13頭以下は5頭・14頭以上は6頭）、",
                    "「来そうかどうか（信頼度）」と「そのオッズで買う価値があるか（妙味）」を両方考えた選出をすることが目的です。",
                    "",
                    "信頼度が同等の馬が複数いる場合は、オッズが高い馬（妙味がある馬）を優先します。",
                    "複勝オッズが1.5倍未満の馬は、よほど強いシグナルがない限り妙味が低いと判断します。",
                    "複勝オッズが4倍以上で、かつ継続的に資金が入っている馬は、妙味・信頼度ともに積極的に評価します。",
                    "一時的にオッズが急落しただけで、その後すぐ戻るような動きは信頼度が低いと判断してください。",
                    "人気馬（低オッズ）だけを並べることは、このシステムの目的を果たしていません。",
                    "",
                    "【入力の説明】",
                    "・「今回の振り返り内容」: 今回新たに追加されたレース振り返り（分析テキスト）です。",
                    "  各エントリの冒頭に [X頭中Y頭が合致] というタグがついています。",
                    "  X頭選出したうち、実際の1〜3着に何頭含まれていたかを示します。",
                    "  ・[X頭中X頭が合致] や [X頭中Y頭が合致（Y≧2）] は「よく選べた例」です。その目線・考え方を特に重視してください。",
                    "  ・[X頭中0頭が合致] や [X頭中1頭が合致] は「見落とした例」です。何が足りなかったかを読み解いてください。",
                    "  ・各エントリには「選出馬(6分前オッズ)」「入賞馬(6分前オッズ)」も付いています。実際の数字を見ながら判断してください。",
                    "  ・「穴馬的中(10倍以上)」が付いているエントリは、高オッズ馬を正しく見つけた成功例です。そのオッズ推移の何が決め手だったかを特に重視してください。",
                    "  ・「穴馬見落とし(10倍以上)」が付いているエントリは、高オッズ馬を見逃した失敗例です。なぜ選べなかったのかを重点的に考えてください。",
                    "・「保存されている要約内容」または「前回の要約内容」: これまでに積み上げてきた判断基準です。初回はまだ存在しません。",
                    "",
                    "【あなたがやること】",
                    "1. 各エントリのタグと分析テキストを照合し、「どんな目線で見た馬が結果を残したか」を具体的な原則として抽出してください。",
                    "   （馬名・馬番など個別の情報ではなく、どんなレースでも使える「選び方の目線」を抽出する）",
                    "   このとき、次の2段階で整理してください。",
                    "   ・第1段階: その馬は結果を残しそうか（オッズ推移・安定性・単複比から判断）",
                    "   ・第2段階: そのオッズは旨味があるか（人気が集まりすぎていないか、割安感があるか）",
                    "   第1段階だけで選んだ馬が割高だった事例、逆に第2段階の視点で見つけた穴馬の事例があれば、それを特に重視してください。",
                    "2. 「保存されている要約内容」または「前回の要約内容」がある場合は、それと今回の内容をマージして、より鋭い目線に磨き上げてください。",
                    "3. 似た内容は統合し、矛盾する内容は「条件によって使い分ける」形で整理してください。",
                    "4. レースを「本命が絞れているレース」「どの馬も横並びで読みにくいレース」「荒れそうなレース」に分けて、それぞれで選び方が変わるなら、その違いも書いてください。",
                    "5. すべての記述を読んだAIが、新しいレースのオッズデータを見たとき、即座に「この馬を選ぶべき理由」を判断できるように書いてください。",
                    "",
                    "【このファイルの使われ方（重要）】",
                    "このファイルは「絶対に従わなければならないルール集」ではありません。",
                    "次のレースを分析するAIが「迷ったときに参考にする羅針盤」です。",
                    "だから、ここに書いてあることと実際のオッズデータが食い違う場合は、目の前のデータを優先してください。",
                    "このファイルの内容より、そのレースの実際のオッズ推移の方が常に正しい情報源です。",
                    "「このファイルに書いていないから選べない」という判断は禁止です。書いていない状況でも、データを見て判断してください。",
                    "",
                    "【厳守事項（重要）】",
                    "・人気馬（低オッズ）だけを選べばよいという結論は禁止です。中穴や安定した中位オッズ馬の目線も必ず含めてください。",
                    "・一時的なオッズの急落・急上昇は過大評価しない。単発の変動ではなく、複数時点にわたって継続している動きだけを根拠にしてください。",
                    "・「予想は本質的に不可能」「システムが機能停止している」「判定不能」などの諦めの記述は禁止です。",
                    "・「〜と認識すべきだ」「〜という誤謬に陥っている」のような反省・自己否定・愚痴のような記述は禁止です。",
                    "・矛盾するデータがある場合でも、必ずどちらか一方を優先する具体的な基準を1つ提示してください。",
                    "・すべて言い切り・断定形で記述してください。",
                    "・「〜が多い」「〜の傾向がある」という書き方にとどめ、「〜でなければ選ばない」という禁止条件は書かないでください。",
                    "",
                    "【文章のやさしさ（最重要）】",
                    "・これはいずれ、競馬をあまり知らない一般の利用者に公開する文章です。専門家向けの分析メモではありません。",
                    "・想定読者は「競馬を始めたばかりの大人」です。会社の同僚と世間話で競馬の話をするときくらいの、やさしい言葉で書いてください。",
                    "・使ってはいけない言葉の例: 系統的、機能不全、価格発見機能、相対的優位性、本質的、統計的、一般化、構造、要因、閾値、傾向、認識、示唆、要素、環境、パターン、根本的。これらは別のやさしい言い方に置き換えてください。",
                    "・一文は40文字以内を目安に、短く区切ってください。1つの文には1つのことだけを書いてください。",
                    "・「〜という」「〜的な」「〜性」を使った硬い言い回しは避けてください。",
                    "・理由や理屈の説明は最小限にし、「オッズが◯倍のときは、こうする」という結論を先に、具体的な数字とセットで書いてください。",
                    "・書いた後、声に出して読んでスラスラ言えるか、一度自分でチェックしてから出力してください。",
                    "",
                    "【文章の例】",
                    "悪い例: 「オッズが安定的に低位で推移している馬は、市場の合意形成が強固であり信頼度が相対的に高いと考えられる」",
                    "良い例: 「オッズがずっと1.5倍くらいで変わらない馬は、たいてい強い。迷わず選んでいい」",
                    "",
                    "【出力形式】",
                    "このファイルを読んだAIが次のレースですぐ使えるよう、以下の構成で書いてください。",
                    "箇条書きを基本とし、具体的なオッズの数字（何倍くらいか）を必ず入れてください。",
                    "",
                    "## 有力馬を選びやすい目線",
                    "（例: オッズがずっと変わらず安定している馬は、入賞を狙いやすい）",
                    "",
                    "## 見落としやすい馬の特徴",
                    "（例: 10〜20倍帯でオッズが全時間帯を通じてほぼ変わらない馬は、見逃しやすいが入賞することがある）",
                    "",
                    "## 迷ったときの選び方",
                    "（例: オッズが近い馬が何頭かいるときは、より変動が少ない方を選ぶ）",
                    "（例: 本命が絞れているレースと、どの馬も横並びのレースとで、選び方が変わるなら書いてください）",
                    "",
                    "## その他の注意点・補足",
                    "※ レスポンスは各セクション5行程度で簡潔にまとめてください。",
                    "",
                    "---",
                    "",
                    $dataSection,
                ]);

                $loopStartedAt = microtime(true);

                $this->info("[ループ {$batchIndex}/" . ($batchCount - 1) . "] API 送信中... (今回 " . count($batchAnalyses) . " 件 / 一致率 {$hitRate}% ({$batchHits}/{$batchTotal}) / プロンプト " . number_format(mb_strlen($prompt)) . " 文字)");

                $response = $this->anthropic->send($prompt);

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

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
            if ($pid > 0 && posix_kill($pid, 0)) {
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
            $introspectionMeta  = []; // 各エントリの的中数
            $introspectionIds   = [];
            $zeroMovementIds    = [];

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

                // ## 結果 セクションから的中数をカウント
                $hitCount = 0;
                if (str_contains($dbRow->introspection, '## 結果')) {
                    $resultPart = explode('## 結果', $dbRow->introspection, 2)[1] ?? '';
                    if (str_contains($resultPart, '## 分析')) {
                        $resultPart = explode('## 分析', $resultPart, 2)[0];
                    }
                    $hitCount = substr_count($resultPart, '（的中）');
                }

                // 的中タグ + 分析テキストのみ（概念的なパターン抽出が目的のため馬番・馬名は不要）
                $analysisText        = trim(explode('## 分析', $dbRow->introspection, 2)[1]);
                $introspections[]    = "[的中{$hitCount}/3]\n{$analysisText}";
                $introspectionMeta[] = $hitCount;
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
                $status = '対象レコードなし（スキップ）';
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
                $batchHitCounts = array_slice($introspectionMeta, $batchIndex * self::DEVIDE_NUM, self::DEVIDE_NUM);
                $batchHits      = array_sum($batchHitCounts);
                $batchTotal     = count($batchAnalyses) * 3;
                $hitRate        = $batchTotal > 0 ? round($batchHits / $batchTotal * 100, 1) : 0;

                $batchText   = implode("\n\n---\n\n", $batchAnalyses);
                $dataSection = "今回の要約内容（{$batchHits}/{$batchTotal}頭的中・的中率{$hitRate}%）:\n{$batchText}";

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
                    "あなたの仕事は、過去のレース予想の振り返りを読み込み、",
                    "「どのような根拠・パターンで予想が当たったか」",
                    "「どのような根拠・パターンで予想が外れたか」",
                    "を整理・統合して、次のレース予想にそのまま使える判断基準（脳みそ）を作ることです。",
                    "",
                    "【入力の説明】",
                    "・「今回の要約内容」: 今回新たに追加されたレース振り返り（分析テキスト）です。",
                    "  各エントリの冒頭に [的中X/3] というタグがついています。AIが1〜3着に予想した3頭のうち、何頭が実際に的中したかを示します。",
                    "  ・[的中3/3] や [的中2/3] のエントリは「よく当たった例」です。その分析に書かれている考え方を特に重視してください。",
                    "  ・[的中0/3] や [的中1/3] のエントリは「外れた例」です。その分析に書かれている失敗の理由を深く読み解いてください。",
                    "・「保存されている要約内容」または「前回の要約内容」: これまでに積み上げてきた判断基準です。初回はまだ存在しません。",
                    "",
                    "【あなたがやること】",
                    "1. 各エントリの [的中X/3] タグと分析テキストを照合し、当たり・外れのパターンを抽象的な原則として抽出してください。",
                    "   （馬名・馬番など個別の情報ではなく、どんなレースでも使える「考え方・原則」を抽出する）",
                    "2. 「保存されている要約内容」または「前回の要約内容」がある場合は、それと今回の抽出内容をマージして、より精度の高い判断基準に磨き上げてください。",
                    "3. 似た内容は統合し、矛盾する内容は「条件によって使い分ける」形で整理してください。",
                    "4. すべての記述は、次のレース予想の場面でそのまま使える「具体的な行動指針」として書いてください（例: ○倍未満なら1着候補として優先する、オッズ差が○倍以内の場合は変動が小さい方を優先する、など）。",
                    "",
                    "【厳守事項（重要）】",
                    "・「予想は本質的に不可能」「システムが機能停止している」「判定不能」「無作為に選ぶ」など、予想そのものを否定・放棄する記述は禁止です。",
                    "・「〜と認識すべきだ」「〜という誤謬に陥っている」のような反省・自己否定・愚痴のような記述は禁止です。",
                    "・矛盾するデータがある場合でも、必ずどちらか一方を優先する具体的な基準を1つ提示してください。判断基準ゼロの結論は不可とします。",
                    "・すべて言い切り・断定形で記述してください。",
                    "",
                    "【文章のやさしさ（最重要）】",
                    "・これはいずれ、競馬をあまり知らない一般の利用者に公開する文章です。専門家向けの分析メモではありません。",
                    "・想定読者は「競馬を始めたばかりの大人」です。会社の同僚と世間話で競馬の話をするときくらいの、やさしい言葉で書いてください。子供向けの絵本のような言葉にする必要はありません。",
                    "・使ってはいけない言葉の例: 系統的、機能不全、価格発見機能、相対的優位性、本質的、統計的、一般化、構造、要因、閾値、傾向、認識、示唆、要素、環境、パターン、根本的。これらの言葉は別のやさしい言い方に置き換えてください。",
                    "・一文は40文字以内を目安に、短く区切ってください。1つの文には1つのことだけを書いてください。",
                    "・「〜という」「〜的な」「〜性」を使った硬い言い回しは避けてください。",
                    "・理由や理屈の説明は最小限にし、「オッズが◯倍のときは、こうする」という結論を先に、具体的な数字とセットで書いてください。",
                    "・書いた後、声に出して読んでスラスラ言えるか、一度自分でチェックしてから出力してください。",
                    "",
                    "【文章の例】",
                    "悪い例: 「オッズが安定的に低位で推移している馬は、市場の合意形成が強固であり信頼度が相対的に高いと考えられる」",
                    "良い例: 「オッズがずっと1.5倍くらいで変わらない馬は、たいてい強い。信じて1着に選んでいい」",
                    "",
                    "【出力形式】",
                    "以下の構成で、テキストとして出力してください。",
                    "箇条書きを基本とし、具体的なオッズの数字（何倍くらいか）を必ず入れてください。",
                    "",
                    "## 的中につながりやすいパターン",
                    "（例: オッズがずっと変わらず1番人気が安いままの馬は、信じていい）",
                    "",
                    "## 不的中になりやすいパターン",
                    "（例: 人気が集まっているだけで、他の馬と差が小さいときは注意）",
                    "",
                    "## 複数馬が似た条件の場合の選別基準",
                    "（例: オッズが近い馬が何頭かいるときは、より安くて、動きが少ない方を選ぶ）",
                    "",
                    "## その他の注意点・補足",
                    "",
                    "※ レスポンスは各セクション5行程度で簡潔にまとめてください。",
                    "",
                    "---",
                    "",
                    $dataSection,
                ]);

                $loopStartedAt = microtime(true);

                $this->info("[ループ {$batchIndex}/" . ($batchCount - 1) . "] API 送信中... (今回 " . count($batchAnalyses) . " 件 / 的中率 {$hitRate}% ({$batchHits}/{$batchTotal}) / プロンプト " . number_format(mb_strlen($prompt)) . " 文字)");

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

            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "SummaryMakeBaganrikiBrain::handle\n{$status}\nループ:{$totalLoops}回、失敗:{$totalFailed}回、finish更新:{$totalFinished}件"
            );
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

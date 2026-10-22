<?php

namespace App\Jobs;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261021-R122-JOBBASE  4本のJobで共通に使うもの
 * ═══════════════════════════════════════════════════════════════════════════
 * 10月21日010（なる）の第2章への実装です。
 *
 * ■ この環境に合わせたこと
 *   このサーバは <b>Laravel 8.83.29 ／ PHP 8.0.30</b> です。
 *   なるの引用は Laravel 12.x の worker 仕様なので、8.x で在るものだけを
 *   使っています。使っていないもの（8.x に無い・8.0 で書けない）は
 *     ・readonly プロパティ（PHP 8.1 から）
 *     ・enum（PHP 8.1 から）
 *     ・第一級callable記法（PHP 8.1 から）
 *   です。Bus::batch と ShouldBeUnique は Laravel 8.0 から在るので使えます。
 *
 * ■ 接続と待ち行列
 *   既定の QUEUE_CONNECTION は <b>sync のまま触りません。</b>
 *   アプリの他の場所の動きを変えたくないので、このJobだけが
 *   `r122` という専用の接続（driver=database）を名指しで使います。
 *
 *   待ち行列は4本に分けます（010の2-1「AIを待つworkerと、期限判定・統合を
 *   行うworkerを分離する」）。
 *     r122-freeze   入力凍結
 *     r122-ai-1st   1st のAI待ち
 *     r122-ai-2nd   2nd のAI待ち
 *     r122-agg      期限判定・統合・公開・実状態の変化
 *
 * ■ 秒数の整合（010の2-1 末尾）
 *   「Jobの上限をHTTP処理の途中で先に迎えないこと、workerの上限より
 *     retry_after を十分長くすること」
 *
 *   時刻の契約（発走を T とします）
 *     cutoff        T−360秒
 *     入力凍結      T−330秒
 *     応答期限      T−150秒   ← AIの返事はここまで
 *     公開期限      T−120秒
 *     投票締切      T− 60秒
 *   入力凍結から応答期限までは <b>180秒</b>です。
 *
 *   1本の RunRoleJob に初回と訂正の<b>両方</b>を入れます。
 *   分けると待ち行列をもう一度通るので、180秒のなかでは間に合いません。
 *     AnthropicService の HTTP 上限   120秒
 *     RunRoleJob の上限               175秒（180秒の窓の内側）
 *     worker の --timeout             180秒（Jobの上限以上）
 *     接続の retry_after              300秒（workerの上限より十分長い）
 *   RunRoleJob は <b>$tries = 1</b> です。やり直すと外部APIへ二度送る
 *   おそれがあるためです。送信権（reserve→allowSend の token）が
 *   二重送信を止めますが、Job の側でも試行を1回に固定します。
 *   これで「worker再実行と外部API再送」を分けます（010の2-5）。
 */
abstract class R122Job
{
    /** 専用の接続名（config/queue.php に足します） */
    public const CONN = 'r122';

    /** 待ち行列の名前 */
    public const Q_FREEZE = 'r122-freeze';
    public const Q_AI_1ST = 'r122-ai-1st';
    public const Q_AI_2ND = 'r122-ai-2nd';
    public const Q_AGG    = 'r122-agg';

    /** role から待ち行列を決めます */
    public static function queueForRole(string $role): string
    {
        return ($role === '2nd') ? self::Q_AI_2ND : self::Q_AI_1ST;
    }

    /**
     * Job の段が上がっているか。
     *
     *   .env の BAGANRIKI_R122_JOBS が 1 のときだけ Job 構成で動きます。
     *   既定は 0 です。<b>0 のあいだ、いまの同期の経路は1バイトも
     *   変わりません。</b>（010の2-5「旗による切替を残す」）
     */
    public static function enabled(): bool
    {
        return \App\Http\Controllers\Api\BaganrikiWorker::jobs();
    }

    /**
     * 待ち行列の表があるか。
     *   無い環境で dispatch すると例外になるので、呼ぶ前に見ます。
     */
    public static function ready(): bool
    {
        try {
            return \Schema::hasTable('jobs');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 例外も記録も、商品（旧来の予想）を止めません */
    protected function note(string $tag, array $ctx): void
    {
        try {
            \Log::error('[R122-JOB] ' . $tag, $ctx);
        } catch (\Throwable $e) {
            // 記録で落ちても何もしません
        }
    }
}

<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

// AiController.php と同じ PHP 8.0 用の array_is_list（このファイル単独で読み込まれても動くように同じものを置く）
// @ANCHOR-20261023-NARU-011  PHP 8.0 には array_is_list が無いので、この名前空間に用意します
//   （011再精査版の直しが array_is_list を使うため。本番は PHP 8.0.30）
if (!function_exists(__NAMESPACE__ . '\\array_is_list')) {
    function array_is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) { return false; }
        }
        return true;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-PREDICTION-QUEUE  永続ワーカー（キューと予約枠）
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 10章・11章／詳細設計書 12章
 *
 * ■ 守ること
 *   ・1レース・1世代・1roleにつき枠は2つだけ（初回=0／訂正=1）
 *   ・予約を確定してから送る。初回の原応答と complete を保存してから訂正に入る
 *   ・予約済みの枠は、重複キュー・期限切れ・再起動のどれでも作り直さない
 *   ・初回が未受領・FAILED・結果不明なら訂正を予約しない
 *   ・送信許可1回につき callback は1回だけ（自動再試行は0）
 *   ・同じ token に同じ応答を保存するのは冪等。別の応答や終端の付け替えは拒否
 *   ・complete=false の本文は、そのまま残して選出には使わない
 *   ・response_revision は「予約の回数＋終端が変わった回数」の合計
 *
 * ■ 枠の状態
 *   RESERVED       枠を取った（まだ送っていない）
 *   SENT           送った（返りを待っている）
 *   RECEIVED       受け取って保存した
 *   FAILED         失敗（終端）
 *   UNKNOWN_RESULT 結果が分からない（終端・再送しない）
 *
 * ■ 再起動したとき
 *   RESERVED は枠として残します（まだ送っていないので送信許可が1回残る）。
 *   SENT は UNKNOWN_RESULT にします。受領していても未commitの応答は
 *   復元できないことがあるので、結果不明として明示し、再送を止めます。
 *
 * 外部APIとの分散取引や Exactly Once は保証しません。
 */
final class BaganrikiWorker
{
    public const FIRST = 0;        // 初回
    public const FIX   = 1;        // 訂正
    public const MAX_PER_ROLE = 2; // 枠は2つだけ

    public const RESERVED = 'RESERVED';
    public const SENT     = 'SENT';
    public const RECEIVED = 'RECEIVED';
    public const FAILED   = 'FAILED';
    public const UNKNOWN  = 'UNKNOWN_RESULT';

    /** 終端（もう動かさない状態） */
    public const TERMINAL = [self::RECEIVED, self::FAILED, self::UNKNOWN];

    private const US = 1000000;

    /**
     * @ANCHOR-20261020-PREDICTION-SHADOW  .env の BAGANRIKI_PREDICTION_QUEUE は3つの値を取ります
     *   0       … 何もしません（既定）。送信口を素通りします。
     *   shadow  … <b>記録はしますが、送信を止めません。</b>
     *             予約・凍結・原応答の保存まで本番と同じことをして、
     *             うまくいかなかったときも<b>AIの呼び出しは止めません</b>。
     *             初めて実物で動かす日に、いまの商品を落とさずに
     *             実測だけを取るための段です。
     *   1       … 契約どおりに止めます（枠が取れなければ送りません）。
     */
    public static function mode(): string
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_QUEUE', '0')));
        if (in_array($v, ['1', 'true', 'on', 'yes'], true)) { return 'on'; }
        if (in_array($v, ['shadow', 'record', 'dry'], true)) { return 'shadow'; }
        return 'off';
    }

    /** 記録の仕組みを動かすか（shadow と on） */
    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }

    /** うまくいかなかったときに送信を止めるか（on だけ） */
    public static function blocking(): bool
    {
        return self::mode() === 'on';
    }

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261021-PREDICTION-JOBFLAG  Job構成の段（章）
    // ══════════════════════════════════════════════════════════════════════
    //   BAGANRIKI_PREDICTION_JOBS=1 のときだけ、待ち行列（app/Jobs の4本）で
    //   動きます。既定は 0 です。<b>0 のあいだ、いまの同期の経路は
    //   1バイトも変わりません。</b>（010の2-5「旗による切替を残す」）
    //
    //   枠の段（BAGANRIKI_PREDICTION_QUEUE）とは別の旗にします。
    //   枠の段は「予約と送信権の仕組みを動かすか」、
    //   Jobの段は「それを待ち行列で動かすか」で、意味が違います。
    //   Jobで動かすには枠の段も上がっている必要があります。

    /** Job構成で動かすか */
    public static function jobs(): bool
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_JOBS', '0')));
        if (!in_array($v, ['1', 'true', 'on', 'yes'], true)) { return false; }
        // 枠の段が上がっていなければ、Jobでも送りません
        return self::mode() === 'on';
    }

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261021-PREDICTION-TARGETFLAG  対象レースの限定を効かせるか
    // ══════════════════════════════════════════════════════════════════════
    //   BAGANRIKI_PREDICTION_TARGETS_ENFORCE=1 のとき、
    //   BAGANRIKI_PREDICTION_TARGETS に並べた race_id 以外へは1レースも送りません。
    //   <b>Jobの段が上がっているときは、設定に関わらず効かせます。</b>
    //   10月24日の非公開検証で、12レース以外へ送らないためです（010の2-5）。
    public static function targetsEnforced(): bool
    {
        if (self::jobs()) { return true; }
        // @ANCHOR-20261023-PREDICTION-SEPARATE  枠の段（QUEUE）が上がっていれば必ず限定します
        //   新経路の初回送信を PROMPT=shadow でも動くようにしたので（003 P-01）、
        //   QUEUE を上げただけで TARGETS を入れ忘れると、開かれたレースすべてへ
        //   送ってしまいます。「空なら1レースも送らない」に倒します。
        if (self::enabled()) { return true; }
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_TARGETS_ENFORCE', '0')));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /** 必要な表がそろっているか */
    public static function ready(): bool
    {
        foreach (['baganriki_snapshots', 'baganriki_ai_attempts',
                  'baganriki_live_states', 'baganriki_audit_events'] as $t) {
            if (!\Schema::hasTable($t)) { return false; }
        }
        return true;
    }

    // ── 時刻 ────────────────────────────────────────────────────────────

    /** 試験用の時計（artisan からの試験のときだけ。本番では常に null）。保存済みの過去レースを「その日の凍結時刻」で作り直すため */
    public static ?int $testNowUs = null;
    private static ?int $testNowSetAt = null;

    public static function setTestNow(?int $us): void
    {
        self::$testNowUs = $us;
        $t = gettimeofday();
        self::$testNowSetAt = ($us === null) ? null : $t['sec'] * self::US + $t['usec'];
    }

    public static function nowUs(): int
    {
        $t = gettimeofday();
        $real = $t['sec'] * self::US + $t['usec'];
        if (self::$testNowUs !== null && function_exists('app') && app()->runningInConsole()) {
            return self::$testNowUs + ($real - (int) self::$testNowSetAt);   // 試験の時計からの経過分だけ進める
        }
        return $real;
    }

    /** マイクロ秒 → DATETIME(6)（日本時間） */
    public static function toDb(int $us): string
    {
        $d = (new \DateTimeImmutable('@' . intdiv($us, self::US)))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $f = $us % self::US;
        if ($f < 0) { $f += self::US; }
        return $d->format('Y-m-d H:i:s') . sprintf('.%06d', $f);
    }

    /** DATETIME(6) → マイクロ秒。マイクロ秒を落としません */
    public static function dbUs($v): ?int
    {
        if ($v === null) { return null; }
        if ($v instanceof \DateTimeInterface) {
            return $v->getTimestamp() * self::US + (int) $v->format('u');
        }
        // @ANCHOR-20261023-NARU-011  入力側と同じ厳密な DATETIME(6) の境界で読みます（011再精査8版）
        //   DateTime の繰り上がり（2026-02-30 など）を正しい日時として受け取りません。
        return BaganrikiTime::us(AiController::dbTimeToRfc3339($v));
        /* 旧
        $s = trim((string) $v);
        if ($s === '' || $s === '0000-00-00 00:00:00') { return null; }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/',
                        $s, $m)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s', $m[1] . ' ' . $m[2], new \DateTimeZone('Asia/Tokyo'));
        if ($d === false) { return null; }
        $frac = isset($m[3]) ? (int) str_pad($m[3], 6, '0') : 0;
        return $d->getTimestamp() * self::US + $frac;
        */
    }

    private static function j($v): string
    {
        return (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    // ── 監査 ────────────────────────────────────────────────────────────

    public static function event(string $raceId, ?int $snapshotId, string $kind,
                                 ?int $old, ?int $new, array $diag,
                                 ?string $related, int $nowUs): void
    {
        \DB::table('baganriki_audit_events')->insert([
            'race_id'      => $raceId,
            'snapshot_id'  => $snapshotId,
            'kind'         => $kind,
            'happened_at'  => self::toDb($nowUs),
            'old_revision' => $old,
            'new_revision' => $new,
            'diagnostics'  => self::j($diag),
            'related_id'   => $related,
        ]);
    }

    // ── ライブ状態 ───────────────────────────────────────────────────────

    /**
     * ライブ状態の行が無ければ作ります（あれば何もしません）。
     * 凍結のときに作られていない古いレースでも、ここで追いつきます。
     */
    public static function ensureLive(int $snapshotId, int $nowUs): void
    {
        $has = \DB::table('baganriki_live_states')
                 ->where('snapshot_id', $snapshotId)->exists();
        if ($has) { return; }
        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        // E3・E5版のライブ行は凍結時に作る。欠落時に正式取消を空として再作成しない。
        if ($snap === null || self::isExpectedSnapshot($snap)) { return; }
        $frozen = json_decode((string) $snap->frozen_nums, true);
        \DB::table('baganriki_live_states')->insertOrIgnore([
            'snapshot_id'          => $snapshotId,
            'cancellations'        => self::j([]),
            'active_count'         => is_array($frozen) ? count($frozen) : null,
            'live_target_count'    => $snap->target_count,
            'live_quota'           => $snap->quota_1st,
            'live_close'           => null,
            'state_revision'       => 0,
            'response_revision'    => 0,
            'publication_revision' => 0,
            'latest_publication_id' => null,
            'updated_at'           => self::toDb($nowUs),
        ]);
    }

    /**
     * @ANCHOR-20261023-NARU-RACELOCK  $lockRace=true のときは、レース正本の行を FOR UPDATE で読みます
     *   （実状態の行をロックしたトランザクションの中から呼ぶとき）。発走時刻を書き換える処理
     *   （keiba:repairStartTime の UPDATE）は、このトランザクションが終わるまで待ちます。
     *   先に書き換えが確定していれば、FOR UPDATE は確定した最新の値を読みます。
     *   どちらの順でも、保存・送信の最終確認が古い発走時刻を使うことはありません。
     * @ANCHOR-20261023-NARU-STARTREAD  正本を読めないとき（DBの例外・時刻の形が読めない）は
     *   「変更なし」と区別し、期限を出しません（START_TIME_UNREADABLE）。送信・公開は止まります。
     *   発走済みの印（XXX）と、正本にレースの行が無いとき（試験の凍結入力など）は、読み飛ばします。
     *   → 20261025 で変更：XXX は RACE_STARTED、実在の場コードで行が無ければ RACE_ROW_MISSING で止めます（NARU-STARTED）。
     */
    public static function deadlines(object $snap, ?object $live, bool $lockRace = false,
                                     bool $forLiveAction = false): array
    {
        if (self::isExpectedSnapshot($snap)) {
            try {
                $sales = BaganrikiPublishedRead::frozenSales($snap);
            } catch (\InvalidArgumentException $invalid) { $sales = null; }
        } else {
            $sales = self::dbUs($snap->sales_close_at ?? null);
        }
        if ($sales === null) {
            return ['ok' => false, 'code' => 'TIME_UNCOMPUTABLE',
                    'reason' => '凍結した投票締切を照合できないので期限を出せません',
                    'reply' => null, 'publish' => null, 'sales' => null];
        }
        $liveValue = $live->live_close ?? null;
        $liveClose = self::dbUs($liveValue);
        // A recorded but unreadable change is not evidence that no change exists.
        // Do not authorize work against the potentially later frozen deadline.
        if ($liveValue !== null && $liveClose === null) {
            return ['ok' => false, 'code' => 'TIME_UNCOMPUTABLE',
                    'reason' => '変更された投票締切を読めないので実効期限を出せません',
                    'reply' => null, 'publish' => null, 'sales' => $sales,
                    'live_close' => null];
        }
        // @ANCHOR-20261023-NARU-STARTTIME  レースの発走時刻（正本）を、毎回その場で読みます（なる 10/23 2通目 3章）
        //   【旧】live_close（実状態）だけを見ていました。実状態へ入るのはオッズ取得に成功したときだけ
        //     だったので、取得対象外の時間帯（残り2分など）や取得の失敗では、前倒しされた発走時刻が
        //     届かず、古い期限で送信・公開を判定し得ました。
        //   【いま】t_horse_odds_finder_races.start_time から投票締切を出し、凍結時の締切・live_close より
        //     早ければ、それを使います（延ばす方向には使いません）。実状態へ書くのは巡回と発走時刻の
        //     更新処理（keiba:repairStartTime）ですが、書かれる前でも、ここで必ず早いほうを使います。
        $rs = self::raceStartClose($snap, $lockRace);
        if ($rs['state'] === 'ERROR' || $rs['state'] === 'UNREADABLE') {
            return ['ok' => false, 'code' => 'START_TIME_UNREADABLE',
                    'reason' => 'レース正本の発走時刻を確かめられないので実効期限を出せません（' . (string) $rs['reason'] . '）',
                    'reply' => null, 'publish' => null, 'sales' => $sales, 'live_close' => $liveClose];
        }
        // ── @ANCHOR-20261025-NARU-STARTED  発走済みの印（XXX）と正本の行の欠落でも止めます（なる 10/23 5通目 5章）──
        //   【旧】STARTED と NO_ROW は「前倒しなし」と同じに扱い、凍結時の締切・live_close から期限を出していました。
        //     前倒しされた発走のあとに XXX へ変わっていた場合、古い（遅い）期限が未来に残り、送信・公開を止められませんでした。
        //   【いま】
        //     STARTED … 業務上の終わり（発走した）。新しい送信・公開はしません。code は RACE_STARTED。
        //               DBの障害（START_TIME_UNREADABLE）とは code を分けて記録します。
        //     NO_ROW  … 実在の場コード（01〜10）なら止めます（RACE_ROW_MISSING）。
        //               読み飛ばすのは、試験用の場コード 90〜99（isTestRaceId）の凍結入力だけです。
        //     NOT_APPLICABLE（race_id がレースの形でない）… 本番の race_id は buildPredictionRaceId が必ず
        //               5つの部分で作るので、本番では起きません。試験の凍結入力だけが通ります。
        if ($rs['state'] === 'STARTED') {
            return ['ok' => false, 'code' => 'RACE_STARTED',
                    'reason' => 'レース正本が発走済み（XXX）なので、新しい送信・公開はしません',
                    'race_started' => true, 'reply' => null, 'publish' => null, 'sales' => $sales, 'live_close' => $liveClose];
        }
        if ($rs['state'] === 'NO_ROW' && !AiController::isTestRaceId((string) ($snap->race_id ?? ''))) {
            return ['ok' => false, 'code' => 'RACE_ROW_MISSING',
                    'reason' => 'レース正本に行が無いので実効期限を出せません',
                    'reply' => null, 'publish' => null, 'sales' => $sales, 'live_close' => $liveClose];
        }
        $raceStarted = null;
        if ($rs['state'] === 'STARTED') {
            $raceStarted = true;
        } elseif ($rs['state'] === 'EARLIER' || $rs['state'] === 'UNCHANGED') {
            $raceStarted = false;
        }
        if ($forLiveAction && ($raceStarted === true || ($raceStarted === null
            && self::isExpectedSnapshot($snap)))) {
            return ['ok' => false, 'code' => $raceStarted ? 'RACE_STARTED' : 'RACE_STATUS_UNAVAILABLE',
                    'reason' => $raceStarted ? '発走済みです' : '正本の発走状態を確認できません',
                    'race_started' => $raceStarted, 'reply' => null, 'publish' => null,
                    'sales' => $sales, 'live_close' => $liveClose];
        }
        $raceClose = ($rs['state'] === 'EARLIER') ? $rs['close'] : null;
        $startTimeUsed = false;
        if ($raceClose !== null && $raceClose < $sales
            && ($liveClose === null || $raceClose < $liveClose)) {
            $liveClose = $raceClose;
            $startTimeUsed = true;
        }
        $d = BaganrikiTime::effectiveDeadlines($sales, $liveClose);
        return ['ok' => true, 'code' => null, 'reason' => null,
                'reply' => $d['reply'], 'publish' => $d['publish'], 'sales' => $sales,
                'live_close' => $liveClose, 'start_time_used' => $startTimeUsed, 'race_started' => $raceStarted];
    }

    public static function isExpectedSnapshot(object $snap): bool
    {
        return class_exists(BaganrikiCandidateSelection::class)
            && BaganrikiCandidateSelection::isExpected($snap->release_version ?? null);
    }

    /** 凍結馬番・累積取消・残頭数を同じ読取りから照合する。欠損を空集合にしない。 */
    public static function verifiedLiveRoster(object $snap, ?object $live, bool $verifyAudit = true, ?string &$issue = null): ?array
    {
        $issue = 'LIVE_STATE_UNAVAILABLE';
        $count = $live->active_count ?? null;
        if (!is_int($count) && !is_string($count)) { return null; }
        $nums = $snap->frozen_nums ?? null;
        $gone = $live->cancellations ?? null;
        if (is_string($nums)) { $nums = json_decode($nums); }
        if (is_string($gone)) { $gone = json_decode($gone); }
        if (!is_array($nums) || !array_is_list($nums) || !is_array($gone) || !array_is_list($gone)) { return null; }
        try {
            $roster = BaganrikiAttemptWindow::horseSet($nums, range(1, 18));
            $withdrawn = BaganrikiAttemptWindow::horseSet($gone, $roster);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        if ((string) (count($roster) - count($withdrawn)) !== (string) $count) { return null; }
        $result = ['roster' => $roster, 'withdrawn' => $withdrawn];
        if ($verifyAudit) {
            try {
                BaganrikiPublishedRead::checkHistory($snap, $live, $result);
            } catch (\InvalidArgumentException $invalid) {
                if ($invalid->getMessage() === 'TIME_UNCOMPUTABLE') { $issue = 'TIME_UNCOMPUTABLE'; }
                return null;
            }
        }
        $issue = null;
        return $result;
    }

    public static function liveStateFailure(string $issue): array
    {
        return self::fin(false, $issue, $issue === 'TIME_UNCOMPUTABLE'
            ? '凍結時の締切・正式な前倒し履歴・保存時刻を照合できません'
            : '凍結馬番・正式取消・残頭数を照合できません');
    }

    /** 正式取消または締切の正本と照合できない場合は新しい送受信を保留する。 */
    public static function sendRosterIssue(object $snap, ?object $live): ?array
    {
        $count = $live->active_count ?? null;
        if (self::isExpectedSnapshot($snap) && self::verifiedLiveRoster($snap, $live, true, $issue) === null) {
            return self::liveStateFailure($issue);
        }
        if ($count !== null && (int) $count === 0) {
            return self::fin(false, 'ALL_CANCELLED', '全頭が取消になっています');
        }
        return null;
    }

    /**
     * @ANCHOR-20261023-NARU-STARTTIME  いまのレース正本の発走時刻から出した投票締切（マイクロ秒）
     *   凍結時の締切より1秒以上早いときだけ値を返します。それ以外は null。
     *   （読めない場合の区別が要るところは raceStartClose を使います）
     */
    public static function closeFromRaceStartTime(object $snap): ?int
    {
        $r = self::raceStartClose($snap, false);
        return ($r['state'] === 'EARLIER') ? $r['close'] : null;
    }

    /**
     * @ANCHOR-20261023-NARU-STARTREAD  レース正本の発走時刻を読み、状態を分けて返します
     * @return array ['state','close','reason']
     *   state … NOT_APPLICABLE（race_id がレースの形でない）／NO_ROW（正本に行が無い）／
     *           STARTED（発走済みの印 XXX）／UNCHANGED（前倒しでない）／EARLIER（1秒以上早い）／
     *           UNREADABLE（時刻の形が読めない・空）／ERROR（DBの例外）
     */
    public static function raceStartClose(object $snap, bool $lock = false): array
    {
        $p = explode('-', (string) ($snap->race_id ?? ''));
        if (count($p) !== 5 || !preg_match('/^\d{8}$/', $p[0])) {
            return ['state' => 'NOT_APPLICABLE', 'close' => null, 'reason' => 'race_id がレースの形ではありません'];
        }
        $date = substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2);
        try {
            $q = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                   ->where('kaisuu', $p[1])->where('basho', $p[2])->where('day', $p[3])
                   ->where('race', (int) $p[4]);
            if ($lock) { $q->lockForUpdate(); }
            $r = $q->first(['start_time']);
        } catch (\Throwable $e) {
            return ['state' => 'ERROR', 'close' => null, 'reason' => 'DBの例外：' . $e->getMessage()];
        }
        if ($r === null) { return ['state' => 'NO_ROW', 'close' => null, 'reason' => '正本にレースの行がありません']; }
        $st = trim((string) ($r->start_time ?? ''));
        if ($st === 'XXX') { return ['state' => 'STARTED', 'close' => null, 'reason' => '発走済みの印です']; }
        if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $st)) {
            return ['state' => 'UNREADABLE', 'close' => null, 'reason' => '発走時刻の形が読めません: ' . $st];
        }
        $st = BaganrikiTime::normalizeRaceStartClock($st);
        $anchor = BaganrikiTime::us($date . 'T' . $st . '+09:00');
        $close  = ($anchor === null) ? null : BaganrikiTime::salesCloseFromPost($anchor);
        $sales  = self::dbUs($snap->sales_close_at ?? null);
        if ($close === null || $sales === null) {
            return ['state' => 'UNREADABLE', 'close' => null, 'reason' => '締切を計算できません'];
        }
        return ($close <= $sales - 1000000)
            ? ['state' => 'EARLIER', 'close' => $close, 'reason' => null]
            : ['state' => 'UNCHANGED', 'close' => null, 'reason' => null];
    }

    // ── 枠の読み取り（token と原文は返しません）──────────────────────────

    public static function slots(int $snapshotId, ?string $role = null): array
    {
        $q = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $snapshotId);
        if ($role !== null) { $q->where('role', $role); }
        $out = [];
        foreach ($q->orderBy('role')->orderBy('attempt_no')->get() as $r) {
            $out[] = [
                'role'        => (string) $r->role,
                'attempt_no'  => (int) $r->attempt_no,
                'status'      => (string) $r->status,
                'complete'    => ((int) $r->complete === 1),
                'reserved_at' => $r->reserved_at,
                'received_at' => $r->received_at,
                'response_hash'  => $r->response_hash,
                'failure_reason' => $r->failure_reason,
            ];
        }
        return $out;
    }

    /** そのroleの枠を attempt_no をキーにして返します（内部用） */
    private static function rowsOf(int $snapshotId, string $role): array
    {
        $out = [];
        foreach (\DB::table('baganriki_ai_attempts')
                    ->where('snapshot_id', $snapshotId)->where('role', $role)
                    ->get() as $r) {
            $out[(int) $r->attempt_no] = $r;
        }
        return $out;
    }

    private static function fin(bool $ok, string $code, string $reason, array $add = []): array
    {
        return array_merge(['ok' => $ok, 'code' => $code, 'reason' => $reason], $add);
    }

    // ── 訂正を予約してよいか（書き込みません）────────────────────────────

    /**
     * 「初回の原応答と complete を保存してから訂正に入る」の判定だけをします。
     * 再起動の後でもここから訂正判定を再開できます。
     */
    public static function fixAllowed(int $snapshotId, string $role): array
    {
        $rows = self::rowsOf($snapshotId, $role);
        if (!isset($rows[self::FIRST])) {
            return self::fin(false, 'FIRST_NOT_RESERVED', '初回の枠がまだありません');
        }
        $f = $rows[self::FIRST];
        $st = (string) $f->status;
        if ($st === self::FAILED || $st === self::UNKNOWN) {
            return self::fin(false, 'FIRST_FAILED',
                '初回が' . $st . 'なので訂正は予約しません');
        }
        if ($st !== self::RECEIVED) {
            return self::fin(false, 'FIRST_NOT_RECEIVED',
                '初回が未受領（' . $st . '）なので訂正は予約しません');
        }
        if (!BaganrikiAttemptWindow::isComplete($f->complete ?? null)) {
            return self::fin(false, 'FIRST_NOT_COMPLETE',
                '初回が complete=false なので訂正は予約しません');
        }
        if (isset($rows[self::FIX])) {
            return self::fin(false, 'SLOT_TAKEN',
                '訂正の枠はすでにあります（' . $rows[self::FIX]->status . '）',
                ['status' => (string) $rows[self::FIX]->status]);
        }
        return self::fin(true, 'OK', '訂正を予約できます');
    }

    // ── 予約 ────────────────────────────────────────────────────────────

    /**
     * 枠を1つ予約します。予約が確定してから送ってください。
     *
     * @param array $expect  版の見張り。
     *                       ['state_revision'=>int, 'response_revision'=>int]
     *                       を渡すと、予約の直前に変わっていれば STATE_CHANGED で
     *                       予約しません（契約11章）。
     */
    public static function reserve(int $snapshotId, string $role, int $attemptNo,
                                   int $nowUs, array $expect = []): array
    {
        if ($attemptNo !== self::FIRST && $attemptNo !== self::FIX) {
            return self::fin(false, 'INVALID_ATTEMPT',
                'attempt_no は 0（初回）か 1（訂正）だけです');
        }
        self::ensureLive($snapshotId, $nowUs);
        try {
            return \DB::transaction(function () use ($snapshotId, $role, $attemptNo,
                                                     $nowUs, $expect) {
                $live = \DB::table('baganriki_live_states')
                          ->where('snapshot_id', $snapshotId)->lockForUpdate()->first();
                $snap = \DB::table('baganriki_snapshots')
                          ->where('id', $snapshotId)->first();
                if ($snap === null) {
                    return self::fin(false, 'NO_SNAPSHOT', '凍結入力がありません');
                }
                $raceId = (string) $snap->race_id;

                // ① 版の見張り（予約の直前にもう一度見ます）
                if ($live !== null) {
                    foreach (['state_revision', 'response_revision',
                              'publication_revision'] as $k) {
                        if (array_key_exists($k, $expect)
                            && (int) $expect[$k] !== (int) $live->{$k}) {
                            return self::fin(false, 'STATE_CHANGED',
                                $k . 'が変わりました（期待 ' . $expect[$k]
                                . '・現在 ' . $live->{$k} . '）');
                        }
                    }
                }

                // ② 全頭取消なら予約しません
                $issue = self::sendRosterIssue($snap, $live);
                if ($issue !== null) { return $issue; }

                // ③ 期限（同時刻は可、1マイクロ秒でも超えたら不可）
                // @ANCHOR-20261023-NARU-RACELOCK  ロックした実状態の中で、レース正本の発走時刻も FOR UPDATE で読みます（なる 10/23 4通目 4章）
                $dl = self::deadlines($snap, $live, true, true);
                if (!$dl['ok']) {
                    return self::fin(false, (string) $dl['code'], (string) $dl['reason']);
                }
                if ($nowUs > $dl['reply']) {
                    return self::fin(false, 'DEADLINE_PASSED',
                        '実効の応答期限を超えています（期限 '
                        . BaganrikiTime::toJst((int) $dl['reply']) . '・現在 '
                        . BaganrikiTime::toJst($nowUs) . '）');
                }

                // ④ 枠の数と順番
                $rows = self::rowsOf($snapshotId, $role);
                if (isset($rows[$attemptNo])) {
                    return self::fin(false, 'SLOT_TAKEN',
                        'その枠はすでにあります（' . $rows[$attemptNo]->status
                        . '）。作り直しません',
                        ['status' => (string) $rows[$attemptNo]->status]);
                }
                if (count($rows) >= self::MAX_PER_ROLE) {
                    return self::fin(false, 'NO_SLOT', '枠は2つまでです');
                }
                if ($attemptNo === self::FIX) {
                    $gate = self::fixAllowed($snapshotId, $role);
                    if (!$gate['ok']) { return $gate; }
                    // 訂正の予約は、初回の受領時刻を下回る現在時刻ではしません
                    $firstAt = self::dbUs($rows[self::FIRST]->received_at ?? null);
                    if ($firstAt !== null && $nowUs < $firstAt) {
                        return self::fin(false, 'CLOCK_BACKWARD',
                            '現在時刻が初回の受領時刻より前です');
                    }
                }

                // ⑤ 予約します
                $token = hash('sha256', $snapshotId . '|' . $role . '|' . $attemptNo
                                        . '|' . $nowUs . '|' . bin2hex(random_bytes(16)));
                \DB::table('baganriki_ai_attempts')->insert([
                    'snapshot_id'    => $snapshotId,
                    'role'           => $role,
                    'attempt_no'     => $attemptNo,
                    'token'          => $token,
                    'status'         => self::RESERVED,
                    'reserved_at'    => self::toDb($nowUs),
                    'received_at'    => null,
                    'complete'       => 0,
                    'raw_response'   => null,
                    'response_hash'  => null,
                    'failure_reason' => null,
                ]);

                $old = ($live === null) ? null : (int) $live->response_revision;
                $new = ($old === null) ? null : ($old + 1);
                if ($live !== null) {
                    \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $snapshotId)
                      ->update(['response_revision' => $new,
                                'updated_at' => self::toDb($nowUs)]);
                }
                self::event($raceId, $snapshotId, 'QUEUE_RESERVED', $old, $new,
                    ['role' => $role, 'attempt_no' => $attemptNo,
                     'reply_deadline' => BaganrikiTime::toJst((int) $dl['reply'])],
                    $role . '#' . $attemptNo, $nowUs);

                return self::fin(true, 'OK', '予約しました',
                    ['token' => $token, 'role' => $role, 'attempt_no' => $attemptNo,
                     'response_revision' => $new,
                     'reply_deadline' => BaganrikiTime::toJst((int) $dl['reply'])]);
            });
        } catch (\Throwable $e) {
            // uq_attempt / uq_token に当たった＝ほかの処理が同じ枠を取りました。
            //   PDOException でも QueryException でも拾えるようにしています。
            if (self::isDup($e)) {
                return self::fin(false, 'SLOT_TAKEN',
                    'ほかの処理が同じ枠を取りました。作り直しません');
            }
            throw $e;
        }
    }

    public static function isDup(\Throwable $e): bool
    {
        // SQLSTATE 23000 includes NOT NULL and foreign-key violations.
        // Only MySQL's duplicate-entry vendor code is an occupied slot.
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
                return (string) $cause->errorInfo[1] === '1062';
            }
        }
        // Compatibility for wrappers that did not retain PDO errorInfo.
        return preg_match('/(?:^|:\s)(?:1062\s+)?Duplicate entry\b/i', $e->getMessage()) === 1;
    }

    // ── 送信許可（callbackは1回だけ）─────────────────────────────────────

    /**
     * 送る直前に呼びます。RESERVED のときだけ1回だけ通ります。
     * 自動再試行は0なので、ここを2回通すことはできません。
     */
    public static function allowSend(string $token, int $nowUs): array
    {
        return \DB::transaction(function () use ($token, $nowUs) {
            $pre = \DB::table('baganriki_ai_attempts')
                     ->where('token', $token)->first(['snapshot_id']);
            if ($pre === null) {
                return self::fin(false, 'NO_TOKEN', 'その予約はありません');
            }
            // @ANCHOR-20261023-NARU-011  ロックで読んだ最新の live 行を使います（011再精査2版）
            $liveLocked = \DB::table('baganriki_live_states')
              ->where('snapshot_id', $pre->snapshot_id)->lockForUpdate()->first();
            $a = \DB::table('baganriki_ai_attempts')
                   ->where('token', $token)->lockForUpdate()->first();
            if ($a === null) {
                return self::fin(false, 'NO_TOKEN', 'その予約はありません');
            }
            $st = (string) $a->status;
            if ($st === self::SENT) {
                return self::fin(false, 'ALREADY_SENT',
                    'すでに送信許可を出しています。再送しません');
            }
            if (in_array($st, self::TERMINAL, true)) {
                return self::fin(false, 'TERMINAL',
                    'すでに終わっています（' . $st . '）');
            }
            // @ANCHOR-20261023-NARU-011  送信権を取れるのは RESERVED だけ。全頭取消なら送らない（011再精査版）
            if ($st !== self::RESERVED) {
                return self::fin(false, 'INVALID_STATUS', '予約中の枠だけが送信許可を取れます（' . $st . '）');
            }
            $snap = \DB::table('baganriki_snapshots')
                      ->where('id', $a->snapshot_id)->first();
            $live = $liveLocked;
            if ($snap === null) {
                return self::fin(false, 'NO_SNAPSHOT', '凍結入力がありません');
            }
            $issue = self::sendRosterIssue($snap, $live);
            if ($issue !== null) { return $issue; }
            // @ANCHOR-20261023-NARU-RACELOCK  ロックした実状態の中で、レース正本の発走時刻も FOR UPDATE で読みます（なる 10/23 4通目 4章）
            $dl = self::deadlines($snap, $live, true, true);
            if (!$dl['ok']) {
                return self::fin(false, (string) $dl['code'], (string) $dl['reason']);
            }
            if ($nowUs > $dl['reply']) {
                return self::fin(false, 'DEADLINE_PASSED',
                    '送信前の期限検査で超過しています');
            }
            \DB::table('baganriki_ai_attempts')->where('token', $token)
              ->update(['status' => self::SENT]);
            self::event((string) $snap->race_id, (int) $a->snapshot_id,
                'QUEUE_SENT', null, null,
                ['role' => (string) $a->role, 'attempt_no' => (int) $a->attempt_no],
                $a->role . '#' . $a->attempt_no, $nowUs);
            return self::fin(true, 'OK', '送ってよいです',
                ['role' => (string) $a->role, 'attempt_no' => (int) $a->attempt_no]);
        });
    }

    // ── 受領 ────────────────────────────────────────────────────────────

    /**
     * 原応答を保存します。
     *   同じ token に同じ応答 → 冪等（版は増やしません）
     *   同じ token に別の応答 → 拒否（RESPONSE_MISMATCH）
     *   終端の付け替え         → 拒否（TERMINAL）
     *   complete=false の本文は、そのまま残して選出には使いません。
     */
    public static function receive(string $token, string $raw, bool $complete,
                                   int $nowUs): array
    {
        return \DB::transaction(function () use ($token, $raw, $complete, $nowUs) {
            // @ANCHOR-20261020-PREDICTION-QUEUE  ロックの順番は
            //   ライブ状態 → 枠 に統一します（予約と同じ順番。取り合いを避けます）
            $pre = \DB::table('baganriki_ai_attempts')
                     ->where('token', $token)->first(['snapshot_id']);
            if ($pre === null) {
                return self::fin(false, 'NO_TOKEN', 'その予約はありません');
            }
            $live = \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $pre->snapshot_id)->lockForUpdate()->first();
            $a = \DB::table('baganriki_ai_attempts')
                   ->where('token', $token)->lockForUpdate()->first();
            if ($a === null) {
                return self::fin(false, 'NO_TOKEN', 'その予約はありません');
            }
            $hash = hash('sha256', $raw);
            $st   = (string) $a->status;

            if ($st === self::RECEIVED) {
                if ((string) $a->response_hash === $hash
                    && ((int) $a->complete === 1) === $complete) {
                    return self::fin(true, 'IDEMPOTENT', 'すでに同じ応答を保存しています',
                        ['response_hash' => $hash]);
                }
                return self::fin(false, 'RESPONSE_MISMATCH',
                    '同じ予約に別の応答は保存しません');
            }
            if ($st === self::FAILED || $st === self::UNKNOWN) {
                return self::fin(false, 'TERMINAL',
                    'すでに終わっています（' . $st . '）。付け替えません');
            }
            if ($st === self::RESERVED) {
                return self::fin(false, 'NOT_SENT',
                    'まだ送信許可を出していません');
            }

            if ($st !== self::SENT) {
                return self::fin(false, 'INVALID_STATUS', '送信許可済みの枠だけが新たに受領できます');
            }

            $snap = \DB::table('baganriki_snapshots')
                      ->where('id', $a->snapshot_id)->first();
            if ($snap === null) {
                return self::fin(false, 'NO_SNAPSHOT', '凍結入力がありません');
            }
            // @ANCHOR-20261023-NARU-RACELOCK  ロックした実状態の中で、レース正本の発走時刻も FOR UPDATE で読みます（なる 10/23 4通目 4章）
            $dl = self::deadlines($snap, $live, true, true);
            if (!$dl['ok']) {
                return self::fin(false, (string) $dl['code'], (string) $dl['reason']);
            }
            // E3・E5版の新規受領では、送信後に確定した取消も同じ取引のライブ行から照合する。
            if (self::isExpectedSnapshot($snap)) {
                $issue = self::sendRosterIssue($snap, $live);
                if ($issue !== null) { return $issue; }
            }
            if ($nowUs > $dl['reply']) {
                return self::fin(false, 'DEADLINE_PASSED',
                    '受領時の期限検査で超過しています（遅着）');
            }

            // 訂正の受領は初回の受領以上の時刻を必須とします（同時刻は可）
            if ((int) $a->attempt_no === self::FIX) {
                $rows = self::rowsOf((int) $a->snapshot_id, (string) $a->role);
                $firstAt = self::dbUs($rows[self::FIRST]->received_at ?? null);
                if ($firstAt !== null && $nowUs < $firstAt) {
                    return self::fin(false, 'RECEIVE_ORDER',
                        '訂正の受領時刻が初回より前です');
                }
            }

            \DB::table('baganriki_ai_attempts')->where('token', $token)->update([
                'status'        => self::RECEIVED,
                'received_at'   => self::toDb($nowUs),
                'complete'      => $complete ? 1 : 0,
                'raw_response'  => $raw,
                'response_hash' => $hash,
            ]);
            $old = ($live === null) ? null : (int) $live->response_revision;
            $new = ($old === null) ? null : ($old + 1);
            if ($live !== null) {
                \DB::table('baganriki_live_states')->where('snapshot_id', $a->snapshot_id)
                  ->update(['response_revision' => $new,
                            'updated_at' => self::toDb($nowUs)]);
            }
            self::event((string) $snap->race_id, (int) $a->snapshot_id,
                'QUEUE_RECEIVED', $old, $new,
                ['role' => (string) $a->role, 'attempt_no' => (int) $a->attempt_no,
                 'complete' => $complete, 'response_hash' => $hash],
                $a->role . '#' . $a->attempt_no, $nowUs);
            return self::fin(true, 'OK', '保存しました',
                ['response_hash' => $hash, 'complete' => $complete,
                 'response_revision' => $new]);
        });
    }

    // ── 失敗・結果不明 ───────────────────────────────────────────────────

    public static function fail(string $token, string $reason, int $nowUs,
                               bool $unknown = false): array
    {
        return \DB::transaction(function () use ($token, $reason, $nowUs, $unknown) {
            $pre = \DB::table('baganriki_ai_attempts')
                     ->where('token', $token)->first(['snapshot_id']);
            if ($pre === null) {
                return self::fin(false, 'NO_TOKEN', 'その予約はありません');
            }
            $live = \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $pre->snapshot_id)->lockForUpdate()->first();
            $a = \DB::table('baganriki_ai_attempts')
                   ->where('token', $token)->lockForUpdate()->first();
            if ($a === null) {
                return self::fin(false, 'NO_TOKEN', 'その予約はありません');
            }
            $st = (string) $a->status;
            if (in_array($st, self::TERMINAL, true)) {
                return self::fin(false, 'TERMINAL',
                    'すでに終わっています（' . $st . '）。付け替えません');
            }
            $to = $unknown ? self::UNKNOWN : self::FAILED;
            \DB::table('baganriki_ai_attempts')->where('token', $token)->update([
                'status' => $to, 'failure_reason' => $reason,
            ]);
            $old = ($live === null) ? null : (int) $live->response_revision;
            $new = ($old === null) ? null : ($old + 1);
            if ($live !== null) {
                \DB::table('baganriki_live_states')->where('snapshot_id', $a->snapshot_id)
                  ->update(['response_revision' => $new,
                            'updated_at' => self::toDb($nowUs)]);
            }
            $snap = \DB::table('baganriki_snapshots')
                      ->where('id', $a->snapshot_id)->first();
            self::event(($snap === null) ? '' : (string) $snap->race_id,
                (int) $a->snapshot_id, 'QUEUE_' . $to, $old, $new,
                ['role' => (string) $a->role, 'attempt_no' => (int) $a->attempt_no,
                 'reason' => $reason],
                $a->role . '#' . $a->attempt_no, $nowUs);
            return self::fin(true, 'OK', $to . 'にしました',
                ['status' => $to, 'response_revision' => $new]);
        });
    }

    // ── 再起動 ──────────────────────────────────────────────────────────

    /**
     * 再起動したあとに1回だけ呼びます。
     *   SENT     → UNKNOWN_RESULT（結果不明。再送しません）
     *   RESERVED → そのまま（まだ送っていないので送信許可が1回残ります）
     * 枠そのものは作り直しません。
     */
    public static function resume(int $snapshotId, int $nowUs): array
    {
        $kept = []; $unknown = [];
        foreach (\DB::table('baganriki_ai_attempts')
                    ->where('snapshot_id', $snapshotId)
                    ->orderBy('role')->orderBy('attempt_no')->get() as $a) {
            $st = (string) $a->status;
            $label = $a->role . '#' . $a->attempt_no;
            if ($st === self::SENT) {
                $result = self::fail((string) $a->token,
                    '再起動をまたいだため結果が分かりません（再送しません）',
                    $nowUs, true);
                // 集計するのは、この処理が結果不明に変更した枠だけです。
                if ($result['ok']) {
                    $unknown[] = $label;
                }
            } elseif ($st === self::RESERVED) {
                $kept[] = $label;
            }
        }
        return self::fin(true, 'OK', '再起動の後始末をしました',
            ['unknown' => $unknown, 'reserved_kept' => $kept,
             'slots' => self::slots($snapshotId)]);
    }

    /**
     * 選出に使える応答だけを返します（complete=true の RECEIVED だけ）。
     * 訂正があればそちらを優先し、無ければ初回を使います。
     * 原応答は呼出側の内部処理にだけ渡します（readでは返しません）。
     */
    public static function usable(int $snapshotId, string $role): array
    {
        $rows = self::rowsOf($snapshotId, $role);
        foreach ([self::FIX, self::FIRST] as $no) {
            if (!isset($rows[$no])) { continue; }
            $r = $rows[$no];
            if ((string) $r->status === self::RECEIVED && (int) $r->complete === 1) {
                return self::fin(true, 'OK', '使えます',
                    ['attempt_no' => $no, 'raw' => (string) $r->raw_response,
                     'response_hash' => (string) $r->response_hash]);
            }
        }
        return self::fin(false, 'NONE', '選出に使える応答がありません');
    }
}

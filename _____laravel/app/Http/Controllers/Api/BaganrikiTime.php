<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-PREDICTION-TIME  新方式 の時刻契約
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 4章・8章／詳細設計書 4.3・11.2
 *
 * 基準は発走時刻 T（anchor_kind=POST_TIME）。
 *   slot_scheduled_at = T − スロット分
 *   slot_deadline     = slot_scheduled_at + 取得猶予（6分前30秒／他120秒）
 *   cutoff_at         = T − 360秒
 *   input_freeze_at   = cutoff_at + 30秒  … 利用できる情報の上限
 *   frozen_at         … 実際に保存した時刻。input_freeze_at とは別物
 *
 * 公開まわりは投票締切 sales_close_at が基準。発走時刻と同一視しません。
 *   publish_deadline           = sales_close_at − 60秒
 *   reply_deadline             = publish_deadline − 30秒
 *   effective_publish_deadline = min(publish_deadline, live_close − 60秒)
 *   effective_reply_deadline   = min(reply_deadline, effective_publish_deadline − 30秒)
 *
 * 日時は「秒必須・小数秒0〜6桁・タイムゾーン必須」のRFC3339だけを受け取り、
 * マイクロ秒の整数で比較します。7桁以上や独自形式は切り捨てず拒否します。
 */
final class BaganrikiTime
{
    /** スロットごとの取得猶予（ミリ秒）。ここに無い時点は予想入力に使いません */
    public const GRACE_MS = [21 => 120000, 18 => 120000, 15 => 120000,
                             12 => 120000,  9 => 120000,  6 =>  60000];
    // 旧：6 => 30000（@ANCHOR-20261107-PREDICTION-ALWAYSNEW  11/7 は38秒・45秒の遅れで2レースが新方式に入れなかったため60秒へ）

    /** 予想入力に使うスロット（30分前・3分前・発走時は入りません） */
    public const INPUT_SLOTS = [21, 18, 15, 12, 9, 6];

    /** 取消の監視だけに読むスロット（数値は信号へ入れません） */
    public const WATCH_SLOTS = [3, 0];

    public const CUTOFF_OFFSET_SEC       = -360;   // T − 360秒
    // public const INPUT_FREEZE_OFFSET_SEC = -330;   // T − 330秒（旧）
    // @ANCHOR-20261107-PREDICTION-ALWAYSNEW  6分前のオッズが遅れても新しいプロンプトで出すため、情報上限を T−300秒 へ
    public const INPUT_FREEZE_OFFSET_SEC = -300;   // T − 300秒
    public const SAFETY_MARGIN_SEC       = 60;     // safety_margin_ms = 60,000
    public const REPLY_BEFORE_PUBLISH_SEC = 30;    // reply_before_publish_ms = 30,000

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261020-PREDICTION-CLOSE  投票締切（sales_close_at）の正本
    // ══════════════════════════════════════════════════════════════════════
    // 根拠：JRA公式「発売時間・発売締切時刻」
    //   https://jra.jp/dento/a/instructions/hatsubai/jra.html
    //   当日発売のインターネット投票は<b>各レース発走時刻の1分前</b>。
    //   プッシュホン投票は5分前。WIN5は最初のレースの発走時刻の5分前。
    //
    //   ここで使うのはインターネット投票の締切です。
    //   <b>発走時刻と同一視していません</b>（契約8章）。
    //   公式の表記は「1分前」で、秒の記載はありません。
    //   秒まで決める必要があるため、ここでは1分前の0秒（T−60秒）とします。
    //
    //   .env で動かせます（コードを直さずに変えられるようにしています）。
    //     BAGANRIKI_SALES_CLOSE_OFFSET_SEC  既定 60（発走の何秒前か）
    //     BAGANRIKI_SALES_CLOSE_DERIVE      既定 1（0 にすると導出しない）
    //   導出しない設定では、正本を渡されたときだけ時刻契約を作ります
    //   （これまでの動きです）。
    public const SALES_CLOSE_BEFORE_POST_SEC = 60;
    public const SALES_CLOSE_SOURCE = 'JRA_INTERNET_1MIN_BEFORE_POST';

    /** 導出してよいか（.env で止められます） */
    public static function salesCloseDerive(): bool
    {
        $v = (string) env('BAGANRIKI_SALES_CLOSE_DERIVE', '1');
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /** 発走の何秒前が締切か。負の値・数値でない値は既定へ戻します */
    public static function salesCloseOffsetSec(): int
    {
        $v = env('BAGANRIKI_SALES_CLOSE_OFFSET_SEC', null);
        if ($v === null || !is_numeric((string) $v)) {
            return self::SALES_CLOSE_BEFORE_POST_SEC;
        }
        $n = (int) $v;
        return ($n < 0) ? self::SALES_CLOSE_BEFORE_POST_SEC : $n;
    }

    /** 正本の H:MM / HH:MM（秒は任意）を、時刻検証に渡す表記にそろえます。 */
    public static function normalizeRaceStartClock(string $clock): string
    {
        if (!preg_match('/^([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?\z/', $clock, $m)) {
            return $clock;
        }
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ':' . ($m[3] ?? '00');
    }

    /**
     * 発走時刻から投票締切を出します。
     * 導出が止められているときは null を返します（埋めません）。
     */
    public static function salesCloseFromPost(int $anchorUs): ?int
    {
        if (!self::salesCloseDerive()) { return null; }
        return $anchorUs - self::salesCloseOffsetSec() * self::US;
    }

    private const US = 1000000;

    /**
     * RFC3339 をマイクロ秒の整数へ。読めなければ null。
     * 小数秒は0〜6桁だけ。7桁以上は切り捨てずに拒否します（契約12章）。
     */
    public static function us(?string $t): ?int
    {
        if ($t === null) { return null; }
        $re = '/^(\d{4}-\d{2}-\d{2})[Tt](\d{2}:\d{2}:\d{2})(\.\d{1,6})?([Zz]|[+\-](?:[01]\d|2[0-3]):[0-5]\d)\z/';
        if (!preg_match($re, $t, $m)) { return null; }
        $frac = isset($m[3]) ? str_pad(substr($m[3], 1), 6, '0') : '000000';
        $tz   = (strtoupper($m[4]) === 'Z') ? '+00:00' : $m[4];
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $m[1] . ' ' . $m[2] . $tz);
        if ($d === false) { return null; }
        // ══════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-ROLLOVER  繰上がりを受け取らない
        // ══════════════════════════════════════════════════════════
        //   createFromFormat は 2026-02-30 を 2026-03-02 へ、
        //   25:00 を翌日01:00 へ<b>黙って繰り上げます</b>（false になりません）。
        //   作り直した日付・時刻を元の文字列と突き合わせます。
        //   同時に警告・注意も見ます（どちらか一方では足りません）。
        $err = \DateTimeImmutable::getLastErrors();
        if (is_array($err)
            && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0)) {
            return null;
        }
        if ($d->format('Y-m-d') !== $m[1] || $d->format('H:i:s') !== $m[2]) {
            return null;
        }
        return $d->getTimestamp() * self::US + (int) $frac;
    }

    /** マイクロ秒の整数を日本時間のRFC3339へ（表示・監査用） */
    public static function toJst(int $us): string
    {
        $d = (new \DateTimeImmutable('@' . intdiv($us, self::US)))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $f = $us % self::US;
        if ($f < 0) { $f += self::US; }
        return $d->format('Y-m-d\TH:i:s') . sprintf('.%06d', $f) . $d->format('P');
    }

    public static function slotScheduled(int $anchorUs, int $slotMin): int
    { return $anchorUs - $slotMin * 60 * self::US; }

    public static function cutoff(int $anchorUs): int
    { return $anchorUs + self::CUTOFF_OFFSET_SEC * self::US; }

    public static function inputFreeze(int $anchorUs): int
    { return $anchorUs + self::INPUT_FREEZE_OFFSET_SEC * self::US; }

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  早期凍結（よっしー11/14 ③B・なる 草案v0.1のB・なる11/15 本文案 _05・別紙01【必須2】）
    // ══════════════════════════════════════════════════════════════════════
    //   6分前のオッズがそろったら、発走5分前（T−300秒）を待たずに凍結します。T−300秒は「最大の期限」です。
    //   ・本文と時刻の決まりを同じ版で切り替えるため、本番の本文版が _05（EARLY_TEXT_REVISIONS）のときだけ使います。
    //     _04 のままなら、今までどおり T−300秒ちょうどで凍結します（時刻契約 v1）。
    //   ・早期凍結の情報上限 input_freeze_at は「凍結した時刻」で、6分前スロットの予定時刻（T−360秒）〜T−300秒の間。
    //     採用する資料は、その時刻までに利用可能になったものだけ（acceptBatch の available_at ≤ input_freeze_at）。
    //   ・6分前のレース全体がそろわないうちは凍結しません（入力が作れなかったら記録せずに次の巡回でもう一度。T−300秒からは今までどおり）。
    //   ・旧スナップショット（v1）は、旧の決まり（T−300秒ちょうど）のまま検証します。
    public const TIME_CONTRACT_V1 = 'baganriki-post-slot-grace-v1';
    public const TIME_CONTRACT_V2 = 'baganriki-post-slot-grace-v2-early';
    // public const EARLY_TEXT_REVISIONS = ['COMPACT_TEXT_20261109_05'];   // 旧（_05 だけ）
    // public const EARLY_TEXT_REVISIONS = ['COMPACT_TEXT_20261109_05','COMPACT_TEXT_20261109_06'];   // 20261116 _06（3指標の選出連動）も早期凍結   // 旧（_07 の前）
    public const EARLY_TEXT_REVISIONS = ['COMPACT_TEXT_20261109_05','COMPACT_TEXT_20261109_06','COMPACT_TEXT_20261124_07'];   // 20261124 _07（1st から3指標を外した版）も早期凍結
    private static ?bool $earlyActiveMemo = null;

    /** 早期凍結を使うか（本番の本文版が早期凍結の版のときだけ。1プロセスの中では最初に読んだ値を使う） */
    public static function earlyFreezeActive(): bool
    {
        if (self::$earlyActiveMemo === null) {
            try { self::$earlyActiveMemo = in_array(BaganrikiDispatch::activeRevision(), self::EARLY_TEXT_REVISIONS, true); }
            catch (\Throwable $e) { self::$earlyActiveMemo = false; }
        }
        return self::$earlyActiveMemo;
    }

    /** 新しく凍結する入力の時刻契約の版 */
    public static function timeContractVersion(): string
    { return self::earlyFreezeActive() ? self::TIME_CONTRACT_V2 : self::TIME_CONTRACT_V1; }

    /** 早く凍結してよい最初の時刻（6分前スロットの予定時刻＝T−360秒） */
    public static function earliestFreeze(int $anchorUs): int
    { return self::slotScheduled($anchorUs, 6); }

    /** これから凍結する入力の情報上限。v1 は T−300秒。v2 は min(今, T−300秒)、ただし T−360秒より前にはしない */
    public static function freezeFor(int $anchorUs, int $nowUs, ?string $version = null): int
    {
        $max = self::inputFreeze($anchorUs);
        if (($version ?? self::timeContractVersion()) !== self::TIME_CONTRACT_V2) { return $max; }
        return max(self::earliestFreeze($anchorUs), min($nowUs, $max));
    }

    /** 情報上限が決まりどおりか。v1 は T−300秒ちょうど。v2（と、版を持たない呼び出し）は T−360秒〜T−300秒 */
    public static function freezeInWindow(int $anchorUs, int $freezeUs, ?string $version = null): bool
    {
        if ($version === self::TIME_CONTRACT_V1) { return $freezeUs === self::inputFreeze($anchorUs); }
        return $freezeUs >= self::earliestFreeze($anchorUs) && $freezeUs <= self::inputFreeze($anchorUs);
    }

    public static function publishDeadline(int $salesCloseUs): int
    { return $salesCloseUs - self::SAFETY_MARGIN_SEC * self::US; }

    public static function replyDeadline(int $salesCloseUs): int
    { return self::publishDeadline($salesCloseUs) - self::REPLY_BEFORE_PUBLISH_SEC * self::US; }

    /**
     * 締切の前倒しを反映した実効期限。延長では猶予を増やしません。
     * $liveCloseUs が null なら前倒しなし。
     */
    public static function effectiveDeadlines(int $salesCloseUs, ?int $liveCloseUs): array
    {
        $pub = self::publishDeadline($salesCloseUs);
        $rep = self::replyDeadline($salesCloseUs);
        if ($liveCloseUs !== null) {
            $pub = min($pub, $liveCloseUs - self::SAFETY_MARGIN_SEC * self::US);
        }
        $rep = min($rep, $pub - self::REPLY_BEFORE_PUBLISH_SEC * self::US);
        return ['publish' => $pub, 'reply' => $rep];
    }

    /**
     * 1バッチを採用してよいか。期限ちょうどは可、1マイクロ秒でも後なら不可。
     * @return array ['ok'=>bool, 'reason'=>string|null, 'code'=>string|null]
     */
    public static function acceptBatch(int $slot, array $b, ?int $anchorUs = null): array
    {
        if (!isset(self::GRACE_MS[$slot])) {
            return ['ok' => false, 'code' => 'SLOT_NOT_INPUT',
                    'reason' => '予想に使わないスロット（21/18/15/12/9/6分前だけ）'];
        }
        $need = ['request_started_at', 'response_received_at', 'available_at', 'input_freeze_at'];
        $v = [];
        foreach ($need as $k) {
            $v[$k] = is_string($b[$k] ?? null) ? self::us($b[$k]) : null;
            if ($v[$k] === null) {
                return ['ok' => false, 'code' => 'TIME_UNREADABLE',
                        'reason' => $k . ' が無いか日時として読めない'];
            }
        }
        // 予定時刻は、与えられていれば使い、無ければ発走時刻から作ります
        $scheduled = $b['slot_scheduled_at'] ?? null;
        $sch = is_string($scheduled) ? self::us($scheduled) : null;
        // Only an absent/null schedule may use the documented anchor fallback.
        // An explicit malformed timestamp invalidates this batch, not its peers.
        if ($scheduled !== null && $sch === null) {
            return ['ok' => false, 'code' => 'TIME_UNREADABLE',
                    'reason' => 'slot_scheduled_at が日時として読めない'];
        }
        if ($sch === null) {
            if ($anchorUs === null) {
                return ['ok' => false, 'code' => 'TIME_UNREADABLE',
                        'reason' => 'slot_scheduled_at も発走時刻も無い'];
            }
            $sch = self::slotScheduled($anchorUs, $slot);
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-ANCHORMATCH  正本との意味的な一致を検査
        // ══════════════════════════════════════════════════════════════
        //   「RFC3339の形になっているか」と
        //   「正本の発走時刻から出した値と合っているか」は別の検査です。
        //   発走時刻が渡されているときは、後者もここで見ます。
        if ($anchorUs !== null) {
            $want = self::slotScheduled($anchorUs, $slot);
            if ($sch !== $want) {
                return ['ok' => false, 'code' => 'SCHEDULE_MISMATCH',
                        'reason' => 'slot_scheduled_at が正本の発走時刻から出した予定と'
                                  . '合いません（申告 ' . self::toJst($sch)
                                  . '／正本 ' . self::toJst($want) . '）'];
            }
            $wantFz = self::inputFreeze($anchorUs);
            // if ($v['input_freeze_at'] !== $wantFz) {   // 旧（T−300秒ちょうどだけ）
            // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  早期凍結の情報上限（T−360秒〜T−300秒）も受け付けます。v1 の入力は T−300秒しか作りません
            if (!self::freezeInWindow($anchorUs, $v['input_freeze_at'])) {
                return ['ok' => false, 'code' => 'FREEZE_MISMATCH',
                        'reason' => 'input_freeze_at が正本の発走時刻から出した情報上限と'
                                  . '合いません（申告 ' . self::toJst($v['input_freeze_at'])
                                  . '／正本 ' . self::toJst($wantFz) . '）'];
            }
        }
        $deadline = $sch + self::GRACE_MS[$slot] * 1000;

        if ($v['request_started_at']   < $sch)
            return ['ok' => false, 'code' => 'REQUEST_BEFORE_SCHEDULE', 'reason' => '要求開始が予定時刻より前'];
        if ($v['response_received_at'] < $v['request_started_at'])
            return ['ok' => false, 'code' => 'CLOCK_REVERSED', 'reason' => '受信完了が要求開始より前（時計逆行）'];
        if ($v['available_at']         < $v['response_received_at'])
            return ['ok' => false, 'code' => 'CLOCK_REVERSED', 'reason' => '利用可能が受信完了より前（時計逆行）'];
        if ($v['available_at'] > $deadline)
            return ['ok' => false, 'code' => 'ACQUISITION_DEADLINE_EXCEEDED', 'reason' => '取得猶予を超えた'];
        if ($v['available_at'] > $v['input_freeze_at'])
            return ['ok' => false, 'code' => 'ACQUISITION_DEADLINE_EXCEEDED', 'reason' => '情報上限 input_freeze_at を超えた'];

        if (($b['source_updated_at'] ?? null) !== null) {
            $su = is_string($b['source_updated_at']) ? self::us($b['source_updated_at']) : null;
            if ($su === null)
                return ['ok' => false, 'code' => 'TIME_UNREADABLE', 'reason' => 'source_updated_at が日時として読めない'];
            if ($su > $v['response_received_at'])
                return ['ok' => false, 'code' => 'SOURCE_TIME_FUTURE', 'reason' => '取得元の更新時刻が受信完了より未来'];
        }
        return ['ok' => true, 'code' => null, 'reason' => null];
    }
}

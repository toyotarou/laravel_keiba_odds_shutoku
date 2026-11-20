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
 * @ANCHOR-20261020-PREDICTION-ACQ  取得バッチの採用（SLOT_FIRST_VALID_COMMITTED_V1）
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 8章／詳細設計書 4.2・4.3
 *
 *   ・採用できるバッチを available_at → response_received_at → batch_id の
 *     昇順に並べ、最初の1件を採用します。batch_id は固定のバイト順で比べます。
 *   ・6分前だけは「同じバッチの中に全活動馬の有効な単勝がある」ことが追加条件です。
 *     別のバッチの馬を混ぜて揃えません。揃わなければ計算不能にします。
 *   ・それ以外の時点の部分欠損は欠損のまま信号へ伝えます。
 *     「全スロット完備」という新しい足切りを足しません。
 *   ・未確定の監査行は採用しません。available_at を予定時刻で埋めません。
 */
final class BaganrikiAcquisition
{
    /**
     * スロット1つぶんの採用バッチを選びます。
     *
     * @param int        $slot      スロット分数（21/18/15/12/9/6）
     * @param array      $batches   監査行の配列。各要素に batch_id・各実時刻・
     *                              values[馬番]=['win'=>文字列|null,'place_min'=>…] を持つ
     * @param array      $activeNums 活動馬の馬番（6分前の全頭確認に使う）
     * @param int|null   $anchorUs  発走時刻（予定時刻が無い行のため）
     * @return array ['status'=>'ADOPTED'|'UNAVAILABLE', 'batch'=>array|null,
     *                'reason'=>string|null, 'code'=>string|null, 'rejected'=>array]
     */
    public static function pick(int $slot, array $batches, array $activeNums,
                                ?int $anchorUs = null): array
    {
        $ok = []; $rejected = [];
        foreach ($batches as $b) {
            if (($b['committed'] ?? true) !== true) {
                $rejected[] = ['batch_id' => $b['batch_id'] ?? null,
                               'code' => 'NOT_COMMITTED',
                               'reason' => '監査行が未確定（保存commit前）'];
                continue;
            }
            $r = BaganrikiTime::acceptBatch($slot, $b, $anchorUs);
            if (!$r['ok']) {
                $rejected[] = ['batch_id' => $b['batch_id'] ?? null,
                               'code' => $r['code'], 'reason' => $r['reason']];
                continue;
            }
            if ($slot === 6) {
                // @ANCHOR-20261021-PREDICTION-PERCAND  その候補自身の取消だけを引きます
                $selfCancel = array_map('intval', (array) ($b['cancelled'] ?? []));
                $activeForB = array_values(array_diff($activeNums, $selfCancel));
                $missing = self::missingWin($b, $activeForB);
                if (!empty($missing)) {
                    $rejected[] = ['batch_id' => $b['batch_id'] ?? null,
                                   'code' => 'FREEZE_ODDS_MISSING',
                                   'reason' => '6分前の単勝が同じバッチに揃っていない（馬番 '
                                             . implode('・', $missing) . '）'];
                    continue;
                }
            }
            $ok[] = $b;
        }
        if (empty($ok)) {
            return ['status' => 'UNAVAILABLE', 'batch' => null, 'rejected' => $rejected,
                    'code' => ($slot === 6) ? 'FREEZE_ODDS_MISSING' : 'ACQUISITION_DEADLINE_EXCEEDED',
                    'reason' => '採用できるバッチがありません'];
        }
        usort($ok, static function (array $x, array $y): int {
            $a = BaganrikiTime::us($x['available_at']);
            $b = BaganrikiTime::us($y['available_at']);
            if ($a !== $b) { return $a <=> $b; }
            $a = BaganrikiTime::us($x['response_received_at']);
            $b = BaganrikiTime::us($y['response_received_at']);
            if ($a !== $b) { return $a <=> $b; }
            // batch_id は固定のバイト順
            return strcmp((string) ($x['batch_id'] ?? ''), (string) ($y['batch_id'] ?? ''));
        });
        return ['status' => 'ADOPTED', 'batch' => $ok[0], 'rejected' => $rejected,
                'code' => null, 'reason' => null];
    }

    /** 6分前に単勝が無い活動馬の馬番 */
    private static function missingWin(array $b, array $activeNums): array
    {
        $vals = $b['values'] ?? [];
        $miss = [];
        foreach ($activeNums as $n) {
            $w = $vals[$n]['win'] ?? null;
            if (!self::usableDecimal($w)) { $miss[] = (int) $n; }
        }
        sort($miss);
        return $miss;
    }

    /** 使える十進数の文字列か（0・負・空・「取消」は不可） */
    public static function usableDecimal($v): bool
    {
        // @ANCHOR-20261023-NARU-RAWEXACT  文字列・整数・小数だけを検査します（bool・配列・object は不正値）
        if (!is_string($v) && !is_int($v) && !is_float($v)) { return false; }
        if (is_float($v) && (is_nan($v) || is_infinite($v))) { return false; }
        $s = self::decimalText($v);
        if ($s === '' || $s === '取消' || $s === '除外') { return false; }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-DIGITS
        // ══════════════════════════════════════════════════════════════
        //   小数4桁までしか受け取らず、5桁以上を黙って欠損にしていました。
        //   契約9章は「整数・小数は最大128桁」です。
        //   【旧】'/^\d+(?:\.\d{1,4})?$/'
        if (!preg_match('/^\d{1,128}(?:\.\d{1,128})?$/', $s)) { return false; }
        return (ltrim($s, '0.') !== '');   // 0 や 0.0000 は不可
    }

    /**
     * @ANCHOR-20261023-NARU-RAWEXACT  数値を十進数の文字列にします（指数表記にしない）
     *   厳密な読み取りで丸まる数値はすでに文字列です。ここに float で来るのは
     *   PHP の float で正確に表せる値だけなので、指数表記を避けて十進で書き出します。
     */
    public static function decimalText($v): string
    {
        if (is_string($v)) { return trim($v); }
        if (is_int($v))    { return (string) $v; }
        if (is_float($v)) {
            $t = (string) $v;
            if (stripos($t, 'e') === false) { return $t; }
            // @ANCHOR-20261023-NARU-AUDIT10  【旧】sprintf('%.20F') は小数20桁で切るので、1e-25 が 0 になりました
            //   （011再精査10版の追加139項目「small exponent」で見つかりました）。
            //   【いま】元へ戻せる最短の表記（json_encode）を、文字の操作だけで十進へ直します。
            $t = BaganrikiPrompt::numToDecimalString($v);
            return $t === '' ? '0' : $t;
        }
        return '';
    }

    /**
     * collection_audit を組み立てます（入力JSONの最上位に入る項目）。
     * 採用時は ADOPTED と各時刻、欠損時は UNAVAILABLE と空でない理由。
     * 時刻を作り話で埋めません。
     */
    public static function buildAudit(array $picked): array
    {
        $out = [];
        foreach (BaganrikiTime::INPUT_SLOTS as $slot) {
            $p = $picked[$slot] ?? null;
            if ($p === null || ($p['status'] ?? '') !== 'ADOPTED') {
                $out[(string) $slot] = [
                    'status' => 'UNAVAILABLE',
                    'reason' => ($p['reason'] ?? null) ?: 'このスロットの採用バッチがありません',
                ];
                continue;
            }
            $b = $p['batch'];
            $out[(string) $slot] = [
                'status'               => 'ADOPTED',
                'batch_id'             => $b['batch_id'] ?? null,
                'payload_hash'         => $b['payload_hash'] ?? null,
                'slot_scheduled_at'    => $b['slot_scheduled_at'] ?? null,
                'source_updated_at'    => $b['source_updated_at'] ?? null,
                'request_started_at'   => $b['request_started_at'] ?? null,
                'response_received_at' => $b['response_received_at'] ?? null,
                'available_at'         => $b['available_at'] ?? null,
            ];
        }
        return $out;
    }
}

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
 * BaganrikiUsage  AIの使用量・料金・1日の予算
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BaganrikiUsage
{
    /** JSONへ（既存の書き方にそろえます） */
    public static function encodeJson($v): string
    {
        return (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @ANCHOR-20261025-NARU-SENTAT  呼び出し結果から送信時刻（マイクロ秒）を取り出します。
     *   sent_at_us（整数）を優先し、無ければ sent_at（日本時間の文字列）を読みます。どちらも無い・読めないときは null。
     */
    public static function sentAtUs(array $call): ?int
    {
        if (isset($call['sent_at_us']) && is_int($call['sent_at_us']) && $call['sent_at_us'] > 0) { return $call['sent_at_us']; }
        $s = $call['sent_at'] ?? null;
        if (!is_string($s) || trim($s) === '') { return null; }
        try {
            $t = new \DateTimeImmutable(trim($s), new \DateTimeZone('Asia/Tokyo'));
            return (int) $t->format('U') * 1000000 + (int) $t->format('u');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function recordAiUsage(int $snapshotId, string $role,
                                           int $attemptNo, array $call): void
    {
        try {
            if (!\Schema::hasTable('baganriki_audit_events')) { return; }
            $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
            $u = $call['usage'] ?? null;
            BaganrikiWorker::event(
                (string) ($snap->race_id ?? ''), $snapshotId, 'R122_USAGE',
                null, null,
                [
                    'role'        => $role,
                    'attempt_no'  => $attemptNo,
                    // 応答が名乗ったモデル（設定値ではありません）
                    'model'       => (string) ($call['model'] ?? ''),
                    'stop'        => $call['stop'] ?? null,
                    'usage'       => is_array($u) ? $u : null,
                    'raw_bytes'   => strlen((string) ($call['raw'] ?? '')),
                    'complete'    => (bool) ($call['complete'] ?? false),
                    'code'        => (string) ($call['code'] ?? ''),
                    // @ANCHOR-20261023-NARU-BUDGET  実際の額（usage から）と予約の番号・送信設定
                    // @ANCHOR-20261023-NARU-BUDGET2  保守的見積り（予算用）と請求見込みを分けます。usage が不正なら null（費用不明）
                    'cost_usd_conservative' => ($cU = AiController::costFromUsage($role, (string) ($call['requested_model'] ?? ($call['model'] ?? '')), $u, 'conservative')) === null ? null : round($cU, 6),
                    // @ANCHOR-20261025-NARU-SENTAT  請求見込みは送信時刻で計算します。送信時刻が無い・読めないときは null（見込み不明）。
                    //   予算に使う保守的見積り（上の行・常にピーク単価）は時刻に関係しないので、そのままです。
                    'cost_usd_estimate'     => (($sentUs = AiController::sentAtUs($call)) === null
                                                || ($cE = AiController::costFromUsage($role, (string) ($call['requested_model'] ?? ($call['model'] ?? '')), $u, 'estimate', $sentUs)) === null)
                                               ? null : round($cE, 6),
                    'usage_valid'   => AiController::usageTokens($role, $u) !== null,
                    'budget_hold'   => $call['budget_hold'] ?? null,
                    'settings'      => $call['settings'] ?? null,
                    'requested_model' => $call['requested_model'] ?? null,
                    'sent_at'       => $call['sent_at'] ?? null,
                    'received_at'   => BaganrikiTime::toJst(BaganrikiWorker::nowUs()),
                    'system_sha256' => $call['system_sha256'] ?? null,
                    'user_sha256'   => $call['user_sha256'] ?? null,
                    'compact_addendum' => ($call['wire_encoding'] ?? null) === null ? null
                                          : (string) ($call['compact_addendum_version'] ?? AiController::COMPACT_ADDENDUM_VERSION),
                    // @ANCHOR-20261023-COMPACT  送った形（詰めたときだけ値が入ります）
                    'wire_encoding' => $call['wire_encoding'] ?? null,
                    'wire_bytes'    => $call['wire_bytes'] ?? null,
                    'wire_sha256'   => $call['wire_sha256'] ?? null,
                    // @ANCHOR-20261110-PREDICTION-1ST-RESEND  1st を送り直したときの記録（送り直していなければ null）
                    'resent'        => $call['resent'] ?? null,
                ],
                $role . '#' . $attemptNo,
                BaganrikiWorker::nowUs()
            );
            // @ANCHOR-20261023-NARU-BUDGET2  予算の精算は、予算専用の接続で確定させて書きます
            //   （呼び出し側のトランザクションに巻き込まれて消えないように）。
            if (!in_array((string) ($call['code'] ?? ''), AiController::NOT_SENT_CODES, true)) {
                AiController::budgetSettle((string) ($snap->race_id ?? ''), $role, $attemptNo, $call, $u);
            }
        } catch (\Throwable $e) {
            // 記録で落ちても送信は止めません
            \Log::error('[PREDICTION-USAGE] 使用量を残せません',
                ['message' => $e->getMessage()]);
        }
    }

    /**
     * @ANCHOR-20261021-PREDICTION-USAGE  そのレースの使用量をまとめて返します
     *
     *   なるへ出す「初回24回／最大48回の費用」は、ここの実測から出します。
     *   単価は資料に書くので、ここでは<b>トークン数と実モデル</b>だけです。
     *
     * @return array ['rows'=>[…], 'by_model'=>[model=>['in','out','calls']]]
     */
    public static function aiUsageOf(int $snapshotId): array
    {
        $out = ['rows' => [], 'by_model' => []];
        if (!\Schema::hasTable('baganriki_audit_events')) { return $out; }
        $rows = \DB::table('baganriki_audit_events')
                  ->where('snapshot_id', $snapshotId)
                  ->where('kind', 'R122_USAGE')
                  ->orderBy('id')->get(['diagnostics', 'happened_at']);
        foreach ($rows as $r) {
            $d = json_decode((string) $r->diagnostics, true);
            if (!is_array($d)) { continue; }
            $m = (string) ($d['model'] ?? '');
            $u = (array) ($d['usage'] ?? []);
            // Anthropic は input_tokens／output_tokens、
            // DeepSeek は prompt_tokens／completion_tokens です。
            $in  = (int) ($u['input_tokens']  ?? ($u['prompt_tokens']     ?? 0));
            $o   = (int) ($u['output_tokens'] ?? ($u['completion_tokens'] ?? 0));
            $d['in'] = $in; $d['out'] = $o;
            $d['at'] = (string) $r->happened_at;
            $out['rows'][] = $d;
            if (!isset($out['by_model'][$m])) {
                $out['by_model'][$m] = ['in' => 0, 'out' => 0, 'calls' => 0];
            }
            $out['by_model'][$m]['in']    += $in;
            $out['by_model'][$m]['out']   += $o;
            $out['by_model'][$m]['calls'] += 1;
        }
        return $out;
    }

    public static function budgetUsdDay(): ?float
    {
        $v = trim((string) env('BAGANRIKI_PREDICTION_BUDGET_USD_DAY', ''));
        return ($v !== '' && is_numeric($v) && (float) $v > 0) ? (float) $v : null;
    }

    /** 料金表のどの行か（無ければ null） */
    public static function priceKeyOf(string $role, string $model): ?string
    {
        $m = trim($model);
        if ($role === '2nd') { return ($m === 'deepseek-flash') ? 'deepseek-flash' : null; }
        foreach (array_keys(AiController::PRICE_USD_PER_MTOK) as $k) {
            if ($k !== 'deepseek-flash' && ($m === $k || strpos($m, $k . '-') === 0)) { return $k; }
        }
        return null;
    }

    /** usage を検証して、計算に使う整数を返します。不正なら null（費用不明） */
    public static function usageTokens(string $role, $u): ?array
    {
        if (!is_array($u) || $u === []) { return null; }
        $int = static fn($v) => (is_int($v) && $v >= 0);
        if ($role === '2nd') {
            foreach (['prompt_tokens', 'completion_tokens'] as $k) {
                if (!array_key_exists($k, $u) || !$int($u[$k])) { return null; }
            }
            // ── @ANCHOR-20261025-NARU-USAGENULL  項目が「無い」と「あるが null」を分ける ──
            //    無い：DeepSeek が返さない版とみなし、prompt_tokens から補う（下の3行）。
            //    あるが null：量が分からないので費用不明（null）。予約額はそのまま残ります。
            foreach (['prompt_cache_hit_tokens', 'prompt_cache_miss_tokens'] as $k) {
                if (array_key_exists($k, $u) && $u[$k] === null) { return null; }
            }
            $hit  = $u['prompt_cache_hit_tokens']  ?? null;
            $miss = $u['prompt_cache_miss_tokens'] ?? null;
            if (($hit !== null && !$int($hit)) || ($miss !== null && !$int($miss))) { return null; }
            if ($hit !== null && $miss !== null && $hit + $miss !== $u['prompt_tokens']) { return null; }
            if ($hit === null && $miss === null) { $hit = 0; $miss = $u['prompt_tokens']; }
            elseif ($hit === null) { $hit = $u['prompt_tokens'] - $miss; if ($hit < 0) { return null; } }
            elseif ($miss === null) { $miss = $u['prompt_tokens'] - $hit; if ($miss < 0) { return null; } }
            return ['hit' => $hit, 'miss' => $miss, 'out' => $u['completion_tokens']];
        }
        foreach (['input_tokens', 'output_tokens'] as $k) {
            if (!array_key_exists($k, $u) || !$int($u[$k])) { return null; }
        }
        foreach (['cache_creation_input_tokens', 'cache_read_input_tokens'] as $k) {
            // @ANCHOR-20261025-NARU-USAGENULL  無い＝キャッシュを使っていない（0）。あるが null や整数でない＝費用不明
            if (array_key_exists($k, $u) && !$int($u[$k])) { return null; }
        }
        return ['in' => $u['input_tokens'], 'cw' => (int) ($u['cache_creation_input_tokens'] ?? 0),
                'cr' => (int) ($u['cache_read_input_tokens'] ?? 0), 'out' => $u['output_tokens']];
    }

    /** DeepSeek のピーク時間か（平日 UTC 01:00〜04:00・06:00〜10:00。中国の祝日は分からないのでピーク扱い） */
    public static function deepseekPeakAt(int $us): bool
    {
        $t = (new \DateTimeImmutable('@' . intdiv($us, 1000000)))->setTimezone(new \DateTimeZone('UTC'));
        $dow = (int) $t->format('N'); $h = (int) $t->format('G');
        if ($dow >= 6) { return false; }
        return ($h >= 1 && $h < 4) || ($h >= 6 && $h < 10);
    }

    /**
     * usage から額（ドル）。$mode は conservative（予算用・DeepSeek はピーク）か estimate（請求見込み）。
     * usage が不正・モデルが料金表に無いときは null（費用不明）。
     */
    public static function costFromUsage(string $role, ?string $model, $u, string $mode = 'conservative', ?int $atUs = null): ?float
    {
        $t = AiController::usageTokens($role, $u);
        if ($t === null) { return null; }
        $key = AiController::priceKeyOf($role, (string) $model);
        if ($key === null) { return null; }
        if ($role === '2nd') {
            $peak = ($mode === 'conservative') || AiController::deepseekPeakAt($atUs ?? BaganrikiWorker::nowUs());
            $p = AiController::PRICE_USD_PER_MTOK[$key][$peak ? 'peak' : 'offpeak'];
            return ($t['miss'] * $p[0] + $t['out'] * $p[1] + $t['hit'] * $p[2]) / 1000000;
        }
        $tab = AiController::PRICE_USD_PER_MTOK[$key];
        $all = $t['in'] + $t['cw'] + $t['cr'];      // 単価帯の判定はキャッシュの読込・書込みも含めた入力
        $p = ($tab['tier'] !== null && $all <= $tab['tier']) ? $tab['low'] : $tab['high'];
        return ($t['in'] * $p[0] + $t['cw'] * $p[0] * 1.25 + $t['cr'] * $p[0] * 0.1 + $t['out'] * $p[1]) / 1000000;
    }

    /** この呼び出しの最悪額（ドル）。料金表に無いモデルは null */
    public static function worstCostUsd(string $role, string $model, int $bytes, int $maxTokens): ?float
    {
        $key = AiController::priceKeyOf($role, $model);
        if ($key === null) { return null; }
        if ($role === '2nd') {
            $p = AiController::PRICE_USD_PER_MTOK[$key]['peak'];
            return ($bytes * $p[0] + $maxTokens * $p[1]) / 1000000;
        }
        $p = AiController::PRICE_USD_PER_MTOK[$key]['high'];
        return ($bytes * $p[0] * 1.25 + $maxTokens * $p[1]) / 1000000;
    }

    /** 予算専用の接続（既定の接続の設定を写し、別の接続として開きます。自動コミット） */
    public static function budgetDb(): \Illuminate\Database\ConnectionInterface
    {
        if (config('database.connections.' . AiController::BUDGET_CONN) === null) {
            $def = (string) config('database.default');
            config(['database.connections.' . AiController::BUDGET_CONN => config('database.connections.' . $def)]);
        }
        return \DB::connection(AiController::BUDGET_CONN);
    }

    /**
     * その日の保守的見積り額＋予約中の額（ドル）。予算専用の接続で、確定した行だけを数えます。
     *   R122_BUDGET_HOLD    予約（最悪額）
     *   R122_BUDGET_SETTLE  送った呼び出しの精算（usage が正しければ保守的見積り額。費用不明なら null＝予約額のまま）
     *   R122_BUDGET_RELEASE 送らなかった予約（0ドル）
     */
    public static function spentTodayUsd(int $nowUs): float
    {
        $day = (new \DateTimeImmutable('@' . intdiv($nowUs, 1000000)))
                 ->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
        $rows = AiController::budgetDb()->table('baganriki_audit_events')
                  ->whereIn('kind', ['R122_BUDGET_HOLD', 'R122_BUDGET_SETTLE', 'R122_BUDGET_RELEASE'])
                  ->where('happened_at', '>=', $day . ' 00:00:00')
                  ->orderBy('id')
                  ->get(['kind', 'diagnostics', 'related_id']);
        $used = 0.0; $holds = []; $settled = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->diagnostics, true);
            if (!is_array($d)) { continue; }
            if ($r->kind === 'R122_BUDGET_HOLD') {
                $holds[(string) $r->related_id] = (float) ($d['amount_usd'] ?? 0);
                continue;
            }
            $h = (string) ($d['budget_hold'] ?? '');
            if ($r->kind === 'R122_BUDGET_RELEASE') {
                if ($h !== '') { $settled[$h] = 0.0; }
                continue;
            }
            // SETTLE：usage が正しいときだけ予約を実際の保守的見積り額に置き換えます
            $amt = $d['amount_usd'] ?? null;
            $valid = (($d['usage_valid'] ?? false) === true) && is_numeric($amt);
            if ($h !== '') {
                if ($valid) { $settled[$h] = (float) $amt; }
                // 費用不明：何もしない（予約額のまま数える）
            }
            // } elseif (is_numeric($amt)) {
            //     $used += (float) $amt;   // 予約なしで送った分（旧。BUDGET3 で予約の無い精算は書かなくなりました）
            // }
        }
        foreach ($holds as $h => $amt) { $used += array_key_exists($h, $settled) ? $settled[$h] : $amt; }
        return $used;
    }

    /**
     * 送った呼び出しの精算（専用接続・確定）。R122_BUDGET_SETTLE に1行。
     *   usage が正しければ保守的見積り額。費用不明なら、この呼び出しの最悪額（予約額）のまま。
     *   予約が無い（予算を設定していない）呼び出しも、後から予算を設定した日のために残します。
     */
    public static function budgetSettle(string $raceId, string $role, int $attemptNo, array $call, $u): void
    {
        // @ANCHOR-20261023-NARU-BUDGET3  予約の無い呼び出し（予算を設定していないとき）は精算を書きません。
        //   【旧】予約が無くても精算を書いていたので、試験（ロールバックする試験の中の recordAiUsage）からも
        //     予算専用の接続で確定した行が残り、その日の額に積み上がりました。
        //   予算は、その日の最初の送信より前に設定してください（設定前の送信は数えません）。
        if (!is_string($call['budget_hold'] ?? null) || (string) $call['budget_hold'] === '') { return; }
        try {
            $model = (string) ($call['requested_model'] ?? ($call['model'] ?? ''));
            $c = AiController::costFromUsage($role, $model, $u, 'conservative');
            $worst = $call['budget_worst_usd'] ?? null;
            $amount = ($c !== null) ? $c : (is_numeric($worst) ? (float) $worst : null);
            AiController::budgetDb()->table('baganriki_audit_events')->insert([
                'race_id' => $raceId, 'snapshot_id' => null, 'kind' => 'R122_BUDGET_SETTLE',
                'happened_at' => BaganrikiWorker::toDb(BaganrikiWorker::nowUs()),
                'old_revision' => null, 'new_revision' => null,
                'diagnostics' => json_encode([
                    'budget_hold' => $call['budget_hold'] ?? null, 'role' => $role, 'attempt_no' => $attemptNo,
                    'usage_valid' => ($c !== null), 'amount_usd' => ($amount === null) ? null : round($amount, 6),
                    'basis' => ($c !== null) ? 'USAGE_CONSERVATIVE' : 'UNKNOWN_KEEP_WORST',
                ], JSON_UNESCAPED_UNICODE),
                'related_id' => (string) ($call['budget_hold'] ?? ($role . '#' . $attemptNo)),
            ]);
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-BUDGET] 精算を書けません（予約額のまま数えます）', ['message' => $e->getMessage()]);
        }
    }

    /** 送らなかった予約を0ドルで精算します（専用接続・確定） */
    public static function budgetRelease(?string $hold, string $raceId, string $why): void
    {
        if ($hold === null || $hold === '') { return; }
        try {
            AiController::budgetDb()->table('baganriki_audit_events')->insert([
                'race_id' => $raceId, 'snapshot_id' => null, 'kind' => 'R122_BUDGET_RELEASE',
                'happened_at' => BaganrikiWorker::toDb(BaganrikiWorker::nowUs()),
                'old_revision' => null, 'new_revision' => null,
                'diagnostics' => json_encode(['budget_hold' => $hold, 'reason' => $why], JSON_UNESCAPED_UNICODE),
                'related_id' => $hold,
            ]);
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-BUDGET] 予約の精算を書けません（予約額のまま数えます）', ['message' => $e->getMessage()]);
        }
    }

    /**
     * 送る前の判断と予約。@return array ['ok','code','hold','reason','spent','worst','budget']
     *   判断・予約の INSERT・ロックの解放は、すべて専用の別接続（自動コミット）で行います。
     *   INSERT は解放の前に確定しているので、ロックを次に取った worker は必ずこの予約を数えます。
     */
    public static function budgetReserve(string $role, string $model, string $raceId, int $bytes, int $maxTokens): array
    {
        $budget = AiController::budgetUsdDay();
        $worst = AiController::worstCostUsd($role, $model, $bytes, $maxTokens);
        if ($budget === null) {
            return ['ok' => true, 'code' => 'NO_BUDGET', 'hold' => null, 'reason' => '予算の上限が設定されていません',
                    'spent' => null, 'worst' => $worst, 'budget' => null];
        }
        if ($worst === null) {
            return ['ok' => false, 'code' => 'MODEL_NOT_PRICED', 'hold' => null,
                    'reason' => '料金表に無いモデルなので、予算を守れないため送りません（' . $model . '）',
                    'spent' => null, 'worst' => null, 'budget' => $budget];
        }
        $db = AiController::budgetDb();
        if ($db->transactionLevel() > 0) {
            return ['ok' => false, 'code' => 'BUDGET_CONN_IN_TX', 'hold' => null,
                    'reason' => '予算の専用接続がトランザクション中なので送りません',
                    'spent' => null, 'worst' => $worst, 'budget' => $budget];
        }
        $got = $db->select("SELECT GET_LOCK('baganriki_r122_budget', 10) AS l");
        if ((int) ($got[0]->l ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'BUDGET_LOCK_TIMEOUT', 'hold' => null,
                    'reason' => '予算のロックを取れないので送りません',
                    'spent' => null, 'worst' => $worst, 'budget' => $budget];
        }
        try {
            $now = BaganrikiWorker::nowUs();
            $spent = AiController::spentTodayUsd($now);
            if ($spent + $worst > $budget) {
                return ['ok' => false, 'code' => 'BUDGET_EXCEEDED', 'hold' => null,
                        'reason' => sprintf('予算を超えるので送りません（本日 %.4f＋最悪 %.4f ＞ 上限 %.4f ドル）', $spent, $worst, $budget),
                        'spent' => $spent, 'worst' => $worst, 'budget' => $budget];
            }
            $hold = 'B' . substr(hash('sha256', $raceId . $role . $now . random_bytes(4)), 0, 30);
            $db->table('baganriki_audit_events')->insert([
                'race_id' => $raceId, 'snapshot_id' => null, 'kind' => 'R122_BUDGET_HOLD',
                'happened_at' => BaganrikiWorker::toDb($now), 'old_revision' => null, 'new_revision' => null,
                'diagnostics' => json_encode(['amount_usd' => round($worst, 6), 'role' => $role, 'model' => $model,
                    'bytes' => $bytes, 'max_tokens' => $maxTokens, 'spent_before' => round($spent, 6),
                    'budget_usd' => $budget], JSON_UNESCAPED_UNICODE),
                'related_id' => $hold,
            ]);
            return ['ok' => true, 'code' => 'RESERVED', 'hold' => $hold, 'reason' => null,
                    'spent' => $spent, 'worst' => $worst, 'budget' => $budget];
        } finally {
            $db->select("SELECT RELEASE_LOCK('baganriki_r122_budget') AS l");
        }
    }
}

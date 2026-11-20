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
 * BaganrikiSendConfig  AIへ送るときの設定：送信形式・3指標・プロンプトの保存・訂正の方針・試験用の偽AI・読取権限
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BaganrikiSendConfig
{
    /**
     * @ANCHOR-20261102-PREDICTION-DICT  role ごとの送信形式
     *   BAGANRIKI_PREDICTION_COMPACT_1ST / BAGANRIKI_PREDICTION_COMPACT_2ND に書けばその role だけ、
     *   書かなければ BAGANRIKI_PREDICTION_COMPACT の値を使います。
     *   0 … 詰めない ／ 1・table … 表の形（SOURCE_TABLE_V1）／ dict … 辞書の形（SOURCE_DICT_V1）
     *   dict2 … 辞書の形をもう一段詰めた形（SOURCE_DICT_V2）／ dict3 … さらに一段（SOURCE_DICT_V3）
     */
    public static function compactFormatFor(string $role): string
    {
        $v = '';
        if ($role === '1st') { $v = trim((string) env('BAGANRIKI_PREDICTION_COMPACT_1ST', '')); }
        if ($role === '2nd') { $v = trim((string) env('BAGANRIKI_PREDICTION_COMPACT_2ND', '')); }
        if ($v === '') { $v = trim((string) env('BAGANRIKI_PREDICTION_COMPACT', '0')); }
        $v = strtolower($v);
        if ($v === 'dict') { return 'dict'; }
        if ($v === 'dict2') { return 'dict2'; }
        if ($v === 'dict3') { return 'dict3'; }
        if (in_array($v, ['1', 'true', 'on', 'yes', 'table'], true)) { return 'table'; }
        return 'off';
    }

    /**
     * @ANCHOR-20261023-COMPACT  送る本文を詰めてよいか判断し、詰めた文字列を返します
     *   ・BAGANRIKI_PREDICTION_COMPACT が 1 のときだけ
     *   ・本文が「凍結した入力」そのもの（source_registry を持つ）か、訂正依頼（input の中に持つ）のときだけ
     *   ・json_decode して canonical に戻したものが元の本文と1バイトも違わない（読みで数値が変わらない）ときだけ
     *   ・詰めた形から元へ戻せることを compactWire が確かめたときだけ
     *   どれか1つでも満たさなければ、元の本文をそのまま送ります。
     * @return array ['applied','user','reason']
     */
    public static function compactUserForSend(string $user, string $role = ''): array
    {
        $no = static fn(string $why) => ['applied' => false, 'user' => $user, 'reason' => $why];
        $fmt = AiController::compactFormatFor($role);
        if ($fmt === 'off') { return $no('旗が下りています'); }
        $enc  = ['dict' => BaganrikiPrompt::SOURCE_DICT_VERSION, 'dict2' => BaganrikiPrompt::SOURCE_DICT2_VERSION,
                 'dict3' => BaganrikiPrompt::SOURCE_DICT3_VERSION, 'table' => BaganrikiPrompt::SOURCE_TABLE_VERSION][$fmt];
        $add  = ['dict' => AiController::COMPACT_DICT_PROMPT_ADDENDUM, 'dict2' => AiController::COMPACT_DICT2_PROMPT_ADDENDUM,
                 'dict3' => AiController::COMPACT_DICT3_PROMPT_ADDENDUM, 'table' => AiController::COMPACT_PROMPT_ADDENDUM][$fmt];
        $addV = ['dict' => AiController::COMPACT_DICT_ADDENDUM_VERSION, 'dict2' => AiController::COMPACT_DICT2_ADDENDUM_VERSION,
                 'dict3' => AiController::COMPACT_DICT3_ADDENDUM_VERSION, 'table' => AiController::COMPACT_ADDENDUM_VERSION][$fmt];
        $pack = static function (array $in) use ($fmt) {
            if ($fmt === 'dict')  { return BaganrikiPrompt::compactWireDict($in); }
            if ($fmt === 'dict2') { return BaganrikiPrompt::compactWireDict2($in); }
            if ($fmt === 'dict3') { return BaganrikiPrompt::compactWireDict3($in); }
            return BaganrikiPrompt::compactWire($in);
        };
        try {
            $d = json_decode($user, true);
            if (!is_array($d)) { return $no('本文がJSONではありません'); }
            if (BaganrikiPrompt::canonical($d) !== $user) { return $no('読み直すと元の本文と1バイトでも違うので詰めません'); }
            if (is_array($d['source_registry'] ?? null)) {
                $c = $pack($d);
                if (!$c['ok']) { return $no((string) $c['reason']); }
                $w = $c['wire'];
                $w['wire_encoding'] = $enc;
            } elseif (is_array($d['input']['source_registry'] ?? null)) {
                $c = $pack($d['input']);
                if (!$c['ok']) { return $no((string) $c['reason']); }
                $w = $d;
                $w['input'] = $c['wire'];
                $w['input']['wire_encoding'] = $enc;
            } else {
                return $no('詰める対象の出典一覧がありません');
            }
            $j = BaganrikiPrompt::canonical($w);
            if ($j === null) { return $no('詰めた本文を書き出せません'); }
            return ['applied' => true, 'user' => $j, 'reason' => null,
                    'encoding' => $enc, 'addendum' => $add, 'addendum_version' => $addV];
        } catch (\Throwable $e) {
            return $no('詰める途中で例外: ' . $e->getMessage());
        }
    }

    /** @ANCHOR-20261108-PREDICTION-METRICS  3指標の頼み書きを足すか（既定は足す） */
    public static function predictionMetricsEnabled(): bool
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_METRICS', '1')));
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * @ANCHOR-20261108-PREDICTION-METRICS  1st の原応答から3指標を読みます
     *   3つとも1〜5の整数で読めたときだけ、画面の形（旧方式と同じ日本語のキー）で返します。
     *   1つでも欠ける・null・範囲外なら null（画面には出さない）。予想の採否には関わりません。
     */
    public static function predictionMetricsFromRaw(string $raw): ?array
    {
        try {
            $ex = BaganrikiResponse::extract($raw);
            if (!($ex['ok'] ?? false) || !is_array($ex['json']['race_metrics'] ?? null)) { return null; }
            $m = $ex['json']['race_metrics'];
            // @ANCHOR-20261110-PREDICTION-METRICS-UNCOMPUTED  よっしー11/9 2A「算出できない場合は数字を埋めず未算出」
            //   【旧】1つでも 1〜5 の整数でなければ、3つとも出しませんでした（return null）。
            //   【いま】項目ごとに見ます。1〜5 の整数ならその値、それ以外（null・範囲外・形違い）は null＝画面で「未算出」。
            $o = [];
            foreach (['wave_level' => '波乱度', 'lower_entry' => '下位進入度', 'big_gap_entry' => '大穴進入度'] as $k => $jp) {
                $v = $m[$k] ?? null;
                $o[$jp] = (is_int($v) && $v >= 1 && $v <= 5) ? $v : null;
            }
            return $o;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @ANCHOR-20261107-PREDICTION-PROMPTFILE  新方式で送るプロンプトを、旧方式と同じ場所・名前で保存します
     *   1st → public/prompt/prompt_{日付}_{回}_{場}_{日}_{R}.data
     *   2nd → public/prompt_2nd/prompt_2nd_{日付}_{回}_{場}_{日}_{R}.data
     *   中身は、実際にAIへ送った system と user です（どちらも送ったとおり）。
     */
    /** 試験のときだけ付ける目印（例 private）。本番の同じレースのファイルを上書きしないため。本番では null */
    public static ?string $promptFileTag = null;

    public static function writePredictionPromptFile(string $role, string $system, string $user): void
    {
        try {
            $ju = json_decode($user, true);
            $rid = '';
            if (is_array($ju)) {
                $rid = (string) ($ju['race_id'] ?? ($ju['input']['race_id'] ?? ($ju['fix_request']['race_id'] ?? '')));
            }
            if ($rid === '' && preg_match('/"race_id"\s*:\s*"(\d{8}-\d+-\d{2}-\d+-\d+)"/', $user, $mm)) {
                $rid = $mm[1];
            }
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})-(\d+)-(\d{2})-(\d+)-(\d+)$/', $rid, $m)) {
                \Log::warning('[PREDICTION-PROMPTFILE] race_id が読めないので保存しません', ['role' => $role]);
                return;
            }
            if ((int) $m[5] >= 90) { return; }   // 試験用の場コード
            $date = $m[1] . '-' . $m[2] . '-' . $m[3];
            $tail = "{$date}_{$m[4]}_{$m[5]}_{$m[6]}_{$m[7]}" . (self::$promptFileTag !== null ? '_' . self::$promptFileTag : '') . '.data';
            $path = ($role === '2nd')
                  ? public_path('prompt_2nd/prompt_2nd_' . $tail)
                  : public_path('prompt/prompt_' . $tail);
            $body = "【system】\n" . $system . "\n\n【user】\n" . $user . "\n";
            if (@file_put_contents($path, $body) === false) {
                \Log::warning('[PREDICTION-PROMPTFILE] 書けませんでした', ['path' => $path]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[PREDICTION-PROMPTFILE] 例外', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-COST-FIXPOLICY  AIへ訂正を依頼してよいか（費用の方針）
     * ═══════════════════════════════════════════════════════════════════════
     *   10/23 の実測：訂正は費用の約半分を占め、2回依頼して2回とも採用できなかった。
     *   直したい中身の多くは点数の合計のずれで、これは inspectAiResponse の救済
     *   （@ANCHOR-20261020-PREDICTION-SALVAGE）がサーバ側で計算し直している。
     *
     *   BAGANRIKI_PREDICTION_FIX_POLICY
     *     contract（既定） … 配布いただいた契約どおり、指摘があれば依頼する（FIX_POLICY_CONTRACT_V1）
     *     missing          … 【検証用】馬の行が丸ごと欠けているときだけ訂正を依頼する（FIX_POLICY_MISSING_V2）
     *     never            … 【検証用】訂正は依頼しない
     *   @ANCHOR-20261023-NARU-FIXVER  【旧】既定は missing でした。なる 10/23 2通目 5章のご指摘
     *     （missing は合計ミスの訂正省略より広く、帯・資格・参照の誤りも直す機会が減る）を受け、
     *     V1／V2 の診断別比較が済むまでは既定を contract に戻しました。missing は検証用です。
     *
     * @return array ['allow'=>bool,'reason'=>string,'policy'=>string]
     */
    public static function correctionPolicy(): string
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_FIX_POLICY', 'contract')));
        return in_array($v, ['missing', 'contract', 'never'], true) ? $v : 'contract';
    }

    /** @ANCHOR-20261023-NARU-FIXVER  訂正方針の版の名前（記録に残すため） */
    public static function correctionPolicyId(): string
    {
        $m = ['contract' => 'FIX_POLICY_CONTRACT_V1', 'missing' => 'FIX_POLICY_MISSING_V2',
              'never' => 'FIX_POLICY_NEVER_V2'];
        return $m[AiController::correctionPolicy()];
    }

    public static function correctionPolicyAllows(array $insp): array
    {
        $pol = AiController::correctionPolicy();
        if ($pol === 'contract') {
            return ['allow' => true, 'policy' => $pol, 'reason' => '契約どおり'];
        }
        if ($pol === 'never') {
            return ['allow' => false, 'policy' => $pol, 'reason' => '訂正は依頼しない設定です'];
        }
        $diag = (array) ($insp['diag'] ?? []);
        $missing = array_merge((array) ($diag['missing'] ?? []), (array) ($diag['iso_missing'] ?? []));
        if (!$missing) {
            foreach ((array) ($diag['findings'] ?? []) as $f) {
                if ((string) ($f['code'] ?? '') === 'HORSE_MISSING') { $missing[] = $f['target'] ?? null; }
            }
        }
        $missing = array_values(array_unique(array_filter($missing, static fn($x) => $x !== null)));
        if ($missing === []) {
            return ['allow' => false, 'policy' => $pol,
                    'reason' => '欠けた馬が無いので訂正は依頼しません（合計のずれ等はサーバ側で計算し直しています）'];
        }
        return ['allow' => true, 'policy' => $pol,
                'reason' => '欠けた馬があるので訂正を依頼します（馬番 ' . implode('・', $missing) . '）'];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  訂正依頼に載せる「具体的なエラー一覧」
     *
     *   配布本文の【説明と訂正】は
     *   「初回応答・同一snapshot・repair_scope・具体的なエラー一覧」
     *   を渡すことになっています。診断の findings をそのまま渡します。
     *   こちらの言い換えを混ぜると、直す対象がぶれるためです。
     */
    public static function correctionErrorList(array $insp): array
    {
        $d = (array) ($insp['diag'] ?? []);
        $errors = [];
        foreach ((array) ($d['findings'] ?? []) as $f) {
            $errors[] = [
                'scope'  => (string) ($f['scope'] ?? ''),
                'target' => $f['target'] ?? null,
                'code'   => (string) ($f['code'] ?? ''),
                'reason' => (string) ($f['reason'] ?? ''),
            ];
        }
        return [
            'errors'          => $errors,
            'missing_horses'  => array_values(array_map('intval',
                                   (array) ($d['missing'] ?? []))),
            'duplicated_horses' => array_values(array_map('intval',
                                   (array) ($d['duplicated'] ?? []))),
            'unknown_horses'  => array_values(array_map('intval',
                                   (array) ($d['unknown'] ?? []))),
            'isolated_horses' => array_values(array_map('intval',
                                   (array) ($d['isolated'] ?? []))),
        ];
    }

    public static function setJobDeadline(?int $deadlineUs): void
    {
        AiController::$jobDeadlineUs = $deadlineUs;
    }

    /** Job の打ち切りまでの秒数。Job の外なら十分大きな値 */
    public static function jobSecondsLeft(): int
    {
        if (AiController::$jobDeadlineUs === null) { return 86400; }
        return (int) floor((AiController::$jobDeadlineUs - BaganrikiWorker::nowUs()) / 1000000);
    }

    /** @ANCHOR-20261023-COST-PROMPTCACHE  指示文のキャッシュを使うか（既定は使う） */
    public static function promptCacheEnabled(): bool
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_PROMPT_CACHE', '1')));
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    public static function fakeAiEnabled(): bool
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_FAKE_AI', '0')));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    public static function fakeAiResponse(string $role, string $user, int $timeout): ?array
    {
        if (!AiController::fakeAiEnabled()) { return null; }
        $j = json_decode($user, true);
        if (!is_array($j)) { return null; }
        $isFix = array_key_exists('fix_request', $j);
        $in = $isFix ? (array) ($j['input'] ?? []) : $j;
        $rid = (string) ($in['race_id'] ?? '');
        if ($rid === '' || !AiController::isTestRaceId($rid)) { return null; }
        $hash = $isFix ? (string) ($j['fix_request']['snapshot_hash'] ?? '')
                       : (string) ($in['snapshot_hash'] ?? '');
        $raceNo = (int) (explode('-', $rid)[4] ?? 0);

        $delay = 3;
        if ($raceNo === 2 && $role === '2nd') { $delay = 100000; }
        // @ANCHOR-20261023-NARU-WORKERTEST2  10/23 16時の1回目：145秒は凍結の待ち行列の遅れぶん
        //   待ち時間（応答期限の5秒前まで）を超え、受け取れませんでした。遅い1stは120秒、
        //   145秒は「上限で切れる」場合として別のレース（5R）に分けます。
        if ($raceNo === 3 && $role === '1st') { $delay = $isFix ? 40 : 120; }
        // @ANCHOR-20261023-NARU-WORKERTEST5  10/23 18時の試験：145秒は、すぐ動き出せれば待ち時間（最大158秒）に
        //   収まって受け取れてしまいました。「1st が使えない場合」を確実に作るため、待ち時間を必ず超える
        //   200秒にします（こちらの上限で切れて結果不明になる）。
        if ($raceNo === 5 && $role === '1st' && !$isFix) { $delay = 200; }
        \Log::error('[PREDICTION-FAKEAI] 試験用の偽AIが応答します（外部へは送りません）', [
            'race_id' => $rid, 'role' => $role, 'fix' => $isFix,
            'delay' => $delay, 'timeout' => $timeout,
        ]);
        $out = ['ok' => false, 'raw' => '', 'complete' => false, 'code' => null,
                'reason' => null, 'usage' => null, 'model' => 'FAKE', 'stop' => null];
        if ($delay > $timeout) {
            sleep(max(0, $timeout));
            $out['code'] = 'CONNECTION';
            $out['reason'] = '偽AI：待ち時間 ' . $timeout . '秒を超えました（試験の想定どおり）';
            return $out;
        }
        sleep($delay);

        // @ANCHOR-20261023-NARU-WORKERTEST4  レースごとの応答の指定（試験が置く。中身はそのまま返す）
        $ovf = storage_path('logs/r122_fake/' . $rid . '_' . $role . '_' . ($isFix ? '1' : '0') . '.txt');
        if (is_file($ovf)) {
            $out['ok'] = true; $out['code'] = 'OK'; $out['complete'] = true; $out['stop'] = 'end_turn';
            $out['raw'] = (string) @file_get_contents($ovf);
            return $out;
        }
        $raw = '';
        $dir = storage_path('logs/r122_live');
        $pat = $dir . '/*_' . $role . '_attempt' . ($isFix ? '1' : '0') . '.txt';
        $files = glob($pat) ?: [];
        if ($files === [] && $isFix) { $files = glob($dir . '/*_' . $role . '_attempt0.txt') ?: []; }
        // @ANCHOR-20261023-NARU-WORKERTEST2  2nd の保存済み応答が無いときは、1st の応答を 2nd として使います
        $asOther = false;
        if ($files === [] && $role === '2nd') {
            $files = glob($dir . '/*_1st_attempt' . ($isFix ? '1' : '0') . '.txt') ?: [];
            $asOther = ($files !== []);
        }
        if ($files !== []) {
            rsort($files);
            $raw = (string) @file_get_contents($files[0]);
        }
        if ($asOther && $raw !== '') {
            $raw = (string) preg_replace('/"ai_role"\s*:\s*"1st"/', '"ai_role":"2nd"', $raw);
        }
        if ($raw === '') {
            $raw = (string) json_encode(['race_id' => $rid, 'ai_role' => $role,
                                         'snapshot_hash' => $hash],
                                        JSON_UNESCAPED_UNICODE);
        }
        $raw = (string) preg_replace('/"race_id"\s*:\s*"[^"]*"/',
                                     '"race_id":"' . $rid . '"', $raw);
        if ($hash !== '') {
            $raw = (string) preg_replace('/"snapshot_hash"\s*:\s*"[^"]*"/',
                                         '"snapshot_hash":"' . $hash . '"', $raw);
        }
        $out['ok'] = true;
        $out['code'] = 'OK';
        $out['raw'] = $raw;
        $out['complete'] = true;
        $out['stop'] = 'end_turn';
        return $out;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-READGATE  非公開の読取経路（章⑤）
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ■ 見つかったこと
     *   読取API（GET /api/baganriki/r12-2/races/{race_id}）に
     *   <b>認証が1つも掛かっていませんでした。</b>
     *   さらに画面側（Flutter）の race_content_page に、
     *   <b>誰にでも見える「新方式」のボタン</b>が出ています。
     *   条件で隠していません（管理者判定も debug 判定もありません）。
     *
     *   いまは記録が1件もないので空が返るだけです。ですが
     *   <b>10月24日に旗を上げた瞬間、その12レースの予想が
     *   一般の利用者に見えます。</b>「非公開の検証」になりません。
     *   race_id は日付と場と番号なので、総当たりも簡単です。
     *
     * ■ 塞ぎ方（既定は閉じる）
     *   .env の BAGANRIKI_PREDICTION_READ で決めます。
     *     （未設定・key） 鍵が合ったときだけ中身を返します【既定】
     *     open           誰でも見られます
     *     off            誰にも見せません（鍵があっても）
     *   鍵は BAGANRIKI_PREDICTION_READ_KEY に入れ、
     *     ヘッダ  X-R122-Key: <鍵>
     *     クエリ  ?r122_key=<鍵>
     *   のどちらかで渡します。
     *   <b>鍵が未設定なら、誰も中身を見られません。</b>
     *   対象レースの限定（TARGETS が空なら1レースも送らない）と
     *   同じ考え方です。設定を入れ忘れた側が安全に倒れます。
     *
     * ■ 画面（Flutter）は作り直しません
     *   鍵を渡さないので「まだありません」と出るだけです。
     *   検証する側は、鍵を付けてURLを直接開けば中身を読めます。
     *     https://…/api/baganriki/r12-2/races/20261024-5-05-1-1?r122_key=<鍵>
     *   PWAの再ビルドを24日までに挟まずに済みます。
     *
     * ■ artisan（コマンド）からは素通りします
     *   試験はコマンドから呼びます。コマンドは外から叩けないので、
     *   ここで止める意味がありません。止めると試験が全部落ちます。
     *
     * @param array $opt 試験用。force_web=true で web とみなし、key で鍵を渡す
     * @return array ['ok','code','reason','mode']
     */
    public static function checkPredictionReadAccess(array $opt = []): array
    {
        $mode = strtolower(trim((string) env('BAGANRIKI_PREDICTION_READ', 'key')));
        if (!in_array($mode, ['key', 'open', 'off'], true)) { $mode = 'key'; }
        $out = ['ok' => false, 'code' => null, 'reason' => null, 'mode' => $mode];

        // コマンドからの呼び出しは素通りします（外から叩けないため）
        $isWeb = (bool) ($opt['force_web'] ?? false);
        if (!$isWeb) {
            try {
                if (app()->runningInConsole()) {
                    $out['ok'] = true; $out['code'] = 'CONSOLE';
                    return $out;
                }
            } catch (\Throwable $e) {
                // 判定できないときは web とみなします（安全側）
            }
        }

        if ($mode === 'open') {
            $out['ok'] = true; $out['code'] = 'OPEN';
            return $out;
        }
        if ($mode === 'off') {
            $out['code'] = 'NOT_PUBLIC';
            $out['reason'] = 'この記録はまだ公開していません';
            return $out;
        }

        $want = (string) env('BAGANRIKI_PREDICTION_READ_KEY', '');
        if ($want === '') {
            // 鍵が未設定なら、誰にも見せません
            $out['code'] = 'NOT_PUBLIC';
            $out['reason'] = 'この記録はまだ公開していません';
            return $out;
        }

        $got = (string) ($opt['key'] ?? '');
        if ($got === '' && !array_key_exists('key', $opt)) {
            try {
                $req = request();
                $got = (string) ($req->header('X-R122-Key') ?? '');
                if ($got === '') { $got = (string) ($req->query('r122_key') ?? ''); }
            } catch (\Throwable $e) {
                $got = '';
            }
        }
        if ($got === '' || !hash_equals($want, $got)) {
            $out['code'] = 'NOT_PUBLIC';
            $out['reason'] = 'この記録はまだ公開していません';
            return $out;
        }
        $out['ok'] = true; $out['code'] = 'KEY';
        return $out;
    }

    public static function checkPredictionFlagConfig(): array
    {
        $p = BaganrikiPrompt::mode();      // off / shadow / on
        $q = BaganrikiWorker::mode();      // off / shadow / on
        $o = ['ok' => true, 'code' => 'OK', 'reason' => null,
              'prompt' => $p, 'queue' => $q];
        if ($p !== 'on') { return $o; }    // 旧本文のまま、または shadow なら関係ありません
        if ($q === 'off') {
            $o['ok']     = false;
            $o['code']   = 'QUEUE_REQUIRED';
            $o['reason'] = 'BAGANRIKI_PREDICTION_PROMPT=1 のときに '
                         . 'BAGANRIKI_PREDICTION_QUEUE=0 は使えません。'
                         . '枠・期限・公開が r12.2 になっていない状態で '
                         . 'r12.2 の本文を送ることになるためです。'
                         . '（QUEUE=1 にしてください）';
            return $o;
        }
        if ($q === 'shadow') {
            $o['code']   = 'QUEUE_SHADOW';
            $o['reason'] = '枠は shadow です。枠が取れなくても送信を止めません。'
                         . '非公開検証の段としては妥当ですが、'
                         . '契約10章の停止条件は効いていません。';
        }
        return $o;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-DEADLINE  AIを待つ時間を応答期限から決めます
     * ═══════════════════════════════════════════════════════════════════════
     * 固定の秒数だと、2つとも困ったことになります。
     *   短すぎる … 期限まで余裕があるのに、こちらが先に切ってしまう
     *   長すぎる … 期限を過ぎてから返ってきて、遅着で捨てることになる
     * 予約がとれているときは、<b>応答期限まで残っている時間</b>から決めます。
     * 受領と公開のために 5秒だけ残します。
     *
     * 予約がない（旗が0）ときは、今までの秒数をそのまま使います。
     *
     * @param int   $fallback 予約が無いときに使う秒数（今までの値）
     * @param array|null $q   reserveAiSlot の戻り
     */
    public static function aiTimeoutFromDeadline(int $fallback, ?array $q): int
    {
        if (!is_array($q) || ($q['skip'] ?? true) || !($q['ok'] ?? false)) {
            return $fallback;
        }
        $dl = BaganrikiTime::us((string) ($q['reply_deadline'] ?? ''));
        if ($dl === null) { return $fallback; }
        $left = (int) floor(($dl - BaganrikiWorker::nowUs()) / 1000000) - 5;
        if ($left < 20)  { return 20; }    // これ以下は待っても無駄なので下限
        if ($left > 170) { return 170; }   // 応答期限（発走150秒前）を超えて待ちません
        return $left;
    }
}

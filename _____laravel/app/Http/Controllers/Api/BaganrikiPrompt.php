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
 * @ANCHOR-20261020-PREDICTION-PROMPT  新方式 の両AI本文（1st・2nd）と INPUT_JSON
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 6章・9章／詳細設計書 5章・13章
 *       同梱 prompts/prompt_1st.txt・prompt_2nd.txt・common_prompt.txt
 *       同梱 schemas/input_schema.json・source_registry_schema.json
 *
 * ■ ここが何をするところか
 *   新方式 では、AIへ送るものが2つとも入れ替わります。
 *     ・system 本文   … 先方が配った prompt_1st.txt / prompt_2nd.txt の全文。
 *                        <b>旧本文への追記ではなく、全文の置換です。</b>
 *                        1st・2nd それぞれが単独で完全な本文なので、
 *                        common_prompt.txt を後ろに足してはいけません
 *                        （足すと同じ規則が二重に入ります）。
 *     ・user 本文     … Laravel が作る INPUT_JSON 1個だけ。
 *                        日本語の散文でオッズを並べる旧方式はやめます。
 *
 * ■ 本文を PHP の文字列に書き写さない理由
 *   本文は先方が版管理している配布物です。コードへ書き写すと、
 *   写し間違いが起きても誰も気づけません。配布ファイルをそのまま置き、
 *   読み込むたびに
 *       ・BEGIN/END マーカーがあるか
 *       ・schema_version・prompt_version が 新方式 のものか
 *       ・未置換の差し込み記号（{{ }}）が残っていないか
 *       ・UTF-8 として壊れていないか
 *   を確かめ、SHA-256 を記録します。
 *
 * ■ 置き場所
 *   既定  storage/app/baganriki/prompts/r12_2/
 *   .env の BAGANRIKI_PREDICTION_PROMPT_DIR で変えられます。
 *   <b>新しい PHP ファイルは作りません。</b>本文は PHP ではなくデータです。
 *
 * ■ 機能フラグ
 *   .env の BAGANRIKI_PREDICTION_PROMPT=1 のときだけ新しい送信文に切り替わります。
 *   既定は 0（切）で、1バイトも今までと変わりません。
 *   フラグを入れていても、INPUT_JSON を正しく作れないときは
 *   <b>旧本文へ落ちずに送信を止めます</b>（契約11.2「正本が特定できない
 *   レースは公開しない」。中途半端な入力でAIを呼ぶほうが危険です）。
 *
 * ■ snapshot_hash の作り方（ここは先方の実装と1バイト単位で合わせました）
 *   対象   INPUT_JSON から ai_role・role_cap・role_quota・snapshot_hash を
 *          外した残り（＝両roleで同一の共通本文）
 *   並び   すべての階層でキーを文字列として昇順に並べ替える
 *   書式   区切りは "," と ":"（余白なし）、非ASCIIはエスケープしない
 *   SHA-256 を取り、snapshot_hash_version='exact-common-payload-sha256-r8'
 *   先方の参照入力14本すべてで同じ値になることを --prediction-prompt-check で
 *   確かめています。
 */
final class BaganrikiPrompt
{
    public const BEGIN = '<<<BAGANRIKI_V041_R8_BEGIN>>>';
    public const END   = '<<<BAGANRIKI_V041_R8_END>>>';

    public const SCHEMA_VERSION = 'baganriki-v041-r12-2';
    public const PROMPT_VERSION = 'baganriki-v041-r12-2-20261020';
    public const HASH_VERSION   = 'exact-common-payload-sha256-r8';

    /** 共通hashの対象から外すキー（契約5章） */
    public const HASH_EXCLUDE = ['ai_role', 'role_cap', 'role_quota', 'snapshot_hash'];

    /** 配布された本文ファイル名 */
    public const FILES = ['1st' => 'prompt_1st.txt', '2nd' => 'prompt_2nd.txt',
                          'common' => 'common_prompt.txt'];

    /**
     * INPUT_JSON のトップレベル必須キー。
     * input_schema.json の required と同じ<b>35件</b>です。
     * @ANCHOR-20261020-PREDICTION-PROMPT
     *   報告書に「34キー」と書いたのはこちらの数え間違いでした。
     *   定数も照合処理も最初から35キーで動いています（件数は数えて出します）。
     */
    public const TOP_KEYS = [
        'schema_version', 'prompt_version', 'race_id', 'snapshot_hash',
        'snapshot_hash_version', 'time_contract', 'cutoff_at', 'N', 'runners',
        'source_registry', 'field_catalog', 'input_indices', 'legacy_gap_type',
        'signals', 'S', 'S_min', 'S_max', 'mode', 'target_count', 'base_quota',
        'quota', 'quota_adjustments', 'selection_enabled', 'display_rating',
        'learning_stage', 'model_version', 'ai_role', 'role_cap', 'role_quota',
        'coverage', 'mode_status', 'allocation_mode', 'mode_policy_id',
        'input_freeze_at', 'collection_audit',
    ];

    /** coverage の7区分（契約3章・順序は問いません） */
    public const COVERAGE_KEYS = ['past_runs', 'OPI', 'estimated_odds',
        'recovery_statistics', 'similar_statistics', 'legacy_qualifications',
        'gap_history'];

    /**
     * 項目辞書の定義。
     * 値そのものからは単位や下限を決められないので、知っている項目は表で持ちます。
     * 知らない項目が来たら登録内容から組み立てます（取りこぼしを作らないため）。
     */
    public const FIELD_DEFS = [
        'win_odds'         => ['unit' => 'odds',  'value_type' => 'number',
                               'missing_allowed' => true, 'minimum' => 0, 'maximum' => null],
        'place_min'        => ['unit' => 'odds',  'value_type' => 'number',
                               'missing_allowed' => true, 'minimum' => 0, 'maximum' => null],
        'place_max'        => ['unit' => 'odds',  'value_type' => 'number',
                               'missing_allowed' => true, 'minimum' => 0, 'maximum' => null],
        'past_performance' => ['unit' => 'index', 'value_type' => 'number',
                               'missing_allowed' => true, 'minimum' => 0, 'maximum' => null],
    ];

    /** JSONの制限（契約9章・出力側と同じ値を入力側にも当てます） */
    public const MAX_DEPTH  = 64;
    public const MAX_DIGITS = 128;

    // ───────────────────────────────────────────────────────────────────────
    // 本文（system）
    // ───────────────────────────────────────────────────────────────────────

    /**
     * @ANCHOR-20261020-PREDICTION-SHADOW  .env の BAGANRIKI_PREDICTION_PROMPT は3つの値を取ります
     *   0       … 何もしません（既定）。旧本文のままです。
     *   shadow  … 新方式 の入力を組み立てて<b>凍結保存だけ</b>します。
     *             AIへ送る本文は<b>旧本文のまま</b>です。
     *             初めて実物で動かす日に、いまの商品を落とさずに
     *             「入力が作れるか・大きさ・coverage・取得監査の紐づき」
     *             だけを実測するための段です。
     *   1       … 新方式 の本文で送ります。契約どおり、作れないレースは
     *             送信を止めます（旧本文へは落ちません）。
     */
    public static function mode(): string
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_PROMPT', '0')));
        if (in_array($v, ['1', 'true', 'on', 'yes'], true)) { return 'on'; }
        if (in_array($v, ['shadow', 'record', 'dry'], true)) { return 'shadow'; }
        return 'off';
    }

    /** 入力の組み立てを動かすか（shadow と on） */
    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }

    /** 新方式 の本文で実際に送るか（on だけ） */
    public static function sending(): bool
    {
        return self::mode() === 'on';
    }

    /** 本文の置き場所 */
    public static function dir(): string
    {
        $d = (string) env('BAGANRIKI_PREDICTION_PROMPT_DIR', '');
        if ($d !== '') return rtrim($d, '/');
        return rtrim(storage_path('app/baganriki/prompts/r12_2'), '/');
    }

    /**
     * 本文を読み、そのまま使えるかどうかを確かめます。
     * 使えないときは ok=false を返し、呼び出し側は送信しません。
     *
     * @param string $role '1st'|'2nd'|'common'
     * @return array ['ok','body','path','sha256','bytes','lines','reason','checks']
     */
    public static function systemBody(string $role): array
    {
        $out = ['ok' => false, 'body' => null, 'path' => null, 'sha256' => null,
                'bytes' => 0, 'lines' => 0, 'reason' => null, 'checks' => []];
        if (!isset(self::FILES[$role])) {
            $out['reason'] = "role が違います（{$role}）";
            return $out;
        }
        $path = self::dir() . '/' . self::FILES[$role];
        $out['path'] = $path;
        if (!is_file($path) || !is_readable($path)) {
            $out['reason'] = '本文ファイルがありません: ' . $path;
            return $out;
        }
        $body = file_get_contents($path);
        if ($body === false || $body === '') {
            $out['reason'] = '本文ファイルが空です: ' . $path;
            return $out;
        }
        // BOM は送信文に混ぜません（hashが変わるため）
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) $body = substr($body, 3);
        $out['body']   = $body;
        $out['sha256'] = hash('sha256', $body);
        $out['bytes']  = strlen($body);
        $out['lines']  = substr_count($body, "\n") + 1;

        $checks = [];
        $checks['utf8']           = (mb_check_encoding($body, 'UTF-8') === true);
        $checks['has_begin']      = (strpos($body, self::BEGIN) !== false);
        $checks['has_end']        = (strpos($body, self::END) !== false);
        $checks['schema_version'] = (strpos($body, self::SCHEMA_VERSION) !== false);
        $checks['prompt_version'] = (strpos($body, self::PROMPT_VERSION) !== false);
        $checks['no_placeholder'] = (strpos($body, '{{') === false);
        if ($role !== 'common') {
            // それぞれが単独で完全な本文であることの目印（共通部の見出し）
            $checks['has_common_part'] = (strpos($body, '【最優先規則】') !== false);
            $checks['has_output_part'] = (strpos($body, '【出力形式】') !== false);
            $checks['has_input_part']  = (strpos($body, '【実入力】') !== false);
        }
        $out['checks'] = $checks;
        $bad = array_keys(array_filter($checks, static fn($v) => $v !== true));
        if ($bad !== []) {
            $out['reason'] = '本文の確認に失敗しました: ' . implode('・', $bad);
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    // ───────────────────────────────────────────────────────────────────────
    // 正規化とhash
    // ───────────────────────────────────────────────────────────────────────

    /**
     * すべての階層でキーを文字列として昇順に並べ替えます。
     * 配列（0始まりの連番）は順序を変えません（runners や source_refs の順は意味を持つ）。
     * 空の連想配列は {} として残すため stdClass に替えます（[] になると別物）。
     */
    public static function sortDeep($v)
    {
        if ($v instanceof \stdClass) {
            $v = (array) $v;
            if ($v === []) return new \stdClass();
        }
        if (!is_array($v)) return $v;
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) {
            $out = [];
            foreach ($v as $x) $out[] = self::sortDeep($x);
            return $out;
        }
        $keys = array_map('strval', array_keys($v));
        $vals = array_values($v);
        array_multisort($keys, SORT_ASC, SORT_STRING, $vals);
        $out = [];
        foreach ($keys as $i => $k) $out[$k] = self::sortDeep($vals[$i]);
        return $out;
    }

    /**
     * 共通hashの対象文（キー昇順・余白なし・非ASCIIはそのまま）。
     * 先方の python 実装 json.dumps(sort_keys=True, separators=(',',':'),
     * ensure_ascii=False) と1バイト単位で同じになることを確かめています。
     */
    public static function canonical($v): ?string
    {
        // JSON_PARTIAL_OUTPUT_ON_ERROR は付けません。付けると NaN / Infinity が
        // 黙って 0 に置き換わり、気づかないまま送ってしまいます。
        $json = json_encode(self::sortDeep($v),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) return null;
        if (json_last_error() !== JSON_ERROR_NONE) return null;
        return $json;
    }

    /**
     * snapshot_hash。role に依存する4キーを外してから取ります。
     * @return array ['ok','json','hash','hash_version','reason']
     */
    public static function commonHash(array $input): array
    {
        foreach (self::HASH_EXCLUDE as $k) unset($input[$k]);
        $json = self::canonical($input);
        if ($json === null) {
            return ['ok' => false, 'json' => null, 'hash' => null,
                    'hash_version' => self::HASH_VERSION,
                    'reason' => '共通本文をJSONにできませんでした'];
        }
        return ['ok' => true, 'json' => $json, 'hash' => hash('sha256', $json),
                'hash_version' => self::HASH_VERSION, 'reason' => null];
    }

    /**
     * 送信する user 本文。契約9章の制限（入れ子64階層・数値128桁）を確かめます。
     * 送信文のキー順は hash と同じ昇順に揃えます（差分を読むときに楽なので）。
     * @return array ['ok','json','bytes','depth','reason']
     */
    public static function encode(array $input): array
    {
        $depth = self::depthOf($input);
        if ($depth > self::MAX_DEPTH) {
            return ['ok' => false, 'json' => null, 'bytes' => 0, 'depth' => $depth,
                    'reason' => '入れ子が ' . $depth . ' 階層で上限64を超えています'];
        }
        $long = self::tooLongNumber($input);
        if ($long !== null) {
            return ['ok' => false, 'json' => null, 'bytes' => 0, 'depth' => $depth,
                    'reason' => '数値の桁数が上限128を超えています: ' . $long];
        }
        $json = self::canonical($input);
        if ($json === null) {
            return ['ok' => false, 'json' => null, 'bytes' => 0, 'depth' => $depth,
                    'reason' => 'INPUT_JSON を作れませんでした'];
        }
        // Actual nonfinite numbers already fail canonical JSON serialization.
        // Text containing their names is valid; validate the generated JSON
        // with the same byte/numeric/depth contract as received JSON instead.
        $checked = BaganrikiJson::strict($json);
        if (!$checked['ok']) {
            return ['ok' => false, 'json' => null, 'bytes' => strlen($json),
                    'depth' => $depth, 'reason' => $checked['reason']];
        }
        return ['ok' => true, 'json' => $json, 'bytes' => strlen($json),
                'depth' => $depth, 'reason' => null];
    }

    /**
     * RFC3339（秒まで必須・タイムゾーン必須）。
     * マイクロ秒が 0 のときは小数部を付けません。
     * 同梱スキーマは小数部を任意にしていますが、先方の参照入力は
     * 小数部なしで書かれているため、同じ書き方にそろえます
     * （snapshot_hash は本文のバイト列から取るので、書き方がそろっていないと
     *   同じ内容でも値が変わります）。
     */
    public static function rfc3339(int $us): string
    {
        return str_replace('.000000', '', BaganrikiTime::toJst($us));
    }

    /** 入れ子の深さ */
    public static function depthOf($v, int $cur = 1): int
    {
        if ($v instanceof \stdClass) $v = (array) $v;
        if (!is_array($v) || $v === []) return $cur;
        $max = $cur;
        foreach ($v as $x) {
            $d = self::depthOf($x, $cur + 1);
            if ($d > $max) $max = $d;
        }
        return $max;
    }

    /** 128桁を超える数値があればその場所を返します */
    public static function tooLongNumber($v, string $path = '')
    {
        if ($v instanceof \stdClass) $v = (array) $v;
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $r = self::tooLongNumber($x, $path . '/' . $k);
                if ($r !== null) return $r;
            }
            return null;
        }
        if (is_int($v) || is_float($v)) {
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-PROMPT  指数表記を展開して桁を数えます
            // ══════════════════════════════════════════════════════════
            // 【直した中身】(string) だけで数えていたので、1.0E+129 のような
            //   指数表記が「6桁」に見えて、桁あふれを見逃していました。
            //   受入試験 R122-P01「129桁の整数は送らない」が不合格でした。
            //   十進数で書いたときの桁数（整数部＋小数部）で数えます。
            if (is_int($v)) {
                $digits = strlen((string) abs($v));
            } else {
                if (!is_finite($v)) return ($path === '' ? '(root)' : $path);
                $abs    = abs($v);
                $digits = strlen(sprintf('%.0F', $abs));     // 整数部（指数を展開）
                $short  = (string) $abs;                      // 最短表記
                if (stripos($short, 'e') === false) {
                    $dot = strpos($short, '.');
                    if ($dot !== false) $digits += strlen(substr($short, $dot + 1));
                }
            }
            if ($digits > self::MAX_DIGITS) return ($path === '' ? '(root)' : $path);
        }
        return null;
    }

    // ───────────────────────────────────────────────────────────────────────
    // INPUT_JSON の組み立て
    // ───────────────────────────────────────────────────────────────────────

    /** 登録索引のキー（先方の examples と同じ形。slot が無いときは文字 None） */
    public static function indexKey(array $e): string
    {
        $slot = ($e['slot_offset'] === null) ? 'None' : (string) $e['slot_offset'];
        $scope = (string) ($e['scope'] ?? 'HORSE');
        $field = (string) ($e['field'] ?? '');
        if ($scope === 'HORSE') {
            $no = (int) (($e['horse_nos'][0]) ?? 0);
            return 'horse/' . $no . '/' . $field . '/' . $slot;
        }
        if ($scope === 'PAIR') {
            $ns = array_map('intval', (array) ($e['horse_nos'] ?? []));
            sort($ns);
            return 'pair/' . implode('-', $ns) . '/' . $field . '/' . $slot;
        }
        if ($scope === 'COHORT') {
            $ns = array_map('intval', (array) ($e['applicable_horse_nos'] ?? []));
            sort($ns);
            return 'cohort/' . implode('-', $ns) . '/' . $field . '/' . $slot;
        }
        return 'race/' . $field . '/' . $slot;
    }

    /** 登録から項目辞書を作ります */
    public static function fieldCatalogFor(array $registry): array
    {
        $cat = [];
        foreach ($registry as $e) {
            $f = (string) ($e['field'] ?? '');
            if ($f === '' || isset($cat[$f])) continue;
            $def = self::FIELD_DEFS[$f] ?? null;
            $vt = 'number';
            if ($def === null) {
                $v = $e['value'] ?? null;
                if (is_bool($v))        $vt = 'boolean';
                elseif (is_string($v))  $vt = 'string';
                $def = ['unit' => (string) ($e['unit'] ?? 'unit'), 'value_type' => $vt,
                        'missing_allowed' => true, 'minimum' => null, 'maximum' => null];
            }
            $cat[$f] = [
                'unit'            => $def['unit'],
                'scope'           => (string) ($e['scope'] ?? 'HORSE'),
                'display_label'   => $f,
                'value_type'      => $def['value_type'],
                'missing_allowed' => $def['missing_allowed'],
                'minimum'         => $def['minimum'],
                'maximum'         => $def['maximum'],
            ];
        }
        return $cat;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-COMPACT  出典一覧（source_registry）を意味を変えずに詰めます（なる 10/23 4章(2)）
     * ═══════════════════════════════════════════════════════════════════════
     *   18頭・過去5走の入力は約88万バイト（約45万トークン）で、その86%が出典一覧です。
     *   出典一覧の中身は値が3%ほどで、残りは同じ文字の繰り返しです
     *   （race_id は全件同じ、日時は25種類だけ、項目ごとに単位・役割・種類が同じ）。
     *
     *   ここでは<b>辞書化と列形式</b>だけで詰めます。値・型・出典ID・対象馬・scope・
     *   SIGNAL／CONTEXT・依存元・利用可能時刻・欠損理由は1つも落としません。
     *   expandRegistry で元の出典一覧へ<b>1バイトも違わずに</b>戻せることを、
     *   使うたびに確かめます（戻せなければ詰めません）。
     *
     *   形（SOURCE_TABLE_V1）
     *     race_id         全件に共通の race_id
     *     columns         rows の列の名前
     *     time_dict       日時の辞書（rows では番号で指します）
     *     field_defaults  項目ごとに全件同じ値の列（unit・dependency_role・source_kind・
     *                     scope・formula_id・formula_version）。項目の中で1件でも違えば
     *                     その列は rows に残します
     *     rows            1行が出典1件。dependency_key は、source_ref と同じなら null、
     *                     source_ref の先頭 n 文字なら整数 n、それ以外は文字列のまま
     *
     *   凍結した入力（snapshot_hash の元）と検証は、これまでどおり元の出典一覧を使います。
     *   詰めるのは送信本文だけです。
     */
    public const SOURCE_TABLE_VERSION = 'SOURCE_TABLE_V1';
    private const COMPACT_TIME_COLS  = ['observed_at', 'available_at', 'computed_at'];
    private const COMPACT_FIELD_COLS = ['unit', 'dependency_role', 'source_kind', 'scope',
                                        'formula_id', 'formula_version'];

    /** @return array ['ok','table','reason'] */
    public static function compactRegistry(array $registry): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'table' => null, 'reason' => $why];
        if ($registry === []) { return $ng('出典一覧が空です'); }
        $all = ['source_ref', 'race_id', 'value', 'scope', 'horse_nos', 'applicable_horse_nos',
                'field', 'unit', 'slot_offset', 'observed_at', 'available_at', 'computed_at',
                'source_kind', 'parents', 'formula_id', 'formula_version',
                'dependency_key', 'dependency_role'];
        $raceId = null;
        foreach ($registry as $ref => $e) {
            if (!is_array($e)) { return $ng('出典の1件が配列ではありません: ' . $ref); }
            $keys = array_keys($e);
            sort($keys); $want = $all; sort($want);
            if ($keys !== $want) { return $ng('出典の項目が想定と違います: ' . $ref); }
            if ((string) $e['source_ref'] !== (string) $ref) { return $ng('source_ref とキーが違います: ' . $ref); }
            if ($raceId === null) { $raceId = $e['race_id']; }
            if ($e['race_id'] !== $raceId) { return $ng('race_id が全件同じではありません'); }
        }
        // 項目ごとに全件同じ列を探します
        $byField = [];
        foreach ($registry as $e) { $byField[(string) $e['field']][] = $e; }
        $fieldCols = [];
        foreach (self::COMPACT_FIELD_COLS as $c) {
            $same = true;
            foreach ($byField as $es) {
                $first = self::canonical($es[0][$c]);
                foreach ($es as $x) { if (self::canonical($x[$c]) !== $first) { $same = false; break 2; } }
            }
            if ($same) { $fieldCols[] = $c; }
        }
        $defaults = [];
        foreach ($byField as $f => $es) {
            foreach ($fieldCols as $c) { $defaults[$f][$c] = $es[0][$c]; }
        }
        // 日時の辞書（文字列だけ。null はそのまま null）
        $times = [];
        foreach ($registry as $e) {
            foreach (self::COMPACT_TIME_COLS as $c) {
                if (is_string($e[$c])) { $times[$e[$c]] = true; }
                elseif ($e[$c] !== null) { return $ng($c . ' が文字列でも null でもありません'); }
            }
        }
        $times = array_keys($times);
        sort($times, SORT_STRING);
        $ti = array_flip($times);
        $cols = ['source_ref', 'value', 'field', 'horse_nos', 'applicable_horse_nos',
                 'slot_offset', 'observed_at', 'available_at', 'computed_at', 'parents',
                 'dependency_key'];
        foreach (self::COMPACT_FIELD_COLS as $c) {
            if (!in_array($c, $fieldCols, true)) { $cols[] = $c; }
        }
        $rows = [];
        $rowNo = [];
        foreach (array_keys($registry) as $i => $ref) { $rowNo[(string) $ref] = $i; }
        foreach ($registry as $e) {
            $r = [];
            foreach ($cols as $c) {
                $v = $e[$c];
                if (in_array($c, self::COMPACT_TIME_COLS, true) && $v !== null) { $v = $ti[$v]; }
                // source_ref は詰めません。AI が根拠として引用する名前なので、AI に組み立てさせると
                // 引用の誤り（EVIDENCE_REF_UNKNOWN）が増えるためです。
                // if ($c === 'source_ref' && self::derivedSourceRef($e) === (string) $v) { $v = null; }   // 試したが採らない
                // parents は、出典一覧にある名前なら行の番号（0から）にします。無い名前（表の名前など）は文字列のまま
                if ($c === 'parents' && is_array($v)) {
                    $pv = [];
                    foreach ($v as $pp) { $pv[] = (is_string($pp) && isset($rowNo[$pp])) ? $rowNo[$pp] : $pp; }
                    $v = $pv;
                }
                if ($c === 'dependency_key') {
                    $sr = (string) $e['source_ref'];
                    if (!is_string($v)) { /* そのまま（文字列でない依存元は文字列のまま残せないので） */ }
                    elseif ($v === $sr) { $v = null; }
                    elseif ($v !== '' && strncmp($sr, $v, strlen($v)) === 0) { $v = strlen($v); }
                }
                $r[] = $v;
            }
            $rows[] = $r;
        }
        $table = ['encoding' => self::SOURCE_TABLE_VERSION, 'race_id' => $raceId,
                  'columns' => $cols, 'time_dict' => $times,
                  'field_defaults' => (object) $defaults, 'rows' => $rows];
        // 戻せることを確かめます（1バイトでも違えば詰めません）
        $back = self::expandRegistry(json_decode((string) json_encode($table,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true));
        if (!$back['ok'] || self::canonical($back['registry']) !== self::canonical($registry)) {
            return $ng('詰めた形から元の出典一覧へ戻せません（' . (string) ($back['reason'] ?? '中身が違う') . '）');
        }
        return ['ok' => true, 'table' => $table, 'reason' => null];
    }

    /** 馬1頭の出典の source_ref の決まった形（HORSE・馬番1つのときだけ）。作れなければ null */
    public static function derivedSourceRef(array $e): ?string
    {
        $hn = $e['horse_nos'] ?? null;
        if (!is_array($hn) || count($hn) !== 1 || !is_int($hn[0] ?? null)) { return null; }
        if (array_key_exists('scope', $e) && $e['scope'] !== 'HORSE') { return null; }
        $f = $e['field'] ?? null;
        if (!is_string($f) || $f === '') { return null; }
        $base = 'h' . $hn[0] . '_' . $f;
        $sl = $e['slot_offset'] ?? null;
        if ($sl === null) { return $base; }
        return is_int($sl) ? $base . '_' . $sl : null;
    }

    /**
     * @ANCHOR-20261023-COMPACT  送信本文を詰めます（出典一覧・索引・coverage の出典リスト）
     *   ・source_registry → source_table（上の compactRegistry）
     *   ・input_indices   → 送りません。source_table から indicesFor で作り直せることを確かめてから外します
     *   ・coverage の source_refs → source_fields（「その項目の出典を全部」）。全部と一致するときだけ置き換えます
     *   最後に expandWire で元の本文へ戻し、canonical が一致することを確かめます。
     *   一致しなければ詰めません（元の本文を送ります）。
     * @return array ['ok','wire','reason']
     */
    public static function compactWire(array $input): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'wire' => null, 'reason' => $why];
        $reg = $input['source_registry'] ?? null;
        if (!is_array($reg)) { return $ng('source_registry がありません'); }
        $c = self::compactRegistry($reg);
        if (!$c['ok']) { return $ng((string) $c['reason']); }
        $w = $input;
        unset($w['source_registry']);
        $w['source_table'] = $c['table'];
        if (array_key_exists('input_indices', $input)) {
            if (self::canonical(self::indicesFor($reg)) !== self::canonical($input['input_indices'])) {
                return $ng('input_indices が出典一覧から作り直せません');
            }
            unset($w['input_indices']);
            $w['input_indices_derived'] = true;
        }
        if (is_array($input['coverage'] ?? null)) {
            $byField = [];
            foreach ($reg as $ref => $e) { $byField[(string) $e['field']][] = (string) $ref; }
            foreach ($input['coverage'] as $k => $cv) {
                $refs = $cv['source_refs'] ?? null;
                if (!is_array($refs) || $refs === []) { continue; }
                $fs = [];
                foreach ($refs as $r) {
                    if (!isset($reg[$r])) { $fs = null; break; }
                    $fs[(string) $reg[$r]['field']] = true;
                }
                if ($fs === null) { continue; }
                $fs = array_keys($fs); sort($fs, SORT_STRING);
                $all = [];
                foreach ($fs as $f) { foreach ($byField[$f] as $r) { $all[] = $r; } }
                $a1 = $all; sort($a1, SORT_STRING); $a2 = array_map('strval', $refs); sort($a2, SORT_STRING);
                if ($a1 !== $a2) { continue; }
                $nc = $cv; unset($nc['source_refs']); $nc['source_fields'] = $fs;
                $w['coverage'][$k] = $nc;
            }
        }
        $back = self::expandWire(json_decode((string) json_encode($w,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true));
        if (!$back['ok'] || self::canonical($back['input']) !== self::canonical($input)) {
            return $ng('詰めた本文から元の本文へ戻せません（' . (string) ($back['reason'] ?? '中身が違う') . '）');
        }
        return ['ok' => true, 'wire' => $w, 'reason' => null];
    }

    /** compactWire の逆。@return array ['ok','input','reason'] */
    public static function expandWire(array $w): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'input' => null, 'reason' => $why];
        if (!is_array($w['source_table'] ?? null)) { return $ng('source_table がありません'); }
        $e = self::expandRegistry($w['source_table']);
        if (!$e['ok']) { return $ng((string) $e['reason']); }
        $reg = $e['registry'];
        $in = $w;
        unset($in['source_table'], $in['input_indices_derived']);
        $in['source_registry'] = $reg;
        if (!empty($w['input_indices_derived'])) { $in['input_indices'] = self::indicesFor($reg); }
        if (is_array($in['coverage'] ?? null)) {
            foreach ($in['coverage'] as $k => $cv) {
                if (!is_array($cv) || !array_key_exists('source_fields', $cv)) { continue; }
                $fs = array_flip(array_map('strval', (array) $cv['source_fields']));
                $refs = [];
                foreach ($reg as $ref => $x) { if (isset($fs[(string) $x['field']])) { $refs[] = (string) $ref; } }
                sort($refs, SORT_STRING);
                unset($cv['source_fields']); $cv['source_refs'] = $refs;
                $in['coverage'][$k] = $cv;
            }
        }
        return ['ok' => true, 'input' => $in, 'reason' => null];
    }

    /** SOURCE_TABLE_V1 から元の出典一覧へ戻します。@return array ['ok','registry','reason'] */
    public static function expandRegistry(array $t): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'registry' => null, 'reason' => $why];
        if (($t['encoding'] ?? null) !== self::SOURCE_TABLE_VERSION) { return $ng('形の版が違います'); }
        $cols = (array) ($t['columns'] ?? []);
        $times = (array) ($t['time_dict'] ?? []);
        $defs = (array) ($t['field_defaults'] ?? []);
        $out = [];
        foreach ((array) ($t['rows'] ?? []) as $i => $r) {
            if (!is_array($r) || count($r) !== count($cols)) { return $ng('行 ' . $i . ' の列数が違います'); }
            $e = array_combine($cols, $r);
            if ($e['source_ref'] === null) {
                $d = self::derivedSourceRef($e);
                if ($d === null) { return $ng('行 ' . $i . ' の source_ref を作れません'); }
                $e['source_ref'] = $d;
            }
            foreach (self::COMPACT_TIME_COLS as $c) {
                if ($e[$c] !== null) {
                    if (!is_int($e[$c]) || !isset($times[$e[$c]])) { return $ng('行 ' . $i . ' の日時の番号が不正です'); }
                    $e[$c] = $times[$e[$c]];
                }
            }
            $sr = (string) $e['source_ref'];
            if ($e['dependency_key'] === null) { $e['dependency_key'] = $sr; }
            elseif (is_int($e['dependency_key'])) { $e['dependency_key'] = substr($sr, 0, $e['dependency_key']); }
            $f = (string) $e['field'];
            foreach (self::COMPACT_FIELD_COLS as $c) {
                if (!array_key_exists($c, $e)) {
                    if (!isset($defs[$f]) || !array_key_exists($c, (array) $defs[$f])) {
                        return $ng('項目 ' . $f . ' の ' . $c . ' がありません');
                    }
                    $e[$c] = ((array) $defs[$f])[$c];
                }
            }
            $e['race_id'] = $t['race_id'] ?? null;
            $out[$sr] = $e;
        }
        // parents の行番号を名前へ戻します
        $names = array_keys($out);
        foreach ($out as $sr => $e) {
            if (!is_array($e['parents'])) { continue; }
            foreach ($e['parents'] as $pi => $pp) {
                if (is_int($pp)) {
                    if (!isset($names[$pp])) { return $ng($sr . ' の parents の行番号が不正です'); }
                    $out[$sr]['parents'][$pi] = $names[$pp];
                }
            }
        }
        return ['ok' => true, 'registry' => $out, 'reason' => null];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261102-PREDICTION-DICT  送信形式 SOURCE_DICT_V1（出典の名前で引ける形）
     * ═══════════════════════════════════════════════════════════════════════
     *   11/2 の実AIの試験で、1st（Claude）は表の形（SOURCE_TABLE_V1）だと各馬の値を拾えず、
     *   「参照が足りない」として根拠・点数を出しませんでした（2nd の DeepSeek は読めました）。
     *   この形は、出典一覧を「source_ref → その1件」の辞書のまま送り、
     *   決まった値（空の配列・null・source_ref と同じ dependency_key 等）だけを省きます。
     *   input_indices と coverage の扱いは表の形と同じです。
     *   詰めたあと必ず元へ戻し、1バイトでも違えば詰めません（元の本文を送ります）。
     * @return array ['ok','wire','reason']
     */
    public const SOURCE_DICT_VERSION = 'SOURCE_DICT_V1';

    public static function compactWireDict(array $input): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'wire' => null, 'reason' => $why];
        $t = self::compactWire($input);          // input_indices・coverage・表への変換が通ることを先に確かめる
        if (!$t['ok']) { return $ng((string) $t['reason']); }
        $reg = (array) $input['source_registry'];
        $tab = (array) $t['wire']['source_table'];
        $times = (array) $tab['time_dict'];
        $ti = array_flip($times);
        $rowCols = (array) $tab['columns'];
        $ent = [];
        foreach ($reg as $ref => $e) {
            $ref = (string) $ref;
            $o = ['value' => $e['value'], 'field' => $e['field']];
            foreach (['horse_nos', 'applicable_horse_nos', 'parents'] as $c) {
                if ($e[$c] !== []) { $o[$c] = $e[$c]; }
            }
            if ($e['slot_offset'] !== null) { $o['slot_offset'] = $e['slot_offset']; }
            foreach (self::COMPACT_TIME_COLS as $c) {
                if ($e[$c] !== null) {
                    if (!is_string($e[$c]) || !isset($ti[$e[$c]])) { return $ng($c . ' を番号にできません: ' . $ref); }
                    $o[$c] = $ti[$e[$c]];
                }
            }
            if ($e['dependency_key'] !== $ref) { $o['dependency_key'] = $e['dependency_key']; }
            foreach (self::COMPACT_FIELD_COLS as $c) {
                if (in_array($c, $rowCols, true)) { $o[$c] = $e[$c]; }   // 項目ごとに一定でない列は各件に残す
            }
            $ent[$ref] = $o;
        }
        $w = $t['wire'];
        unset($w['source_table']);
        $w['source_dict'] = ['encoding' => self::SOURCE_DICT_VERSION, 'race_id' => $tab['race_id'],
                             'time_dict' => $times, 'field_defaults' => $tab['field_defaults'],
                             'entries' => (object) $ent];
        $back = self::expandWireDict(json_decode((string) json_encode($w,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true));
        if (!$back['ok'] || self::canonical($back['input']) !== self::canonical($input)) {
            return $ng('詰めた本文から元の本文へ戻せません（' . (string) ($back['reason'] ?? '中身が違う') . '）');
        }
        return ['ok' => true, 'wire' => $w, 'reason' => null];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261102-PREDICTION-DICT2  送信形式 SOURCE_DICT_V2（V1 をもう一段詰めた形）
     * ═══════════════════════════════════════════════════════════════════════
     *   1st（claude-haiku-5-5）は、受け取る量が10万トークンを超えると単価が5倍になります。
     *   V1 は 12頭で約10.4万、18頭で約14.3万トークンで、少しずつ超えていました。
     *   V2 は、各件の項目名を1〜2文字にし、source_ref から一意に分かる値を省きます。
     *     v=value f=field h=horse_nos ah=applicable_horse_nos p=parents s=slot_offset
     *     o=observed_at a=available_at c=computed_at d=dependency_key
     *     f が無ければ … source_ref から「h{馬番}_」と「_{s}」を除いた残り
     *     h が無ければ … source_ref が h{馬番}_ で始まれば [馬番]、それ以外は []
     *     a が無ければ … o と同じ
     *     d が整数 n なら source_ref の先頭 n 文字、無ければ source_ref と同じ
     *   詰めたあと必ず元へ戻し、1バイトでも違えば詰めません。
     */
    public const SOURCE_DICT2_VERSION = 'SOURCE_DICT_V2';

    /** source_ref から馬番を読みます（h{馬番}_ で始まらなければ null） */
    private static function dict2HorseOf(string $ref): ?int
    {
        return preg_match('/^h(\d+)_/', $ref, $m) ? (int) $m[1] : null;
    }

    /** source_ref から field を作ります（作れなければ null） */
    private static function dict2FieldOf(string $ref, $slot): ?string
    {
        $n = self::dict2HorseOf($ref);
        $f = ($n === null) ? $ref : substr($ref, strlen('h' . $n . '_'));
        if ($slot !== null) {
            if (!is_int($slot)) { return null; }
            $suf = '_' . $slot;
            if (substr($f, -strlen($suf)) !== $suf) { return null; }
            $f = substr($f, 0, -strlen($suf));
        }
        return ($f === '' || $f === false) ? null : $f;
    }

    public static function compactWireDict2(array $input): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'wire' => null, 'reason' => $why];
        $t = self::compactWire($input);
        if (!$t['ok']) { return $ng((string) $t['reason']); }
        $reg = (array) $input['source_registry'];
        $tab = (array) $t['wire']['source_table'];
        $times = (array) $tab['time_dict'];
        $ti = array_flip($times);
        $rowCols = (array) $tab['columns'];
        $ent = [];
        foreach ($reg as $ref => $e) {
            $ref = (string) $ref;
            $o = ['v' => $e['value']];
            if (self::dict2FieldOf($ref, $e['slot_offset']) !== $e['field']) { $o['f'] = $e['field']; }
            $hn = self::dict2HorseOf($ref);
            if ($e['horse_nos'] !== (($hn === null) ? [] : [$hn])) { $o['h'] = $e['horse_nos']; }
            if ($e['applicable_horse_nos'] !== []) { $o['ah'] = $e['applicable_horse_nos']; }
            if ($e['parents'] !== []) { $o['p'] = $e['parents']; }
            if ($e['slot_offset'] !== null) { $o['s'] = $e['slot_offset']; }
            foreach (['observed_at' => 'o', 'available_at' => 'a', 'computed_at' => 'c'] as $c => $k) {
                if ($e[$c] === null) {
                    if ($c === 'available_at' && $e['observed_at'] !== null) { $o[$k] = null; }   // o と違う（null）ことを明示
                    continue;
                }
                if (!is_string($e[$c]) || !isset($ti[$e[$c]])) { return $ng($c . ' を番号にできません: ' . $ref); }
                if ($c === 'available_at' && $e['available_at'] === $e['observed_at']) { continue; }
                $o[$k] = $ti[$e[$c]];
            }
            $dk = $e['dependency_key'];
            if ($dk !== $ref) {
                $o['d'] = (is_string($dk) && $dk !== '' && strncmp($ref, $dk, strlen($dk)) === 0) ? strlen($dk) : $dk;
            }
            foreach (self::COMPACT_FIELD_COLS as $c) {
                if (in_array($c, $rowCols, true)) { $o[$c] = $e[$c]; }
            }
            $ent[$ref] = $o;
        }
        $w = $t['wire'];
        unset($w['source_table']);
        $w['source_dict'] = ['encoding' => self::SOURCE_DICT2_VERSION, 'race_id' => $tab['race_id'],
                             'time_dict' => $times, 'field_defaults' => $tab['field_defaults'],
                             'entries' => (object) $ent];
        $back = self::expandWireDict2(json_decode((string) json_encode($w,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true));
        if (!$back['ok'] || self::canonical($back['input']) !== self::canonical($input)) {
            return $ng('詰めた本文から元の本文へ戻せません（' . (string) ($back['reason'] ?? '中身が違う') . '）');
        }
        return ['ok' => true, 'wire' => $w, 'reason' => null];
    }

    /** compactWireDict2 の逆。@return array ['ok','input','reason'] */
    public static function expandWireDict2(array $w): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'input' => null, 'reason' => $why];
        $sd = $w['source_dict'] ?? null;
        if (!is_array($sd) || ($sd['encoding'] ?? null) !== self::SOURCE_DICT2_VERSION) { return $ng('source_dict がありません'); }
        $times = (array) ($sd['time_dict'] ?? []);
        $defs = (array) ($sd['field_defaults'] ?? []);
        $v1 = [];
        foreach ((array) ($sd['entries'] ?? []) as $ref => $o) {
            $ref = (string) $ref;
            if (!is_array($o) || !array_key_exists('v', $o)) { return $ng('出典 ' . $ref . ' の形が違います'); }
            $slot = array_key_exists('s', $o) ? $o['s'] : null;
            $field = array_key_exists('f', $o) ? $o['f'] : self::dict2FieldOf($ref, $slot);
            if (!is_string($field)) { return $ng('出典 ' . $ref . ' の field を作れません'); }
            $hn = self::dict2HorseOf($ref);
            $x = ['value' => $o['v'], 'field' => $field,
                  'horse_nos' => array_key_exists('h', $o) ? $o['h'] : (($hn === null) ? [] : [$hn]),
                  'applicable_horse_nos' => array_key_exists('ah', $o) ? $o['ah'] : [],
                  'parents' => array_key_exists('p', $o) ? $o['p'] : []];
            if ($slot !== null) { $x['slot_offset'] = $slot; }
            if ($x['horse_nos'] === []) { unset($x['horse_nos']); }
            if ($x['applicable_horse_nos'] === []) { unset($x['applicable_horse_nos']); }
            if ($x['parents'] === []) { unset($x['parents']); }
            foreach (['o' => 'observed_at', 'c' => 'computed_at'] as $k => $c) {
                if (array_key_exists($k, $o)) { $x[$c] = $o[$k]; }
            }
            if (array_key_exists('a', $o)) {
                if ($o['a'] !== null) { $x['available_at'] = $o['a']; }
            } elseif (array_key_exists('o', $o)) {
                $x['available_at'] = $o['o'];
            }
            if (array_key_exists('d', $o)) {
                $x['dependency_key'] = is_int($o['d']) ? substr($ref, 0, $o['d']) : $o['d'];
            }
            foreach (self::COMPACT_FIELD_COLS as $c) {
                if (array_key_exists($c, $o)) { $x[$c] = $o[$c]; }
            }
            $v1[$ref] = $x;
        }
        // ここから先は V1 の戻し方と同じ
        $w1 = $w;
        $w1['source_dict'] = ['encoding' => self::SOURCE_DICT_VERSION, 'race_id' => $sd['race_id'] ?? null,
                              'time_dict' => $times, 'field_defaults' => $defs, 'entries' => $v1];
        return self::expandWireDict($w1);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261102-PREDICTION-DICT3  送信形式 SOURCE_DICT_V3（V2 にもう一段）
     * ═══════════════════════════════════════════════════════════════════════
     *   11/2 の実AIの試験で、V2 は 12頭で約7.3万トークン（料金の境目10万の下）でしたが、
     *   18頭では入力が約10.7万トークンで、まだ境目を少し超えていました。V3 は次の3つを足します。
     *   ・source_dict.field_auto[field].d … その項目でいちばん多い dependency_key の形。
     *       「{h}」は source_ref の馬番に置き換えます。各件に d が無ければこれを使います。
     *   ・source_dict.field_auto[field].o … その項目でいちばん多い observed_at の番号。
     *     時点のある項目は field_auto[field].os[時点]（observed_at）・as[時点]（available_at）も持ちます。
     *     各件に o が無ければ os[s]→o の順、a が無ければ as[s]→（その件の o）の順で使います。
     *   ・field_catalog … display_label が項目名と同じ・maximum/minimum が null・missing_allowed が true のときは省く。
     *   これ以外は V2 と同じです。詰めたあと必ず元へ戻し、1バイトでも違えば詰めません。
     */
    public const SOURCE_DICT3_VERSION = 'SOURCE_DICT_V3';

    public static function compactWireDict3(array $input): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'wire' => null, 'reason' => $why];
        $t = self::compactWireDict2($input);
        if (!$t['ok']) { return $ng((string) $t['reason']); }
        $w = $t['wire'];
        $reg = (array) $input['source_registry'];
        $ent = (array) $w['source_dict']['entries'];
        $ti = array_flip((array) $w['source_dict']['time_dict']);
        $idx = static fn($v) => ($v === null) ? null : $ti[$v];
        // 項目ごと（時点があれば時点ごと）の「いちばん多い形」を数えます（元の出典一覧から）
        $dCnt = []; $oCnt = []; $osCnt = []; $asCnt = [];
        foreach ($reg as $ref => $e) {
            $ref = (string) $ref; $f = (string) $e['field'];
            $hn = self::dict2HorseOf($ref);
            $dk = $e['dependency_key'];
            if (is_string($dk)) {
                $tpl = ($hn !== null && strncmp($dk, 'h' . $hn . '_', strlen('h' . $hn . '_')) === 0)
                     ? 'h{h}_' . substr($dk, strlen('h' . $hn . '_')) : $dk;
                $dCnt[$f][$tpl] = ($dCnt[$f][$tpl] ?? 0) + 1;
            }
            $o = $idx($e['observed_at']); $a = $idx($e['available_at']);
            if ($o !== null) { $oCnt[$f][$o] = ($oCnt[$f][$o] ?? 0) + 1; }
            if (is_int($e['slot_offset'])) {
                $sk = (string) $e['slot_offset'];
                if ($o !== null) { $osCnt[$f][$sk][$o] = ($osCnt[$f][$sk][$o] ?? 0) + 1; }
                if ($a !== null) { $asCnt[$f][$sk][$a] = ($asCnt[$f][$sk][$a] ?? 0) + 1; }
            }
        }
        $top = static function (array $c) { arsort($c); $k = array_key_first($c); return ($c[$k] > 1) ? $k : null; };
        $auto = [];
        foreach ($dCnt as $f => $c) { $k = $top($c); if ($k !== null) { $auto[$f]['d'] = (string) $k; } }
        foreach ($oCnt as $f => $c) { $k = $top($c); if ($k !== null) { $auto[$f]['o'] = (int) $k; } }
        foreach ($osCnt as $f => $bySlot) {
            foreach ($bySlot as $sk => $c) { $k = $top($c); if ($k !== null) { $auto[$f]['os'][$sk] = (int) $k; } }
        }
        foreach ($asCnt as $f => $bySlot) {
            foreach ($bySlot as $sk => $c) { $k = $top($c); if ($k !== null) { $auto[$f]['as'][$sk] = (int) $k; } }
        }
        foreach ($reg as $ref => $e) {
            $ref = (string) $ref; $f = (string) $e['field'];
            $fa = $auto[$f] ?? [];
            $o = (array) $ent[$ref];
            $hn = self::dict2HorseOf($ref);
            // dependency_key
            if (isset($fa['d'])) {
                $def = ($hn !== null) ? str_replace('{h}', (string) $hn, $fa['d']) : $fa['d'];
                if ($e['dependency_key'] === $def) { unset($o['d']); }
                elseif (!array_key_exists('d', $o)) { $o['d'] = $ref; }
            }
            // observed_at・available_at（V2 の o・a を外して、既定と違うときだけ書き直します）
            unset($o['o'], $o['a']);
            $sk = is_int($e['slot_offset']) ? (string) $e['slot_offset'] : null;
            $actO = $idx($e['observed_at']); $actA = $idx($e['available_at']);
            $defO = ($sk !== null && isset($fa['os'][$sk])) ? $fa['os'][$sk] : ($fa['o'] ?? null);
            if ($actO !== $defO) { $o['o'] = $actO; }
            $defA = ($sk !== null && isset($fa['as'][$sk])) ? $fa['as'][$sk] : $actO;
            if ($actA !== $defA) { $o['a'] = $actA; }
            $ent[$ref] = $o;
        }
        foreach ($auto as $f => $fa) {
            foreach (['os', 'as'] as $k) { if (isset($fa[$k])) { $auto[$f][$k] = (object) $fa[$k]; } }
        }
        $w['source_dict']['encoding'] = self::SOURCE_DICT3_VERSION;
        $w['source_dict']['entries'] = (object) $ent;
        $w['source_dict']['field_auto'] = (object) $auto;
        if (is_array($w['field_catalog'] ?? null)) {
            $fc = [];
            foreach ($w['field_catalog'] as $k => $v) {
                if (!is_array($v)) { $fc = null; break; }
                if (($v['display_label'] ?? null) === (string) $k) { unset($v['display_label']); }
                foreach (['maximum', 'minimum'] as $mk) { if (array_key_exists($mk, $v) && $v[$mk] === null) { unset($v[$mk]); } }
                if (($v['missing_allowed'] ?? null) === true) { unset($v['missing_allowed']); }
                $fc[$k] = (object) $v;
            }
            if ($fc !== null) { $w['field_catalog'] = (object) $fc; $w['field_catalog_compact'] = true; }
        }
        $back = self::expandWireDict3(json_decode((string) json_encode($w,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true));
        if (!$back['ok'] || self::canonical($back['input']) !== self::canonical($input)) {
            return $ng('詰めた本文から元の本文へ戻せません（' . (string) ($back['reason'] ?? '中身が違う') . '）');
        }
        return ['ok' => true, 'wire' => $w, 'reason' => null];
    }

    /** compactWireDict3 の逆。@return array ['ok','input','reason'] */
    public static function expandWireDict3(array $w): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'input' => null, 'reason' => $why];
        $sd = $w['source_dict'] ?? null;
        if (!is_array($sd) || ($sd['encoding'] ?? null) !== self::SOURCE_DICT3_VERSION) { return $ng('source_dict がありません'); }
        $auto = (array) ($sd['field_auto'] ?? []);
        $ent = [];
        foreach ((array) ($sd['entries'] ?? []) as $ref => $o) {
            $ref = (string) $ref;
            if (!is_array($o)) { return $ng('出典 ' . $ref . ' の形が違います'); }
            $slot = array_key_exists('s', $o) ? $o['s'] : null;
            $f = array_key_exists('f', $o) ? $o['f'] : self::dict2FieldOf($ref, $slot);
            if (!is_string($f)) { return $ng('出典 ' . $ref . ' の field を作れません'); }
            $fa = (array) ($auto[$f] ?? []);
            $hn = self::dict2HorseOf($ref);
            // dependency_key → V2 の書き方へ
            if (!array_key_exists('d', $o) && isset($fa['d'])) {
                $def = ($hn !== null) ? str_replace('{h}', (string) $hn, (string) $fa['d']) : (string) $fa['d'];
                if ($def !== $ref) { $o['d'] = $def; }
            } elseif (array_key_exists('d', $o) && $o['d'] === $ref) {
                unset($o['d']);
            }
            // observed_at・available_at → V2 の書き方へ
            $sk = is_int($slot) ? (string) $slot : null;
            $os = (array) ($fa['os'] ?? []); $as = (array) ($fa['as'] ?? []);
            $actO = array_key_exists('o', $o) ? $o['o']
                  : (($sk !== null && array_key_exists($sk, $os)) ? $os[$sk] : ($fa['o'] ?? null));
            $actA = array_key_exists('a', $o) ? $o['a']
                  : (($sk !== null && array_key_exists($sk, $as)) ? $as[$sk] : $actO);
            unset($o['o'], $o['a']);
            if ($actO !== null) { $o['o'] = $actO; }
            if ($actA === $actO && $actO !== null) { /* V2 では書かない＝o と同じ */ }
            elseif ($actA === null && $actO === null) { /* 書かない＝null */ }
            else { $o['a'] = $actA; }
            $ent[$ref] = $o;
        }
        $w2 = $w;
        $w2['source_dict'] = ['encoding' => self::SOURCE_DICT2_VERSION, 'race_id' => $sd['race_id'] ?? null,
                              'time_dict' => $sd['time_dict'] ?? [], 'field_defaults' => $sd['field_defaults'] ?? [],
                              'entries' => $ent];
        if (!empty($w['field_catalog_compact']) && is_array($w['field_catalog'] ?? null)) {
            $fc = [];
            foreach ($w['field_catalog'] as $k => $v) {
                $v = (array) $v;
                if (!array_key_exists('display_label', $v)) { $v['display_label'] = (string) $k; }
                foreach (['maximum', 'minimum'] as $mk) { if (!array_key_exists($mk, $v)) { $v[$mk] = null; } }
                if (!array_key_exists('missing_allowed', $v)) { $v['missing_allowed'] = true; }
                $fc[$k] = $v;
            }
            $w2['field_catalog'] = $fc;
        }
        unset($w2['field_catalog_compact']);
        return self::expandWireDict2($w2);
    }

    /** compactWireDict の逆。@return array ['ok','input','reason'] */
    public static function expandWireDict(array $w): array
    {
        $ng = static fn(string $why) => ['ok' => false, 'input' => null, 'reason' => $why];
        $sd = $w['source_dict'] ?? null;
        if (!is_array($sd) || ($sd['encoding'] ?? null) !== self::SOURCE_DICT_VERSION) { return $ng('source_dict がありません'); }
        $times = (array) ($sd['time_dict'] ?? []);
        $defs = (array) ($sd['field_defaults'] ?? []);
        $reg = [];
        foreach ((array) ($sd['entries'] ?? []) as $ref => $o) {
            $ref = (string) $ref;
            if (!is_array($o) || !array_key_exists('value', $o) || !is_string($o['field'] ?? null)) {
                return $ng('出典 ' . $ref . ' の形が違います');
            }
            $e = ['source_ref' => $ref, 'race_id' => $sd['race_id'] ?? null,
                  'value' => $o['value'], 'field' => $o['field']];
            foreach (['horse_nos', 'applicable_horse_nos', 'parents'] as $c) {
                $e[$c] = array_key_exists($c, $o) ? $o[$c] : [];
            }
            $e['slot_offset'] = array_key_exists('slot_offset', $o) ? $o['slot_offset'] : null;
            foreach (self::COMPACT_TIME_COLS as $c) {
                if (!array_key_exists($c, $o)) { $e[$c] = null; continue; }
                if (!is_int($o[$c]) || !isset($times[$o[$c]])) { return $ng($ref . ' の ' . $c . ' の番号が不正です'); }
                $e[$c] = $times[$o[$c]];
            }
            $e['dependency_key'] = array_key_exists('dependency_key', $o) ? $o['dependency_key'] : $ref;
            foreach (self::COMPACT_FIELD_COLS as $c) {
                if (array_key_exists($c, $o)) { $e[$c] = $o[$c]; continue; }
                $fd = (array) ($defs[$o['field']] ?? []);
                if (!array_key_exists($c, $fd)) { return $ng('項目 ' . $o['field'] . ' の ' . $c . ' がありません'); }
                $e[$c] = $fd[$c];
            }
            $reg[$ref] = $e;
        }
        $in = $w;
        unset($in['source_dict'], $in['input_indices_derived']);
        $in['source_registry'] = $reg;
        if (!empty($w['input_indices_derived'])) { $in['input_indices'] = self::indicesFor($reg); }
        if (is_array($in['coverage'] ?? null)) {
            foreach ($in['coverage'] as $k => $cv) {
                if (!is_array($cv) || !array_key_exists('source_fields', $cv)) { continue; }
                $fs = array_flip(array_map('strval', (array) $cv['source_fields']));
                $refs = [];
                foreach ($reg as $r => $x) { if (isset($fs[(string) $x['field']])) { $refs[] = (string) $r; } }
                sort($refs, SORT_STRING);
                unset($cv['source_fields']); $cv['source_refs'] = $refs;
                $in['coverage'][$k] = $cv;
            }
        }
        return ['ok' => true, 'input' => $in, 'reason' => null];
    }

    /** 登録から索引を作ります（同じ場所に2件あれば並べます） */
    public static function indicesFor(array $registry): array
    {
        $idx = [];
        foreach ($registry as $ref => $e) {
            $k = self::indexKey($e);
            $idx[$k][] = (string) ($e['source_ref'] ?? $ref);
        }
        foreach ($idx as $k => $v) {
            $v = array_values(array_unique($v));
            sort($v, SORT_STRING);
            $idx[$k] = $v;
        }
        return $idx;
    }

    /**
     * 文脈から INPUT_JSON を組み立てます。
     *
     * $ctx に入れるもの（呼び出し側が正本から用意します）
     *   race_id            文字列
     *   anchor_at          発走時刻（RFC3339・秒必須・TZ必須）
     *   sales_close_at     投票締切（同）。<b>null なら作りません。</b>
     *   registry           source_ref => 登録1件（source_registry_schema と同じ形）
     *   audit              スロット => 取得監査1件（collection_audit と同じ形）
     *   coverage           7区分の状態
     *   entry_nums         出馬表の馬番（任意・集合照合に使う）
     *   cancelled          正式取消の馬番（任意）
     *   legacy_gap_type    旧断層タイプ（無ければ null）
     *   similar            X6 の類似統計（無ければ null＝N>=7で不明）
     *
     * @return array ['ok','input','code','reason','derived']
     */
    public static function buildInput(array $ctx, string $role): array
    {
        $ng = static fn(string $code, string $why) =>
            ['ok' => false, 'input' => null, 'code' => $code, 'reason' => $why,
             'derived' => []];

        if (!in_array($role, ['1st', '2nd'], true)) {
            return $ng('ROLE_INVALID', 'role は 1st か 2nd だけです');
        }
        $raceId = (string) ($ctx['race_id'] ?? '');
        if ($raceId === '') return $ng('ENTRY_MISSING', 'race_id がありません');

        // ── 時刻契約 ───────────────────────────────────────────────────
        $anchorUs = BaganrikiTime::us($ctx['anchor_at'] ?? null);
        if ($anchorUs === null) {
            return $ng('TIME_CONTRACT_INVALID',
                       '発走時刻を RFC3339（秒・タイムゾーン必須）として読めません');
        }
        $salesUs = BaganrikiTime::us($ctx['sales_close_at'] ?? null);
        if ($salesUs === null) {
            // 契約11.2。推測した締切でAIを呼ぶことはしません。
            return $ng('SALES_CLOSE_UNRESOLVED',
                       '投票締切の正本が特定できないため送信しません（契約11.2）');
        }
        // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  時刻契約の版は、材料を集めたときの版（無ければ v1）
        $timeVersion = (string) ($ctx['time_contract_version'] ?? BaganrikiTime::TIME_CONTRACT_V1);
        if (!in_array($timeVersion, [BaganrikiTime::TIME_CONTRACT_V1, BaganrikiTime::TIME_CONTRACT_V2], true)) {
            return $ng('TIME_CONTRACT_INVALID', '時刻契約の版が分かりません: ' . $timeVersion);
        }
        $time = [
            // 'version'            => 'baganriki-post-slot-grace-v1',   // 旧
            'version'            => $timeVersion,
            'anchor_kind'        => 'POST_TIME',
            'anchor_at'          => self::rfc3339($anchorUs),
            'snapshot_offsets'   => BaganrikiTime::INPUT_SLOTS,
            'sampling_policy_id' => 'SLOT_FIRST_VALID_COMMITTED_V1',
            'reply_deadline'     => self::rfc3339(BaganrikiTime::replyDeadline($salesUs)),
            'publish_deadline'   => self::rfc3339(BaganrikiTime::publishDeadline($salesUs)),
            'sales_close_at'     => self::rfc3339($salesUs),
            'safety_margin_ms'   => BaganrikiTime::SAFETY_MARGIN_SEC * 1000,
            'acquisition_grace_ms' => BaganrikiTime::GRACE_MS,
        ];
        $cutoffUs = BaganrikiTime::cutoff($anchorUs);
        // $freezeUs = BaganrikiTime::inputFreeze($anchorUs);   // 旧（T−300秒固定）
        // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  材料を集めたときの情報上限を使う。無ければ今までどおり T−300秒
        $freezeUs = (isset($ctx['input_freeze_us']) && is_int($ctx['input_freeze_us']))
                  ? $ctx['input_freeze_us'] : BaganrikiTime::inputFreeze($anchorUs);
        if (!BaganrikiTime::freezeInWindow($anchorUs, $freezeUs, $timeVersion)) {
            return $ng('TIME_CONTRACT_INVALID', '情報上限が時刻契約の範囲にありません');
        }

        // ── 取得監査 ───────────────────────────────────────────────────
        $audit = (array) ($ctx['audit'] ?? []);
        if (!isset($audit[6]) && !isset($audit['6'])) {
            return $ng('COLLECTION_AUDIT_MISSING',
                       '6分前の取得監査がありません（6分前は必須です）');
        }
        $auditOut = [];
        foreach (BaganrikiTime::INPUT_SLOTS as $slot) {
            $a = $audit[$slot] ?? $audit[(string) $slot] ?? null;
            if ($a === null) {
                $auditOut[(string) $slot] = ['status' => 'UNAVAILABLE',
                    'reason' => 'このスロットの採用できる監査行がありません'];
                continue;
            }
            $auditOut[(string) $slot] = $a;
        }
        if (($auditOut['6']['status'] ?? '') !== 'ADOPTED') {
            return $ng('COLLECTION_AUDIT_MISSING',
                       '6分前の取得監査が ADOPTED ではありません');
        }

        // ── 登録・辞書・索引 ──────────────────────────────────────────
        $registry = (array) ($ctx['registry'] ?? []);
        if ($registry === []) {
            return $ng('SOURCE_REGISTRY_EMPTY', 'source_registry が空です');
        }
        foreach ($registry as $ref => $e) {
            $miss = [];
            foreach (['source_ref', 'race_id', 'value', 'scope', 'horse_nos',
                      'applicable_horse_nos', 'field', 'unit', 'slot_offset',
                      'observed_at', 'available_at', 'computed_at', 'source_kind',
                      'parents', 'formula_id', 'formula_version', 'dependency_key',
                      'dependency_role'] as $k) {
                if (!array_key_exists($k, (array) $e)) $miss[] = $k;
            }
            if ($miss !== []) {
                return $ng('SOURCE_REGISTRY_INVALID',
                           $ref . ' に ' . implode('・', $miss) . ' がありません');
            }
        }

        // ── 凍結集合と N ───────────────────────────────────────────────
        //   6分前の単勝だけが凍結順位の土台です。欠けていれば作りません。
        $win6 = [];
        foreach ($registry as $e) {
            if (($e['field'] ?? '') !== 'win_odds') continue;
            if ((int) ($e['slot_offset'] ?? -1) !== 6) continue;
            $no = (int) (($e['horse_nos'][0]) ?? 0);
            if ($no <= 0) continue;
            $v = $e['value'];
            if ($v === null) continue;
            $win6[$no] = is_string($v) ? trim($v) : $v;
        }
        $cancelled = array_map('intval', (array) ($ctx['cancelled'] ?? []));
        foreach ($cancelled as $no) unset($win6[$no]);
        if (count($win6) < 2) {
            return $ng('ODDS_SERIES_INCOMPLETE',
                       '6分前の単勝オッズが2頭ぶんに届きません');
        }
        $entryNums = ($ctx['entry_nums'] ?? null);
        if (is_array($entryNums) && $entryNums !== []) {
            $lack = array_values(array_diff(
                array_map('intval', $entryNums), $cancelled, array_keys($win6)));
            if ($lack !== []) {
                return $ng('ODDS_SERIES_INCOMPLETE',
                           '6分前の単勝が無い馬がいます: ' . implode('・', $lack));
            }
        }
        $fr = BaganrikiSnapshot::frozenRank($win6);
        if (($fr['ok'] ?? false) !== true) {
            return $ng((string) ($fr['code'] ?? 'FREEZE_ODDS_MISSING'),
                       (string) ($fr['reason'] ?? '凍結順位を作れません'));
        }
        $frozen = $fr['rank'];                            // 馬番 => 凍結人気
        $N = count($frozen);
        $runners = [];
        foreach ($frozen as $no => $rank) {
            $runners[] = ['horse_no' => (int) $no, 'win_rank' => (int) $rank,
                          'band' => BaganrikiAllocation::v04Band((int) $rank)];
        }
        usort($runners, static fn($a, $b) => $a['win_rank'] <=> $b['win_rank']);

        // ── 信号・mode ────────────────────────────────────────────────
        //   判定は本番側の実装（BaganrikiSignals）をそのまま使います。
        //   検証側（--mode-check）との72レース突き合わせで食い違い0です。
        $pHorses = self::horsesForSignals($registry, $frozen);
        $pairs   = BaganrikiSignals::v04Pairs($pHorses);
        $flags   = BaganrikiSignals::v04DeriveFlags($N, $pairs, $pHorses,
                                                    $ctx['similar'] ?? null);
        $cls     = BaganrikiSignals::v04Classify($flags['flags']);
        $tri = static function ($v) {
            if ($v === 'TRUE')  return true;
            if ($v === 'FALSE') return false;
            return null;
        };
        $signals = [];
        foreach (['X1', 'X2', 'X3', 'X4', 'X5', 'X6', 'Z'] as $k) {
            $signals[$k] = $tri($flags['flags'][$k] ?? 'UNKNOWN');
        }

        // ── 配分 ──────────────────────────────────────────────────────
        $alloc = BaganrikiAllocation::v04Allocation($N, $cls);
        $baseQ = $alloc['base_quota'] ?? null;
        $selEnabled = (bool) $cls['selection_enabled'];
        $quota = $alloc['quota'];
        if ($role === '1st') {
            $roleQuota = $selEnabled ? $quota : null;
            $roleCap   = $selEnabled ? (int) $alloc['target_count'] : 0;
        } else {
            $roleQuota = $selEnabled ? $alloc['quota_2nd'] : null;
            $roleCap   = $selEnabled ? min(5, (int) $alloc['target_count']) : 0;
        }

        // ── 組み立て ──────────────────────────────────────────────────
        $input = [
            'schema_version'        => self::SCHEMA_VERSION,
            'prompt_version'        => self::PROMPT_VERSION,
            'race_id'               => $raceId,
            'snapshot_hash'         => '',           // あとで入れます
            'snapshot_hash_version' => self::HASH_VERSION,
            'time_contract'         => $time,
            'cutoff_at'             => self::rfc3339($cutoffUs),
            'input_freeze_at'       => self::rfc3339($freezeUs),
            'N'                     => $N,
            'runners'               => $runners,
            'source_registry'       => $registry,
            'field_catalog'         => self::fieldCatalogFor($registry),
            'input_indices'         => self::indicesFor($registry),
            'legacy_gap_type'       => $ctx['legacy_gap_type'] ?? null,
            'signals'               => $signals,
            'S'                     => $cls['S'],
            'S_min'                 => (int) $cls['S_min'],
            'S_max'                 => (int) $cls['S_max'],
            'mode'                  => (string) $cls['mode'],
            'target_count'          => (int) $alloc['target_count'],
            'base_quota'            => $baseQ,
            'quota'                 => $quota,
            'quota_adjustments'     => array_values((array) $alloc['quota_adjustments']),
            'selection_enabled'     => $selEnabled,
            'display_rating'        => $cls['display_rating'],
            'learning_stage'        => 'COLLECT_ONLY',
            'model_version'         => null,
            'ai_role'               => $role,
            'role_cap'              => $roleCap,
            'role_quota'            => $roleQuota,
            'coverage'              => self::coverageOf($ctx['coverage'] ?? null),
            'mode_status'           => (string) $cls['mode_status'],
            'allocation_mode'       => $cls['allocation_mode'],
            'mode_policy_id'        => (string) $cls['mode_policy_id'],
            'collection_audit'      => $auditOut,
        ];
        $h = self::commonHash($input);
        if (!$h['ok']) return $ng('SNAPSHOT_HASH_FAILED', (string) $h['reason']);
        $input['snapshot_hash'] = $h['hash'];

        $miss = array_values(array_diff(self::TOP_KEYS, array_keys($input)));
        if ($miss !== []) {
            return $ng('INPUT_KEYS_MISSING', '必須キーが足りません: ' . implode('・', $miss));
        }
        $extra = array_values(array_diff(array_keys($input), self::TOP_KEYS));
        if ($extra !== []) {
            return $ng('INPUT_KEYS_EXTRA', '余分なキーがあります: ' . implode('・', $extra));
        }

        return ['ok' => true, 'input' => $input, 'code' => null, 'reason' => null,
                'derived' => ['N' => $N, 'flags' => $flags['flags'],
                              'mode' => $cls['mode'], 'mode_status' => $cls['mode_status'],
                              'snapshot_hash' => $h['hash'],
                              'common_json_bytes' => strlen((string) $h['json'])]];
    }

    /** coverage を7区分そろえます。指定が無い区分は UNAVAILABLE にします。 */
    public static function coverageOf($given): array
    {
        $g = is_array($given) ? $given : [];
        $out = [];
        foreach (self::COVERAGE_KEYS as $k) {
            $v = $g[$k] ?? null;
            if (is_array($v) && ($v['status'] ?? '') === 'INCLUDED'
                && !empty($v['source_refs'])) {
                $refs = array_values(array_unique(array_map('strval', $v['source_refs'])));
                $out[$k] = ['status' => 'INCLUDED', 'source_refs' => $refs];
                continue;
            }
            $reason = (is_array($v) && isset($v['reason']) && (string) $v['reason'] !== '')
                    ? (string) $v['reason']
                    : 'この区分の実データを入力へ入れていません';
            $out[$k] = ['status' => 'UNAVAILABLE', 'reason' => $reason];
        }
        return $out;
    }

    /**
     * 信号判定が受け取る形（6分前人気の昇順）へ直します。
     * tan_series / fuku_min_series は「スロット => 元の十進数文字列」です。
     * 丸めません（@ANCHOR-20261020-PREDICTION-EXACT と同じ扱い）。
     */
    public static function horsesForSignals(array $registry, array $frozen): array
    {
        $byNo = [];
        foreach ($registry as $e) {
            $scope = (string) ($e['scope'] ?? '');
            if ($scope !== 'HORSE') continue;
            $no = (int) (($e['horse_nos'][0]) ?? 0);
            if ($no <= 0 || !isset($frozen[$no])) continue;
            $slot = $e['slot_offset'];
            if ($slot === null) continue;
            $v = $e['value'];
            if ($v === null) continue;
            $s = is_string($v) ? trim($v) : self::numToDecimalString($v);
            if ($s === '') continue;
            $f = (string) ($e['field'] ?? '');
            // tan_series は v04Pairs() が読むキー名です（単勝の時系列）。
            if ($f === 'win_odds')  $byNo[$no]['tan_series'][(int) $slot]      = $s;
            if ($f === 'place_min') $byNo[$no]['fuku_min_series'][(int) $slot] = $s;
        }
        $order = $frozen;
        asort($order);
        $out = [];
        foreach (array_keys($order) as $no) {
            $out[] = [
                'num'             => (int) $no,
                'popularity'      => (int) $frozen[$no],
                'tan_series'      => $byNo[$no]['tan_series']      ?? [],
                'fuku_min_series' => $byNo[$no]['fuku_min_series'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * 数値を十進数の文字列へ（指数表記にしない・余分な0を付けない）
     *
     * ══════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-DIGITS2
     * ══════════════════════════════════════════════════════════════════
     *   【旧】`number_format($v, 12)` でした。小数13桁目から下を黙って
     *     落とします。float で正確に持てている値でも、ここで桁が減ります。
     *   【いま】float は `(string) $v` を使います。PHP は
     *     serialize_precision=-1 のとき<b>元へ戻せる最短の表記</b>を返すので、
     *     桁を減らしません。指数表記になったときだけ十進数へ直します。
     *   文字列（十進数のまま持っている値）は、いまも trim するだけです。
     *   126桁の小数でも1桁も落ちません。
     */
    public static function numToDecimalString($v): string
    {
        if (is_int($v)) return (string) $v;
        if (!is_float($v)) return trim((string) $v);
        if (!is_finite($v)) return '';
        // `(string) $v` は ini の precision（既定14）に従うので桁が減ります。
        // `json_encode` は serialize_precision に従い、既定（-1）では
        // 元へ戻せる最短表記を返すので、こちらを使います。
        $s = json_encode($v);
        if (!is_string($s) || $s === '') { return ''; }
        if (strpbrk($s, 'eE') !== false) { $s = self::expToDecimal($s); }
        if ($s === '') { return ''; }
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-DIGITS2  指数表記を、桁を落とさずに小数表記へ
     *   1.0e-7 → 0.0000001、1.0e+20 → 100000000000000000000。
     *   文字として小数点を動かすだけなので、float を通りません。
     *   読めない形は空文字を返します（作り話をしないため）。
     */
    public static function expToDecimal(string $s): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?[eE]([+-]?\d+)$/',
                       trim($s), $m) !== 1) {
            return '';
        }
        $sign = $m[1];
        $int  = $m[2];
        $frac = $m[3] ?? '';
        $exp  = (int) $m[4];
        $digits = $int . $frac;
        $point  = strlen($int) + $exp;      // 小数点はこの桁数のうしろ
        if ($point <= 0) {
            $out = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $out = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $out = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        return $sign . $out;
    }

    // ───────────────────────────────────────────────────────────────────────
    // 送信の一式
    // ───────────────────────────────────────────────────────────────────────

    /**
     * system 本文と user 本文を1組にします。送れる状態でなければ ok=false。
     * @return array ['ok','role','system','system_sha256','user','user_bytes',
     *                'input','snapshot_hash','code','reason','issues','derived']
     */
    public static function assemble(array $ctx, string $role): array
    {
        $sys = self::systemBody($role);
        if (!$sys['ok']) {
            return ['ok' => false, 'role' => $role, 'code' => 'PROMPT_BODY_INVALID',
                    'reason' => (string) $sys['reason'], 'system' => null,
                    'system_sha256' => null, 'user' => null, 'user_bytes' => 0,
                    'input' => null, 'snapshot_hash' => null, 'issues' => [],
                    'derived' => []];
        }
        $b = self::buildInput($ctx, $role);
        if (!$b['ok']) {
            return ['ok' => false, 'role' => $role, 'code' => (string) $b['code'],
                    'reason' => (string) $b['reason'], 'system' => null,
                    'system_sha256' => $sys['sha256'], 'user' => null, 'user_bytes' => 0,
                    'input' => null, 'snapshot_hash' => null, 'issues' => [],
                    'derived' => []];
        }
        $enc = self::encode($b['input']);
        if (!$enc['ok']) {
            return ['ok' => false, 'role' => $role, 'code' => 'INPUT_ENCODE_FAILED',
                    'reason' => (string) $enc['reason'], 'system' => null,
                    'system_sha256' => $sys['sha256'], 'user' => null, 'user_bytes' => 0,
                    'input' => $b['input'], 'snapshot_hash' => null, 'issues' => [],
                    'derived' => $b['derived']];
        }
        $asm = ['ok' => true, 'role' => $role, 'system' => $sys['body'],
                'system_sha256' => $sys['sha256'], 'user' => $enc['json'],
                'user_bytes' => $enc['bytes'], 'input' => $b['input'],
                'snapshot_hash' => $b['input']['snapshot_hash'], 'code' => null,
                'reason' => null, 'issues' => [], 'derived' => $b['derived']];
        $asm['issues'] = self::selfCheck($asm);
        if ($asm['issues'] !== []) {
            $asm['ok'] = false;
            $asm['code'] = 'SEND_SELF_CHECK_FAILED';
            $asm['reason'] = implode('／', array_column($asm['issues'], 'reason'));
        }
        return $asm;
    }

    /** @ANCHOR-20261020-PREDICTION-STRICTJSON  参照の上限（詳細設計書8章） */
    public const MAX_SOURCE_REFS = 20000;

    public static function selfCheck(array $asm): array
    {
        $bad = [];
        $sys = (string) ($asm['system'] ?? '');
        $usr = (string) ($asm['user'] ?? '');
        $in  = (array) ($asm['input'] ?? []);
        $add = static function (array &$bad, string $code, string $why) {
            $bad[] = ['code' => $code, 'reason' => $why];
        };
        if (strpos($sys, self::BEGIN) === false || strpos($sys, self::END) === false) {
            $add($bad, 'MARKER_MISSING', 'system本文にBEGIN/ENDの指定がありません');
        }
        if (strpos($sys, self::PROMPT_VERSION) === false) {
            $add($bad, 'PROMPT_VERSION_MISMATCH', 'system本文のprompt_versionが違います');
        }
        if (($in['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $add($bad, 'SCHEMA_VERSION_MISMATCH', 'INPUT_JSONのschema_versionが違います');
        }
        if (($in['prompt_version'] ?? null) !== self::PROMPT_VERSION) {
            $add($bad, 'PROMPT_VERSION_MISMATCH', 'INPUT_JSONのprompt_versionが違います');
        }
        if (($in['snapshot_hash_version'] ?? null) !== self::HASH_VERSION) {
            $add($bad, 'HASH_VERSION_MISMATCH', 'snapshot_hash_versionが違います');
        }
        if (($in['ai_role'] ?? null) !== ($asm['role'] ?? null)) {
            $add($bad, 'ROLE_MISMATCH', 'ai_roleと送り先のroleが違います');
        }
        $h = self::commonHash($in);
        if (!$h['ok'] || $h['hash'] !== ($in['snapshot_hash'] ?? null)) {
            $add($bad, 'SNAPSHOT_HASH_MISMATCH', 'snapshot_hashが共通本文と合いません');
        }
        if ($usr === '' || $usr[0] !== '{') {
            $add($bad, 'INPUT_NOT_OBJECT', 'user本文がJSONオブジェクト1個ではありません');
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-STRICTJSON  送る前に、自分の本文も厳密に読む
        // ══════════════════════════════════════════════════════════════
        //   【旧】json_decode が null でないことしか見ていませんでした。
        //     重複キー・4MiB超・128桁超・指数の範囲外が素通りします。
        //     相手に読めない本文を送ってから気づく、ということになります。
        //   詳細設計書8章の「生成後のDTOも同じ検査を行う」はここです。
        $sj = BaganrikiJson::strict($usr);
        if (!$sj['ok']) {
            $add($bad, 'INPUT_NOT_PARSEABLE',
                 'user本文を厳密に読み直せません（' . $sj['code'] . '：'
                 . $sj['reason'] . '）');
        }
        // 参照20,000件（詳細設計書8章）。source_registry 固有の限度です。
        $nRef = count((array) ($in['source_registry'] ?? []));
        if ($nRef > self::MAX_SOURCE_REFS) {
            $add($bad, 'SOURCE_REGISTRY_TOO_MANY',
                 'source_registry が ' . number_format($nRef) . ' 件で、上限 '
                 . number_format(self::MAX_SOURCE_REFS) . ' 件を超えています');
        }
        if (strpos($usr, '{{') !== false) {
            $add($bad, 'PLACEHOLDER_LEFT', 'user本文に未置換の差し込み記号があります');
        }
        // 選出停止のときは枠を渡しません（契約7章）
        if (($in['selection_enabled'] ?? null) === false) {
            // role_quota は null が正しい値なので ?? で既定値を当てません
            // （?? は null を「無い」と見なすため、null のときに誤検知します）
            $rq0 = array_key_exists('role_quota', $in) ? $in['role_quota'] : '(キー無し)';
            if (($in['role_cap'] ?? null) !== 0 || $rq0 !== null) {
                $add($bad, 'SELECTION_DISABLED_MISMATCH',
                     'selection_enabled=false なのに role_cap/role_quota が空ではありません');
            }
        } else {
            $rq = $in['role_quota'] ?? null;
            if (!is_array($rq)) {
                $add($bad, 'ROLE_QUOTA_MISSING', 'role_quota がありません');
            } else {
                $sum = (int) ($rq['A'] ?? 0) + (int) ($rq['B'] ?? 0) + (int) ($rq['D'] ?? 0);
                if ($sum !== (int) ($in['role_cap'] ?? -1)) {
                    $add($bad, 'ROLE_CAP_MISMATCH',
                         'role_quota の合計（' . $sum . '）が role_cap と合いません');
                }
            }
        }
        // 2nd の上限は5頭（契約7章）
        if (($asm['role'] ?? '') === '2nd' && (int) ($in['role_cap'] ?? 0) > 5) {
            $add($bad, 'ROLE_CAP_OVER', '2ndのrole_capが5を超えています');
        }
        return $bad;
    }
}

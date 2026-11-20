<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

final class BaganrikiJson
{
    public const MAX_BYTES  = 4194304;   // 4 MiB
    public const MAX_DEPTH  = 64;
    public const MAX_DIGITS = 128;
    /** @ANCHOR-20261020-PREDICTION-NUMEXACT  監査に残す数値トークンの上限 */
    public const MAX_NUMBERS_KEPT = 5000;
    // ══════════════════════════════════════════════════════════════════
    // @ANCHOR-20261021-PREDICTION-TYPECAP  型の地図は監査の5,000件とは別です
    // ══════════════════════════════════════════════════════════════════
    //   そのとおりでした。監査に残す一覧（numbers）と、再保存に使う
    //   型の地図（number_paths）を<b>同じ 5,000 で切っていました。</b>
    //   丸まる数値の置き替え（inexact）には上限が無いので、
    //   5,001個目から先は「文字列に置き替わったのに、数値だった印が
    //   地図に無い」状態になり、再保存で<b>数値が文字列へ化けます。</b>
    //   なるの3-5の「5,001個目の長い数値」がこれです。
    //   【いま】地図は監査とは別の上限にします。
    //     numbers（監査の一覧）      5,000 件のまま
    //     number_paths（型の地図）   200,000 件
    //     kind_paths（obj/arr の地図） 200,000 件
    //   18頭・過去5走の本文でも、値の数は数万で収まります。
    public const MAX_NUMTYPE_KEPT = 200000;
    // ══════════════════════════════════════════════════════════════════
    // @ANCHOR-20261021-PREDICTION-KINDPATH  obj／arr を控える位置の上限
    // ══════════════════════════════════════════════════════════════════
    //   数値の上限（5,000）とは別にします。obj／arr の印は
    //   <b>再保存のときに形を決める</b>ので、落とすと {} が [] に
    //   化けます。18頭の本文でも階層の数は数万には届かないので、
    //   数値より多めにしておきます。
    public const MAX_KINDS_KEPT = 200000;
    public const EXP_MIN    = -308;
    public const EXP_MAX    = 308;

    /**
     * @return array ok/code/reason/value/stats
     *   stats = ['bytes','depth','nodes','members','max_digits']
     */
    public static function strict(string $raw): array
    {
        $ng = static fn(string $c, string $r, array $st = []) =>
            ['ok' => false, 'code' => $c, 'reason' => $r, 'value' => null,
             'stats' => $st];

        $len = strlen($raw);
        if ($len === 0) {
            return $ng('JSON_EMPTY', '本文が空です');
        }
        if ($len > self::MAX_BYTES) {
            return $ng('JSON_TOO_LARGE',
                '本文が ' . number_format($len) . ' バイトで、上限 '
                . number_format(self::MAX_BYTES) . ' バイトを超えています');
        }
        // UTF-8 として読めない本文は、ここで止めます（後段で化けないように）
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return $ng('JSON_NOT_UTF8', '本文が UTF-8 として読めません');
        }

        $s = ['i' => 0, 'n' => $len, 'raw' => $raw,
              'depth' => 0, 'max_depth' => 0, 'nodes' => 0, 'members' => 0,
              'max_digits' => 0, 'err' => null,
              // @ANCHOR-20261020-PREDICTION-NUMEXACT  数値トークンの原表現
              'numbers' => [], 'inexact' => [],
              // @ANCHOR-20261021-PREDICTION-NUMTYPE  いま読んでいる位置（経路）と、
              //   「ここはJSON上の数値だった」という印
              'path' => [], 'number_paths' => [],
              // ══════════════════════════════════════════════════════
              // @ANCHOR-20261021-PREDICTION-KINDPATH
              // ══════════════════════════════════════════════════════
              //   そのとおりです。json_decode(…, true) は
              //   {"0":"a","1":"b"} も ["a","b"] も同じPHP配列にします。
              //   【いま】<b>経路ごとに obj／arr を控えます。</b>
              //   空かどうかに関係なく、数値キーのオブジェクトも
              //   区別できます。
              'kind_paths' => []];

        self::ws($s);
        $okTop = self::value($s);
        if ($okTop) {
            self::ws($s);
            if ($s['i'] !== $s['n']) {
                $s['err'] = ['JSON_TRAILING',
                             $s['i'] . ' バイト目より後ろに余分な文字があります'];
                $okTop = false;
            }
        }
        $stats = ['bytes' => $len, 'depth' => $s['max_depth'],
                  'nodes' => $s['nodes'], 'members' => $s['members'],
                  'max_digits' => $s['max_digits'],
                  // @ANCHOR-20261020-PREDICTION-NUMEXACT  原表現（監査用）
                  'numbers' => count($s['numbers']),
                  // @ANCHOR-20261021-PREDICTION-NUMTYPE  数値だった位置の数
                  'number_paths' => count($s['number_paths']),
                  // @ANCHOR-20261021-PREDICTION-KINDPATH  obj／arr を控えた位置の数
                  'kind_paths' => count($s['kind_paths'])];
        if (!$okTop) {
            $e = $s['err'] ?? ['JSON_INVALID', '読めない形です'];
            return $ng($e[0], $e[1], $stats);
        }

        // @ANCHOR-20261020-PREDICTION-NUMEXACT
        //   <b>PHPの数値では丸まるものだけ、十進数の文字列に置き替えます。</b>
        //   置き替えるのは数値トークンの位置そのもの（走査で控えた
        //   byte位置と長さ）なので、他の部分は1バイトも動きません。
        //   うしろから替えていくので、前の位置はずれません。
        $dec = $raw;
        if ($s['inexact'] !== []) {
            $spans = $s['inexact'];
            usort($spans, static fn($a, $b) => $b['off'] <=> $a['off']);
            foreach ($spans as $sp) {
                $dec = substr($dec, 0, $sp['off'])
                     . '"' . $sp['dec'] . '"'
                     . substr($dec, $sp['off'] + $sp['len']);
            }
        }
        $stats['inexact'] = count($s['inexact']);
        $stats['inexact_decimals'] = array_values(array_map(
            static fn($x) => $x['dec'], array_slice($s['inexact'], 0, 20)));

        // 走査を通ったものだけを、いつもの方法で値にします
        $v = json_decode($dec, true, self::MAX_DEPTH + 1);
        if ($v === null && strtolower(trim($dec)) !== 'null') {
            return $ng('JSON_INVALID',
                       'json_decode が読めませんでした（' . json_last_error_msg() . '）',
                       $stats);
        }
        return ['ok' => true, 'code' => null, 'reason' => null,
                'value' => $v, 'stats' => $stats,
                // @ANCHOR-20261021-PREDICTION-NUMTYPE  JSON上の型を結び付けた印
                'number_paths' => $s['number_paths'],
                // @ANCHOR-20261021-PREDICTION-KINDPATH  obj／arr の印
                'kind_paths' => $s['kind_paths'],
                // @ANCHOR-20261021-PREDICTION-TYPEMAP  上の2つをまとめた型の地図
                //   これ1つを持ち回せば、型を落とさずに
                //   診断→訂正比較→保存→読戻しまで通せます（010の3章）。
                'map' => ['num' => $s['number_paths'],
                          'kind' => $s['kind_paths']]];
    }

    // ── 以下は走査の部品 ───────────────────────────────────────────────

    private static function ws(array &$s): void
    {
        while ($s['i'] < $s['n']) {
            $c = $s['raw'][$s['i']];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { $s['i']++; }
            else { break; }
        }
    }

    /**
     * @ANCHOR-20261021-PREDICTION-UNESCAPE  符号位置を UTF-8 のバイト列にします
     *   （\uXXXX を復号してからキーを比べるために使います）
     */
    private static function utf8(int $cp): string
    {
        if ($cp < 0x80)    { return chr($cp); }
        if ($cp < 0x800)   { return chr(0xC0 | ($cp >> 6))
                                  . chr(0x80 | ($cp & 0x3F)); }
        if ($cp < 0x10000) { return chr(0xE0 | ($cp >> 12))
                                  . chr(0x80 | (($cp >> 6) & 0x3F))
                                  . chr(0x80 | ($cp & 0x3F)); }
        return chr(0xF0 | ($cp >> 18))
             . chr(0x80 | (($cp >> 12) & 0x3F))
             . chr(0x80 | (($cp >> 6) & 0x3F))
             . chr(0x80 | ($cp & 0x3F));
    }

    private static function fail(array &$s, string $code, string $reason): bool
    {
        if ($s['err'] === null) { $s['err'] = [$code, $reason]; }
        return false;
    }

    private static function value(array &$s): bool
    {
        if ($s['i'] >= $s['n']) {
            return self::fail($s, 'JSON_INVALID', '値が来るはずの位置で本文が終わっています');
        }
        $s['nodes']++;
        $c = $s['raw'][$s['i']];
        if ($c === '{') { return self::obj($s); }
        if ($c === '[') { return self::arr($s); }
        if ($c === '"') { return self::str($s) !== null; }
        if ($c === '-' || ($c >= '0' && $c <= '9')) { return self::num($s); }
        foreach (['true' => 4, 'false' => 5, 'null' => 4] as $w => $l) {
            if (substr_compare($s['raw'], $w, $s['i'], $l) === 0) {
                $s['i'] += $l; return true;
            }
        }
        return self::fail($s, 'JSON_INVALID',
            $s['i'] . ' バイト目に読めない文字があります（' . $c . '）');
    }

    private static function obj(array &$s): bool
    {
        // @ANCHOR-20261021-PREDICTION-KINDPATH  ここはJSONオブジェクトです（）
        if (count($s['kind_paths']) < self::MAX_KINDS_KEPT) {
            $s['kind_paths'][self::pathKey($s['path'])] = 'obj';
        }
        $s['i']++;                       // '{'
        $s['depth']++;
        if ($s['depth'] > $s['max_depth']) { $s['max_depth'] = $s['depth']; }
        if ($s['depth'] > self::MAX_DEPTH) {
            return self::fail($s, 'JSON_TOO_DEEP',
                '階層が ' . self::MAX_DEPTH . ' を超えています');
        }
        self::ws($s);
        $seen = [];                      // ここが重複キーの見張りです
        if ($s['i'] < $s['n'] && $s['raw'][$s['i']] === '}') {
            $s['i']++; $s['depth']--; return true;
        }
        for (;;) {
            self::ws($s);
            if ($s['i'] >= $s['n'] || $s['raw'][$s['i']] !== '"') {
                return self::fail($s, 'JSON_INVALID',
                    $s['i'] . ' バイト目でキーが文字列になっていません');
            }
            $k = self::str($s);
            if ($k === null) { return false; }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-STRICTJSON  重複キーは拒否します
            // ══════════════════════════════════════════════════════════
            //   json_decode は後勝ちで黙って1つにします。
            //   同じ馬番が2回来たときに気づけないので、ここで止めます。
            if (isset($seen[$k])) {
                return self::fail($s, 'JSON_DUPLICATE_KEY',
                    '同じ階層に同じキーが2回あります（' . $k . '）');
            }
            $seen[$k] = true;
            $s['members']++;
            self::ws($s);
            if ($s['i'] >= $s['n'] || $s['raw'][$s['i']] !== ':') {
                return self::fail($s, 'JSON_INVALID',
                    $s['i'] . ' バイト目にコロンがありません');
            }
            $s['i']++;
            self::ws($s);
            // @ANCHOR-20261021-PREDICTION-NUMTYPE  経路を1つ下ります
            $s['path'][] = $k;
            $okV = self::value($s);
            array_pop($s['path']);
            if (!$okV) { return false; }
            self::ws($s);
            if ($s['i'] >= $s['n']) {
                return self::fail($s, 'JSON_INVALID', '波括弧が閉じていません');
            }
            $c = $s['raw'][$s['i']];
            if ($c === ',') { $s['i']++; continue; }
            if ($c === '}') { $s['i']++; $s['depth']--; return true; }
            return self::fail($s, 'JSON_INVALID',
                $s['i'] . ' バイト目に読点か閉じ波括弧がありません');
        }
    }

    private static function arr(array &$s): bool
    {
        // @ANCHOR-20261021-PREDICTION-KINDPATH  ここはJSON配列です（）
        if (count($s['kind_paths']) < self::MAX_KINDS_KEPT) {
            $s['kind_paths'][self::pathKey($s['path'])] = 'arr';
        }
        $s['i']++;                       // '['
        $s['depth']++;
        if ($s['depth'] > $s['max_depth']) { $s['max_depth'] = $s['depth']; }
        if ($s['depth'] > self::MAX_DEPTH) {
            return self::fail($s, 'JSON_TOO_DEEP',
                '階層が ' . self::MAX_DEPTH . ' を超えています');
        }
        self::ws($s);
        if ($s['i'] < $s['n'] && $s['raw'][$s['i']] === ']') {
            $s['i']++; $s['depth']--; return true;
        }
        $idx = 0;
        for (;;) {
            self::ws($s);
            // @ANCHOR-20261021-PREDICTION-NUMTYPE  経路を1つ下ります（配列の番号）
            $s['path'][] = $idx;
            $okV = self::value($s);
            array_pop($s['path']);
            $idx++;
            if (!$okV) { return false; }
            self::ws($s);
            if ($s['i'] >= $s['n']) {
                return self::fail($s, 'JSON_INVALID', '角括弧が閉じていません');
            }
            $c = $s['raw'][$s['i']];
            if ($c === ',') { $s['i']++; continue; }
            if ($c === ']') { $s['i']++; $s['depth']--; return true; }
            return self::fail($s, 'JSON_INVALID',
                $s['i'] . ' バイト目に読点か閉じ角括弧がありません');
        }
    }

    /** 文字列を読み、中身を返します（読めなければ null） */
    private static function str(array &$s): ?string
    {
        $s['i']++;                       // '"'
        $out = '';
        while ($s['i'] < $s['n']) {
            $c = $s['raw'][$s['i']];
            if ($c === '"') { $s['i']++; return $out; }
            if ($c === '\\') {
                $s['i']++;
                if ($s['i'] >= $s['n']) {
                    self::fail($s, 'JSON_INVALID', '逆斜線の後ろで本文が終わっています');
                    return null;
                }
                $e = $s['raw'][$s['i']];
                $map = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08",
                        'f' => "\x0C", 'n' => "\n", 'r' => "\r", 't' => "\t"];
                if (isset($map[$e])) { $out .= $map[$e]; $s['i']++; continue; }
                if ($e === 'u') {
                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-UNESCAPE(2)
                    // ══════════════════════════════════════════════════
                    //   【本物の不具合でした】
                    //   \uXXXX を<b>復号せずそのまま</b>キーの比較用文字列へ
                    //   足していました。そのため
                    //     {"a":1,"\u0061":2}
                    //   は JSON 上は同じキー a なのに、走査では別のキーに
                    //   見え、<b>重複キーの拒否をすり抜けます。</b>
                    //   後段の json_decode では同じキーへ戻るので、
                    //   黙って片方が消えます。
                    //   【いま】復号してから比べます（代理対も組み立てます）。
                    if ($s['i'] + 4 >= $s['n']
                        || preg_match('/^[0-9a-fA-F]{4}$/', substr($s['raw'], $s['i'] + 1, 4)) !== 1) {
                        self::fail($s, 'JSON_INVALID', '\\u の後ろが16進4桁ではありません');
                        return null;
                    }
                    $cp = (int) hexdec(substr($s['raw'], $s['i'] + 1, 4));
                    $s['i'] += 5;
                    if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                        // 上位代理。下位代理が続いていなければ UTF-8 にできません
                        if ($s['i'] + 5 < $s['n']
                            && $s['raw'][$s['i']] === '\\'
                            && $s['raw'][$s['i'] + 1] === 'u'
                            && preg_match('/^[0-9a-fA-F]{4}$/',
                                          substr($s['raw'], $s['i'] + 2, 4)) === 1) {
                            $lo = (int) hexdec(substr($s['raw'], $s['i'] + 2, 4));
                            if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                                $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                                $s['i'] += 6;
                            } else {
                                self::fail($s, 'JSON_BAD_SURROGATE',
                                    '上位代理の後ろが下位代理ではありません');
                                return null;
                            }
                        } else {
                            self::fail($s, 'JSON_BAD_SURROGATE',
                                '上位代理だけがあり、下位代理がありません');
                            return null;
                        }
                    } elseif ($cp >= 0xDC00 && $cp <= 0xDFFF) {
                        self::fail($s, 'JSON_BAD_SURROGATE',
                            '下位代理だけがあります');
                        return null;
                    }
                    $out .= self::utf8($cp);
                    continue;
                }
                self::fail($s, 'JSON_INVALID', '使えない逃がし文字です（\\' . $e . '）');
                return null;
            }
            if (ord($c) < 0x20) {
                self::fail($s, 'JSON_INVALID',
                    '文字列の中に生の制御文字があります（' . ord($c) . '）');
                return null;
            }
            $out .= $c;
            $s['i']++;
        }
        self::fail($s, 'JSON_INVALID', '文字列が閉じていません');
        return null;
    }

    /**
     * 数値。JSONの文法どおりに読み、桁数と調整後指数を見ます。
     * <b>値は作りません（float にしません）。</b>文字として調べるだけです。
     */
    private static function num(array &$s): bool
    {
        $st = $s['i'];
        if ($s['raw'][$s['i']] === '-') { $s['i']++; }
        // 整数部
        $i0 = $s['i'];
        if ($s['i'] < $s['n'] && $s['raw'][$s['i']] === '0') {
            $s['i']++;
        } else {
            // 先頭の0は JSON では許されません（011 は不可）
            if ($s['i'] >= $s['n'] || $s['raw'][$s['i']] < '1' || $s['raw'][$s['i']] > '9') {
                return self::fail($s, 'JSON_INVALID',
                    $st . ' バイト目の数値が読めません');
            }
            while ($s['i'] < $s['n'] && $s['raw'][$s['i']] >= '0'
                   && $s['raw'][$s['i']] <= '9') { $s['i']++; }
        }
        $intPart = substr($s['raw'], $i0, $s['i'] - $i0);
        // 小数部
        $frac = '';
        if ($s['i'] < $s['n'] && $s['raw'][$s['i']] === '.') {
            $s['i']++;
            $f0 = $s['i'];
            while ($s['i'] < $s['n'] && $s['raw'][$s['i']] >= '0'
                   && $s['raw'][$s['i']] <= '9') { $s['i']++; }
            if ($s['i'] === $f0) {
                return self::fail($s, 'JSON_INVALID', '小数点の後ろに数字がありません');
            }
            $frac = substr($s['raw'], $f0, $s['i'] - $f0);
        }
        // 指数部
        $exp = 0;
        if ($s['i'] < $s['n'] && ($s['raw'][$s['i']] === 'e' || $s['raw'][$s['i']] === 'E')) {
            $s['i']++;
            $sign = 1;
            if ($s['i'] < $s['n'] && ($s['raw'][$s['i']] === '+' || $s['raw'][$s['i']] === '-')) {
                if ($s['raw'][$s['i']] === '-') { $sign = -1; }
                $s['i']++;
            }
            $e0 = $s['i'];
            while ($s['i'] < $s['n'] && $s['raw'][$s['i']] >= '0'
                   && $s['raw'][$s['i']] <= '9') { $s['i']++; }
            if ($s['i'] === $e0) {
                return self::fail($s, 'JSON_INVALID', '指数の後ろに数字がありません');
            }
            $digits = substr($s['raw'], $e0, $s['i'] - $e0);
            // 指数そのものが桁あふれするほど長い場合も、ここで落とします
            if (strlen(ltrim($digits, '0')) > 6) {
                return self::fail($s, 'JSON_EXPONENT_RANGE',
                    '指数が大きすぎます（' . substr($s['raw'], $st, $s['i'] - $st) . '）');
            }
            $exp = $sign * (int) $digits;
        }

        $tok = substr($s['raw'], $st, $s['i'] - $st);

        // 有効桁数（先頭の0は数えません）
        $all = $intPart . $frac;
        $sig = ltrim($all, '0');
        $nd  = strlen($sig);
        if ($nd > $s['max_digits']) { $s['max_digits'] = $nd; }
        if ($nd > self::MAX_DIGITS) {
            return self::fail($s, 'JSON_TOO_MANY_DIGITS',
                '有効桁が ' . $nd . ' 桁で、上限 ' . self::MAX_DIGITS
                . ' 桁を超えています（' . substr($tok, 0, 32) . '…）');
        }

        // 調整後指数。0 は 0 とします。
        if ($sig !== '') {
            $k = 0;
            $L = strlen($all);
            while ($k < $L && $all[$k] === '0') { $k++; }
            $adj = $exp + (strlen($intPart) - 1 - $k);
            if ($adj < self::EXP_MIN || $adj > self::EXP_MAX) {
                return self::fail($s, 'JSON_EXPONENT_RANGE',
                    '調整後指数が ' . $adj . ' で、範囲 ' . self::EXP_MIN . '〜'
                    . self::EXP_MAX . ' の外です（' . substr($tok, 0, 32) . '）');
            }
        }

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-NUMEXACT
        // ══════════════════════════════════════════════════════════════
        //   【ご指摘のとおりでした】走査で桁や指数を見るようにしても、
        //   <b>値の組み立ては通常の json_decode</b> なので、
        //   PHP整数の範囲を超える整数や長い小数は float を通ります。
        //   1.99999999999999999999 は正確には2未満ですが、
        //   倍精度では 2.0 になります。調整後指数308を許しても、
        //   2e308 は倍精度の有限範囲の外です。
        //   「走査が通った」ことと「値を正確に持てる」ことは別でした。
        //
        //   【いま】数値トークンごとに、<b>原表現と、PHPで持てる値とを
        //   突き合わせます。</b>
        //     ・無限大になるもの            → 受け取りません
        //     ・戻すと別の十進数になるもの  → 受け取りません
        //   受け取らない、というのは「別値へ変えない」ということです。
        //   丸めた値を黙って通すことはしません。
        //   原表現は $s['numbers'] に残すので、監査で読めます。
        $norm = self::normDec($intPart, $frac, $exp, ($tok[0] ?? '') === '-');
        $val  = json_decode($tok);
        $exact = (is_int($val)
                  || (is_float($val) && is_finite($val)
                      && BaganrikiPrompt::numToDecimalString($val) === $norm));
        if (!$exact) {
            // ══════════════════════════════════════════════════════════
            //   <b>受け取らない、のではありません。</b>契約9章は128桁まで
            //   許しているので、拒否すると正しい応答を落とします。
            //   PHPの数値では丸まる（または無限大になる）ものは、
            //   <b>原表現の十進数文字列として持ちます。</b>
            //   入力側で「登録から落とさず文字列で持つ」ようにしたのと
            //   同じ扱いです（@ANCHOR-...-DIGITS2）。
            //   値の型は後段で見ます（採点は整数と決まっているので、
            //   文字列のまま来れば採点の検査が落とします）。
            $s['inexact'][] = ['off' => $st, 'len' => $s['i'] - $st,
                               'dec' => $norm];
        }
        if (count($s['numbers']) < self::MAX_NUMBERS_KEPT) {
            $s['numbers'][] = ['raw' => $tok, 'decimal' => $norm,
                               'exact' => $exact];
        }
        // ══════════════════════════════════════════════════════════════
        //     「現行sameHorseRowは1と1.0を区別する仕様を明記しています。
        //       値オブジェクトから十進値『1』だけを出力して、1.0や1e0を
        //       読戻し時に整数1へ変えてしまわないようにします。」
        //   十進値だけでは 1 ／ 1.0 ／ 1e0 が同じになります。
        //   <b>原トークンの表記（form）も控えます。</b>
        //     int  小数点も指数も無い   1
        //     dec  小数点がある         1.0
        //     exp  指数がある           1e0
        //   再保存は原トークンをそのまま書くので、表記も戻ります。
        $form = 'int';
        if (strpbrk($tok, 'eE') !== false) { $form = 'exp'; }
        elseif (strpos($tok, '.') !== false) { $form = 'dec'; }
        // @ANCHOR-20261021-PREDICTION-TYPECAP  監査（5,000件）とは別の上限です
        if (count($s['number_paths']) < self::MAX_NUMTYPE_KEPT) {
            $s['number_paths'][self::pathKey($s['path'])] =
                ['raw' => $tok, 'decimal' => $norm, 'exact' => $exact,
                 'form' => $form];
        }
        return true;
    }

    /**
     * @ANCHOR-20261021-PREDICTION-NUMTYPE  経路を取り違えようのない文字列にします
     *   ["horses",0,"scores","H1"] のような JSON 配列にします。
     *   キーに「.」や「[」が入っていても境目が決まります。
     */
    public static function pathKey(array $path): string
    {
        return (string) json_encode(array_values($path),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261021-PREDICTION-TYPEMAP  型を落とさない読み・書き・比べ（章）
    // ══════════════════════════════════════════════════════════════════════
    // 10月21日010（なる）の第3章ぜんぶに対する実装です。
    //
    // ■ なぜ値オブジェクトを「全部の値」に使わないのか
    //   ご指摘のとおり、値に型を持たせる方式（設計A）を採ります。
    //   ただし<b>すべての値を包む</b>と、既存の業務の判定
    //   （is_int・array_sum・foreach・比較演算）が全部落ちます。
    //   正しい整数まで落ちます。
    //   【採った形】値はこれまでどおりPHPの素の値で持ち、
    //   <b>型は経路をキーにした地図（map）で並べて持ちます。</b>
    //     map['num'][経路]  = ['raw','decimal','exact','form']
    //     map['kind'][経路] = 'obj' | 'arr'
    //   業務の判定は素の値でそのまま動き、型が必要な場所
    //   （比較・保存・読戻し）だけが地図を見ます。
    //   値オブジェクト（BaganrikiDecimal）は、1つの値を持ち回すときの
    //   入れ物として残します。地図はその「全体版」です。
    //
    // ■ 保存と読戻しが同じ形で閉じます（3-1・3-3）
    //   encode(値, 地図)   数値は<b>原トークンのまま引用符なし</b>で書き、
    //                      obj／arr の種類も地図のとおりに書きます
    //   decode(本文)       走査して 値＋地図 を返します（strict と同じ）
    //   encode したものを decode すれば、値も型も表記も戻ります。
    //   JSON_NUMERIC_CHECK も float への変換も使っていません。
    //
    // ■ 比べ方（3-2）
    //   same(値A,地図A, 値B,地図B) が、
    //     ・JSON上の型（数値／文字列／真偽／null／obj／arr）
    //     ・数値は正確な十進値と表記（int／dec／exp）
    //     ・obj はキーの集合（<b>順序は無視</b>）
    //     ・arr は長さと<b>順序</b>
    //   を見ます。同じ値の別インスタンスという概念がないので、
    //   「同じ数値なのに別物と判定する」ことは起きません。
    // ══════════════════════════════════════════════════════════════════════

    /** 空の地図 */
    public static function emptyMap(): array
    {
        return ['num' => [], 'kind' => []];
    }

    /**
     * 専用パーサ。走査して「値」と「型の地図」を返します。
     *
     * @return array ['ok','code','reason','value','map','stats']
     */
    public static function decode(string $raw): array
    {
        $r = self::strict($raw);
        if (!$r['ok']) {
            return ['ok' => false, 'code' => $r['code'], 'reason' => $r['reason'],
                    'value' => null, 'map' => self::emptyMap(),
                    'stats' => $r['stats']];
        }
        return ['ok' => true, 'code' => null, 'reason' => null,
                'value' => $r['value'],
                'map' => (array) ($r['map'] ?? self::emptyMap()),
                'stats' => $r['stats']];
    }

    /**
     * 専用シリアライザ。型の地図のとおりに書き戻します。
     *
     *   ・数値だった経路は<b>原トークンをそのまま</b>引用符なしで書きます
     *     （1.0 は 1.0 のまま。1 へ丸めません）
     *   ・obj だった経路は、PHPの値が空配列でも数値キーでも {} で書きます
     *   ・arr だった経路は [] で書きます
     *   ・地図に無い経路は、PHPの値の形から決めます
     *     （連番の配列なら [ ]、それ以外は { }）
     *   ・BaganrikiDecimal は原トークン、BaganrikiEmptyObject は {}
     *
     * @param mixed $value
     * @param array $map   decode の返り値 map
     * @param array $path  いまの経路（内部用）
     */
    public static function encode($value, array $map, array $path = []): string
    {
        $k    = self::pathKey($path);
        $nums = (array) ($map['num'] ?? []);
        $kind = (array) ($map['kind'] ?? []);

        // ── ① 値オブジェクトは、それ自身が型を持っています ──────────
        if ($value instanceof BaganrikiDecimal) {
            return $value->raw();
        }
        if ($value instanceof BaganrikiEmptyObject) {
            return '{}';
        }

        // ── ② 数値だった経路 ───────────────────────────────────────
        //   文字列として持っている（丸まる数値）場合も、整数・小数で
        //   持っている場合も、原トークンを書きます。
        if (isset($nums[$k])) {
            $n = (array) $nums[$k];
            if (is_string($value) || is_int($value) || is_float($value)) {
                // 値が差し替えられていないかを見ます（3-3 の「整合」）。
                //   差し替えられていれば、その新しい値を数値として書きます。
                //   原トークンを押し付けると、訂正で変えた値が戻ってしまいます。
                if (self::sameNumberValue($value, $n)) {
                    return (string) $n['raw'];
                }
                return self::numberLiteral($value);
            }
        }

        // ── ③ 配列・オブジェクト ────────────────────────────────────
        if (is_array($value)) {
            $want = $kind[$k] ?? null;
            if ($want === null) {
                $want = (array_keys($value) === range(0, count($value) - 1))
                      ? 'arr' : 'obj';
                if ($value === []) { $want = 'arr'; }   // 地図が無ければ []
            }
            $out = [];
            if ($want === 'arr') {
                foreach (array_values($value) as $i => $vv) {
                    $sub = $path; $sub[] = (int) $i;
                    $out[] = self::encode($vv, $map, $sub);
                }
                return '[' . implode(',', $out) . ']';
            }
            foreach ($value as $kk => $vv) {
                $sub = $path; $sub[] = (string) $kk;
                $out[] = (string) json_encode((string) $kk,
                             JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                       . ':' . self::encode($vv, $map, $sub);
            }
            return '{' . implode(',', $out) . '}';
        }

        if ($value instanceof \stdClass) {
            $out = [];
            foreach ((array) $value as $kk => $vv) {
                $sub = $path; $sub[] = (string) $kk;
                $out[] = (string) json_encode((string) $kk,
                             JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                       . ':' . self::encode($vv, $map, $sub);
            }
            return '{' . implode(',', $out) . '}';
        }

        // ── ④ そのほか（文字列・真偽・null・素の数値）────────────────
        return (string) json_encode($value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * いまのPHPの値が、地図に控えた数値と同じ値かどうか。
     *   文字列で持っている場合は十進値を文字どおりに比べます。
     *   整数・小数で持っている場合は十進値へ直してから比べます。
     */
    private static function sameNumberValue($value, array $n): bool
    {
        $dec = (string) ($n['decimal'] ?? '');
        if (is_string($value)) {
            return ($value === (string) ($n['raw'] ?? '')) || ($value === $dec);
        }
        if (is_int($value)) {
            return (self::intToDecimalString($value) === $dec);
        }
        if (is_float($value)) {
            if (!is_finite($value)) { return false; }
            return (BaganrikiPrompt::numToDecimalString($value) === $dec);
        }
        return false;
    }

    /** 整数を十進数の文字列へ（normDec と同じ形にそろえます） */
    private static function intToDecimalString(int $v): string
    {
        return ($v === 0) ? '0' : (string) $v;
    }

    /**
     * 差し替えられた数値を、JSONの数値として書きます。
     *   <b>引用符は付けません。</b>文字列へ化けさせないためです。
     *   数字として読めないものは、JSONとして壊れないように
     *   文字列で書きます（黙って0にはしません）。
     */
    private static function numberLiteral($value): string
    {
        if (is_int($value)) { return (string) $value; }
        if (is_float($value)) {
            if (!is_finite($value)) {
                // 無限大・NaN はJSONに書けません。文字列で残します
                return (string) json_encode((string) $value);
            }
            return BaganrikiPrompt::numToDecimalString($value);
        }
        $t = trim((string) $value);
        if ($t !== '' && preg_match('/\A-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][-+]?[0-9]+)?\z/', $t) === 1) {
            return $t;
        }
        return (string) json_encode((string) $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 地図から、ある経路より下だけを取り出して、経路を付け替えます。
     *
     *   馬の行だけを比べるときに使います。
     *   ["horses",3,"scores","H1"] を、["scores","H1"] にします。
     */
    public static function mapSub(array $map, array $from): array
    {
        $out = self::emptyMap();
        $pre = self::pathKey($from);
        $preBody = substr($pre, 1, -1);          // 角括弧の中身
        foreach (['num', 'kind'] as $sec) {
            foreach ((array) ($map[$sec] ?? []) as $k => $v) {
                if ($from === []) { $out[$sec][$k] = $v; continue; }
                if ($k === $pre) { $out[$sec]['[]'] = $v; continue; }
                $head = '[' . $preBody . ',';
                if (strncmp($k, $head, strlen($head)) === 0) {
                    $out[$sec]['[' . substr($k, strlen($head))] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-MAPMOVE  並べ替えに地図を追従させます（）
     * ═══════════════════════════════════════════════════════════════════
     *
     *   応答の馬の行は ["horses", 3] のような位置にあります。
     *   正常部分（normal）は<b>馬番をキーにしたオブジェクト</b>に
     *   組み替えるので、位置が ["7"] に変わります。
     *   地図の経路もそのとおりに付け替えないと、再保存で型が落ちます。
     *
     *   mapMove(地図, ["horses",3], ["7"]) で、その枝だけを付け替えます。
     *   mapMerge で、付け替えた枝を1つの地図にまとめます。
     */
    public static function mapMove(array $map, array $from, array $to): array
    {
        $sub = self::mapSub($map, $from);
        $out = self::emptyMap();
        $pre = self::pathKey($to);
        $preBody = substr($pre, 1, -1);
        foreach (['num', 'kind'] as $sec) {
            foreach ((array) ($sub[$sec] ?? []) as $k => $v) {
                if ($k === '[]') {
                    $out[$sec][$pre] = $v;
                    continue;
                }
                // '["scores","H1"]' の先頭の '[' を、'["7",' に差し替えます
                $out[$sec]['[' . $preBody . ',' . substr($k, 1)] = $v;
            }
        }
        return $out;
    }

    /** 地図を重ねます（後から渡したものが勝ちます） */
    public static function mapMerge(array ...$maps): array
    {
        $out = self::emptyMap();
        foreach ($maps as $m) {
            foreach (['num', 'kind'] as $sec) {
                foreach ((array) ($m[$sec] ?? []) as $k => $v) {
                    $out[$sec][$k] = $v;
                }
            }
        }
        return $out;
    }

    /** 経路に種類（obj/arr）を書き入れた地図を返します */
    public static function mapWithKind(array $map, array $path, string $kind): array
    {
        $map['kind'][self::pathKey($path)] = $kind;
        return $map;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-SAMEVALUE  共通の比較関数（）
     * ═══════════════════════════════════════════════════════════════════
     *   「共通の比較関数で、JSONの型、正確な十進値、必要な整数／小数・
     *     指数表記の区別を比較してください。通常のオブジェクトのキー順は
     *     無視し、配列の順序は保持します。」
     *
     * ■ 見るもの
     *   ・JSON上の型            数値／文字列／真偽／null／obj／arr
     *   ・数値                  正確な十進値と、表記（int／dec／exp）
     *   ・obj                   キーの集合（<b>順序は無視</b>）
     *   ・arr                   長さと<b>順序</b>
     *
     * ■ $strictForm
     *   true  … 1 と 1.0 と 1e0 を<b>別物</b>として見ます（既定）。
     *           sameHorseRow の「1と1.0を区別する」仕様に合わせます。
     *   false … 十進値が同じなら同じと見ます。
     *           「値が変わっていないか」だけを見たいときに使います。
     *
     * ■ 末尾の0（1.50 と 1.5）は「同じ」と見ます
     *   どちらも十進値 1.5 で、表記も小数（dec）です。
     *   値も型も同じなので、<b>範囲外を変えた</b>とは見ません。
     *   区別しているのは
     *     int  小数点も指数も無い   1
     *     dec  小数点がある         1.0／1.50／1.5
     *     exp  指数がある           1e0
     *   の3つです。1 と 1.0 の区別（整数必須の検査に効くもの）は残り、
     *   書き方のゆらぎだけで訂正を蹴ることはしません。
     *   なお<b>保存では原トークンをそのまま書く</b>ので、
     *   1.50 は 1.50 のまま残ります（丸めていません）。
     *
     * @return array ['same','where','reason']
     *   where は食い違った経路（JSON配列の文字列）です。
     */
    public static function same($a, array $mapA, $b, array $mapB,
                                bool $strictForm = true,
                                array $path = []): array
    {
        $k  = self::pathKey($path);
        $na = ((array) ($mapA['num'] ?? []))[$k] ?? null;
        $nb = ((array) ($mapB['num'] ?? []))[$k] ?? null;
        $ka = ((array) ($mapA['kind'] ?? []))[$k] ?? null;
        $kb = ((array) ($mapB['kind'] ?? []))[$k] ?? null;

        $ng = static fn(string $why) => ['same' => false, 'where' => $k,
                                         'reason' => $why];

        // ── ① どちらかがJSON上の数値だったとき ────────────────────
        if ($na !== null || $nb !== null) {
            if ($na === null || $nb === null) {
                return $ng('片方はJSON上の数値、もう片方は数値ではありません');
            }
            if ((string) ($na['decimal'] ?? '') !== (string) ($nb['decimal'] ?? '')) {
                return $ng('数値の十進値が違います（'
                         . (string) ($na['decimal'] ?? '') . ' と '
                         . (string) ($nb['decimal'] ?? '') . '）');
            }
            if ($strictForm
                && (string) ($na['form'] ?? '') !== (string) ($nb['form'] ?? '')) {
                return $ng('同じ値ですが表記が違います（'
                         . (string) ($na['raw'] ?? '') . ' と '
                         . (string) ($nb['raw'] ?? '') . '）');
            }
            return ['same' => true, 'where' => null, 'reason' => null];
        }

        // ── ② obj／arr の種類 ─────────────────────────────────────
        $aArr = is_array($a) || ($a instanceof BaganrikiEmptyObject);
        $bArr = is_array($b) || ($b instanceof BaganrikiEmptyObject);
        if ($aArr !== $bArr) {
            return $ng('片方だけが入れ物です');
        }
        if ($aArr) {
            // 種類は地図で決めます。地図に無ければPHPの形から決めます
            $kindA = $ka ?? self::kindOf($a);
            $kindB = $kb ?? self::kindOf($b);
            if ($kindA !== $kindB) {
                return $ng('片方はオブジェクト、もう片方は配列です（'
                         . $kindA . ' と ' . $kindB . '）');
            }
            $av = ($a instanceof BaganrikiEmptyObject) ? [] : (array) $a;
            $bv = ($b instanceof BaganrikiEmptyObject) ? [] : (array) $b;

            if ($kindA === 'arr') {
                $av = array_values($av); $bv = array_values($bv);
                if (count($av) !== count($bv)) {
                    return $ng('配列の長さが違います（' . count($av) . ' と '
                             . count($bv) . '）');
                }
                foreach ($av as $i => $vv) {
                    $sub = $path; $sub[] = (int) $i;
                    $r = self::same($vv, $mapA, $bv[$i], $mapB, $strictForm, $sub);
                    if (!$r['same']) { return $r; }
                }
                return ['same' => true, 'where' => null, 'reason' => null];
            }

            // オブジェクト。キーの集合だけ見ます（順序は無視）
            $akeys = array_map('strval', array_keys($av));
            $bkeys = array_map('strval', array_keys($bv));
            sort($akeys); sort($bkeys);
            if ($akeys !== $bkeys) {
                $only = array_merge(array_diff($akeys, $bkeys),
                                    array_diff($bkeys, $akeys));
                return $ng('キーの集合が違います（'
                         . implode('・', array_slice($only, 0, 5)) . '）');
            }
            foreach ($av as $kk => $vv) {
                $sub = $path; $sub[] = (string) $kk;
                $r = self::same($vv, $mapA, $bv[$kk] ?? null, $mapB,
                                $strictForm, $sub);
                if (!$r['same']) { return $r; }
            }
            return ['same' => true, 'where' => null, 'reason' => null];
        }

        // ── ③ 素の値（文字列・真偽・null・地図に載らない数値）─────────
        if (gettype($a) !== gettype($b)) {
            return $ng('型が違います（' . gettype($a) . ' と ' . gettype($b) . '）');
        }
        if ($a !== $b) {
            return $ng('値が違います');
        }
        return ['same' => true, 'where' => null, 'reason' => null];
    }

    /** PHPの値から obj／arr を決めます（地図に無いときだけ使います） */
    private static function kindOf($v): string
    {
        if ($v instanceof BaganrikiEmptyObject) { return 'obj'; }
        if (!is_array($v)) { return 'arr'; }
        if ($v === []) { return 'arr'; }
        return (array_keys($v) === range(0, count($v) - 1)) ? 'arr' : 'obj';
    }

    /**
     * 本文どうしを、型まで見て比べます（読戻しの確認に使います）。
     *
     * @return array ['same','where','reason','ok']
     *   ok=false は、どちらかが走査を通らなかったことを表します。
     */
    public static function sameJson(string $rawA, string $rawB,
                                    bool $strictForm = true): array
    {
        $da = self::decode($rawA);
        $db = self::decode($rawB);
        if (!$da['ok'] || !$db['ok']) {
            return ['ok' => false, 'same' => false, 'where' => null,
                    'reason' => '走査を通らない本文があります（'
                              . (string) ($da['code'] ?? '')
                              . '／' . (string) ($db['code'] ?? '') . '）'];
        }
        $r = self::same($da['value'], $da['map'], $db['value'], $db['map'],
                        $strictForm);
        $r['ok'] = true;
        return $r;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-NUMEXACT  原表現を十進数の文字列へ（文字だけで動かします）
     *   指数は小数点を動かすだけで展開します。float を通りません。
     *   前後の余分な0は落とします（numToDecimalString と同じ形にそろえます）。
     */
    private static function normDec(string $intPart, string $frac, int $exp,
                                    bool $neg): string
    {
        $digits = $intPart . $frac;
        $point  = strlen($intPart) + $exp;
        if ($point <= 0) {
            $out = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $out = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $out = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if (strpos($out, '.') !== false) { $out = rtrim(rtrim($out, '0'), '.'); }
        $out = ltrim($out, '0');
        if ($out === '' || $out[0] === '.') { $out = '0' . $out; }
        if ($out === '0') { return '0'; }
        return ($neg ? '-' : '') . $out;
    }
}

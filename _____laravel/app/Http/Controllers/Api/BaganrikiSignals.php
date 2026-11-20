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
 * @ANCHOR-20261020-REFACTOR  v0.4-X / 新方式 の信号判定
 * ═══════════════════════════════════════════════════════════════════════════
 * AiController が1万8千行を超えて読みづらくなったので、
 * 役割ごとに切り出しました。<b>新しいファイルは作らず、このファイルの中に置きます。</b>
 *
 * ここに入っているもの
 *   ・三値論理（AND／OR／m件以上）
 *   ・6分前人気で固定した隣接ペアの W（単勝比）と P（複勝比）
 *   ・X1〜X6・Z の判定
 *   ・S・S_min・S_max と mode、新方式 の mode_status／allocation_mode／星
 *
 * 動きは切り出す前と同じです。閾値や配分表の定数は AiController 側に残し、
 * ここからは AiController::V04_… で参照します（定数の正本を1か所に保つため）。
 */
final class BaganrikiSignals
{

    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261020-PREDICTION-EXACT  任意精度の十進数で、丸めずに比べます
    // ═════════════════════════════════════════════════════════════════════
    // 根拠：server_contract_r12.2.txt 2章・12章／詳細設計書8章
    //
    //   【直した理由】
    //     これまで W（単勝比）と P（複勝比）を PHP の float で割り算し、
    //     小数4桁に丸めてから閾値と比べていました。
    //     「元値の正確な分数で比較し、表示用に丸めた比率を使用しない」
    //     という契約に反します。境界ちょうどの馬で判定が変わり得ます。
    //
    //   【直し方】
    //     割り算も float も使いません。十進数の文字列のまま、
    //     たすき掛け（分子×相手の分母）を多倍長整数で比べます。
    //     桁数の上限を固定していないので、小数第5位以上の値が来ても
    //     切り捨てたり拒否したりしません。
    //     W や P の数値は画面と監査のために今までどおり残しますが、
    //     判定には使いません。

    /** 十進数の文字列を ['d'=>数字だけの文字列, 'sc'=>小数桁数] へ。読めなければ null */
    public static function decNorm($v): ?array
    {
        if ($v === null || is_bool($v)) { return null; }
        $s = trim((string) $v);
        if ($s === '') { return null; }
        if (!preg_match('/^(\d{1,128})(?:\.(\d{1,128}))?$/', $s, $m)) { return null; }
        $int  = $m[1];
        $frac = $m[2] ?? '';
        $d = ltrim($int . $frac, '0');
        if ($d === '') { $d = '0'; }
        if (strlen($d) > 128) { return null; }   // 契約の128桁上限
        return ['d' => $d, 'sc' => strlen($frac)];
    }

    /** 数字列どうしの掛け算（多倍長。筆算と同じやり方） */
    public static function bigMul(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') { return '0'; }
        $la = strlen($a); $lb = strlen($b);
        $r = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $ai = (int) $a[$i];
            if ($ai === 0) { continue; }
            $carry = 0;
            for ($j = $lb - 1; $j >= 0; $j--) {
                $t = $r[$i + $j + 1] + $ai * (int) $b[$j] + $carry;
                $r[$i + $j + 1] = $t % 10;
                $carry = intdiv($t, 10);
            }
            $r[$i] += $carry;
        }
        $s = ltrim(implode('', $r), '0');
        return ($s === '') ? '0' : $s;
    }

    /** 数字列どうしの大小（-1 / 0 / 1） */
    public static function bigCmp(string $a, string $b): int
    {
        $a = ltrim($a, '0'); $b = ltrim($b, '0');
        if ($a === '') { $a = '0'; }
        if ($b === '') { $b = '0'; }
        if (strlen($a) !== strlen($b)) { return (strlen($a) < strlen($b)) ? -1 : 1; }
        return strcmp($a, $b) <=> 0;
    }

    /**
     * 項の積どうしを比べます。各項は十進数の文字列、k は整数の係数。
     * 返りは -1 / 0 / 1。読めない値が混ざれば null。
     *   例：4×W21 と 5×W6 を比べる → cmpProd(4,[l21,u6], 5,[l6,u21])
     */
    public static function cmpProd(int $kl, array $left, int $kr, array $right): ?int
    {
        $build = static function (int $k, array $xs): ?array {
            $d = (string) abs($k); $sc = 0;
            foreach ($xs as $x) {
                $n = self::decNorm($x);
                if ($n === null) { return null; }
                $d = self::bigMul($d, $n['d']);
                $sc += $n['sc'];
            }
            return ['d' => $d, 'sc' => $sc];
        };
        $L = $build($kl, $left);
        $R = $build($kr, $right);
        if ($L === null || $R === null) { return null; }
        // 小数桁をそろえてから比べます（10の冪を掛けるだけ＝0を足すだけ）
        $diff = $L['sc'] - $R['sc'];
        if ($diff > 0)      { $R['d'] = ($R['d'] === '0') ? '0' : $R['d'] . str_repeat('0', $diff); }
        elseif ($diff < 0)  { $L['d'] = ($L['d'] === '0') ? '0' : $L['d'] . str_repeat('0', -$diff); }
        return self::bigCmp($L['d'], $R['d']);
    }

    /**
     * 比 (num/den) と有理数 (p/q) の大小。num/den は十進数の文字列。
     * num/den >= p/q ⇔ num×q >= den×p（den>0・q>0 のとき）
     */
    public static function cmpRatio($num, $den, int $p, int $q): ?int
    {
        return self::cmpProd($q, [$num], $p, [$den]);
    }

    /** @ANCHOR-20261013-V04-CORE  有効な観測値か（0・負・欠損・真偽値は不可） */
    public static function v04Valid($x): bool
    {
        if (is_bool($x) || $x === null) return false;
        if (!is_numeric($x)) return false;
        return ((float) $x) > 0;
    }

    /**
     * @ANCHOR-20261013-V04-CORE  三値のOR集約（付録F tri_any と同一）
     *   1つでも成立 → TRUE。成立し得る未観測が残る → UNKNOWN。
     *   対象が空（構造的非該当）→ FALSE。
     * @param array $conditions true / false / null の列
     */
    public static function v04TriAny(array $conditions): string
    {
        $unknown = false;
        foreach ($conditions as $v) {
            if ($v === true)  return 'TRUE';
            if ($v === null)  $unknown = true;
        }
        return $unknown ? 'UNKNOWN' : 'FALSE';
    }

    /**
     * @ANCHOR-20261013-V04-CORE  三値の件数集約（付録F tri_at_least と同一）
     *   成立数が n 以上 → TRUE
     *   成立数＋不明数が n に届かない → FALSE（欠損を自動でFALSEにするのではなく、
     *     「どう転んでも届かない」と断定できる場合だけFALSE）
     *   それ以外 → UNKNOWN
     */
    public static function v04TriAtLeast(array $conditions, int $n): string
    {
        $t = 0; $u = 0;
        foreach ($conditions as $v) {
            if ($v === true) $t++;
            elseif ($v === null) $u++;
        }
        if ($t >= $n)      return 'TRUE';
        if ($t + $u < $n)  return 'FALSE';
        return 'UNKNOWN';
    }

    /**
     * @ANCHOR-20261013-V04-CORE  6分前人気で固定した隣接馬番ペアの W(k,t) / P(k,t)
     *
     * 02-1「6分前人気で h[k],h[k+1] の隣接馬番ペアを固定。各時点では順位k/k+1の
     *   別の馬へ差し替えず、同じh番号のオッズで比率を計算する」
     *   W(k,t)=win(h[k+1],t)/win(h[k],t)、P(k,t)=place_min(h[k+1],t)/place_min(h[k],t)
     *   0・負・欠損・取消は計算不可（null）。
     *   21分前に順位が入れ替わっていてW<1になっても、同じ馬の実測が有効なら
     *   比率は残し rank_crossed=true を別記する（別の馬に差し替えない）。
     *
     * @param array $pHorses 6分前単勝人気の昇順に並んだ出走馬
     * @return array k（1..N-1）=> ペア情報
     */
    public static function v04Pairs(array $pHorses): array
    {
        $T = [21, 18, 15, 12, 9, 6];

        $ratio = static function (array $u, array $l, string $key, int $t): ?float {
            $uv = $u[$key][$t] ?? null;
            $lv = $l[$key][$t] ?? null;
            if (!self::v04Valid($uv) || !self::v04Valid($lv)) return null;
            return (float) $lv / (float) $uv;          // 下側 ÷ 上側
        };

        $pairs = [];
        $cnt   = count($pHorses);
        for ($i = 0; $i < $cnt - 1; $i++) {
            $u = $pHorses[$i];
            $l = $pHorses[$i + 1];
            $k = $i + 1;                               // 位置番号（付録Fのkと同じ）

            $W = []; $P = [];
            // @ANCHOR-20261020-PREDICTION-EXACT  元値（十進数の文字列）をそのまま持ちます。
            //   判定はこの元値で行い、$W/$P の数値は画面と監査だけに使います。
            $raw = ['Wn' => [], 'Wd' => [], 'Pn' => [], 'Pd' => []];
            foreach ($T as $t) {
                $W[$t] = $ratio($u, $l, 'tan_series',      $t);
                $P[$t] = $ratio($u, $l, 'fuku_min_series', $t);
                $raw['Wd'][$t] = $u['tan_series'][$t]      ?? null;   // 上側＝分母
                $raw['Wn'][$t] = $l['tan_series'][$t]      ?? null;   // 下側＝分子
                $raw['Pd'][$t] = $u['fuku_min_series'][$t] ?? null;
                $raw['Pn'][$t] = $l['fuku_min_series'][$t] ?? null;
            }

            $w21 = $W[21]; $w6 = $W[6]; $p6 = $P[6];

            // ─────────────────────────────────────────────────────────
            // @ANCHOR-20261020-PREDICTION-EXACT  判定はすべて元値のたすき掛けで行います
            //   【旧】float で割った比を 2.0 や 1.5 と比べていました。
            //   W>=2    ⇔ 下側 >= 2×上側
            //   W<1.50  ⇔ 2×下側 <  3×上側
            //   P>=1.50 ⇔ 2×下側 >= 3×上側
            //   縮小率>=0.20 ⇔ 4×W21 >= 5×W6 ⇔ 4×下21×上6 >= 5×下6×上21
            // ─────────────────────────────────────────────────────────
            $cw = static function (int $t, int $p, int $q) use ($raw): ?int {
                return self::cmpRatio($raw['Wn'][$t] ?? null, $raw['Wd'][$t] ?? null, $p, $q);
            };
            $cp = static function (int $t, int $p, int $q) use ($raw): ?int {
                return self::cmpRatio($raw['Pn'][$t] ?? null, $raw['Pd'][$t] ?? null, $p, $q);
            };
            $t1 = $cw(21, 2, 1); $w21Ge2 = ($t1 === null) ? null : ($t1 >= 0);
            $t2 = $cw(6,  2, 1); $w6Ge2  = ($t2 === null) ? null : ($t2 >= 0);
            $t3 = $cw(6,  3, 2); $w6Lt15 = ($t3 === null) ? null : ($t3 <  0);
            $t4 = $cp(6,  3, 2); $p6Ge15 = ($t4 === null) ? null : ($t4 >= 0);
            $t5 = $cw(21, 1, 1); $crossed = ($t5 === null) ? null : ($t5 < 0);
            $t6 = self::cmpProd(4, [$raw['Wn'][21] ?? null, $raw['Wd'][6]  ?? null],
                                5, [$raw['Wn'][6]  ?? null, $raw['Wd'][21] ?? null]);
            $shrink20 = ($t6 === null) ? null : ($t6 >= 0);
            $t7 = self::cmpProd(1, [$raw['Wn'][6]  ?? null, $raw['Wd'][21] ?? null],
                                1, [$raw['Wn'][21] ?? null, $raw['Wd'][6]  ?? null]);

            // 壁の出現／消滅／拡大／縮小（欠損は不明のまま）
            $appear = $vanish = $expand = $shrink = null;
            $shrinkRate = null;
            if ($w21Ge2 !== null && $w6Ge2 !== null) {
                $appear = (!$w21Ge2 && $w6Ge2);
                $vanish = ($w21Ge2 && !$w6Ge2);
            }
            if ($t7 !== null) { $expand = ($t7 > 0); $shrink = ($t7 < 0); }
            // 縮小率の数値は画面と監査のためだけに残します（判定には使いません）
            if ($w21 !== null && $w6 !== null && $w21 > 0) { $shrinkRate = ($w21 - $w6) / $w21; }
            // 単複の一致／不一致（単勝壁 W>=2.00、複勝補強 P>=1.50）
            $agree = ($w6Ge2 !== null && $p6Ge15 !== null) ? ($w6Ge2 === $p6Ge15) : null;

            $missing = [];
            foreach ($T as $t) {
                if ($W[$t] === null) $missing[] = "W{$t}";
                if ($P[$t] === null) $missing[] = "P{$t}";
            }

            $pairs[$k] = [
                'k'            => $k,
                'upper_num'    => (int) ($u['num'] ?? 0),
                'lower_num'    => (int) ($l['num'] ?? 0),
                'upper_pop'    => (int) ($u['popularity'] ?? 0),
                'lower_pop'    => (int) ($l['popularity'] ?? 0),
                'W'            => array_map(static fn($v) => $v === null ? null : round($v, 4), $W),
                'P'            => array_map(static fn($v) => $v === null ? null : round($v, 4), $P),
                '出現'         => $appear,
                '消滅'         => $vanish,
                '拡大'         => $expand,
                '縮小'         => $shrink,
                '縮小率'       => $shrinkRate === null ? null : round($shrinkRate, 4),
                '単複一致'     => $agree,
                '単勝壁6分'    => $w6Ge2,
                '単勝壁21分'   => $w21Ge2,
                '複勝補強6分'  => $p6Ge15,
                'rank_crossed' => $crossed,
                'missing'      => $missing,
                // @ANCHOR-20261020-PREDICTION-EXACT  元値と、元値で出した判定
                'raw'          => $raw,
                'exact'        => ['W21>=2' => $w21Ge2, 'W6>=2' => $w6Ge2,
                                   'W6<1.50' => $w6Lt15, 'P6>=1.50' => $p6Ge15,
                                   '21→6短縮>=0.20' => $shrink20],
            ];
        }
        return $pairs;
    }

    /**
     * @ANCHOR-20261013-V04-CORE  X1〜X6・Z の三値判定（付録F derive_flags と同一）
     *
     * 02-2 の表。閾値はすべて研究初期値であり検証済みの本番値ではない。
     *   X1 境界弱化(+2)   k<=6 で W(k,21)>=2.00 かつ（W(k,6)<2.00 または 縮小20%以上）
     *   X2 単複乖離(+1)   k<=10 で W(k,6)>=2.00 かつ P(k,6)<1.50
     *   X3 中位接近(+1)   k=3,4,5,6 が全て有効で max W(k,6) < 1.50。N<7 は構造的非該当
     *   X4 穴側の絶対支持(+1) 7番人気以下の異なる2頭以上で複勝最小が
     *                      21→6分に5%以上短縮、または9→6分に3%以上短縮
     *   X5 中穴の壁(+1)   k=7..min(10,N-1) で W(k,6)>=2.00。該当境界が無ければFALSE
     *   X6 類似統計(+1)   有効50レース以上かつ基準率より10pp以上高い。
     *                      基準率が未定義・未提供なら必ずUNKNOWN（0%で埋めない）
     *   Z  安定上位(-2)   k=1..min(5,N-1) の異なる2境界以上で
     *                      W(k,21)>=2.00 かつ W(k,6)>=2.00 かつ P(k,6)>=1.50
     *
     * @param  int        $N        取消を反映した実出走頭数
     * @param  array      $pairs    v04Pairs() の戻り
     * @param  array      $pHorses  出走馬（popularity / fuku_min_series を持つ）
     * @param  array|null $similar  ['sample_size'=>int|null, 'delta_pp'=>float|null]
     * @return array      ['flags'=>[X1..Z=>'TRUE'|'FALSE'|'UNKNOWN'], 'evidence'=>[...]]
     */
    public static function v04DeriveFlags(int $N, array $pairs, array $pHorses, ?array $similar = null): array
    {
        $thr  = AiController::V04_THRESHOLDS;
        $maxK = max(0, $N - 1);
        $at   = static fn(int $k, int $t, string $v) => $pairs[$k][$v][$t] ?? null;

        // ── X1 境界弱化 ─────────────────────────────────────────────────────
        //   上側の21分前が有効で壁未満なら、6分前が欠損でもFALSEと断定できる。
        // ─────────────────────────────────────────────────────────────
        // @ANCHOR-20261020-PREDICTION-EXACT  判定は $pairs[k]['exact']（元値で出した結果）
        //   【旧】丸めた $W[$t] を 2.0 と比べ、割り算で短縮率を出していました。
        //
        //   受入試験は $pairs を手で組み立てて渡すので 'exact' がありません。
        //   その場合は、渡された比の値そのものを十進数の文字列として
        //   たすき掛けで比べます（ここでも float の比較はしません）。
        // ─────────────────────────────────────────────────────────────
        $ex = static function (int $k, string $key) use ($pairs) {
            if (isset($pairs[$k]['exact']) && array_key_exists($key, $pairs[$k]['exact'])) {
                return $pairs[$k]['exact'][$key];
            }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-DIGITS
            // ══════════════════════════════════════════════════════════
            //   下の代替比較は float と sprintf を通ります。
            //   <b>本番の入力からは絶対に到達させません。</b>
            //   v04Pairs() が作ったペアには必ず 'raw'（元値）が入るので、
            //   'raw' があるのに 'exact' が無いときは、代替へ落とさず
            //   不明（null）として扱います。代替は、受入試験が手で組み立てた
            //   ペア（'raw' が無いもの）専用です。
            if (isset($pairs[$k]['raw'])) { return null; }
            $w21 = $pairs[$k]['W'][21] ?? null;
            $w6  = $pairs[$k]['W'][6]  ?? null;
            $p6  = $pairs[$k]['P'][6]  ?? null;
            $cmp = static function ($v, int $p, int $q) {
                if (!self::v04Valid($v)) { return null; }
                return self::cmpRatio(rtrim(rtrim(sprintf('%.12F', (float) $v), '0'), '.') ?: '0',
                                      '1', $p, $q);
            };
            switch ($key) {
                case 'W21>=2':
                    $c = $cmp($w21, 2, 1); return ($c === null) ? null : ($c >= 0);
                case 'W6>=2':
                    $c = $cmp($w6, 2, 1);  return ($c === null) ? null : ($c >= 0);
                case 'W6<1.50':
                    $c = $cmp($w6, 3, 2);  return ($c === null) ? null : ($c < 0);
                case 'P6>=1.50':
                    $c = $cmp($p6, 3, 2);  return ($c === null) ? null : ($c >= 0);
                case '21→6短縮>=0.20':
                    // (W21−W6)/W21 >= 0.20 ⇔ 4×W21 >= 5×W6
                    if (!self::v04Valid($w21) || !self::v04Valid($w6)) { return null; }
                    $f = static fn($v) => rtrim(rtrim(sprintf('%.12F', (float) $v), '0'), '.') ?: '0';
                    $c = self::cmpProd(4, [$f($w21)], 5, [$f($w6)]);
                    return ($c === null) ? null : ($c >= 0);
            }
            return null;
        };
        $x1 = static function (int $k) use ($ex) {
            $w21Ge2 = $ex($k, 'W21>=2');
            $w6Ge2  = $ex($k, 'W6>=2');
            $shrink = $ex($k, '21→6短縮>=0.20');
            if ($w21Ge2 === false) return false;          // 21分前が壁未満なら確定でFALSE
            if ($w21Ge2 === null)  return null;
            if ($w6Ge2 === false)  return true;           // 壁が消えた
            if ($w6Ge2 === null || $shrink === null) return null;
            return $shrink;                               // 壁は残るが20%以上縮んだか
        };
        $x1Each = []; $x1Ev = [];
        for ($k = 1; $k <= min($maxK, 6); $k++) {
            $x1Each[$k] = $x1($k);
            if ($x1Each[$k] === true) {
                $x1Ev[] = ['k' => $k, 'W21' => $at($k, 21, 'W'), 'W6' => $at($k, 6, 'W'),
                           '縮小率' => $pairs[$k]['縮小率'] ?? null,
                           '馬番' => [$pairs[$k]['upper_num'] ?? null, $pairs[$k]['lower_num'] ?? null]];
            }
        }
        $X1 = self::v04TriAny($x1Each);

        // ── X2 単複乖離 ─────────────────────────────────────────────────────
        //   単勝が壁未満と分かる、または複勝が補強済みと分かる時点でFALSE。
        $x2 = static function (int $k) use ($ex) {
            $w = $ex($k, 'W6>=2'); $p = $ex($k, 'P6>=1.50');
            if ($w === false) return false;
            if ($p === true)  return false;
            if ($w === null || $p === null) return null;
            return ($w && !$p);
        };
        $x2Each = []; $x2Ev = [];
        for ($k = 1; $k <= min($maxK, 10); $k++) {
            $x2Each[$k] = $x2($k);
            if ($x2Each[$k] === true) {
                $x2Ev[] = ['k' => $k, 'W6' => $at($k, 6, 'W'), 'P6' => $at($k, 6, 'P'),
                           '馬番' => [$pairs[$k]['upper_num'] ?? null, $pairs[$k]['lower_num'] ?? null]];
            }
        }
        $X2 = self::v04TriAny($x2Each);

        // ── X3 中位接近 ─────────────────────────────────────────────────────
        //   AND条件なので、1つでも「有効かつ1.50以上」ならFALSE、
        //   4つとも有効で全て1.50未満ならTRUE、残りはUNKNOWN。
        //   N<7 は k=3..6 の境界が揃わないので構造的非該当＝FALSE。
        $x3Vals = [];
        if ($N < 7) {
            $X3 = 'FALSE';
            $x3Note = "N={$N}（<7）のため k=3..6 の境界が成立せず構造的非該当";
        } else {
            $anyOver = false; $allValid = true;
            foreach ([3, 4, 5, 6] as $k) {
                $x3Vals[$k] = $at($k, 6, 'W');            // 表示用
                $lt = $ex($k, 'W6<1.50');                 // 判定は元値から
                if ($lt === false) $anyOver = true;
                if ($lt === null)  $allValid = false;
            }
            $X3    = $anyOver ? 'FALSE' : ($allValid ? 'TRUE' : 'UNKNOWN');
            $x3Note = $anyOver ? 'k=3..6のいずれかが1.50以上' : ($allValid ? '4境界すべて1.50未満' : '欠損あり');
        }

        // ── X4 穴側の絶対支持 ────────────────────────────────────────────────
        //   相対的な流入順位は証拠にしない。各馬の複勝最小の絶対短縮率だけで見る。
        // @ANCHOR-20261020-PREDICTION-EXACT  短縮率も割り算せずに比べます。
        //   (前−後)/前 >= 0.05 ⇔ 19×前 >= 20×後
        //   (前−後)/前 >= 0.03 ⇔ 97×前 >= 100×後
        $x4Runner = static function (array $r) {
            $p21 = $r['fuku_min_series'][21] ?? null;
            $p9  = $r['fuku_min_series'][9]  ?? null;
            $p6  = $r['fuku_min_series'][6]  ?? null;
            if (!self::v04Valid($p6)) return null;
            $c21 = self::cmpProd(19, [$p21], 20,  [$p6]);
            $c9  = self::cmpProd(97, [$p9],  100, [$p6]);
            $v21 = ($c21 === null) ? null : ($c21 >= 0);
            $v9  = ($c9  === null) ? null : ($c9  >= 0);
            if ($v21 === true || $v9 === true) return true;
            if ($v21 === null || $v9 === null) return null;
            return false;
        };
        $x4Each = []; $x4Ev = []; $x4Unknown = [];
        foreach ($pHorses as $h) {
            if ((int) ($h['popularity'] ?? 10000) < 7) continue;
            $v = $x4Runner($h);
            $x4Each[] = $v;
            $p21 = $h['fuku_min_series'][21] ?? null;
            $p9  = $h['fuku_min_series'][9]  ?? null;
            $p6  = $h['fuku_min_series'][6]  ?? null;
            $row = ['num' => (int) ($h['num'] ?? 0), '人気' => (int) ($h['popularity'] ?? 0),
                    '複勝21' => $p21, '複勝9' => $p9, '複勝6' => $p6,
                    '21→6短縮率' => (self::v04Valid($p21) && self::v04Valid($p6)) ? round(($p21 - $p6) / $p21, 4) : null,
                    '9→6短縮率'  => (self::v04Valid($p9)  && self::v04Valid($p6)) ? round(($p9  - $p6) / $p9,  4) : null];
            if ($v === true)      $x4Ev[] = $row;
            elseif ($v === null)  $x4Unknown[] = $row;
        }
        $X4 = self::v04TriAtLeast($x4Each, 2);

        // ── X5 中穴の壁 ─────────────────────────────────────────────────────
        //   k=7..min(10,N-1)。該当境界自体が存在しなければ（空集合）FALSE。
        $x5Each = []; $x5Ev = [];
        for ($k = 7; $k <= min($maxK, 10); $k++) {
            $w = $at($k, 6, 'W');                      // 表示用
            $x5Each[$k] = $ex($k, 'W6>=2');            // 判定は元値から
            if ($x5Each[$k] === true) {
                $x5Ev[] = ['k' => $k, 'W6' => $w,
                           '馬番' => [$pairs[$k]['upper_num'] ?? null, $pairs[$k]['lower_num'] ?? null]];
            }
        }
        $X5 = self::v04TriAny($x5Each);

        // ── X6 類似統計 ─────────────────────────────────────────────────────
        //   基準率（X6_baseline）が未合意のため、通常は $similar=null で UNKNOWN。
        //   07章Bでよっしーに確認中の未決事項。0%で埋めてはいけない。
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-PROMPT  N<7 は構造的非該当で FALSE
        // ══════════════════════════════════════════════════════════════
        // 【直した中身】決着事項①（B案）は「N>=7 で null、N<7 で false」です。
        //   X6 は「7番人気以下の3着内進入率」を見る信号なので、
        //   7番人気以下が存在しない N<7 では成立しません（X3 と同じ扱い）。
        //   検証側（SummaryAiAnalysisCompensate::modeCheckOne）は前から
        //   この扱いでしたが、<b>本番側のここが抜けていました</b>。
        //   X6 は --prediction-exact-check の突き合わせ対象（X1〜X5・Z）に
        //   入っていなかったため、これまで見つかっていませんでした。
        //   先方の参照入力 normal/FIRM_N4_1st_input.json の X6=false と
        //   一致しなかったことで分かりました。
        if ($N < 7) {
            $X6 = 'FALSE';
            $x6Note = 'N<7 のため7番人気以下が存在せず構造的に非該当（決着事項①B案）';
        } elseif (AiController::V04_X6_BASELINE === null) {
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-X6  B案の経路をここで閉じます
            // ══════════════════════════════════════════════════════════
            //   そのとおりでした。本番では V04_X6_BASELINE が null なので
            //   $similar=null で呼ばれ、到達しない経路でしたが、
            //   <b>経路として閉じていませんでした</b>。
            //   基準率（07章B）が未合意のあいだは、類似統計を渡されても
            //   必ず UNKNOWN にします。下の TRUE/FALSE の表は、
            //   基準率が決まって V04_X6_BASELINE に値が入ってから使います。
            $X6 = 'UNKNOWN';
            $x6Note = 'B案：同条件比較基準率（07章B）が未合意のため、'
                    . '類似統計を渡されても TRUE/FALSE にしません';
        } elseif ($similar === null
            || ($similar['sample_size'] ?? null) === null
            || ($similar['delta_pp']    ?? null) === null) {
            $X6 = 'UNKNOWN';
            $x6Note = '同条件比較基準率が未定義・未提供（07章B）のため不明。0%で埋めない';
        } elseif ((int) $similar['sample_size'] < $thr['similar_min_races']) {
            $X6 = 'FALSE';
            $x6Note = "有効母数 {$similar['sample_size']} レースが最低条件 {$thr['similar_min_races']} に未達";
        } else {
            $X6 = ((float) $similar['delta_pp'] >= $thr['similar_3in_delta_pp']) ? 'TRUE' : 'FALSE';
            $x6Note = "有効母数 {$similar['sample_size']} / 基準率との差 {$similar['delta_pp']}pp";
        }

        // ── Z 安定上位 ──────────────────────────────────────────────────────
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-Z  Z も元値のたすき掛けで判定します
        // ══════════════════════════════════════════════════════════════
        // 【10月20日005のご指摘3。本物の不具合でした】
        //   X1〜X5 は $ex（元値の比較）へ置き換えたのに、<b>Zだけ $at
        //   （round(...,4) した表示用の値）を見たままでした。</b>
        //   そのため 19.9999/10 = 1.99999 が 2.0000 に丸められて
        //   「壁あり」と判定され、16頭の例で
        //   混戦 3/3/1 が 堅い 5/2/0 に変わっていました。
        //   穴側が4枠から2枠、大穴側が1枠から0枠に減ります。
        //
        //   【旧コード】消さずに残します。使っていません。
        //     $z = static function (int $k) use ($at, $thr) {
        //         $w21 = $at($k, 21, 'W'); $w6 = $at($k, 6, 'W'); $p6 = $at($k, 6, 'P');
        //         if (self::v04Valid($w21) && $w21 < $thr['win_wall'])   return false;
        //         …（丸めた値と閾値を比べていました）
        //     };
        $z = static function (int $k) use ($ex) {
            $w21 = $ex($k, 'W21>=2');
            $w6  = $ex($k, 'W6>=2');
            $p6  = $ex($k, 'P6>=1.50');
            // 三値のAND。1つでもFALSEならFALSE、不明が混ざればUNKNOWN。
            if ($w21 === false || $w6 === false || $p6 === false) { return false; }
            if ($w21 === null  || $w6 === null  || $p6 === null)  { return null; }
            return true;
        };
        $zEach = []; $zEv = [];
        for ($k = 1; $k <= min($maxK, 5); $k++) {
            $zEach[$k] = $z($k);
            if ($zEach[$k] === true) {
                $zEv[] = ['k' => $k, 'W21' => $at($k, 21, 'W'), 'W6' => $at($k, 6, 'W'), 'P6' => $at($k, 6, 'P'),
                          '馬番' => [$pairs[$k]['upper_num'] ?? null, $pairs[$k]['lower_num'] ?? null]];
            }
        }
        $Z = self::v04TriAtLeast($zEach, 2);

        return [
            'flags'    => ['X1' => $X1, 'X2' => $X2, 'X3' => $X3, 'X4' => $X4,
                           'X5' => $X5, 'X6' => $X6, 'Z'  => $Z],
            'evidence' => [
                'X1' => ['value' => $X1, '対象k' => array_keys($x1Each), '成立' => $x1Ev,
                         'missing_reason' => self::v04MissingReason($x1Each)],
                'X2' => ['value' => $X2, '対象k' => array_keys($x2Each), '成立' => $x2Ev,
                         'missing_reason' => self::v04MissingReason($x2Each)],
                'X3' => ['value' => $X3, 'W6' => $x3Vals, 'note' => $x3Note],
                'X4' => ['value' => $X4, '成立頭数' => count($x4Ev), '成立' => $x4Ev,
                         '不明' => $x4Unknown, '必要件数' => 2],
                'X5' => ['value' => $X5, '対象k' => array_keys($x5Each), '成立' => $x5Ev,
                         'missing_reason' => self::v04MissingReason($x5Each)],
                'X6' => ['value' => $X6, 'note' => $x6Note, 'similar' => $similar],
                'Z'  => ['value' => $Z, '対象k' => array_keys($zEach), '成立' => $zEv,
                         '必要件数' => 2, 'missing_reason' => self::v04MissingReason($zEach)],
            ],
        ];
    }

    /** @ANCHOR-20261013-V04-CORE  三値列のうち不明だった位置を記録する */
    public static function v04MissingReason(array $each): array
    {
        $out = [];
        foreach ($each as $k => $v) { if ($v === null) $out[] = $k; }
        return empty($out) ? [] : ['不明だったk' => $out, '理由' => 'オッズ欠損・取消・0以下のため比率が計算不可'];
    }

    /** @ANCHOR-20261013-V04-CORE  Sからモードへ（付録F score_mode と同一） */
    public static function v04ScoreMode(int $s): string
    {
        if ($s <= AiController::V04_MODE_FIRM_MAX)  return 'FIRM';
        if ($s <= AiController::V04_MODE_MIXED_MAX) return 'MIXED';
        return 'VOLATILE';
    }

    /**
     * @ANCHOR-20261013-V04-CORE  S・S_min・S_max と mode（付録F classify と同一）
     *
     * S = 2*X1 + X2 + X3 + X4 + X5 + X6 - 2*Z（既知TRUE=1、FALSE=0）。
     *   これは確率ではなく研究用の離散指数。
     * 不明がある場合は各不明を0/1に振った全組合せを数え上げ、
     *   ・不明が3信号以上
     *   ・不明の振り方でFIRM/MIXED/VOLATILEの境界をまたぐ
     * のいずれかなら mode=UNKNOWN。不明が1〜2で全組合せが同じモードなら
     * そのモードを付し、mode_has_unknown=true を必ず立てる。
     */
    public static function v04Classify(array $flags): array
    {
        $w = AiController::V04_SIGNAL_WEIGHTS;
        if (array_keys($w) !== array_keys($flags)) {
            // 付録Fの assert 相当。信号キーの取り違えを早い段階で落とす。
            $flags = array_merge(array_fill_keys(array_keys($w), 'UNKNOWN'),
                                 array_intersect_key($flags, $w));
        }
        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-ABNORMAL-INPUT  知らない値で例外を投げない
        // ══════════════════════════════════════════════════════════════════
        // 【旧実装の問題】
        //   TRUE / FALSE / UNKNOWN 以外の値が1つでも入ると
        //   InvalidArgumentException を投げていました。
        //   10月14日（5回目）のご指摘で追加した異常入力試験（AB-T02）で
        //   見つかりました。信号は v04DeriveFlags() が作るので通常は
        //   3値しか入りませんが、例外が外へ出るとレースが止まります。
        //   「候補不足や欠損でレースを止めない」という方針
        //   （@ANCHOR-20260928-NO-RACE-STOP）に反します。
        // 【この変更】
        //   知らない値は【不明（UNKNOWN）】に倒し、\Log::error で必ず表に出します。
        //   仕様の禁止事項「不明値を0点・不振・条件未達として扱うこと」を守るため、
        //   FALSE には倒しません。例外は投げません。
        $badTri = [];
        foreach ($flags as $k => $v) {
            if (!in_array($v, ['TRUE', 'FALSE', 'UNKNOWN'], true)) {
                $badTri[$k] = var_export($v, true);
                $flags[$k]  = 'UNKNOWN';
            }
        }
        if (!empty($badTri)) {
            \Log::error('[V04_CLASSIFY] 知らない信号値を不明として扱った（@ANCHOR-20261014-ABNORMAL-INPUT）', [
                '対象'   => $badTri,
                '対処'   => 'UNKNOWNへ倒した（FALSEには倒さない）',
                // @ANCHOR-20261020-PREDICTION-MODE  新方式：不明が増えると UNRESOLVED になり、
                //   配分を作りません（MIXEDの流用は廃止）。
                '影響'   => '不明が増え、mode_status=UNRESOLVED で選出しない可能性がある',
            ]);
        }

        $unknown = [];
        $fixed   = 0;
        foreach ($flags as $k => $v) {
            if ($v === 'UNKNOWN') { $unknown[] = $k; continue; }
            if ($v === 'TRUE')    $fixed += $w[$k];
        }

        $sMin = $fixed; $sMax = $fixed;
        foreach ($unknown as $k) {
            // ZのUNKNOWNはマイナス方向に作用する → S_min では Z=1、S_max では Z=0
            $sMin += min(0, $w[$k]);
            $sMax += max(0, $w[$k]);
        }

        // 不明の全組合せ（2^u、u<=7なので最大128通り）で判定が揺れるか確認する
        $modes = [];
        $u     = count($unknown);
        for ($mask = 0; $mask < (1 << $u); $mask++) {
            $val = $fixed;
            foreach ($unknown as $i => $k) { if ($mask & (1 << $i)) $val += $w[$k]; }
            $modes[self::v04ScoreMode((int) $val)] = true;
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-MODE  新方式 の mode 判定（2026-10-20 受領の指定）
        // ══════════════════════════════════════════════════════════════════
        // 【旧実装】
        //   $mode = ($u >= AiController::V04_UNKNOWN_COUNT_AT_LEAST || count($modes) > 1)
        //       ? 'UNKNOWN' : array_key_first($modes);
        //   'allocation_mode' => ($mode === 'UNKNOWN') ? 'MIXED_PROVISIONAL' : $mode,
        //
        //   ①「不明が3信号以上なら UNKNOWN」は 新方式 の条件にありません。
        //   ②「UNKNOWN なら配分は MIXED を流用」は 新方式 が明確に禁止しています。
        //      配分用modeを使えるのは、不明がX6だけで S0 が 1 か 3 のときだけです。
        //      それ以外の UNKNOWN は allocation_mode=null・選出しない（UNRESOLVED）。
        //
        // 【新方式 の規則】server_contract_r12.2 5章／詳細設計書5.2
        //   可能なmodeが一つ            → そのmode / CONFIRMED   / 配分も同じmode
        //   不明がX6だけで S0=1         → UNKNOWN  / PROVISIONAL / 配分MIXED
        //   不明がX6だけで S0=3         → UNKNOWN  / PROVISIONAL / 配分VOLATILE
        //   それ以外で複数modeにまたがる → UNKNOWN  / UNRESOLVED  / 配分なし・選出しない
        //
        //   S0 は X6 を除いた重み付き合計。X6以外が全部確定しているときだけ出せます。
        //   PROVISIONAL では星（display_rating）を出しません。
        $mode = (count($modes) === 1) ? array_key_first($modes) : 'UNKNOWN';

        // S0 … X6 以外が全部確定しているときだけ値になります
        $s0 = 0; $s0ok = true;
        foreach ($flags as $k => $v) {
            if ($k === 'X6') continue;
            if ($v === 'UNKNOWN') { $s0ok = false; break; }
            if ($v === 'TRUE')    $s0 += $w[$k];
        }
        $S0 = $s0ok ? $s0 : null;

        $onlyX6 = (count($unknown) === 1 && $unknown[0] === 'X6');

        if (count($modes) === 1) {
            $modeStatus = 'CONFIRMED';
            $allocMode  = $mode;
            $policyId   = 'BASE_TRI_STATE_V1';
            $selEnabled = true;
        } elseif ($onlyX6 && ($S0 === 1 || $S0 === 3)) {
            $modeStatus = 'PROVISIONAL';
            $allocMode  = ($S0 === 1) ? 'MIXED' : 'VOLATILE';
            $policyId   = 'X6_ONLY_UPPER_MODE_V1';
            $selEnabled = true;
        } else {
            $modeStatus = 'UNRESOLVED';
            $allocMode  = null;
            $policyId   = 'BASE_TRI_STATE_V1';
            $selEnabled = false;
        }

        // 星は、取り得る S が全部同じ星になるときだけ出します。
        // PROVISIONAL は契約で null 固定です。
        $stars = [];
        for ($mask = 0; $mask < (1 << $u); $mask++) {
            $val = $fixed;
            foreach ($unknown as $i => $k) { if ($mask & (1 << $i)) $val += $w[$k]; }
            $stars[self::v04StarOfS((int) $val)] = true;
        }
        $displayRating = ($modeStatus === 'CONFIRMED' && count($stars) === 1)
            ? array_key_first($stars) : null;

        return [
            'mode'             => $mode,
            'S'               => empty($unknown) ? $fixed : null,
            'S_min'            => $sMin,
            'S_max'            => $sMax,
            'S0'               => $S0,
            'unknown_features' => $unknown,
            'mode_has_unknown' => !empty($unknown),
            // @ANCHOR-20261020-PREDICTION-MODE  配分に使うmode。停止時は null です。
            'allocation_mode'  => $allocMode,
            'mode_status'      => $modeStatus,
            'mode_policy_id'   => $policyId,
            'selection_enabled' => $selEnabled,
            'display_rating'   => $displayRating,
            '取り得るmode'     => array_keys($modes),
        ];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-MODE  S の値から星（1〜5）
     *   r12.1 の検証経路（SummaryAiAnalysisCompensate::starOfS）と同じ対応です。
     */
    public static function v04StarOfS(int $s): int
    {
        if ($s <= 0)  return 1;
        if ($s === 1) return 2;
        if ($s <= 3)  return 3;
        if ($s <= 5)  return 4;
        return 5;
    }
}

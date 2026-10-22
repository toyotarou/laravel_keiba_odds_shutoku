
    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261020-R122-EXACT  任意精度の十進数で、丸めずに比べます
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

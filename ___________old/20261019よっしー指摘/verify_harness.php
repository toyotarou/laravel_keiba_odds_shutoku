<?php
class T {
public function modeCheckOne(array $sr, ?array $entrySet = null, ?int $declaredCount = null): array
    {

        $t6 = []; $t21 = []; $f6 = []; $f21 = []; $f9 = [];
        foreach ($sr as $n => $o) {
            if (isset($o['t'][6]))  $t6[$n]  = $o['t'][6];
            if (isset($o['t'][21])) $t21[$n] = $o['t'][21];
            if (isset($o['f'][6]))  $f6[$n]  = $o['f'][6];
            if (isset($o['f'][21])) $f21[$n] = $o['f'][21];
            if (isset($o['f'][9]))  $f9[$n]  = $o['f'][9];
        }
        $N = count($t6);













































        $FREEZE = 6;                 // 凍結は6分前
        $cancelPre  = [];            // 凍結前の取消（出走馬集合から外す）
        $cancelPost = [];            // 凍結後の取消（凍結集合はそのまま）
        $cancelLate = [];            // 6分前が欠損で、取消が凍結後にしかない馬
        $conflict   = [];            // 取消の時点より後に実数値がある馬
        foreach ($sr as $hn => $ho) {
            if (!isset($ho['cancel'])) continue;
            $slots = $ho['cancel_slots'] ?? [];
            $cOrd = null;
            foreach ($slots as $t) {
                $o = self::slotOrd($t);
                if ($cOrd === null || $o > $cOrd) $cOrd = $o;   // いちばん早い取消
            }
            if ($cOrd === null) { $cancelLate[] = $hn; continue; }

            foreach (array_keys($ho['t'] ?? []) as $t) {
                if (self::slotOrd($t) < $cOrd) { $conflict[] = $hn; break; }
            }
            if (in_array($hn, $conflict, true)) continue;

            if ($cOrd >= $FREEZE) {
                $cancelPre[] = $hn;                      // 凍結前に取消が確認できた
            } elseif (isset($ho['t'][$FREEZE])) {
                $cancelPost[] = $hn;                     // 6分前は数値、そのあと取消
            } else {
                $cancelLate[] = $hn;                     // 6分前が欠損・根拠は凍結後だけ
            }
        }
        sort($cancelPre); sort($cancelPost); sort($cancelLate); sort($conflict);

        if (!empty($conflict)) {








            $hasEntry = ($entrySet !== null && !empty($entrySet));
            return ['N' => $hasEntry ? count($entrySet) : count($sr),
                    'entry_count'    => $hasEntry ? count($entrySet) : null,
                    'acquired_count' => count($sr),
                    'active_count'   => null,   // 矛盾した取消情報からは確定できません
                    '状態' => '計算不能', 'frozen' => [],
                    'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                    'cancelled_late' => $cancelLate,
                    '理由' => '取消の時点より後に実オッズがある（入力不整合・馬番 '
                              . implode('・', $conflict) . '）'
                              . ($hasEntry ? '／出馬表 ' . count($entrySet)
                                             . '頭・オッズが取れた馬 ' . count($sr) . '頭' : '')];
        }
        $cancelled = $cancelPre;   // 以降の「外す対象」は凍結前のぶんだけ

        if ($entrySet !== null) {
            if (empty($entrySet)) {



                return ['N' => ($declaredCount !== null) ? $declaredCount : count($sr),
                        'entry_count'    => 0,
                        'acquired_count' => count($sr),
                        'active_count'   => null,   // 出馬表が無いので確定できません
                        '状態' => '計算不能', 'frozen' => [],
                        'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                        'cancelled_late' => $cancelLate,
                        '理由' => '出馬表が欠損している（出馬表 0頭'
                                  . ($declaredCount !== null
                                     ? '・レース情報の出走頭数 ' . $declaredCount . '頭' : '')
                                  . '・オッズが取れた馬 ' . count($sr) . '頭）'];
            }
            $entryNums = array_keys($entrySet);
            sort($entryNums);

            if ($declaredCount !== null && $declaredCount !== count($entryNums)) {
                return ['N' => count($entryNums), '状態' => '計算不能', 'frozen' => [],
                        'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                        'cancelled_late' => $cancelLate,
                        '理由' => 'レース情報の出走頭数（' . $declaredCount
                                  . '頭）と出馬表の頭数（' . count($entryNums) . '頭）が合わない'];
            }
            $extra = array_values(array_diff(array_keys($sr), $entryNums));
            if (!empty($extra)) {
                sort($extra);
                return ['N' => count($entryNums), '状態' => '計算不能', 'frozen' => [],
                        'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                        'cancelled_late' => $cancelLate,
                        '理由' => '出馬表に無い馬番のオッズがある（馬番 '
                                  . implode('・', $extra) . '）'];
            }

            $active = array_values(array_diff($entryNums, $cancelled));
            sort($active);
            if (count($active) < 2) {
                return ['N' => count($active), '状態' => '計算不能', 'frozen' => [],
                        'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                        'cancelled_late' => $cancelLate,
                        '理由' => '取消を除いた出走馬が' . count($active) . '頭しかいない'];
            }

            $lack = [];
            foreach ($active as $hn) {
                if (!isset($sr[$hn]['t'][6])) $lack[] = $hn;
            }
            if (!empty($lack)) {
                return ['N' => count($active), '状態' => '計算不能', 'frozen' => [],
                        'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                        'cancelled_late' => $cancelLate,
                        '理由' => '出走馬の6分前単勝が無い（馬番 '
                                  . implode('・', $lack) . ' / 出馬表 '
                                  . count($entryNums) . '頭・取消 '
                                  . count($cancelled) . '頭）'
                                  . (empty($cancelLate) ? ''
                                     : '／うち、取消の記録が3分前以降にしかない馬 '
                                       . implode('・', $cancelLate)
                                       . '（凍結前の取消として外しません）')];
            }

            foreach ($cancelled as $hn) {
                unset($t6[$hn], $t21[$hn], $f6[$hn], $f21[$hn], $f9[$hn]);
            }
            $N = count($t6);
        }











        $missing6 = [];
        foreach ($sr as $hn => $ho) {



            if (in_array($hn, $cancelPre, true) || in_array($hn, $cancelPost, true)) continue;
            if (!isset($ho['t'][6])) $missing6[] = $hn;
        }
        if (!empty($missing6)) {
            sort($missing6);
            return ['N' => count($sr) - count($cancelled), '状態' => '計算不能',
                    'frozen' => [], 'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                        'cancelled_late' => $cancelLate,
                    '理由' => '6分前の単勝オッズが欠けた馬がある（馬番 '
                              . implode('・', $missing6) . '）'
                              . (empty($cancelLate) ? ''
                                 : '／うち、取消の記録が3分前以降にしかない馬 '
                                   . implode('・', $cancelLate)
                                   . '（凍結前の取消として外しません）')];
        }

        if ($N < 2) {
            return ['N' => $N, '状態' => '計算不能', 'frozen' => [],
                    '理由' => '6分前の単勝オッズが足りない（凍結順位が作れない）'];
        }

        $order = array_keys($t6);
        usort($order, static function ($a, $b) use ($t6) {
            if ($t6[$a] === $t6[$b]) return $a <=> $b;
            return ($t6[$a] < $t6[$b]) ? -1 : 1;
        });
        $frozen = [];
        $bands  = [];
        foreach ($order as $idx => $n) {
            $frozen[$n] = $idx + 1;

            $bands[$n] = ($frozen[$n] <= 6) ? 'A' : (($frozen[$n] <= 10) ? 'B' : 'D');
        }


        $up = static fn(int $k) => $order[$k - 1];
        $dn = static fn(int $k) => $order[$k];





        $ge2 = static function (array $m, int $k) use ($up, $dn): ?bool {
            $a = $m[$dn($k)] ?? null; $b = $m[$up($k)] ?? null;
            return ($a === null || $b === null) ? null : ($a >= 2 * $b);
        };
        $lt2 = static function (array $m, int $k) use ($up, $dn): ?bool {
            $a = $m[$dn($k)] ?? null; $b = $m[$up($k)] ?? null;
            return ($a === null || $b === null) ? null : ($a < 2 * $b);
        };
        $lt150 = static function (array $m, int $k) use ($up, $dn): ?bool {
            $a = $m[$dn($k)] ?? null; $b = $m[$up($k)] ?? null;
            return ($a === null || $b === null) ? null : (2 * $a < 3 * $b);
        };
        $ge150 = static function (array $m, int $k) use ($up, $dn): ?bool {
            $a = $m[$dn($k)] ?? null; $b = $m[$up($k)] ?? null;
            return ($a === null || $b === null) ? null : (2 * $a >= 3 * $b);
        };


        $wShrink20 = static function (int $k) use ($up, $dn, $t6, $t21): ?bool {
            $a = $t6[$dn($k)]  ?? null; $b = $t21[$up($k)] ?? null;
            $c = $t21[$dn($k)] ?? null; $d = $t6[$up($k)]  ?? null;
            if ($a === null || $b === null || $c === null || $d === null) return null;
            return (10 * $a * $b <= 8 * $c * $d);
        };

        $pShrink = static function (?int $after, ?int $before, int $keep): ?bool {
            if ($after === null || $before === null) return null;
            return (100 * $after <= $keep * $before);
        };

        $v = [];
        for ($k = 1; $k <= min(6, $N - 1); $k++) {
            $v[] = self::triAnd([$ge2($t21, $k), self::triOr([$lt2($t6, $k), $wShrink20($k)])]);
        }
        $x1 = self::atLeast($v, 1);

        $v = [];
        for ($k = 1; $k <= min(10, $N - 1); $k++) {
            $v[] = self::triAnd([$ge2($t6, $k), $lt150($f6, $k)]);
        }
        $x2 = self::atLeast($v, 1);

        if ($N < 7) {
            $x3 = false;
        } else {
            $v = [];
            foreach ([3, 4, 5, 6] as $k) {
                $v[] = ($k > $N - 1) ? null : $lt150($t6, $k);
            }
            $x3 = self::triAnd($v);
        }

        $v = [];
        for ($rank = 7; $rank <= $N; $rank++) {
            $n = $order[$rank - 1];
            $v[] = self::triOr([
                $pShrink($f6[$n] ?? null, $f21[$n] ?? null, 95),
                $pShrink($f6[$n] ?? null, $f9[$n]  ?? null, 97),
            ]);
        }
        $x4 = self::atLeast($v, 2);

        $v = [];
        for ($k = 7; $k <= min(10, $N - 1); $k++) { $v[] = $ge2($t6, $k); }
        $x5 = self::atLeast($v, 1);

        $v = [];
        for ($k = 1; $k <= min(5, $N - 1); $k++) {
            $v[] = self::triAnd([$ge2($t21, $k), $ge2($t6, $k), $ge150($f6, $k)]);
        }
        $z = self::atLeast($v, 2);



        $rng = static function (?bool $b, int $w): array {
            if ($b === null) return ($w >= 0) ? [0, $w] : [$w, 0];
            return $b ? [$w, $w] : [0, 0];
        };
        $s0min = 0; $s0max = 0;
        foreach ([[$x1, 2], [$x2, 1], [$x3, 1], [$x4, 1], [$x5, 1], [$z, -2]] as $pair) {
            $r = $rng($pair[0], $pair[1]);
            $s0min += $r[0]; $s0max += $r[1];
        }

        $sMin = $s0min;
        $sMax = $s0max + (($N >= 7) ? 1 : 0);

        $out = ['N' => $N, 'X1' => $x1, 'X2' => $x2, 'X3' => $x3,
                'X4' => $x4, 'X5' => $x5, 'Z' => $z,
                'S0min' => $s0min, 'S0max' => $s0max,
                'S0' => ($s0min === $s0max) ? $s0min : null,
                'Smin' => $sMin, 'Smax' => $sMax, 'frozen' => $frozen,
                'bands' => $bands,
                'cancelled' => $cancelPre, 'cancelled_post' => $cancelPost,
                'cancelled_late' => $cancelLate];

        $mA = self::modeOfS($sMin); $mB = self::modeOfS($sMax);
        if ($mA !== $mB) {
            $out['状態'] = '判定不能';
            $out['mode'] = 'UNKNOWN';
            $out['星']   = '';
            return $out;
        }
        $out['状態'] = '確定';
        $out['mode'] = $mA;
        $stA = self::starOfS($sMin); $stB = self::starOfS($sMax);
        $out['星'] = ($stA === $stB) ? (string) $stA : '';   // 星が割れたら出さない
        return $out;
    }

public static function decScale($v): ?int
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '' || !preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $s, $m)) return null;
        $frac = str_pad(isset($m[2]) ? $m[2] : '', 4, '0');
        $i = ((int) $m[1]) * 10000 + ((int) $frac);
        return ($i > 0) ? $i : null;
    }

public static function slotOrd(int $t): int
    {
        if ($t === 999) return 30;
        if ($t === -999)  return 0;
        return $t;
    }

public static function triAnd(array $v): ?bool
    {
        $unk = false;
        foreach ($v as $b) {
            if ($b === false) return false;
            if ($b === null)  $unk = true;
        }
        return $unk ? null : true;
    }

public static function triOr(array $v): ?bool
    {
        $unk = false;
        foreach ($v as $b) {
            if ($b === true) return true;
            if ($b === null) $unk = true;
        }
        return $unk ? null : false;
    }

public static function atLeast(array $v, int $k): ?bool
    {
        $t = 0; $u = 0;
        foreach ($v as $b) {
            if ($b === true)      $t++;
            elseif ($b === null)  $u++;
        }
        if ($t >= $k)       return true;
        if ($t + $u < $k)   return false;
        return null;
    }

public static function modeOfS(int $s): string
    {
        return ($s <= 1) ? 'FIRM' : (($s <= 3) ? 'MIXED' : 'VOLATILE');
    }

public static function starOfS(int $s): int
    {
        if ($s <= 0) return 1;
        if ($s === 1) return 2;
        if ($s <= 3) return 3;
        if ($s <= 5) return 4;
        return 5;
    }
    /**
     * 試験用の入口。
     *   新形式: {"odds": {...}, "entry": [馬番...], "declared": 頭数}
     * オッズの値に "取消" を入れると、本番と同じく正式な取消として扱われます。
     * 取消の時点も本番と同じように全部拾います（cancel_slots）。
     */
    public static function run(string $json): void
    {
        $t = new T();
        $cases = json_decode($json, true);
        $res = [];
        foreach ($cases as $case) {
            $isNew = array_key_exists('odds', $case)
                  || array_key_exists('entry', $case)
                  || array_key_exists('declared', $case);
            $odds     = $isNew ? ($case['odds'] ?? []) : $case;
            $entryIn  = $isNew ? ($case['entry'] ?? null) : null;
            $declared = $isNew ? ($case['declared'] ?? null) : null;

            $sr = []; $entry = [];
            foreach ($odds as $no => $o) {
                if (!$isNew) $entry[(int) $no] = true;
                foreach (($o['t'] ?? []) as $tm => $v) {
                    $raw = trim((string) $v);
                    if ($raw === '取消' || $raw === '除外') {
                        $sr[(int) $no]['cancel'] = $raw;
                        $sr[(int) $no]['cancel_slots'][] = (int) $tm;
                        $ord = self::slotOrd((int) $tm);
                        if (!isset($sr[(int) $no]['cancel_from'])
                            || $ord > $sr[(int) $no]['cancel_from']) {
                            $sr[(int) $no]['cancel_from'] = $ord;
                        }
                        continue;
                    }
                    // 本番と同じく、信号に使う時点の数値だけを取り込みます
                    // （3分前・発走時の数値は入れません。@ANCHOR-20261019-POSTSLOT）
                    if (!in_array((int) $tm, [999, 21, 18, 15, 12, 9, 6], true)) continue;
                    $sv = self::decScale($v);
                    if ($sv !== null) $sr[(int) $no]['t'][(int) $tm] = $sv;
                }
                foreach (($o['f'] ?? []) as $tm => $v) {
                    $sv = self::decScale($v);
                    if ($sv !== null) $sr[(int) $no]['f'][(int) $tm] = $sv;
                }
            }
            if ($isNew) {
                if ($entryIn === null) {
                    $entry = null;
                } else {
                    $entry = [];
                    foreach ($entryIn as $no) $entry[(int) $no] = true;
                }
                if ($declared === null && $entry !== null) $declared = count($entry);
            } else {
                $declared = count($entry);
            }
            $r = $t->modeCheckOne($sr, $entry, $declared === null ? null : (int) $declared);
            $g = function ($k) use ($r) {
                if (!array_key_exists($k, $r)) return 'NA';
                if ($r[$k] === true)  return 'T';
                if ($r[$k] === false) return 'F';
                if ($r[$k] === null)  return 'U';
                return $r[$k];
            };
            $res[] = ['N' => $r['N'], 'X1' => $g('X1'), 'X2' => $g('X2'), 'X3' => $g('X3'),
                      'X4' => $g('X4'), 'X5' => $g('X5'), 'Z' => $g('Z'),
                      'S0min' => $r['S0min'] ?? 'NA', 'S0max' => $r['S0max'] ?? 'NA',
                      'Smin' => $r['Smin'] ?? 'NA', 'Smax' => $r['Smax'] ?? 'NA',
                      'mode' => $r['mode'] ?? 'NA', 'state' => $r['状態'],
                      'frozen' => $r['frozen'] ?? [],
                      'bands' => $r['bands'] ?? [],
                      'cancelled' => $r['cancelled'] ?? [],
                      'cancelled_post' => $r['cancelled_post'] ?? [],
                      'cancelled_late' => $r['cancelled_late'] ?? [],
                      'entry_count' => $r['entry_count'] ?? null,
                      'acquired_count' => $r['acquired_count'] ?? null,
                      'reason' => $r['理由'] ?? ''];
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE), "\n";
    }
}
T::run(file_get_contents($argv[1]));

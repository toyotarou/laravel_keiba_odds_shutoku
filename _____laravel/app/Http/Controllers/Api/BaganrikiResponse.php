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
 * @ANCHOR-20261020-PREDICTION-RESP  原応答の検証と局所回復
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 6章・11章／詳細設計書 10.2
 *
 *   ・識別・版・role・hash・quota・mode_status・allocation_mode・mode_policy_id の
 *     不一致は応答全体を不採用にします。
 *   ・馬番の欠落・重複・未知馬・局所フィールド不正は、その範囲だけ隔離して
 *     正常な他馬を残します。片側のroleが失敗しても、もう片側は残します。
 *   ・未完了や長さ打切りは complete=false のまま保持し、選出へ使いません。
 *   ・訂正は各role最大1回。正常な根拠を消す訂正、complete を partial へ
 *     悪化させる訂正は採用せず、初回を保持します。
 */
final class BaganrikiResponse
{
    public const BEGIN = '<<<BAGANRIKI_V041_R8_BEGIN>>>';
    public const END   = '<<<BAGANRIKI_V041_R8_END>>>';

    /** BEGIN/END に挟まれたJSONを1つだけ厳密に取り出します */
    public static function extract(string $raw): array
    {
        $i = strpos($raw, self::BEGIN);
        $j = strpos($raw, self::END);
        if ($i === false || $j === false || $j <= $i) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'BEGIN/END マーカーが揃っていない', 'json' => null];
        }
        if (strpos($raw, self::BEGIN, $i + 1) !== false) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'BEGIN が複数ある', 'json' => null];
        }
        $body = substr($raw, $i + strlen(self::BEGIN), $j - $i - strlen(self::BEGIN));
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-STRICTJSON  読む前に本文そのものを調べます
        // ══════════════════════════════════════════════════════════════
        //   【旧】$data = json_decode(trim($body), true, 64);
        //     深さ64しか見ていませんでした。重複キーは後勝ちで黙って
        //     片方が消え、128桁超は float へ丸まり、1e400 は INF になります。
        //     どれも「読めた」ことになるので、後段の検証では拾えません。
        $st = BaganrikiJson::strict(trim($body));
        // @ANCHOR-20261108-PREDICTION-JSONREPAIR  記号の打ち間違いだけなら、その場で直して読み直す（11/7 豊田さんの指示）
        //   11/7 は 1st 2件・2nd 1件が「読点・閉じ括弧・コロンが無い」だけで丸ごと読めませんでした。
        //   直すのは、読めなくなった位置のすぐ手前の余分な記号（, : { } [ ] "）を消すか、読点・閉じ括弧を足すことだけで、
        //   文字の中身（数値・理由の文）は変えません。直せたら、その後の検証は今までどおり全部通します。
        $repairs = [];
        if (!$st['ok']) {
            $rp = self::repairJson(trim($body));
            if ($rp !== null) { $st = $rp['st']; $repairs = $rp['repairs']; }
        }
        if (!$st['ok']) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'JSONとして読めない（' . $st['code'] . '：'
                              . $st['reason'] . '）', 'json' => null];
        }
        $data = $st['value'];
        if (!is_array($data)) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'JSONの最上位がオブジェクトでも配列でもありません',
                    'json' => null];
        }
        // @ANCHOR-20261021-PREDICTION-NUMTYPE  JSON上の型の印を後段へ引き継ぎます。
        //   これが無いと、丸まる数を文字列で持ったときに
        //   「数値だったのか、最初から文字列だったのか」が分かりません。
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  型の地図を後段へ渡します（章）
        // ══════════════════════════════════════════════════════════════
        //   number_paths だけでは、{} と [] の区別や、
        //   {"0":"a"} と ["a"] の区別が残りません（010の3-4）。
        //   obj／arr の種類も一緒にした「地図」を渡します。
        //     保存     BaganrikiJson::encode($json, $map)
        //     読戻し   BaganrikiJson::decode($本文)
        //     比べる   BaganrikiJson::same($a, $mapA, $b, $mapB)
        //   number_paths は、これまでの呼び出し元のために残します。
        // @ANCHOR-20261102-PREDICTION-CLERICAL  帳簿の合わせ間違いは、ここで直してから検証します
        $cf = self::clericalFix($data);
        $data = $cf['json'];
        return ['ok' => true, 'code' => null, 'reason' => null, 'json' => $data,
                'number_paths' => (array) ($st['number_paths'] ?? []),
                'map' => (array) ($st['map'] ?? BaganrikiJson::emptyMap()),
                'clerical_fixes' => $cf['fixes'],
                'json_repairs' => $repairs];
    }

    /** @ANCHOR-20261108-PREDICTION-JSONREPAIR  同じ本文を何度も直さないための控え（1プロセス内） */
    private static array $repairMemo = [];

    /**
     * @ANCHOR-20261108-PREDICTION-JSONREPAIR  記号の打ち間違いを直して読み直します
     *   読めなかった位置（N バイト目）の手前8バイトの範囲で、記号1つを「消す」か「足す」を順に試し、
     *   その位置より先まで読めるようになった直し方を採ります。これを最大3か所まで繰り返します。
     *   全部読めたら ['st' => 読めた結果, 'repairs' => 直した箇所] 、直せなければ null。
     *   BAGANRIKI_PREDICTION_JSON_REPAIR=0 で止められます（既定は直す）。
     */
    public static function repairJson(string $body): ?array
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_JSON_REPAIR', '1')));
        if (in_array($v, ['0', 'false', 'off', 'no'], true)) { return null; }
        $key = hash('sha256', $body);
        if (array_key_exists($key, self::$repairMemo)) { return self::$repairMemo[$key]; }
        $posOf = static function (array $st): ?int {
            // 記号の抜け・余りだけを直す対象にします（重複キー・NaN・桁あふれ等は直さず、今までどおり不採用）
            $r = (string) ($st['reason'] ?? '');
            if (!preg_match('/(\d+) バイト目(?:にコロンがありません|に読点か閉じ波括弧がありません|に読点か閉じ角括弧がありません|でキーが文字列になっていません|より後ろに余分な文字があります|に読めない文字があります（[\]\}]）)/u', $r, $m)) {
                return null;
            }
            return (int) $m[1];
        };
        $punct = ',:{}[]"';
        $cur = $body;
        $st = BaganrikiJson::strict($cur);
        $repairs = [];
        // 11/7 の実物は、同じ打ち間違いを馬ごとに繰り返していた（1件で最大8か所）ので、20か所まで直します
        for ($round = 0; $round < 20 && !$st['ok']; $round++) {
            $pos = $posOf($st);
            if ($pos === null) { break; }
            $best = null; $bestNp = -1;
            // ① 余分な記号を消す（1〜3文字、全部が記号のときだけ）を先に試す
            // ② 消して直らないときだけ、読点・閉じ括弧を足す（開き括弧は足さない：にせの馬を作らないため）
            foreach (['del', 'ins'] as $phase) {
                for ($back = 0; $back <= 16; $back++) {
                    $at = $pos - $back;
                    if ($at < 0 || $at > strlen($cur)) { continue; }
                    $tries = [];
                    if ($phase === 'del') {
                        for ($n = 1; $n <= 3; $n++) {
                            $frag = substr($cur, $at, $n);
                            if (strlen($frag) !== $n || strspn($frag, $punct) !== $n) { break; }
                            $tries[] = ['del', $frag, substr($cur, 0, $at) . substr($cur, $at + $n)];
                        }
                    } else {
                        foreach ([',', '}', ']'] as $ins) {
                            $tries[] = ['ins', $ins, substr($cur, 0, $at) . $ins . substr($cur, $at)];
                        }
                    }
                    // 全部読める直し方を最優先、無ければいちばん先まで読める直し方（同じなら先に試した方）
                    foreach ($tries as [$op, $c, $cand]) {
                        $r = BaganrikiJson::strict($cand);
                        $np = $r['ok'] ? PHP_INT_MAX : $posOf($r);
                        if ($np !== null && $np > $pos + 1 && $np > $bestNp) {
                            $best = [$op, $c, $at, $cand, $r];
                            $bestNp = $np;
                        }
                    }
                }
                if ($best !== null) { break; }
            }
            if ($best === null) { break; }
            [$op, $c, $at, $cur, $st] = $best;
            $repairs[] = ['op' => $op, 'char' => $c, 'byte' => $at];
        }
        $out = $st['ok'] ? ['st' => $st, 'repairs' => $repairs] : null;
        if (count(self::$repairMemo) > 50) { self::$repairMemo = []; }
        self::$repairMemo[$key] = $out;
        if ($out !== null) {
            \Log::warning('[PREDICTION-JSONREPAIR] AIの答えの記号の打ち間違いを直して読みました', ['repairs' => $repairs]);
        }
        return $out;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261102-PREDICTION-CLERICAL  帳簿の合わせ間違いを直す（11/2 豊田の判断）
     * ═══════════════════════════════════════════════════════════════════════
     *   11/2 の実AIの試験で、AIの点数や根拠そのものは読めるのに、
     *   「合計の欄が合わない」「選ばない馬の印の付け方」などの<b>帳簿の合わせ間違いだけで</b>
     *   馬ごと不採用になり、公開できる馬がほとんど残りませんでした。
     *   これらは、AIが書いた採点・資格・順位からサーバが一意に計算し直せるので、
     *   不採用にせず、ここで直してから検証に回します。直した箇所は fixes に残します。
     *
     *   直すもの（AIの判断は変えません。決まりで一意に決まる欄だけです）
     *     1. observed_sum・observed_cap・score_status・total_score … scores から計算し直す
     *        （scores に範囲外・整数でない値があるときは直さず、検証に任せる）
     *     2. 非適格馬（eligibility が ELIGIBLE でない）の rank_basis … NOT_RANKED にする
     *     3. public_candidates が {horse_no, …} の形 … 馬番の配列にする
     *     4. 各馬の public_candidate の印 … public_candidates に入っているかで付け直す
     *     5. shortfalls・validation_issues が配列でない … 1件のものは配列に包む、無ければ []
     *   直さないもの（中身の問題なので、今までどおり検証で落とす）
     *     根拠（evidence）が無い・別の馬のデータを根拠にしている・出典が無い、など
     *
     *   BAGANRIKI_PREDICTION_CLERICAL_FIX=0 で止められます（既定は直す）。
     * @return array ['json' => 直した応答, 'fixes' => [['where','field','from','to'], …]]
     */
    public static function clericalFix(array $res): array
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_CLERICAL_FIX', '1')));
        if (in_array($v, ['0', 'false', 'off', 'no'], true)) { return ['json' => $res, 'fixes' => []]; }
        $fixes = [];
        $note = static function (string $where, string $field, $from, $to) use (&$fixes) {
            $fixes[] = ['where' => $where, 'field' => $field, 'from' => $from, 'to' => $to];
        };
        // 5. 配列であるべき上の欄
        foreach (['shortfalls', 'validation_issues'] as $k) {
            if (!array_key_exists($k, $res) || $res[$k] === null) {
                $note('top', $k, null, []); $res[$k] = []; continue;
            }
            if (is_array($res[$k]) && !array_is_list($res[$k])) {
                $note('top', $k, 'object', 'array'); $res[$k] = [$res[$k]];
            }
        }
        // 3. 公開リストの形
        if (is_array($res['public_candidates'] ?? null) && array_is_list($res['public_candidates'])) {
            $pc = []; $changed = false;
            foreach ($res['public_candidates'] as $c) {
                if (is_int($c)) { $pc[] = $c; continue; }
                if (is_array($c) && is_int($c['horse_no'] ?? null)) { $pc[] = $c['horse_no']; $changed = true; continue; }
                $pc = null; break;               // 読めない要素があれば直さない
            }
            if ($pc !== null && $changed) {
                $note('top', 'public_candidates', 'objects', $pc);
                $res['public_candidates'] = $pc;
            }
        }
        // 3b. 公開リストは「帯の先頭から枠数ぶん、適格馬だけ」で一意に決まるので、AIの順位と資格から作り直す
        //     （順位 band_ranks・枠 role_quota・資格 eligibility は AI の判断のまま。飛ばし取りはしない）
        if (($res['selection_enabled'] ?? null) === true && is_array($res['band_ranks'] ?? null)
            && is_array($res['role_quota'] ?? null) && is_array($res['horses'] ?? null)) {
            $eligOf = [];
            foreach ($res['horses'] as $h) {
                if (is_array($h) && is_int($h['horse_no'] ?? null)) { $eligOf[$h['horse_no']] = $h['eligibility'] ?? null; }
            }
            $want = []; $readable = true;
            foreach (BaganrikiDiagnose::BANDS as $b) {
                $k = $res['role_quota'][$b] ?? 0;
                if (!is_int($k)) { $readable = false; break; }
                $list = $res['band_ranks'][$b] ?? [];
                if (!is_array($list)) { $readable = false; break; }
                $taken = 0;
                foreach ($list as $no) {
                    if ($taken >= $k) { break; }
                    if (!is_int($no)) { continue; }
                    if (($eligOf[$no] ?? null) === 'ELIGIBLE' && !in_array($no, $want, true)) { $want[] = $no; $taken++; }
                }
            }
            if ($readable && ($res['public_candidates'] ?? null) !== $want) {
                $note('top', 'public_candidates', $res['public_candidates'] ?? null, $want);
                $res['public_candidates'] = $want;
            }
        }
        $pcList = (is_array($res['public_candidates'] ?? null) && array_is_list($res['public_candidates'])
                   && count(array_filter($res['public_candidates'], 'is_int')) === count($res['public_candidates']))
                ? $res['public_candidates'] : null;
        if (!is_array($res['horses'] ?? null)) { return ['json' => $res, 'fixes' => $fixes]; }
        foreach ($res['horses'] as $i => $h) {
            if (!is_array($h)) { continue; }
            $no = is_int($h['horse_no'] ?? null) ? $h['horse_no'] : null;
            $where = 'horse ' . ($no ?? ('#' . $i));
            // 1. 合計の欄
            if (is_array($h['scores'] ?? null)) {
                $sum = 0; $cap = 0; $ok = true;
                foreach ($h['scores'] as $sk => $sv) {
                    if (!array_key_exists($sk, BaganrikiDiagnose::scoreCap())) { $ok = false; break; }
                }
                if ($ok) {
                    foreach (BaganrikiDiagnose::scoreCap() as $sk => $mx) {
                        if (!array_key_exists($sk, $h['scores'])) { $ok = false; break; }
                        $sv = $h['scores'][$sk];
                        if ($sv === null) { continue; }
                        if (!is_int($sv) || $sv < 0 || $sv > $mx) { $ok = false; break; }
                        $sum += $sv; $cap += $mx;
                    }
                }
                if ($ok) {
                    $st = ($cap === array_sum(BaganrikiDiagnose::scoreCap())) ? 'complete' : 'partial';
                    $want = ['observed_sum' => $sum, 'observed_cap' => $cap, 'score_status' => $st,
                             'total_score' => ($st === 'complete') ? $sum : null];
                    foreach ($want as $fk => $fv) {
                        if (!array_key_exists($fk, $h) || $h[$fk] !== $fv) {
                            $note($where, $fk, $h[$fk] ?? null, $fv);
                            $h[$fk] = $fv;
                        }
                    }
                }
            }
            // 2. 非適格馬の順位欄
            $elig = $h['eligibility'] ?? null;
            if (is_string($elig) && $elig !== 'ELIGIBLE' && ($h['rank_basis'] ?? null) !== 'NOT_RANKED') {
                $note($where, 'rank_basis', $h['rank_basis'] ?? null, 'NOT_RANKED');
                $h['rank_basis'] = 'NOT_RANKED';
            }
            // 4. 公開の印
            if ($pcList !== null && $no !== null && array_key_exists('public_candidate', $h)) {
                $flag = in_array($no, $pcList, true);
                if ($h['public_candidate'] !== $flag) {
                    $note($where, 'public_candidate', $h['public_candidate'], $flag);
                    $h['public_candidate'] = $flag;
                }
                // 選ぶ馬に非選出理由は付けない。選ばない馬には決まったコードを付ける（理由の文はサーバが付けた印）
                if ($flag === true && ($h['nonselect_reason'] ?? null) !== null) {
                    $note($where, 'nonselect_reason', $h['nonselect_reason'], null);
                    $h['nonselect_reason'] = null;
                } elseif ($flag === false && !is_array($h['nonselect_reason'] ?? null)) {
                    $code = (($res['selection_enabled'] ?? null) !== true) ? 'SELECTION_DISABLED'
                          : (($elig === 'ELIGIBLE') ? 'BAND_QUOTA'
                          : (($elig === 'UNVERIFIED') ? 'UNVERIFIED' : 'NO_EVIDENCE'));
                    $nsr = ['code' => $code, 'reason' => '公開リストに入っていないため（サーバが付け直した印）'];
                    $note($where, 'nonselect_reason', $h['nonselect_reason'] ?? null, $nsr);
                    $h['nonselect_reason'] = $nsr;
                }
            }
            $res['horses'][$i] = $h;
        }
        return ['json' => $res, 'fixes' => $fixes];
    }

    /**
     * 応答全体の照合。ここが合わなければ応答ごと不採用にします。
     * @param array $expect ['prompt_version','schema_version','race_id','ai_role',
     *                       'snapshot_hash','role_cap','role_quota',
     *                       'mode','mode_status','allocation_mode','mode_policy_id']
     */
    public static function checkEnvelope(array $res, array $expect): array
    {
        $bad = [];
        foreach (['prompt_version', 'schema_version', 'race_id', 'ai_role',
                  'snapshot_hash', 'mode', 'mode_status', 'allocation_mode',
                  'mode_policy_id'] as $k) {
            if (!array_key_exists($k, $expect)) { continue; }
            if (!array_key_exists($k, $res) || $res[$k] !== $expect[$k]) {
                $bad[] = sprintf('%s（期待 %s / 実際 %s）', $k,
                    var_export($expect[$k], true), var_export($res[$k] ?? null, true));
            }
        }
        if (array_key_exists('role_cap', $expect)
            && (!is_int($res['role_cap'] ?? null) || $res['role_cap'] !== $expect['role_cap'])) {
            $bad[] = 'role_cap';
        }
        if (array_key_exists('role_quota', $expect)
            && !array_key_exists('role_quota', $res)) {
            $bad[] = 'role_quota（必須項目がありません）';
        } elseif (array_key_exists('role_quota', $expect)) {
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-NULLQUOTA  選出停止（UNRESOLVED）の扱い
            // ══════════════════════════════════════════════════════════
            // 【本物の不具合でした】
            //   role_quota が null（選出しない）のとき、
            //   ?? -1 と ?? -2 の既定値が必ず食い違うので、
            //   <b>正しい応答まで不採用になっていました。</b>
            //   UNRESOLVED のレースは全部これに当たります。
            //   10月20日005の異常例試験で、正常出力14本のうち
            //   UNRESOLVED の2本が落ちたことで分かりました。
            $rq = $res['role_quota'] ?? null;
            $eq = $expect['role_quota'];
            if ($eq === null || $rq === null) {
                if ($eq !== $rq) {
                    $bad[] = sprintf('role_quota（期待 %s / 実際 %s）',
                        ($eq === null) ? 'null' : '値あり',
                        ($rq === null) ? 'null' : '値あり');
                }
            } else {
                // Additional bands/keys are not the frozen role allocation.
                // Check the exact key set without imposing object member order.
                if (!is_array($rq) || count($rq) !== 3) {
                    $bad[] = 'role_quota（A・B・Dの3項目が必要です）';
                }
                foreach (['A', 'B', 'D'] as $b) {
                    if (!is_array($rq) || !is_int($rq[$b] ?? null) || ($rq[$b] ?? null) !== ($eq[$b] ?? null)) {
                        $bad[] = 'role_quota.' . $b;
                    }
                }
            }
        }
        if (!empty($bad)) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => '応答の識別・版・mode・配分が合わない: ' . implode('／', $bad)];
        }
        return ['ok' => true, 'code' => null, 'reason' => null];
    }

    /**
     * 馬ごとの隔離。凍結集合に無い馬・重複・局所不正はその馬だけ外します。
     * @return array ['kept'=>正常な馬の配列, 'isolated'=>[馬番=>理由]]
     */
    public static function isolateHorses(array $horses, array $frozenNums): array
    {
        $kept = []; $iso = []; $seen = [];
        foreach ($horses as $h) {
            if (!is_array($h)) { $iso['?'.count($iso)] = '馬の行がobjectではありません'; continue; }
            // The response contract requires horse_no as an integer. Do not coerce
            // strings or use the old test-only 'num' alias to bypass validation.
            $num = $h['horse_no'] ?? null;
            if (!is_int($num)) {
                $iso['?' . count($iso)] = 'horse_no が整数ではありません';
                continue;
            }
            if (isset($seen[$num])) { unset($kept[$num]); $iso[$num] = '同じ馬番が重複している'; continue; }
            $seen[$num] = true;
            if (!in_array($num, $frozenNums, true)) { $iso[$num] = '凍結集合に無い馬番'; continue; }
            $kept[$num] = $h;
        }
        // 欠落している馬は「不足」として返します（他馬は残します）
        $missing = array_values(array_diff($frozenNums, array_keys($seen)));
        sort($missing);
        return ['kept' => $kept, 'isolated' => $iso, 'missing' => $missing];
    }

    /**
     * 訂正を採用してよいか。
     *   ・初回の正常な根拠を全部失う → 採用しない
     *   ・complete を partial へ悪化させる → 採用しない
     *   ・凍結集合に無い馬・対象外の変更 → 採用しない
     *   ・受領時刻が初回より前 → 採用しない（同一時刻は可）
     */
    public static function acceptCorrection(array $first, array $fix,
                                            ?string $firstAt = null, ?string $fixAt = null,
                                            array $frozenNums = [], ?array $repairScope = null,
                                            array $rowValid = [],
                                            ?array $firstMap = null,
                                            ?array $fixMap = null): array
    {
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  型まで見て比べます（）
        // ══════════════════════════════════════════════════════════════
        //
        //   $firstMap／$fixMap（型の地図）が両方そろっているときは、
        //   <b>BaganrikiJson::same</b> で比べます。
        //   ・PHPの === では、丸まる数値を文字列で持っているときに
        //     「元が数値だったか文字列だったか」が区別できません。
        //   ・値オブジェクトを使う案は、同じ値の別インスタンスを
        //     差異と見るおそれがあります。地図は値を包まないので、
        //     その問題は起きません。
        //   地図が無いときは、これまでどおり === で比べます
        //   （試験用の素の配列はこちらを通ります）。
        $typed = ($firstMap !== null && $fixMap !== null);
        $sameV = static function ($a, $b, array $path) use ($typed, $firstMap, $fixMap) {
            if (!$typed) { return ($a === $b); }
            return (bool) BaganrikiJson::same($a, (array) $firstMap,
                                              $b, (array) $fixMap, true, $path)['same'];
        };
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-REPAIR
        // ══════════════════════════════════════════════════════════════
        // 【直した中身】
        //   ・初回に無い馬番を<b>一律に拒否</b>していました。
        //     契約6章は「凍結runner集合にいて、horses訂正の対象なら救済する」です。
        //     未知馬（凍結集合にいない）と、初回から漏れた実在馬を分けます。
        //   ・repair_scope と row_valid を受け取っていませんでした。
        //     対象外の馬を変えようとする訂正は採用しません。
        //     row_valid=false の行は適用しません。
        $rescued = []; $skipped = [];
        if ($firstAt !== null && $fixAt !== null) {
            $a = BaganrikiTime::us($firstAt); $b = BaganrikiTime::us($fixAt);
            if ($a === null || $b === null) {
                return ['ok' => false, 'reason' => '受領時刻が日時として読めない'];
            }
            if ($b < $a) { return ['ok' => false, 'reason' => '訂正の受領時刻が初回より前'];
            }
        }
        if ($fix !== [] && $rowValid === []) {
            return ['ok' => false, 'rescued' => [], 'applied' => [], 'skipped' => [],
                    'restated' => [],
                    'reason' => 'row_valid が渡されていません'
                              . '（明示 true の行だけ承認します）'];
        }
        // Invalid correction rows are local failures, not permission to replace
        // initial rows or to reject unrelated valid repairs during scope comparison.
        foreach ($fix as $num => $h) {
            if (($rowValid[(int) $num] ?? null) !== true) {
                $skipped[] = (int) $num;
                unset($fix[$num]);
            }
        }
        foreach ($first as $num => $h0) {
            if (!array_key_exists($num, $fix)) { continue; }   // 変更なしは初回を保持
            $h1 = $fix[$num];
            // @ANCHOR-20261020-PREDICTION-CORRKEY  正規キーで数えます
            $grp = static function ($h) {
                $n = 0;
                foreach ((array) ($h['family_review'] ?? []) as $fam) {
                    if ((string) ($fam['status'] ?? '') === 'SUPPORTED') { $n++; }
                }
                // 正規キーの無い試験用の形には independent_groups で応えます
                if ($n === 0 && array_key_exists('independent_groups', (array) $h)) {
                    $n = (int) $h['independent_groups'];
                }
                return $n;
            };
            $g0 = $grp($h0);
            $g1 = $grp($h1);
            if ($g0 > 0 && $g1 === 0) {
                return ['ok' => false, 'reason' => sprintf('馬番 %d の正常な根拠を全部失う訂正', $num)];
            }
            // 正規キーは score_status。score_state は試験用の形のための控えです。
            $s0 = $h0['score_status'] ?? ($h0['score_state'] ?? 'partial');
            $s1 = $h1['score_status'] ?? ($h1['score_state'] ?? 'partial');
            if ($s0 === 'complete' && $s1 !== 'complete') {
                return ['ok' => false, 'reason' => sprintf('馬番 %d の採点を complete から悪化させる訂正', $num)];
            }
        }
        // ── 初回に無い馬番 ────────────────────────────────────────
        //   凍結集合にいる実在馬 → 救済（初回から漏れていただけ）
        //   凍結集合にいない馬   → 未知馬。応答ごと採用しない
        $frozen  = array_map('intval', $frozenNums);
        $extra   = array_values(array_diff(array_keys($fix), array_keys($first)));
        $unknown = [];
        foreach ($extra as $num) {
            if ($frozen !== [] && in_array((int) $num, $frozen, true)) {
                $rescued[] = (int) $num;
            } else {
                $unknown[] = (int) $num;
            }
        }
        if (!empty($unknown)) {
            sort($unknown);
            return ['ok' => false, 'rescued' => [], 'applied' => [], 'skipped' => [],
                    'reason' => '凍結集合にない馬番を足している（馬番 '
                              . implode('・', $unknown) . '）'];
        }

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-SCOPEFIELD
        // ══════════════════════════════════════════════════════════════
        //   【本物の不具合でした】許可リストを repair_scope.horses だけに
        //   していたので、採点の集計だけがおかしい馬（summary_horses）を
        //   訂正しようとすると「対象外」で拒否していました。
        //   その馬は row_valid=false で初回からも外れるため、
        //   <b>採点欄の誤りひとつで、正常な根拠を持つ馬が候補から落ちます。</b>
        //   契約は「不正な得点項目だけを直し、正常根拠は保持する」です。
        //
        //   そこで範囲ごとに<b>直してよい項目</b>を決めます。
        //   horses は行ごと、summary_horses は採点と資格の欄だけ、
        //   presentation_horses は表示の欄だけ。
        //   範囲外の馬も、範囲内でも許されない項目を変える訂正も採用しません。
        if (is_array($repairScope)) {
            $inH = array_map('intval', (array) ($repairScope['horses'] ?? []));
            $inS = array_map('intval', (array) ($repairScope['summary_horses'] ?? []));
            $inP = array_map('intval', (array) ($repairScope['presentation_horses'] ?? []));
            $inRank = AiController::invalidBandsOf($repairScope);
            $outOfScope = []; $outOfField = [];
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-RESTATE
            // ══════════════════════════════════════════════════════════
            //   【本物の不具合でした】訂正は<b>常に全頭を返す</b>のが契約です
            //   （配布本文：「常に全頭を含む完全なJSONを返し」）。
            //   ところが、範囲に入っていない馬を<b>初回と同じ中身かどうか
            //   調べる前に</b>「対象外を変えようとしている」と判定して、
            //   訂正をまるごと拒否していました。
            //   例：3頭のうち馬番2の集計だけ不正。訂正は3頭返し、2だけ直す。
            //       1と3は初回と同じ。それでも拒否していました。
            //   【いま】<b>未変更の正常行を再掲することと、対象外の行を
            //   書き換えることを分けます。</b>初回と同じ中身なら通します。
            // @ANCHOR-20261020-PREDICTION-RESCUESCOPE
            //   救済馬も horses の対象に入っていることを明示的に見ます。
            //   以前は範囲の検査ごと continue で飛ばしていました。
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-METASPLIT
            // ══════════════════════════════════════════════════════════
            //   内部の監査項目（r122_ で始まるキー）は、こちらが足した
            //   ものです。AIの業務項目ではありません。
            //   ・比較からは<b>外します</b>（これが入っていると、正しい
            //     訂正が「範囲外を変えた」と判定されます）
            //   ・訂正の側に入っていたら<b>断ります</b>（AIに内部の監査
            //     項目を書かせることになるためです）
            $metaIn = [];
            foreach ($fix as $num => $h1) {
                foreach (array_keys((array) $h1) as $k) {
                    if (strncmp((string) $k, 'r122_', 5) === 0) {
                        $metaIn[] = (int) $num . ':' . (string) $k;
                    }
                }
            }
            if ($metaIn !== []) {
                sort($metaIn);
                return ['ok' => false, 'rescued' => [], 'applied' => [],
                        'skipped' => [], 'restated' => [],
                        'reason' => '訂正に内部の監査項目が入っています（'
                                  . implode('・', array_slice($metaIn, 0, 5))
                                  . '）。これはAIが返す項目ではありません'];
            }
            $restated = [];
            foreach ($fix as $num => $h1) {
                $num = (int) $num;
                if (in_array($num, $inH, true)) { continue; }      // 行ごと直せます
                if (in_array($num, $rescued, true)) {
                    // @ANCHOR-...-RESCUESCOPE  救済も horses の対象であること
                    if (!in_array($num, $inH, true)) {
                        $outOfScope[] = $num;
                    }
                    continue;
                }
                $fields = [];
                // Rank-band repairs also need the rank mode/reason on the existing rows.
                // Use the validated initial band; never authorize a band change from the fix.
                if (in_array($first[$num]['band'] ?? null, $inRank, true)) {
                    $fields = ['rank_basis', 'rank_reason'];
                }
                if (in_array($num, $inS, true)) {
                    $fields = array_merge($fields, self::FIX_FIELDS_SUMMARY);
                }
                if (in_array($num, $inP, true)) {
                    $fields = array_merge($fields, self::FIX_FIELDS_PRESENTATION);
                }
                if ($fields === []) {
                    // @ANCHOR-...-RESTATE  初回と同じなら「再掲」です
                    if (array_key_exists($num, $first)
                        && self::sameHorseRow((array) $first[$num], (array) $h1,
                                              $firstMap, $fixMap, [(string) $num])) {
                        $restated[] = $num;
                        continue;
                    }
                    $outOfScope[] = $num;
                    continue;
                }
                $h0 = (array) ($first[$num] ?? []);
                $keys = array_unique(array_merge(array_keys($h0),
                                                 array_keys((array) $h1)));
                foreach ($keys as $k) {
                    if (in_array((string) $k, $fields, true)) { continue; }
                    // @ANCHOR-20261021-PREDICTION-METASPLIT  内部の監査項目は見ません
                    if (strncmp((string) $k, 'r122_', 5) === 0) { continue; }
                    // @ANCHOR-20261021-PREDICTION-TYPEMAP  型まで見て比べます
                    //   どちらかにキーが無い場合もここで分かります
                    //   （?? null は null の値と見分けが付かないので、
                    //     キーの有無を先に見ます）
                    $has0 = array_key_exists($k, $h0);
                    $has1 = array_key_exists($k, (array) $h1);
                    if ($has0 !== $has1) {
                        $outOfField[] = $num . ':' . $k;
                        continue;
                    }
                    if (!$has0) { continue; }           // どちらにも無い
                    $v0 = $h0[$k];
                    $v1 = ((array) $h1)[$k];
                    if (!$sameV($v0, $v1, [(string) $num, (string) $k])) {
                        $outOfField[] = $num . ':' . $k;
                    }
                }
            }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-PREDICTION-FIXPARTIAL  対象外を変えた馬だけを外す（旗の後ろ）
            // ══════════════════════════════════════════════════════════
            //   【10/23 の実AI 18頭実測（5回目）で分かったこと】
            //   訂正が「対象外の馬番1の中身」を変えていたため、訂正全体が
            //   不採用になり、救済できたはずの9頭（初回で行が抜けた馬）も
            //   戻りませんでした。
            //   変更計画書8章「不正な一頭や片側応答だけを理由に、正常な他馬・
            //   もう片側を一括で捨てない」との兼ね合いで、よっしーさんの判断待ちです。
            //   【いま】.env の BAGANRIKI_PREDICTION_FIX_PARTIAL=1 のときだけ、
            //   対象外を変えた馬・直してよい項目の外を変えた馬<b>だけ</b>を
            //   「飛ばし」にして初回のまま残し、他の馬の訂正は採ります。
            //   既定（0）は今までどおり訂正全体を採りません。
            //   未知馬（凍結集合にいない馬）と内部の監査項目は、旗に関係なく
            //   今までどおり訂正全体を採りません。
            $fixPartial = in_array(strtolower(trim((string) env('BAGANRIKI_PREDICTION_FIX_PARTIAL', '0'))),
                                   ['1', 'true', 'on', 'yes'], true);
            $partialSkip = [];
            if ($fixPartial && (!empty($outOfScope) || !empty($outOfField))) {
                foreach ($outOfScope as $nP) { $partialSkip[] = (int) $nP; }
                foreach ($outOfField as $nf) { $partialSkip[] = (int) explode(':', (string) $nf)[0]; }
                $partialSkip = array_values(array_unique($partialSkip));
                sort($partialSkip);
                $outOfScope = []; $outOfField = [];
            }
            if (!empty($outOfScope)) {
                sort($outOfScope);
                return ['ok' => false, 'rescued' => [], 'applied' => [], 'skipped' => [],
                        'restated' => [],
                        'reason' => 'repair_scope の対象外を変えようとしている（馬番 '
                                  . implode('・', $outOfScope) . '）'
                                  . '。初回と同じ中身の再掲は通します'];
            }
            if (!empty($outOfField)) {
                sort($outOfField);
                return ['ok' => false, 'rescued' => [], 'applied' => [], 'skipped' => [],
                        'reason' => 'その範囲で直してよい項目の外を変えています（'
                                  . implode('・', array_slice($outOfField, 0, 5)) . '）'];
            }
        }

        // ══════════════════════════════════════════════════════════════
        //     という説明が関数単体では成立していませんでした。
        //   【いま】<b>row_valid を渡さない呼び方を断ります。</b>
        //     検証結果なしに訂正を承認してはいけないためです。
        //   訂正の中身が空（当てる行が1つも無い）ときは、承認する行も
        //   無いので、この検査は当てません（時刻だけを見る呼び方のためです）。
        $applied = [];
        foreach (array_keys($fix) as $num) {
            $num = (int) $num;
            // @ANCHOR-20261023-PREDICTION-FIXPARTIAL  対象外を変えた馬は当てません（初回のまま）
            if (in_array($num, (array) ($partialSkip ?? []), true)) {
                $skipped[] = $num;
                continue;
            }
            if (($rowValid[$num] ?? null) !== true) {
                $skipped[] = $num;
                continue;
            }
            $applied[] = $num;
        }
        // 再掲（未変更の正常行）は「当てる」側には入れません。初回を保ちます。
        $restated = array_values(array_unique(array_map('intval', $restated ?? [])));
        $applied  = array_values(array_diff($applied, $restated));
        $rescued  = array_values(array_diff($rescued, $skipped));
        $applied  = array_values(array_diff($applied, $skipped));
        sort($rescued); sort($applied); sort($skipped); sort($restated);
        return ['ok' => true, 'reason' => null, 'restated' => $restated,
                'rescued' => $rescued, 'applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-RESTATE  2つの馬行が同じ中身かどうか
     *   キーの並びが違っても同じと見ます（訂正はJSONを作り直すためです）。
     *   入れ子も並べ替えてから比べます。数値の型の違い（1 と 1.0）は
     *   別物として扱います（採点は整数と決まっているためです）。
     */
    public static function sameHorseRow(array $a, array $b,
                                        ?array $mapA = null, ?array $mapB = null,
                                        array $path = []): bool
    {
        // @ANCHOR-20261021-PREDICTION-METASPLIT  内部の監査項目（r122_）は比べません。
        //   こちらが足したもので、AIが返す項目ではないためです。
        $strip = static function (array $v): array {
            $o = [];
            foreach ($v as $k => $x) {
                if (strncmp((string) $k, 'r122_', 5) === 0) { continue; }
                $o[$k] = $x;
            }
            return $o;
        };
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  型の地図があるときは型まで見ます
        // ══════════════════════════════════════════════════════════════
        //   地図があれば、JSON上の型・正確な十進値・整数/小数/指数の
        //   表記まで見ます（010の3-2）。並べ替えは中で行うので、
        //   ここで ksort する必要はありません
        //   （same はオブジェクトのキー順を無視します）。
        if ($mapA !== null && $mapB !== null) {
            $r = BaganrikiJson::same($strip($a), $mapA, $strip($b), $mapB,
                                     true, $path);
            return (bool) $r['same'];
        }
        // 地図が無いとき（試験用の素の配列など）は、これまでどおりです。
        $norm = static function ($v) use (&$norm) {
            if (!is_array($v)) { return $v; }
            $isList = (array_keys($v) === range(0, count($v) - 1));
            $o = [];
            foreach ($v as $k => $x) { $o[$k] = $norm($x); }
            if (!$isList) { ksort($o, SORT_STRING); }
            return $o;
        };
        return $norm($strip($a)) === $norm($strip($b));
    }

    /**
     * @ANCHOR-20261020-PREDICTION-SCOPEFIELD  範囲ごとに直してよい項目
     *   summary_horses は採点と資格のまわりだけ、
     *   presentation_horses は表示のまわりだけを直せます。
     *   ここに無い項目を変える訂正は採用しません。
     */
    public const FIX_FIELDS_SUMMARY = [
        'eligibility', 'tier', 'scores', 'score_evidence', 'score_status',
        'total_score', 'observed_sum', 'observed_cap', 'rank_basis',
        'rank_reason', 'family_review',
    ];
    public const FIX_FIELDS_PRESENTATION = [
        'public_candidate', 'nonselect_reason', 'explanation',
        'evidence_rank_reason', 'evidence_rank_evidence_ids',
    ];

    /** 訂正を反映した結果。対象外の正常行は初回のままにします */
    public static function applyCorrection(array $first, array $fix,
                                           ?array $accept = null): array
    {
        // @ANCHOR-20261020-PREDICTION-REPAIR  採否の結果にある行だけを反映します
        //   $accept を渡さないときは、これまでどおり $fix を全部当てます。
        $out = $first;
        if ($accept === null) {
            foreach ($fix as $num => $h) { $out[$num] = $h; }
            return $out;
        }
        if (($accept['ok'] ?? false) !== true) { return $out; }   // 不採用なら初回のまま
        $allow = array_map('intval',
            array_merge((array) ($accept['applied'] ?? []), (array) ($accept['rescued'] ?? [])));
        foreach ($fix as $num => $h) {
            if (!in_array((int) $num, $allow, true)) { continue; }   // 対象外・row_valid=false
            $out[(int) $num] = $h;
        }
        return $out;
    }
}

<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** Display-only assessments. Never consulted by ranking, quotas, E3/E5 or purchase policy. */
/**
 * @ANCHOR-20261109-PREDICTION-REPLY-TIDY  E3・E5版の返事を、検査の前に「意味を変えずに」整えます
 *   11/9 の非公開試験で、両AIの返事に「書き方だけのミス」が多く、その馬の行がまるごと使えず、
 *   予想が1頭も出ませんでした。答えの中身（どの馬を評価したか・根拠・点数）は変えず、
 *   機械的に一つに決まるものだけを直します。直した内容は inspection の tidied に残します。
 *   ① family_review の status の書き損じ（DATA_MISSINGING など）→ 先頭が一致する正しい値がちょうど1つならそれに
 *   ② score_status・observed_sum・observed_cap・total_score → scores から計算し直す（AIの転記ミスを直すだけ）
 *   ③ market の根拠で market_claim の書き忘れ → observation が決まり文句のときだけ補う
 *        「市場比較（支持加速を根拠にしない）」→ OTHER／null
 *        「支持加速（12→9→6分）」→ 引用が同じ指標の12・9・6分の3件だけのとき ACCELERATION／その指標
 *      （支持加速そのものは、このあと BaganrikiMarketClaim::verify が元の値で確かめます）
 *   ④ market 以外の根拠に付いた market_claim → 取り除く
 *   ⑤ tier と eligibility の食い違いが「どちらも候補外」の組み合わせのとき → tier に合わせる（候補には絶対にしない）
 *   ⑥ score_evidence が丸ごと無い行 → 点数を全部 null（未算出）にする（根拠の無い点は使わない。行と根拠は残す）
 *   ⑧ score_evidence の1項目が null → 「資料なし」の形にし、その項目の点は null（未算出）
 *   ⑦ public_candidates を {band,horse_no,rank} で書いたとき → 馬番の並びにする（順番はそのまま）
 *   直さないもの：根拠の中身・引用値・点数そのもの・候補にするかどうかの判断。それらの誤りは今までどおり検査で外します。
 */
final class BaganrikiReplyTidy
{
    private const STATUS=['SUPPORTED','NO_SUPPORT','DATA_MISSING'];
    private const OTHER_TEXT='市場比較（支持加速を根拠にしない）';
    private const ACCEL_TEXT='支持加速（12→9→6分）';

    /** @return array [$res, $map, $notes] */
    public static function apply(array $res, ?array $map): array
    {
        $notes=[];
        if (!is_array($res['horses']??null)) return [$res,$map,$notes];
        foreach ($res['horses'] as $i=>&$h) {
            if (!is_array($h)) continue;
            $no=$h['horse_no']??$i;
            // ①
            if (is_array($h['family_review']??null)) {
                foreach ($h['family_review'] as $fam=>&$fr) {
                    $st=$fr['status']??null;
                    if (!is_array($fr) || !is_string($st) || in_array($st,self::STATUS,true)) continue;
                    $hit=array_values(array_filter(self::STATUS,static fn($v)=>str_starts_with($st,$v)));
                    if (count($hit)===1) { $fr['status']=$hit[0]; $notes[]="{$no}番 {$fam}.status {$st}→{$hit[0]}"; }
                }
                unset($fr);
            }
            // ⑩ scores・score_evidence・family_review に、決まりにない項目（V3_note など）が足されているとき → その項目だけ外す。
            //    scores の値が 3.0 のような整数の小数なら整数に、整数でない数・文字なら null（未算出）にする
            $caps=BaganrikiCandidateSelection::scoreCaps();
            foreach (['scores','score_evidence'] as $blk) {
                if (!is_array($h[$blk]??null)) continue;
                foreach (array_keys($h[$blk]) as $k) if (!array_key_exists($k,$caps)) { unset($h[$blk][$k]); $notes[]="{$no}番 {$blk}.{$k} を除去"; }
            }
            if (is_array($h['scores']??null)) foreach ($h['scores'] as $k=>$v) {
                if ($v===null || is_int($v)) continue;
                $h['scores'][$k]=(is_float($v) && is_finite($v) && floor($v)===$v) ? (int)$v : null;
                $notes[]="{$no}番 scores.{$k} ".json_encode($v).'→'.json_encode($h['scores'][$k]);
            }
            if (is_array($h['score_evidence']??null)) foreach ($h['score_evidence'] as $k=>$se) {
                if (is_array($se) && array_diff(array_keys($se),['source_refs','reason'])) {
                    $h['score_evidence'][$k]=array_intersect_key($se,['source_refs'=>true,'reason'=>true]); $notes[]="{$no}番 score_evidence.{$k} の余分な項目を除去";
                }
            }
            if (is_array($h['family_review']??null)) foreach ($h['family_review'] as $f=>$fr) {
                if (is_array($fr) && array_diff(array_keys($fr),['status','source_refs','evidence_ids','reason'])) {
                    $h['family_review'][$f]=array_intersect_key($fr,['status'=>true,'source_refs'=>true,'evidence_ids'=>true,'reason'=>true]); $notes[]="{$no}番 family_review.{$f} の余分な項目を除去";
                }
            }
            // ⑥ 採点の根拠（score_evidence）が丸ごと無い行：点数は根拠なしでは使えないので全部 null（未算出）にして、行は残します
            if (!is_array($h['score_evidence']??null) && is_array($h['scores']??null)) {
                $se=[];
                foreach (BaganrikiCandidateSelection::scoreCaps() as $k=>$mx) {
                    $h['scores'][$k]=null;
                    $se[$k]=['source_refs'=>[],'reason'=>'AIの返事に採点の根拠が無いため未算出'];
                }
                $h['score_evidence']=$se;
                $notes[]="{$no}番 score_evidence が無いので点数を未算出に";
            }
            // ⑧ score_evidence の中の1項目が null（採点しなかった項目を null と書いた）→ 「資料なし」の形に。点が付いていればその点も null（根拠の無い点は使わない）
            if (is_array($h['score_evidence']??null) && is_array($h['scores']??null)) {
                foreach (BaganrikiCandidateSelection::scoreCaps() as $k=>$mx) {
                    if (array_key_exists($k,$h['score_evidence']) && !is_array($h['score_evidence'][$k])) {
                        $h['score_evidence'][$k]=['source_refs'=>[],'reason'=>'採点の根拠が書かれていないため未算出'];
                        if (($h['scores'][$k]??null)!==null) $h['scores'][$k]=null;
                        $notes[]="{$no}番 score_evidence.{$k} null→資料なし";
                    }
                }
            }
            // ②
            if (is_array($h['scores']??null)) {
                $ok=true;$sum=0;$cap=0;$nulls=0;
                // 上限はこの版の契約（contract.json の score_caps）です。旧版の SCORE_CAP とは V1・V5 が違います
                foreach (BaganrikiCandidateSelection::scoreCaps() as $k=>$mx) {
                    if (!array_key_exists($k,$h['scores'])) { $ok=false; break; }
                    $v=$h['scores'][$k];
                    if ($v===null) { $nulls++; continue; }
                    if (!is_int($v) || $v<0 || $v>$mx) { $ok=false; break; }
                    $sum+=$v;$cap+=$mx;
                }
                if ($ok) {
                    $want=['score_status'=>$nulls===0?'complete':'partial','observed_sum'=>$sum,'observed_cap'=>$cap,'total_score'=>$nulls===0?$sum:null];
                    foreach ($want as $k=>$v) {
                        if (!array_key_exists($k,$h) || $h[$k]!==$v) {
                            $notes[]="{$no}番 {$k} ".json_encode($h[$k]??null,JSON_UNESCAPED_UNICODE).'→'.json_encode($v);
                            $h[$k]=$v;
                        }
                    }
                }
            }
            // ③④
            if (is_array($h['evidence']??null)) {
                foreach ($h['evidence'] as $j=>&$e) {
                    if (!is_array($e)) continue;
                    $fam=$e['family']??null;
                    if ($fam!=='market') {
                        if (array_key_exists('market_claim',$e)) { unset($e['market_claim']); $notes[]="{$no}番 根拠{$j} market以外のmarket_claimを除去"; }
                        continue;
                    }
                    if (array_key_exists('market_claim',$e)) continue;
                    $obs=$e['observation']??null;
                    if ($obs===self::OTHER_TEXT) {
                        $e['market_claim']=['kind'=>'OTHER','metric'=>null];
                        $notes[]="{$no}番 根拠{$j} market_claim=OTHER を補完";
                    } elseif ($obs===self::ACCEL_TEXT && is_array($e['source_refs']??null)) {
                        $metric=null;$mins=[];
                        foreach ($e['source_refs'] as $ref) {
                            if (!is_string($ref) || !preg_match('/^h'.(int)$no.'_(win_odds|place_min|place_max)_(12|9|6)$/D',$ref,$m)) { $metric=false; break; }
                            if ($metric!==null && $metric!==$m[1]) { $metric=false; break; }
                            $metric=$m[1];$mins[$m[2]]=true;
                        }
                        if (is_string($metric) && count($e['source_refs'])===3 && count($mins)===3) {
                            $e['market_claim']=['kind'=>'ACCELERATION','metric'=>$metric];
                            $notes[]="{$no}番 根拠{$j} market_claim=ACCELERATION/{$metric} を補完";
                        }
                    }
                }
                unset($e);
            }
            // ⑤
            $tier=$h['tier']??null;$el=$h['eligibility']??null;
            $byTier=['C'=>'NO_EVIDENCE','UNVERIFIED'=>'UNVERIFIED'];
            // 候補外の tier（C・UNVERIFIED）なのに eligibility が ELIGIBLE 以外の別の値（NOT_ELIGIBLE など決まりにない値も）のとき。ELIGIBLE は直さない
            if (isset($byTier[$tier]) && is_string($el) && $el!=='ELIGIBLE' && $el!==$byTier[$tier]) {
                $h['eligibility']=$byTier[$tier]; $notes[]="{$no}番 eligibility {$el}→{$byTier[$tier]}";
            }
        }
        unset($h);
        // ⑨ race_metrics の source_refs に、決まり（その馬自身の単勝・複勝オッズ）以外の参照（断層の比など）が混ざっているとき → 決まりに合う参照だけ残す（値・理由はそのまま。足りなければこのあとの検査で外れる）
        if (is_array($res['race_metrics']??null)) {
            foreach (['wave_level','lower_entry','big_gap_entry'] as $mk) {
                $refs=$res['race_metrics'][$mk]['source_refs']??null;
                if (!is_array($refs)) continue;
                $keep=array_values(array_filter($refs,static fn($r)=>is_string($r) && preg_match('/^h[0-9]+_(?:win_odds|place_min|place_max)_(?:21|18|15|12|9|6)$/D',$r)===1));
                if (count($keep)!==count($refs)) { $res['race_metrics'][$mk]['source_refs']=$keep; $notes[]="指標 {$mk} の決まり外の参照を".(count($refs)-count($keep)).'件除去'; }
            }
        }
        // ⑦ public_candidates を {band,horse_no,rank} の形で書いたとき → 馬番の並びに（順番はそのまま）
        if (is_array($res['public_candidates']??null)) {
            $pc=[];$changed=false;
            foreach ($res['public_candidates'] as $c) {
                if (is_array($c) && is_int($c['horse_no']??null)) { $pc[]=$c['horse_no'];$changed=true; }
                else $pc[]=$c;
            }
            if ($changed) { $res['public_candidates']=$pc; $notes[]='public_candidates を馬番の並びに'; }
        }
        if ($notes===[]) return [$res,$map,$notes];
        // 型の地図を作り直します（直した値は、その形のまま書いて読み直す）
        $parsed=BaganrikiJson::decode(BaganrikiJson::encode($res,$map??BaganrikiJson::emptyMap()));
        if (!$parsed['ok']) return [$res,$map,[]];
        return [$parsed['value'],$parsed['map'],$notes];
    }
}

<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** An early inspection suggests corrections. Only the independent saved-input/original-response check can authorize publication. */
final class BaganrikiResponseInspection
{
    /** 返事の検査の版。よっしーの config の validator_version と同じ値（契約の値なので中身は変えない） */
    public const REVISION='w2-r15.21';

    /** Keep independently valid evidence on a mixed row. Never promote a local salvage to a full AI answer. */
    /**
     * @ANCHOR-20261109-PREDICTION-EVIDENCE-SALVAGE  根拠の一部だけが不正な馬を、正しい根拠だけで組み直して残します
     *   よっしーの契約6章「不正項目だけを除き、正常根拠は保持する」。これまでは支持加速の誤り（salvageMarket）だけが対象で、
     *   それ以外の根拠を1つでも誤ると、その馬の行がまるごと消え、正しい根拠を持つ馬まで候補から外れていました（11/9 非公開試験）。
     *   ① 根拠（evidence）を1件ずつ凍結資料で照合し、通ったものだけ残す（AIの書いた値・出典はそのまま。足さない）
     *   ② 5系統の判定は、残った根拠がある系統だけ SUPPORTED。根拠を失った系統は NO_SUPPORT（AIが付けた出典のうち、その馬の実在する出典だけ残す）
     *   ③ tier・eligibility は残った根拠の独立群の数から決め直す（2以上＝A、1＝B、0＝C・候補外）
     *   ④ 点数は、まずAIの点のまま確かめ、通らなければ全部 null（未算出）にして確かめる
     *   ⑤ 組み直した行を、AIの返事と同じ検査（validOriginalHorse・inspectRow）に通す。通らなければ今までどおり行を外す
     */
    /** 直前の salvageEvidence で組み直せなかった理由（馬番 => 理由）。試験と調査用 */
    public static array $salvageWhy=[];

    public static function salvageEvidence(array $in,array $horse,int $index,array $inputMap,array $responseMap):?array
    {
        try { return self::salvageEvidenceRun($in,$horse,$index,$inputMap,$responseMap); }
        catch (\Throwable $error) {
            if (is_int($horse['horse_no']??null)) self::$salvageWhy[$horse['horse_no']]='例外 '.$error->getMessage();
            return null;   // 組み直せないときは、今までどおりその行を外すだけ（公開の処理は止めない）
        }
    }

    /** 系統ごとの「見た資料」の既定（その馬自身の実在する出典だけ）。NO_SUPPORT の資料が足りないときに使う */
    private static function defaultFamilyRefs(array $in,int $no,string $family):array
    {
        $reg=$in['source_registry'];$has=static fn(string $id)=>isset($reg[$id]) && $reg[$id]['value']!==null;
        $out=[];
        if ($family==='market') { foreach (['win_odds_21','win_odds_12','win_odds_9','win_odds_6'] as $f) if ($has("h{$no}_{$f}")) $out[]="h{$no}_{$f}"; }
        elseif ($family==='win_place') { foreach (['win_odds_6','place_min_6','place_max_6'] as $f) if ($has("h{$no}_{$f}")) $out[]="h{$no}_{$f}"; }
        elseif ($family==='ability') { for ($k=1;$k<=5;$k++) if ($has("h{$no}_past_finish_{$k}")) $out[]="h{$no}_past_finish_{$k}"; }
        elseif ($family==='gap') {
            foreach ($reg as $id=>$e) if (($e['scope']??'')==='PAIR' && ($e['slot_offset']??null)===6 && in_array($no,(array)$e['horse_nos'],true) && $e['value']!==null) $out[]=(string)$id;
        }
        return $out;
    }

    private static function salvageEvidenceRun(array $in,array $horse,int $index,array $inputMap,array $responseMap):?array
    {
        if (!is_array($horse['evidence']??null) || !is_int($horse['horse_no']??null)) return null;
        $no=$horse['horse_no'];$cache=[];$kept=[];$removed=[];$ids=[];
        $value=static fn(string $ref):array=>BaganrikiOutputSchema::scalar($in['source_registry'][$ref]['value'],$inputMap,['source_registry',$ref,'value']);
        foreach ($horse['evidence'] as $j=>$item) {
            $id=is_array($item)?($item['evidence_id']??null):null;
            if (!is_string($id) || isset($ids[$id])) { $removed[]=(string)$id; continue; }
            $ids[$id]=true;
            try {
                BaganrikiSourceEvidence::checkedEvidence($in,$in['source_registry'],$value,$item,$no,$responseMap,['horses',$index,'evidence',$j],$cache);
                $kept[$j]=$item;
            } catch (\Throwable $error) { $removed[]=$id; }
        }
        $roster=array_column($in['runners'],'band','horse_no');
        $base=$horse;$base['band']=$roster[$no]??($horse['band']??null);
        $base['evidence']=array_values($kept);
        $groups=$kept?BaganrikiSourceEvidence::acceptedGroupCount($in['source_registry'],$base['evidence'],$cache):0;
        $byFamily=[];foreach ($base['evidence'] as $e) $byFamily[$e['family']][]=$e;
        $fams=['market','ability','gap','win_place','similar_value'];
        $fr=is_array($horse['family_review']??null)?$horse['family_review']:[];
        $newFr=[];
        foreach ($fams as $f) {
            $old=is_array($fr[$f]??null)?$fr[$f]:[];
            if (isset($byFamily[$f])) {
                $refs=[];foreach ($byFamily[$f] as $e) foreach ($e['source_refs'] as $r) $refs[$r]=true;
                $newFr[$f]=['status'=>'SUPPORTED','source_refs'=>array_keys($refs),'evidence_ids'=>array_column($byFamily[$f],'evidence_id'),
                    'reason'=>is_string($old['reason']??null)&&($old['status']??null)==='SUPPORTED'?$old['reason']:'検証できた根拠のみ保持'];
            } else {
                $st=in_array($old['status']??null,['NO_SUPPORT','DATA_MISSING'],true)?$old['status']:'NO_SUPPORT';
                $newFr[$f]=['status'=>$st,'source_refs'=>$st==='DATA_MISSING'?[]:BaganrikiSourceEvidence::retainedRefs($in['source_registry'],$no,$old['source_refs']??[]),
                    'evidence_ids'=>[],'reason'=>($st===($old['status']??null) && is_string($old['reason']??null))?$old['reason']:'根拠を凍結資料で検証できないため不採用'];
            }
        }
        // 5系統の判定から、tier・eligibility・順位の扱いを決め直します（よっしーの検査と同じ決まり）
        $finish=static function(array $row,array $frs) use ($groups):array {
            $row['family_review']=$frs;
            $row['evidence_rank_evidence_ids']=array_column($row['evidence'],'evidence_id');
            if ($groups>=1) {
                $row['tier']=$groups>=2?'A':'B';$row['eligibility']='ELIGIBLE';
                if (($row['rank_basis']??null)!=='SCORE' || ($row['score_status']??null)!=='complete') $row['rank_basis']='EVIDENCE';
                $ns=$row['nonselect_reason']??null;
                if (is_array($ns) && in_array($ns['code']??null,['NO_EVIDENCE','UNVERIFIED'],true)) $row['nonselect_reason']=null;
                if (!is_string($row['evidence_rank_reason']??null) || $row['evidence_rank_reason']==='') $row['evidence_rank_reason']='検証できた根拠で順位付け';
            } else {
                $missing=in_array('DATA_MISSING',array_column($frs,'status'),true);
                $row['eligibility']=$missing?'UNVERIFIED':'NO_EVIDENCE';$row['tier']=$missing?'UNVERIFIED':'C';
                $row['rank_basis']='NOT_RANKED';$row['evidence_rank_evidence_ids']=[];
                $row['nonselect_reason']=['code'=>$row['eligibility'],'reason'=>$missing?'資料が欠けている系統があり検証できない':'検証できた根拠がない'];
                $row['public_candidate']=false;
            }
            return $row;
        };
        if ($removed) $base['rank_reason']='検証できない根拠を除き、残った根拠で評価を組み直す';
        if (!is_string($base['explanation']??null) || $base['explanation']==='') $base['explanation']='検証できた根拠のみで評価';
        $rowMapBase=BaganrikiJson::mapSub($responseMap,['horses',$index]);
        $nullScores=static function(array $r) use ($in,$no):array {
            foreach (BaganrikiCandidateSelection::scoreCaps() as $k=>$mx) {
                $r['scores'][$k]=null;
                $old=is_array($r['score_evidence'][$k]??null)?$r['score_evidence'][$k]:[];
                $r['score_evidence'][$k]=['source_refs'=>BaganrikiSourceEvidence::retainedRefs($in['source_registry'],$no,$old['source_refs']??[]),
                    'reason'=>$k.'は出典または採点内容を検証できず未算出'];
            }
            $r['score_status']='partial';$r['total_score']=null;$r['observed_sum']=0;$r['observed_cap']=0;
            return $r;
        };
        $variants=[$base,$nullScores($base)];
        if (($base['counterevidence']??[])!==[]) { $c=$base;$c['counterevidence']=[];$variants[]=$nullScores($c); }
        $why=[];
        foreach ($variants as $vi=>$variant) {
            $frs=$newFr;
            // 資料不足（NO_SUPPORT の資料が足りない・資料があるのに DATA_MISSING）と言われた系統は、その馬自身の実在する資料に差し替えて、もう一度確かめます（最大4回）
            // for ($round=0;$round<4;$round++) {   // 旧
            for ($round=0;$round<6;$round++) {
                $t=$finish($variant,$frs);
                $rowMap=$rowMapBase;$next=0;
                foreach ($kept as $old=>$item) $rowMap=BaganrikiJson::mapMerge($rowMap,BaganrikiJson::mapMove($responseMap,['horses',$index,'evidence',$old],['evidence',$next++]));
                $parsed=BaganrikiJson::decode(BaganrikiJson::encode($t,$rowMap));
                if (!$parsed['ok']) { $why[]=$vi.':JSON'; break; }
                if (!BaganrikiOutputSchema::validOriginalHorse($parsed['value'],$parsed['map'],[])) {
                    $path='';
                    try { $path=json_encode(array_slice(BaganrikiOutputSchema::outputIssues(['horses'=>[$parsed['value']]],BaganrikiJson::mapMove($parsed['map'],[],['horses',0])),0,2),JSON_UNESCAPED_UNICODE); }
                    catch (\Throwable $ignored) { $path=''; }
                    $why[]=$vi.':SCHEMA'.$path; break;
                }
                $c2=[];
                $iss=BaganrikiSourceEvidence::inspectIssues($in,$parsed['value'],0,$inputMap,BaganrikiJson::mapMove($parsed['map'],[],['horses',0]),$c2,[]);
                if (!$iss) {
                    unset(self::$salvageWhy[$no]);
                    return ['row'=>$parsed['value'],'map'=>$parsed['map'],'removed_evidence_ids'=>$removed];
                }
                $changed=false;
                // @ANCHOR-20261110-PREDICTION-SALVAGE-NOSUPPORT  「NO_SUPPORT なのに、見た資料に値のある参照が1つも無い」（EXPECTED_FAMILY_NO_SUPPORT）と言われた系統も、
                //   その馬自身の実在する資料に差し替えます。資料が無ければ DATA_MISSING にします（11/10 非公開試験で、2nd・1st の全頭がこれで組み直せなかった）
                if (in_array('EXPECTED_FAMILY_NO_SUPPORT',$iss,true)) {
                    $reg=$in['source_registry'];
                    foreach ($fams as $f) {
                        if (($frs[$f]['status']??null)!=='NO_SUPPORT') continue;
                        $live=false;
                        foreach ((array)($frs[$f]['source_refs']??[]) as $r) if (isset($reg[$r]) && ($reg[$r]['value']??null)!==null) $live=true;
                        if ($live) continue;
                        $def=self::defaultFamilyRefs($in,$no,$f);
                        $want=$def?['status'=>'NO_SUPPORT','source_refs'=>$def,'evidence_ids'=>[],'reason'=>'資料はあるが支持は確認できない']
                                  :['status'=>'DATA_MISSING','source_refs'=>[],'evidence_ids'=>[],'reason'=>'この系統の資料がない'];
                        if ($frs[$f]!==$want) { $frs[$f]=$want;$changed=true; }
                    }
                }
                foreach ($iss as $code) {
                    foreach ($fams as $f) {
                        if (($code==='EXPECTED_NO_SUPPORT_MATERIAL_'.$f || $code==='EXPECTED_FALSE_MISSING_'.$f) && ($frs[$f]['status']??null)!=='SUPPORTED') {
                            $def=self::defaultFamilyRefs($in,$no,$f);
                            $want=$def?['status'=>'NO_SUPPORT','source_refs'=>$def,'evidence_ids'=>[],'reason'=>'資料はあるが支持は確認できない']
                                      :['status'=>'DATA_MISSING','source_refs'=>[],'evidence_ids'=>[],'reason'=>'この系統の資料がない'];
                            if ($frs[$f]!==$want) { $frs[$f]=$want;$changed=true; }
                        }
                    }
                }
                if (!$changed) { $why[]=$vi.':'.implode(',',$iss); break; }
            }
        }
        self::$salvageWhy[$no]='残った根拠'.count($kept).'件／除いた根拠'.count($removed).'件／'.implode(' | ',$why);
        return null;
    }

    public static function salvageMarket(array $in,array $horse,int $index,array $inputMap,array $responseMap):?array
    {
        $cache=[];$kept=[];$removed=[];$ids=[];$no=$horse['horse_no'];
        $value=static fn(string $ref):array=>BaganrikiOutputSchema::scalar($in['source_registry'][$ref]['value'],$inputMap,['source_registry',$ref,'value']);
        foreach ($horse['evidence'] as $j=>$item) {
            if (!is_array($item) || !is_string($item['evidence_id']??null) || isset($ids[$item['evidence_id']])) return null;
            $ids[$item['evidence_id']]=true;
            try {
                BaganrikiSourceEvidence::checkedEvidence($in,$in['source_registry'],$value,$item,$no,$responseMap,['horses',$index,'evidence',$j],$cache);
                $kept[$j]=$item;
            } catch (\InvalidArgumentException $error) {
                if ($item['family']!=='market' || $error->getMessage()!=='EXPECTED_FALSE_ACCELERATION') return null;
                $removed[]=$item['evidence_id'];
            }
        }
        if (!$removed || !$kept) return null;
        // A non-null market score needs the AI's scoped correction; do not infer its basis.
        if (($horse['scores']['H2']??null)!==null) return null;
        $row=$horse;$row['evidence']=array_values($kept);
        $market=array_values(array_filter($row['evidence'],static fn($e)=>$e['family']==='market'));
        $refs=[];foreach ($market as $e) foreach ($e['source_refs'] as $ref) $refs[$ref]=true;
        $row['family_review']['market']=$market?
            ['status'=>'SUPPORTED','source_refs'=>array_keys($refs),'evidence_ids'=>array_column($market,'evidence_id'),'reason'=>'検証できた市場根拠のみ保持']:
            ['status'=>'NO_SUPPORT','source_refs'=>$horse['family_review']['market']['source_refs'],'evidence_ids'=>[],'reason'=>'引用値に支持加速を確認できないため、その根拠を除去'];
        $groups=BaganrikiSourceEvidence::acceptedGroupCount($in['source_registry'],$row['evidence'],$cache);
        $row['tier']=$groups>=2?'A':'B';$row['eligibility']='ELIGIBLE';
        $row['evidence_rank_evidence_ids']=array_column($row['evidence'],'evidence_id');
        $row['evidence_rank_reason']=$row['rank_reason']=$row['explanation']='不正な支持加速の根拠を除去し、別の検証済み根拠を保持。順位は再計算する';
        $rowMap=BaganrikiJson::mapSub($responseMap,['horses',$index]);$next=0;
        foreach ($kept as $old=>$item) $rowMap=BaganrikiJson::mapMerge($rowMap,
            BaganrikiJson::mapMove($responseMap,['horses',$index,'evidence',$old],['evidence',$next++]));
        // Re-parse exact surviving numeric tokens, then validate the whole rebuilt row.
        $parsed=BaganrikiJson::decode(BaganrikiJson::encode($row,$rowMap));
        if (!$parsed['ok'] || !BaganrikiOutputSchema::validOriginalHorse($parsed['value'],$parsed['map'],[])) return null;
        try { BaganrikiSourceEvidence::inspectRow($in,$parsed['value'],0,$inputMap,
            BaganrikiJson::mapMove($parsed['map'],[],['horses',0]),$cache); }
        catch (\InvalidArgumentException $error) { return null; }
        return ['row'=>$parsed['value'],'map'=>$parsed['map'],'removed_evidence_ids'=>$removed];
    }

    public static function raw(array $in,array $res,array $inputMap,array $map,callable $add,callable $markScore):array
    {
        $rows=$res['horses']??null;$roster=array_column($in['runners'],'band','horse_no');
        $shape=[];$invalid=[];$unsafe=[];$cache=[];
        foreach (BaganrikiRaceMetrics::issues($in,$res,$map) as $key=>$code) $add('top_fields','race_metrics.'.$key,$code,'表示指標を凍結資料と照合してください。正常な指標と馬の評価は保持します');
        foreach (BaganrikiOutputSchema::outputIssues($res,$map) as $path) {
            // @ANCHOR-20261124-TEXT07-SERVER-METRICS  サーバ計算の版の 1st：race_metrics は読まずに捨てるので、形が違っても指摘にしない
            if (($path[0]??null)==='race_metrics' && BaganrikiRaceMetrics::serverComputed($in) && ($in['ai_role']??null)==='1st') continue;
            $why='JSONの項目・型を照合してください '.BaganrikiJson::pathKey($path);
            $key=$path[0]??'response';
            if ($key!=='horses') {
                if (in_array($key,['band_ranks','evidence_band_ranks'],true)) {
                    $bands=isset($path[1])?(in_array($path[1],BaganrikiCandidateSelection::BANDS,true)?[$path[1]]:[]):BaganrikiCandidateSelection::BANDS;
                    foreach ($bands as $b) $add('rank_bands',$b,'EXPECTED_RANK_SHAPE',$why);
                    if (!$bands) $add('top_fields',$key,'EXPECTED_SCHEMA',$why);
                } else $add('top_fields',$key,'EXPECTED_SCHEMA',$why);
                continue;
            }
            $i=$path[1]??null;
            if ($i===null) { $add('top_fields','horses','EXPECTED_SCHEMA',$why);continue; }
            $shape[$i][$path[2]??'horse_no']=true;$no=$rows[$i]['horse_no']??null;
            if (!is_int($no) || !isset($roster[$no])) { $add('top_fields','horses','EXPECTED_SCHEMA',$why);continue; }
            $field=$path[2]??'horse_no';$code='EXPECTED_SCHEMA';
            if (!BaganrikiOutputSchema::knownHorseField((string)$field)) { $scope='summary_horses';$code='EXPECTED_EXTRA_ROW_FIELD'; }
            elseif (in_array($field,BaganrikiResponse::FIX_FIELDS_PRESENTATION,true)) $scope='presentation_horses';
            elseif (in_array($field,BaganrikiResponse::FIX_FIELDS_SUMMARY,true)) $scope='summary_horses';
            else $scope='horses';
            if ($field==='score_evidence' && isset($path[3],$path[4]) && !in_array($path[4],['reason','source_refs'],true)) $code='EXPECTED_SCORE_PROOF_FIELDS';
            elseif (in_array($field,['scores','score_evidence'],true) && isset($path[3]) && array_key_exists($path[3],BaganrikiCandidateSelection::scoreCaps())) $markScore($no,[$path[3]]);
            $add($scope,$no,$code,$why);
            if ($scope!=='presentation_horses' && $code!=='EXPECTED_EXTRA_ROW_FIELD') $invalid[$no]=true;
            // Earlier diagnostics assume correctly typed nested arrays. A malformed row must not abort inspection of the other horses.
            if (in_array($field,['scores','score_evidence','family_review','evidence','counterevidence'],true) && $code!=='EXPECTED_SCORE_PROOF_FIELDS') $unsafe[$no]=true;
        }
        if (!is_array($rows) || BaganrikiOutputSchema::scalar($rows,$map,['horses'])[0]!=='array') return [$invalid,$unsafe];
        foreach ($rows as $i=>$row) {
            if (!is_array($row) || !is_int($i) || !is_int($no=$row['horse_no']??null) || !isset($roster[$no])) continue;
            foreach (BaganrikiSourceEvidence::inspectIssues($in,$row,$i,$inputMap,$map,$cache,$shape[$i]??[]) as $code) {
                // The controller already identifies the affected operand and can salvage the valid component scores.
                if ($code==='EXPECTED_SCORE_TOTAL' || str_starts_with($code,'EXPECTED_SCORE_MATERIAL_') ||
                    str_starts_with($code,'EXPECTED_NO_SUPPORT_MATERIAL_')) continue;
                if (in_array($code,['EXPECTED_RANK_EVIDENCE_ID','EXPECTED_ORIGINAL_RANK_REASON'],true)) $scope='presentation_horses';
                elseif (in_array($code,['EXPECTED_SCORE_TOTAL','EXPECTED_TIER_FAMILIES','EXPECTED_FAMILY_REASON','EXPECTED_FAMILY_REFS','EXPECTED_FAMILY_IDS','EXPECTED_FAMILY_NO_SUPPORT','EXPECTED_SCORE_MISSING_REASON'],true)) $scope='summary_horses';
                else $scope='horses';
                $add($scope,$no,$code,'凍結資料とこの馬の回答を再照合してください '.$code);
                if ($scope!=='presentation_horses') $invalid[$no]=true;
            }
        }
        return [$invalid,$unsafe];
    }
}

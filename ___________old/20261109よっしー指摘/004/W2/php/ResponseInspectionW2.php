<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiResponse;

/** An early inspection suggests corrections. Only the independent saved-input/original-response check can authorize publication. */
final class ResponseInspectionW2
{
    public const REVISION='w2-r15.19';

    /** Keep independently valid evidence on a mixed row. Never promote a local salvage to a full AI answer. */
    public static function salvageMarket(array $in,array $horse,int $index,array $inputMap,array $responseMap):?array
    {
        $cache=[];$kept=[];$removed=[];$ids=[];$no=$horse['horse_no'];
        $value=static fn(string $ref):array=>SchemaW2::scalar($in['source_registry'][$ref]['value'],$inputMap,['source_registry',$ref,'value']);
        foreach ($horse['evidence'] as $j=>$item) {
            if (!is_array($item) || !is_string($item['evidence_id']??null) || isset($ids[$item['evidence_id']])) return null;
            $ids[$item['evidence_id']]=true;
            try {
                SourceEvidenceW2::checkedEvidence($in,$in['source_registry'],$value,$item,$no,$responseMap,['horses',$index,'evidence',$j],$cache);
                $kept[$j]=$item;
            } catch (\InvalidArgumentException $error) {
                if ($item['family']!=='market' || $error->getMessage()!=='W19_FALSE_ACCELERATION') return null;
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
        $groups=SourceEvidenceW2::acceptedGroupCount($in['source_registry'],$row['evidence'],$cache);
        $row['tier']=$groups>=2?'A':'B';$row['eligibility']='ELIGIBLE';
        $row['evidence_rank_evidence_ids']=array_column($row['evidence'],'evidence_id');
        $row['evidence_rank_reason']=$row['rank_reason']=$row['explanation']='不正な支持加速の根拠を除去し、別の検証済み根拠を保持。順位は再計算する';
        $rowMap=BaganrikiJson::mapSub($responseMap,['horses',$index]);$next=0;
        foreach ($kept as $old=>$item) $rowMap=BaganrikiJson::mapMerge($rowMap,
            BaganrikiJson::mapMove($responseMap,['horses',$index,'evidence',$old],['evidence',$next++]));
        // Re-parse exact surviving numeric tokens, then validate the whole rebuilt row.
        $parsed=BaganrikiJson::decode(BaganrikiJson::encode($row,$rowMap));
        if (!$parsed['ok'] || !SchemaW2::validOriginalHorse($parsed['value'],$parsed['map'],[])) return null;
        try { SourceEvidenceW2::inspectRow($in,$parsed['value'],0,$inputMap,
            BaganrikiJson::mapMove($parsed['map'],[],['horses',0]),$cache); }
        catch (\InvalidArgumentException $error) { return null; }
        return ['row'=>$parsed['value'],'map'=>$parsed['map'],'removed_evidence_ids'=>$removed];
    }

    public static function raw(array $in,array $res,array $inputMap,array $map,callable $add,callable $markScore):array
    {
        $rows=$res['horses']??null;$roster=array_column($in['runners'],'band','horse_no');
        $shape=[];$invalid=[];$unsafe=[];$cache=[];
        foreach (SchemaW2::outputIssues($res,$map) as $path) {
            $why='JSONの項目・型を照合してください '.BaganrikiJson::pathKey($path);
            $key=$path[0]??'response';
            if ($key!=='horses') {
                if (in_array($key,['band_ranks','evidence_band_ranks'],true)) {
                    $bands=isset($path[1])?(in_array($path[1],CandidateSelectionW2::BANDS,true)?[$path[1]]:[]):CandidateSelectionW2::BANDS;
                    foreach ($bands as $b) $add('rank_bands',$b,'W15_RANK_SHAPE',$why);
                    if (!$bands) $add('top_fields',$key,'W15_SCHEMA',$why);
                } else $add('top_fields',$key,'W15_SCHEMA',$why);
                continue;
            }
            $i=$path[1]??null;
            if ($i===null) { $add('top_fields','horses','W15_SCHEMA',$why);continue; }
            $shape[$i][$path[2]??'horse_no']=true;$no=$rows[$i]['horse_no']??null;
            if (!is_int($no) || !isset($roster[$no])) { $add('top_fields','horses','W15_SCHEMA',$why);continue; }
            $field=$path[2]??'horse_no';$code='W15_SCHEMA';
            if (!SchemaW2::knownHorseField((string)$field)) { $scope='summary_horses';$code='W15_EXTRA_ROW_FIELD'; }
            elseif (in_array($field,BaganrikiResponse::FIX_FIELDS_PRESENTATION,true)) $scope='presentation_horses';
            elseif (in_array($field,BaganrikiResponse::FIX_FIELDS_SUMMARY,true)) $scope='summary_horses';
            else $scope='horses';
            if ($field==='score_evidence' && isset($path[3],$path[4]) && !in_array($path[4],['reason','source_refs'],true)) $code='W13_SCORE_PROOF_FIELDS';
            elseif (in_array($field,['scores','score_evidence'],true) && isset($path[3]) && array_key_exists($path[3],CandidateSelectionW2::scoreCaps())) $markScore($no,[$path[3]]);
            $add($scope,$no,$code,$why);
            if ($scope!=='presentation_horses' && $code!=='W15_EXTRA_ROW_FIELD') $invalid[$no]=true;
            // Earlier diagnostics assume correctly typed nested arrays. A malformed row must not abort inspection of the other horses.
            if (in_array($field,['scores','score_evidence','family_review','evidence','counterevidence'],true) && $code!=='W13_SCORE_PROOF_FIELDS') $unsafe[$no]=true;
        }
        if (!is_array($rows) || SchemaW2::scalar($rows,$map,['horses'])[0]!=='array') return [$invalid,$unsafe];
        foreach ($rows as $i=>$row) {
            if (!is_array($row) || !is_int($i) || !is_int($no=$row['horse_no']??null) || !isset($roster[$no])) continue;
            foreach (SourceEvidenceW2::inspectIssues($in,$row,$i,$inputMap,$map,$cache,$shape[$i]??[]) as $code) {
                // The controller already identifies the affected operand and can salvage the valid component scores.
                if ($code==='W2_SCORE_TOTAL' || str_starts_with($code,'W12_SCORE_MATERIAL_') ||
                    str_starts_with($code,'W14_NO_SUPPORT_MATERIAL_')) continue;
                if (in_array($code,['W3_RANK_EVIDENCE_ID','W4_ORIGINAL_RANK_REASON'],true)) $scope='presentation_horses';
                elseif (in_array($code,['W2_SCORE_TOTAL','W3_TIER_FAMILIES','W4_FAMILY_REASON','W3_FAMILY_REFS','W3_FAMILY_IDS','W3_FAMILY_NO_SUPPORT','W12_SCORE_MISSING_REASON'],true)) $scope='summary_horses';
                else $scope='horses';
                $add($scope,$no,$code,'凍結資料とこの馬の回答を再照合してください '.$code);
                if ($scope!=='presentation_horses') $invalid[$no]=true;
            }
        }
        return [$invalid,$unsafe];
    }
}

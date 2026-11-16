<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiResponse;

/** An early inspection suggests corrections. Only the independent saved-input/original-response check can authorize publication. */
final class ResponseInspectionW2
{
    public const REVISION='w2-r15.5';

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

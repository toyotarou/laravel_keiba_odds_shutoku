<?php
declare(strict_types=1);

use App\Http\Controllers\Api\BaganrikiPrompt as R14Wire;
use App\Services\Baganriki\FrozenInputW2 as R14Frozen;
use App\Services\Baganriki\CandidateSelectionW2 as R14Sel;

function r14Observed(array &$in,string $id,string $field,mixed $value,string $unit):void {
    $seed=$in['source_registry']['h1_past_performance_None'];
    $in['source_registry'][$id]=array_replace($seed,['source_ref'=>$id,'field'=>$field,'value'=>$value,'unit'=>$unit,'dependency_key'=>$id]);
    $in['field_catalog'][$field]=array_replace($in['field_catalog']['past_performance'],['unit'=>$unit,'minimum'=>null,'maximum'=>null,'display_label'=>$field]);
}
function r14Fixture(string $dir,string $role):array {
    $base=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
    $in=r12ScoreInput($base);$proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);
    r14Observed($in,'r14_average','past_avg_finish',0,'rank');
    r14Observed($in,'r14_rate','past_top3_rate',120,'percent');
    $error=$in['source_registry']['h1_win_final_odds_error'];$old=$in['source_registry']['h1_win_odds_21'];
    $in['source_registry']['r14_early_error']=array_replace($error,['source_ref'=>'r14_early_error','slot_offset'=>21,'available_at'=>$old['available_at'],'observed_at'=>$old['observed_at']]);
    $in['input_indices']=R14Wire::indicesFor($in['source_registry']);
    $freeze=r3stored($in,$proof);$tree=R14Frozen::checked($freeze);
    $out=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);$out['snapshot_hash']=$tree['snapshot_hash'];
    return [$freeze,$tree,$out,$proof];
}
function r14SetScore(array $out,string $key,array $refs,int $points):array {
    $h=&$out['horses'][0];$h['scores'][$key]=$points;$h['score_evidence'][$key]=['reason'=>'引用した資料から評価','source_refs'=>$refs];
    $h['observed_sum']=$points;$h['observed_cap']=R14Sel::scoreCaps()[$key];return $out;
}
function r14SetNoSupport(array $out,string $family,array $refs):array {
    $out['horses'][0]['family_review'][$family]=['status'=>'NO_SUPPORT','source_refs'=>$refs,'evidence_ids'=>[],'reason'=>'対応資料を確認したが好走の根拠を得られず'];
    return $out;
}
function r14Verify($f,array $out):array { $raw=r3frame($out);return R14Sel::verifyOriginal($f,$raw,hash('sha256',$raw)); }

/** Non-eligible horse stays in the roster, but leaves both ranking lists. */
function r14Exclude(array $in,array $out,string $status,array $refsByFamily=[]):array {
    $h=&$out['horses'][0];$h['evidence']=$h['evidence_rank_evidence_ids']=[];
    $h['tier']=$status==='NO_SUPPORT'?'C':'UNVERIFIED';$h['eligibility']=$status==='NO_SUPPORT'?'NO_EVIDENCE':'UNVERIFIED';$h['rank_basis']='NOT_RANKED';
    foreach ($h['family_review'] as $name=>&$review) $review=['status'=>$status,'evidence_ids'=>[],'source_refs'=>$refsByFamily[$name]??[],
        'reason'=>$status==='NO_SUPPORT'?'系統ごとの該当資料を評価':'対象の比較資料が不足'];
    unset($review,$h);
    foreach (['band_ranks','evidence_band_ranks'] as $name) $out[$name]['A']=array_values(array_diff($out[$name]['A'],[1]));
    return r5project($in,$out);
}

function r14ExactFreeze(array $base,array $proof,string $token):R14Frozen {
    $base['source_registry']['r14_average']['value']=987654321;
    $render=static function(string $s)use($token):string {
        if (substr_count($s,'987654321')!==1) throw new RuntimeException('exact-value fixture marker');
        return str_replace('987654321',$token,$s);
    };
    $common=$render(R14Wire::commonHash($base)['json']);$base['snapshot_hash']=hash('sha256',$common);
    return R14Frozen::fromStored($common,$render(r3bytes($base)),$base['snapshot_hash'],$base['ai_role'],$proof);
}

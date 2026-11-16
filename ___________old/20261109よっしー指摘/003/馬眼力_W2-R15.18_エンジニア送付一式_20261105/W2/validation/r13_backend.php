<?php
declare(strict_types=1);

use App\Http\Controllers\Api\BaganrikiPrompt as R13Wire;
use App\Services\Baganriki\CandidateSelectionW2 as R13Selection;
use App\Services\Baganriki\FrozenInputW2 as R13Frozen;
use App\Services\Baganriki\SourceEvidenceW2 as R13Evidence;

(function(string $dir):void {
    $count=0;
    $check=static function(bool $ok,string $name)use(&$count):void { expect($ok,'R13 '.$name);++$count; };
    $deny=static function(callable $run,string $prefix)use($check):void {
        try { $run(); } catch (InvalidArgumentException $e) {
            $check(str_starts_with($e->getMessage(),$prefix),$prefix.' / '.$e->getMessage());return;
        }
        throw new RuntimeException('R13 unexpectedly accepted '.$prefix);
    };
    $verify=static function($f,array $out):array {
        $raw=r3frame($out);return R13Selection::verifyOriginal($f,$raw,hash('sha256',$raw));
    };
    $addObservation=static function(array &$in,string $id,string $field,mixed $value,string $unit):void {
        $seed=$in['source_registry']['h1_past_performance_None'];
        $in['source_registry'][$id]=array_replace($seed,['source_ref'=>$id,'field'=>$field,'value'=>$value,'unit'=>$unit,'dependency_key'=>$id]);
        $in['field_catalog'][$field]=array_replace($in['field_catalog']['past_performance'],['unit'=>$unit,'missing_allowed'=>true,'display_label'=>$field]);
        $in['input_indices'][R13Wire::indexKey($in['source_registry'][$id])][]=$id;
    };
    $withEvidence=static function(array $out,array $in,array $families,string $tier):array {
        $h=&$out['horses'][0];$h['evidence']=$h['evidence_rank_evidence_ids']=[];
        foreach ($h['family_review'] as &$review) $review=['status'=>'DATA_MISSING','source_refs'=>[],'evidence_ids'=>[],'reason'=>'該当なし'];unset($review);
        foreach ($families as $family=>$refs) {
            $id='r13_'.$family;$facts=[];
            foreach ($refs as $ref) $facts[]=['source_ref'=>$ref,'value'=>$in['source_registry'][$ref]['value']];
            $h['evidence'][]=['evidence_id'=>$id,'family'=>$family,'dependency_group'=>$id,'source_refs'=>$refs,'facts'=>$facts,
                'observation'=>'固定された観測','interpretation'=>'引用した資料を比較'];
            $h['evidence_rank_evidence_ids'][]=$id;
            $h['family_review'][$family]=['status'=>'SUPPORTED','source_refs'=>$refs,'evidence_ids'=>[$id],'reason'=>'対応資料を確認'];
        }
        $h['tier']=$tier;unset($h);return r5project($in,$out);
    };
    $family=static function(array $in,string $name,array $refs,int $no=1):bool {
        $cache=[];return R13Evidence::familyKnown($in,$no,$name,$refs,$cache);
    };
    $score=static function(array $in,string $key,array $refs):bool {
        $cache=[];return R13Evidence::scoreKnown($in,1,$key,1,['reason'=>'資料','source_refs'=>$refs],$cache);
    };
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true);
    foreach (['1st','2nd'] as $role) {
        $in=r12ScoreInput(json_decode(file_get_contents("$dir/{$role}_input.json"),true));
        $out=json_decode(file_get_contents("$dir/{$role}_output.json"),true);
        $addObservation($in,'r13_null','r13_unobserved',null,'index');
        $addObservation($in,'r13_finish','past_finish_1',0,'rank');
        $s=r3stored($in,$proof);$in=R13Frozen::checked($s);$out['snapshot_hash']=$in['snapshot_hash'];
        $nos=array_column($in['runners'],'horse_no');$past='h1_past_performance_None';$win='h1_win_odds_6';$early='h1_win_odds_21';$lo='h1_place_min_6';
        $genuine=['ability'=>[$past],'market'=>[$early,$win]];
        $fake=['ability'=>[$win],'gap'=>[$lo]];
        foreach ([$fake,['gap'=>[$lo]]] as $bad) {
            $test=$withEvidence($out,$in,$bad,count($bad)===2?'A':'B');
            $deny(fn()=>$verify($s,$test),'W13_EVIDENCE_FAMILY_'.array_key_first($bad));
            $deny(fn()=>R13Selection::verifyReply($s,$test),'W13_EVIDENCE_FAMILY');
        }
        $native=$out;$native['horses'][0]['evidence']='malformed';
        $deny(fn()=>R13Selection::verifyReply($s,$native),'W13_EVIDENCE_ITEMS');
        $bad=$withEvidence($out,$in,$fake,'A');$receipt=r4receipt($s,r3frame($bad),0,false);
        $merge=static fn($rec)=>r9merge($s,[$role=>$rec],$in['quota'],$nos,[],r4live($s));
        $deny(fn()=>$merge(r4recoveryRecord($s,$bad,$receipt)),'W2_NO_VERIFIED_ROLE');
        foreach ([false,true] as $withNull) {
            $fs=$genuine;if ($withNull) { $fs['ability'][]='r13_null';$fs['market'][]='r13_null'; }
            $test=$withEvidence($out,$in,$fs,'A');
            $check($verify($s,$test)['independent_groups'][1]===2,'confirmed ability and series remain independent, missing='.intval($withNull));
            $check(R13Selection::verifyReply($s,$test)['public_candidates']===$test['public_candidates'],'normal candidate ordering');
        }
        $test['horses'][0]['evidence'][0]['facts'][1]['value']=7;
        $deny(fn()=>$verify($s,$test),'W3_FACT_VALUE');
        $overlap=$genuine;$overlap['ability'][]=$win;
        $deny(fn()=>$verify($s,$withEvidence($out,$in,$overlap,'A')),'W3_TIER_FAMILIES');
        $check($verify($s,$withEvidence($out,$in,$overlap,'B'))['independent_groups'][1]===1,'real shared source still counts once');
        $refs=r12Refs(1);
        foreach ([['ability',[$past]],['market',[$early,$win]],['market',[$lo,'h1_place_min_21']],['gap',$refs['H3']],['win_place',[$win,$lo]]] as [$name,$ids])
            $check($family($in,$name,$ids),'supported '.$name);
        foreach ([['ability',[$win]],['ability',['h2_past_performance_None']],['ability',['absent']],['ability',[]],['ability',['r13_null']],
            ['market',[$early,$lo]],['gap',[$lo]],['win_place',[$early,$lo]],['similar_value',[...$refs['H4'],$win]],
            ['similar_value',[...$refs['V3'],$win]],['similar_value',['h1_opi',$past]],['unknown',[$past]]] as [$name,$ids])
            $check(!$family($in,$name,$ids),'wrong material for '.$name.' '.implode(',',$ids));
        $ctx=$in;$ctx['source_registry']['r12_gap_1']['dependency_role']='CONTEXT';
        $check(!$family($ctx,'gap',['r12_gap_1',$early]),'unrelated owned signal cannot certify a gap');
        $check($family($ctx,'gap',['r12_gap_1',$win]),'actual own parent can certify the gap');
        $ctx=$in;$ctx['source_registry']['h1_similar_top3_rate']['dependency_role']='SIGNAL';
        $check($family($ctx,'similar_value',$refs['H4']),'matched rate and positive count');
        $ctx['source_registry']['h1_similar_sample_count']['dependency_key']='different-sample';
        $check(!$family($ctx,'similar_value',$refs['H4']),'rate and count from different samples');
        $ctx=$in;$ctx['source_registry']['h1_opi']['dependency_role']='SIGNAL';
        $check($family($ctx,'similar_value',['h1_opi']),'applicable observed value ratio');
        $check(!$score($in,'H1',['r13_finish']) && !$score($in,'V2',['r13_finish',$win]),'zero finish is not ability');
        $check(!$family($in,'ability',['r13_finish']),'zero finish is not an ability signal');
        $check($score($in,'H1',['r13_finish',$past]),'unrelated corrupt finish does not erase sound prior performance');
        foreach ([[1,'rank',true],[7,'rank',true],[1.0,'rank',true],[-1,'rank',false],[1.5,'rank',false],[2,'odds',false]] as [$v,$unit,$ok]) {
            $ctx=$in;$ctx['source_registry']['r13_finish']['value']=$v;$ctx['source_registry']['r13_finish']['unit']=$unit;
            $check($family($ctx,'ability',['r13_finish'])===$ok && $score($ctx,'H1',['r13_finish'])===$ok,'valid ordinal '.$v.'/'.$unit);
        }
        $clean=$out;$h=&$clean['horses'][0];$h['scores']['H1']=18;$h['observed_sum']=$h['observed_cap']=18;
        $h['score_evidence']['H1']=['reason'=>'過去着順','source_refs'=>['r13_finish']];unset($h);
        $deny(fn()=>$verify($s,$clean),'W12_SCORE_MATERIAL_H1');
        $clean['horses'][0]['scores']['H1']=$clean['horses'][0]['observed_sum']=4;
        $clean['horses'][0]['score_evidence']['H1']=['reason'=>'確認した過去走','source_refs'=>[$past]];
        $first=$clean;$first['horses'][0]['score_evidence']['H1']['extra_note']='未定義の注記';
        $deny(fn()=>$verify($s,$first),'W3_SCHEMA_output');
        $initial=r4receipt($s,r3frame($first),0,false);
        $fix=static function(array $reply)use($s,$initial):array {
            $second=r4receipt($s,r3frame($reply),1,true);$second['repair_scope']=r5scope($initial,[],[],[1]);
            return ['kind'=>'ORIGINAL','input'=>$s,'receipts'=>[$initial,$second]];
        };
        $expected=$merge(r4original($s,r3frame($clean)))['public_candidates'];
        $corrected=$merge($fix($clean));$check($corrected['public_candidates']===$expected && !$corrected['attempt_issues'],'valid points and ordering survive corrected extra property');
        $check($merge(r4recoveryRecord($s,$clean,$initial))['public_candidates']===$expected,'locally repaired row remains traceable');
        foreach (['points','reason','refs','other_horse','other_band'] as $field) {
            $bad=$clean;
            if ($field==='points') $bad['horses'][0]['scores']['H1']=$bad['horses'][0]['observed_sum']=3;
            if ($field==='reason') $bad['horses'][0]['score_evidence']['H1']['reason']='改変';
            if ($field==='refs') $bad['horses'][0]['score_evidence']['H1']['source_refs'][]=$win;
            if ($field==='other_horse') $bad['horses'][1]['rank_reason'].='改変';
            if ($field==='other_band') $bad['band_ranks']['D']=array_reverse($bad['band_ranks']['D']);
            $deny(fn()=>$merge($fix($bad)),'W2_NO_VERIFIED_ROLE');
            if (in_array($field,['points','reason','refs'],true)) $deny(fn()=>$merge(r4recoveryRecord($s,$bad,$initial)),'W2_NO_VERIFIED_ROLE');
        }
    }
    echo "PASS $count R13 evidence families, shared missing values, ordinal finishes, correction boundaries and recovery checks across both roles.\n";
})($argv[2]);

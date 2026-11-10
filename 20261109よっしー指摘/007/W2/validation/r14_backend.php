<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as R14Choice;
use App\Services\Baganriki\FrozenInputW2 as R14Input;
use App\Services\Baganriki\SourceEvidenceW2 as R14Proof;
use App\Http\Controllers\Api\BaganrikiDecimal as R14Decimal;
require_once __DIR__.'/r14_fixtures.php';

(function(string $dir):void {
    $count=0;
    $check=static function(bool $ok,string $name)use(&$count):void {expect($ok,'R14 '.$name);++$count;};
    $deny=static function(callable $run,string $wanted)use($check):void {
        try {$run();} catch (InvalidArgumentException $e) { $check(str_starts_with($e->getMessage(),$wanted),$wanted.' / '.$e->getMessage());return; }
        throw new RuntimeException('R14 unexpected acceptance '.$wanted);
    };
    $eligible=static function(array $in,string $key,array $refs,int $no=1):bool {
        $cache=[];try {return R14Proof::scoreKnown($in,$no,$key,1,['reason'=>'参照','source_refs'=>$refs],$cache);}
        catch (InvalidArgumentException $e){return false;}
    };
    $family=static function(array $in,string $name,array $refs,bool $positive):bool {
        $cache=[];return R14Proof::familyKnown($in,1,$name,$refs,$cache,$positive);
    };
    $both=[];
    foreach (['1st','2nd'] as $role) {
        [$f,$in,$o,$proof]=r14Fixture($dir,$role);$both[$role]=[$f,$in,$o];$nos=array_column($in['runners'],'horse_no');
        $by=['ability'=>['h1_past_performance_None'],'gap'=>['r12_gap_1'],'market'=>['h1_win_odds_21','h1_win_odds_6'],
            'similar_value'=>['h1_similar_top3_rate','h1_similar_sample_count'],'win_place'=>['h1_win_odds_6','h1_place_min_6']];
        $held=r14Exclude($in,$o,'DATA_MISSING');$no=r14Exclude($in,$o,'NO_SUPPORT',$by);
        $deny(fn()=>r14Verify($f,$held),'W19_FALSE_MISSING_ability');
        $deny(fn()=>R14Choice::verifyReply($f,$held),'W19_FALSE_MISSING_ability');
        foreach ([$no] as $x) {
            $verified=r14Verify($f,$x);$check(count($x['horses'])===16 && !in_array(1,$verified['public_candidates'],true),'whole roster and valid exclusion');
            $check(R14Choice::verifyReply($f,$x)['public_candidates']===$verified['public_candidates'],'native accepts valid review');
        }
        $wrong=r14Exclude($in,$o,'NO_SUPPORT',array_fill_keys(array_keys($by),['h1_win_odds_6']));
        $deny(fn()=>r14Verify($f,$wrong),'W14_NO_SUPPORT_MATERIAL_ability');
        $deny(fn()=>R14Choice::verifyReply($f,$wrong),'W14_NO_SUPPORT_MATERIAL_ability');
        $broken=$no;unset($broken['horses'][0]['family_review']['ability']);$deny(fn()=>R14Choice::verifyReply($f,$broken),'W14_FAMILY_REVIEWS');
        $broken=$no;$broken['horses'][0]['family_review']['market']['status']='unchecked';$deny(fn()=>R14Choice::verifyReply($f,$broken),'W14_FAMILY_REVIEWS');
        foreach ($by as $name=>$refs) {
            $ctx=$in;foreach ($refs as $id) $ctx['source_registry'][$id]['dependency_role']='CONTEXT';
            $check($family($ctx,$name,$refs,false) && !$family($ctx,$name,$refs,true),'neutral material can justify a negative but never a positive '.$name);
            foreach ([['h1_win_odds_6'],['h2_past_performance_None'],[],['r14_unknown']] as $bad)
                $check(!$family($in,$name,$bad,false),'unrelated or absent material '.$name);
        }
        $check(!$family($in,'market',['h1_win_odds_6','h1_win_odds_6'],false),'repeated single observation');
        // Check all pairs of forecast/error horizons without sharing code with the matcher.
        // Retain the older observed time on the error to distinguish model horizon from publication day.
        foreach ([21,18,15,12,9,6,null,3] as $p) foreach ([21,18,15,12,9,6,null,3] as $e) {
            $ctx=$in;$ctx['source_registry']['h1_estimated_final_odds']['slot_offset']=$p;
            $ctx['source_registry']['h1_win_final_odds_error']['slot_offset']=$e;
            $expected=$p===$e && is_int($p) && in_array($p,[6,9,12,15,18,21],true);
            $check($eligible($ctx,'V4',['h1_estimated_final_odds','h1_win_final_odds_error'])===$expected,'forecast/error horizon matrix');
        }
        $ctx=$in;$ctx['source_registry']['h1_win_final_odds_error']['field']='place_final_odds_error';
        $check(!$eligible($ctx,'V4',['h1_estimated_final_odds','h1_win_final_odds_error']),'ticket mismatch');
        $ctx['source_registry']['h1_estimated_final_odds']['field']='estimated_final_place_min';
        $check($eligible($ctx,'V4',['h1_estimated_final_odds','h1_win_final_odds_error']),'place forecast with place error');
        $check(!$eligible($in,'V4',['h1_estimated_final_odds','h2_win_final_odds_error']),'wrong error owner');
        $check($eligible($in,'V4',['h1_estimated_final_odds','r14_early_error','h1_win_final_odds_error']),'valid matched pair along with irrelevant horizon');
        $check(!$eligible($in,'V4',['h1_estimated_final_odds','r14_early_error']),'earlier measure for another forecast horizon');
        $ctx=$in;$ctx['source_registry']['h1_win_final_odds_error']['value']=0;
        $check($eligible($ctx,'V4',['h1_estimated_final_odds','h1_win_final_odds_error']),'known zero measured error');
        $cases=[['r14_average','rank',1,true],['r14_average','rank',1.5,true],['r14_average','rank',0,false],
            ['r14_average','rank',-0.4,false],['r14_average','index',4,false],['r14_average','rank',null,false],
            ['r14_rate','percent',100,true],['r14_rate','percent',0,true],['r14_rate','percent',22.5,true],['r14_rate','percent',120,false],
            ['r14_rate','ratio',1,true],['r14_rate','ratio',0.6,true],['r14_rate','ratio',22.5,false],['r14_rate','ratio',-0.1,false],
            ['r14_rate','unknown',0.7,false],['r14_rate','percent',null,false]];
        foreach ([['r14_average','rank','0.999999999999999999999',false],['r14_average','rank','1.000000000000000000001',true],
            ['r14_rate','percent','100.00000000000000000001',false],['r14_rate','percent','99.99999999999999999999',true],
            ['r14_rate','ratio','1.00000000000000000001',false]] as [$id,$unit,$exact,$ok]) $cases[]=[$id,$unit,new R14Decimal($exact,$exact),$ok];
        foreach ($cases as [$id,$unit,$v,$ok]) {
            $ctx=$in;$ctx['source_registry'][$id]['value']=$v;$ctx['source_registry'][$id]['unit']=$unit;
            $check($eligible($ctx,'H1',[$id])===$ok && $eligible($ctx,'V2',[$id,'h1_win_odds_6'])===$ok,'bounded ability statistics');
            $check($family($ctx,'ability',[$id],false)===$ok && $family($ctx,'ability',[$id],true)===$ok,'consistent positive and negative ability checks');
        }
        $check($eligible($in,'H1',['r14_rate','h1_past_performance_None']),'independent valid ability remains usable');
        foreach ([['H1',['r14_average'],18],['H1',['r14_rate'],18],['V4',['h1_estimated_final_odds','r14_early_error'],6]] as [$key,$refs,$points]) {
            $x=r14SetScore($o,$key,$refs,$points);$deny(fn()=>r14Verify($f,$x),'W12_SCORE_MATERIAL_'.$key);$deny(fn()=>R14Choice::verifyReply($f,$x),'W12_SCORE_MATERIAL_'.$key);
        }
        $valid=r14SetScore($o,'V4',['h1_estimated_final_odds','h1_win_final_odds_error'],6);
        $check(r14Verify($f,$valid)['public_candidates']===$o['public_candidates'],'genuine forecast and error in raw response');
        foreach (['1.00000000000000000001'=>true,'0.99999999999999999999'=>false] as $digits=>$validPoint) {
            $precise=r14ExactFreeze($in,$proof,$digits);$tree=R14Input::checked($precise);$response=$o;$response['snapshot_hash']=$tree['snapshot_hash'];
            $score=r14SetScore($response,'H1',['r14_average'],2);
            if ($validPoint) $check(r14Verify($precise,$score)['public_candidates']===$o['public_candidates'],'valid exact average accepted from stored bytes');
            else $deny(fn()=>r14Verify($precise,$score),'W12_SCORE_MATERIAL_H1');
            $sent=r9send($precise);$body=$sent['user_json'];
            $check($sent['format']==='SOURCE_TABLE_V1' && $sent['messages'][1]['content']===$body,'normal worker sends upgraded exact compact body');
            $dec=\App\Http\Controllers\Api\BaganrikiJson::decode($body);$tab=$dec['value']['source_table'];$cols=array_flip($tab['columns']);
            foreach ($tab['rows'] as $rowIndex=>$row) if ($row[$cols['source_ref']]==='r14_average') break;
            $check($dec['ok'] && $row[$cols['source_ref']]==='r14_average' && \App\Services\Baganriki\SchemaW2::scalar($row[$cols['value']],$dec['map'],['source_table','rows',$rowIndex,$cols['value']])===['number',$digits],'actual transmitted cell keeps JSON number and original precision');
            $raw=r3frame($response);$initialReceipt=r4receipt($precise,$raw,0,false);$initialReceipt['sent_request']=$sent['sent_request'];
            $grant=r5scope($initialReceipt,[],[],[1]);$diagnostics=r9diagnostics();
            $repair=['first_receipt'=>$initialReceipt,'repair_scope'=>$grant,'diagnostics'=>$diagnostics,
                'inspection_sha256'=>\App\Services\Baganriki\DispatchW2::inspectionSeal($precise,$initialReceipt['response_hash'],$grant,$diagnostics)];
            $fixed=r9send($precise,$repair);
            $check(str_ends_with($fixed['user_json'],',"input":'.$body.'}') && $fixed['format']==='SOURCE_TABLE_V1','corrective send keeps the same numeric body');
            $lossy=static function($a) { $a['source_registry']['r14_average']['value']=(string)$a['source_registry']['r14_average']['value'];return r9pack($a); };
            $deny(fn()=>\App\Services\Baganriki\ExpectedBridgeW2::prepareSend($precise,$lossy,'r9expand',static fn()=>true,static fn()=>r4live($precise)),'W2_FULL_NOT_ALLOWED');
        }
        $deny(fn()=>r14ExactFreeze($in,$proof,'"1.00000000000000000001"'),'W3_SOURCE_TYPE');
        $bad=r14SetNoSupport($o,'gap',['h1_win_odds_6']);$initial=r4receipt($f,r3frame($bad),0,false);
        $merge=static fn(array $record)=>r9merge($f,[$role=>$record],$in['quota'],$nos,[],r4live($f));
        $correct=static function($initial,$reply,$scope)use($f):array {
            $second=r4receipt($f,r3frame($reply),1,true);$second['repair_scope']=$scope;
            return ['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$initial,$second]];
        };
        $expected=$merge(r4original($f,r3frame($o)))['public_candidates'];
        $check($merge($correct($initial,$o,r5scope($initial,[],[],[1])))['public_candidates']===$expected,'restore missing review without touching genuine evidence');
        $deny(fn()=>$merge(r4recoveryRecord($f,$bad,$initial)),'W2_NO_VERIFIED_ROLE');
        $alter=$o;$alter['horses'][1]['rank_reason'].='別馬変更';
        $deny(fn()=>$merge($correct($initial,$alter,r5scope($initial,[],[],[1]))),'W2_NO_VERIFIED_ROLE');
        $initial=r4receipt($f,r3frame($wrong),0,false);$scope=r5scope($initial,[1],[],[],[1,2,3,4,5,6]);
        $restored=$merge($correct($initial,$o,$scope));
        $check($restored['public_candidates']===$expected && !$restored['attempt_issues'],'corrected eligible horse and changed display inside its band');
        $alter=$o;$alter['horses'][6]['explanation'].='別帯で変更';
        $deny(fn()=>$merge($correct($initial,$alter,$scope)),'W2_NO_VERIFIED_ROLE');
    }
    foreach (['1st','2nd'] as $failed) {
        $good=$failed==='1st'?'2nd':'1st';[$main,$in,$o]=$both[$good];[$other,,$out]=$both[$failed];
        $bad=r14SetNoSupport($out,'gap',['h1_win_odds_6']);$goodRecord=r4original($main,r3frame($o));$badRecord=r4original($other,r3frame($bad));
        $nos=array_column($in['runners'],'horse_no');
        $base=r9merge($main,[$good=>$goodRecord],$in['quota'],$nos,[],r4live($main));
        $mix=r9merge($main,[$good=>$goodRecord,$failed=>$badRecord],$in['quota'],$nos,[],r4live($main));
        $check($base['public_candidates']===$mix['public_candidates'] && isset($mix['role_issues'][$failed]) && max($mix['agreement_bonus'])===0,'isolated failed role '.$failed);
    }
    echo "PASS $count R14 negative review, bounded past statistics, forecast horizons, correction and isolated-role checks across both roles.\n";
})($argv[2]);

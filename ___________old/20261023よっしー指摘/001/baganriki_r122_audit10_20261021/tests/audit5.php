<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
require __DIR__.'/audit4_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiDiagnose as Diagnose,BaganrikiResponse as Response,BaganrikiWorker as W};
use App\Support\Baganriki\ExactJson;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function must4(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function check5(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function readyPublication5(): void {
    $cutoff=W::nowUs()-5000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($cutoff+90000000);
    foreach(DB::$rows['baganriki_ai_attempts'] as &$a) $a['received_at']=W::toDb($cutoff-10+(int)$a['attempt_no']);
    unset($a); DB::$rows['baganriki_publication_intents']=[];
}
function partialSecond5(array $res): array {
    foreach(Diagnose::SCORE_CAP as $key=>$max) {
        $res['horses'][1]['scores'][$key]=null;
        $res['horses'][1]['score_evidence'][$key]=['source_refs'=>[],'reason'=>'Not observed'];
    }
    $res['horses'][1]['score_status']='partial';
    $res['horses'][1]['observed_sum']=$res['horses'][1]['observed_cap']=0;
    $res['horses'][1]['total_score']=null;
    foreach($res['horses'] as &$h) $h['rank_basis']='EVIDENCE'; unset($h);
    return $res;
}
foreach(Diagnose::SCORE_CAP as $key=>$cap) foreach(['unknown','other-horse'] as $kind) {
    check5('invalid score reference is nulled locally: '.$key.' '.$kind,function() use($key,$cap,$kind) {
        [$src,$res,$c]=eligibleFixture4(true);
        $res['horses'][0]['score_evidence'][$key]['source_refs']=[$kind==='unknown'?'does-not-exist':'horse-2-signal'];
        setRaw($res); $out=$c->r122InspectResponse(1,'1st');
        must4(array_keys($out['normal'])===[1,2],'normal own signal or unaffected horse was lost');
        $h=$out['normal'][1];
        must4($h['scores'][$key]===null,'unverified score survived');
        must4($h['observed_sum']===9 && $h['observed_cap']===100-$cap && $h['total_score']===null && $h['score_status']==='partial','score summary was not recalculated');
        must4($out['row_valid'][1]===false && in_array(1,$out['salvaged'],true),'salvaged row mislabeled as fully valid');
        foreach(Diagnose::SCORE_CAP as $other=>$max) if($other!==$key) must4($h['scores'][$other]===1,'unaffected score lost');
        must4($out['normal'][2]['score_status']==='complete','unaffected horse changed');
    });
}
check5('all bad score references retain own signal but no score',function() {
    [$src,$res,$c]=eligibleFixture4(true);
    foreach($res['horses'][0]['score_evidence'] as &$entry) $entry['source_refs']=['does-not-exist']; unset($entry);
    setRaw($res); $out=$c->r122InspectResponse(1,'1st'); $h=$out['normal'][1]??[];
    must4(($h['eligibility']??null)==='ELIGIBLE' && $h['observed_sum']===0 && $h['observed_cap']===0 && $h['total_score']===null,'normal signal lost or unverified scores retained');
});
foreach(['unknown','other-horse'] as $kind) foreach(['sum','other-score','score-container'] as $extra) {
    check5('score reference and arithmetic defects remain local: '.$kind.' '.$extra,function() use($kind,$extra) {
        [$src,$res,$c]=eligibleFixture4(true);
        $res['horses'][0]['score_evidence']['H1']['source_refs']=[$kind==='unknown'?'does-not-exist':'horse-2-signal'];
        if($extra==='sum') $res['horses'][0]['observed_sum']=999;
        elseif($extra==='other-score') $res['horses'][0]['scores']['H2']=999;
        else $res['horses'][0]['scores']=null;
        $res['evidence_band_ranks']['A']=[2,1]; setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4(array_keys($out['normal'])===[1,2],'combined score defects removed a horse with a valid own signal');
        must4($out['normal'][1]['scores']['H1']===null && $out['normal'][1]['score_status']==='partial','bad score retained');
        $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
        must4($used['band_ranks']['A']===[2,1] && $used['rank_source']['A']==='EVIDENCE_RANK','combined salvage lost effective EVIDENCE mode');
    });
}
check5('score salvage cannot bypass a simultaneous invalid eligibility',function() {
    [$src,$res,$c]=eligibleFixture4(true);
    $res['horses'][0]['score_evidence']['H1']['source_refs']=['does-not-exist'];
    $res['horses'][0]['observed_sum']=999; $res['horses'][0]['eligibility']='INVALID';
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must4(array_keys($out['normal'])===[2],'invalid qualification was salvaged');
});
foreach(['own-horse','race','applicable-cohort'] as $kind) check5('valid score reference remains accepted: '.$kind,function() use($kind) {
    [$src,$res,$c]=eligibleFixture4(true);
    $ref='valid-score-ref';
    $src['source_registry'][$ref]=['scope'=>match($kind){'race'=>'RACE','applicable-cohort'=>'COHORT',default=>'HORSE'},'horse_nos'=>[1],'applicable_horse_nos'=>[1],'dependency_role'=>'CONTEXT'];
    $res['horses'][0]['score_evidence']['H1']['source_refs']=[$ref];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src); setRaw($res);
    $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['needs_fix'] && $out['normal'][1]['scores']['H1']===1 && $out['normal'][1]['total_score']===10,'valid scoring evidence rejected');
});
check5('bad scoring reference cannot rescue a missing horse as a valid correction',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; array_shift($first['horses']); setRaw($first);
    $res['horses'][0]['score_evidence']['H1']['source_refs']=['does-not-exist']; addFix4($res);
    $out=$c->r122ResolveCorrection(1,'1st');
    must4(array_keys($out['merged'])===[2] && !in_array(1,$out['rescued'],true),'unvalidated correction rescued a horse');
});
check5('corrected scoring reference restores valid points and proof',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res;
    $first['horses'][0]['score_evidence']['H1']['source_refs']=['does-not-exist']; setRaw($first); addFix4($res);
    $out=$c->r122ResolveCorrection(1,'1st'); $used=$c->r122ResponseUsed(1,'1st',$out);
    must4($out['code']==='FIX_APPLIED' && $out['merged'][1]['total_score']===10,'valid correction did not restore scores');
    must4($used['proof']!==null,'fully corrected response lost proof');
});
check5('invalid correction row cannot reject a valid correction for another horse',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['scores']['H1']=99; setRaw($first);
    $fix=$res; $fix['horses'][1]['scores']['H1']=99; addFix4($fix);
    $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['code']==='FIX_APPLIED','one invalid correction rejected every valid repair: '.$out['reason']);
    must4($out['merged'][1]['scores']['H1']===1 && $out['merged'][1]['score_status']==='complete','valid correction lost');
    must4($out['merged'][2]['scores']['H1']===1 && $out['merged'][2]['score_status']==='complete','initial valid row was damaged');
    must4(in_array(2,$out['skipped'],true) && !in_array(2,$out['applied'],true),'invalid row not recorded as skipped');
});
check5('valid out-of-scope correction still rejects the attempt',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['scores']['H1']=99; setRaw($first);
    $res['horses'][1]['explanation']='Unauthorized explanation'; addFix4($res);
    $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['code']==='FIX_REJECTED' && $out['merged'][2]['explanation']!=='Unauthorized explanation','scope was widened');
});
foreach(['missing','duplicated','invalid-summary'] as $kind) check5('rank mode uses merged rows after a partial correction: '.$kind,function() use($kind) {
    [$src,$res,$c]=eligibleFixture4(true); $res=partialSecond5($res);
    $first=$res; $first['band_ranks']['A']=[1,1]; setRaw($first);
    $fix=$res; $fix['horses'][0]['rank_basis']='SCORE';
    if($kind==='missing') array_pop($fix['horses']);
    elseif($kind==='duplicated') $fix['horses'][]=$fix['horses'][1];
    else $fix['horses'][1]['family_review']['ability']['status']='invalid';
    $fix['band_ranks']['A']=[2,1]; $fix['public_candidates']=[2,1];
    addFix4($fix); $accepted=$c->r122ResolveCorrection(1,'1st');
    must4($accepted['code']==='FIX_APPLIED' && count($accepted['merged'])===2,'valid initial partial row not retained');
    $used=$c->r122ResponseUsed(1,'1st',$accepted);
    must4($used['band_ranks']['A']===[1,2] && $used['rank_source']['A']==='EVIDENCE_RANK','SCORE rank based on incomplete correction survived merge');
    must4($used['proof']===null,'partial correction gained proof');
    readyPublication5(); $pub=$c->r122Publish(1);
    must4($pub['ok'] && array_column($pub['candidates'],'horse_no')===[1,2],'unsafe correction order reached publication');
});
check5('retained initial rank is also checked against the merged scoring state',function() {
    [$src,$res,$c]=eligibleFixture4(true); $fix=partialSecond5($res);
    $first=$res; array_pop($first['horses']);
    $first['band_ranks']['A']=[2,1]; $first['evidence_band_ranks']['A']=[1,2]; $first['public_candidates']=[1];
    setRaw($first);
    $fix['horses'][0]['rank_basis']='SCORE'; // unchanged initial row; only missing horse 2 may be rescued
    $fix['band_ranks']['A']=$fix['evidence_band_ranks']['A']=[1,2]; addFix4($fix);
    $accepted=$c->r122ResolveCorrection(1,'1st');
    must4($accepted['code']==='FIX_APPLIED' && in_array(2,$accepted['rescued'],true),'missing horse not rescued');
    $used=$c->r122ResponseUsed(1,'1st',$accepted);
    must4($used['band_ranks']['A']===[1,2] && $used['rank_source']['A']==='EVIDENCE_RANK','incompatible initial SCORE order reused after rescue');
});
foreach([false,true] as $cancel) check5('score repair and earlier deadline compose with cancellation: '.($cancel?'cancelled':'active'),function() use($cancel) {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['score_evidence']['H1']['source_refs']=['does-not-exist']; setRaw($first); addFix4($res);
    readyPublication5();
    if($cancel) { DB::$rows['baganriki_live_states'][0]['cancellations']='[1]'; DB::$rows['baganriki_live_states'][0]['active_count']=1; }
    $out=$c->r122Publish(1);
    must4($out['ok'] && array_column($out['candidates'],'horse_no')===($cancel?[2]:[1,2]),'correction/cancellation composition lost normal horses');
    $last=end($out['candidates']); must4($last['score']===50.0,'cancellation compressed the frozen rank');
});
check5('late correction with score repairs is excluded without losing initial valid evidence',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['score_evidence']['H1']['source_refs']=['does-not-exist']; setRaw($first); addFix4($res);
    readyPublication5();
    $reply=W::dbUs(DB::$rows['baganriki_live_states'][0]['live_close'])-90000000;
    DB::$rows['baganriki_ai_attempts'][1]['received_at']=W::toDb($reply+1);
    $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['code']==='FIX_NOT_USABLE' && $out['merged'][1]['scores']['H1']===null && count($out['merged'])===2,'late correction restored invalid score or normal signal was lost');
});
echo "AUDIT5 RESULT: $passed passed, $failed failed. PHP with DB/HTTP doubles; no real database or external AI.\n";
exit($failed===0?0:1);

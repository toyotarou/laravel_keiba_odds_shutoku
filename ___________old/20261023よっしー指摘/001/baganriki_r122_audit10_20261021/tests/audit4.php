<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
require __DIR__.'/audit4_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiDiagnose as Diagnose,BaganrikiResponse as Response,BaganrikiWorker as W};
use App\Support\Baganriki\{ExactJson,R122Jobs};
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function check4(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function must4(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
foreach(['duplicate-same','duplicate-conflicting','string-number','legacy-num-only'] as $variant) check4('invalid horse identity is isolated locally: '.$variant,function() use($variant) {
    [$src,$res,$c]=eligibleFixture4();
    if(str_starts_with($variant,'duplicate')) {
        $copy=$res['horses'][0];
        if($variant==='duplicate-conflicting') $copy['explanation']='Different duplicated row';
        $res['horses'][]=$copy;
    } elseif($variant==='string-number') $res['horses'][0]['horse_no']='1';
    else { unset($res['horses'][0]['horse_no']); $res['horses'][0]['num']=1; }
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must4($out['ok'] && $out['needs_fix'],'identity issue not diagnosed');
    must4(array_keys($out['normal'])===[2],'invalid horse retained or unaffected horse lost');
    must4(in_array(1,$out['repair_scope']['horses'],true),'invalid horse not repairable');
});
check4('duplicate isolation rejects every duplicate occurrence',function() {
    $out=Response::isolateHorses([['horse_no'=>1],['horse_no'=>2],['horse_no'=>1],['horse_no'=>1]],[1,2]);
    must4(array_keys($out['kept'])===[2] && isset($out['isolated'][1]),'first duplicate was retained');
});
foreach(['duplicate','string-number'] as $variant) check4('corrected invalid horse identity can be rescued: '.$variant,function() use($variant) {
    [$src,$res,$c]=eligibleFixture4(); $first=$res;
    if($variant==='duplicate') $first['horses'][]=$first['horses'][0]; else $first['horses'][0]['horse_no']='1';
    setRaw($first); addFix4($res); $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['code']==='FIX_APPLIED' && in_array(1,$out['rescued'],true),'valid identity correction not rescued: '.$out['reason']);
    must4(array_keys($out['merged'])===[1,2] || array_keys($out['merged'])===[2,1],'normal horses not restored');
});
foreach(['NO_EVIDENCE','UNVERIFIED'] as $eligibility) check4('rank mode ignores noneligible partial horse per design section 8: '.$eligibility,function() use($eligibility) {
    [$src,$res,$c]=eligibleFixture4(true);
    [, $noEvidence]=(static fn()=>require __DIR__.'/fixture.php')();
    $h=$noEvidence['horses'][0]; $h['horse_no']=2; $h['eligibility']=$eligibility;
    $h['nonselect_reason']['code']=$eligibility; $res['horses'][1]=$h;
    $res['public_candidates']=[1]; $res['evidence_band_ranks']['A']=[2,1];
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['needs_fix'],'valid complete eligible band rejected: '.json_encode($out['diag']['findings']));
    $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
    must4($used['band_ranks']['A']===[1,2] && $used['rank_source']['A']==='RESPONSE','noneligible horse changed score order');
    must4($used['proof']!==null,'valid response lost proof');
});
foreach(['named-object','numeric-object'] as $variant) check4('horses must be a JSON list, not an object: '.$variant,function() use($variant) {
    [$src,$res,$c]=eligibleFixture4();
    $res['horses']=$variant==='named-object'
        ? ['first'=>$res['horses'][0],'second'=>$res['horses'][1]] : (object)$res['horses'];
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must4($out['normal']===[] && $out['needs_fix'],'object-shaped horse collection accepted');
});
check4('malformed identity must not suppress a separate valid integer row',function() {
    [$src,$res,$c]=eligibleFixture4(); $bad=$res['horses'][0]; $bad['horse_no']='1';
    $res['horses'][]=$bad; setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must4(array_keys($out['normal'])===[1,2] && $out['needs_fix'],'valid integer row lost with malformed extra row');
});
check4('isolated invalid eligibility cannot switch a valid band to evidence mode',function() {
    [$src,$res,$c]=eligibleFixture4(true);
    foreach($res['horses'][1]['scores'] as &$v) $v=null; unset($v);
    $res['horses'][1]['observed_sum']=$res['horses'][1]['observed_cap']=0;
    $res['horses'][1]['total_score']=null; $res['horses'][1]['score_status']='partial';
    foreach($res['horses'][1]['family_review'] as &$f) $f['status']='DATA_MISSING'; unset($f);
    $res['evidence_band_ranks']['A']=[2,1]; setRaw($res);
    $out=$c->r122InspectResponse(1,'1st');
    must4(array_keys($out['normal'])===[1],'invalid eligibility not isolated');
    $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
    must4($used['band_ranks']['A']===[1,2] && $used['rank_source']['A']==='RESPONSE','isolated row changed the valid horse score rank');
});
foreach(['empty','absent'] as $variant) check4('eligible claim needs a verifiable own signal even without a registry: '.$variant,function() use($variant) {
    [$src,$res,$c]=eligibleFixture4();
    if($variant==='empty') $src['source_registry']=[]; else unset($src['source_registry']);
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src);
    $out=$c->r122InspectResponse(1,'1st');
    must4($out['normal']===[],'unverified eligible claims survived empty or missing registry');
    must4(!$out['ok'] || $out['needs_fix'],'missing source verification passed');
});
check4('valid incomplete scoring stays EVIDENCE with both eligible horses',function() {
    [$src,$res,$c]=eligibleFixture4(); $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['needs_fix'] && count($out['normal'])===2,'valid partial eligible response rejected');
});
check4('valid integer identities stay usable',function() {
    [$src,$res,$c]=eligibleFixture4(true); $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['needs_fix'] && array_keys($out['normal'])===[1,2],'valid identities rejected');
});
foreach([-1,0,1] as $offset) check4('earlier live close revalidates received timestamp at publication: offset '.$offset,function() use($offset) {
    [$src,$res,$c]=eligibleFixture4();
    $close=W::nowUs()+85000000; $cutoff=$close-90000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($close);
    DB::$rows['baganriki_live_states'][0]['state_revision']=1;
    DB::$rows['baganriki_ai_attempts'][0]['received_at']=W::toDb($cutoff+$offset);
    DB::$rows['baganriki_publication_intents']=[];
    $out=$c->r122Publish(1);
    if($offset<=0) must4($out['ok'] && count($out['candidates'])===2,'on-time response was lost: '.json_encode($out));
    else {
        must4(!$out['ok'] && DB::$rows['baganriki_publications']===[],'response after new deadline was published');
        $audit=$c->r122InspectResponse(1,'1st');
        must4(count($audit['normal_kept'])===2 && $audit['normal']===[],'late response lost its audit material');
    }
});
check4('late correction cannot replace an on-time initial response or its ranks',function() {
    [$src,$res,$c]=eligibleFixture4(); $first=$res;
    $first['band_ranks']['A']=[1,1]; setRaw($first);
    $res['band_ranks']['A']=$res['evidence_band_ranks']['A']=$res['public_candidates']=[2,1];
    addFix4($res);
    $close=W::nowUs()+85000000; $cutoff=$close-90000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($close);
    DB::$rows['baganriki_ai_attempts'][0]['received_at']=W::toDb($cutoff-1);
    DB::$rows['baganriki_ai_attempts'][1]['received_at']=W::toDb($cutoff+1);
    $accepted=$c->r122ResolveCorrection(1,'1st');
    $used=$c->r122ResponseUsed(1,'1st',$accepted);
    must4($accepted['code']==='FIX_NOT_USABLE','late correction still adopted');
    must4($used['attempt_no']===W::FIRST && $used['band_ranks']['A']===[1,2],'late correction changed ranks');
});
foreach([null,'not-a-timestamp'] as $time) check4('missing or invalid receive time cannot prove eligibility: '.($time??'null'),function() use($time) {
    [$src,$res,$c]=eligibleFixture4(); DB::$rows['baganriki_ai_attempts'][0]['received_at']=$time;
    $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['ok'] && $out['normal']===[],'unverifiable receive time accepted');
});
check4('unknown frozen close fails closed while preserving audit material',function() {
    [$src,$res,$c]=eligibleFixture4(); DB::$rows['baganriki_snapshots'][0]['sales_close_at']=null;
    $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['ok'] && $out['code']==='TIME_UNCOMPUTABLE' && $out['normal']===[],'unknown deadline accepted');
    must4(count($out['normal_kept'])===2,'audit material discarded');
});
foreach([false,true] as $repair) check4('duplicate isolation and correction reach actual publication: '.($repair?'rescued':'isolated'),function() use($repair) {
    [$src,$res,$c]=eligibleFixture4(); $first=$res; $first['horses'][]=$first['horses'][0]; setRaw($first);
    if($repair) addFix4($res);
    $close=W::nowUs()+85000000; $cutoff=$close-90000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($close);
    DB::$rows['baganriki_ai_attempts'][0]['received_at']=W::toDb($cutoff-2);
    if($repair) DB::$rows['baganriki_ai_attempts'][1]['received_at']=W::toDb($cutoff-1);
    DB::$rows['baganriki_publication_intents']=[];
    $out=$c->r122Publish(1);
    must4($out['ok'],'publication did not complete: '.$out['code']);
    must4(array_column($out['candidates'],'horse_no')===($repair?[1,2]:[2]),'invalid horse leaked into publication or corrected horse was lost');
    $last=end($out['candidates']);
    must4($last['score']===50.0 && $last['bonus']===0.0,'rank was compressed or bonus invented');
});
echo "AUDIT4 RESULT: $passed passed, $failed failed. PHP with DB/HTTP doubles; no production runtime or external AI.\n";
exit($failed===0?0:1);

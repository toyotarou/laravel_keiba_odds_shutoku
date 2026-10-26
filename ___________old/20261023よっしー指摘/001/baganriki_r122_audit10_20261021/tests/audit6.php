<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
require __DIR__.'/audit4_fixture.php';
use App\Http\Controllers\Api\{BaganrikiDiagnose as Diagnose,BaganrikiResponse as Response,BaganrikiWorker as W};
use App\Support\Baganriki\ExactJson;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function must4(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function check6(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function readyPublication6(): void {
    $cutoff=W::nowUs()-5000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($cutoff+90000000);
    foreach(DB::$rows['baganriki_ai_attempts'] as &$a) $a['received_at']=W::toDb($cutoff-10+(int)$a['attempt_no']);
    unset($a); DB::$rows['baganriki_publication_intents']=[];
}
function second6(array $res): void {
    $res['ai_role']='2nd';
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    $row=DB::$rows['baganriki_ai_attempts'][0];
    $row['id']=20; $row['role']='2nd'; $row['token']='second-token';
    $row['raw_response']=$raw; $row['response_hash']=hash('sha256',$raw);
    DB::$rows['baganriki_ai_attempts'][]=$row;
}
function disabled6(): array {
    [$src,$res,$c]=resetFixture();
    foreach(['mode'=>'UNKNOWN','mode_status'=>'UNRESOLVED','allocation_mode'=>null,
             'mode_policy_id'=>'BASE_TRI_STATE_V1','selection_enabled'=>false,
             'role_cap'=>0,'role_quota'=>null,'quota'=>null,'target_count'=>0] as $k=>$v) $src[$k]=$res[$k]=$v;
    $res['horses'][0]['nonselect_reason']=['code'=>'SELECTION_DISABLED','reason'=>'Unresolved mode'];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src);
    DB::$rows['baganriki_snapshots'][0]['allocation_mode']=null;
    DB::$rows['baganriki_snapshots'][0]['quota_1st']='null';
    setRaw($res);
    return [$src,$res,$c];
}

check6('explicit nulls remain valid for UNRESOLVED',function() {
    [$src,$res,$c]=disabled6(); $out=$c->r122InspectResponse(1,'1st');
    must4($out['ok'] && !$out['needs_fix'],'valid explicit-null response rejected: '.$out['reason']);
});
foreach(['allocation_mode','role_quota'] as $field) check6('missing nullable envelope field is rejected: '.$field,function() use($field) {
    [$src,$res,$c]=disabled6(); unset($res[$field]); setRaw($res);
    $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['ok'] && $out['stage']==='envelope' && $out['normal']===[],'missing required field accepted as null');
    $v=end(DB::$rows['baganriki_validation_results']);
    must4(json_decode($v['diagnostics'],true)['stage']==='envelope','rejection not recorded');
});
foreach(['prompt_version','schema_version','race_id','ai_role','snapshot_hash','mode',
         'mode_status','allocation_mode','mode_policy_id','role_cap','role_quota'] as $field) {
    check6('existing envelope mismatch still rejected: '.$field,function() use($field) {
        [$src,$res,$c]=eligibleFixture4(true); $res[$field]=null; setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4(!$out['ok'] && $out['stage']==='envelope' && $out['normal']===[],'mismatching identity or allocation survived');
    });
}
foreach(['extra-band'=>['A'=>2,'B'=>0,'D'=>0,'X'=>0],
         'extra-numbered-key'=>['A'=>2,'B'=>0,'D'=>0,0=>0],
         'extra-case-key'=>['A'=>2,'B'=>0,'D'=>0,'a'=>0],
         'missing-band'=>['A'=>2,'B'=>0],
         'string-count'=>['A'=>'2','B'=>0,'D'=>0],
         'decimal-count'=>['A'=>2.0,'B'=>0,'D'=>0],
         'bool-count'=>['A'=>true,'B'=>0,'D'=>0],
         'list'=>[2,0,0], 'empty-object'=>new stdClass(), 'scalar'=>2] as $name=>$quota) {
    check6('invalid role quota is an envelope failure: '.$name,function() use($quota) {
        [$src,$res,$c]=eligibleFixture4(true); $res['role_quota']=$quota; setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4(!$out['ok'] && $out['stage']==='envelope' && $out['normal']===[],'invalid quota only marked repairable');
        readyPublication6(); $pub=$c->r122Publish(1);
        must4(!$pub['ok'] && $pub['code']==='NO_RESPONSE' && DB::$rows['baganriki_publications']===[],'invalid envelope reached publication');
    });
}
foreach([['A','B','D'],['A','D','B'],['B','A','D'],['B','D','A'],['D','A','B'],['D','B','A']] as $order) {
    check6('object key order is immaterial: '.implode('',$order),function() use($order) {
        [$src,$res,$c]=eligibleFixture4(true);
        foreach(['quota','role_quota'] as $field) {
            $q=[]; foreach($order as $key) $q[$key]=$res[$field][$key]; $res[$field]=$q;
        }
        setRaw($res); $original=DB::$rows['baganriki_ai_attempts'][0]['raw_response'];
        $out=$c->r122InspectResponse(1,'1st');
        must4($out['ok'] && !$out['needs_fix'],'same JSON object falsely diagnosed: '.$out['reason']);
        $accepted=$c->r122AcceptedHorses(1,'1st');
        $used=$c->r122ResponseUsed(1,'1st',$accepted);
        must4($used['proof']!==null && $used['proof'][1]===hash('sha256',$original),'valid original response lost proof');
        must4(DB::$rows['baganriki_ai_attempts'][0]['raw_response']===$original,'raw evidence rewritten');
        $resolved=$c->r122ResolveCorrection(1,'1st');
        must4($resolved['code']==='NO_FIX_NEEDED','key order requested an unnecessary correction');
    });
}
foreach(['quota','role_quota'] as $field) check6('reordered quota retains proven two-role bonus: '.$field,function() use($field) {
    [$src,$res,$c]=eligibleFixture4(true);
    $res[$field]=['D'=>0,'B'=>0,'A'=>2]; setRaw($res); second6($res);
    readyPublication6(); $out=$c->r122Publish(1);
    must4($out['ok'] && array_column($out['candidates'],'horse_no')===[1,2],'valid candidates not published');
    foreach($out['candidates'] as $h) must4($h['bonus']===5.0 && $h['public_proof_hash']!==null,'valid key order lost the verified +5 bonus');
    $stored=json_decode(DB::$rows['baganriki_publications'][0]['candidates'],true);
    must4(array_column($stored,'score')===[105.0,55.0],'wrong scores persisted');
});
foreach(['changed-value'=>['A'=>1,'B'=>1,'D'=>0],
         'extra-key'=>['A'=>2,'B'=>0,'D'=>0,'X'=>0],
         'string'=>['A'=>'2','B'=>0,'D'=>0],
         'decimal'=>['A'=>2.0,'B'=>0,'D'=>0],
         'list'=>[2,0,0]] as $name=>$quota) check6('quota order equivalence does not hide a real difference: '.$name,function() use($quota) {
    [$src,$res,$c]=eligibleFixture4(true); $res['quota']=$quota; setRaw($res);
    $out=$c->r122InspectResponse(1,'1st');
    must4($out['needs_fix'] && in_array('quota',$out['repair_scope']['top_fields'],true),'different quota treated as equivalent');
    $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
    must4($used['proof']===null,'different quota received proof');
});
check6('bad correction envelope preserves initial usable rows',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['scores']['H1']=99;
    setRaw($first); $res['role_quota']['X']=0; addFix4($res);
    $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['code']==='FIX_NOT_USABLE' && count($out['merged'])===2,'bad correction envelope was accepted');
    must4($out['merged'][1]['scores']['H1']===null && $out['merged'][2]['total_score']===10,'invalid correction changed initial retained rows');
});
check6('invalid role cannot alter the other valid role publication',function() {
    [$src,$res,$c]=eligibleFixture4(true); $bad=$res; $bad['role_quota']['X']=0;
    $bad['band_ranks']['A']=$bad['evidence_band_ranks']['A']=$bad['public_candidates']=[2,1];
    setRaw($bad); second6($res); readyPublication6(); $out=$c->r122Publish(1);
    must4($out['ok'] && array_column($out['candidates'],'horse_no')===[1,2],'valid role was lost');
    must4(array_column($out['candidates'],'score')===[100.0,50.0],'invalid role contaminated rank scores');
    must4(!array_key_exists('1st',$out['rank_source']) && isset($out['rank_source']['2nd']),'rejected role was used');
    foreach($out['candidates'] as $h) must4($h['bonus']===0.0,'bonus invented from invalid role');
});
check6('reordered valid quota composes with cancellation and an earlier deadline',function() {
    [$src,$res,$c]=eligibleFixture4(true); $res['role_quota']=['D'=>0,'A'=>2,'B'=>0];
    setRaw($res); second6($res); readyPublication6();
    DB::$rows['baganriki_live_states'][0]['cancellations']='[1]';
    DB::$rows['baganriki_live_states'][0]['active_count']=1;
    $out=$c->r122Publish(1);
    must4($out['ok'] && array_column($out['candidates'],'horse_no')===[2],'cancellation lost the remaining valid horse');
    must4($out['candidates'][0]['score']===55.0 && $out['candidates'][0]['bonus']===5.0,'frozen rank or valid bonus changed');
});
echo "AUDIT6 RESULT: $passed passed, $failed failed. PHP with DB/HTTP doubles; no real database or external AI.\n";
exit($failed===0?0:1);

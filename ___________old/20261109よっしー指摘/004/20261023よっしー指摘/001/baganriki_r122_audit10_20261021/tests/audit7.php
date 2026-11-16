<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
require __DIR__.'/audit4_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiDiagnose as Diagnose,BaganrikiResponse as Response,BaganrikiTime as Time,BaganrikiWorker as W};
use App\Support\Baganriki\ExactJson;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function must4(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function check7(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function zero7(array $res,string $key,bool $evidence): array {
    $res['horses'][0]['scores'][$key]=0;
    $res['horses'][0]['observed_sum']=$res['horses'][0]['total_score']=9;
    if(!$evidence) $res['horses'][0]['score_evidence'][$key]=['source_refs'=>[],'reason'=>'Not observed'];
    return $res;
}
function ready7(): void {
    $cutoff=W::nowUs()-5000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($cutoff+90000000);
    foreach(DB::$rows['baganriki_ai_attempts'] as &$a) $a['received_at']=W::toDb($cutoff-10+(int)$a['attempt_no']);
    unset($a); DB::$rows['baganriki_publication_intents']=[];
}
function second7(array $res): void {
    $res['ai_role']='2nd'; $row=DB::$rows['baganriki_ai_attempts'][0];
    $row['id']=20; $row['role']='2nd'; $row['token']='second-token';
    $row['raw_response']=Response::BEGIN.ExactJson::encode($res).Response::END;
    $row['response_hash']=hash('sha256',$row['raw_response']); DB::$rows['baganriki_ai_attempts'][]=$row;
}
foreach(Diagnose::SCORE_CAP as $key=>$cap) {
    check7('unsupported zero is null and cannot force SCORE: '.$key,function() use($key,$cap) {
        [$src,$res,$c]=eligibleFixture4(true); $res=zero7($res,$key,false);
        $res['evidence_band_ranks']['A']=[2,1]; setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4(array_keys($out['normal'])===[1,2],'normal qualification or other horse lost');
        $h=$out['normal'][1];
        must4($h['scores'][$key]===null && $h['observed_sum']===9 && $h['observed_cap']===100-$cap && $h['total_score']===null && $h['score_status']==='partial','unsupported zero counted as observed');
        must4($out['row_valid'][1]===false && $out['normal'][2]['total_score']===10,'repair or unaffected horse incorrect');
        $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
        must4($used['band_ranks']['A']===[2,1] && $used['rank_source']['A']==='EVIDENCE_RANK' && $used['proof']===null,'unsupported zero influenced rank mode or proof');
    });
    check7('supported zero remains a complete score: '.$key,function() use($key) {
        [$src,$res,$c]=eligibleFixture4(true); $res=zero7($res,$key,true); setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4($out['ok'] && !$out['needs_fix'] && $out['normal'][1]['scores'][$key]===0 && $out['normal'][1]['total_score']===9,'observed zero was discarded');
        $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
        must4($used['proof']!==null && $used['rank_source']['A']==='RESPONSE','valid zero lost rank or proof');
    });
}
foreach(['null-entry'=>null,'empty-object'=>new stdClass(),'missing-refs'=>['reason'=>'Unavailable']] as $name=>$entry) {
    check7('unverifiable zero entry remains local: '.$name,function() use($entry) {
        [$src,$res,$c]=eligibleFixture4(true); $res=zero7($res,'H1',true);
        $res['horses'][0]['score_evidence']['H1']=$entry; setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4(array_keys($out['normal'])===[1,2] && $out['normal'][1]['scores']['H1']===null,'unverifiable zero survived or valid qualification lost');
    });
}
check7('all unobserved zeros cannot fabricate complete scoring',function() {
    [$src,$res,$c]=eligibleFixture4(true);
    foreach(Diagnose::SCORE_CAP as $key=>$cap) {
        $res['horses'][0]['scores'][$key]=0;
        $res['horses'][0]['score_evidence'][$key]=['source_refs'=>[],'reason'=>'Not observed'];
    }
    $res['horses'][0]['observed_sum']=$res['horses'][0]['total_score']=0; setRaw($res);
    $out=$c->r122InspectResponse(1,'1st'); $h=$out['normal'][1];
    must4($h['eligibility']==='ELIGIBLE' && $h['observed_cap']===0 && $h['observed_sum']===0 && $h['total_score']===null && $h['score_status']==='partial','unobserved zeros still fabricate completeness');
});
check7('unobserved zero correction cannot restore completeness',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['scores']['H1']=99;
    setRaw($first); addFix4(zero7($res,'H1',false)); $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['merged'][1]['scores']['H1']===null && $out['merged'][1]['score_status']==='partial' && in_array(1,$out['skipped'],true),'unsupported zero was applied as a valid repair');
});
check7('supported zero correction restores valid scoring and proof',function() {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['scores']['H1']=99;
    setRaw($first); addFix4(zero7($res,'H1',true)); $out=$c->r122ResolveCorrection(1,'1st');
    must4($out['code']==='FIX_APPLIED' && $out['merged'][1]['scores']['H1']===0,'valid zero correction lost');
    must4($c->r122ResponseUsed(1,'1st',$out)['proof']!==null,'valid corrected response lost proof');
});
foreach([false,true] as $supported) check7('two-role publication with zero evidence: '.($supported?'supported':'missing'),function() use($supported) {
    [$src,$res,$c]=eligibleFixture4(true); $res=zero7($res,'H1',$supported); setRaw($res); second7($res);
    ready7(); $out=$c->r122Publish(1);
    must4($out['ok'] && array_column($out['candidates'],'horse_no')===[1,2],'valid candidates lost');
    foreach($out['candidates'] as $h) must4($h['bonus']===($supported?5.0:0.0),'wrong proof bonus persisted');
    $stored=json_decode(DB::$rows['baganriki_publications'][0]['candidates'],true);
    must4(array_column($stored,'score')===($supported?[105.0,55.0]:[100.0,50.0]),'wrong publication scores');
});

$parsers=['input'=>[AiController::class,'r122DbRfc3339'],'registry'=>[AiController::class,'r122DbTime'],'deadline'=>[W::class,'dbUs']];
foreach($parsers as $name=>$parse) foreach([
    '2026-02-30 12:00:00','2026-10-24 12:60:00','2026-10-24 12:00:60',
    '2026-10-24 12:00:00.1234567','2026-10-24 12:00:00junk',
    '2026-10-24 12:00:00+00:00','2026-00-24 12:00:00','2026-10-00 12:00:00'
] as $value) check7('invalid database time is not normalized: '.$name.' '.$value,function() use($parse,$value) {
    must4($parse($value)===null,'invalid or over-precision time was silently changed');
});
foreach($parsers as $name=>$parse) foreach(['','0','000001','123456'] as $frac) {
    check7('valid database time preserves microseconds: '.$name.' '.($frac===''?'seconds':$frac),function() use($name,$parse,$frac) {
        $value='2026-10-24 12:34:56'.($frac===''?'':'.'.$frac);
        $expected=Time::us(str_replace(' ','T',$value).'+09:00');
        $actual=$parse($value); if($name!=='deadline') $actual=Time::us($actual);
        must4($actual===$expected,'valid microseconds changed');
    });
}
check7('DATE-only registry input and DateTime deadline input stay supported',function() {
    must4(Time::us(AiController::r122DbTime('2026-10-24'))===Time::us('2026-10-24T00:00:00+09:00'),'DATE compatibility lost');
    $d=new DateTimeImmutable('2026-10-24T12:34:56.123456+00:00');
    must4(W::dbUs($d)===Time::us('2026-10-24T12:34:56.123456+00:00'),'DateTime instant changed');
});
check7('input time conversion cannot truncate a late batch into the cutoff',function() {
    $converted=AiController::r122DbRfc3339('2026-10-24 12:00:00.0000001');
    must4($converted===null,'over-precision receipt was moved onto the exact cutoff');
});
check7('unparseable received_at cannot authorize publication',function() {
    [$src,$res,$c]=eligibleFixture4(true); ready7();
    DB::$rows['baganriki_ai_attempts'][0]['received_at']='2026-00-01 10:00:00';
    $out=$c->r122InspectResponse(1,'1st');
    must4(!$out['ok'] && $out['code']==='RECEIVE_TIME_INVALID' && $out['normal']===[],'invalid calendar receipt accepted');
    $pub=$c->r122Publish(1);
    must4(!$pub['ok'] && $pub['code']==='NO_RESPONSE' && DB::$rows['baganriki_publications']===[],'invalid receipt reached publication');
});
echo "AUDIT7 RESULT: $passed passed, $failed failed. PHP with DB/HTTP doubles; no real database or external AI.\n";
exit($failed===0?0:1);

<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Support\Baganriki\{Decimal,ExactJson,JobPolicy,R122Jobs};
use App\Http\Controllers\Api\{AiController,BaganrikiJson,BaganrikiResponse,BaganrikiDiagnose};
set_error_handler(static function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$passed=0; $failed=0;
function check(bool $ok,string $name): void {
    global $passed,$failed;
    if($ok) { ++$passed; echo "PASS ",$name,"\n"; }
    else { ++$failed; echo "FAIL ",$name,"\n"; }
}
function rejects(callable $f): bool { try { $f(); return false; } catch(Throwable $e) { return true; } }
function parsed(string $json): mixed {
    $r=BaganrikiJson::strict($json);
    if(!$r['ok']) throw new RuntimeException($r['code'].':'.$r['reason']);
    return $r['value'];
}
$decimal='0.12345678901234567890123456789';
$v=parsed('{"n":'.$decimal.',"s":"'.$decimal.'","a":[],"o":{},"numeric_object":{"0":"x"}}');
check($v['n'] instanceof Decimal && is_string($v['s']),'numeric and quoted string stay distinct');
check(ExactJson::encode($v)==='{"n":'.$decimal.',"s":"'.$decimal.'","a":[],"o":{},"numeric_object":{"0":"x"}}','decimal and container types round-trip');
check(!ExactJson::same($v['n'],$v['s']),'comparison rejects numeric-to-string change');
check(!ExactJson::same($v['a'],$v['o']),'comparison rejects empty list-to-object change');
check(!ExactJson::same(parsed('1'),parsed('1.0')) && ExactJson::same(parsed('1e0'),parsed('1.00')),'integer syntax differs; equivalent decimal syntax agrees');
check(ExactJson::same(parsed('{"b":2,"a":1}'),parsed('{"a":1,"b":2}')),'object member order is not a change');
check(!ExactJson::same(parsed('[1,2]'),parsed('[2,1]')),'array element order remains meaningful');
$v['n']=new Decimal('0.98765432109876543210987654321');
check(str_contains(BaganrikiJson::reserialize($v,[]),'0.98765432109876543210987654321'),'edited number is saved instead of stale path value');
check(rejects(fn()=>json_encode($v,JSON_THROW_ON_ERROR)),'ordinary json_encode fails instead of quoting number');
check(ExactJson::encode(parsed('9223372036854775808'))==='9223372036854775808','integer beyond PHP range remains exact');
$many='['.implode(',',array_fill(0,5001,$decimal)).']';
$manyParsed=BaganrikiJson::strict($many);
check($manyParsed['ok'] && count($manyParsed['number_paths'])===5000 && ExactJson::encode($manyParsed['value'])===$many,'5001st number survives audit path cap');
foreach(['{"a":1,"\u0061":2}','[1,]','{"a":1,}','01','1e309',str_repeat('9',129),str_repeat('[',65).'0'.str_repeat(']',65)] as $i=>$invalid) {
    check(!BaganrikiJson::strict($invalid)['ok'],'strict parser rejects invalid case '.($i+1));
}
check(BaganrikiJson::strict('1e-308')['ok'] && BaganrikiJson::strict('1e308')['ok'],'numeric exponent limits remain usable');
check(R122Jobs::parts('20261024-4-5-7-12')===['2026-10-24','4','5','7',12],'race identifier parses explicitly');
check(rejects(fn()=>R122Jobs::parts('20260230-4-5-7-12')),'invalid calendar date is rejected');
$row=parsed('{"horse_no":1,"score_status":"partial","evidence":[{"value":'.$decimal.',"empty":{}}]}');
$row2=parsed(ExactJson::encode($row));
check(BaganrikiResponse::sameHorseRow($row,$row2),'separate Decimal instances compare by value and type');
$restated=BaganrikiResponse::acceptCorrection([1=>$row],[1=>$row2],null,null,[1],['horses'=>[]],[1=>true]);
check($restated['ok'],'unchanged full correction row is accepted');
$row2['evidence'][0]['value']=$decimal;
check(!BaganrikiResponse::acceptCorrection([1=>$row],[1=>$row2],null,null,[1],['horses'=>[]],[1=>true])['ok'],'out-of-scope numeric/string mutation is rejected');
$row2=parsed(ExactJson::encode($row)); $row2['observed_sum']=0;
$row['observed_sum']=1;
check(BaganrikiResponse::acceptCorrection([1=>$row],[1=>$row2],null,null,[1],['summary_horses'=>[1]],[1=>true])['ok'],'allowed summary edit keeps equal Decimal evidence');
$roles=['1st'=>['settled'=>true,'fingerprint_matches'=>true],'2nd'=>['settled'=>false,'fingerprint_matches'=>true]];
check(!JobPolicy::mayAggregate(145,180,210,$roles),'slow second role cannot cause early publication');
check(!JobPolicy::mayAggregate(180,180,210,$roles),'reply equality is still a response window');
check(JobPolicy::mayAggregate(181,180,210,$roles),'deadline can aggregate while one role is unfinished');
$roles['2nd']['settled']=true;
check(JobPolicy::mayAggregate(100,180,210,$roles),'both fully processed roles may aggregate early');
$roles['1st']['fingerprint_matches']=false;
check(!JobPolicy::mayAggregate(100,180,210,$roles),'changed journal invalidates prior settled marker');
check(!JobPolicy::mayAggregate(211,180,210,$roles),'publication after its deadline is rejected');

// Actual supplied controller pipeline with an in-memory DB double: no Laravel or real SQL.
$src=['prompt_version'=>'test','schema_version'=>'test','race_id'=>'20261024-4-5-7-1',
      'ai_role'=>'1st','snapshot_hash'=>'fixture-hash','mode'=>'test','mode_status'=>'test',
      'allocation_mode'=>'test','mode_policy_id'=>'test','target_count'=>1,'role_cap'=>1,
      'quota'=>['A'=>1,'B'=>0,'D'=>0],'role_quota'=>['A'=>1,'B'=>0,'D'=>0],
      'learning_stage'=>'test','selection_enabled'=>true,'runners'=>[['horse_no'=>1,'band'=>'A']],
      'source_registry'=>[]];
$h=['horse_no'=>1,'band'=>'A','tier'=>'UNVERIFIED','eligibility'=>'NO_EVIDENCE',
    'family_review'=>[],'evidence'=>[['value'=>new Decimal($decimal),'empty_object'=>new stdClass(),'empty_list'=>[]]],
    'counterevidence'=>[],'scores'=>[],'score_evidence'=>[],'score_status'=>'partial','total_score'=>null,
    'observed_sum'=>0,'observed_cap'=>0,'rank_basis'=>'NOT_RANKED','rank_reason'=>'No evidence',
    'public_candidate'=>false,'nonselect_reason'=>['code'=>'NO_EVIDENCE','reason'=>'No evidence'],
    'explanation'=>'Fixture','evidence_rank_evidence_ids'=>[],'evidence_rank_reason'=>'No evidence'];
foreach(BaganrikiDiagnose::FAMILIES as $k) $h['family_review'][$k]=['status'=>'DATA_MISSING','source_refs'=>[]];
foreach(BaganrikiDiagnose::SCORE_CAP as $k=>$cap) { $h['scores'][$k]=null; $h['score_evidence'][$k]=['source_refs'=>[],'reason'=>'Not observed']; }
$res=$src; unset($res['runners'],$res['source_registry']);
$res+=['horses'=>[$h],'band_ranks'=>['A'=>[1],'B'=>[],'D'=>[]],
       'evidence_band_ranks'=>['A'=>[1],'B'=>[],'D'=>[]],'public_candidates'=>[],'shortfalls'=>[],'validation_issues'=>[]];
$raw=BaganrikiResponse::BEGIN.ExactJson::encode($res).BaganrikiResponse::END;
DB::$rows=['baganriki_snapshots'=>[['id'=>1,'input_json_1st'=>json_encode($src),'frozen_nums'=>'[1]',
               'sales_close_at'=>'2026-10-24 12:05:00.000000']],
           'baganriki_ai_attempts'=>[['id'=>10,'snapshot_id'=>1,'role'=>'1st','attempt_no'=>0,'status'=>'RECEIVED',
               'complete'=>1,'raw_response'=>$raw,'response_hash'=>hash('sha256',$raw),'received_at'=>'2026-10-24 12:00:00.000000']],
           'baganriki_validation_results'=>[]];
$controller=new AiController(new App\Services\AnthropicService());
$inspected=$controller->r122InspectResponse(1,'1st');
check($inspected['ok'] && !$inspected['needs_fix'] && isset($inspected['normal'][1]),'real extract/envelope/isolate/diagnose pipeline accepts fixture');
if($inspected['needs_fix']) echo 'Diagnostic findings: ',ExactJson::encode($inspected['diag']),"\n";
$saved=DB::$rows['baganriki_validation_results'][0]['normal_part'];
$readBack=ExactJson::decode($saved);
check(ExactJson::encode($readBack[1]['evidence'][0]['value'])===$decimal && $readBack[1]['evidence'][0]['empty_object'] instanceof stdClass,'controller validation storage preserves precision and container type');
check(BaganrikiResponse::sameHorseRow($h,$readBack[1]),'saved normal row can be reread and compared exactly');
$accepted=$controller->r122AcceptedHorses(1,'1st');
$used=$controller->r122ResponseUsed(1,'1st',$accepted);
check($used['proof'][1]===hash('sha256',$raw),'publication proof survives separately parsed equal Decimal values');
$bad=$res; $bad['horses'][0]['scores']['H1']=new Decimal('1.0');
$d=BaganrikiDiagnose::run($bad,$src);
check(in_array('SCORES_RANGE',array_column($d['findings'],'code'),true),'Decimal score cannot masquerade as integer');
$bad=$res; $bad['band_ranks']['A']=[new Decimal('1.0')];
$d=BaganrikiDiagnose::run($bad,$src);
check(in_array('BAND_TYPE',array_column($d['findings'],'code'),true),'Decimal rank cannot coerce to horse 1');
$bad=$res; $bad['public_candidates']=[new Decimal('1.0')];
$d=BaganrikiDiagnose::run($bad,$src);
check(in_array('PC_TYPE',array_column($d['findings'],'code'),true),'Decimal public candidate cannot coerce to horse 1');
$bad=$res; $bad['role_cap']=new Decimal('1.0');
check(!BaganrikiResponse::checkEnvelope($bad,$src)['ok'],'Decimal role cap is rejected without integer coercion');
$bad=$res; $bad['role_quota']=new stdClass();
check(!BaganrikiResponse::checkEnvelope($bad,$src)['ok'],'empty object quota is rejected without object subscript error');
DB::$failWrites=true;
check(rejects(fn()=>$controller->r122InspectResponse(1,'1st')),'validation write failure propagates instead of silently settling');
DB::$failWrites=false;
echo "RESULT: $passed passed, $failed failed. In-memory DB only; no API calls.\n";
exit($failed===0 ? 0 : 1);

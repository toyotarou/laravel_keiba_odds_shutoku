<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Support\Baganriki\{Decimal,ExactJson,R122Jobs};
use App\Http\Controllers\Api\{AiController,BaganrikiWorker as W,BaganrikiResponse as Response,BaganrikiPrompt as Prompt};
use App\Jobs\Baganriki\RunRoleJob;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function audit(string $name,callable $f): void {
    global $passed,$failed;
    try { if(!$f()) throw new RuntimeException('assertion failed'); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function resetFixture(): array {
    global $testConfig,$testEnv;
    [$src,$res]=require __DIR__.'/fixture.php';
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    $now=W::nowUs();
    DB::$rows=[
        'baganriki_snapshots'=>[['id'=>1,'race_id'=>$src['race_id'],'generation'=>0,'snapshot_hash'=>$src['snapshot_hash'],
            'input_json_1st'=>json_encode($src),'input_json_2nd'=>json_encode($src),'frozen_nums'=>'[1]',
            'sales_close_at'=>W::toDb($now+200000000),'quota_1st'=>'{"A":1,"B":0,"D":0}','allocation_mode'=>'混戦']],
        'baganriki_ai_attempts'=>[['id'=>10,'snapshot_id'=>1,'role'=>'1st','attempt_no'=>0,'status'=>W::RECEIVED,
            'token'=>'token','complete'=>1,'raw_response'=>$raw,'response_hash'=>hash('sha256',$raw),'received_at'=>W::toDb($now-1000000)]],
        'baganriki_live_states'=>[['snapshot_id'=>1,'active_count'=>1,'state_revision'=>0,'response_revision'=>1,
            'publication_revision'=>0,'latest_publication_id'=>null,'cancellations'=>'[]','live_close'=>null]],
        'baganriki_r122_role_states'=>[['snapshot_id'=>1,'role'=>'1st','settled'=>false,'fingerprint'=>null]],
        'baganriki_r122_job_runs'=>[['race_id'=>$src['race_id']]],
        'baganriki_r122_jobs'=>[], 'baganriki_r122_leases'=>[], 'baganriki_r122_call_metrics'=>[],
        'baganriki_validation_results'=>[], 'baganriki_publications'=>[], 'baganriki_audit_events'=>[],
    ];
    DB::$failWrites=false; DB::$lockHook=null; DB::$storageType='longtext';
    DB::$connection=new TestConnection(); TestBus::$sent=[]; TestBus::$throwAfterInsert=false;
    $testConfig=['baganriki_jobs.enabled'=>true,'baganriki_jobs.race_ids'=>[$src['race_id']],
        'database.default'=>'mysql','queue.connections.baganriki_database.driver'=>'database',
        'queue.connections.baganriki_database.connection'=>'mysql','queue.connections.baganriki_database.table'=>'baganriki_r122_jobs'];
    $testEnv=['BAGANRIKI_R122_PROMPT'=>'1','BAGANRIKI_R122_QUEUE'=>'1'];
    return [$src,$res,new AiController(new App\Services\AnthropicService())];
}
function setRaw(array $res): void {
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    DB::$rows['baganriki_ai_attempts'][0]['raw_response']=$raw;
    DB::$rows['baganriki_ai_attempts'][0]['response_hash']=hash('sha256',$raw);
}
class TransientController extends AiController {
    public int $calls=0;
    public function r122SendFirst(int $snapshotId,string $role,array $opt=[]): array {
        ++$this->calls; return ['ok'=>false,'sent'=>false,'code'=>'STATE_CHANGED','reason'=>'test race'];
    }
}
class SendGateController extends AiController {
    public array $result=[];
    public function r122SendFirst(int $snapshotId,string $role,array $opt=[]): array {
        $this->result=W::allowSend('token',W::nowUs()-5000000);
        return $this->result+['sent'=>false];
    }
}
class ProcessController extends AiController {
    public bool $throw=false;
    public function r122AfterReceive($date,$kaisuu,$basho,$day,$race,string $role,array $q): array {
        if($this->throw) throw new RuntimeException('Simulated validation failure');
        return ['ran'=>true];
    }
}
class TwiceGateController extends AiController {
    public array $results=[];
    public function r122SendFirst(int $snapshotId,string $role,array $opt=[]): array {
        $this->results[] = W::allowSend('token',W::nowUs());
        $this->results[] = W::allowSend('token',W::nowUs());
        return ['sent'=>false,'ok'=>true];
    }
}
audit('retry a transient pre-reservation failure',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts']=[];
    $c=new TransientController(new App\Services\AnthropicService());
    R122Jobs::runRole($c,1,'1st'); R122Jobs::runRole($c,1,'1st');
    return $c->calls===2 && !DB::$rows['baganriki_r122_role_states'][0]['settled'];
});
audit('do not enqueue duplicate pending role jobs after throttle expiry',function() {
    resetFixture();
    $m=new ReflectionMethod(R122Jobs::class,'enqueue');
    for($i=0;$i<20;$i++) {
        foreach(DB::$rows['baganriki_r122_leases'] as &$lease) $lease['until_epoch']=0;
        unset($lease);
        $m->invoke(null,new RunRoleJob(1,'1st'),'role:1:1st','r122-1st');
    }
    return count(TestBus::$sent)===1;
});
audit('all-cancelled race cannot consume a reserved send right',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::RESERVED;
    DB::$rows['baganriki_live_states'][0]['active_count']=0;
    $c=new SendGateController(new App\Services\AnthropicService()); R122Jobs::runRole($c,1,'1st');
    return !$c->result['ok'] && DB::$rows['baganriki_ai_attempts'][0]['status']===W::RESERVED;
});
audit('send gate checks fresh time after lock acquisition',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::RESERVED;
    DB::$rows['baganriki_snapshots'][0]['sales_close_at']=W::toDb(W::nowUs()+89000000);
    $c=new SendGateController(new App\Services\AnthropicService()); R122Jobs::runRole($c,1,'1st');
    return !$c->result['ok'] && DB::$rows['baganriki_ai_attempts'][0]['status']===W::RESERVED;
});
foreach(['1st','2nd'] as $role) audit('missing finish reason is incomplete: '.$role,function() use($role) {
    [$src,$res,$c]=resetFixture();
    TestHttp::$response=new TestResponse($role==='1st' ? ['content'=>[['text'=>'valid-looking output']]] : ['choices'=>[['message'=>['content'=>'valid-looking output']]]]);
    return $c->r122CallAi($role,'fixture','fixture',1)['complete']===false;
});
foreach(['decimal_entry','empty_object_entry','bad_source_refs','bad_family_refs'] as $variant) audit('malformed nested evidence is isolated: '.$variant,function() use($variant) {
    [$src,$res,$c]=resetFixture();
    if($variant==='decimal_entry') $res['horses'][0]['evidence']=[new Decimal('1.2')];
    if($variant==='empty_object_entry') $res['horses'][0]['evidence']=[new stdClass()];
    if($variant==='bad_source_refs') $res['horses'][0]['evidence'][0]['source_refs']=[new stdClass()];
    if($variant==='bad_family_refs') $res['horses'][0]['family_review']['ability']['source_refs']=[new stdClass()];
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    return $out['ok'] && $out['needs_fix'] && $out['normal']===[];
});
audit('unsafe MySQL JSON storage is blocked before saving Decimal values',function() {
    [$src,$res,$c]=resetFixture(); DB::$storageType='json';
    try { $c->r122InspectResponse(1,'1st'); } catch(Throwable $e) { return count(DB::$rows['baganriki_validation_results'])===0; }
    return false;
});
audit('missing validation table must not be treated as successful inspection',function() {
    [$src,$res,$c]=resetFixture(); unset(DB::$rows['baganriki_validation_results']);
    try { $c->r122InspectResponse(1,'1st'); } catch(Throwable $e) { return true; }
    return false;
});
audit('invalid calendar close timestamp is rejected',function() {
    resetFixture();
    try { R122Jobs::updateLive(1,[],'2026-02-30 12:00:00',0,'test-event'); } catch(InvalidArgumentException $e) { return true; }
    return false;
});
audit('live update cannot modify a replay generation',function() {
    resetFixture(); DB::$rows['baganriki_snapshots'][0]['generation']=900;
    try { R122Jobs::updateLive(1,[1],null,0,'test-event'); } catch(RuntimeException $e) { return true; }
    return false;
});
audit('frozen input keeps exact values in correction payload',function() {
    global $testEnv;
    [$src,$res,$c]=resetFixture();
    $dir=sys_get_temp_dir().'/r122-audit-prompts-'.bin2hex(random_bytes(4)); mkdir($dir);
    $testEnv['BAGANRIKI_R122_PROMPT_DIR']=$dir;
    $files=(new ReflectionClass(Prompt::class))->getConstant('FILES');
    $body=Prompt::BEGIN."\n".Prompt::END."\n".Prompt::SCHEMA_VERSION."\n".Prompt::PROMPT_VERSION."\n【最優先規則】\n【出力形式】\n【実入力】";
    file_put_contents($dir.'/'.$files['1st'],$body);
    $input=json_encode($src); $input=substr($input,0,-1).',"precision_probe":0.12345678901234567890123456789,"empty_object":{}}';
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=$input;
    try { $out=$c->r122AssembleFix(1,'1st',['diag'=>[]]); }
    finally { unlink($dir.'/'.$files['1st']); rmdir($dir); }
    if(!$out['ok']) throw new RuntimeException($out['code'].':'.$out['reason']);
    $data=ExactJson::decode($out['user']);
    return $data['input']['precision_probe']->raw==='0.12345678901234567890123456789' && $data['input']['empty_object'] instanceof stdClass
        && str_contains($out['user'],'"input":'.$input);
});
audit('out-of-scope missing versus null field is not silently equal',function() {
    resetFixture();
    $first=[1=>['horse_no'=>1,'score_status'=>'partial','observed_sum'=>1,'optional_fact'=>null]];
    $fix=[1=>['horse_no'=>1,'score_status'=>'partial','observed_sum'=>0]];
    return !Response::acceptCorrection($first,$fix,null,null,[1],['summary_horses'=>[1]],[1=>true])['ok'];
});
audit('metadata database writes wait until after raw response handling',function() {
    [$src,$res,$c]=resetFixture();
    DB::$rows['baganriki_ai_attempts'][0]['status']=W::SENT;
    $context=new ReflectionProperty(R122Jobs::class,'roleContext'); $context->setValue(null,[1,'1st']);
    TestHttp::$response=new TestResponse(['model'=>'fixture','usage'=>['input_tokens'=>1],
        'stop_reason'=>'end_turn','content'=>[['text'=>'fixture']]]);
    try { $call=$c->r122CallAi('1st','fixture','fixture',1); }
    finally { $context->setValue(null,null); }
    return $call['ok'] && $call['complete'] && DB::$rows['baganriki_r122_call_metrics']===[] && isset($call['metrics']);
});
audit('conflicting confirmed cancellation is reapplied to fresh revision',function() {
    [$src,$res,$c]=resetFixture();
    $src['runners'][]=['horse_no'=>2,'band'=>'A'];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src);
    DB::$rows['baganriki_live_states'][0]['state_revision']=1;
    DB::$rows['baganriki_live_states'][0]['cancellations']='[1]';
    (new App\Jobs\Baganriki\UpdateRaceLiveJob(1,[2],null,0,'confirmed-event'))->handle();
    return DB::$rows['baganriki_live_states'][0]['cancellations']==='[1,2]' && DB::$rows['baganriki_live_states'][0]['active_count']===0;
});
audit('complete score summary requires an integer total',function() {
    [$src,$res,$c]=resetFixture();
    $h=&$res['horses'][0];
    foreach($h['scores'] as &$score) $score=0; unset($score);
    $h['observed_cap']=100; $h['score_status']='complete'; $h['total_score']=null;
    $d=App\Http\Controllers\Api\BaganrikiDiagnose::run($res,$src);
    return in_array('TOTAL_SCORE',array_column($d['findings'],'code'),true);
});
// Regression coverage for the revised recovery mechanism, with controlled interleavings.
audit('job marker and queue insert roll back together',function() {
    resetFixture(); TestBus::$throwAfterInsert=true;
    $m=new ReflectionMethod(R122Jobs::class,'enqueue');
    try { $m->invoke(null,new RunRoleJob(1,'1st'),'role:1:1st','r122-1st'); }
    catch(RuntimeException $e) { return DB::$rows['baganriki_r122_jobs']===[] && DB::$rows['baganriki_r122_leases']===[]; }
    finally { TestBus::$throwAfterInsert=false; }
    return false;
});
audit('active worker suppresses duplicate; released worker lock permits recovery',function() {
    resetFixture(); $m=new ReflectionMethod(R122Jobs::class,'enqueue');
    $m->invoke(null,new RunRoleJob(1,'1st'),'role:1:1st','r122-1st');
    DB::$rows['baganriki_r122_jobs'][0]['reserved_at']=time()-5;
    DB::$rows['baganriki_r122_leases'][0]['until_epoch']=0;
    $lock=(new ReflectionMethod(R122Jobs::class,'workLock'))->invoke(null,'role-run:1:1st');
    $m->invoke(null,new RunRoleJob(1,'1st'),'role:1:1st','r122-1st');
    if(count(TestBus::$sent)!==1) return false;
    (new ReflectionMethod(R122Jobs::class,'workUnlock'))->invoke(null,$lock,'role-run:1:1st');
    $m->invoke(null,new RunRoleJob(1,'1st'),'role:1:1st','r122-1st');
    return count(TestBus::$sent)===2;
});
audit('SENT journal is never sent again by recovery',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::SENT;
    $c=new TransientController(new App\Services\AnthropicService()); R122Jobs::runRole($c,1,'1st');
    return $c->calls===0 && !DB::$rows['baganriki_r122_role_states'][0]['settled'];
});
audit('one reserved slot grants the send right only once',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::RESERVED;
    $c=new TwiceGateController(new App\Services\AnthropicService()); R122Jobs::runRole($c,1,'1st');
    return $c->results[0]['ok'] && !$c->results[1]['ok'] && $c->results[1]['code']==='ALREADY_SENT';
});
audit('processing exception leaves role unsettled and clears role context',function() {
    resetFixture(); $c=new ProcessController(new App\Services\AnthropicService()); $c->throw=true;
    try { R122Jobs::runRole($c,1,'1st'); }
    catch(RuntimeException $e) { return !DB::$rows['baganriki_r122_role_states'][0]['settled'] && !R122Jobs::isRoleJob(1,'1st') && !R122Jobs::canSend(1,'1st'); }
    return false;
});
audit('received is not settled; both processed roles are required before deadline',function() {
    resetFixture();
    $second=DB::$rows['baganriki_ai_attempts'][0]; $second['id']=11; $second['role']='2nd'; $second['token']='second';
    DB::$rows['baganriki_ai_attempts'][]=$second;
    DB::$rows['baganriki_r122_role_states'][]=['snapshot_id'=>1,'role'=>'2nd','settled'=>false,'fingerprint'=>null];
    $c=new ProcessController(new App\Services\AnthropicService());
    if(R122Jobs::publicationGate(1)!==false) return false;
    R122Jobs::runRole($c,1,'1st');
    if(R122Jobs::publicationGate(1)!==false) return false;
    R122Jobs::runRole($c,1,'2nd');
    if(R122Jobs::publicationGate(1)!==true) return false;
    $fix=$second; $fix['attempt_no']=1; $fix['status']=W::RESERVED; DB::$rows['baganriki_ai_attempts'][]=$fix;
    return R122Jobs::publicationGate(1)===false;
});
audit('repeat live update is idempotent despite an old caller revision',function() {
    resetFixture(); R122Jobs::updateLive(1,[1],null,0,'same-confirmed-event');
    $revision=DB::$rows['baganriki_live_states'][0]['state_revision'];
    R122Jobs::updateLive(1,[1],null,0,'same-confirmed-event');
    return $revision===1 && DB::$rows['baganriki_live_states'][0]['state_revision']===1 && count(DB::$rows['baganriki_audit_events'])===1;
});
audit('no send when the remaining first-response window is too short',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::RESERVED;
    DB::$rows['baganriki_snapshots'][0]['sales_close_at']=W::toDb(W::nowUs()+129000000);
    $c=new SendGateController(new App\Services\AnthropicService()); R122Jobs::runRole($c,1,'1st');
    return !$c->result['ok'] && $c->result['code']==='NO_TIME_FOR_SEND';
});
audit('replay generation cannot publish even after reply deadline',function() {
    resetFixture(); DB::$rows['baganriki_snapshots'][0]['generation']=900;
    DB::$rows['baganriki_snapshots'][0]['sales_close_at']=W::toDb(W::nowUs()+89000000);
    return R122Jobs::publicationGate(1)===false;
});
audit('disabled owned race does not fall back to legacy send permission',function() {
    global $testConfig; [$src]=resetFixture(); $testConfig['baganriki_jobs.enabled']=false;
    return R122Jobs::intercept($src['race_id']) && !R122Jobs::canSend(1,'1st');
});
echo "AUDIT RESULT: $passed passed, $failed failed. DB/queue/lock doubles; no external HTTP.\n";
exit($failed ? 1 : 0);

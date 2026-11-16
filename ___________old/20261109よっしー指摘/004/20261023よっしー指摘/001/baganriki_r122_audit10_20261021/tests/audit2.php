<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiWorker as W,BaganrikiPrompt as Prompt,BaganrikiResponse as Response};
use App\Support\Baganriki\{R122Jobs,ExactJson};
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0;$failed=0;
function check2(string $name,callable $test): void {
    global $passed,$failed;
    try { if(!$test()) throw new RuntimeException('assertion failed'); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
class FixConflictController extends AiController {
    public int $fixCalls=0;
    public function r122SendFix(int $snapshotId,string $role,array $insp): array {
        ++$this->fixCalls;
        return ['sent'=>false,'ok'=>false,'code'=>$this->fixCalls===1?'STATE_CHANGED':'NO_TIME_FOR_FIX','reason'=>'fixture'];
    }
}
class LockReadController extends AiController {
    public array $gate=[];
    public function r122SendFirst(int $snapshotId,string $role,array $opt=[]): array {
        $this->gate=W::allowSend('token',W::nowUs()); return $this->gate+['sent'=>false];
    }
}
class RealFixConflictController extends AiController {
    public bool $once=true;
    public function r122AssembleFix(int $snapshotId,string $role,array $insp): array {
        $out=parent::r122AssembleFix($snapshotId,$role,$insp);
        if($this->once && $out['ok']) {
            $this->once=false;
            ++DB::$rows['baganriki_live_states'][0]['state_revision'];
        }
        return $out;
    }
}
check2('transient correction conflict remains retryable without repeating initial HTTP',function() {
    [$src,$res]=resetFixture(); $res['horses'][0]['observed_sum']=99; setRaw($res);
    $c=new FixConflictController(new App\Services\AnthropicService());
    try { R122Jobs::runRole($c,1,'1st'); } catch(RuntimeException $e) { if(!str_contains($e->getMessage(),'STATE_CHANGED')) throw $e; }
    $blocked=!DB::$rows['baganriki_r122_role_states'][0]['settled'];
    R122Jobs::runRole($c,1,'1st');
    return $blocked && $c->fixCalls===2 && DB::$rows['baganriki_r122_role_states'][0]['settled']
        && count(DB::$rows['baganriki_ai_attempts'])===1;
});
foreach(['cancelled','earlier-close'] as $variant) check2('send uses locked live row, not old repeatable-read snapshot: '.$variant,function() use($variant) {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::RESERVED;
    DB::$readSnapshots['baganriki_live_states']=DB::$rows['baganriki_live_states'];
    DB::$lockHook=static function() use($variant) {
        if($variant==='cancelled') DB::$rows['baganriki_live_states'][0]['active_count']=0;
        else DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb(W::nowUs()+80000000);
    };
    $c=new LockReadController(new App\Services\AnthropicService()); R122Jobs::runRole($c,1,'1st');
    return !$c->gate['ok'] && DB::$rows['baganriki_ai_attempts'][0]['status']===W::RESERVED
        && array_slice(DB::$lockEvents,0,2)===['baganriki_live_states','baganriki_ai_attempts'];
});
function withFixturePrompts(callable $test): mixed {
    global $testEnv;
    $dir=sys_get_temp_dir().'/r122-audit2-prompts-'.bin2hex(random_bytes(4)); mkdir($dir);
    $testEnv['BAGANRIKI_R122_PROMPT_DIR']=$dir;
    $files=(new ReflectionClass(Prompt::class))->getConstant('FILES');
    $body=Prompt::BEGIN."\n".Prompt::END."\n".Prompt::SCHEMA_VERSION."\n".Prompt::PROMPT_VERSION."\n【最優先規則】\n【出力形式】\n【実入力】";
    foreach(['1st','2nd'] as $role) file_put_contents($dir.'/'.$files[$role],$body);
    try { return $test(); }
    finally { foreach(['1st','2nd'] as $role) unlink($dir.'/'.$files[$role]); rmdir($dir); unset($testEnv['BAGANRIKI_R122_PROMPT_DIR']); }
}
foreach(['normal','correction','reservation-conflict'] as $scenario) check2('real controller send/receive/inspect/settle/publish pipeline: '.$scenario,function() use($scenario) {
    $repair=$scenario!=='normal';
    [$src,$valid,$c]=resetFixture();
    if($scenario==='reservation-conflict') $c=new RealFixConflictController(new App\Services\AnthropicService());
    $src['source_registry']['horse-1-signal']=['scope'=>'HORSE','horse_nos'=>[1],'applicable_horse_nos'=>[],'dependency_role'=>'SIGNAL'];
    $h=&$valid['horses'][0];
    $h['eligibility']='ELIGIBLE'; $h['tier']='A'; $h['rank_basis']='EVIDENCE';
    $h['rank_reason']='Confirmed own signal'; $h['public_candidate']=true; $h['nonselect_reason']=null;
    $h['family_review']['ability']=['status'=>'SUPPORTED','source_refs'=>['horse-1-signal']];
    $h['evidence'][0]['source_refs']=['horse-1-signal']; unset($h);
    $valid['public_candidates']=[1];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src);
    DB::$rows['baganriki_ai_attempts']=[];
    DB::$rows['baganriki_publication_intents']=[];
    $src2=$src; $src2['ai_role']='2nd';
    DB::$rows['baganriki_snapshots'][0]['input_json_2nd']=json_encode($src2);
    DB::$rows['baganriki_r122_role_states'][]=['snapshot_id'=>1,'role'=>'2nd','settled'=>false,'fingerprint'=>null];
    TestHttp::$handler=static function($role,$payload) use($valid,$repair) {
        $input=$role==='1st' ? $payload['prompt'] : $payload['messages'][1]['content'];
        $isFix=isset(json_decode($input,true)['fix_request']);
        $res=$valid; $res['ai_role']=$role;
        if($repair && !$isFix) $res['horses'][0]['observed_sum']=99;
        $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
        return new TestResponse($role==='1st'
            ? ['model'=>'test-anthropic','id'=>'fixture','stop_reason'=>'end_turn','content'=>[['text'=>$raw]]]
            : ['model'=>'test-deepseek','id'=>'fixture','choices'=>[['finish_reason'=>'stop','message'=>['content'=>$raw]]]]);
    };
    return withFixturePrompts(function() use($c,$repair,$scenario) {
        if(R122Jobs::publicationGate(1)!==false) throw new RuntimeException('initial gate');
        try { R122Jobs::runRole($c,1,'1st'); }
        catch(RuntimeException $e) { if($scenario!=='reservation-conflict' || !str_contains($e->getMessage(),'STATE_CHANGED')) throw $e; }
        if($scenario==='reservation-conflict') {
            if(DB::$rows['baganriki_r122_role_states'][0]['settled'] || count(TestHttp::$calls)!==1) throw new RuntimeException('correction conflict incorrectly settled');
            R122Jobs::runRole($c,1,'1st');
        }
        if(R122Jobs::publicationGate(1)!==false) throw new RuntimeException('single-role gate');
        R122Jobs::runRole($c,1,'2nd');
        if(R122Jobs::publicationGate(1)!==true) throw new RuntimeException('both-role gate: '.json_encode(DB::$rows['baganriki_r122_role_states']).' attempts='.count(DB::$rows['baganriki_ai_attempts']));
        R122Jobs::aggregate($c,1);
        R122Jobs::aggregate($c,1); // same revisions must not create another publication
        R122Jobs::runRole($c,1,'1st'); R122Jobs::runRole($c,1,'2nd');
        if(count(TestHttp::$calls)!==($repair?4:2)) throw new RuntimeException('HTTP count='.count(TestHttp::$calls).' diagnostics='.json_encode(array_column(DB::$rows['baganriki_validation_results'],'diagnostics')));
        if(count(DB::$rows['baganriki_ai_attempts'])!==($repair?4:2)) throw new RuntimeException('attempt count');
        if(count(DB::$rows['baganriki_publications'])!==1) throw new RuntimeException('publication: '.json_encode($c->r122PublishWhenReady(1)));
        $pub=DB::$rows['baganriki_publications'][0];
        return array_column(json_decode($pub['candidates'],true),'horse_no')===[1]
            && DB::$rows['baganriki_live_states'][0]['latest_publication_id']===$pub['publication_id'];
    });
});
echo "AUDIT2 RESULT: $passed passed, $failed failed. DB read-view/queue/HTTP doubles; no real concurrent database.\n";
exit($failed===0?0:1);

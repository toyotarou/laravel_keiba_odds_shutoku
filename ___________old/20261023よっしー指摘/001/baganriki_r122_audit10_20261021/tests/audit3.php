<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiWorker as W,BaganrikiResponse as Response,BaganrikiDiagnose as Diagnose,BaganrikiRanking as Ranking,BaganrikiPrompt as Prompt};
use App\Support\Baganriki\{ExactJson,R122Jobs};
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function check3(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function must3(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function eligibleFixture3(bool $complete=false): array {
    [$src,$res,$c]=resetFixture();
    $src['runners']=[['horse_no'=>1,'band'=>'A'],['horse_no'=>2,'band'=>'A']];
    foreach(['target_count','role_cap'] as $k) $src[$k]=$res[$k]=2;
    foreach(['quota','role_quota'] as $k) $src[$k]=$res[$k]=['A'=>2,'B'=>0,'D'=>0];
    $template=$res['horses'][0]; $res['horses']=[];
    foreach([1,2] as $n) {
        $ref='horse-'.$n.'-signal';
        $src['source_registry'][$ref]=['scope'=>'HORSE','horse_nos'=>[$n],'applicable_horse_nos'=>[],'dependency_role'=>'SIGNAL'];
        $h=$template; $h['horse_no']=$n; $h['eligibility']='ELIGIBLE'; $h['tier']='A';
        $h['rank_basis']=$complete?'SCORE':'EVIDENCE'; $h['rank_reason']='Confirmed own signal';
        $h['public_candidate']=true; $h['nonselect_reason']=null;
        $h['family_review']['ability']=['status'=>'SUPPORTED','source_refs'=>[$ref]];
        $h['evidence'][0]['source_refs']=[$ref];
        if($complete) {
            foreach(Diagnose::SCORE_CAP as $k=>$cap) {
                $h['scores'][$k]=1;
                $h['score_evidence'][$k]=['source_refs'=>[$ref],'reason'=>'Fixture signal'];
            }
            $h['score_status']='complete'; $h['observed_cap']=100;
            $h['observed_sum']=$h['total_score']=10;
        }
        $res['horses'][]=$h;
    }
    $res['band_ranks']['A']=$res['evidence_band_ranks']['A']=$res['public_candidates']=[1,2];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src);
    $src2=$src; $src2['ai_role']='2nd';
    DB::$rows['baganriki_snapshots'][0]['input_json_2nd']=json_encode($src2);
    DB::$rows['baganriki_snapshots'][0]['frozen_nums']='[1,2]';
    DB::$rows['baganriki_snapshots'][0]['quota_1st']='{"A":2,"B":0,"D":0}';
    DB::$rows['baganriki_live_states'][0]['active_count']=2;
    setRaw($res);
    $d=Diagnose::run($res,$src);
    must3($d['ok'],'invalid test fixture: '.json_encode($d['findings']));
    return [$src,$res,$c];
}
function addFix3(array $res): void {
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    $row=DB::$rows['baganriki_ai_attempts'][0];
    $row['id']=11; $row['attempt_no']=W::FIX; $row['token']='fix-token';
    $row['raw_response']=$raw; $row['response_hash']=hash('sha256',$raw);
    $row['received_at']=W::toDb(W::nowUs());
    DB::$rows['baganriki_ai_attempts'][]=$row;
}
foreach([false,true] as $swap) check3('proof covers effective band order: '.($swap?'changed outside correction scope':'unchanged valid correction'),function() use($swap) {
    [$src,$res,$c]=eligibleFixture3();
    $first=$res; $first['horses'][0]['observed_sum']=99; setRaw($first);
    if($swap) $res['band_ranks']['A']=$res['evidence_band_ranks']['A']=$res['public_candidates']=[2,1];
    addFix3($res);
    $accepted=$c->r122ResolveCorrection(1,'1st');
    must3($accepted['code']==='FIX_APPLIED','valid summary correction rejected: '.$accepted['reason']);
    $used=$c->r122ResponseUsed(1,'1st',$accepted);
    must3($used['band_ranks']['A']===[1,2],'out-of-scope rank changed');
    must3(($used['proof']===null)===$swap,'proof does not match the response actually used');
    $per=['1st'=>[],'2nd'=>[]];
    foreach([1,2] as $n) foreach(['1st','2nd'] as $role) $per[$role][$n]=['r'=>$n,'K'=>2,'public'=>true,'groups'=>1];
    $proof=['2nd'=>[1=>str_repeat('a',64),2=>str_repeat('a',64)]];
    if($used['proof']!==null) $proof['1st']=$used['proof'];
    $merged=Ranking::merge($per,$proof);
    foreach($merged as $h) must3($h['bonus']===($swap?0.0:5.0),'bonus not tied to effective response proof');
});
foreach(['all-null','one-null','missing-key'] as $variant) check3('incomplete score data cannot claim complete: '.$variant,function() use($variant) {
    [$src,$res,$c]=eligibleFixture3(true);
    $h=&$res['horses'][0];
    if($variant==='all-null') {
        foreach($h['scores'] as &$v) $v=null; unset($v);
        $h['observed_cap']=0; $h['observed_sum']=$h['total_score']=0;
    } else {
        $k=array_key_first(Diagnose::SCORE_CAP);
        if($variant==='one-null') $h['scores'][$k]=null; else unset($h['scores'][$k]);
        $h['observed_cap']-=Diagnose::SCORE_CAP[$k]; $h['observed_sum']=$h['total_score']=9;
    }
    unset($h);
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must3($out['needs_fix'],'unscored response passed validation');
    must3(($out['normal'][1]['score_status']??null)==='partial','score salvage did not normalize to partial');
    must3($out['normal'][1]['total_score']===null,'partial total must be null');
    must3(in_array('A',AiController::r122BadBands($out['repair_scope']),true),'score-based order remains accepted after partial salvage');
});
check3('partial score data cannot carry a numeric total',function() {
    [$src,$res,$c]=eligibleFixture3(); $res['horses'][0]['total_score']=0; setRaw($res);
    $out=$c->r122InspectResponse(1,'1st');
    must3($out['needs_fix'],'partial numeric total passed validation');
    must3(isset($out['normal'][1]) && $out['normal'][1]['total_score']===null,'valid own signal was lost or numeric total retained');
});
check3('all valid scores cannot falsely claim partial',function() {
    [$src,$res,$c]=eligibleFixture3(true); $res['horses'][0]['score_status']='partial';
    $res['horses'][0]['total_score']=null;
    foreach($res['horses'] as &$h) $h['rank_basis']='EVIDENCE'; unset($h);
    setRaw($res); $out=$c->r122InspectResponse(1,'1st');
    must3($out['needs_fix'],'inconsistent partial status passed validation');
    must3(($out['normal'][1]['score_status']??null)==='complete' && $out['normal'][1]['total_score']===10,'summary not recomputed from intact scores');
});
foreach([null,'bad',true,new stdClass()] as $i=>$bad) check3('invalid score-evidence container cannot retain unverified scores: '.$i,function() use($bad) {
    [$src,$res,$c]=eligibleFixture3(true); $res['horses'][0]['score_evidence']=$bad; setRaw($res);
    $out=$c->r122InspectResponse(1,'1st');
    must3($out['needs_fix'],'invalid evidence container passed validation');
    must3(($out['normal'][1]['score_status']??null)==='partial','scores without their evidence retained');
    must3($out['normal'][1]['observed_cap']===0 && $out['normal'][1]['total_score']===null,'unverified score totals retained');
    must3(in_array('A',AiController::r122BadBands($out['repair_scope']),true),'unverified score rank was kept');
});
check3('rank-band repair permits necessary per-horse rank-mode corrections',function() {
    [$src,$res,$c]=eligibleFixture3();
    $first=$res; foreach($first['horses'] as &$h) $h['rank_basis']='SCORE'; unset($h);
    setRaw($first); addFix3($res);
    $out=$c->r122ResolveCorrection(1,'1st');
    must3($out['code']==='FIX_APPLIED','rank-mode correction rejected: '.$out['reason']);
    must3($out['merged'][1]['rank_basis']==='EVIDENCE' && $out['merged'][2]['rank_basis']==='EVIDENCE','corrected rank modes not applied');
});
foreach(['other-field','other-band','change-band'] as $variant) check3('rank-band permission remains limited: '.$variant,function() use($variant) {
    $first=[1=>['horse_no'=>1,'band'=>'A','score_status'=>'partial','rank_basis'=>'SCORE','rank_reason'=>'old'],
            2=>['horse_no'=>2,'band'=>'B','score_status'=>'partial','rank_basis'=>'SCORE','rank_reason'=>'old']];
    $fix=$first; $fix[1]['rank_basis']='EVIDENCE';
    if($variant==='other-field') $fix[1]['explanation']='unauthorized';
    if($variant==='other-band') $fix[2]['rank_basis']='EVIDENCE';
    if($variant==='change-band') $fix[1]['band']='B';
    must3(!Response::acceptCorrection($first,$fix,null,null,[1,2],['rank_bands'=>['A']],[1=>true,2=>true])['ok'],'scope widened to unrelated fields or bands');
});
check3('complete score with an invalid component uses verified evidence order',function() {
    [$src,$res,$c]=eligibleFixture3(true);
    $key=array_key_first(Diagnose::SCORE_CAP); $res['horses'][0]['scores'][$key]=Diagnose::SCORE_CAP[$key]+1;
    $res['evidence_band_ranks']['A']=[2,1]; setRaw($res);
    $accepted=$c->r122AcceptedHorses(1,'1st');
    $used=$c->r122ResponseUsed(1,'1st',$accepted);
    must3(($accepted['merged'][1]['score_status']??null)==='partial','invalid item not salvaged');
    must3(($used['band_ranks']['A']??null)===[2,1],'unusable score order retained');
    must3($used['rank_source']['A']==='EVIDENCE_RANK','evidence fallback not recorded');
    must3($used['proof']===null,'invalid response received bonus proof');
});
foreach([false,true] as $complete) check3('valid status and rank mode remain accepted: '.($complete?'complete':'partial'),function() use($complete) {
    [$src,$res,$c]=eligibleFixture3($complete);
    $out=$c->r122InspectResponse(1,'1st');
    must3(!$out['needs_fix'] && count($out['normal'])===2,'valid response rejected');
    $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
    must3($used['band_ranks']['A']===[1,2] && $used['proof']!==null,'valid proof or order lost');
});
check3('score salvage followed by whole-band rank-mode correction is accepted',function() {
    [$src,$res,$c]=eligibleFixture3(true);
    $key=array_key_first(Diagnose::SCORE_CAP);
    $res['horses'][0]['scores'][$key]=null;
    $res['horses'][0]['observed_cap']-=Diagnose::SCORE_CAP[$key];
    $res['horses'][0]['observed_sum']=$res['horses'][0]['total_score']=9;
    setRaw($res);
    $res['horses'][0]['score_status']='partial'; $res['horses'][0]['total_score']=null;
    foreach($res['horses'] as &$h) $h['rank_basis']='EVIDENCE'; unset($h);
    addFix3($res); $out=$c->r122ResolveCorrection(1,'1st');
    must3($out['code']==='FIX_APPLIED','valid band correction rejected: '.$out['reason']);
    must3($out['merged'][1]['total_score']===null && $out['merged'][2]['total_score']===10,'unrelated valid scores changed');
    $used=$c->r122ResponseUsed(1,'1st',$out);
    must3($used['band_ranks']['A']===[1,2] && $used['proof']!==null,'corrected whole response not usable');
});
function readyPublication3(): AiController {
    [$src,$res,$c]=eligibleFixture3();
    $res['ai_role']='2nd';
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    $row=DB::$rows['baganriki_ai_attempts'][0];
    $row['id']=11; $row['role']='2nd'; $row['token']='second';
    $row['raw_response']=$raw; $row['response_hash']=hash('sha256',$raw);
    DB::$rows['baganriki_ai_attempts'][]=$row;
    DB::$rows['baganriki_publication_intents']=[];
    DB::$rows['baganriki_r122_role_states'][]=['snapshot_id'=>1,'role'=>'2nd','settled'=>false,'fingerprint'=>null];
    R122Jobs::runRole($c,1,'1st'); R122Jobs::runRole($c,1,'2nd');
    must3(R122Jobs::publicationGate(1)===true,'publication fixture not ready');
    return $c;
}
foreach(['state_revision','response_revision','publication_revision','deadline'] as $change) check3('publication rechecks state under commit lock: '.$change,function() use($change) {
    $c=readyPublication3();
    DB::$lockHook=static function() use($change) {
        if($change==='deadline') DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb(W::nowUs()-1000000);
        else ++DB::$rows['baganriki_live_states'][0][$change];
    };
    $out=$c->r122Publish(1);
    must3(!$out['ok'] && in_array($out['code'],['STALE','DEADLINE_PASSED'],true),'stale publication accepted: '.json_encode($out));
    must3(DB::$rows['baganriki_publications']===[] && DB::$rows['baganriki_publication_intents']===[],'partial publication saved');
    must3(TestHttp::$calls===[],'publication invoked AI');
});
foreach(['baganriki_publication_intents','baganriki_publications','baganriki_live_states'] as $table) check3('publication write failure rolls back and retry is idempotent: '.$table,function() use($table) {
    $c=readyPublication3();
    DB::$writeHook=static function($actual,$operation,$data) use($table) {
        if($actual!==$table) return;
        DB::$writeHook=null; throw new RuntimeException('injected publication write failure');
    };
    $out=$c->r122Publish(1);
    must3(!$out['ok'] && $out['code']==='PUBLISH_EXCEPTION','write failure not reported');
    must3(DB::$rows['baganriki_publications']===[] && DB::$rows['baganriki_publication_intents']===[],'transaction partially persisted');
    must3(DB::$rows['baganriki_live_states'][0]['latest_publication_id']===null,'live pointer advanced despite rollback');
    $one=$c->r122Publish(1); $two=$c->r122Publish(1);
    must3($one['ok'] && $two['ok'] && $one['publication_id']===$two['publication_id'],'retry not idempotent');
    must3(count(DB::$rows['baganriki_publications'])===1 && count(DB::$rows['baganriki_publication_intents'])===1,'retry duplicated publication');
    must3(TestHttp::$calls===[],'retry sent AI again');
});
function withPrompts3(callable $test): void {
    global $testEnv;
    $dir=sys_get_temp_dir().'/r122-audit3-prompts-'.bin2hex(random_bytes(4)); mkdir($dir);
    $testEnv['BAGANRIKI_R122_PROMPT_DIR']=$dir;
    $files=(new ReflectionClass(Prompt::class))->getConstant('FILES');
    $body=Prompt::BEGIN."\n".Prompt::END."\n".Prompt::SCHEMA_VERSION."\n".Prompt::PROMPT_VERSION."\n【最優先規則】\n【出力形式】\n【実入力】";
    file_put_contents($dir.'/'.$files['1st'],$body);
    try { $test(); }
    finally { unlink($dir.'/'.$files['1st']); rmdir($dir); unset($testEnv['BAGANRIKI_R122_PROMPT_DIR']); }
}
foreach(['received-write','validation-write'] as $failure) check3('interrupted first-response processing never repeats HTTP: '.$failure,function() use($failure) {
    [$src,$res,$c]=eligibleFixture3(); DB::$rows['baganriki_ai_attempts']=[];
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    TestHttp::$response=new TestResponse(['id'=>'fixture','model'=>'fixture','stop_reason'=>'end_turn','content'=>[['text'=>$raw]]]);
    DB::$writeHook=static function($table,$op,$data) use($failure) {
        $hit=$failure==='received-write'
            ? ($table==='baganriki_ai_attempts' && ($data['status']??null)===W::RECEIVED)
            : $table==='baganriki_validation_results';
        if(!$hit) return;
        DB::$writeHook=null; throw new RuntimeException('injected response processing interruption');
    };
    withPrompts3(function() use($c,$failure) {
        try { R122Jobs::runRole($c,1,'1st'); } catch(RuntimeException $e) {
            must3(str_contains($e->getMessage(),'injected response'),'unexpected exception: '.$e->getMessage());
        }
        must3(count(TestHttp::$calls)===1 && count(DB::$rows['baganriki_ai_attempts'])===1,'first HTTP/journal count');
        must3(!DB::$rows['baganriki_r122_role_states'][0]['settled'],'interrupted role settled');
        R122Jobs::runRole($c,1,'1st');
        must3(count(TestHttp::$calls)===1,'recovery repeated charged HTTP');
        if($failure==='validation-write') {
            must3(DB::$rows['baganriki_ai_attempts'][0]['status']===W::RECEIVED && DB::$rows['baganriki_r122_role_states'][0]['settled'],'received body did not resume validation');
        } else {
            must3(DB::$rows['baganriki_ai_attempts'][0]['status']===W::SENT && !DB::$rows['baganriki_r122_role_states'][0]['settled'],'uncertain delivery was treated as safely retryable');
        }
    });
});
echo "AUDIT3 RESULT: $passed passed, $failed failed. Standalone PHP with database doubles; no real database or external AI.\n";
exit($failed===0?0:1);

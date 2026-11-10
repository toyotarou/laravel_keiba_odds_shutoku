<?php
use App\Support\Baganriki\ExactJson;
use App\Http\Controllers\Api\{AiController,BaganrikiWorker as W,BaganrikiResponse as Response};
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
    DB::$failWrites=false; DB::$lockHook=null; DB::$writeHook=null; DB::$storageType='longtext';
    DB::$readSnapshots=[]; DB::$lockEvents=[];
    TestHttp::$calls=[]; TestHttp::$handler=null;
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

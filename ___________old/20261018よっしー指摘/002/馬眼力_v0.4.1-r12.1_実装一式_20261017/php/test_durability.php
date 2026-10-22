<?php
declare(strict_types=1);
require __DIR__.'/BaganrikiR12Bridge.php';
require __DIR__.'/RoleWorker.php';
$fixture=json_decode(file_get_contents(__DIR__.'/live_clock_fixture.json'),true,512,JSON_THROW_ON_ERROR);
$p=$fixture['prepared'];$root=dirname(__DIR__);$databases=[];$results=[];
foreach (['failed_correction','unknown_initial','incomplete_reply','large_transport','deadline_mismatch'] as $case) {
    $db=sys_get_temp_dir().'/bgr12-durable-'.getmypid().'-'.$case.'.sqlite';$databases[]=$db;
    $bridge=new \Baganriki\BaganrikiR12Bridge(getenv('BAGANRIKI_PYTHON') ?: 'python3',$root,$db);
    $bridge->install($p['input_json']['1st'],1);
    $worker=new \Baganriki\RoleWorker($bridge);$calls=0;
    $never=function () use (&$calls):array {++$calls;throw new RuntimeException('unexpected provider call');};
    if ($case==='failed_correction') {
        $durableBeforeCorrection=false;
        $provider=function () use (&$calls,&$durableBeforeCorrection,$bridge,$p,$fixture):array {
            ++$calls;
            if ($calls===2) {
                $saved=$bridge->workerState($p['input_json']['1st'],1);
                if (count($saved['attempts'])!==1 || $saved['attempts'][0]['text']!==$fixture['initial']) throw new LogicException('initial was not durable before correction');
                $durableBeforeCorrection=true;
                throw new RuntimeException('simulated provider disconnect');
            }
            return ['text'=>$fixture['initial'],'complete'=>true];
        };
        $raw=$worker->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],$provider);
        if ($calls!==2 || count($raw)!==1 || !$durableBeforeCorrection) throw new RuntimeException('initial lost after correction failed');
        $saved=$bridge->workerState($p['input_json']['1st'],1);
        if ($saved['slots'][1]['status']!=='FAILED') throw new RuntimeException('failure not persisted');
        $worker->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],$never);
        if ($calls!==2) throw new RuntimeException('queue retry repeated provider');
        $out=$bridge->publishSaved('race',1,0,0,4,'durable');
        if (!$out['public_candidates']) throw new RuntimeException('lost valid initial horses');
    } elseif ($case==='unknown_initial') {
        $grant=$bridge->claim($p['input_json']['1st'],1,$p['prompts']['1st']);
        if (!$grant['granted']) throw new RuntimeException('missing initial grant');
        $raw=$worker->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],$never);
        if ($calls!==0 || $raw!==[]) throw new RuntimeException('unknown call regranted');
    } elseif ($case==='incomplete_reply') {
        $provider=function () use (&$calls,$fixture):array {++$calls;return ['text'=>$fixture['corrected'],'complete'=>$calls===2];};
        $raw=$worker->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],$provider);
        if ($calls!==2 || $raw[0]['text']!==$fixture['corrected'] || $raw[0]['complete']!==false) throw new RuntimeException('incomplete raw bytes changed');
        $out=$bridge->publishSaved('race',1,0,0,4,'complete');
        if (!$out['public_candidates']) throw new RuntimeException('complete correction not adopted');
    } elseif ($case==='large_transport') {
        $grant=$bridge->claim($p['input_json']['1st'],1,$p['prompts']['1st']);
        $raw=str_repeat("\x00",1024*1024);
        $at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
        $bridge->complete($grant['token'],$raw,$at,false);
        $saved=$bridge->workerState($p['input_json']['1st'],1);
        if ($saved['attempts'][0]['text']!==$raw) throw new RuntimeException('transport truncated raw response');
    } else {
        $wrong=(new DateTimeImmutable($fixture['reply_deadline']))->modify('+1 microsecond')->format('Y-m-d\TH:i:s.uP');
        $rejected=false;
        try {$worker->run($p['prompts']['1st'],$p['input_json']['1st'],$wrong,$never);}
        catch (InvalidArgumentException $ex) {$rejected=true;}
        if (!$rejected || $calls!==0) throw new RuntimeException('fractional deadline mismatch ignored');
    }
    $results[$case]='PASSED';
}
echo json_encode(['php_version'=>PHP_VERSION,'runtime'=>'PHP-WASM','provider'=>'mock','durability'=>$results]),"\n";
foreach ($databases as $db) foreach ([$db,$db.'-wal',$db.'-shm'] as $path) if (file_exists($path)) unlink($path);

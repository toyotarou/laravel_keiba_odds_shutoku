<?php
declare(strict_types=1);
require __DIR__ . '/BaganrikiR12Bridge.php';
require __DIR__ . '/RoleWorker.php';
require __DIR__ . '/PublicView.php';
require __DIR__ . '/LaravelIntegration.example.php';
$fixture = json_decode(file_get_contents(__DIR__ . '/live_clock_fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$root=dirname(__DIR__);$db=sys_get_temp_dir().'/bgr12-worker-'.getmypid().'.sqlite';
$bridge=new \Baganriki\BaganrikiR12Bridge(getenv('BAGANRIKI_PYTHON') ?: 'python3',$root,$db);
$p=$fixture['prepared'];$calls=0;
$bridge->install($p['input_json']['1st'],1);
$provider = function (string $system, string $user, float $timeout) use (&$calls,$fixture): array {
    if ($timeout<=0 || strpos($system,'BAGANRIKI_V041_R8_BEGIN')===false) throw new RuntimeException('bad AI request');
    ++$calls;
    if ($calls===2 && strpos($user,'repair_scope')===false) throw new RuntimeException('missing repair scope');
    return ['text'=>$calls===1 ? $fixture['initial'] : $fixture['corrected'],'complete'=>true];
};
$attempts=(new \Baganriki\RoleWorker($bridge))->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],$provider);
if ($calls!==2 || count($attempts)!==2) throw new RuntimeException('correction call count');
$resume=(new \Baganriki\RoleWorker($bridge))->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],static function ():array {throw new RuntimeException('must not call again');});
if ($resume!==$attempts) throw new RuntimeException('resume lost response');
$one=$bridge->publishSaved('race',1,0,0,4,'job1');
if (!in_array(7,$one['public_candidates'],true)) throw new RuntimeException('missing horse not rescued');
$again=$bridge->publishSaved('race',1,0,0,4,'job1');
if ($again['publication_id']!==$one['publication_id']) throw new RuntimeException('duplicate commit');
$bridge->updateLive('race',0,[7]);$two=$bridge->publishSaved('race',1,1,1,4,'job2');
if (in_array(7,$two['public_candidates'],true)) throw new RuntimeException('cancelled horse remained');
$read=$bridge->read('race');
if ($read['publication']['publication_id']!==$two['publication_id']) throw new RuntimeException('read version');
if (($read['input_audit']['coverage']['OPI']['status'] ?? null)!=='UNAVAILABLE') throw new RuntimeException('coverage not persisted');
if (!isset($read['live_state']['effective_publish_deadline'])) throw new RuntimeException('missing effective deadline');
$dto=\Baganriki\PublicView::build($read);
if ($dto['actual_quota']!==$two['actual_quota']) throw new RuntimeException('missing quota DTO');
$cases=[];$databases=[$db];
foreach (['unrelated_correction','public_flag_correction','summary_correction','review_correction','counter_correction'] as $key) {
    $callsForCase=0;$sample=$fixture[$key];
    $caseDb=$db.'-'.$key;$databases[]=$caseDb;
    $caseBridge=new \Baganriki\BaganrikiR12Bridge(getenv('BAGANRIKI_PYTHON') ?: 'python3',$root,$caseDb);
    $caseBridge->install($p['input_json']['1st'],1);
    $mock=function (string $system,string $user,float $timeout) use (&$callsForCase,$sample):array {
        ++$callsForCase;
        return ['text'=>$sample[$callsForCase===1?'initial':'corrected'],'complete'=>true];
    };
    $raw=(new \Baganriki\RoleWorker($caseBridge))->run($p['prompts']['1st'],$p['input_json']['1st'],$fixture['reply_deadline'],$mock);
    if ($callsForCase!==2) throw new RuntimeException('missed correction: '.$key);
    $at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    $out=$bridge->replay($p['input_json']['1st'],['1st'=>$raw],[],$at);
    if ($out['public_candidates']!==[1,2,10,9,11]) throw new RuntimeException('normal holes changed: '.$key);
    if (in_array('RANK_RECOVERY_FALLBACK',$out['status_flags'],true) || in_array('PUBLIC_CANDIDATE_VALIDATION_FAILED',$out['status_flags'],true)) throw new RuntimeException('unresolved correction: '.$key);
    $cases[$key]='PASSED';
}
echo json_encode(['php_version'=>PHP_VERSION,'runtime'=>'PHP-WASM','provider'=>'mock','correction_calls'=>$calls,
    'worker_publish_idempotency_cancel_read'=>'PASSED','coverage_roundtrip'=>'PASSED','r8_corrections'=>$cases,
    'production_db_and_live_ai_tested'=>false]),"\n";
foreach ($databases as $database) foreach ([$database,$database.'-wal',$database.'-shm'] as $path) if (file_exists($path)) unlink($path);

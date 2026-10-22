<?php
declare(strict_types=1);
require __DIR__.'/BaganrikiR12Bridge.php';
require __DIR__.'/RoleWorker.php';
require __DIR__.'/PublicView.php';
use Baganriki\Timestamp;
$checks=[];
$rejects=0;
foreach (['2026-10-17T14:54:00.0000001+09:00','2026-10-17T14:54:00.0000000+09:00',
    '2026-10-17 14:54:00+09:00','2026-W42-6T14:54:00+09:00','20261017T145400+0900',
    '2026-10-17T14:54:00+09:00:00.1','2026-10-17T14:54+09:00',
    '2026-10-17T14:54:00,1+09:00','2026-02-30T14:54:00+09:00','0000-01-01T00:00:00Z',
    '2026-10-17T14:54:60+09:00','2026-10-17T24:00:00+09:00'] as $text) {
    try {Timestamp::parse($text);}
    catch (InvalidArgumentException $ex) {++$rejects;continue;}
    throw new RuntimeException('Unsupported timestamp accepted: '.$text);
}
$checks['invalid_timestamps_rejected']='PASSED';
$jst=Timestamp::parse('2026-10-17T14:54:00.123456+09:00');
if ($jst!=Timestamp::parse('2026-10-17t05:54:00.123456z') || $jst->format('u')!=='123456') throw new RuntimeException('precision or alias lost');
$checks['exact_microseconds_and_alias']='PASSED';
$fixture=json_decode(file_get_contents(__DIR__.'/live_clock_fixture.json'),true,512,JSON_THROW_ON_ERROR);
$p=$fixture['prepared'];$db=sys_get_temp_dir().'/bgr12-time-'.getmypid().'.sqlite';
$bridge=new \Baganriki\BaganrikiR12Bridge(getenv('BAGANRIKI_PYTHON') ?: 'python3',dirname(__DIR__),$db);
$bridge->install($p['input_json']['1st'],1);
$worker=new \Baganriki\RoleWorker($bridge);$calls=0;
$never=function () use (&$calls):array {++$calls;throw new RuntimeException('unexpected provider call');};
$bad=preg_replace('/(\.[0-9]+)?(Z|[+-][0-9]{2}:[0-9]{2})$/','.0000001$2',$fixture['reply_deadline']);
try {$worker->run($p['prompts']['1st'],$p['input_json']['1st'],$bad,$never);throw new RuntimeException('invalid deadline accepted');}
catch (InvalidArgumentException $ex) {}
$state=$bridge->workerState($p['input_json']['1st'],1);
if ($calls!==0 || $state['slots']!==[]) throw new RuntimeException('invalid deadline consumed call budget');
$checks['worker_rejects_before_reserving_or_calling']='PASSED';
$stored=['publication'=>null,'live_state'=>['active_count'=>13,'effective_publish_deadline'=>'2026-10-17T14:58:00+09:00']];
try {\Baganriki\PublicView::build($stored,[],'2026-10-17T14:58:00.0000001+09:00');throw new RuntimeException('view truncated timestamp');}
catch (InvalidArgumentException $ex) {}
$checks['view_rejects_unsupported_precision']='PASSED';
if (\Baganriki\PublicView::build($stored,[],'2026-10-17T05:58:00Z')['status']!=='PENDING') throw new RuntimeException('exact deadline rejected');
if (\Baganriki\PublicView::build($stored,[],'2026-10-17T05:58:00.000001Z')['status']!=='UNAVAILABLE') throw new RuntimeException('late view pending');
$checks['view_exact_and_next_microsecond']='PASSED';
echo json_encode(['php_version'=>PHP_VERSION,'time_boundary'=>$checks,'invalid_timestamp_cases'=>$rejects]),"\n";
foreach ([$db,$db.'-wal',$db.'-shm'] as $path) if (file_exists($path)) unlink($path);

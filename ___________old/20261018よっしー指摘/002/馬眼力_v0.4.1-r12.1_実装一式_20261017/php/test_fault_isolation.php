<?php
declare(strict_types=1);
require __DIR__ . '/BaganrikiR12Bridge.php';
$fixture=json_decode(file_get_contents(__DIR__.'/live_clock_fixture.json'),true,512,JSON_THROW_ON_ERROR);
$root=dirname(__DIR__);$db=sys_get_temp_dir().'/bgr12-isolation-'.getmypid().'.sqlite';
$bridge=new \Baganriki\BaganrikiR12Bridge(getenv('BAGANRIKI_PYTHON') ?: 'python3',$root,$db);
$bridge->install($fixture['prepared']['input_json']['1st'],1);
$revision=0;$results=[];
foreach ($fixture['malformed_responses'] as $name=>$text) {
    $at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    $responses=['1st'=>[['text'=>$text,'received_at'=>$at]],'2nd'=>[['text'=>$fixture['second_response'],'received_at'=>$at]]];
    $out=$bridge->publish('race',1,0,$revision,'isolation-'.$name,$responses);
    if ($out['public_candidates']!==[1,2,7,8,11] || !in_array('SINGLE_AI',$out['status_flags'],true)) throw new RuntimeException('fault isolation failed: '.$name);
    $read=$bridge->read('race');
    if ($read['publication']['publication_id']!==$out['publication_id']) throw new RuntimeException('failed persistence: '.$name);
    $revision=$out['publication_revision'];$results[$name]='PASSED';
}
echo json_encode(['php_version'=>PHP_VERSION,'fault_isolation'=>$results,'production_db_and_live_ai_tested'=>false]),"\n";
foreach ([$db,$db.'-wal',$db.'-shm'] as $path) if (file_exists($path)) unlink($path);

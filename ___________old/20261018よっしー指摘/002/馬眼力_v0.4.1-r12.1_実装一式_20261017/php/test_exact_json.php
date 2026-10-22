<?php
declare(strict_types=1);
require __DIR__.'/BaganrikiR12Bridge.php';
require __DIR__.'/PublicView.php';
use Baganriki\ExactJson;
$checks=[];
$big='123456789012345678901234567890123456789';
$raw='{"small":13,"negative":-2,"zero":0,"big":'.$big.',"huge":9E+308,"tiny":1E-308,"precise":1.000000000000000001,"signed_zero":-0.0000,"text":"1e309","quoted":"a \\"1.23\\" b","null":null,"flag":true}';
$a=ExactJson::decode($raw);
if ($a['small']!==13 || $a['negative']!==-2 || $a['zero']!==0 || $a['flag']!==true || $a['null']!==null) throw new RuntimeException('metadata types changed');
foreach (['big'=>$big,'huge'=>'9E+308','tiny'=>'1E-308','precise'=>'1.000000000000000001','signed_zero'=>'-0.0000','text'=>'1e309','quoted'=>'a "1.23" b'] as $key=>$value) {
    if ($a[$key]!==$value) throw new RuntimeException('exact value changed: '.$key);
}
$checks['numbers_and_metadata']='PASSED';
$roundtrip=json_decode(json_encode($a,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
if ($a!==$roundtrip) throw new RuntimeException('HTTP serialization lost value');
$checks['http_roundtrip']='PASSED';
$text=str_repeat('text 1.000000000000000001 \\ " ',140000);
$input=['input_json'=>'{"fact":9E308,"label":"1.23"}','response_text'=>$text,'unicode'=>'馬眼力🐎'];
if (ExactJson::decode(json_encode($input,JSON_THROW_ON_ERROR))!==$input) throw new RuntimeException('nested strings changed');
$checks['large_escaped_strings_unchanged']='PASSED';
$controls=['response_text'=>str_repeat("\x00",1024*1024)];
if (ExactJson::decode(json_encode($controls,JSON_THROW_ON_ERROR))!==$controls) throw new RuntimeException('control payload changed');
$checks['large_control_string_unchanged']='PASSED';
foreach (['{"n":NaN}','{"n":01.2}','{"n":1.}',"{\"n\":\"unterminated 1e3}"] as $bad) {
    try {ExactJson::decode($bad);throw new RuntimeException('invalid JSON accepted');}
    catch (JsonException $ex) {}
}
$checks['invalid_json_rejected']='PASSED';
$fixture=ExactJson::decode(file_get_contents(__DIR__.'/live_clock_fixture.json'))['numeric_case'];
$p=$fixture['prepared'];$db=sys_get_temp_dir().'/bgr12-exact-'.getmypid().'.sqlite';
$bridge=new \Baganriki\BaganrikiR12Bridge(getenv('BAGANRIKI_PYTHON') ?: 'python3',dirname(__DIR__),$db);
$bridge->install($p['input_json']['1st'],1);
foreach (['1st','2nd'] as $role) {
    $grant=$bridge->claim($p['input_json'][$role],1,$p['prompts'][$role]);
    if (!$grant['granted']) throw new RuntimeException('grant not available');
    $at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    $bridge->complete($grant['token'],$fixture['responses'][$role],$at,true);
}
$first=$bridge->publishSaved('race',1,0,0,4,'exact');
$stored=$bridge->read('race');$dto=\Baganriki\PublicView::build($stored);
if ($stored['publication']!==$first || $first['public_candidates']!==[1,2,7,8,11]) throw new RuntimeException('numeric fixture publication changed');
$served=json_decode(json_encode($dto,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
$observed=[];
foreach ($served['candidates'] as $candidate) {
    $fact=$candidate['explanations']['1st']['facts'][0];
    if ($fact['value_type']!=='number') throw new RuntimeException('fact type missing');
    $observed[$candidate['horse_no']]=$fact['value'];
}
foreach ($fixture['expected'] as $no=>$expected) if (($observed[$no] ?? null)!==$expected) throw new RuntimeException('DB-to-HTTP numeric loss: '.$no);
$checks['store_publication_view_http_exact']='PASSED';
echo json_encode(['php_version'=>PHP_VERSION,'runtime'=>'PHP-WASM','exact_json'=>$checks,'production_db_and_live_ai_tested'=>false]),"\n";
foreach ([$db,$db.'-wal',$db.'-shm'] as $path) if (file_exists($path)) unlink($path);

<?php
declare(strict_types=1);
use App\Services\Baganriki\FrozenInputW2 as F;
use App\Services\Baganriki\ExpectedBridgeW2 as B;
use App\Services\Baganriki\DispatchW2 as D;
use App\Services\Baganriki\RaceMetricsW2 as M;
use App\Services\Baganriki\SchemaW2 as S;
use App\Http\Controllers\Api\BaganrikiPrompt as C;
use App\Http\Controllers\Api\BaganrikiJson as J;
require $argv[1];require __DIR__.'/../php/ExpectedBridgeW2.php';require __DIR__.'/r4_fixtures.php';
$dir=$argv[2];$count=0;
function good(bool $ok,string $why):void {global $count;if(!$ok)throw new RuntimeException($why);++$count;}
function denied(callable $op,string $code):void {try{$op();}catch(InvalidArgumentException $e){good(str_contains($e->getMessage(),$code),$e->getMessage().' != '.$code);return;}throw new RuntimeException('accepted '.$code);}
$proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true);
foreach(['1st','2nd'] as $role){
 $raw=file_get_contents($dir.'/'.$role.'_input.json');$in=json_decode($raw,true);$c=C::commonHash($in);
 $f=F::fromStored($c['json'],$raw,$c['hash'],$role,$proof);$state=r4live($f,r4time($in['input_freeze_at'],'+5 seconds'));
 $pack=fn($x)=>C::compactWire($x)['wire'];$expand=fn($x)=>C::expandWire($x)['input'];$live=fn()=>$state;
 $sent=B::prepareSend($f,$pack,$expand,fn()=>true,$live);
 good($sent['format']===($role==='1st'?'FULL':'SOURCE_TABLE_V1'),'initial format');
 good($sent['user_json']===D::inputBody($f,$sent['format']),'exact sent body');
 D::recordSent($f,$sent,$sent['messages']);++$count;
 $budget=strlen($sent['user_json'])+strlen($sent['system_text']);
 B::prepareSend($f,$pack,$expand,fn($fmt,$user,$sys)=>strlen($user)+strlen($sys)<=$budget,$live);++$count;
 denied(fn()=>B::prepareSend($f,$pack,$expand,fn($fmt,$user,$sys)=>strlen($user)+strlen($sys)<$budget,$live),'W2_SEND_BUDGET');
 $tampered=$sent['messages'];$tampered[1]['content'].=' ';denied(fn()=>D::recordSent($f,$sent,$tampered),'W9_OUTBOUND_MESSAGES');
 if($role==='1st'){
  good($sent['user_json']===$f->raw() && str_contains($sent['user_json'],'"source_registry"'),'named keys preserved');
  denied(fn()=>B::prepareSend($f,$pack,$expand,fn()=>true,$live,true,null,'SOURCE_TABLE_V1'),'W20_SEND_POLICY');
  denied(fn()=>D::record($f,'SOURCE_TABLE_V1',0,D::systemFor($in,'SOURCE_TABLE_V1'),D::inputBody($f,'SOURCE_TABLE_V1')),'W20_SEND_POLICY');
  $calls=0;$noPack=function($x)use(&$calls){++$calls;throw new RuntimeException('must not pack');};
  B::prepareSend($f,$noPack,$expand,fn()=>true,$live);good($calls===0,'full never attempts compressed path');
 }
 $first=r4receipt($f,'synthetic response',0,false);$first['sent_request']=$sent['sent_request'];
 $grant=r5scope($first,[5]);$diag=r9diagnostics();$repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diag,
   'inspection_sha256'=>D::inspectionSeal($f,$first['response_hash'],$grant,$diag)];
 $fixed=B::prepareSend($f,$pack,$expand,fn()=>true,$live,false,$repair);
 good($fixed['format']===$sent['format'] && $fixed['system_text']===$sent['system_text'],'correction keeps format and revision');
 $input=json_decode($fixed['user_json'],true)['input'];
 good(($role==='1st')===isset($input['source_registry']),'correction data shape');
 if($role!=='1st')continue;
 $reply=json_decode(file_get_contents($dir.'/1st_output.json'),true);
 good(!M::issues($in,$reply),'valid metrics');
 $saved=M::fromResponse($in,$reply,str_repeat('a',64));good(M::publicValues($in,$saved)===['波乱度'=>3,'下位進入度'=>2,'大穴進入度'=>2],'public projection preserves existing Japanese API keys');
 foreach(M::KEYS as $key){
  $x=$reply;$x['race_metrics'][$key]['value']=2.0;good(!M::issues($in,$x),'integral JSON number');
  foreach([0,6,-1,'3',3.2,true,[],['value'=>3]] as $bad){$x=$reply;$x['race_metrics'][$key]['value']=$bad;good(isset(M::issues($in,$x)[$key]),'bad value '.$key);}
  foreach([1,2,3,4,5] as $n){$x=$reply;$x['race_metrics'][$key]['value']=$n;$issue=M::issues($in,$x);good(($key==='big_gap_entry'&&$n>=3)?($issue[$key]??null)==='W20_METRIC_CONTINUITY':!isset($issue[$key]),'rating boundary '.$key);}
  $x=$reply;$x['race_metrics'][$key]=['value'=>null,'source_refs'=>[],'reason'=>'資料不足'];good(!M::issues($in,$x),'explicit unknown');
  $x=$reply;$x['race_metrics'][$key]['reason']='　';good(isset(M::issues($in,$x)[$key]),'invisible reason');
  $x=$reply;$x['race_metrics'][$key]['source_refs']=['missing'];good(isset(M::issues($in,$x)[$key]),'invented ref');
  $x=$reply;$x['race_metrics'][$key]['source_refs'][]=$x['race_metrics'][$key]['source_refs'][0];good(isset(M::issues($in,$x)[$key]),'duplicate ref');
  $x=$reply;unset($x['race_metrics'][$key]);$z=M::fromResponse($in,$x,str_repeat('a',64));good($z['values'][$key]===null && $z['status'][$key]==='INVALID','missing isolated');
 }
 $x=$reply;$x['race_metrics']['lower_entry']['source_refs']=$reply['race_metrics']['big_gap_entry']['source_refs'];good((M::issues($in,$x)['lower_entry']??null)==='W20_METRIC_COVERAGE','popularity not horse number');
 $x=$reply;$x['race_metrics']['big_gap_entry']['value']=4;$x['race_metrics']['big_gap_entry']['source_refs']=['h5_place_min_12','h5_place_min_9','h5_place_min_6'];
 $source=$in;foreach([12=>8,9=>6,6=>4] as $s=>$v)$source['source_registry']['h5_place_min_'.$s]['value']=$v;
 good(!M::issues($source,$x),'continuous place support');
 $source['source_registry']['h5_place_min_9']['value']=4;good((M::issues($source,$x)['big_gap_entry']??null)==='W20_METRIC_CONTINUITY','flat does not continue');
 foreach ([['1.00000000000000000002','1.00000000000000000001','1'],['1e100','1e99','1e98'],['1e-98','1e-99','1e-100']] as $series) {
  $source=$in;
  foreach (array_combine([12,9,6],$series) as $slot=>$decimal) {
   $decoded=J::decode('{"v":'.$decimal.'}');$source['source_registry']['h5_place_min_'.$slot]['value']=F::typedValues($decoded)['v'];
  }
  good(!M::issues($source,$x),'exact decimal continuity');
 }
 $long=$reply;$long['race_metrics']['wave_level']['reason']=str_repeat('あ',501);
 good((M::issues($in,$long)['wave_level']??null)==='W20_METRIC_REASON','reason length');
 $parsed=J::decode(json_encode($long));denied(fn()=>S::validate('output',$parsed['value'],$parsed['map']),'W3_SCHEMA_output');
 $source=$in;$source['source_registry']['h10_place_min_6']['race_id']='foreign';good((M::issues($source,$reply)['lower_entry']??null)==='W20_METRIC_SOURCE','foreign fact');
 $source=$in;$source['source_registry']['h10_place_min_6']['value']=null;good((M::issues($source,$reply)['lower_entry']??null)==='W20_METRIC_SOURCE','null fact');
 M::checkCorrection($in,$reply,$reply);++$count;
 $x=$reply;$x['race_metrics']['wave_level']['value']=4;denied(fn()=>M::checkCorrection($in,$reply,$x),'W20_METRIC_CORRECTION_SCOPE');
 $bad=$reply;$bad['race_metrics']['wave_level']['value']=0;M::checkCorrection($in,$bad,$reply);++$count;
 $unknown=$reply;$unknown['race_metrics']['wave_level']=['value'=>null,'source_refs'=>[],'reason'=>'資料不足'];denied(fn()=>M::checkCorrection($in,$unknown,$reply),'W20_METRIC_CORRECTION_SCOPE');
 $x=$reply;$x['ai_role']='2nd';good(isset(M::issues(array_replace($in,['ai_role'=>'2nd']),$x)['race_metrics']),'2nd metrics rejected');
 $parsed=J::decode(json_encode($x));denied(fn()=>S::validate('output',$parsed['value'],$parsed['map']),'W3_SCHEMA_output');
 $bad=$reply;$bad['race_metrics']['lower_entry']['value']='2';
 $raw="<<<BAGANRIKI_V041_R8_BEGIN>>>\n".json_encode($bad,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)."\n<<<BAGANRIKI_V041_R8_END>>>";
 $selected=\App\Services\Baganriki\CandidateSelectionW2::verifyOriginal($f,$raw,hash('sha256',$raw));
 good($selected['public_candidates']===$reply['public_candidates'] && $selected['public_proof_hash']===null && $selected['race_metrics']['values']['lower_entry']===null,'direct original replay isolates auxiliary field');
}
echo "PASS $count R15.20 format, budget, metric, correction and source checks\n";

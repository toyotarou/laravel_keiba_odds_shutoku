<?php
declare(strict_types=1);
use App\Services\Baganriki\FrozenInputW2 as F;
use App\Services\Baganriki\ExpectedBridgeW2 as B;
use App\Services\Baganriki\DispatchW2 as D;
use App\Services\Baganriki\RaceMetricsW2 as M;
use App\Http\Controllers\Api\BaganrikiPrompt as C;
use App\Http\Controllers\Api\BaganrikiJson as J;
require $argv[1];require __DIR__.'/../php/ExpectedBridgeW2.php';require __DIR__.'/r4_fixtures.php';
$dir=$argv[2];$count=0;$sizes=[];
function good(bool $test,string $name):void {global $count;if(!$test)throw new RuntimeException($name);++$count;}
function denied(callable $op,string $code):void {try{$op();}catch(InvalidArgumentException $e){good($e->getMessage()===$code,$code.' != '.$e->getMessage());return;}throw new RuntimeException('accepted '.$code);}
$proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true);
$pack=static fn($x)=>C::compactWire($x)['wire'];$expand=static fn($x)=>C::expandWire($x)['input'];
foreach(['1st','2nd'] as $role){
 $raw=file_get_contents($dir.'/'.$role.'_input.json');$in=json_decode($raw,true);$common=C::commonHash($in);
 $f=F::fromStored($common['json'],$raw,$common['hash'],$role,$proof);
 good($in['prompt_text_revision']==='COMPACT_TEXT_20261109_05','new revision reached frozen input');
 good(M::enabled($in),'new revision retains metric capability');
 $live=static fn()=>r4live($f,r4time($in['input_freeze_at'],'+5 seconds'));
 $budgetCalls=0;$seen=[];$budget=function($fmt,$user,$system)use(&$budgetCalls,&$seen){++$budgetCalls;$seen=[$fmt,$user,$system];return true;};
 $sent=B::prepareSend($f,$pack,$expand,$budget,$live);
 good($sent['format']==='SOURCE_TABLE_V1','both roles compressed');
 good($budgetCalls===1 && $seen===[$sent['format'],$sent['user_json'],$sent['system_text']],'exact budget request once');
 good(D::recordSent($f,$sent,$sent['messages'])===$sent['sent_request'],'exact provider binding');
 good($sent['fallback_reason']===null && str_contains($sent['system_text'],'【送信形式 SOURCE_TABLE_V1】'),'matching format instructions');
 $wire=F::typedValues(J::decode($sent['user_json']));$restored=B::expand($wire,$expand);
 $same=J::sameJson($raw,$f->transportJson($restored),true);good($same['ok'] && $same['same'],'typed complete roundtrip');
 $column=array_search('source_ref',$wire['source_table']['columns'],true);
 good(array_column($wire['source_table']['rows'],$column)===array_keys(F::checked($f)['source_registry']),'every original name retained');
 $mapsMatch=true;
 foreach(['expected_v01','input_indices','coverage'] as $key)
  $mapsMatch=$mapsMatch && F::nativeJson($restored[$key])===F::nativeJson($in[$key]);
 good($mapsMatch,'expected packet and reference maps retained with JSON object order ignored');
 $sizes[$role]=['full_user_bytes'=>strlen($raw),'compact_user_bytes'=>strlen($sent['user_json']),'system_bytes'=>strlen($sent['system_text'])];
 good(strlen($sent['user_json'])<strlen($raw),'synthetic input compressed');
 denied(fn()=>B::prepareSend($f,$pack,$expand,fn()=>false,$live),'W2_SEND_BUDGET');
 denied(fn()=>B::prepareSend($f,$pack,$expand,fn()=>true,$live,true,null,'FULL'),'W20_SEND_POLICY');
 denied(fn()=>D::record($f,'FULL',0,D::systemFor($in,'FULL'),$raw),'W20_SEND_POLICY');
 $badMessages=$sent['messages'];$badMessages[1]['content'].=' ';
 denied(fn()=>D::recordSent($f,$sent,$badMessages),'W9_OUTBOUND_MESSAGES');
 $first=r4receipt($f,'synthetic response',0,false);$first['sent_request']=$sent['sent_request'];
 $scope=r5scope($first,[5]);$diagnostics=r9diagnostics();
 $repair=['first_receipt'=>$first,'repair_scope'=>$scope,'diagnostics'=>$diagnostics,
   'inspection_sha256'=>D::inspectionSeal($f,$first['response_hash'],$scope,$diagnostics)];
 $fixed=B::prepareSend($f,$pack,$expand,fn()=>true,$live,false,$repair);
 good($fixed['format']===$sent['format'] && $fixed['system_text']===$sent['system_text'],'repair retains format and text');
 good(json_decode($fixed['user_json'],true)['input']===json_decode($sent['user_json'],true),'repair keeps original wire input');
 $checkedScope=\App\Services\Baganriki\AttemptWindowW2::scope($scope,$first['response_hash'],$in);
 D::verifyReceipt($f,$fixed['sent_request'],1,$first['raw'],$checkedScope,$diagnostics);++$count;
 denied(fn()=>B::prepareSend($f,$pack,$expand,fn()=>true,$live,true,$repair,'FULL'),'W20_SEND_POLICY');
 foreach(['drop_row','change_value','numeric_string','extra_field'] as $change){
  $bad=function($x)use($pack,$change){$wire=$pack($x);
   if($change==='drop_row')array_pop($wire['source_table']['rows']);
   if(in_array($change,['change_value','numeric_string'],true)){
    $i=array_search('value',$wire['source_table']['columns'],true);
    foreach($wire['source_table']['rows'] as &$row)if(is_int($row[$i])||is_float($row[$i])){$row[$i]=$change==='change_value'?999:(string)$row[$i];break;}unset($row);
   }
   if($change==='extra_field')$wire['extra']='unapproved';return $wire;};
  $calls=0;$budget=function()use(&$calls){++$calls;return true;};
  denied(fn()=>B::prepareSend($f,$bad,$expand,$budget,$live,true),'W2_FULL_NOT_ALLOWED');
  good($calls===0,'failed compression never budgeted or dispatched');
  denied(fn()=>B::prepareSend($f,$bad,$expand,fn()=>true,$live,true,$repair),'W2_FULL_NOT_ALLOWED');
 }
 $reply=json_decode(file_get_contents($dir.'/'.$role.'_output.json'),true);
 good(!M::issues($in,$reply),'matching role output and metrics accepted');
 if($role==='1st'){
  good(M::publicValues($in,M::fromResponse($in,$reply,str_repeat('a',64)))===['波乱度'=>3,'下位進入度'=>2,'大穴進入度'=>2],'three display values preserved');
  $bad=$reply;$bad['race_metrics']['lower_entry']['value']='2';
  good(M::fromResponse($in,$bad,str_repeat('a',64))['values']===['wave_level'=>3,'lower_entry'=>null,'big_gap_entry'=>2],'single invalid metric isolated');
 }
}
denied(fn()=>D::transportPolicy(['ai_role'=>'1st','prompt_text_revision'=>'UNDECLARED_REVISION']),'W22_TRANSPORT_POLICY');
good(D::transportPolicy(['ai_role'=>'1st','prompt_text_revision'=>'COMPACT_TEXT_20261109_04'])['required']==='FULL','old first retains FULL');
good(D::transportPolicy(['ai_role'=>'2nd','prompt_text_revision'=>'COMPACT_TEXT_20261109_04'])['required']===null,'old second receipts still accept their saved format');
echo json_encode(['synthetic_message_bytes'=>$sizes],JSON_UNESCAPED_UNICODE)."\n";
echo "PASS $count R15.22 compression, exact input, budget, correction, role and metric checks\n";

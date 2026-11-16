<?php
declare(strict_types=1);
use App\Services\Baganriki\FrozenInputW2 as F;
use App\Services\Baganriki\ExpectedBridgeW2 as B;
use App\Services\Baganriki\DispatchW2 as D;
use App\Services\Baganriki\RaceMetricsW2 as M;
use App\Services\Baganriki\SourceTableV2W2 as V2;
use App\Services\Baganriki\AttemptWindowW2 as W;
use App\Http\Controllers\Api\BaganrikiPrompt as C;
use App\Http\Controllers\Api\BaganrikiJson as J;
require $argv[1];require __DIR__.'/../php/ExpectedBridgeW2.php';require __DIR__.'/r4_fixtures.php';
$dir=$argv[2];$count=0;$sizes=[];
function good(bool $ok,string $label):void {global $count;if(!$ok)throw new RuntimeException($label);++$count;}
function denied(callable $op,string $code):void {try{$op();}catch(InvalidArgumentException $e){good($e->getMessage()===$code,$code.' != '.$e->getMessage());return;}throw new RuntimeException('accepted '.$code);}
function freeze(array $in,?string $token=null):F {
 global $proof;
 $common=$in;foreach(['snapshot_hash','ai_role','role_cap','role_quota'] as $k)unset($common[$k]);
 $raw=F::nativeJson($common);if($token!==null)$raw=str_replace('"EXACT_NUMERIC_TOKEN"',$token,$raw);
 $in['snapshot_hash']=hash('sha256',$raw);$role=F::nativeJson($in);if($token!==null)$role=str_replace('"EXACT_NUMERIC_TOKEN"',$token,$role);
 return F::fromStored($raw,$role,$in['snapshot_hash'],$in['ai_role'],$proof);
}
function state(F $f):array {return r4live($f,r4time(F::checked($f)['input_freeze_at'],'+5 seconds'));}
function sent(F $f):array {
 $unused=static function(){throw new RuntimeException('V2 unexpectedly called a V1 application codec');};
 return B::prepareSend($f,$unused,$unused,fn()=>true,fn()=>state($f));
}
function compare(F $f,array $packet,string $label):array {
 $decoded=J::decode($packet['user_json']);good($decoded['ok'],$label.' valid JSON');
 $wire=F::typedValues($decoded);$full=V2::expand($wire);
 $same=J::sameJson($f->raw(),$f->transportJson($full),true);good($same['ok'] && $same['same'],$label.' exact typed roundtrip');
 good($packet['format']===V2::FORMAT && $wire['source_table']['encoding']===V2::FORMAT,$label.' V2 format');
 good(str_contains($packet['system_text'],'【送信形式 SOURCE_TABLE_V2】') && !str_contains($packet['system_text'],'【送信形式 SOURCE_TABLE_V1】'),$label.' correct addendum');
 D::verifyReceipt($f,$packet['sent_request'],0,null,null,null);++$GLOBALS['count'];
 good(D::recordSent($f,$packet,$packet['messages'])===$packet['sent_request'],$label.' provider request binding');
 return $wire;
}
$proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true);
$pack=static fn($x)=>C::compactWire($x)['wire'];$expand=static fn($x)=>C::expandWire($x)['input'];
foreach(['1st','2nd'] as $role){
 $base=json_decode(file_get_contents($dir.'/'.$role.'_input.json'),true);
 good($base['prompt_text_revision']==='COMPACT_TEXT_20261109_06' && M::enabled($base),'new revision capability');
 $refs=[];foreach($base['source_registry'] as $id=>$s)if($s['field']==='past_performance')$refs[]=$id;
 sort($refs,SORT_STRING);good(count($refs)>2,'fixture has enough nonmarket references');
 foreach(['normal','coverage_sorted','coverage_reversed','coverage_partial','extra_index','renamed_index','subset_index','combined'] as $case){
  $in=$base;
  if(in_array($case,['coverage_sorted','coverage_reversed','coverage_partial','combined'],true))
   $in['coverage']['past_runs']=['status'=>'INCLUDED','source_refs'=>$case==='coverage_sorted'?$refs:($case==='coverage_partial'?array_slice(array_reverse($refs),0,2):array_reverse($refs))];
  if(in_array($case,['extra_index','combined'],true))$in['input_indices']['extra_lookup']=[$refs[1],$refs[0]];
  if($case==='renamed_index'){
   $key=C::indexKey($in['source_registry'][$refs[0]]);$in['input_indices']['named_lookup']=$in['input_indices'][$key];unset($in['input_indices'][$key]);
  }
  if($case==='subset_index')unset($in['input_indices'][C::indexKey($in['source_registry'][$refs[0]])]);
  $f=freeze($in);$s=sent($f);$wire=compare($f,$s,$role.' '.$case);
  $restored=V2::expand($wire);
  good($restored['coverage']===F::checked($f)['coverage'],'coverage order and boundaries retained');
  good(C::canonical($restored['input_indices'])===C::canonical($in['input_indices']),'exact named index retained');
  if(in_array($case,['extra_index','renamed_index','subset_index','combined'],true))
   good(isset($wire['input_indices']) && !isset($wire['input_indices_derived']),'non-derivable map explicit');
  else good(!isset($wire['input_indices']) && ($wire['input_indices_derived']??false)===true,'derivable map compact');
  $first=r4receipt($f,'synthetic response',0,false);$first['sent_request']=$s['sent_request'];
  $grant=r5scope($first,[5]);$diagnostics=r9diagnostics();
  $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diagnostics,
   'inspection_sha256'=>D::inspectionSeal($f,$first['response_hash'],$grant,$diagnostics)];
  $fix=B::prepareSend($f,$pack,$expand,fn()=>true,fn()=>state($f),false,$repair);
  good($fix['format']===$s['format'] && $fix['system_text']===$s['system_text'],'repair text and format retained');
  $json=J::decode($fix['user_json']);$fixInput=F::typedValues($json)['input'];
  good($f->transportJson($fixInput)===$s['user_json'],'repair input bytes retained');
  D::verifyReceipt($f,$fix['sent_request'],1,$first['raw'],W::scope($grant,$first['response_hash'],F::checked($f)),$diagnostics);++$count;
  if(in_array($case,['coverage_reversed','extra_index','combined'],true)){
   $old=$in;$old['prompt_text_revision']='COMPACT_TEXT_20261109_05';$of=freeze($old);
   denied(fn()=>B::prepareSend($of,$pack,$expand,fn()=>true,fn()=>state($of)),'W2_FULL_NOT_ALLOWED');
  }
  if($case==='normal'){
   $sizes[$role]=['full_user_bytes'=>strlen($f->raw()),'compact_user_bytes'=>strlen($s['user_json']),'system_bytes'=>strlen($s['system_text'])];
   good(strlen($s['user_json'])<strlen($f->raw()),'normal fixture compressed');
   $col=array_search('source_ref',$wire['source_table']['columns'],true);
   good(array_column($wire['source_table']['rows'],$col)===array_keys(F::checked($f)['source_registry']),'every original source name retained');
   foreach(['FULL','SOURCE_TABLE_V1'] as $format)
    denied(fn()=>B::prepareSend($f,$pack,$expand,fn()=>true,fn()=>state($f),true,null,$format),'W20_SEND_POLICY');
   denied(fn()=>B::prepareSend($f,$pack,$expand,fn()=>false,fn()=>state($f)),'W2_SEND_BUDGET');
   // A callback may inspect exact texts, but cannot rewrite them after budget approval.
   denied(fn()=>B::prepareSend($f,$pack,$expand,function(&$fmt,&$body,&$sys){$body.=' ';return true;},fn()=>state($f)),'W9_BUDGET_MUTATED_REQUEST');
   $seen=null;
   B::prepareSend($f,$pack,$expand,function($fmt,$body,$sys)use(&$seen){$seen=[$fmt,$body,$sys];return true;},fn()=>state($f));
   good($seen===[$s['format'],$s['user_json'],$s['system_text']],'actual complete texts budgeted');
   foreach(['drop_row','numeric_string','change_value','extra_field','coverage_change'] as $mutation){
    $bad=$wire;
    if($mutation==='drop_row')array_pop($bad['source_table']['rows']);
    if(in_array($mutation,['numeric_string','change_value'],true)){
     $i=array_search('value',$bad['source_table']['columns'],true);
     foreach($bad['source_table']['rows'] as &$row)if(is_int($row[$i])||is_float($row[$i])){$row[$i]=$mutation==='numeric_string'?(string)$row[$i]:999;break;}unset($row);
    }
    if($mutation==='extra_field')$bad['unapproved']=true;
    if($mutation==='coverage_change')$bad['coverage']['past_runs']=['status'=>'INCLUDED','source_refs'=>$refs];
    $record=D::record($f,V2::FORMAT,0,$s['system_text'],$f->transportJson($bad));
    denied(fn()=>D::verifyReceipt($f,$record,0,null,null,null),'W9_SENT_INPUT');
   }
   foreach(['v1_then_v2','v2_then_v1'] as $order){
    $cf=freeze($in);$formats=$order==='v1_then_v2'?['SOURCE_TABLE_V1',V2::FORMAT]:[V2::FORMAT,'SOURCE_TABLE_V1'];
    foreach($formats as $format)good(json_decode(D::inputBody($cf,$format),true)['source_table']['encoding']===$format,'cache is format-specific');
    foreach(array_reverse($formats) as $format)good(json_decode(D::inputBody($cf,$format),true)['source_table']['encoding']===$format,'cache replay is format-specific');
   }
  }
 }
 // Do not let a new worker select a metrics-requiring system for an unversioned frozen input.
 $legacy=$base;unset($legacy['prompt_text_revision']);$f=freeze($legacy);$calls=0;
 $observe=function()use(&$calls){++$calls;return true;};
 denied(fn()=>B::prepareSend($f,$observe,$observe,$observe,fn()=>state($f)),'W23_INITIAL_TEXT_REVISION');
 good($calls===0,'unversioned initial rejected before codec or budget');
 good(!M::enabled(F::checked($f)),'legacy capability not silently upgraded');
 // Stored old unversioned receipts can still be verified; cross-process tests also cover their repairs.
 foreach(['FULL','SOURCE_TABLE_V1'] as $format){
  $record=D::record($f,$format,0,D::system($role,$format,'ORIGINAL_R15_18'),D::inputBody($f,$format));
  D::verifyReceipt($f,$record,0,null,null,null);++$count;
 }
 foreach(['1.000000000000000000001','9007199254740993','1e-100','0','0.0'] as $token){
  $in=$base;$in['source_registry'][$refs[0]]['value']='EXACT_NUMERIC_TOKEN';$f=freeze($in,$token);$s=sent($f);
  compare($f,$s,$role.' exact number '.$token);
 }
 foreach(['123','0','01','普通の参照'] as $name){
  $in=$base;$old=$refs[0];$entry=$in['source_registry'][$old];unset($in['source_registry'][$old]);
  $entry['source_ref']=$name;$in['source_registry'][$name]=$entry;
  $in['source_registry']['derived_acceptance']=array_replace($entry,['source_ref'=>'derived_acceptance','source_kind'=>'DERIVED',
   'parents'=>[$name],'formula_id'=>'identity','formula_version'=>'1','computed_at'=>$in['input_freeze_at']]);
  $in['input_indices']=C::indicesFor($in['source_registry']);$f=freeze($in);$s=sent($f);
  $wire=compare($f,$s,$role.' parent reference '.$name);
  good(V2::expand($wire)['source_registry']['derived_acceptance']['parents']===[$name],'parent name remains a string');
  $first=r4receipt($f,'synthetic response',0,false);$first['sent_request']=$s['sent_request'];
  $grant=r5scope($first,[5]);$diagnostics=r9diagnostics();
  $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diagnostics,
   'inspection_sha256'=>D::inspectionSeal($f,$first['response_hash'],$grant,$diagnostics)];
  $fix=B::prepareSend($f,$pack,$expand,fn()=>true,fn()=>state($f),false,$repair);
  good($fix['format']===$s['format'] && $fix['system_text']===$s['system_text'],'parent reference correction text and format');
  good($f->transportJson(F::typedValues(J::decode($fix['user_json']))['input'])===$s['user_json'],'parent reference correction input');
  D::verifyReceipt($f,$fix['sent_request'],1,$first['raw'],W::scope($grant,$first['response_hash'],F::checked($f)),$diagnostics);++$count;
  if(in_array($name,['123','0'],true)){
   $old=$in;$old['prompt_text_revision']='COMPACT_TEXT_20261109_05';$of=freeze($old);
   denied(fn()=>B::prepareSend($of,$pack,$expand,fn()=>true,fn()=>state($of)),'W2_FULL_NOT_ALLOWED');
  }
 }
}
echo json_encode(['synthetic_message_bytes'=>$sizes],JSON_UNESCAPED_UNICODE)."\n";
echo "PASS $count R15.23 ordered coverage, named indices, typed decimals, cache, budget, correction and version checks\n";

<?php
declare(strict_types=1);
require $argv[1];require __DIR__.'/../php/ExpectedBridgeW2.php';require __DIR__.'/r4_fixtures.php';
use App\Services\Baganriki\FrozenInputW2 as F;
use App\Services\Baganriki\ExpectedBridgeW2 as B;
use App\Services\Baganriki\DispatchW2 as D;
use App\Services\Baganriki\ExactSourceTableW2 as E;
use App\Services\Baganriki\MarketStateW2 as M;
use App\Services\Baganriki\AttemptWindowW2 as W;
use App\Http\Controllers\Api\BaganrikiPrompt as C;
use App\Http\Controllers\Api\BaganrikiAllocation as A;
use App\Http\Controllers\Api\BaganrikiJson as J;
$count=0;$cases=0;$saved=[];$root=__DIR__.'/..';
function good(bool $ok,string $name):void {global $count;if(!$ok)throw new RuntimeException($name);++$count;}
function deny(callable $op,string $code):void {try{$op();}catch(InvalidArgumentException $e){good($e->getMessage()===$code,$e->getMessage().' != '.$code);return;}throw new RuntimeException('accepted '.$code);}
function frozen(array $in,array $proof,?string $token=null):F {
 $in['source_registry']=(object)$in['source_registry'];$common=$in;
 foreach(['snapshot_hash','ai_role','role_cap','role_quota'] as $k)unset($common[$k]);
 $cr=F::nativeJson($common);if($token!==null)$cr=str_replace('"EXACT_TOKEN"',$token,$cr);
 $in['snapshot_hash']=hash('sha256',$cr);$raw=F::nativeJson($in);if($token!==null)$raw=str_replace('"EXACT_TOKEN"',$token,$raw);
 return F::fromStored($cr,$raw,$in['snapshot_hash'],$in['ai_role'],$proof);
}
function sparse(array $base,int $size,bool $numeric,bool $reversed):array {
 $role=$base['ai_role'];$extra=null;
 foreach($base['source_registry'] as $e)if($e['field']==='past_performance'){$extra=$e;break;}
 foreach(['ai_role','role_cap','role_quota','snapshot_hash','expected_v01'] as $k)unset($base[$k]);
 $base['runners']=[['horse_no'=>1,'win_rank'=>1,'band'=>'A'],['horse_no'=>2,'win_rank'=>2,'band'=>'A']];$base['N']=2;
 $reg=[];
 foreach([21,6] as $slot)foreach([1,2] as $no){$e=$base['source_registry']["h{$no}_win_odds_{$slot}"];$id=($numeric?'':'ref').count($reg);$e['source_ref']=$id;$e['value']=$no+1;$reg[$id]=$e;}
 $base['field_catalog']=['win_odds'=>$base['field_catalog']['win_odds'],'past_performance'=>$base['field_catalog']['past_performance']];
 while(count($reg)<$size){
  $id=($numeric?'':'ref').count($reg);$field='past_performance_'.count($reg);
  $base['field_catalog'][$field]=$base['field_catalog']['past_performance'];
  $reg[$id]=array_replace($extra,['source_ref'=>$id,'field'=>$field,'horse_nos'=>[1],'applicable_horse_nos'=>[],'dependency_key'=>$id,'value'=>count($reg)]);
 }
 if($reversed)$reg=array_reverse($reg,true);
 $base['source_registry']=$reg;$base['input_indices']=C::indicesFor($reg);
 foreach([18,15,12,9] as $slot)$base['collection_audit'][$slot]=['status'=>'UNAVAILABLE','reason'=>'sparse synthetic fixture'];
 $calc=M::derive([['no'=>1,'rank'=>1,'win21'=>2,'win6'=>2],['no'=>2,'rank'=>2,'win21'=>3,'win6'=>3]]);unset($calc['formula_ranks']);$base=array_replace($base,$calc);$alloc=A::v04Allocation(2,$calc);
 foreach(['target_count','base_quota','quota','quota_adjustments'] as $k)$base[$k]=$alloc[$k];
 $current=['race_id'=>$base['race_id'],'N'=>2,'post_at'=>$base['time_contract']['anchor_at'],'input_freeze_at'=>$base['input_freeze_at'],
  'jurisdiction'=>'JRA','surface'=>'DIRT','race_type'=>'FLAT','distance_metres'=>1800,'class_group'=>'C1',
  'roster'=>[['horse_no'=>1,'horse_id'=>'H1'],['horse_no'=>2,'horse_id'=>'H2']],'batches'=>[]];
 foreach([6,21] as $slot){$a=$base['collection_audit'][$slot];$current['batches'][$slot]=['batch_id'=>$a['batch_id'],'requested_at'=>$a['request_started_at'],
  'received_at'=>$a['response_received_at'],'available_at'=>$a['available_at'],'quotes'=>[['horse_id'=>'H1','win'=>2],['horse_id'=>'H2','win'=>3]]];}
 $attached=B::attach($base,$current,null);
 return [$attached['common']+['ai_role'=>$role,'role_cap'=>2,'role_quota'=>$alloc['quota']],$attached['audit']['expected_proof']];
}
function exercise(F $f,string $name):array {
 global $cases;++$cases;$unused=static function(){throw new RuntimeException('new transport called V1 callback');};
 $live=fn()=>r4live($f,r4time(F::checked($f)['input_freeze_at'],'+5 seconds'));
 $s=B::prepareSend($f,$unused,$unused,fn()=>true,$live);
 good($s['format']==='SOURCE_TABLE_V2',$name.' format');
 $restored=E::expand($s['user_json']);$same=J::sameJson($f->raw(),$restored,true);
 good($same['ok']&&$same['same'],$name.' independent exact roundtrip');
 good(json_decode($restored)->source_registry instanceof stdClass,$name.' registry remains JSON object');
 $wire=json_decode($s['user_json']);good(is_array($wire->source_table->rows),$name.' table rows remain array');
 $j=J::decode($s['user_json']);$v=F::typedValues($j);
 $rc=array_search('source_ref',$v['source_table']['columns'],true);
 good(array_column($v['source_table']['rows'],$rc)===array_map('strval',array_keys(F::checked($f)['source_registry'])),$name.' reference names and order');
 D::verifyReceipt($f,$s['sent_request'],0,null,null,null);++$GLOBALS['count'];
 good(D::recordSent($f,$s,$s['messages'])===$s['sent_request'],$name.' actual messages');
 $first=r4receipt($f,'synthetic initial response',0,false);$first['sent_request']=$s['sent_request'];$grant=r5scope($first,[1]);$diag=r9diagnostics();
 $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diag,'inspection_sha256'=>D::inspectionSeal($f,$first['response_hash'],$grant,$diag)];
 $fix=B::prepareSend($f,$unused,$unused,fn()=>true,$live,false,$repair);
 $scope=W::scope($grant,$first['response_hash'],F::checked($f));
 good($fix['system_text']===$s['system_text'] && $fix['format']===$s['format'],$name.' correction version');
 good($fix['user_json']===D::correctionBody($f,$s['user_json'],$first['raw'],$scope,$diag),$name.' correction input bytes');
 D::verifyReceipt($f,$fix['sent_request'],1,$first['raw'],$scope,$diag);++$GLOBALS['count'];
 deny(fn()=>B::prepareSend($f,$unused,$unused,fn()=>false,$live),'W2_SEND_BUDGET');
 foreach(['string_number','changed_value','object_array','missing_row'] as $mutation){
  $bad=$v;$vc=array_search('value',$bad['source_table']['columns'],true);$hc=array_search('horse_nos',$bad['source_table']['columns'],true);
  if($mutation==='missing_row')array_pop($bad['source_table']['rows']);
  elseif($mutation==='object_array')$bad['source_table']['rows'][0][$hc]=(object)$bad['source_table']['rows'][0][$hc];
  else $bad['source_table']['rows'][0][$vc]=$mutation==='string_number'?'2':999;
  $raw=F::nativeJson($bad);$same=J::sameJson($f->raw(),E::expand($raw),true);
  good(!$same['same'],$name.' independent rejection '.$mutation);
  $record=D::record($f,'SOURCE_TABLE_V2',0,$s['system_text'],$raw);
  deny(fn()=>D::verifyReceipt($f,$record,0,null,null,null),'W9_SENT_INPUT');
 }
 return $s;
}
$proof=json_decode(file_get_contents($root.'/examples/synthetic_revision06/expected_proof.json'),true);
foreach(['1st','2nd'] as $role){
 $base=json_decode(file_get_contents($root.'/examples/synthetic_revision06/'.$role.'_frozen_input.json'),true);$base['prompt_text_revision']=E::REVISION;
 foreach([4,5,9,10,12] as $size)foreach([false,true] as $numeric)foreach([false,true] as $reverse){
  [$in,$smallProof]=sparse($base,$size,$numeric,$reverse);$f=frozen($in,$smallProof);
  good(F::checked($f)['selection_enabled']===true,'sparse fixture really enabled');exercise($f,$role.' sparse '.$size.' numeric '.$numeric.' reverse '.$reverse);
 }
 $ref=null;foreach($base['source_registry'] as $id=>$e)if($e['field']==='past_performance'){$ref=$id;break;}
 foreach(['combined','0','01','123','普通の参照'] as $variant){
  $in=$base;
  if($variant==='combined'){
   $refs=[];foreach($in['source_registry'] as $id=>$e)if($e['field']==='past_performance')$refs[]=$id;
   $in['coverage']['past_runs']=['status'=>'INCLUDED','source_refs'=>array_reverse($refs)];
   $in['input_indices']['追加索引']=[$refs[1],$refs[0]];
  }else{
   $entry=$in['source_registry'][$ref];unset($in['source_registry'][$ref]);$entry['source_ref']=$variant;$in['source_registry'][$variant]=$entry;
   $in['source_registry']['derived_acceptance']=array_replace($entry,['source_ref'=>'derived_acceptance','source_kind'=>'DERIVED',
    'parents'=>[$variant],'formula_id'=>'identity','formula_version'=>'1','computed_at'=>$in['input_freeze_at']]);
   $in['input_indices']=C::indicesFor($in['source_registry']);
  }
  exercise(frozen($in,$proof),$role.' preserved V2 behaviour '.$variant);
 }
 foreach(['1e0','1E+1','0e0','1e-1','1.50','-0.0','1.000000000000000000001','9007199254740993','1e-100','0','0.0'] as $token){
  $in=$base;$in['source_registry'][$ref]['value']='EXACT_TOKEN';$f=frozen($in,$proof,$token);$s=exercise($f,$role.' number '.$token);
  $restored=J::decode(E::expand($s['user_json']));
  good($restored['map']['num'][J::pathKey(['source_registry',$ref,'value'])]['raw']===$token,'original number spelling retained '.$token);
 }
 // Previously delivered records must remain valid byte-for-byte with revision 07 active.
 foreach(['05','06'] as $revision){
  $folder=$root.'/examples/synthetic_revision'.$revision;$in=json_decode(file_get_contents($folder.'/'.$role.'_frozen_input.json'),true);
  $oldProof=json_decode(file_get_contents($folder.'/expected_proof.json'),true);$f=frozen($in,$oldProof);
  $sent=json_decode(file_get_contents($folder.'/'.$role.'_send_record.json'),true);D::verifyReceipt($f,$sent,0,null,null,null);++$count;
  good(D::inputBody($f,$sent['format'])===$sent['user_json'],'delivered revision '.$revision.' receipt unchanged');
 }
}
echo "PASS $count R15.24 assertions across $cases initial/correction fixtures; sparse registries, exact number tokens, independent tamper rejection and delivered old receipts\n";

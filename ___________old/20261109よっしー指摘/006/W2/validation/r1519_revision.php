<?php
declare(strict_types=1);
use App\Services\Baganriki\FrozenInputW2 as F;
use App\Services\Baganriki\ExpectedPacketW2 as P;
use App\Services\Baganriki\MarketStateW2 as M;
use App\Services\Baganriki\DispatchW2 as D;
use App\Services\Baganriki\ExpectedBridgeW2 as B;
use App\Services\Baganriki\AttemptWindowW2 as W;
use App\Http\Controllers\Api\BaganrikiPrompt as C;
[$script,$root,$bootstrap,$fixtures,$mode,$saved]=$argv;
require $bootstrap;require $root.'/php/ExpectedBridgeW2.php';require $root.'/validation/r4_fixtures.php';
function j(array $a):string{return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
function frozen(array $in,array $proof):F{
 $w=json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR);$w['market_source_sha256']=M::verify($in);$proof=P::sealProof($w);
 $common=C::commonHash($in);$in['snapshot_hash']=$common['hash'];return F::fromStored($common['json'],j($in),$in['snapshot_hash'],$in['ai_role'],$proof);
}
function must(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
function deny(callable $call,string $code):void{try{$call();}catch(InvalidArgumentException $e){must($e->getMessage()===$code,'wrong error '.$e->getMessage());return;}throw new RuntimeException('accepted '.$code);}
$proof=json_decode(file_get_contents($fixtures.'/expected_proof.json'),true);$rows=[];$count=0;
if($mode==='verify')$rows=json_decode(file_get_contents($saved),true);
foreach(['1st','2nd'] as $role)foreach(['ORIGINAL_R15_18','COMPACT_TEXT_20261109_02','COMPACT_TEXT_20261109_03','COMPACT_TEXT_20261109_04'] as $rev)foreach(['FULL','SOURCE_TABLE_V1'] as $format){
 if($rev==='COMPACT_TEXT_20261109_04' && $role==='1st' && $format!=='FULL')continue;
 $in=json_decode(file_get_contents($fixtures.'/'.$role.'_input.json'),true);
 if(in_array($rev,['COMPACT_TEXT_20261109_03','COMPACT_TEXT_20261109_04'],true))$in['prompt_text_revision']=$rev;else unset($in['prompt_text_revision']);
 $f=frozen($in,$proof);$in=F::checked($f);$key=$role.'|'.$rev.'|'.$format;
 if($mode==='save'){$rows[$key]=D::record($f,$format,0,D::system($role,$format,$rev),D::inputBody($f,$format));continue;}
 $r=$rows[$key];D::verifyReceipt($f,$r,0,null,null,null);$count++;
 $wrong=$r;$wrong['request_sha256']=str_repeat('0',64);deny(fn()=>D::verifyReceipt($f,$wrong,0,null,null,null),'W9_SENT_HASH');$count++;
 deny(fn()=>D::record($f,$format,0,$r['system_text'].'altered',$r['user_json']),'W9_SENT_SYSTEM');$count++;
 deny(fn()=>D::record($f,$format,0,D::system($role==='1st'?'2nd':'1st',$format),$r['user_json']),'W9_SENT_SYSTEM');$count++;
 $receipt=r4receipt($f,'synthetic initial response',0,false);$receipt['sent_request']=$r;
 $grant=r5scope($receipt,[5]);$diagnostics=r9diagnostics();$seal=D::inspectionSeal($f,$receipt['response_hash'],$grant,$diagnostics);
 $state=r4live($f,r4time($in['input_freeze_at'],'+5 seconds'));
 $prepared=B::prepareSend($f,fn($v)=>C::compactWire($v)['wire'],fn($v)=>C::expandWire($v)['input'],fn()=>true,fn()=>$state,true,
   ['first_receipt'=>$receipt,'repair_scope'=>$grant,'diagnostics'=>$diagnostics,'inspection_sha256'=>$seal]);
 must($prepared['system_text']===$r['system_text'] && $prepared['format']===$format,'correction changed revision/format');$count++;
 $fix=r4receipt($f,'synthetic correction',1,false);$fix['sent_request']=$prepared['sent_request'];$fix['repair_scope']=$grant;$fix['repair_diagnostics']=$diagnostics;$fix['inspection_sha256']=$seal;
 [$ok,$bad]=W::receipts($f,[$receipt,$fix],W::current($f,$state));must(count($ok)===2 && !$bad,'correction receipt failed');$count++;
 if(!isset($in['prompt_text_revision'])){
  $fix['sent_request']=D::record($f,$format,1,D::system($role,$format),$prepared['user_json']);
  [$ok,$bad]=W::receipts($f,[$receipt,$fix],W::current($f,$state));must(count($ok)===1 && ($bad[1]??null)==='W19_FIX_TEXT_REVISION','unpinned correction switched text');$count++;
 }
 if($rev==='COMPACT_TEXT_20261109_03' && $format==='SOURCE_TABLE_V1'){
  $initial=B::prepareSend($f,fn($v)=>C::compactWire($v)['wire'],fn($v)=>C::expandWire($v)['input'],fn()=>true,fn()=>$state);
  must($initial['system_text']===$r['system_text'],'pinned new send switched');$count++;
 }
 if($rev==='COMPACT_TEXT_20261109_04' && $format===($role==='1st'?'FULL':'SOURCE_TABLE_V1')){
  $initial=B::prepareSend($f,fn($v)=>C::compactWire($v)['wire'],fn($v)=>C::expandWire($v)['input'],fn()=>true,fn()=>$state);
  must($initial['system_text']===$r['system_text'] && $initial['format']===$format,'04 pinned format/text after active revision changed');$count++;
 }
}
if($mode==='save')file_put_contents($saved,j($rows));else echo "PASS $count cross-process version, correction and tamper cases\n";

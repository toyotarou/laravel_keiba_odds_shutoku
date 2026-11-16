<?php
declare(strict_types=1);
require $argv[1];require __DIR__.'/../php/ExpectedBridgeW2.php';require __DIR__.'/r4_fixtures.php';
use App\Http\Controllers\Api\BaganrikiPrompt as C;
use App\Services\Baganriki\FrozenInputW2 as F;
use App\Services\Baganriki\ExpectedBridgeW2 as B;
use App\Services\Baganriki\DispatchW2 as D;
$fixtures=__DIR__.'/r1519_fixtures';$out=__DIR__.'/../examples/synthetic_revision07';
if(!is_dir($out))mkdir($out,0770,true);
$proof=json_decode(file_get_contents($fixtures.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);$sizes=[];
foreach(['1st','2nd'] as $role){
 $in=json_decode(file_get_contents($fixtures.'/'.$role.'_input.json'),true,512,JSON_THROW_ON_ERROR);
 $in['prompt_text_revision']='COMPACT_TEXT_20261109_07';$common=C::commonHash($in);$in['snapshot_hash']=$common['hash'];
 $f=F::fromStored($common['json'],F::nativeJson($in),$common['hash'],$role,$proof);
 $sent=B::prepareSend($f,fn($x)=>C::compactWire($x)['wire'],fn($x)=>C::expandWire($x)['input'],fn()=>true,
   fn()=>r4live($f,r4time($in['input_freeze_at'],'+5 seconds')));
 D::recordSent($f,$sent,$sent['messages']);D::verifyReceipt($f,$sent['sent_request'],0,null,null,null);
 file_put_contents($out.'/'.$role.'_frozen_input.json',$f->raw());
 file_put_contents($out.'/'.$role.'_user.json',$sent['user_json']);
 file_put_contents($out.'/'.$role.'_system.txt',$sent['system_text']);
 file_put_contents($out.'/'.$role.'_send_record.json',json_encode($sent['sent_request'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 $sizes[$role]=['full_bytes'=>strlen($f->raw()),'wire_bytes'=>strlen($sent['user_json']),'system_bytes'=>strlen($sent['system_text']),'format'=>$sent['format']];
}
file_put_contents($out.'/expected_proof.json',json_encode($proof,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
echo json_encode($sizes)."\n";

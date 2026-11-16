<?php
declare(strict_types=1);
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Http\Controllers\Api\BaganrikiResponse as Reply;
require_once __DIR__.'/../php/ExpectedBridgeW2.php';require $argv[1];require __DIR__.'/r4_fixtures.php';
$dir=$argv[2];$proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);$ready=[];
while (($line=fgets(STDIN))!==false) {
    $q=json_decode($line,true,512,JSON_THROW_ON_ERROR);$role=$q['role'];$format=$q['format'];$key=$role.$format;
    $pack=static function($v)use($format){if($format==='FULL')throw new InvalidArgumentException('test approved full');return Codec::compactWire($v)['wire'];};
    $expand=static fn($w)=>Codec::expandWire($w)['input'];
    if (!isset($ready[$key])) {
        $input=file_get_contents("$dir/{$role}_input.json");$in=json_decode($input,true);$common=Codec::commonHash($in);
        $f=Frozen::fromStored($common['json'],$input,$common['hash'],$role,$proof);$live=r4live($f);
        $init=Bridge::prepareSend($f,$pack,$expand,static fn()=>true,static fn()=>$live,$format==='FULL');
        $raw=Reply::BEGIN."\n".file_get_contents("$dir/{$role}_output.json")."\n".Reply::END;
        $first=r4receipt($f,$raw);$first['sent_request']=Dispatch::recordSent($f,$init,$init['messages']);
        $ready[$key]=[$f,$live,$first,$init];
    }
    [$f,$live,$first,$init]=$ready[$key];
    if (!isset($q['diagnostics'])) { $answer=['input'=>$init['user_json'],'system_hash'=>$init['system_sha256']]; }
    else {
        $grant=Window::repairGrant($first['response_hash'],$q['grant']);$diagnostics=$q['diagnostics'];
        $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diagnostics,'inspection_sha256'=>Dispatch::inspectionSeal($f,$first['response_hash'],$grant,$diagnostics)];
        $checked=null;
        $budget=static function($m,$u,$s)use($q,&$checked){$checked=[strlen($s)+strlen($u),hash('sha256',$u)];return $checked[0]<=$q['limit'];};
        try { $v=Bridge::prepareSend($f,$pack,$expand,$budget,static fn()=>$live,$format==='FULL',$repair);$actual=Dispatch::recordSent($f,$v,$v['messages']);$code='ACCEPTED'; }
        catch (InvalidArgumentException $err) { $code=$err->getMessage(); }
        $answer=['code'=>$code,'checked'=>$checked,'request_sha256'=>$code==='ACCEPTED'?$actual['request_sha256']:null];
    }
    echo json_encode($answer,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
}

<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiPrompt;
use InvalidArgumentException;

/** Exactly the two message texts sent by the R9 worker; both stay on the server for replay. */
final class DispatchW2
{
    private static function need(bool $ok,string $why):void { if (!$ok) throw new InvalidArgumentException($why); }

    private static function config():array
    {
        $raw=file_get_contents(__DIR__.'/../config/w2_contract.json');
        try { $cfg=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new InvalidArgumentException('W9_SYSTEM_VERSION',0,$e); }
        self::need(is_array($cfg) && ($cfg['prompt_version']??null)===CandidateSelectionW2::VERSION,'W9_SYSTEM_VERSION');
        return $cfg;
    }

    public static function system(string $role,string $format,?string $revision=null):string
    {
        self::need(in_array($role,['1st','2nd'],true) && in_array($format,['FULL','SOURCE_TABLE_V1'],true),'W9_DISPATCH_FORMAT');
        static $texts=[];
        $key=$role.'|'.$format.'|'.($revision??'active');
        if (!isset($texts[$key])) {
            $dir=__DIR__.'/../prompts/';$config=__DIR__.'/../config/w2_contract.json';
            $raw=is_readable($config)?file_get_contents($config):false;self::need(is_string($raw),'W9_SYSTEM_VERSION');
            try { $cfg=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new InvalidArgumentException('W9_SYSTEM_VERSION',0,$e); }
            self::need(($cfg['prompt_version']??null)===CandidateSelectionW2::VERSION,'W9_SYSTEM_VERSION');
            if ($revision!==null && $revision!==($cfg['active_text_revision']??null)) {
                self::need(preg_match('/^[A-Za-z0-9_-]+$/D',$revision)===1 && isset($cfg['archived_text_revisions'][$revision]),'W19_TEXT_REVISION');
                $cfg['system_file_sha256']=$cfg['archived_text_revisions'][$revision];
                $dir.='versions/'.$revision.'/';
            }
            $files=['prompt_'.$role.'.txt'];if ($format==='SOURCE_TABLE_V1') $files[]='SOURCE_TABLE_V1_ADDENDUM_V2.txt';
            $parts=[];
            foreach ($files as $name) {
                $body=is_readable($dir.$name)?file_get_contents($dir.$name):false;
                self::need(is_string($body) && hash('sha256',$body)===($cfg['system_file_sha256'][$name]??null),'W9_SYSTEM_FILE');
                $parts[]=$body;
            }
            $texts[$key]=implode("\n\n",$parts);
        }
        return $texts[$key];
    }

    public static function activeRevision():string
    {
        $cfg=self::config();$revision=$cfg['active_text_revision']??null;
        self::need(is_string($revision) && preg_match('/^[A-Za-z0-9_-]+$/D',$revision)===1,'W19_TEXT_REVISION');
        return $revision;
    }

    /** New snapshots carry an explicit revision inside the common hash. Legacy receipts use approved archives. */
    public static function systemFor(array $input,string $format):string
    {
        return self::system($input['ai_role'],$format,$input['prompt_text_revision']??null);
    }

    private static function knownSystem(array $in,string $format,string $text):bool
    {
        if (isset($in['prompt_text_revision'])) return $text===self::systemFor($in,$format);
        if ($text===self::system($in['ai_role'],$format)) return true;
        foreach (array_keys(self::config()['archived_text_revisions']??[]) as $revision)
            if ($text===self::system($in['ai_role'],$format,$revision)) return true;
        return false;
    }

    /** The submitted codec is the authority for this format's columns, dictionaries and omitted fields. */
    public static function inputBody(FrozenInputW2 $frozen,string $format):string
    {
        if ($format==='FULL') return $frozen->raw();
        self::need($format==='SOURCE_TABLE_V1','W9_DISPATCH_FORMAT');
        static $cache=null;$cache??=new \WeakMap();
        if (isset($cache[$frozen])) return $cache[$frozen];
        $tree=FrozenInputW2::checked($frozen);$packet=$tree['expected_v01'];$revision=$tree['prompt_text_revision']??null;
        unset($tree['expected_v01'],$tree['prompt_text_revision']);
        $reference=BaganrikiPrompt::compactWire($tree);
        self::need(($reference['ok']??false)===true && is_array($reference['wire']??null),'W9_WIRE_REFERENCE');
        $wire=$reference['wire'];$wire['expected_v01']=$packet;
        if ($revision!==null) $wire['prompt_text_revision']=$revision;
        $bytes=$frozen->transportJson($wire);$decoded=BaganrikiJson::decode($bytes);
        self::need($decoded['ok'],'W9_WIRE_REFERENCE');
        $transmitted=FrozenInputW2::typedValues($decoded);$expected=$transmitted['expected_v01'];
        unset($transmitted['expected_v01'],$transmitted['prompt_text_revision']);
        $restored=BaganrikiPrompt::expandWire($transmitted);self::need(($restored['ok']??false)===true,'W9_WIRE_REFERENCE');
        $full=$restored['input'];$full['expected_v01']=$expected;
        if ($revision!==null) $full['prompt_text_revision']=$revision;
        $same=BaganrikiJson::sameJson($frozen->raw(),$frozen->transportJson($full),true);
        self::need($same['ok'] && $same['same'],'W9_WIRE_REFERENCE');
        return $cache[$frozen]=$bytes;
    }

    private const DIAGNOSTICS=['errors','missing_horses','duplicated_horses','unknown_horses','isolated_horses'];

    private static function diagnostics(mixed $diagnostics):array
    {
        self::need(is_array($diagnostics) && count($diagnostics)===count(self::DIAGNOSTICS),'W9_FIX_DIAGNOSTICS');$out=[];
        foreach (self::DIAGNOSTICS as $key) {
            $list=$diagnostics[$key]??null;
            self::need(is_array($list) && ExpectedPacketW2::isList($list),'W9_FIX_DIAGNOSTICS');
            foreach ($list as $pos=>$item) {
                if ($key!=='errors') { self::need(is_int($item),'W9_FIX_DIAGNOSTICS');continue; }
                self::need(is_array($item) && count($item)===4 && array_key_exists('target',$item) &&
                    (is_int($item['target']) || is_string($item['target']) || $item['target']===null),'W9_FIX_DIAGNOSTICS');
                foreach (['scope','code','reason'] as $field) self::need(is_string($item[$field]??null) && TextW2::visible($item[$field]),'W9_FIX_DIAGNOSTICS');
                $list[$pos]=['scope'=>$item['scope'],'target'=>$item['target'],'code'=>$item['code'],'reason'=>$item['reason']];
            }
            $out[$key]=$list;
        }
        self::need((bool)array_filter($out),'W9_FIX_DIAGNOSTICS');
        return $out;
    }

    private static function grants(array $scope):array
    {
        $grant=[];
        foreach ($scope as $key=>$set) { $ids=array_keys($set);sort($ids);$grant[$key]=$ids; }
        return $grant;
    }

    /** Persist once at initial inspection, from the inspected raw response and the server's actual findings. */
    public static function inspectionSeal(FrozenInputW2 $frozen,string $firstHash,array $grant,mixed $diagnostics):string
    {
        $in=FrozenInputW2::checked($frozen);$scope=AttemptWindowW2::scope($grant,$firstHash,$in);
        $source=['W9_INSPECTION_V1',$in['race_id'],$in['snapshot_hash'],$in['ai_role'],$firstHash,self::grants($scope),self::diagnostics($diagnostics)];
        return hash('sha256',FrozenInputW2::nativeJson($source));
    }

    public static function correctionBody(FrozenInputW2 $frozen,string $input,string $first,array $scope,mixed $diagnostics):string
    {
        $in=FrozenInputW2::checked($frozen);
        $fix=['race_id'=>$in['race_id'],'ai_role'=>$in['ai_role'],'snapshot_hash'=>$in['snapshot_hash'],'attempt_no'=>1,'max_attempts'=>1,
            'repair_scope'=>self::grants($scope)]+self::diagnostics($diagnostics);
        try { $head=json_encode(['fix_request'=>$fix,'first_response'=>$first],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
        catch (\JsonException $error) { throw new InvalidArgumentException('W9_FIX_JSON',0,$error); }
        $body=substr($head,0,-1).',"input":'.$input.'}';
        self::need(strlen($body)<=4194304,'W9_SEND_SIZE');
        return $body;
    }

    public static function record(FrozenInputW2 $frozen,string $format,int $number,string $system,string $user):array
    {
        self::need(in_array($number,[0,1],true) && strlen($user)<=4194304,'W9_SENT_RECORD');
        $in=FrozenInputW2::checked($frozen);$systemHash=hash('sha256',$system);$userHash=hash('sha256',$user);
        self::need(self::knownSystem($in,$format,$system),'W9_SENT_SYSTEM');
        $request=self::requestHash($in['race_id'],$in['snapshot_hash'],$in['ai_role'],$number,$format,$systemHash,$userHash);
        return ['format'=>$format,'attempt_no'=>$number,'system_text'=>$system,'user_json'=>$user,'system_sha256'=>$systemHash,
            'user_sha256'=>$userHash,'request_sha256'=>$request];
    }

    public static function requestHash(string $race,string $snapshot,string $role,int $number,string $format,string $systemHash,string $userHash):string
    {
        $identity=['W9_REQUEST_V1',CandidateSelectionW2::VERSION,$race,$snapshot,$role,$number,$format,$systemHash,$userHash];
        return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
    }

    /** The reservation token, the exact role source, and the two already approved provider messages. */
    public static function sendBinding(int $snapshotId,string $token,string $roleJson,string $requestHash):string
    {
        return hash('sha256',json_encode(['W18_SEND_V1',$snapshotId,$token,hash('sha256',$roleJson),$requestHash],JSON_THROW_ON_ERROR));
    }

    /** Immediately before the provider adapter, compare the actual texts and order with the budgeted messages. */
    public static function recordSent(FrozenInputW2 $frozen,array $prepared,array $messages):array
    {
        self::need(ExpectedPacketW2::isList($messages) && count($messages)===2 &&
            is_array($messages[0]) && is_array($messages[1]) && count($messages[0])===2 && count($messages[1])===2 &&
            ($messages[0]['content']??null)===($prepared['messages'][0]['content']??null) &&
            ($messages[1]['content']??null)===($prepared['messages'][1]['content']??null) &&
            ($messages[0]['role']??null)==='system' && ($messages[1]['role']??null)==='user','W9_OUTBOUND_MESSAGES');
        $audit=$prepared['sent_request']??null;
        self::need(is_array($audit) && is_string($audit['format']??null) && is_int($audit['attempt_no']??null) &&
            is_string($messages[0]['content']??null) && is_string($messages[1]['content']??null),'W9_SENT_RECORD');
        $actual=self::record($frozen,$audit['format'],$audit['attempt_no'],$messages[0]['content'],$messages[1]['content']);
        self::need(($audit['request_sha256']??null)===$actual['request_sha256'],'W9_OUTBOUND_MESSAGES');
        return $actual;
    }

    public static function verifyReceipt(FrozenInputW2 $frozen,mixed $saved,int $number,?string $first,?array $scope,mixed $diagnostics):void
    {
        self::need(is_array($saved) && ($saved['attempt_no']??null)===$number && is_string($saved['format']??null) &&
            is_string($saved['system_text']??null) && is_string($saved['user_json']??null),'W9_SENT_RECORD');
        $body=$saved['user_json'];self::need(strlen($body)<=4194304,'W9_SEND_SIZE');
        $audit=self::record($frozen,$saved['format'],$number,$saved['system_text'],$body);
        foreach (['system_sha256','user_sha256','request_sha256'] as $key) self::need(($saved[$key]??null)===$audit[$key],'W9_SENT_HASH');
        $input=self::inputBody($frozen,$saved['format']);
        if ($number===0) { self::need($body===$input,'W9_SENT_INPUT');return; }
        self::need($first!==null && $scope!==null,'W9_FIX_SOURCE');
        // Use the saved initial inspection, independently of the text carried by the outgoing request.
        self::need($body===self::correctionBody($frozen,$input,$first,$scope,$diagnostics),'W9_SENT_FIX');
    }
}

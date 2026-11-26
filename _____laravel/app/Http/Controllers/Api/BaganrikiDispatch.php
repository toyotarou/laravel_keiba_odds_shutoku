<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** Exactly the two message texts sent by the R9 worker; both stay on the server for replay. */
final class BaganrikiDispatch
{
    private static function need(bool $ok,string $why):void { if (!$ok) throw new \InvalidArgumentException($why); }

    private static function config():array
    {
        $raw=file_get_contents(BaganrikiExpectedFiles::path('config/contract.json'));
        try { $cfg=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('EXPECTED_SYSTEM_VERSION',0,$e); }
        self::need(is_array($cfg) && BaganrikiCandidateSelection::isExpected($cfg['prompt_version']??null),'EXPECTED_SYSTEM_VERSION');
        return $cfg;
    }

    public static function system(string $role,string $format,?string $revision=null):string
    {
        self::need(in_array($role,['1st','2nd'],true) && in_array($format,self::FORMATS,true),'EXPECTED_DISPATCH_FORMAT');
        static $texts=[];
        $key=$role.'|'.$format.'|'.($revision??'active');
        if (!isset($texts[$key])) {
            $dir=BaganrikiExpectedFiles::path('prompts/');$config=BaganrikiExpectedFiles::path('config/contract.json');
            $raw=is_readable($config)?file_get_contents($config):false;self::need(is_string($raw),'EXPECTED_SYSTEM_VERSION');
            try { $cfg=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \InvalidArgumentException('EXPECTED_SYSTEM_VERSION',0,$e); }
            self::need(BaganrikiCandidateSelection::isExpected($cfg['prompt_version']??null),'EXPECTED_SYSTEM_VERSION');
            if ($revision!==null && $revision!==($cfg['active_text_revision']??null)) {
                self::need(preg_match('/^[A-Za-z0-9_-]+$/D',$revision)===1 && isset($cfg['archived_text_revisions'][$revision]),'EXPECTED_TEXT_REVISION');
                $cfg['system_file_sha256']=$cfg['archived_text_revisions'][$revision];
                $dir.='versions/'.$revision.'/';
            }
            $files=['prompt_'.$role.'.txt'];
            // 送信形式の説明（付録）。1st の SOURCE_DICT_V3 はよっしー11/9 A（名前で引ける圧縮形式）
            if ($format==='SOURCE_TABLE_V1') $files[]='SOURCE_TABLE_V1_ADDENDUM_V2.txt';
            if ($format==='SOURCE_DICT_V3') $files[]='SOURCE_DICT_V3_ADDENDUM_V1.txt';
            $parts=[];
            foreach ($files as $name) {
                $body=is_readable($dir.$name)?file_get_contents($dir.$name):false;
                self::need(is_string($body) && hash('sha256',$body)===($cfg['system_file_sha256'][$name]??null),'EXPECTED_SYSTEM_FILE');
                $parts[]=$body;
            }
            $texts[$key]=implode("\n\n",$parts);
        }
        return $texts[$key];
    }

    public static function activeRevision():string
    {
        $cfg=self::config();$revision=$cfg['active_text_revision']??null;
        self::need(is_string($revision) && preg_match('/^[A-Za-z0-9_-]+$/D',$revision)===1,'EXPECTED_TEXT_REVISION');
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
    public static function inputBody(BaganrikiFrozenInput $frozen,string $format):string
    {
        if ($format==='FULL') return $frozen->raw();
        self::need($format==='SOURCE_TABLE_V1' || $format==='SOURCE_DICT_V3','EXPECTED_DISPATCH_FORMAT');
        static $cache=null;$cache??=new \WeakMap();
        if (isset($cache[$frozen][$format])) return $cache[$frozen][$format];
        [$packWire,$expandWire]=self::codec($format);
        $tree=BaganrikiFrozenInput::checked($frozen);$packet=$tree['expected_v01'];$revision=$tree['prompt_text_revision']??null;
        unset($tree['expected_v01'],$tree['prompt_text_revision']);
        $reference=$packWire($tree);
        self::need(($reference['ok']??false)===true && is_array($reference['wire']??null),'EXPECTED_WIRE_REFERENCE');
        $wire=$reference['wire'];$wire['expected_v01']=$packet;
        if ($revision!==null) $wire['prompt_text_revision']=$revision;
        $bytes=$frozen->transportJson($wire);$decoded=BaganrikiJson::decode($bytes);
        self::need($decoded['ok'],'EXPECTED_WIRE_REFERENCE');
        $transmitted=BaganrikiFrozenInput::typedValues($decoded);$expected=$transmitted['expected_v01'];
        unset($transmitted['expected_v01'],$transmitted['prompt_text_revision']);
        $restored=$expandWire($transmitted);self::need(($restored['ok']??false)===true,'EXPECTED_WIRE_REFERENCE');
        $full=$restored['input'];$full['expected_v01']=$expected;
        if ($revision!==null) $full['prompt_text_revision']=$revision;
        $same=BaganrikiJson::sameJson($frozen->raw(),$frozen->transportJson($full),true);
        self::need($same['ok'] && $same['same'],'EXPECTED_WIRE_REFERENCE');
        $kept=$cache[$frozen]??[];$kept[$format]=$bytes;$cache[$frozen]=$kept;
        return $bytes;
    }

    /** 送れる形式。FULL・SOURCE_TABLE_V1 はよっしーの元の形式、SOURCE_DICT_V3 は 1st 用（よっしー11/9 A） */
    public const FORMATS=['FULL','SOURCE_TABLE_V1','SOURCE_DICT_V3'];

    /** 形式ごとの詰め方・戻し方（既存の BaganrikiPrompt の圧縮をそのまま使う。10/20版の1stと同じ dict3） */
    public static function codec(string $format):array
    {
        if ($format==='SOURCE_DICT_V3') {
            // 戻した中身の「項目の並び」を、凍結原文と同じ（名前順）にそろえます。値は変えません。
            //   名前で引ける形は、戻すときに項目を作り直すので並びが変わります。よっしーの照合はバイト単位なので並びも合わせます。
            $sorted=static function(array $w){
                // 詰めた側は一部を stdClass（JSON の {}）で作るので、戻す前に配列へそろえます（数値の BaganrikiDecimal はそのまま）
                $w=self::plainArrays($w);
                $r=BaganrikiPrompt::expandWireDict3($w);
                if (($r['ok']??false)===true && is_array($r['input']??null)) $r['input']=self::sortKeys($r['input']);
                return $r;
            };
            return [static fn(array $t)=>BaganrikiPrompt::compactWireDict3($t), $sorted];
        }
        self::need($format==='SOURCE_TABLE_V1','EXPECTED_DISPATCH_FORMAT');
        return [static fn(array $t)=>BaganrikiPrompt::compactWire($t), static fn(array $w)=>BaganrikiPrompt::expandWire($w)];
    }

    /** stdClass を配列へ（入れ子も）。それ以外のオブジェクト（BaganrikiDecimal など）はそのまま */
    private static function plainArrays(mixed $v):mixed
    {
        if ($v instanceof \stdClass) $v=(array)$v;
        if (!is_array($v)) return $v;
        foreach ($v as $k=>$c) $v[$k]=self::plainArrays($c);
        return $v;
    }

    /** 連想配列の項目を名前順（文字列の順）に並べ直す。リスト（0,1,2…の配列）の順番はそのまま */
    private static function sortKeys(array $a):array
    {
        $isList=($a===[] || array_keys($a)===range(0,count($a)-1));
        foreach ($a as $k=>$v) if (is_array($v)) $a[$k]=self::sortKeys($v);
        if (!$isList) ksort($a,SORT_STRING);
        return $a;
    }

    /** その役割で送る形式（config の role_wire_format。1st=SOURCE_DICT_V3、2nd=SOURCE_TABLE_V1） */
    public static function wireFormatFor(string $role):string
    {
        $f=self::config()['role_wire_format'][$role]??null;
        self::need(in_array($f,self::FORMATS,true) && $f!=='FULL','EXPECTED_DISPATCH_FORMAT');
        return $f;
    }

    /** 送った本文の形から形式を見分ける（送信直前の照合用） */
    public static function formatOfInput(array $input):string
    {
        if (array_key_exists('source_dict',$input)) return 'SOURCE_DICT_V3';
        if (array_key_exists('source_table',$input)) return 'SOURCE_TABLE_V1';
        return 'FULL';
    }

    private const DIAGNOSTICS=['errors','missing_horses','duplicated_horses','unknown_horses','isolated_horses'];

    private static function diagnostics(mixed $diagnostics):array
    {
        self::need(is_array($diagnostics) && count($diagnostics)===count(self::DIAGNOSTICS),'EXPECTED_FIX_DIAGNOSTICS');$out=[];
        foreach (self::DIAGNOSTICS as $key) {
            $list=$diagnostics[$key]??null;
            self::need(is_array($list) && BaganrikiExpectedPacket::isList($list),'EXPECTED_FIX_DIAGNOSTICS');
            foreach ($list as $pos=>$item) {
                if ($key!=='errors') { self::need(is_int($item),'EXPECTED_FIX_DIAGNOSTICS');continue; }
                self::need(is_array($item) && count($item)===4 && array_key_exists('target',$item) &&
                    (is_int($item['target']) || is_string($item['target']) || $item['target']===null),'EXPECTED_FIX_DIAGNOSTICS');
                foreach (['scope','code','reason'] as $field) self::need(is_string($item[$field]??null) && BaganrikiText::visible($item[$field]),'EXPECTED_FIX_DIAGNOSTICS');
                $list[$pos]=['scope'=>$item['scope'],'target'=>$item['target'],'code'=>$item['code'],'reason'=>$item['reason']];
            }
            $out[$key]=$list;
        }
        self::need((bool)array_filter($out),'EXPECTED_FIX_DIAGNOSTICS');
        return $out;
    }

    private static function grants(array $scope):array
    {
        $grant=[];
        foreach ($scope as $key=>$set) { $ids=array_keys($set);sort($ids);$grant[$key]=$ids; }
        return $grant;
    }

    /** Persist once at initial inspection, from the inspected raw response and the server's actual findings. */
    public static function inspectionSeal(BaganrikiFrozenInput $frozen,string $firstHash,array $grant,mixed $diagnostics):string
    {
        $in=BaganrikiFrozenInput::checked($frozen);$scope=BaganrikiAttemptWindow::scope($grant,$firstHash,$in);
        $source=['EXPECTED_INSPECTION_V1',$in['race_id'],$in['snapshot_hash'],$in['ai_role'],$firstHash,self::grants($scope),self::diagnostics($diagnostics)];
        return hash('sha256',BaganrikiFrozenInput::nativeJson($source));
    }

    public static function correctionBody(BaganrikiFrozenInput $frozen,string $input,string $first,array $scope,mixed $diagnostics):string
    {
        $in=BaganrikiFrozenInput::checked($frozen);
        $fix=['race_id'=>$in['race_id'],'ai_role'=>$in['ai_role'],'snapshot_hash'=>$in['snapshot_hash'],'attempt_no'=>1,'max_attempts'=>1,
            'repair_scope'=>self::grants($scope)]+self::diagnostics($diagnostics);
        try { $head=json_encode(['fix_request'=>$fix,'first_response'=>$first],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
        catch (\JsonException $error) { throw new \InvalidArgumentException('EXPECTED_FIX_JSON',0,$error); }
        $body=substr($head,0,-1).',"input":'.$input.'}';
        self::need(strlen($body)<=4194304,'EXPECTED_SEND_SIZE');
        return $body;
    }

    public static function record(BaganrikiFrozenInput $frozen,string $format,int $number,string $system,string $user):array
    {
        $policyInput=BaganrikiFrozenInput::checked($frozen);
        // 1st は名前で引ける形だけ（よっしー11/9 A）。表の形では送らない
        // if (BaganrikiRaceMetrics::enabled($policyInput) && $policyInput['ai_role']==='1st' && $format!=='SOURCE_DICT_V3') throw new \InvalidArgumentException('EXPECTED_SEND_POLICY');   // 旧（〜_06）
        // @ANCHOR-20261124-TEXT07-SERVER-METRICS  3指標を外した版（_07）でも、1st の送信形式の縛りは今までどおり（SOURCE_DICT_V3 だけ）
        if ((BaganrikiRaceMetrics::enabled($policyInput) || BaganrikiRaceMetrics::serverComputed($policyInput)) && $policyInput['ai_role']==='1st' && $format!=='SOURCE_DICT_V3') throw new \InvalidArgumentException('EXPECTED_SEND_POLICY');
        self::need(in_array($number,[0,1],true) && strlen($user)<=4194304,'EXPECTED_SENT_RECORD');
        $in=BaganrikiFrozenInput::checked($frozen);$systemHash=hash('sha256',$system);$userHash=hash('sha256',$user);
        self::need(self::knownSystem($in,$format,$system),'EXPECTED_SENT_SYSTEM');
        BaganrikiCandidateSelection::useVersion($in['prompt_version']??null);
        $request=self::requestHash($in['race_id'],$in['snapshot_hash'],$in['ai_role'],$number,$format,$systemHash,$userHash);
        return ['format'=>$format,'attempt_no'=>$number,'system_text'=>$system,'user_json'=>$user,'system_sha256'=>$systemHash,
            'user_sha256'=>$userHash,'request_sha256'=>$request];
    }

    public static function requestHash(string $race,string $snapshot,string $role,int $number,string $format,string $systemHash,string $userHash):string
    {
        // @ANCHOR-20261125-E5-SCORE8  送信の照合値に入れる版は、そのレースの凍結入力の版（〜11/27 の分は今までと同じ値になる）
        $identity=['EXPECTED_REQUEST_V1',BaganrikiCandidateSelection::contextVersion(),$race,$snapshot,$role,$number,$format,$systemHash,$userHash];
        return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
    }

    /** The reservation token, the exact role source, and the two already approved provider messages. */
    public static function sendBinding(int $snapshotId,string $token,string $roleJson,string $requestHash):string
    {
        return hash('sha256',json_encode(['EXPECTED_SEND_V1',$snapshotId,$token,hash('sha256',$roleJson),$requestHash],JSON_THROW_ON_ERROR));
    }

    /** Immediately before the provider adapter, compare the actual texts and order with the budgeted messages. */
    public static function recordSent(BaganrikiFrozenInput $frozen,array $prepared,array $messages):array
    {
        self::need(BaganrikiExpectedPacket::isList($messages) && count($messages)===2 &&
            is_array($messages[0]) && is_array($messages[1]) && count($messages[0])===2 && count($messages[1])===2 &&
            ($messages[0]['content']??null)===($prepared['messages'][0]['content']??null) &&
            ($messages[1]['content']??null)===($prepared['messages'][1]['content']??null) &&
            ($messages[0]['role']??null)==='system' && ($messages[1]['role']??null)==='user','EXPECTED_OUTBOUND_MESSAGES');
        $audit=$prepared['sent_request']??null;
        self::need(is_array($audit) && is_string($audit['format']??null) && is_int($audit['attempt_no']??null) &&
            is_string($messages[0]['content']??null) && is_string($messages[1]['content']??null),'EXPECTED_SENT_RECORD');
        $actual=self::record($frozen,$audit['format'],$audit['attempt_no'],$messages[0]['content'],$messages[1]['content']);
        self::need(($audit['request_sha256']??null)===$actual['request_sha256'],'EXPECTED_OUTBOUND_MESSAGES');
        return $actual;
    }

    public static function verifyReceipt(BaganrikiFrozenInput $frozen,mixed $saved,int $number,?string $first,?array $scope,mixed $diagnostics):void
    {
        self::need(is_array($saved) && ($saved['attempt_no']??null)===$number && is_string($saved['format']??null) &&
            is_string($saved['system_text']??null) && is_string($saved['user_json']??null),'EXPECTED_SENT_RECORD');
        $body=$saved['user_json'];self::need(strlen($body)<=4194304,'EXPECTED_SEND_SIZE');
        $audit=self::record($frozen,$saved['format'],$number,$saved['system_text'],$body);
        foreach (['system_sha256','user_sha256','request_sha256'] as $key) self::need(($saved[$key]??null)===$audit[$key],'EXPECTED_SENT_HASH');
        $input=self::inputBody($frozen,$saved['format']);
        if ($number===0) { self::need($body===$input,'EXPECTED_SENT_INPUT');return; }
        self::need($first!==null && $scope!==null,'EXPECTED_FIX_SOURCE');
        // Use the saved initial inspection, independently of the text carried by the outgoing request.
        self::need($body===self::correctionBody($frozen,$input,$first,$scope,$diagnostics),'EXPECTED_SENT_FIX');
    }
}

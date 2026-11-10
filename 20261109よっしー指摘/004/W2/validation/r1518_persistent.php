<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api {
    function gettimeofday(){ $us=$GLOBALS['clock18'];return ['sec'=>intdiv($us,1000000),'usec'=>$us%1000000]; }
}
namespace App\Services {
    class AnthropicService {
        function sendWithRetry(...$args){return \Local18::sent('1st',$args['system'],$args['prompt']);}
    }
}
namespace Illuminate\Support\Facades {
    class Http {
        static function timeout($n){return new self();}
        static function withHeaders($h){return new self();}
        function post($url,$data){return \Local18::sent('2nd',$data['messages'][0]['content'],$data['messages'][1]['content']);}
    }
}
namespace {
    use App\Http\Controllers\Api\AiController as Ctrl;
    use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
    use App\Http\Controllers\Api\BaganrikiWorker as Worker;
    use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
    use App\Services\Baganriki\FrozenInputW2 as Frozen;
    use App\Services\Baganriki\DispatchW2 as Dispatch;
    class Log {public static function info(...$x){} public static function error(...$x){}}
    class Local18 {
        static function sent($role,$system,$user){
            if(\DB::$inWrite || \DB::$p->inTransaction())throw new \RuntimeException('provider under lock');
            $q=\DB::$p->prepare('INSERT INTO sender18_calls(role,sys,user) VALUES(?,?,?)');
            $q->execute([$role,hash('sha256',$system),hash('sha256',$user)]);
            return new self();
        }
        function successful(){return true;} function failed(){return false;}
        function json($k=null){$data=['content'=>[['type'=>'text','text'=>$GLOBALS['raw18']]],'stop_reason'=>'end_turn',
          'choices'=>[['message'=>['content'=>$GLOBALS['raw18']],'finish_reason'=>'stop']],'usage'=>[],'model'=>'local'];
            return $k===null?$data:($data[$k]??null);}
    }
    $GLOBALS['worker_test_env']=['BAGANRIKI_R122_BUDGET_USD_DAY'=>'','BAGANRIKI_R122_PROMPT'=>'on'];
    require __DIR__.'/r154_worker.php';require __DIR__.'/r4_fixtures.php';
    $q=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$GLOBALS['clock18']=$q['clock'];
    $ctrl=new Ctrl(new \App\Services\AnthropicService());$role=$q['role'];$slot=$q['slot'];$tok=$q['token'];$dir=$q['examples'];
    $input=file_get_contents("$dir/{$role}_input.json");$common=Codec::commonHash(json_decode($input,true));
    $f=Frozen::fromStored($common['json'],$input,$common['hash'],$role,json_decode(file_get_contents("$dir/expected_proof.json"),true));
    $row=file_get_contents("$dir/{$role}_output.json");$raw=\App\Http\Controllers\Api\BaganrikiResponse::BEGIN."\n".$row."\n".\App\Http\Controllers\Api\BaganrikiResponse::END;
    if(isset($q['break_horse'])) {
        $bad=json_decode($row,true,512,JSON_THROW_ON_ERROR);
        foreach($bad['horses'] as &$h){if($h['horse_no']===$q['break_horse'])$h['evidence'][0]['facts'][0]['value']=999;}unset($h);
        $raw=\App\Http\Controllers\Api\BaganrikiResponse::BEGIN."\n".json_encode($bad,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n".\App\Http\Controllers\Api\BaganrikiResponse::END;
    }
    $GLOBALS['raw18']=$raw;$start=r4live($f);
    $pack=static fn($x)=>Codec::compactWire($x)['wire'];$expand=static fn($x)=>Codec::expandWire($x)['input'];
    if($q['op']==='stage'||$q['op']==='chain'){
        $prepared=Bridge::prepareSend($f,$pack,$expand,static fn()=>true,static fn()=>$start);
        if ($slot) {
            $db=DB::$p->prepare('SELECT raw_response,response_hash,complete,sent_at,received_at FROM baganriki_ai_attempts WHERE role=? AND attempt_no=0');
            $db->execute([$role]);$first=$db->fetch(\PDO::FETCH_ASSOC);$db->closeCursor();
            $rcpt=r4receipt($f,$first['raw_response']);$rcpt['response_hash']=$first['response_hash'];$rcpt['complete']=$first['complete'];
            $rcpt['sent_at']=$first['sent_at'];$rcpt['received_at']=str_replace(' ','T',$first['received_at']).'+09:00';
            $s=DB::$p->prepare('SELECT record FROM sender18_receipts WHERE token=(SELECT token FROM baganriki_ai_attempts WHERE role=? AND attempt_no=0)');
            $s->execute([$role]);$rcpt['sent_request']=json_decode($s->fetchColumn(),true);$s->closeCursor();
            $grant=$q['grant'];$diag=$q['diagnostics'];
            $prepared=Bridge::prepareSend($f,$pack,$expand,static fn()=>true,static fn()=>$start,false,[
              'first_receipt'=>$rcpt,'repair_scope'=>$grant,'diagnostics'=>$diag,'inspection_sha256'=>Dispatch::inspectionSeal($f,$rcpt['response_hash'],$grant,$diag)]);
        }
        $record=Dispatch::recordSent($f,$prepared,$prepared['messages']);
        $ins=DB::$p->prepare('INSERT OR IGNORE INTO sender18_receipts(token,record) VALUES(?,?)');
        $ins->execute([$tok,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$ins->closeCursor();
        $out=$q['op']==='chain'?$ctrl->sendReservedW2Request(1,$role,$slot,$tok,$f,$prepared,40):Ctrl::stagePreparedW2Request(1,$role,$slot,$tok,$f,$prepared);
    } elseif($q['op']==='allow') $out=Worker::allowSend($tok,$q['clock']);
    else {
        $get=DB::$p->prepare('SELECT record FROM sender18_receipts WHERE token=?');$get->execute([$tok]);$record=json_decode($get->fetchColumn(),true);$get->closeCursor();
        $sys=$record['system_text'];$user=$record['user_json'];if(!empty($q['tamper']))$user.=' ';
        $out=$ctrl->callPredictionAi($role,$sys,$user,40,1);
    }
    if(isset($out['raw']) && $out['ok']){
       $st=DB::$p->prepare('UPDATE baganriki_ai_attempts SET id=rowid,sent_at=? WHERE token=?');$st->execute([$out['sent_at'],$tok]);
       if (($q['receive']??false)) {
           $received=Worker::receive($tok,$out['raw'],$out['complete'],$q['clock']+250);
           if(!$received['ok'])throw new RuntimeException('receipt '.$received['code']);
       }
       $out['raw_sha256']=hash('sha256',$out['raw']);
    }
    unset($out['raw'],$out['sent_request']);echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}

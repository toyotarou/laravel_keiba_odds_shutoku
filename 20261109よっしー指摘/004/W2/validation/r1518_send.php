<?php
declare(strict_types=1);
use App\Http\Controllers\Api\AiController as Controller;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Http\Controllers\Api\BaganrikiWorker as Worker;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\AttemptWindowW2 as Window;

$old=($argv[3]??'')==='--observe-r1517';
require ($old?$argv[4]:__DIR__).'/r156_sdk.php';
function need18($ok,$why){if(!$ok) throw new RuntimeException($why);}
$p=preparedRows($argv[2]);$rows=array_column($p['roles'],null,'role');$ctrl=new Controller(new \App\Services\AnthropicService());
$total=0;$accepted=0;$stopped=0;$refusals=0;
foreach($rows as $role=>$row){
    $other=$role==='1st'?'2nd':'1st'; $f=Frozen::fromStored($p['common'],$row['input'],$p['hash'],$role,$p['witness']);
    $in=Frozen::checked($f);$col='input_json_'.$role;$live=r4live($f);
    foreach(['SOURCE_TABLE_V1','FULL'] as $fmt){
        $pack=static function($x)use($fmt){if($fmt==='FULL')throw new InvalidArgumentException('explicit fixture fallback');return Codec::compactWire($x)['wire'];};
        $unpack=static fn($x)=>Codec::expandWire($x)['input'];
        $initial=Bridge::prepareSend($f,$pack,$unpack,static fn()=>true,static fn()=>$live,$fmt==='FULL');
        $first=r4receipt($f,$row['original']);$first['sent_request']=Dispatch::recordSent($f,$initial,$initial['messages']);
        $grant=r5scope($first,[12],['B']);$diagnostics=r9diagnostics();
        $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diagnostics,
            'inspection_sha256'=>Dispatch::inspectionSeal($f,$first['response_hash'],$grant,$diagnostics)];
        $fix=Bridge::prepareSend($f,$pack,$unpack,static fn()=>true,static fn()=>$live,$fmt==='FULL',$repair);
        foreach([$initial,$fix] as $n=>$prepared) {
            $rec=Dispatch::recordSent($f,$prepared,$prepared['messages']);$token='real-fixture-'.$role.'-'.$n;
            $cases=['normal','retry','swap_saved','changed_quota','missing_saved','wrong_system','wrong_format','changed_source','whitespace',
                'missing_binding','damaged_binding','other_token','not_sent','after_live_lock','foreign_record','other_format_attempt'];
            if($n) $cases=array_merge($cases,['other_initial','out_of_scope','changed_diagnostics','wrapper_extra']);
            if($old)$cases=['normal','swap_saved','changed_quota','missing_saved','wrong_system','changed_source','whitespace'];
            foreach($cases as $case) {
                TestIO::reset($p,$in,30000000);foreach($rows as $r=>$v)TestIO::$snap->{'input_json_'.$r}=$v['input'];
                if(!$old) {
                    TestIO::$attempt=(object)['token'=>$token,'snapshot_id'=>1,'role'=>$role,'attempt_no'=>$n,'status'=>Worker::RESERVED,'w2_send_proof'=>null];
                    $stage=Controller::stagePreparedW2Request(1,$role,$n,$token,$f,$prepared);
                    need18($stage['ok']&&$stage['sent_request']===$rec,"$role/$n/$fmt/$case stage");
                    $same=Controller::stagePreparedW2Request(1,$role,$n,$token,$f,$prepared);
                    need18($same['ok']&&strlen(TestIO::$attempt->w2_send_proof)===65 && TestIO::$attempt->w2_send_proof[0]==='R','repeat stage');
                    TestIO::$attempt->status=Worker::SENT;
                }
                $system=$rec['system_text'];$user=$rec['user_json'];
                if($case==='wrong_system')$system=Dispatch::system($other,$fmt);
                if($case==='wrong_format')$system=Dispatch::system($role,$fmt==='FULL'?'SOURCE_TABLE_V1':'FULL');
                if($case==='whitespace')$user.=' ';
                if($case==='changed_source') {$user=str_replace('input_freeze_at','input_freeze_AX',$user,$count);need18($count>0,'mutated input');}
                if(in_array($case,['other_initial','out_of_scope','changed_diagnostics','wrapper_extra'],true)){
                    $obj=json_decode($user,true,512,JSON_THROW_ON_ERROR);
                    if($case==='other_initial')$obj['first_response']=$rows[$other]['original'];
                    if($case==='out_of_scope')$obj['fix_request']['repair_scope']['horses']=[1];
                    if($case==='changed_diagnostics')$obj['fix_request']['errors'][0]['reason'].='追加';
                    if($case==='wrapper_extra')$obj['unbudgeted']='extra';
                    $user=dbJson($obj);need18($user!==$rec['user_json'],'mutated correction');
                }
                TestIO::$onHold=static function()use($case,$role,$other,$rows,$col,$fmt,$n,$token){
                    if($case==='swap_saved')TestIO::$snap->$col=$rows[$other]['input'];
                    if($case==='missing_saved')TestIO::$snap->$col=null;
                    if($case==='changed_quota'){
                        $obj=json_decode(TestIO::$snap->$col,true);$obj['role_quota']=['A'=>0,'B'=>0,'D'=>0];TestIO::$snap->$col=dbJson($obj);
                    }
                    if($case==='missing_binding')TestIO::$attempt->w2_send_proof=null;
                    if($case==='damaged_binding')TestIO::$attempt->w2_send_proof='R'.str_repeat('e',64);
                    if($case==='other_token')TestIO::$attempt->token.='-other';
                    if($case==='not_sent')TestIO::$attempt->status=Worker::RESERVED;
                    if($case==='foreign_record')TestIO::$attempt->role=$other;
                    if($case==='other_format_attempt')TestIO::$attempt->attempt_no=1-$n;
                };
                if($case==='after_live_lock')TestIO::$atLiveLock=static function()use($other,$rows,$col){TestIO::$snap->$col=$rows[$other]['input'];};
                $res=$ctrl->callPredictionAi($role,$system,$user,40,1);$ok=in_array($case,['normal','retry'],true);
                if($old){need18($res['code']==='OK'&&count(TestIO::$calls)===1,"old observation $case");++$total;continue;}
                $label="$role/$fmt/$n/$case"; $calls=TestIO::$calls;
                if($ok){
                    need18($res['ok']&&$res['code']==='OK'&&count($calls)===1&&$calls[0][1]===$rec['system_sha256']&&$calls[0][2]===$rec['user_sha256']
                        &&TestIO::$attempt->w2_send_proof[0]==='U',$label.' approved');
                    if($case==='retry'){
                        $retry=$ctrl->callPredictionAi($role,$system,$user,40,1);
                        need18(!$retry['ok']&&$retry['code']==='OUTBOUND_MISMATCH'&&count(TestIO::$calls)===1,$label.' twice');
                    }
                    ++$accepted;
                }else{
                    need18(!$res['ok']&&$res['code']==='OUTBOUND_MISMATCH'&&!$calls&&!isset($res['sent_at'])
                        &&array_column(TestIO::$audit,'kind')===['R122_BUDGET_HOLD','R122_BUDGET_RELEASE']
                        &&Controller::spentTodayUsd(TestIO::$now)===0.0,$label.' held '.($res['code']??'NULL'));
                    ++$stopped;
                }
                ++$total;
            }
            if(!$old){
                foreach(['bad_token','wrong_slot','wrong_role','second_plan','late_stage','altered_preparation'] as $bad){
                    TestIO::reset($p,$in,30000000);TestIO::$attempt=(object)['token'=>$token,'snapshot_id'=>1,'role'=>$role,'attempt_no'=>$n,'status'=>Worker::RESERVED,'w2_send_proof'=>null];
                    $v=$prepared;if($bad==='altered_preparation')$v['messages'][1]['content'].=' ';
                    if($bad==='second_plan')TestIO::$attempt->w2_send_proof='R'.str_repeat('7',64);
                    if($bad==='late_stage')TestIO::$attempt->status=Worker::SENT;
                    $prev=TestIO::$attempt->w2_send_proof;
                    $stage=Controller::stagePreparedW2Request(1,$bad==='wrong_role'?$other:$role,$bad==='wrong_slot'?1-$n:$n,$bad==='bad_token'?'other':$token,$f,$v);
                    need18(!$stage['ok']&&TestIO::$attempt->w2_send_proof===$prev&&!TestIO::$calls,"protected staging $bad");++$refusals;
                }
            }
        }
    }
}
if($old)echo "Observed R15.17: $total initial/correction and control cases, all reached the test provider.\n";
else echo "PASS R15.18 $total sender cases ($accepted approved, $stopped blocked); $refusals invalid staging attempts, no duplicate payment call.\n";

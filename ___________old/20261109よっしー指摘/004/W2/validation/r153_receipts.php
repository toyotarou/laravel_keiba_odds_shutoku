<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\RoleJournalW2 as Journal;

function r153RoleClock(string $role,$ctrl,Frozen $base,array $in,array $reply,$db,$saved,array $proof):array
{
    $count=0;
    $check=static function(bool $valid,string $label)use(&$count,$role):void {
        if (!$valid) throw new \RuntimeException("R15.3 $role $label");
        ++$count;
    };
    $toDb=static fn(string $s)=>Packet::instant($s)->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s.u');
    $freeze=$in['input_freeze_at'];$replyLimit=$in['time_contract']['reply_deadline'];
    $now=\r4time($freeze,'+30 seconds');$raw=\r3frame($reply);$original=\r4original($base,$raw);
    $variants=[[$base,$reply,$freeze,$now,$replyLimit,true,'OK'],
        [$base,$reply,\r4time($freeze,'-1 microsecond'),$now,$replyLimit,1,'RESPONSE_BEFORE_INPUT_READY'],
        [$base,$reply,\r4time($now,'+1 microsecond'),$now,$replyLimit,'1','RESPONSE_IN_FUTURE'],
        [$base,$reply,$now,$now,$replyLimit,1,'OK'],
        [$base,$reply,$replyLimit,$replyLimit,$replyLimit,'1','OK'],
        [$base,$reply,\r4time($replyLimit,'+1 microsecond'),\r4time($replyLimit,'+10 seconds'),$replyLimit,1,'REPLY_DEADLINE_EXCEEDED'],
        [$base,$reply,\r4time($replyLimit,'+1 microsecond'),\r4time($replyLimit,'+10 seconds'),\r4time($replyLimit,'+20 seconds'),1,'REPLY_DEADLINE_EXCEEDED'],
        [$base,$reply,\r4time($now,'+1 microsecond'),\r4time($now,'+20 seconds'),$now,1,'REPLY_DEADLINE_EXCEEDED']];
    foreach ([false,0,'0',null,'01','1oops','1.0',1.5,2] as $flag) $variants[]=[$base,$reply,$now,$now,$replyLimit,$flag,'INCOMPLETE_RESPONSE'];

    // A genuine derived observation can become ready later than the raw input freeze.
    $key='r153_derived';$parent='h1_past_performance_None';$computed=\r4time($freeze,'+12 seconds');$later=$in;
    $later['source_registry'][$key]=array_replace($in['source_registry'][$parent],
        ['source_kind'=>'DERIVED','source_ref'=>$key,'parents'=>[$parent],'formula_id'=>'identity','formula_version'=>'1','computed_at'=>$computed]);
    $common=BaganrikiPrompt::commonHash($later);$later['snapshot_hash']=$common['hash'];
    $derived=Frozen::fromStored($common['json'],\r3bytes($later),$common['hash'],$role,$proof);$adjusted=$reply;$adjusted['snapshot_hash']=$common['hash'];
    $check($derived->readyAt()==Packet::instant($computed),'fixture uses derived-input readiness');
    $variants[]=[$derived,$adjusted,\r4time($computed,'-1 microsecond'),$now,$replyLimit,1,'RESPONSE_BEFORE_INPUT_READY'];
    $variants[]=[$derived,$adjusted,$computed,$now,$replyLimit,1,'OK'];
    try {
        foreach ($variants as [$snapshot,$o,$at,$clock,$liveReply,$flag,$expected]) {
            $bytes=\r3frame($o);$info=Frozen::checked($snapshot);$nos=array_column($info['runners'],'horse_no');
            $db($bytes,$bytes,$snapshot);array_pop(\R15Table::$tables['baganriki_ai_attempts']);
            $row=\R15Table::$tables['baganriki_ai_attempts'][0];$row->sent_at=$at;$row->received_at=$toDb($at);$row->complete=$flag;
            \R15Table::$tables['baganriki_snapshots'][0]->deadline=$liveReply;
            BaganrikiWorker::$fixtureNow=BaganrikiTime::us($clock);
            $d=$ctrl->inspectAiResponse(1,$role);$a=$ctrl->acceptedHorsesOf(1,$role);$use=$ctrl->markResponseUsed(1,$role,$a);
            $ok=$expected==='OK';$label=$expected.' @'.$at.' / '.json_encode($flag);
            $check($d['code']===$expected && $d['ok']===$ok && $d['accepted']===$ok,$label.' inspection '.($d['code']??''));
            $check(($ok && $use['public_candidates']===$o['public_candidates'] && $use['proof'][1]===hash('sha256',$bytes))
                || (!$ok && $a['merged']===[] && $use['public_candidates']===[] && $use['proof']===null),$label.' normal worker entry');
            $check(BaganrikiWorker::fixAllowed(1,$role)['ok']===in_array($flag,[true,1,'1'],true),$label.' completed initial required before booking correction');
            $state=\r4live($snapshot,$clock,\r4time($liveReply,'+90 seconds'));
            $rec=\r4original($snapshot,$bytes);$rec['receipts'][0]['sent_at']=$rec['receipts'][0]['received_at']=$at;$rec['receipts'][0]['complete']=$flag;
            try {$result=\r9merge($snapshot,[$role=>$rec],$info['quota'],$nos,[],$state);$final=!isset($result['role_issues'][$role]);}
            catch (\InvalidArgumentException $e) {if ($e->getMessage()!=='W2_NO_VERIFIED_ROLE') throw $e;$final=false;}
            $check($final===$ok,$label.' independent stored-receipt verification');
        }
        // Verify the current attempt column and the deployed usage-event layout at the actual call boundary.
        foreach (['reversed','send before ready','absent','invalid','event valid','event duplicate','event wrong role','event wrong race','database datetime'] as $case) {
            $db($raw,$raw);array_pop(\R15Table::$tables['baganriki_ai_attempts']);
            $a=\R15Table::$tables['baganriki_ai_attempts'][0];$at=\r4time($freeze,'+1 second');$rcpt=\r4original($base,$raw);
            $event=(object)['race_id'=>$in['race_id'],'snapshot_id'=>1,'kind'=>'R122_USAGE','related_id'=>$role.'#0','diagnostics'=>json_encode(['role'=>$role,'attempt_no'=>0,'sent_at'=>$at])];
            $code='OK';$valid=true;
            switch ($case) {
            case 'reversed': $at=\r4time($freeze,'+3 seconds');$code='RECEIVED_BEFORE_SENT';break;
            case 'send before ready': $at=\r4time($freeze,'-1 microsecond');$code='SENT_BEFORE_INPUT_READY';break;
            case 'absent': $at=null;$code='SEND_TIME_MISSING';break;
            case 'invalid': $at='yesterday';$code='SEND_TIME_INVALID';break;
            case 'database datetime': $a->sent_at=$toDb($at);break;
            default:
                $a->sent_at=null;$events=[$event];
                if ($case==='event duplicate') {$events[]=$event;$code='SEND_TIME_AMBIGUOUS';}
                if ($case==='event wrong role') {$event->diagnostics=json_encode(['role'=>'invalid','attempt_no'=>0,'sent_at'=>$at]);$code='SEND_TIME_AMBIGUOUS';}
                if ($case==='event wrong race') {$event->race_id='other';$code='SEND_TIME_MISSING';}
                \R15Table::$tables['baganriki_audit_events']=$events;
            }
            if (!str_starts_with($case,'event ') && $case!=='database datetime') $a->sent_at=$at;
            $d=$ctrl->inspectAiResponse(1,$role);$effective=$ctrl->acceptedHorsesOf(1,$role);$use=$ctrl->markResponseUsed(1,$role,$effective);
            $valid=$code==='OK';$check($d['ok']===$valid && $d['code']===$code && ($use['public_candidates']!==[])===$valid,$case.' send record');
            if (!str_starts_with($case,'event ') || $valid) {
                $rcpt['receipts'][0]['sent_at']=$at;
                try {$result=\r9merge($base,[$role=>$rcpt],$in['quota'],$nos,[],\r4live($base,$now));$final=!isset($result['role_issues'][$role]);}
                catch (\InvalidArgumentException $e) {if ($e->getMessage()!=='W2_NO_VERIFIED_ROLE') throw $e;$final=false;}
                $check($final===$valid,$case.' downstream send time');
            }
        }
        // The normal adapter reports the missing legacy column even with Laravel-style warning handling.
        $db($raw,$raw);$old=\R15Table::$tables['baganriki_snapshots'][0];$old->release_version='legacy';
        $column=$role==='1st'?'input_json_1st':'input_json_2nd';$old->$column='';
        set_error_handler(static function($severity,$message){throw new \ErrorException($message,0,$severity);});
        try {$d=$ctrl->inspectAiResponse(1,$role);$check(!$d['ok'] && $d['code']==='NO_INPUT' && str_contains($d['reason'],$column),'missing legacy input');}
        finally {restore_error_handler();}

        // A real correction which is allowed to change the order in B.
        BaganrikiWorker::$fixtureNow=BaganrikiTime::us($replyLimit);
        $bad=$reply;$bad['horses'][6]['evidence'][0]['facts'][0]['value']='8765';$fixed=$reply;
        foreach (['band_ranks','evidence_band_ranks'] as $field) {$list=&$fixed[$field]['B'];[$list[0],$list[1]]=[$list[1],$list[0]];unset($list);}
        $projection=Selection::apply($base,$fixed['horses'],$fixed['band_ranks']);
        foreach (['selection_ranks','public_candidates','expected_reviews'] as $k) $fixed[$k]=$projection[$k];
        $before=\r3frame($bad);$after=\r3frame($fixed);$db($before,$after);$firstDiag=$ctrl->inspectAiResponse(1,$role);
        $corrected=$saved($before,$after,$firstDiag);$valid=$ctrl->acceptedHorsesOf(1,$role);
        $used=$ctrl->markResponseUsed(1,$role,$valid);
        $check($valid['code']==='FIX_APPLIED' && array_slice($used['band_ranks']['B'],0,2)===[8,7] && $used['public_candidates']===$fixed['public_candidates'],'ready accepted correction');
        $cut=\r4time($freeze,'+3 seconds');\R15Table::$tables['baganriki_snapshots'][0]->deadline=$cut;
        $stale=$ctrl->markResponseUsed(1,$role,$valid);$rechecked=$ctrl->acceptedHorsesOf(1,$role);
        $check($stale['attempt_no']===null && !$stale['public_candidates'] && $stale['proof']===null && $rechecked['code']==='FIX_NOT_USABLE','deadline shortened after correction was accepted');
        $db($before,$after);$frow=\R15Table::$tables['baganriki_ai_attempts'][1];
        $frow->sent_at=\r4time($freeze,'+1 second');$record=$corrected;$record['receipts'][1]['sent_at']=$frow->sent_at;
        $d=$ctrl->inspectAiResponse(1,$role,1);$rechecked=$ctrl->acceptedHorsesOf(1,$role);
        $check(!$d['ok'] && $d['code']==='CORRECTION_SENT_BEFORE_FIRST_RECEIVED' && $rechecked['code']==='FIX_NOT_USABLE','correction cannot be sent before initial receipt');
        $refused=false;try {\r9merge($base,[$role=>$record],$in['quota'],$nos,[],\r4live($base,$now));}
        catch (\InvalidArgumentException $e) {$refused=$e->getMessage()==='W2_NO_VERIFIED_ROLE';}
        $check($refused,'same correction order denied in saved publication');
        $frow->sent_at=\r4time($freeze,'+2 seconds');$check($ctrl->acceptedHorsesOf(1,$role)['code']==='FIX_APPLIED','subsequent send at identical microsecond');
        $db($before,$after);BaganrikiWorker::$fixtureNow=BaganrikiTime::us($cut);
        $fixedLater=$ctrl->acceptedHorsesOf(1,$role);$check($fixedLater['code']==='FIX_NOT_USABLE','future correction retained for audit');
        BaganrikiWorker::$fixtureNow=BaganrikiTime::us(\r4time($cut,'+1 second'));
        $check($ctrl->acceptedHorsesOf(1,$role)['code']==='FIX_APPLIED','future correction becomes eligible when clock reaches receipt');
    } finally {BaganrikiWorker::$fixtureNow=null;}
    $healthy=Selection::verifyOriginal($base,$raw,hash('sha256',$raw));$fixed=Selection::verifyOriginal($base,$after,hash('sha256',$after));
    $recovery=\r4recoveryRecord($base,$reply,$original['receipts'][0]);$recovered=$healthy;$recovered['public_proof_hash']=null;
    $broken=$original;$broken['receipts'][0]['complete']='1oops';$early=$original;$early['receipts'][0]['received_at']=\r4time($freeze,'-1 microsecond');
    return [$count,['seed'=>$base,'original'=>[$original,$healthy],'corrected'=>[$corrected,$fixed],
                    'recovery'=>[$recovery,$recovered],'broken'=>[$broken,null],'early'=>[$early,null],'absent'=>[null,null]]];
}

function r153Mixed(array $cases):int
{
    return (require __DIR__.'/r153_mixed_matrix.php')($cases);
}

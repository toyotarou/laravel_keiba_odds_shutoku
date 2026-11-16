<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\RoleJournalW2 as Journal;

return static function(array $cases):int {
    $count=0;
    $check=static function(bool $ok,string $label)use(&$count):void {
        if (!$ok) throw new RuntimeException('R15.3 mixed '.$label);++$count;
    };
    $deny=static function(callable $op,string $code)use($check):void {
        try {$op();}catch(InvalidArgumentException $e){$check($e->getMessage()===$code,$code.' / '.$e->getMessage());return;}
        $check(false,'expected '.$code);
    };
    $seed=$cases['1st']['seed'];$in=Frozen::checked($seed);$by=array_column($in['runners'],'band','horse_no');$all=array_keys($by);
    $groups=array_fill_keys($all,1);$start=$in['input_freeze_at'];$q=$in['quota'];$results=[];
    $vars=['original','corrected','recovery','broken','early','absent'];
    $cut=r4time($start,'+3 seconds');$lateClose=r4time($cut,'+90 seconds');
    foreach ($vars as $left) foreach ($vars as $right) {
        $names=['1st'=>$left,'2nd'=>$right];$rows=[];$proof=[];
        foreach ($names as $role=>$name) {
            [$record,$good]=$cases[$role][$name];if ($record!==null) $rows[$role]=$record;
            $proof[$role]=array_fill_keys($all,$good['public_proof_hash']??$cases[$role]['original'][1]['public_proof_hash']);
        }
        $rows=r9fixtureRecords($rows);$journal=Journal::witness($seed,$rows,$proof);
        foreach (['normal','before corrected receipt','reduced reply deadline'] as $clock) {
            $known=[];
            foreach ($names as $role=>$name) {
                $verified=$cases[$role][$name][1];if ($verified!==null && ($name!=='corrected' || $clock==='normal')) $known[]=$verified;
            }
            foreach ([[],[7],[2,7,8]] as $gone) {
                $active=array_values(array_diff($all,$gone));
                $quota=Ranking::afterCancellation($by,$gone,$q,$in['allocation_mode'])['live_quota'];
                $time=$clock==='before corrected receipt'?$cut:r4time($start,'+10 seconds');
                $ctx=r4live($seed,$time,$clock==='reduced reply deadline'?$lateClose:null,false,$active);
                $ctx['state_revision']=count($gone);$ctx['role_journal']=$journal;
                $merge=static fn()=>Selection::mergeStored($seed,$rows,$quota,$active,$proof,$ctx);
                if (!$known) {$deny($merge,'W2_NO_VERIFIED_ROLE');continue;}
                $actual=$merge();$expected=Selection::merge($seed,$known,$quota,$active,$proof,$groups);
                $label=implode('/',$names).' '.$clock.' '.implode(',',$gone);
                $check($actual['public_candidates']===$expected['public_candidates'] && !array_intersect($gone,$actual['public_candidates']),$label.' exact order without withdrawn horses');
                $check($actual['agreement_bonus']===$expected['agreement_bonus'] && $actual['public_proof_hashes']===$expected['public_proof_hashes'],$label.' proof per remaining role');
                if (count($known)===1) $check(max($actual['agreement_bonus'])===0,$label.' single role no agreement bonus');
                foreach (['actual_quota','fill','shortfalls'] as $field) $check($actual[$field]===$expected[$field],$label.' '.$field);
                Window::assertPublicationCommit($seed,$actual,$ctx['now'],$ctx);$check(true,$label.' fresh commit');
                if ($left==='corrected' && $right==='original' && $clock==='normal' && !$gone) $results=[$actual,$ctx,$rows,$proof];
            }
        }
    }
    [$selected,$ctx,$rows,$votes]=$results;
    $changed=$ctx;$changed['cancelled_horse_nos']=[7];$deny(fn()=>Window::assertPublicationCommit($seed,$selected,$ctx['now'],$changed),'W8_PUBLICATION_CHANGED');
    $changed=$ctx;$changed['live_close_at']=$lateClose;$deny(fn()=>Window::assertPublicationCommit($seed,$selected,$ctx['now'],$changed),'W8_PUBLICATION_CHANGED');
    $changed=$ctx;$different=$rows;$different['1st']['receipts'][1]['received_at']=r4time($start,'+6 seconds');$changed['role_journal']=Journal::witness($seed,$different,$votes);
    $deny(fn()=>Window::assertPublicationCommit($seed,$selected,$ctx['now'],$changed),'W9_JOURNAL_CHANGED');
    $off=r4live($seed,$ctx['now'],null,true,[]);$none=Selection::mergeStored($seed,$rows,null,[],$votes,$off);
    $check($none['selection_state']==='CANCELLED' && $none['public_candidates']===[],'all withdrawn no candidates');
    $deny(fn()=>Window::assertPublicationCommit($seed,$none,$ctx['now'],$off),'W8_NOT_PUBLISHABLE');

    // Both reads are from a mock live-state provider. Never call an external model.
    $pack=static function($tree){$x=Codec::compactWire($tree);if (!$x['ok']) throw new RuntimeException('pack');return $x['wire'];};
    $expand=static function($wire){$x=Codec::expandWire($wire);if (!$x['ok'])throw new RuntimeException('expand');return $x['input'];};
    foreach (['1st','2nd'] as $role) {
        $f=$cases[$role]['seed'];$rs=$cases[$role]['corrected'][0]['receipts'];
        foreach (['FULL','SOURCE_TABLE_V1'] as $fmt) {
            $full=$fmt==='FULL';$packRole=$full?static function(){throw new InvalidArgumentException('explicit full fallback');}:$pack;
            $first=$rs[0];$first['sent_request']=Dispatch::record($f,$fmt,0,Dispatch::systemFor(Frozen::checked($f),$fmt),Dispatch::inputBody($f,$fmt));
            $fix=['first_receipt'=>$first,'repair_scope'=>$rs[1]['repair_scope'],'diagnostics'=>$rs[1]['repair_diagnostics'],'inspection_sha256'=>$rs[1]['inspection_sha256']];
            foreach ([null,$fix] as $repair) foreach (['open','advance','cancel'] as $event) {
                $live=r4live($f,r4time($start,'+10 seconds'));$end=$live;
                if ($event==='advance') $end['live_close_at']=$lateClose;
                if ($event==='cancel') $end['all_cancelled']=true;
                $readCount=0;$read=static function()use(&$readCount,$live,$end){return $readCount++===0?$live:$end;};
                $send=static fn()=>Bridge::prepareSend($f,$packRole,$expand,static fn()=>true,$read,$full,$repair);
                if ($event==='open') {
                    $created=$send();$audit=Dispatch::recordSent($f,$created,$created['messages']);
                    $check($audit['format']===$fmt && $audit['attempt_no']===($repair===null?0:1),'allowed send format and slot');
                } else $deny($send,'W4_SEND_WINDOW');
                $check($readCount===2,'live read before and after preparation');
            }
        }
    }
    return $count;
};

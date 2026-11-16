<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use DateTimeImmutable;
use InvalidArgumentException;
require_once __DIR__.'/DispatchW2.php';
require_once __DIR__.'/RoleJournalW2.php';

final class AttemptWindowW2
{
    private static function need(bool $ok,string $code):void { if (!$ok) throw new InvalidArgumentException($code); }
    /** DB fields are normalized only here: unambiguous 0/1 and their canonical PDO strings. */
    private static function flag(mixed $v,string $invalidCode='W4_DB_FLAG'):bool
    {
        self::need(in_array($v,[true,false,0,1,'0','1'],true),$invalidCode);
        return self::isComplete($v);
    }

    /** Exactly the three affirmative values from a boolean or PDO flag. */
    public static function isComplete(mixed $flag):bool
    {
        return $flag===true || $flag===1 || $flag==='1';
    }

    /** Inclusive at readiness, inspection time and deadline. Preserve the cause for the controller audit. */
    public static function receiveTimeIssue(\DateTimeImmutable $sent,\DateTimeImmutable $got,\DateTimeImmutable $ready,\DateTimeImmutable $now,\DateTimeImmutable $reply):?string
    {
        if ($got<$ready) return 'RESPONSE_BEFORE_INPUT_READY';
        if ($sent<$ready) return 'SENT_BEFORE_INPUT_READY';
        if ($got<$sent) return 'RECEIVED_BEFORE_SENT';
        if ($got>$reply) return 'REPLY_DEADLINE_EXCEEDED';
        if ($got>$now) return 'RESPONSE_IN_FUTURE';
        return null;
    }

    /** Read from the current server clock and live overlay, never from the AI. */
    public static function current(FrozenInputW2 $snapshot,array $state):array
    {
        $in=FrozenInputW2::checked($snapshot);$tc=$in['time_contract'];
        self::need(($state['race_id']??null)===$in['race_id'] && ($state['snapshot_hash']??null)===$in['snapshot_hash'] &&
            array_key_exists('live_close_at',$state) && array_key_exists('all_cancelled',$state),'W4_LIVE_ID');
        self::need(!self::flag($state['race_started'] ?? null, 'RACE_STATUS_UNAVAILABLE'), 'RACE_STARTED');
        $now=ExpectedPacketW2::instant($state['now']??null);$close=ExpectedPacketW2::instant($tc['sales_close_at']);
        if ($state['live_close_at']!==null) $close=min($close,ExpectedPacketW2::instant($state['live_close_at']));
        $publish=min(ExpectedPacketW2::instant($tc['publish_deadline']),$close->modify('-60 seconds'));
        $reply=min(ExpectedPacketW2::instant($tc['reply_deadline']),$publish->modify('-30 seconds'));
        return ['now'=>$now,'reply'=>$reply,'publish'=>$publish,'cancelled'=>self::flag($state['all_cancelled']),'ready'=>$snapshot->readyAt()];
    }

    /** Freeze a set of known horse numbers; PDO's canonical decimal strings represent the same IDs. */
    public static function horseSet(array $items,array $roster,bool $allowRepeats=false):array
    {
        $seen=[];$allowed=array_fill_keys($roster,true);
        foreach ($items as $no) {
            if (is_string($no) && preg_match('/^(?:[1-9]|1[0-8])$/D',$no)===1) $no=(int)$no;
            self::need(is_int($no) && isset($allowed[$no]) && ($allowRepeats || !isset($seen[$no])),'W2_ACTIVE_ROSTER');
            $seen[$no]=true;
        }
        $numbers=array_keys($seen);sort($numbers,SORT_NUMERIC);return $numbers;
    }

    /** Use the official cumulative withdrawals and all three revisions of one consistent DB read. */
    public static function publicationGuard(FrozenInputW2 $snapshot,array $state,array $window):array
    {
        $in=FrozenInputW2::checked($snapshot);$roster=array_column($in['runners'],'horse_no');
        $cancelled=$state['cancelled_horse_nos']??null;
        self::need(is_array($cancelled) && ExpectedPacketW2::isList($cancelled),'W8_LIVE_CANCELLED_SET');
        $active=array_values(array_diff($roster,self::horseSet($cancelled,$roster)));sort($active,SORT_NUMERIC);
        self::need($window['cancelled']===($active===[]),'W4_CANCELLATION_STATE');
        $revisions=[];
        foreach (['state_revision','response_revision','publication_revision'] as $field) {
            $revisions[$field]=self::revision($state[$field]??null);
        }
        $journal=$active && $in['selection_enabled']?RoleJournalW2::read($state['role_journal']??null):null;
        return ['race_id'=>$in['race_id'],'snapshot_hash'=>$in['snapshot_hash'],'role_journal'=>$journal,'revisions'=>$revisions,'active_horse_nos'=>$active,
            'reply_deadline'=>ExpectedPacketW2::utc($window['reply']),'publish_deadline'=>ExpectedPacketW2::utc($window['publish']),
            'checked_at'=>ExpectedPacketW2::utc($window['now'])];
    }

    public static function revision(mixed $v): string
    {
        if (is_int($v)) $v=(string)$v;
        self::need(is_string($v) && preg_match('/^(?:0|[1-9][0-9]{0,19})$/D',$v)===1 &&
            (strlen($v)<20 || strcmp($v,'18446744073709551615')<=0),'W8_LIVE_REVISION');
        return $v;
    }

    private static function checkedSelection(array $selection):array
    {
        $previous=$selection['publication_guard']??null;
        self::need(is_array($previous) && in_array($selection['selection_state']??null,['CANDIDATES_READY','VERIFIED_NO_CANDIDATES'],true),'W8_NOT_PUBLISHABLE');
        $digest=$previous['selection_sha256']??null;unset($selection['publication_guard']['selection_sha256']);
        self::need(is_string($digest) && hash_equals(hash('sha256',FrozenInputW2::nativeJson($selection)),$digest),'W8_PUBLICATION_BYTES');
        return $previous;
    }

    /** Seal the assigned id with the exact payload hash before committing the immutable publication row. */
    public static function publicationSeal(FrozenInputW2 $snapshot,string $intentHash,string $id,string $payloadSha):string
    {
        $in=FrozenInputW2::checked($snapshot);
        return self::sealFor($in['race_id'],$in['snapshot_hash'],$intentHash,$id,$payloadSha);
    }

    private static function sealFor(string $raceId,string $snapshotHash,string $intentHash,string $id,string $payloadSha):string
    {
        self::need(preg_match('/^[a-f0-9]{64}$/D',$intentHash)===1 && preg_match('/^[a-f0-9]{64}$/D',$payloadSha)===1 &&
            trim($id)!=='','W9_REPLAY_ID');
        return hash('sha256',FrozenInputW2::nativeJson(['W9_PUBLISHED_V1',$raceId,$snapshotHash,$intentHash,$id,$payloadSha]));
    }

    /** Already-completed intent: return the immutable publication id after verifying its own saved bytes and scope. */
    public static function replayId(FrozenInputW2 $snapshot,string $intentHash,array $stored):string
    {
        $in=FrozenInputW2::checked($snapshot);
        return self::savedPublication($in['race_id'],$in['snapshot_hash'],$intentHash,$stored)['id'];
    }

    public static function savedPublication(string $raceId,string $snapshotHash,string $intentHash,array $stored):array
    {
        self::need(preg_match('/^[a-f0-9]{64}$/D',$intentHash)===1 && ($stored['intent_hash']??null)===$intentHash &&
            ($stored['race_id']??null)===$raceId && ($stored['snapshot_hash']??null)===$snapshotHash,'W9_REPLAY_ID');
        $id=$stored['publication_id']??null;$raw=$stored['selection_json']??null;$saved=$stored['payload_sha256']??null;
        self::need((is_string($id) && trim($id)!=='') || (is_int($id) && $id>0),'W9_REPLAY_ID');
        self::need(is_string($raw) && strlen($raw)<=4194304 && is_string($saved) && hash_equals(hash('sha256',$raw),$saved),'W9_REPLAY_BYTES');
        $parsed=\App\Http\Controllers\Api\BaganrikiJson::decode($raw);
        self::need($parsed['ok'] && is_array($parsed['value']),'W9_REPLAY_BYTES');
        $guard=self::checkedSelection($parsed['value']);
        self::need(($guard['race_id']??null)===$raceId && ($guard['snapshot_hash']??null)===$snapshotHash,'W9_REPLAY_ID');
        self::need(($stored['publication_sha256']??null)===self::sealFor($raceId,$snapshotHash,$intentHash,(string)$id,$saved),'W9_REPLAY_RECORD');
        return ['id'=>(string)$id,'selection'=>$parsed['value']];
    }

    /** Call under the existing live_state/intent locks immediately before writing a new publication. */
    public static function assertPublicationCommit(FrozenInputW2 $snapshot,array $selection,string $validationCompletedAt,array $lockedState):void
    {
        $previous=self::checkedSelection($selection);
        $window=self::current($snapshot,$lockedState);$fresh=self::publicationGuard($snapshot,$lockedState,$window);
        foreach ($fresh['revisions'] as $key=>$value) self::need(($previous['revisions'][$key]??null)===$value,'W8_PUBLICATION_CHANGED');
        foreach (['race_id','snapshot_hash','active_horse_nos','reply_deadline','publish_deadline'] as $key)
            self::need(($previous[$key]??null)===$fresh[$key],'W8_PUBLICATION_CHANGED');
        self::need(is_array($previous['role_journal']??null) && is_array($fresh['role_journal']),'W9_JOURNAL_CHANGED');
        foreach ($fresh['role_journal'] as $role=>$stamp) self::need(array_key_exists($role,$previous['role_journal']) &&
            $previous['role_journal'][$role]===$stamp,'W9_JOURNAL_CHANGED');
        $start=ExpectedPacketW2::instant($previous['checked_at']??null);$end=ExpectedPacketW2::instant($validationCompletedAt);
        self::need(!$window['cancelled'] && $window['ready']<=$start && $start<=$end && $end<=$window['now'] &&
            $window['now']<=$window['publish'],'W8_COMMIT_WINDOW');
    }

    public static function dispatch(FrozenInputW2 $snapshot,array $state):array
    {
        $w=self::current($snapshot,$state);
        self::need(!$w['cancelled'] && $w['now']>=$w['ready'] && $w['now']<=$w['reply'],'W4_SEND_WINDOW');
        return $w;
    }

    /** Adapt the trusted r12.2 first inspection. The first hash must come from the stored attempt. */
    public static function repairGrant(string $firstHash,array $inspection):array
    {
        self::need(preg_match('/^[a-f0-9]{64}$/D',$firstHash)===1,'W5_REPAIR_SCOPE');
        $out=['first_response_hash'=>$firstHash];
        foreach (['horses','summary_horses','presentation_horses','rank_bands'] as $field) {
            $items=$inspection[$field]??null;
            self::need(is_array($items) && ExpectedPacketW2::isList($items),'W5_REPAIR_SCOPE');
            $ids=[];$allBands=false;
            foreach ($items as $val) {
                if ($field==='rank_bands') {
                    self::need(in_array($val,['A','B','D','band_ranks','evidence_band_ranks'],true),'W5_REPAIR_SCOPE');
                    if ($val==='band_ranks' || $val==='evidence_band_ranks') { $allBands=true;continue; }
                } else {
                    if (is_string($val) && preg_match('/^(?:[1-9]|1[0-8])$/D',$val)===1) $val=(int)$val;
                    self::need(is_int($val) && $val>=1 && $val<=18,'W5_REPAIR_SCOPE');
                }
                $ids[$val]=true;
            }
            $out[$field]=$allBands?['A','B','D']:array_keys($ids);
        }
        return $out;
    }

    /** Normalize only the first inspection's saved permission to repair. Never infer it from the fix. */
    public static function scope(mixed $scope,string $firstHash,array $in):array
    {
        self::need(is_array($scope) && count($scope)===5 && ($scope['first_response_hash']??null)===$firstHash,'W5_REPAIR_SCOPE');
        $roster=array_column($in['runners'],'horse_no');$out=[];
        foreach (['horses','summary_horses','presentation_horses','rank_bands'] as $field) {
            $list=$scope[$field]??null;
            self::need(is_array($list) && ExpectedPacketW2::isList($list),'W5_REPAIR_SCOPE');
            $valid=$field==='rank_bands'?['A','B','D']:$roster;$out[$field]=[];
            foreach ($list as $id) {
                self::need(in_array($id,$valid,true) && !isset($out[$field][$id]),'W5_REPAIR_SCOPE');
                $out[$field][$id]=true;
            }
        }
        return $out;
    }

    /** Returns structurally admissible saved attempts and per-attempt errors. A bad fix cannot replace a good first response. */
    public static function receipts(FrozenInputW2 $snapshot,array $rows,array $window):array
    {
        $in=FrozenInputW2::checked($snapshot);$slots=[];$valid=[];$errors=[];
        self::need(count($rows)>=1 && count($rows)<=2,'W4_RECEIPT_COUNT');
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['attempt_no']??null,[0,1,'0','1'],true)) {
                $errors['unidentified']='W4_ATTEMPT_NO';continue;
            }
            $slot=(int)$row['attempt_no'];self::need(!isset($slots[$slot]),'W4_DUPLICATE_ATTEMPT');$slots[$slot]=$row;
        }
        self::need(isset($slots[0]),'W4_FIRST_REQUIRED');ksort($slots,SORT_NUMERIC);
        foreach ($slots as $no=>$r) {
            try {
                self::need(($r['ai_role']??null)===$in['ai_role'] && ($r['snapshot_hash']??null)===$in['snapshot_hash'] &&
                    ($r['race_id']??null)===$in['race_id'],'W4_RECEIPT_ID');
                self::need(($r['status']??null)==='RECEIVED' && self::flag($r['complete']??null),'W4_RESPONSE_INCOMPLETE');
                $approved=self::flag($r['original_approved']??null);
                self::need(is_string($r['raw']??null) && strlen($r['raw'])<=4194400 &&
                    is_string($r['response_hash']??null) && hash_equals(hash('sha256',$r['raw']),$r['response_hash']),'W4_RECEIPT_BYTES');
                $sent=ExpectedPacketW2::instant($r['sent_at']??null);$got=ExpectedPacketW2::instant($r['received_at']??null);
                self::need(self::receiveTimeIssue($sent,$got,$window['ready'],$window['now'],$window['reply'])===null,'W4_RECEIPT_TIME');
                self::need($no===0 || (isset($valid[0]) && $sent>=$valid[0]['received']),'W4_CORRECTION_ORDER');
                $scope=$no===1?self::scope($r['repair_scope']??null,$valid[0]['hash'],$in):null;
                if ($no===1) {
                    self::need(($r['sent_request']['format']??null)===$valid[0]['format'],'W9_FIX_FORMAT');
                    self::need(($r['inspection_sha256']??null)===DispatchW2::inspectionSeal($snapshot,$valid[0]['hash'],$r['repair_scope'],$r['repair_diagnostics']??null),'W9_FIX_INSPECTION');
                }
                DispatchW2::verifyReceipt($snapshot,$r['sent_request']??null,$no,$valid[0]['raw']??null,$scope,$r['repair_diagnostics']??null);
                $valid[$no]=['format'=>$r['sent_request']['format'],'raw'=>$r['raw'],'hash'=>$r['response_hash'],'approved'=>$approved,'received'=>$got,'scope'=>$scope];
            } catch (InvalidArgumentException $ex) { $errors[$no]=$ex->getMessage(); }
        }
        return [$valid,$errors];
    }
}

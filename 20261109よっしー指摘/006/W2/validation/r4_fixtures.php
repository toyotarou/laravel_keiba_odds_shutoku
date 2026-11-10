<?php
declare(strict_types=1);
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\ExpectedPacketW2 as Packet;

function r4live(Frozen $snap,?string $now=null,?string $close=null,bool $cancelled=false,?array $active=null):array {
    $in=Frozen::checked($snap);
    $roster=array_column($in['runners'],'horse_no');
    $withdrawals=$cancelled?$roster:array_values(array_diff($roster,$active??$roster));
    return ['state_revision'=>0,'response_revision'=>0,'publication_revision'=>0,'cancelled_horse_nos'=>$withdrawals,
        'race_id'=>$in['race_id'],'snapshot_hash'=>$in['snapshot_hash'],'now'=>$now??$in['time_contract']['reply_deadline'],'live_close_at'=>$close,'all_cancelled'=>$cancelled,'race_started'=>false];
}
function r4time(string $start,string $change):string {
    return Packet::instant($start)->modify($change)->format('Y-m-d\TH:i:s.uP');
}
function r4receipt(Frozen $snap,string $raw,int $attempt=0,bool $approved=true):array {
    $in=Frozen::checked($snap);$start=$in['input_freeze_at'];
    return ['attempt_no'=>$attempt,'race_id'=>$in['race_id'],'snapshot_hash'=>$in['snapshot_hash'],'ai_role'=>$in['ai_role'],'status'=>'RECEIVED','complete'=>true,'original_approved'=>$approved,
        'raw'=>$raw,'response_hash'=>hash('sha256',$raw),'sent_at'=>r4time($start,$attempt?'+3 seconds':'+1 second'),'received_at'=>r4time($start,$attempt?'+4 seconds':'+2 seconds')];
}
function r4original(Frozen $snap,string $raw):array {
    return ['kind'=>'ORIGINAL','input'=>$snap,'receipts'=>[r4receipt($snap,$raw)]];
}
function r4recoveryDoc(Frozen $snap,array $reply,array $receipt):array {
    $schema=json_decode(file_get_contents(__DIR__.'/../schemas/recovery_schema.json'),true,512,JSON_THROW_ON_ERROR);
    $doc=array_intersect_key(Frozen::checked($snap),array_flip(['schema_version','prompt_version','race_id','snapshot_hash','ai_role']));
    $keys=$schema['properties']['horses']['items']['properties'];$doc['horses']=[];$orig=[];
    foreach ($reply['horses'] as $h) { $doc['horses'][]=array_intersect_key($h,$keys);$orig[$h['horse_no']]=[$receipt['response_hash']]; }
    $doc['base_ranks']=$reply['band_ranks'];$doc['row_sources']=(object)$orig;$doc['rank_sources']=array_fill_keys(['A','B','D'],$receipt['response_hash']);
    return $doc;
}
function r4seal(array $doc,array $receipts,Frozen $snap):array {
    $raw=r3bytes($doc);
    return ['kind'=>'RECOVERED','input'=>$snap,'receipts'=>$receipts,'recovery_json'=>$raw,'recovery_hash'=>hash('sha256',$raw)];
}
function r4recoveryRecord(Frozen $snap,array $reply,array $receipt):array {
    return r4seal(r4recoveryDoc($snap,$reply,$receipt),[$receipt],$snap);
}

function r5scope(array $first,array $horses=[],array $bands=[],array $summary=[],array $presentation=[]):array {
    return ['first_response_hash'=>$first['response_hash'],'horses'=>$horses,'rank_bands'=>$bands,'summary_horses'=>$summary,'presentation_horses'=>$presentation];
}

/** Upgrade earlier synthetic receipt fixtures to the same new FULL sender contract. */
function r9diagnostics():array {
    return ['errors'=>[['scope'=>'band','target'=>'A','code'=>'FIXTURE','reason'=>'saved initial inspection']],
        'missing_horses'=>[],'duplicated_horses'=>[],'unknown_horses'=>[],'isolated_horses'=>[]];
}
function r9fixtureRecords(array $records):array {
    foreach (['1st','2nd'] as $role) {
        $record=$records[$role]??null;$f=$record['input']??null;
        if (!$f instanceof Frozen || !is_array($record['receipts']??null)) continue;
        $in=Frozen::checked($f);$system=\App\Services\Baganriki\DispatchW2::systemFor($in,'FULL');$first=null;
        foreach ($record['receipts'] as $r) if (is_array($r) && in_array($r['attempt_no']??null,[0,'0'],true) && is_string($r['raw']??null)) { $first=$r;break; }
        foreach ($record['receipts'] as $pos=>$r) {
            if (!is_array($r) || isset($r['sent_request']) || !in_array($r['attempt_no']??null,[0,1,'0','1'],true)) continue;
            $number=(int)$r['attempt_no'];$body=$f->raw();
            if ($number===1) {
                if (!$first) continue;
                try { $scope=\App\Services\Baganriki\AttemptWindowW2::scope($r['repair_scope']??null,(string)($first['response_hash']??''),$in); }
                catch (InvalidArgumentException $badScope) { continue; }
                $r['repair_diagnostics']=r9diagnostics();
                $r['inspection_sha256']=\App\Services\Baganriki\DispatchW2::inspectionSeal($f,$first['response_hash'],$r['repair_scope'],$r['repair_diagnostics']);
                $body=\App\Services\Baganriki\DispatchW2::correctionBody($f,$body,$first['raw'],$scope,$r['repair_diagnostics']);
            }
            $r['sent_request']=\App\Services\Baganriki\DispatchW2::record($f,'FULL',$number,$system,$body);
            $record['receipts'][$pos]=$r;
        }
        $records[$role]=$record;
    }
    return $records;
}
function r9merge(Frozen $snapshot,array $records,?array $quota,array $nos,array $proof,array $context):array {
    $records=r9fixtureRecords($records);
    if (!array_key_exists('role_journal',$context)) $context['role_journal']=\App\Services\Baganriki\RoleJournalW2::witness($snapshot,$records,$proof);
    return \App\Services\Baganriki\CandidateSelectionW2::mergeStored($snapshot,$records,$quota,$nos,$proof,$context);
}
function r9commit(Frozen $snapshot,array $selection,string $time,array $state):void {
    $state['role_journal']??=$selection['publication_guard']['role_journal']??null;
    \App\Services\Baganriki\AttemptWindowW2::assertPublicationCommit($snapshot,$selection,$time,$state);
}

<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Http\Controllers\Api\BaganrikiResponse as Reply;
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\RoleJournalW2 as Journal;
use App\Services\Baganriki\DispatchW2 as Dispatch;
require_once __DIR__.'/../php/ExpectedBridgeW2.php';require $argv[1];require __DIR__.'/r4_fixtures.php';
function dbJson(array $x):string { return json_encode($x,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION); }
function dbSend(Frozen $f,array $state):array {
    $pack=static function($t){$c=Codec::compactWire($t);if(!$c['ok'])throw new RuntimeException($c['reason']);return $c['wire'];};
    $back=static function($t){$c=Codec::expandWire($t);if(!$c['ok'])throw new RuntimeException($c['reason']);return $c['input'];};
    return Bridge::prepareSend($f,$pack,$back,static fn()=>true,static fn()=>$state);
}
function preparedRows(string $dir):array {
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);$rows=[];$records=[];$votes=[];
    foreach (['1st','2nd'] as $role) {
        $input=file_get_contents("$dir/{$role}_input.json");$in=json_decode($input,true,512,JSON_THROW_ON_ERROR);$common=Codec::commonHash($in);
        $f=Frozen::fromStored($common['json'],$input,$common['hash'],$role,$proof);
        $sent=dbSend($f,r4live($f,r4time($in['input_freeze_at'],'+1 second')));
        $raw=Reply::BEGIN."\n".file_get_contents("$dir/{$role}_output.json")."\n".Reply::END;
        $receipt=r4receipt($f,$raw);$receipt['sent_request']=Dispatch::recordSent($f,$sent,$sent['messages']);
        $records[$role]=['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$receipt]];unset($receipt['raw']);
        $accepted=Sel::verifyOriginal($f,$raw,$receipt['response_hash']);
        foreach ($accepted['base_candidates'] as $n)$votes[$role][$n]=$receipt['response_hash'];
        $rows[]=['role'=>$role,'input'=>$input,'original'=>$raw,'receipt'=>$receipt,'proof_horses'=>$accepted['base_candidates']];
    }
    return ['race_id'=>$in['race_id'],'common'=>$common['json'],'hash'=>$common['hash'],'witness'=>$proof,'roles'=>$rows,
        'role_journal'=>Journal::witness($f,$records,$votes),'numbers'=>array_column($in['runners'],'horse_no'),
        'now'=>$in['time_contract']['reply_deadline'],'publish_deadline'=>$in['time_contract']['publish_deadline']];
}
function replayRows(array $p,string $phase):array {
    $rs=[];$proofs=[];$formats=[];$live=$p['live'];
    foreach ($p['roles'] as $r) {
        $role=$r['role'];$f=Frozen::fromStored($p['common'],$r['input'],$p['hash'],$role,$p['witness']);$in=Frozen::checked($f);
        if (!isset($main)||$role==='1st')$main=$f;
        if ($p['send']) {
            $formatted=dbSend($f,$live);$formats[$role]=[$formatted['format'],$formatted['user_sha256'],$formatted['system_sha256']];
        }
        $rs[$role]=['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$r['receipt']+['raw'=>$r['original']]]];
        $proofHash=$r['receipt']['response_hash'];
        if (isset($r['correction'])) {
            $rs[$role]['receipts'][]=$r['correction'];
            $proofHash=$r['correction']['response_hash'];
        }
        foreach ($r['proof_horses'] as $n)$proofs[$role][$n]=$proofHash;
    }
    if($phase==='adopt')return ['role_journal'=>Journal::witness($main,$rs,$proofs)];
    $by=array_column($in['runners'],'band','horse_no');$active=array_values(array_diff(array_keys($by),$p['cancelled']));
    $q=Ranking::afterCancellation($by,$p['cancelled'],$in['quota'],$in['allocation_mode'])['live_quota'];
    return ['result'=>Sel::mergeStored($main,$rs,$q,$active,$proofs,$live),'formats'=>$formats,'requested_quota'=>$q];
}
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) return;
try {
    $phase=$argv[2];
    if ($phase==='export') $result=preparedRows($argv[3]);
    else {
        $p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
        if ($phase==='record_correction_fixture') {
            $f=Frozen::fromStored($p['common'],$p['input'],$p['hash'],$p['role'],$p['witness']);
            $first=$p['original'];$hash=hash('sha256',$first);$scope=Window::scope($p['scope'],$hash,Frozen::checked($f));
            $format=$p['format'];$input=Dispatch::inputBody($f,$format);
            $body=Dispatch::correctionBody($f,$input,$first,$scope,$p['diagnostics']);
            $result=['inspection_sha256'=>Dispatch::inspectionSeal($f,$hash,$p['scope'],$p['diagnostics']),
                'sent_request'=>Dispatch::record($f,$format,1,Dispatch::systemFor(Frozen::checked($f),$format),$body)];
        } else if (in_array($phase,['commit','replay','seal_publication'],true)) {
            $f=Frozen::fromStored($p['common'],$p['roles'][0]['input'],$p['hash'],'1st',$p['witness']);
            if ($phase==='seal_publication') $result=['sha256'=>Window::publicationSeal($f,$p['intent'],$p['publication_id'],$p['payload_sha256'])];
            elseif ($phase==='replay') $result=['publication_id'=>Window::replayId($f,$p['intent'],$p['stored'])];
            else { Window::assertPublicationCommit($f,$p['selection'],$p['finished_at'],$p['live']);$result=['authorized'=>true]; }
        } else $result=replayRows($p,$phase);
    }
    echo dbJson(['ok'=>true,'data'=>$result]);
} catch (InvalidArgumentException $rejected) { echo dbJson(['ok'=>false,'reason'=>$rejected->getMessage()]); }

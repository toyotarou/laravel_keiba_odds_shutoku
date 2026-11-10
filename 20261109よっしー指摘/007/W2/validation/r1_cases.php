<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;

// Shared synthetic fixtures are created by verify_w2.php. Keep scenario mutations local.
(function () use ($i,$o,$off,$ins,$replies,$roles,$groups,$nr,$m,$base,$c,$history,$unavailable) {
    $both=fixtureProof($roles);
    $merge=static fn($q,$active,$g=null)=>Sel::merge($i,$roles,$q,$active,$both,$g??$groups);
    $q7=['A'=>3,'B'=>3,'D'=>1]; $q6=['A'=>3,'B'=>2,'D'=>1]; $q5=['A'=>3,'B'=>2,'D'=>0]; $q4=['A'=>2,'B'=>2,'D'=>0];
    foreach ([[16,$q7],[15,$q6],[14,$q6],[13,$q5],[9,$q5],[8,$q4],[6,['A'=>4,'B'=>0,'D'=>0]],[3,['A'=>3,'B'=>0,'D'=>0]],[1,['A'=>1,'B'=>0,'D'=>0]]] as [$n,$q]) {
        $active=range(1,$n); $p=$merge($q,$active)['public_candidates'];
        check(count($p)===array_sum($q) && count($p)===count(array_unique($p)) && !array_diff($p,$active),
            "R1 active $n: expected structural quota, no cancelled runner");
    }
    foreach ([[15,$q7],[13,$q6],[8,$q5]] as [$n,$stale])
        reject(fn()=>$merge($stale,range(1,$n)),"R1 rejects stale quota after crossing to $n runners");
    reject(fn()=>$merge(['A'=>6,'B'=>4,'D'=>6],$nr),'R1 rejects 16 requested candidates with C7');
    reject(fn()=>$merge(['A'=>4,'B'=>2,'D'=>1],$nr),'R1 rejects wrong split even when total is seven');
    reject(fn()=>$merge(null,$nr),'R1 rejects null quota on an active enabled race');
    reject(fn()=>$merge($q7,[]),'R1 rejects stale quota with all runners cancelled');
    check($merge(null,[])['public_candidates']===[],'R1 all cancelled yields zero public candidates');
    $remaining=[11,12,13]; $late=$merge(['A'=>0,'B'=>0,'D'=>3],$remaining);
    check(count($late['public_candidates'])===3 && !array_diff($late['public_candidates'],$remaining),'R1 three remaining original D runners stay in D');
    check($merge(['D'=>1,'B'=>3,'A'=>3],array_reverse($nr))===$m,'R1 object-key order and active list order do not change result');
    check($merge($q7,array_map('strval',$nr))===$m,'R1 canonical DB string horse numbers retain selections');
    foreach (['01','1.0','+1',true,19] as $bad)
        reject(fn()=>$merge($q7,[$bad,...array_slice($nr,1)]),'R1 ambiguous or unknown active horse '.json_encode($bad));
    reject(fn()=>$merge($q7,[...$nr,'1']),'R1 duplicates across DB integer and string formats');
    $active=array_values(array_diff($nr,[10])); $liveGroups=$groups; unset($liveGroups[10]);
    check($merge($q6,$active,$liveGroups)===$merge($q6,$active),'R1 cancelled group data not required');
    unset($liveGroups[1]);reject(fn()=>$merge($q6,$active,$liveGroups),'R1 missing active evidence group rejected');
    $single=Sel::merge($i,[$roles['2nd']],$q7,$nr,$both,$groups);
    check(count($single['public_candidates'])===7 && max($single['agreement_bonus'])===0,'R1 single verified role has no consensus bonus');
    $badRole=$roles['1st'];$badRole['selection_ranks']['A'][]=1;
    reject(fn()=>Sel::merge($i,[$badRole],$q7,$nr,[],$groups),'R1 repeated rank rejected at merge');
    $badRole=$roles['1st'];$badRole['prompt_version']='old';
    reject(fn()=>Sel::merge($i,[$badRole],$q7,$nr,[],$groups),'R1 old release role refused at merger');
    $badRole=$roles['1st'];$badRole['base_candidates'][1]=$badRole['base_candidates'][0];
    reject(fn()=>Sel::merge($i,[$badRole],$q7,$nr,$both,$groups),'R1 duplicate baseline cannot inflate agreement');
    $forged=$roles['1st'];$forged['base_candidates']=$forged['public_candidates'];
    reject(fn()=>Sel::merge($i,[$forged],$q7,$nr,$both,$groups),'R1 E-only candidates cannot be recorded as original agreement');
    $forged=$roles['1st'];$forged['expected_reviews']['D']['incumbent']=16;
    reject(fn()=>Sel::merge($i,[$forged],$q7,$nr,$both,$groups),'R1 mismatched original boundary evidence refused');
    $bad=$ins['2nd'];$bad['role_quota']=$q7;$bad['role_cap']=7;
    reject(fn()=>Sel::apply($bad,$replies['2nd']['horses'],$replies['2nd']['band_ranks']),'R1 second role cannot take seven frozen slots');
    $bad=$i; $bad['role_quota']=['A'=>4,'B'=>2,'D'=>1];
    reject(fn()=>Sel::apply($bad,$o['horses'],$o['band_ranks']),'R1 frozen role quota must match allocation');
    $b=$o;$b['mode']='FIRM';$b['allocation_mode']='FIRM';
    reject(fn()=>Sel::verifyReply($i,$b),'R1 inconsistent tendency echo rejected');
    foreach (['target_count'=>6,'role_cap'=>5,'mode_status'=>'PROVISIONAL','mode_policy_id'=>'X6_ONLY_UPPER_MODE_V1','quota'=>$q6,'learning_stage'=>'SHADOW'] as $key=>$wrong) {
        $b=$o;$b[$key]=$wrong;
        reject(fn()=>Sel::verifyReply($i,$b),"R1 rejects changed $key");
    }
    $b=$o;$b['quota']=array_reverse($b['quota'],true);$b['role_quota']=array_reverse($b['role_quota'],true);
    check(Sel::verifyReply($i,$b)===array_replace($roles['1st'],['public_proof_hash'=>null]),'R1 quota object order is irrelevant');
    $short=['band'=>'D','requested'=>1,'eligible'=>0,'missing'=>1,'reason'=>'合成試験の適格馬不足'];
    $b=$o;$b['shortfalls']=[$short];reject(fn()=>Sel::verifyReply($i,$b),'R1 invented shortfall rejected');
    $draft=$o;
    foreach ($draft['horses'] as &$h) if ($h['band']==='D') {
        $h['eligibility']='NO_EVIDENCE'; $h['tier']='C'; $h['nonselect_reason']=['code'=>'NO_EVIDENCE','reason'=>'合成試験の根拠なし'];
    }
    unset($h);
    $draft['band_ranks']['D']=[]; $draft['evidence_band_ranks']['D']=[]; $draft['shortfalls']=[$short];
    $withShort=makeReply($i,$draft);
    check(count(Sel::verifyReply($i,$withShort)['public_candidates'])===6,'R1 true D shortfall leaves one slot unselected within the role');
    foreach ([[],[$short,$short],[array_replace($short,['eligible'=>1])],[array_replace($short,['missing'=>2])],[array_replace($short,['reason'=>' '])]] as $j=>$invalid) {
        $b=$withShort;$b['shortfalls']=$invalid;
        reject(fn()=>Sel::verifyReply($i,$b),"R1 bad or missing shortfall $j rejected");
    }
    $b=$withShort;$b['horses'][10]['nonselect_reason']['code']='UNVERIFIED';
    reject(fn()=>Sel::verifyReply($i,$b),'R1 rejects factually wrong nonselection code');
    $b=$o;$b['evidence_band_ranks']['A']=array_reverse($b['evidence_band_ranks']['A']);
    reject(fn()=>Sel::verifyReply($i,$b),'R1 EVIDENCE mode requires consistent primary and backup rank');
    $disabled=makeReply($off,$o);
    check(Sel::verifyReply($off,$disabled)['public_candidates']===[],'R1 unresolved input retains full role output with no public picks');
    $b=$disabled;$b['shortfalls']=[$short];reject(fn()=>Sel::verifyReply($off,$b),'R1 disabled role cannot declare shortfalls');
    $pro=marketFixture($i,'PROV_MIXED');
    $pro=freezeFixture($pro);
    check(count(Sel::verifyReply($pro,makeReply($pro,$o))['public_candidates'])===7,'R1 legitimate provisional mode still selects');
    $b=$i;$b['mode_status']='UNRESOLVED';
    reject(fn()=>Sel::apply(freezeFixture($b),$o['horses'],$o['band_ranks']),'R1 unresolved mode cannot enable selection');
    $b=$i;$b['runners'][0]['win_rank']=2;
    reject(fn()=>Sel::apply(freezeFixture($b),$o['horses'],$o['band_ranks']),'R1 duplicate frozen popularity rank rejected');
    $past=$history[0];$prior=$history[1];
    foreach (['scratch','unknown','surface'] as $type) {
        $conflict=$past;
        if ($type==='scratch') $conflict['post_freeze_scratch']=true;
        if ($type==='unknown') $conflict['results'][0]['finish']='UNKNOWN';
        if ($type==='surface') $conflict['surface']='TURF';
        foreach ([[$past,$conflict,$prior],[$prior,$conflict,$past]] as $j=>$rows) {
            $x=Packet::build($c,$rows);
            check($x['packet']['rows'][0]['M']===1 && $x['audit']['pool_ids']===[$prior['race_id']] &&
                $x['audit']['excluded']['DUPLICATE_HISTORY_ID']===2,"R1 known $type conflict isolated, order $j");
        }
    }
    $future=$past;$future['post_freeze_scratch']=true; $future['result_available_at']=$i['expected_v01']['history_before'];
    $one=Packet::build($c,[$past,$prior]);$late=Packet::build($c,[$future,$past,$prior]);
    check($one['packet']===$late['packet'] && $late['audit']['excluded']['HISTORY_AVAILABILITY']===1,'R1 correction visible at JST midnight cannot affect earlier cutoff');
    $known=$past;$known['result_available_at']=(new DateTimeImmutable($future['result_available_at']))->modify('-1 microsecond')->format('Y-m-d\TH:i:s.uP');
    check(Packet::build($c,[$past,$known])['packet']['rows'][0]['M']===0,'R1 correction one microsecond before midnight counts as conflicting');
    $timed=$c; $timed['batches'][6]['available_at']=(new DateTimeImmutable($c['input_freeze_at']))->modify('+1 microsecond')->format('Y-m-d\TH:i:s.uP');
    check(Packet::build($timed,[])['packet']['rows'][0]['reason']==='INCOMPLETE_ODDS6','R1 one microsecond after final odds deadline rejects late quotes');
    $timed=$c;$timed['post_at']='2026-05-03T15:00:00+09:60';
    reject(fn()=>Packet::build($timed,[]),'R1 malformed UTC offset rejected');
    $bad=$c;unset($bad['batches'][6]['quotes']);reject(fn()=>Bridge::attach($base,$bad,[]),'R1 adopted batch must contain a quote list');
    $wrong=$c;$wrong['batches'][21]['quotes']=array_combine(range(1,16),$wrong['batches'][21]['quotes']);
    reject(fn()=>Bridge::attach($base,$wrong,[]),'R1 keyed object cannot stand in for an adopted JSON array');
})();

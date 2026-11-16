<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
function rejectsWith(callable $fn,string $code,string $label):void {
    try { $fn(); } catch (InvalidArgumentException $e) {
        check($e->getMessage()===$code,$label.' ['.$e->getMessage().']');return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
(function () use ($i,$o,$off,$ins,$replies,$roles,$groups,$nr,$m,$unavailable,$history,$c) {
    $changed=$i;$changed['expected_v01']=$unavailable;
    rejectsWith(fn()=>Sel::apply($changed,$o['horses'],$o['band_ranks']),'W2_SNAPSHOT_HASH','R2 unchanged hash with swapped same-race E history refused');
    rejectsWith(fn()=>Sel::verifyReply($changed,$o),'W2_SNAPSHOT_HASH','R2 response path independently verifies its common input');
    rejectsWith(fn()=>Sel::merge($changed,$roles,$i['quota'],$nr,[],$groups),'W2_SNAPSHOT_HASH','R2 merge independently verifies its common input');
    $new=freezeFixture($changed);
    $p=Sel::apply($new,$o['horses'],$o['band_ranks']);
    check($new['snapshot_hash']!==$i['snapshot_hash'] && $p['public_candidates']===$p['base_candidates'],'R2 real missing E snapshot receives a different ID and keeps base ranks');
    rejectsWith(fn()=>Sel::merge($new,$roles,$new['quota'],$nr,[],$groups),'W2_MERGE_SOURCE','R2 roles from the old version of this same race cannot enter a new merge');
    $bad=$i;$bad['source_registry']=[];rejectsWith(fn()=>Frozen::checked($bad),'W2_SNAPSHOT_HASH','R2 changed source references refused');
    $bad=$i;$bad['snapshot_hash_version']='unsupported';rejectsWith(fn()=>Frozen::checked(freezeFixture($bad)),'W2_SNAPSHOT_HASH','R2 hash protocol version pinned');

    foreach ([999,0,0.99] as $nEff) {
        $bad=$i;$bad['expected_v01']['rows'][0]['n_eff']=$nEff;
        rejectsWith(fn()=>Sel::apply(freezeFixture($bad),$o['horses'],$o['band_ranks']),'W2_EFFECTIVE_SAMPLE_SIZE','R2 impossible n_eff '.(string)$nEff.' rejected even in newly sealed data');
    }
    $bad=$i;$bad['expected_v01']['rows'][0]['M']++;
    rejectsWith(fn()=>Sel::apply(freezeFixture($bad),$o['horses'],$o['band_ranks']),'W2_POOL_SIZE','R2 same race cannot have two baseline sample counts');
    $bad=$i;$bad['expected_v01']['rows'][0]['n_eff']=($i['expected_v01']['rows'][0]['W']-1);
    rejectsWith(fn()=>Sel::apply(freezeFixture($bad),$o['horses'],$o['band_ranks']),'W2_EFFECTIVE_SAMPLE_SIZE','R2 n_eff must also support the published total weight');
    $near=$i;$r=&$near['expected_v01']['rows'][0];$r['n_eff']=$r['K']+PHP_FLOAT_EPSILON*$r['K'];unset($r);
    check(count(Sel::apply(freezeFixture($near),$o['horses'],$o['band_ranks'])['public_candidates'])===7,'R2 allows floating-point rounding at the mathematical sample cap');
    $missing21=$c;array_splice($missing21['batches'][21]['quotes'],9,1);
    $mix=$i;$mix['expected_v01']=\App\Services\Baganriki\ExpectedPacketW2::build($missing21,$history)['packet'];
    $r=Sel::apply(freezeFixture($mix),$o['horses'],$o['band_ranks']);
    check($r['expected_reviews']['B']['decision']==='E_UNAVAILABLE' && $r['expected_reviews']['D']['decision']==='SWAP','R2 real single-horse 21-minute gap isolates the affected boundary');

    $draft=$o;$caps=json_decode(file_get_contents(__DIR__.'/../config/w2_contract.json'),true)['score_caps'];
    $hv=[1=>[6,2],2=>[3,5],3=>[0,8],4=>[3,5],5=>[3,5],6=>[9,0]];
    foreach ($draft['horses'] as &$h) if ($h['band']==='A') {
        [$hp,$vp]=$hv[$h['horse_no']]; $h['scores']=array_fill_keys(array_keys($caps),0);
        $h['scores']['H1']=$hp;$h['scores']['V1']=$vp;
        $h['total_score']=$h['observed_sum']=$hp+$vp;$h['observed_cap']=100;$h['score_status']='complete';$h['rank_basis']='SCORE';
    }
    unset($h);
    $draft['band_ranks']['A']=[6,3,2,4,5,1];
    $good=makeReply($new,$draft);
    check(Sel::verifyReply($new,$good)['selection_ranks']['A']===[6,3,2,4,5,1],'R2 SCORE total, V subtotal and horse-number ties follow the prompt');
    foreach ([[1,2,3,4,5,6],[6,2,3,4,5,1],[6,3,4,2,5,1]] as $j=>$order) {
        $bad=$good;$bad['band_ranks']['A']=$order;
        rejectsWith(fn()=>Sel::verifyReply($new,$bad),'W2_BASE_SCORE_ORDER_A',"R2 incorrect SCORE ordering $j rejected before E can promote any runner");
    }
    $bad=$good;$bad['horses'][0]['rank_basis']='EVIDENCE';
    rejectsWith(fn()=>Sel::verifyReply($new,$bad),'W2_BAND_RANK_BASIS_A','R2 complete band cannot choose EVIDENCE');
    foreach (['observed_cap'=>99,'observed_sum'=>2,'total_score'=>99,'score_status'=>'partial'] as $k=>$v) {
        $bad=$good;$bad['horses'][0][$k]=$v;
        rejectsWith(fn()=>Sel::verifyReply($new,$bad),'W2_SCORE_TOTAL','R2 inconsistent score summary '.$k);
    }
    $bad=$good;$bad['horses'][0]['scores']['V2']=11;
    rejectsWith(fn()=>Sel::verifyReply($new,$bad),'W2_SCORE_COMPONENT','R2 component above cap rejected');
    $partial=$draft;$partial['horses'][0]['scores']['V1']=null;
    $partial['horses'][0]['score_status']='partial';$partial['horses'][0]['total_score']=null;
    $partial['horses'][0]['observed_sum']=6;$partial['horses'][0]['observed_cap']=88;
    foreach ($partial['horses'] as &$h) if ($h['band']==='A') $h['rank_basis']='EVIDENCE';unset($h);
    $partial['band_ranks']['A']=$partial['evidence_band_ranks']['A']=[5,4,6,1,2,3];
    $ok=makeReply($new,$partial);
    check(Sel::verifyReply($new,$ok)['selection_ranks']['A']===[5,4,6,1,2,3],'R2 one missing component switches the band to evidence with no score sort');

    $proof=fixtureProof($roles);$pick=static fn($proof,$rs)=>Sel::merge($i,$rs,$i['quota'],$nr,$proof,$groups);
    $a=$roles['1st']['public_proof_hash'];$b=$roles['2nd']['public_proof_hash'];
    check($m['agreement_bonus'][7]===5 && $m['public_proof_hashes'][7]===['1st'=>$a,'2nd'=>$b],'R2 agreement carries both stored source hashes');
    $small=[7=>['1st'=>$a,'2nd'=>$b]];
    check($pick($small,$roles)['agreement_bonus'][7]===5 && $pick($small,$roles)['agreement_bonus'][8]===0,'R2 horse-major proof honors only the documented horse');
    foreach ([['1st'=>true,'2nd'=>true],['1st'=>[7=>$a]],['1st'=>[7=>$b],'2nd'=>[7=>$a]],['1st'=>[7=>$a],'2nd'=>[7=>'not-a-hash']],['1st'=>[7=>$a],'2nd'=>[7=>$b],7=>['2nd'=>str_repeat('a',64)]]] as $j=>$wrong) {
        $r=$pick($wrong,$roles);check($r['agreement_bonus'][7]===0 && !isset($r['public_proof_hashes'][7]),"R2 missing, stale or conflicting per-horse proof $j gives no bonus");
    }
    $unproved=$roles;$unproved['2nd']=Sel::verifyReply($ins['2nd'],$replies['2nd']);
    check($pick($proof,$unproved)['agreement_bonus'][7]===0,'R2 a reconstructed role does not inherit original-reply proof');
    check($m['agreement_bonus'][10]===0 && $m['agreement_bonus'][12]===0,'R2 two E-only promotions cannot create a consensus vote');
    rejectsWith(fn()=>$pick([],[]),'W2_NO_VERIFIED_ROLE','R2 two failed roles do not produce a valid empty publication');
    check(Sel::merge($off,[],null,$nr,[],[])['public_candidates']===[],'R2 enabled race without replies is distinct from an unresolved race');

    $mixed=[]; $mixedInput=[];
    foreach (['1st','2nd'] as $role) {
        $seed=$ins[$role];$seed['expected_v01']=$unavailable;$seed=freezeFixture($seed);$v=$replies[$role];
        if ($role==='2nd') $v['band_ranks']=['A'=>[4,2,1,3,5,6],'B'=>[10,9,8,7],'D'=>[12,11,13,14,15,16]];
        $v['evidence_band_ranks']=$v['band_ranks'];$v=makeReply($seed,$v);$rv=Sel::verifyReply($seed,$v);
        $rv['public_proof_hash']=fixtureResponseHash($v);$mixed[$role]=$rv;$mixedInput[$role]=$seed;
    }
    $g=array_replace($groups,[4=>2,9=>2,10=>2,12=>2]);
    $merge=static fn($rs,$active,$quota)=>Sel::merge($mixedInput['1st'],$rs,$quota,$active,fixtureProof($mixed),$g);
    $a=$merge($mixed,$nr,$i['quota']);$reverse=$merge(array_reverse($mixed,true),$nr,$i['quota']);
    check($a['public_candidates']===[4,1,2,10,7,9,12],'R2 divergent roles choose the specified seven by best rank, proof, evidence groups and horse number');
    check($a===$reverse,'R2 role completion order changes neither public order nor proof audit');
    $late=$merge($mixed,array_values(array_diff($nr,[12])),['A'=>3,'B'=>2,'D'=>1]);
    check($late['public_candidates']===[4,1,2,10,7,11],'R2 opposing roles plus late cancellation uses frozen ranks and six active slots');
})();

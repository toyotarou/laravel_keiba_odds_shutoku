<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\FrozenInputW2 as Frozen;

(function(string $dir) {
    $checked=0;$ok=static function($v,$name)use(&$checked){expect($v,$name);++$checked;};
    $bad=static function(callable $op,string $code)use($ok){try{$op();}catch(InvalidArgumentException $ex){$ok(str_starts_with($ex->getMessage(),$code),$code.' / '.$ex->getMessage());return;}throw new RuntimeException('accepted '.$code);};
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);
    $open=static function(array $in,mixed $trusted):Frozen {
        $common=Codec::commonHash($in);$in['snapshot_hash']=$common['hash'];
        return Frozen::fromStored($common['json'],r3bytes($in),$common['hash'],$in['ai_role'],$trusted);
    };
    $signed=static fn(string $json)=>['json'=>$json,'sha256'=>hash('sha256',$json)];
    $seal=static fn(array $data)=>$signed(r3bytes($data));
    $ledger=json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR);
    $records=[];$hashes=[];
    foreach (['2nd','1st'] as $role) {
        $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $f=$open($in,$proof);$records[$role]=r4original($f,r3frame($o));
        $r=Sel::verifyOriginal($f,r3frame($o),hash('sha256',r3frame($o)));foreach ($r['base_candidates'] as $n)$hashes[$role][$n]=$r['public_proof_hash'];
        $ok(Frozen::checked($f)['expected_v01']['rows'][2]['horse_no']===3,'independent calculator bytes work for '.$role);
        $wrong=$in;$rows=$in['expected_v01']['rows'];
        foreach ([3=>4,4=>3] as $to=>$from)$wrong['expected_v01']['rows'][$to-1]=['horse_no'=>$to,'formula_rank'=>$rows[$to-1]['formula_rank']]+$rows[$from-1];
        $bad(fn()=>$open($wrong,$proof),'W6_E_PROOF_ROW_3');
        $p=$ledger;$p['rows']=array_reverse($p['rows']);$ok($open($in,$seal($p))->readyAt()==$f->readyAt(),'proof joins by number across a different saved order');
        $p=$ledger;$p['rows'][0]['horse_no']=$p['rows'][1]['horse_no'];$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_ROSTER');
        $p=$ledger;array_pop($p['rows']);$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_ROSTER');
        $p=$ledger;$p['rows'][0]['E3']=(string)$p['rows'][0]['E3'];$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_ROW_1');
        $p=$ledger;$p['rows'][0]['W']+=.0000001;$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_ROW_1');
        foreach (['race_id'=>'other race','history_sha256'=>str_repeat('a',64),'current_sha256'=>str_repeat('b',64),'calibration_id'=>'stale calibration','formula_version'=>'old'] as $key=>$value){
            $p=$ledger;$p[$key]=$value;$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_HEADER');
        }
        $p=$ledger;$p['input_freeze_at']=r4time($p['input_freeze_at'],'+1 microsecond');$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_HEADER');
        $p=$ledger;$p['input_freeze_at']=(new DateTimeImmutable($p['input_freeze_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $ok($open($in,$seal($p))->readyAt()==$f->readyAt(),'UTC and Japan time identify one freeze');
        $round=preg_replace('/("E3":)([0-9]+\.[0-9]+)(?=[,}])/','$1${2}1',$proof['json'],1,$count);
        $ok($count===1,'exact muted digit probe');$bad(fn()=>$open($in,$signed($round)),'W6_E_PROOF_ROW_');
        $bad(fn()=>$open($in,null),'W6_E_PROOF_REQUIRED');$bad(fn()=>$open($in,'not decoded'),'W6_E_PROOF_REQUIRED');$bad(fn()=>$open($in,(object)[]),'W6_E_PROOF_REQUIRED');$bad(fn()=>$open($in,['sha256'=>$proof['sha256']]),'W6_E_PROOF_REQUIRED');
        $bad(fn()=>$open($in,array_replace($proof,['json'=>$proof['json'].' '])),'W6_E_PROOF_HASH');
        $bad(fn()=>$open($in,$signed('{"broken":')),'W6_E_PROOF_JSON');
        $p=$ledger;$p['rows']=(object)$p['rows'];$bad(fn()=>$open($in,$seal($p)),'W6_E_PROOF_ROSTER');
    }
    $active=array_column($in['runners'],'horse_no');$q=$in['quota'];$base=$o['public_candidates'];
    $merge=static fn($rs)=>r9merge($f,$rs,$q,$active,$hashes,r4live($f));
    $r0=$records['1st']['receipts'][0];$rec=r4recoveryRecord($f,$o,$r0);
    foreach (['recovery_json','recovery_hash'] as $key) foreach (['missing','null','array'] as $mode) {
        $x=$rec;if ($mode==='missing') unset($x[$key]);else $x[$key]=$mode==='null'?null:[1];
        $a=$merge(['1st'=>$x]);$ok($a['public_candidates']===$base && $a['replayed_initial_roles']===['1st'] &&
            $a['role_issues']['1st']==='W3_RECOVERY_RECORD' && max($a['agreement_bonus'])===0,'only independently approved original survives absent or mistyped '.$key);
        $x['receipts'][0]['original_approved']=false;$bad(fn()=>$merge(['1st'=>$x]),'W2_NO_VERIFIED_ROLE');
    }
    $invalid=$rec;$invalid['recovery_json']=null;$res=$merge(['1st'=>$invalid,'2nd'=>$records['2nd']]);
    $ok(max($res['agreement_bonus'])===0 && $res['replayed_initial_roles']===['1st'],'rescued initial gets no joint bonus with a healthy second AI');
    foreach ([null,'broken',['raw'=>'x'],['attempt_no'=>false],['attempt_no'=>2]] as $unknown) foreach ([true,false] as $flip) {
        $r=$records['1st'];$r['receipts']=$flip?[$unknown,$r0]:[$r0,$unknown];$res=$merge(['1st'=>$r]);
        $ok($res['public_candidates']===$base && $res['attempt_issues']['1st']['unidentified']==='W4_ATTEMPT_NO','one uniquely identified original survives a malformed companion');
    }
    $r=$records['1st'];$r['receipts']=[$r0,$r0];$bad(fn()=>$merge(['1st'=>$r]),'W2_NO_VERIFIED_ROLE');
    $r['receipts']=[$r0,null,null];$bad(fn()=>$merge(['1st'=>$r]),'W2_NO_VERIFIED_ROLE');
    $r['receipts']=[null,['attempt_no'=>1]];$bad(fn()=>$merge(['1st'=>$r]),'W2_NO_VERIFIED_ROLE');
    $r['receipts']=[$r0,null];$r['receipts'][0]['received_at']=r4time($in['time_contract']['reply_deadline'],'+1 microsecond');
    $bad(fn()=>$merge(['1st'=>$r]),'W2_NO_VERIFIED_ROLE');
    // Same frozen enrichment for both roles. Each positive component now has its own genuine material.
    foreach (['2nd','1st'] as $role) {
        $in=r12ScoreInput(json_decode(file_get_contents("$dir/{$role}_input.json"),true));
        $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true);$f=$open($in,$proof);
        $in['snapshot_hash']=$o['snapshot_hash']=Frozen::checked($f)['snapshot_hash'];
        $o=r1519Reviews($in,$o);
        $records[$role]=r4original($f,r3frame($o));
    }
    $merge=static fn($rs)=>r9merge($f,$rs,$q,$active,[],r4live($f));
    $first=$o;$h=&$first['horses'][1];
    foreach (Sel::scoreCaps() as $key=>$cap){$h['scores'][$key]=$cap;$h['score_evidence'][$key]=['reason'=>'initial observed','source_refs'=>r12Refs(2)[$key]];}
    unset($h);$firstRaw=r4receipt($f,r3frame($first),0,false);
    $fix=r4receipt($f,r3frame($o),1,true);$fix['repair_scope']=r5scope($firstRaw,[2]);
    $rr=$records['1st'];$rr['receipts']=[$firstRaw,$fix];$res=$merge(['1st'=>$rr,'2nd'=>$records['2nd']]);
    $ok($res['attempt_issues']['1st'][1]==='W4_RECOVERY_REGRESSION' && isset($res['role_issues']['1st']) && max($res['agreement_bonus'])===0,'wrong first status cannot erase ten proven scores or award a bonus');
    $right=$first;$h=&$right['horses'][1];$h['score_status']='complete';$h['total_score']=$h['observed_cap']=$h['observed_sum']=array_sum(Sel::scoreCaps());unset($h);
    $fix=r4receipt($f,r3frame(r5project($in,$right)),1,true);$fix['repair_scope']=r5scope($firstRaw,[],[],[2]);
    $rr['receipts']=[$firstRaw,$fix];$res=$merge(['1st'=>$rr]);$ok($res['role_issues']===['2nd'=>'W3_ROLE_MISSING'] && $res['attempt_issues']===[],'summary-only correction keeps the authentic complete scores');
    $first['horses'][1]['score_evidence']['V1']['source_refs']=['other'];$rBad=r4receipt($f,r3frame($first),0,false);
    $fix=r4receipt($f,r3frame($o),1,true);$fix['repair_scope']=r5scope($rBad,[2]);$rr['receipts']=[$rBad,$fix];
    $bad(fn()=>$merge(['1st'=>$rr]),'W2_NO_VERIFIED_ROLE'); // Erasing the nine genuinely observed components is invalid.
    $partial=$right;$ph=&$partial['horses'][1];$ph['scores']['V1']=null;
    $ph['score_evidence']['V1']=['reason'=>'OPI not verified','source_refs'=>[]];$ph['score_status']='partial';$ph['total_score']=null;
    $ph['observed_sum']=$ph['observed_cap']=array_sum(Sel::scoreCaps())-Sel::scoreCaps()['V1'];unset($ph);
    $fix=r4receipt($f,r3frame(r5project($in,$partial)),1,true);$fix['repair_scope']=r5scope($rBad,[2]);$rr['receipts']=[$rBad,$fix];
    $ok($merge(['1st'=>$rr])['role_issues']===['2nd'=>'W3_ROLE_MISSING'],'the truly unverified component is nulled and nine genuine components survive');
    echo "PASS $checked R6 calculator-to-role proof, exact value, partial receipt and correction checks.\n";
})($argv[2]);

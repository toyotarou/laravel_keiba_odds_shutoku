<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiJson as Parser;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Http\Controllers\Api\BaganrikiResponse as Response;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
(function(string $dir) {
    $checks=0;
    $eProof=json_decode(file_get_contents("$dir/expected_proof.json"),true,512,JSON_THROW_ON_ERROR);
    $test=static function($ok,$name) use (&$checks) { expect($ok,$name);$checks++; };
    $deny=static function(callable $f,string $code,string $name) use ($test) {
        try { $f(); } catch (InvalidArgumentException $e) { $test($e->getMessage()===$code,$name.' / '.$e->getMessage());return; }
        throw new RuntimeException('Expected rejection: '.$name);
    };
    $pack=static function($d){$r=Codec::compactWire($d);expect($r['ok'],'pack');return $r['wire'];};
    $back=static function($d){$r=Codec::expandWire($d);expect($r['ok'],'expand');return $r['input'];};
    $roles=[];$proof=[];$frozen=[];
    foreach (['1st','2nd'] as $role) {
        $roleRaw=file_get_contents("$dir/{$role}_input.json");$input=Parser::decode($roleRaw)['value'];
        $plain=Codec::commonHash($input);$seed=Frozen::fromStored($plain['json'],$roleRaw,$plain['hash'],$role,$eProof);$frozen[$role]=$seed;
        $test($seed->raw()===$roleRaw,'original role bytes retained');
        $allow=static fn($kind,$bytes)=>$kind==='SOURCE_TABLE_V1' && strlen($bytes)>0;
        $send=Bridge::prepareSend($seed,$pack,$back,$allow,static fn()=>r4live($seed));
        $test($send['format']==='SOURCE_TABLE_V1' && $send['fallback_reason']===null && $send['user_sha256']===hash('sha256',$send['user_json']),'measured exact compressed body');
        $wire=Parser::decode($send['user_json']);$restored=Bridge::expand($wire['value'],$back);
        $test(Parser::sameJson($roleRaw,Codec::canonical($restored),true)['same'],'compressed bytes expanded to original semantic values and JSON kinds');
        $raw=Response::BEGIN."\n".file_get_contents("$dir/{$role}_output.json")."\n".Response::END;
        $hash=hash('sha256',$raw);$r=Sel::verifyOriginal($seed,$raw,$hash);$roles[$role]=$r;
        foreach ($r['base_candidates'] as $n) $proof[$role][$n]=$hash;
        $test($r['public_proof_hash']===$hash,'archive parser and stored raw SHA bind proof');
        $deny(fn()=>Sel::verifyOriginal($seed,$raw." ",$hash),'W2_RESPONSE_PROOF_HASH','a different byte string cannot carry the same saved proof');
        $bad=$raw."\nextra";$deny(fn()=>Sel::verifyOriginal($seed,$bad,hash('sha256',$bad)),'W2_RESPONSE_FRAME','text after END refused for original proof');
        $bad=Response::BEGIN."\n".'{"a":1,"a":2}'."\n".Response::END;
        $deny(fn()=>Sel::verifyOriginal($seed,$bad,hash('sha256',$bad)),'W2_RESPONSE_JSON','duplicate keys refused for original proof');
        $deny(fn()=>Frozen::fromStored($plain['json']." ",$roleRaw,$plain['hash'],$role,$eProof),'W2_STORED_ID','changed common bytes refused even if logically equivalent');
        $bad=$input;$bad['expected_v01']['rows'][0]['E3']=0;
        $deny(fn()=>Frozen::fromStored($plain['json'],Codec::canonical($bad),$plain['hash'],$role,$eProof),'W2_STORED_COMMON_MISMATCH','same ID with changed score refused');
        $bad=$input;$bad['role_quota']['A']--;
        $deny(fn()=>Frozen::fromStored($plain['json'],Codec::canonical($bad),$plain['hash'],$role,$eProof),'W2_FROZEN_QUOTA','role-specific cap outside the common hash still validated');
        $deny(fn()=>Frozen::fromStored($plain['json'],$roleRaw,$plain['hash'],$role==='1st'?'2nd':'1st',$eProof),'W2_ROLE_ID','opposite role refused');
        $deny(fn()=>Bridge::prepareSend($seed,$pack,$back,static fn()=>false,static fn()=>r4live($seed)),'W2_SEND_BUDGET','budget gate applies to compressed body');
    }
    $m=Sel::merge($frozen['1st'],$roles,$input['quota'],range(1,16),$proof,array_fill_keys(range(1,16),1));
    $test($m['agreement_bonus'][7]===5 && $m['agreement_bonus'][10]===0 && $m['public_proof_hashes'][7]['2nd']===$roles['2nd']['public_proof_hash'],'real parsed response proof to merger');
    $deny(fn()=>Sel::verifyOriginal($frozen['1st'],$raw,$hash),'W2_REPLY_ID_ai_role','proof from a different job refused');
    $seedRole=$input['ai_role'];
    $ref=$input['input_indices']['horse/1/win_odds/18'][0];$marker='SYNTHETIC_RAW_JSON_VALUE_TO_REPLACE';
    foreach (['11.00000000000000000001','{}','{"0":"x","1":"y"}'] as $lossy) {
        $tmp=$input;$tmp['source_registry'][$ref]['value']=$marker;
        $common=str_replace('"'.$marker.'"',$lossy,Codec::commonHash($tmp)['json']);
        $h=hash('sha256',$common);$tmp['snapshot_hash']=$h;
        $rawRole=str_replace('"'.$marker.'"',$lossy,Codec::canonical($tmp));
        if (str_starts_with($lossy,'{')) {
            foreach ([false,true] as $allow) {
                try {
                    $invalid=Frozen::fromStored($common,$rawRole,$h,$seedRole,$eProof);
                    Bridge::prepareSend($invalid,$pack,$back,static fn()=>true,static fn()=>r4live($invalid),$allow);
                    throw new RuntimeException('schema-invalid object odds sent');
                } catch (InvalidArgumentException $e) { $test(str_starts_with($e->getMessage(),'W3_SCHEMA_input'),'object-valued odds rejected before sending'); }
            }
            $test($rawRole!==$common,'separate stored input bytes for invalid-type fixture');
            continue;
        }
        // This test deliberately authors a different 18-minute market before freezing it.
        $published=\App\Http\Controllers\Api\BaganrikiJson::decode($rawRole)['value'];
        $sealed=json_decode($eProof['json'],true,512,JSON_THROW_ON_ERROR);
        $sealed['market_source_sha256']=\App\Services\Baganriki\MarketStateW2::verify($published);
        $newProof=\App\Services\Baganriki\ExpectedPacketW2::sealProof($sealed);
        $seed=Frozen::fromStored($common,$rawRole,$h,$seedRole,$newProof);
        // R14 preserves the number in the supported codec. Force an actual type loss in these refusal/fallback cases.
        $lossyPack=static function(array $tree)use($pack,$ref) {
            $tree['source_registry'][$ref]['value']=(string)$tree['source_registry'][$ref]['value'];
            return $pack($tree);
        };
        $deny(fn()=>Bridge::prepareSend($seed,$lossyPack,$back,static fn()=>true,static fn()=>r4live($seed)),'W2_FULL_NOT_ALLOWED','lossy compression cannot be released by default');
        $seen=[];$budget=static function($kind,$bytes) use (&$seen,$rawRole){$seen=[$kind,$bytes===$rawRole];return true;};
        $send=Bridge::prepareSend($seed,$lossyPack,$back,$budget,static fn()=>r4live($seed),true);
        $test($seen===['FULL',true] && $send['user_json']===$rawRole && $send['format']==='FULL' &&
            $send['user_sha256']===hash('sha256',$rawRole) && $send['fallback_reason']!==null,'explicitly allowed full fallback retains exact original bytes and actual mode');
        $deny(fn()=>Bridge::prepareSend($seed,$lossyPack,$back,static fn()=>false,static fn()=>r4live($seed),true),'W2_SEND_BUDGET','full fallback also requires a positive current budget check');
    }
    $tmp=$input;$tmp['source_registry'][$ref]['value']=$marker;
    $common=str_replace('"'.$marker.'"','11.00000000000000000001',Codec::commonHash($tmp)['json']);
    $h=hash('sha256',$common);$tmp['snapshot_hash']=$h;
    $wrongType=str_replace('"'.$marker.'"','"11.00000000000000000001"',Codec::canonical($tmp));
    $deny(fn()=>Frozen::fromStored($common,$wrongType,$h,$seedRole,$eProof),'W2_STORED_COMMON_MISMATCH','raw numeric token and same-looking string differ in frozen DB inputs');
    echo "PASS $checks R2 stored-byte, raw-response proof and transport checks using the submitted backend.\n";
})($argv[2]);

// Independent comparison of selections with both roles disagreeing, missing eligible horses and cancellations.
(function(string $dir) {
    $seed=json_decode(file_get_contents("$dir/1st_input.json"),true,512,JSON_THROW_ON_ERROR);
    $template=json_decode(file_get_contents("$dir/1st_output.json"),true,512,JSON_THROW_ON_ERROR);
    $bands=array_column($seed['runners'],'band','horse_no');$sizes=array_count_values($bands);
    $count=0;$filled=0;$different=0;$swapped=0;
    foreach (['FIRM','MIXED','VOLATILE'] as $mode) for ($case=0;$case<32;$case++) {
        $seed=marketFixture($seed,$mode);
        $seed['quota']=\App\Http\Controllers\Api\BaganrikiRanking::afterCancellation($bands,[],[],$mode)['live_quota'];
        $seed['snapshot_hash']=Codec::commonHash($seed)['hash'];
        $roles=[];$proof=[];$per=[];$groups=[];$active=[];
        foreach ($bands as $n=>$band) {
            $groups[$n]=1+(($n+$case)%4);
            if (($n+3*$case)%11!==0) $active[]=$n;
        }
        $ranksByRole=[];
        foreach (['1st','2nd'] as $ix=>$role) {
            $in=$seed;$in['ai_role']=$role;$in['role_quota']=$seed['quota'];
            if ($ix===1) $in['role_quota']['A']=5-$in['role_quota']['B']-$in['role_quota']['D'];
            $in['role_cap']=array_sum($in['role_quota']);
            $rows=$template['horses']; $ranks=['A'=>[],'B'=>[],'D'=>[]];
            foreach ($rows as &$h) {
                $n=$h['horse_no'];$b=$h['band'];
                $omit=(($n+2*$case+3*$ix)%7===0) || ($case%5===0 && $b==='D') || ($case%4===0 && $b==='B');
                if ($omit) { $h['eligibility']='NO_EVIDENCE';$h['tier']='C';continue; }
                $ranks[$b][]=$n;
            }
            unset($h);
            foreach ($ranks as &$list) {
                usort($list,static fn($x,$y)=>strcmp(hash('sha256',"$case/$ix/$x"),hash('sha256',"$case/$ix/$y")));
            }
            unset($list);
            $r=Sel::apply($in,$rows,$ranks);$ranksByRole[]=$ranks;
            $h=hash('sha256',"unit-ranking-only/$mode/$case/$role");$r['public_proof_hash']=$h;$roles[$role]=$r;$per[$role]=[];
            foreach ($r['expected_reviews'] as $review) if ($review['decision']==='SWAP') $swapped++;
            foreach ($r['selection_ranks'] as $band=>$list) foreach ($list as $pos=>$n) {
                if (!in_array($n,$active,true)) continue;
                $proven=in_array($n,$r['base_candidates'],true) && in_array($n,$r['public_candidates'],true) && ($n+$case+$ix)%5!==0;
                if ($proven) $proof[$role][$n]=$h;
                $per[$role][$n]=['r'=>$pos+1,'K'=>$sizes[$band],'public'=>$proven,'groups'=>$groups[$n]];
            }
        }
        if ($ranksByRole[0]!==$ranksByRole[1]) $different++;
        $live=\App\Http\Controllers\Api\BaganrikiRanking::afterCancellation($bands,array_diff(array_keys($bands),$active),$seed['quota'],$mode)['live_quota'];
        $combined=Sel::merge($seed,$roles,$live,$active,$proof,$groups);
        $queues=['A'=>[],'B'=>[],'D'=>[]];
        $original=\App\Http\Controllers\Api\BaganrikiRanking::merge($per,$proof);
        foreach ($original as $entry) {
            $n=$entry['num'];$queues[$bands[$n]][]=$n;
            expect($combined['agreement_bonus'][$n]===(int)$entry['bonus'],'reference original agreement');
        }
        $effective=\App\Http\Controllers\Api\BaganrikiRanking::fillShortfall($live,array_map('count',$queues))['quota'];
        $wanted=[];foreach ($queues as $band=>$queue) array_push($wanted,...array_slice($queue,0,$effective[$band]));
        $got=$combined['public_candidates'];
        expect($wanted===$got && count($got)===count(array_unique($got)),'reference candidate order '.$mode.'/'.$case);
        if ($combined['fill']!==[]) $filled++;
        $count++;
    }
    expect($different===$count && $filled>0 && $swapped>0,'scenario coverage');
    echo "PASS $count divergent two-role selections against submitted ranking/fill code; $filled with cross-band fill.\n";
})($argv[2]);

<?php
declare(strict_types=1);
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\SourceEvidenceW2 as Evidence;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
(function(string $dir) {
    $count=0;$ok=static function(bool $v,string $label)use(&$count){expect($v,'R8 '.$label);++$count;};
    $deny=static function(callable $f,string $why)use($ok){try{$f();}catch(InvalidArgumentException $e){$ok($e->getMessage()===$why,$why.' / '.$e->getMessage());return;}throw new RuntimeException('R8 expected '.$why);};
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);
    foreach (['1st','2nd'] as $role) {
        $base=json_decode(file_get_contents("$dir/{$role}_input.json"),true);$out=json_decode(file_get_contents("$dir/{$role}_output.json"),true);
        $snap=r3stored($base,$proof);$ids=array_column($base['runners'],'horse_no');$raw=r3frame($out);
        $attempt=r4original($snap,$raw);$ctx=r4live($snap);$originals=[$role=>$attempt];$now=$ctx['now'];
        $merge=static fn($records,$state)=>r9merge($snap,$records,$base['quota'],$ids,[],$state);
        $selected=$merge($originals,$ctx);$stop=$base['time_contract']['publish_deadline'];
        $utc=static fn(string $time)=>Packet::utc(Packet::instant($time));
        foreach (['input_freeze_at','history_before'] as $field) {
            $x=$base;$x['expected_v01'][$field]=$utc($x['expected_v01'][$field]);$s=r3stored($x,$proof);
            $ok($s->readyAt()==$snap->readyAt(),'equivalent UTC timestamps '.$field);
            foreach (['+1 microsecond','-1 microsecond'] as $step) {
                $z=$x;$z['expected_v01'][$field]=r4time($x['expected_v01'][$field],$step);
                $deny(fn()=>r3stored($z,$proof),'W6_E_PROOF_HEADER');
            }
        }
        foreach ([21,18,15,12,9,6] as $slot) {
            $x=$base;$r=$x['input_indices']["horse/2/place_max/$slot"][0];$x['source_registry'][$r]['value']+=1;
            $deny(fn()=>r3stored($x,$proof),'W8_MARKET_SOURCE_MISMATCH');
            $x=$base;$x['collection_audit'][$slot]['payload_hash']=str_repeat('a',64);$deny(fn()=>r3stored($x,$proof),'W8_MARKET_SOURCE_MISMATCH');
            $x=$base;$x['collection_audit'][$slot]['source_updated_at']=$x['collection_audit'][$slot]['request_started_at'];$deny(fn()=>r3stored($x,$proof),'W8_MARKET_SOURCE_MISMATCH');
            $x=$base;$x['source_registry'][$r]['value']=null;$deny(fn()=>r3stored($x,$proof),'W8_MARKET_SOURCE_MISMATCH');
        }
        $ledger=json_decode($proof['json'],true);unset($ledger['market_source_sha256']);
        $deny(fn()=>r3stored($base,Packet::sealProof($ledger)),'W8_MARKET_PROOF_REQUIRED');
        foreach ([true,1,'1'] as $flag) {
            $a=$attempt;$a['receipts'][0]['original_approved']=$flag;
            $ok($merge([$role=>$a],$ctx)['public_candidates']===$selected['public_candidates'],'DB boolean exact approved');
        }
        foreach ([false,0,'0',null,'false','true','01',1.0,[]] as $flag) {
            $a=$attempt;$a['receipts'][0]['original_approved']=$flag;
            $deny(fn()=>$merge([$role=>$a],$ctx),'W2_NO_VERIFIED_ROLE');
        }
        foreach (['state_revision','response_revision','publication_revision'] as $field) {
            $z=$ctx;$z[$field]='18446744073709551615';$ans=$merge($originals,$z);
            $ok($ans['publication_guard']['revisions'][$field]===$z[$field],'full unsigned revision supported');
            foreach (['18446744073709551616','00',-1,null,true,1.0] as $bad) {
                $x=$ctx;$x[$field]=$bad;$deny(fn()=>$merge($originals,$x),'W8_LIVE_REVISION');
            }
            $x=$ctx;$x[$field]=1;$deny(fn()=>r9commit($snap,$selected,$now,$x),'W8_PUBLICATION_CHANGED');
        }
        foreach ([null,'1',false,[1,1],[0],[19],['01']] as $bad) {
            $z=$ctx;$z['cancelled_horse_nos']=$bad;
            $deny(fn()=>$merge($originals,$z),is_array($bad)?'W2_ACTIVE_ROSTER':'W8_LIVE_CANCELLED_SET');
        }
        $z=$ctx;$z['cancelled_horse_nos']=[1];$deny(fn()=>$merge($originals,$z),'W8_LIVE_ROSTER_MISMATCH');
        $z=$ctx;$z['cancelled_horse_nos']=$ids;$deny(fn()=>$merge($originals,$z),'W4_CANCELLATION_STATE');
        $z=$ctx;$z['now']=$stop;
        r9commit($snap,$selected,$utc($stop),$z);$ok(true,'deadline equality in a fresh locked state');
        $clean=json_decode(Frozen::nativeJson($selected),true,512,JSON_THROW_ON_ERROR);
        r9commit($snap,$clean,$utc($now),$ctx);$ok(true,'persisted canonical object-key order accepted');
        foreach (['public_candidates','actual_quota','fill','shortfalls','agreement_bonus'] as $key) {
            $wrong=$selected;$wrong[$key]=['unexpected'];$deny(fn()=>r9commit($snap,$wrong,$now,$ctx),'W8_PUBLICATION_BYTES');
        }
        $z['now']=r4time($stop,'+1 microsecond');$deny(fn()=>r9commit($snap,$selected,$stop,$z),'W8_COMMIT_WINDOW');
        $deny(fn()=>r9commit($snap,$selected,r4time($now,'-1 microsecond'),$ctx),'W8_COMMIT_WINDOW');
        $deny(fn()=>r9commit($snap,$selected,r4time($now,'+1 microsecond'),$ctx),'W8_COMMIT_WINDOW');
        $z=$ctx;$z['live_close_at']=r4time($base['time_contract']['sales_close_at'],'-1 microsecond');
        $deny(fn()=>r9commit($snap,$selected,$now,$z),'W8_PUBLICATION_CHANGED');
        $gone=$ctx;$gone['all_cancelled']=true;$gone['cancelled_horse_nos']=$ids;
        $none=r9merge($snap,[],null,[],[],$gone);$deny(fn()=>r9commit($snap,$none,$now,$gone),'W8_NOT_PUBLISHABLE');
        // A pair label alone cannot turn a copy of horse 1's features into a personal signal for horse 2.
        $parent='h1_past_performance_None';$peer='h2_past_performance_None';$ref='r8_pair_derived';$field='r8_pair_history';
        $x=$base;$x['field_catalog'][$field]=array_replace($x['field_catalog']['past_performance'],['scope'=>'PAIR']);
        $src=array_replace($x['source_registry'][$parent],['scope'=>'PAIR','horse_nos'=>[1,2],'field'=>$field,'source_ref'=>$ref,
            'source_kind'=>'DERIVED','parents'=>[$parent],'formula_id'=>'identity','formula_version'=>'1','computed_at'=>$x['input_freeze_at']]);
        $x['source_registry'][$ref]=$src;$x['input_indices'][Codec::indexKey($src)]=[$ref];
        $pair=$out;$h=&$pair['horses'][1];$h['evidence'][0]['source_refs']=[$ref];$h['evidence'][0]['facts']=[['source_ref'=>$ref,'value'=>$src['value']]];
        $h['family_review']['ability']['source_refs']=[$ref];unset($h);
        $s=r3stored($x,$proof);$pair['snapshot_hash']=Frozen::checked($s)['snapshot_hash'];$raw=r3frame($pair);
        $deny(fn()=>Sel::verifyOriginal($s,$raw,hash('sha256',$raw)),'W3_EVIDENCE_FACTS');
        $cache=[];$score=['reason'=>'r8 fixture','source_refs'=>[$ref]];
        $ok(Evidence::scoreKnown($x,1,'H1',1,$score,$cache) && !Evidence::scoreKnown($x,2,'H1',1,$score,$cache),'score ownership when a pair contains one horse’s raw observation');
        $rec=r4receipt($s,$raw,0,false);$recovery=r4seal(r4recoveryDoc($s,$pair,$rec),[$rec],$s);
        $deny(fn()=>r9merge($s,[$role=>$recovery],$base['quota'],$ids,[],r4live($s)),'W2_NO_VERIFIED_ROLE');
        $x['source_registry'][$ref]['formula_id']='ratio_12dp';$x['source_registry'][$ref]['parents']=[$parent,$peer];
        $ratio=$x['source_registry'][$peer]['value']/$x['source_registry'][$parent]['value'];$x['source_registry'][$ref]['value']=$ratio;
        $s=r3stored($x,$proof);$pair['snapshot_hash']=Frozen::checked($s)['snapshot_hash'];$pair['horses'][1]['evidence'][0]['facts'][0]['value']=$ratio;
        $raw=r3frame($pair);$ok(Sel::verifyOriginal($s,$raw,hash('sha256',$raw))['independent_groups'][2]===1,'derived pair with both owners remains valid');
    }
    echo "PASS $count R8 market commitments, temporal equivalence, personal signal ownership, DB flags and publication guard checks.\n";
})($argv[2]);

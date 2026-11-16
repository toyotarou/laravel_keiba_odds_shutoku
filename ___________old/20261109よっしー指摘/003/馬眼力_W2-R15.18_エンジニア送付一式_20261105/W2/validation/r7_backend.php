<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
(function(string $dir) {
    $count=0;$ok=static function($value,$message)use(&$count){expect($value,$message);++$count;};
    $deny=static function(callable $fn,string $message)use($ok){try{$fn();}catch(InvalidArgumentException $e){$ok(str_starts_with($e->getMessage(),$message),$message.' / '.$e->getMessage());return;}throw new RuntimeException('accepted '.$message);};
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);
    $pack=static function($v){$r=Codec::compactWire($v);if (!$r['ok'])throw new RuntimeException($r['reason']);return $r['wire'];};
    $unpack=static function($v){$r=Codec::expandWire($v);if (!$r['ok'])throw new RuntimeException($r['reason']);return $r['input'];};
    foreach (['2nd','1st'] as $role) {
        $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $out=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $snap=r3stored($in,$proof);$open=static fn($v)=>r3stored($v,$proof);$nums=array_column($in['runners'],'horse_no');
        $run=static fn($rs,$active=null,$q=null)=>r9merge($snap,$rs,$q??$in['quota'],$active??$nums,[],r4live($snap,null,null,false,$active));
        $send=static fn($s)=>Bridge::prepareSend($s,$pack,$unpack,static fn()=>true,static fn()=>r4live($s),true);
        foreach ([21,6] as $slot) {
            $ref=$in['input_indices']["horse/16/win_odds/$slot"][0];
            $other=$in;$other['source_registry'][$ref]['value']*=1.1;$deny(fn()=>$send($open($other)),'W7_E_ODDS_BINDING');
            $other=$in;$other['collection_audit'][$slot]['batch_id'].='/another';$deny(fn()=>$open($other),'W7_E_ODDS_BINDING');
            $other=$in;$other['collection_audit'][$slot]['request_started_at']=r4time($other['collection_audit'][$slot]['request_started_at'],'+1 microsecond');
            $deny(fn()=>$open($other),'W7_E_ODDS_BINDING');
            $other=$in;$other['source_registry'][$ref]['value']='R7_EXACT_ODD';
            foreach (['26.00000000000000000000','26.00000000000000000001'] as $ix=>$number) {
                $common=str_replace('"R7_EXACT_ODD"',$number,Codec::commonHash($other)['json']);$h=hash('sha256',$common);$other['snapshot_hash']=$h;
                $bytes=str_replace('"R7_EXACT_ODD"',$number,r3bytes($other));
                $verify=static fn()=>Frozen::fromStored($common,$bytes,$h,$role,$proof);
                if ($ix) $deny($verify,'W7_E_ODDS_BINDING');else $ok($verify()->readyAt()==$snap->readyAt(),'equivalent exact odds representations');
            }
        }
        // The independent calculation proof already matches the real input. Change only the unrelated index.
        $key='horse/1/past_performance/None';$own=$in['input_indices'][$key][0];$foreign=$in['input_indices']['horse/2/past_performance/None'][0];
        foreach ([$key=>'foreign','horse/1/other_field/None'=>'own','horse/1/past_performance/21'=>'own','pair/1-2/past_performance/None'=>'own',
            'cohort/1-2/past_performance/None'=>'own','race/past_performance/None'=>'own'] as $where=>$which) {
            $x=$in;$x['input_indices'][$where]=[$which==='foreign'?$foreign:$own];$deny(fn()=>$send($open($x)),'W7_INDEX_BINDING');
        }
        $x=$in;$x['input_indices']['custom/inspection']=[$own,$own];$deny(fn()=>$open($x),'W7_DUPLICATE_INDEX_REF');
        $x=$in;$x['input_indices']['custom/inspection']=[$foreign,$own];$sent=$send($open($x));
        $ok($sent['format']==='FULL' && json_decode($sent['user_json'],true)['input_indices']['custom/inspection']===[$foreign,$own],'valid supplementary multi-owner index retained in authorized full transport');
        $x=$in;
        foreach ([['PAIR',[2,1],[]],['COHORT',[],[5,1,2]],['RACE',[],[]]] as [$scope,$owners,$applicable]) {
            $field='r7_'.strtolower($scope);$id='fixture_'.$field;
            $x['field_catalog'][$field]=array_replace($x['field_catalog']['past_performance'],['scope'=>$scope,'display_label'=>$field]);
            $src=array_replace($x['source_registry'][$own],['scope'=>$scope,'field'=>$field,'source_ref'=>$id,'horse_nos'=>$owners,'applicable_horse_nos'=>$applicable,'dependency_key'=>$id]);
            $x['source_registry'][$id]=$src;$x['input_indices'][Codec::indexKey($src)]=[$id];
        }
        $ok($open($x)->readyAt()==$snap->readyAt(),'pair/cohort/race canonical indices preserve owner sets');
        $ledger=json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR);
        foreach (['missing','invalid','other'] as $kind) {
            $z=$ledger;if ($kind==='missing')unset($z['odds_binding_sha256']);else $z['odds_binding_sha256']=$kind==='invalid'?'x':str_repeat('9',64);
            $b=r3bytes($z);$deny(fn()=>r3stored($in,['json'=>$b,'sha256'=>hash('sha256',$b)]),'W7_E_ODDS_BINDING');
        }
        $receipt=r4receipt($snap,r3frame($out),0,false);$rec=r4recoveryDoc($snap,$out,$receipt);
        $expected=$run([$role=>r4seal($rec,[$receipt],$snap)])['public_candidates'];
        foreach (['A','B','D'] as $band) foreach (['empty','null','absent'] as $defect) {
            $x=$rec;$x['rank_sources'][$band]=null;
            if ($defect==='absent')unset($x['base_ranks'][$band]);else $x['base_ranks'][$band]=$defect==='null'?null:[];
            $got=$run([$role=>r4seal($x,[$receipt],$snap)]);
            $ok($got['public_candidates']===$expected && $got['rebuilt_base_bands'][$role]===[$band] && $got['retained_rank_sources'][$role][$band]===$receipt['response_hash'] &&
                $got['rank_fallback_bands']===[] && max($got['agreement_bonus'])===0,'unclaimed incomplete membership restored from actual verified rows '.$band.'/'.$defect);
        }
        $x=$rec;unset($x['base_ranks']['A']);$deny(fn()=>$run([$role=>r4seal($x,[$receipt],$snap)]),'W2_NO_VERIFIED_ROLE');
        $noRank=$out;$noRank['horses'][6]['evidence_rank_reason']=' ';$rr=r4receipt($snap,r3frame($noRank),0,false);
        $x=r4recoveryDoc($snap,$out,$rr);$x['rank_sources']['B']=null;unset($x['base_ranks']['B']);
        $got=$run([$role=>r4seal($x,[$rr],$snap)]);$ok($got['rank_fallback_bands'][$role]===['B'] && $got['rebuilt_base_bands'][$role]===['B'] && $got['public_candidates']===$expected,'missing unprovable rank uses all eligible rows, deterministic group order');
        $x=r4recoveryDoc($snap,$out,$receipt);$x['rank_sources']=array_fill_keys(['A','B','D'],null);$x['base_ranks']=(object)[];
        $got=$run([$role=>r4seal($x,[$receipt],$snap)]);$ok($got['public_candidates']===$expected && $got['rebuilt_base_bands'][$role]===['A','B','D'],'empty rank cache never invents or drops horses');
        $in=r12ScoreInput($in);$snap=r3stored($in,$proof);
        $in['snapshot_hash']=$out['snapshot_hash']=Frozen::checked($snap)['snapshot_hash'];
        $run=static fn($rs)=>r9merge($snap,$rs,$in['quota'],$nums,[],r4live($snap));
        $complete=$out;$scores=Sel::scoreCaps();foreach ($complete['horses'] as &$horse) if ($horse['band']==='B') {
            $n=$horse['horse_no'];$points=0;
            foreach ($scores as $key=>$cap) {$v=min($cap,$n-6);$points+=$v;$horse['scores'][$key]=$v;$horse['score_evidence'][$key]=['reason'=>'fixture','source_refs'=>r12Refs($n)[$key]];}
            $horse['rank_basis']='SCORE';$horse['score_status']='complete';$horse['observed_sum']=$horse['total_score']=$points;$horse['observed_cap']=array_sum($scores);
        }unset($horse);
        $complete['band_ranks']['B']=[10,9,8,7];$complete=r5project($in,$complete);
        $rr=r4receipt($snap,r3frame($complete),0,false);$x=r4recoveryDoc($snap,$complete,$rr);$expectedScore=$run([$role=>r4seal($x,[$rr],$snap)])['public_candidates'];
        foreach (['reversed','object'] as $badRank) {
            $bad=$complete;$bad['band_ranks']['B']=$badRank==='reversed'?[7,8,9,10]:(object)[10,9,8,7];
            $badReceipt=r4receipt($snap,r3frame($bad),0,false);$claimed=r4recoveryDoc($snap,$complete,$badReceipt);
            $deny(fn()=>$run([$role=>r4seal($claimed,[$badReceipt],$snap)]),'W2_NO_VERIFIED_ROLE');
            $claimed['rank_sources']['B']=null;
            $got=$run([$role=>r4seal($claimed,[$badReceipt],$snap)]);
            $ok($got['public_candidates']===$expectedScore && max($got['agreement_bonus'])===0,'independent scores remain usable when no raw rank is claimed');
        }
        $x['base_ranks']['B']=[7];$x['rank_sources']['B']=null;$got=$run([$role=>r4seal($x,[$rr],$snap)]);
        $ok($got['public_candidates']===$expectedScore && $got['rank_fallback_bands']===[] && $got['rebuilt_base_bands'][$role]===['B'],'complete verified scores reconstruct entire sorted band without cached membership');
    }
    echo "PASS $count R7 saved odds provenance, scoped index, full transport and incomplete rank recovery checks.\n";
})($argv[2]);

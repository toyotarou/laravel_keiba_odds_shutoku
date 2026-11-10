<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
use App\Http\Controllers\Api\BaganrikiPublicView as View;

function r5project(array $in,array $reply):array {
    $res=Sel::apply($in,$reply['horses'],$reply['band_ranks']);
    foreach (['public_candidates','selection_ranks','expected_reviews'] as $k) $reply[$k]=$res[$k];
    foreach ($reply['horses'] as &$h) {
        $chosen=in_array($h['horse_no'],$res['public_candidates'],true);$h['public_candidate']=$chosen;
        $code=$h['eligibility']==='ELIGIBLE'?'BAND_QUOTA':$h['eligibility'];
        if ($chosen) $h['nonselect_reason']=null;
        elseif (($h['nonselect_reason']['code']??null)!==$code) $h['nonselect_reason']=['code'=>$code,'reason'=>'synthetic not selected'];
    }
    unset($h);return $reply;
}
function r5missing(array $h):array {
    $h['eligibility']=$h['tier']='UNVERIFIED';$h['rank_basis']='NOT_RANKED';$h['evidence']=[];$h['evidence_rank_evidence_ids']=[];
    $h['family_review']['ability']=['status'=>'NO_SUPPORT','source_refs'=>['h'.$h['horse_no'].'_past_performance_None'],
        'evidence_ids'=>[],'reason'=>'能力資料を確認したが肯定根拠として採用しない'];return $h;
}
(function(string $dir) {
    $count=0;$ok=static function($test,$message)use(&$count){expect($test,$message);++$count;};
    $bad=static function(callable $test,string $reason)use($ok){try{$test();}catch(InvalidArgumentException $e){$ok(str_starts_with($e->getMessage(),$reason),$reason.' / '.$e->getMessage());return;}throw new RuntimeException('accepted '.$reason);};
    $records=[];$verified=[];$proof=[];
    foreach (['2nd','1st'] as $role) {
        $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $f=r3stored($in);$raw=r3frame($o);$records[$role]=r4original($f,$raw);
        $verified[$role]=Sel::verifyOriginal($f,$raw,hash('sha256',$raw));
        foreach ($verified[$role]['base_candidates'] as $n) $proof[$role][$n]=hash('sha256',$raw);
    }
    $nos=array_column($in['runners'],'horse_no');$bands=array_column($in['runners'],'band','horse_no');
    $merge=static fn($records,$active=null,$quota=null,$all=false)=>r9merge($f,$records,$all?null:($quota??$in['quota']),$active??$nos,$proof,r4live($f,null,null,$all,$active));
    $initial=$records['1st'];$r0=$initial['receipts'][0];$solo=static fn($r)=>$merge(['1st'=>$r]);
    $base=$solo($initial)['public_candidates'];$opposite=$solo($initial)['role_issues'];$ok($opposite===['2nd'=>'W3_ROLE_MISSING'],'sole accepted role');
    $rev=$o;$rev['band_ranks']['A']=$rev['evidence_band_ranks']['A']=array_reverse($o['band_ranks']['A']);$rev=r5project($in,$rev);
    $fix=r4receipt($f,r3frame($rev),1,true);$rankGrant=r5scope($r0,[],['A'],[],range(1,6));
    $repaired=static fn($r1)=>array_replace($initial,['receipts'=>[$r1,$r0]]);
    $full=static function($scope)use($fix,$repaired,$solo){$c=$fix;if ($scope!==null)$c['repair_scope']=$scope;return $solo($repaired($c));};
    foreach ([null,[],array_replace($rankGrant,['first_response_hash'=>str_repeat('0',64)]),array_replace($rankGrant,['horses'=>[99]]),
        array_replace($rankGrant,['rank_bands'=>['B','B']]),array_replace($rankGrant,['summary_horses'=>[true]]),array_replace($rankGrant,['presentation_horses'=>['1']])] as $invalid) {
        $res=$full($invalid);$ok($res['public_candidates']===$base && ($res['attempt_issues']['1st'][1]??'')==='W5_REPAIR_SCOPE','scope bound to original and typed identities');
    }
    $scope=r5scope($r0,[],[],[],range(1,6));$res=$full($scope);
    $ok($res['public_candidates']===$base && $res['attempt_issues']['1st'][1]==='W5_REPAIR_RANK_SCOPE','presentation permission cannot move the cut line');
    $correct=$full($rankGrant);$wanted=$rev['public_candidates'];
    $ok($correct['public_candidates']===$wanted && $wanted!==$base && $correct['attempt_issues']===[],'authorized rank correction adopted');
    $res=$merge(['1st'=>$initial,'rogue'=>null,5=>'noise']);$ok($res['public_candidates']===$base && $res['ignored_role_count']===2,'unknown metadata cannot drop healthy role');
    $doc=r4recoveryDoc($f,$rev,$r0);$doc['rank_sources']['A']=$fix['response_hash'];
    $expired=$fix;$expired['complete']=false;$expired['repair_scope']=$rankGrant;
    $damaged=r4seal($doc,[$r0,$expired],$f);$res=$solo($damaged);
    $ok($res['public_candidates']===$base && $res['replayed_initial_roles']===['1st'] && $res['role_issues']['1st']==='W4_RECOVERY_STALE_SOURCE','bad recovery replays only approved on-time initial');
    $res=$merge(['1st'=>$damaged,'2nd'=>$records['2nd']]);$ok($res['replayed_initial_roles']===['1st'] && max($res['agreement_bonus'])===0,'replayed initial cannot issue mixed +5 proof');
    $unapproved=$r0;$unapproved['original_approved']=false;$bad(fn()=>$solo(r4seal($doc,[$unapproved,$expired],$f)),'W2_NO_VERIFIED_ROLE');
    $late=$r0;$late['received_at']=r4time($in['time_contract']['reply_deadline'],'+1 microsecond');
    $bad(fn()=>$solo(r4seal($doc,[$late,$expired],$f)),'W2_NO_VERIFIED_ROLE');
    $out=$fix;$out['repair_scope']=r5scope($r0);$res=$solo(r4seal($doc,[$out,$r0],$f));
    $ok($res['public_candidates']===$base && $res['retained_rank_sources']['1st']['A']===$r0['response_hash'],'unapproved recovered ranking replaced by first authorized order');
    $out['repair_scope']=$rankGrant;$ok($solo(r4seal($doc,[$r0,$out],$f))['public_candidates']===$wanted,'legal rank-only recovery');
    $rankOnly=r5scope($r0,[],['A']);$originalRankOnly=$full($rankOnly);
    $ok(isset($originalRankOnly['attempt_issues']['1st'][1]) && $originalRankOnly['public_candidates']===$base,'rank-only grant does not certify new presentation as a full original');
    $out['repair_scope']=$rankOnly;$locally=$solo(r4seal($doc,[$r0,$out],$f));
    $ok($locally['public_candidates']===$wanted && max($locally['agreement_bonus'])===0,'authorized rank-only recovery recomputes dependent flags without proof bonus');
    $rev0=r4receipt($f,r3frame($rev),0,false);$nullDoc=r4recoveryDoc($f,$rev,$rev0);$nullDoc['base_ranks']['A']=$o['band_ranks']['A'];$nullDoc['rank_sources']['A']=null;
    $res=$solo(r4seal($nullDoc,[$rev0],$f));$ok($res['public_candidates']===$wanted && $res['rank_fallback_bands']===[],'recover valid first ranking even when full approval absent');
    $broken=$o;$broken['horses'][0]['evidence_rank_reason']=' ';$rBroken=r4receipt($f,r3frame($broken),0,false);
    $fallback=r4recoveryDoc($f,$o,$rBroken);$fallback['rank_sources']['A']=null;
    $res=$solo(r4seal($fallback,[$rBroken],$f));$ok($res['rank_fallback_bands']===['1st'=>['A']] && $res['public_candidates']===$base,'genuine absence of rank permits explicit deterministic fallback');
    $wrong=$rev;$wrong['horses'][0]['evidence_rank_reason']=' ';$rawFirst=r4receipt($f,r3frame($wrong),0,false);
    $rFix=r4receipt($f,r3frame($rev),1,false);$rFix['repair_scope']=r5scope($rawFirst);
    $claim=r4recoveryDoc($f,$rev,$rawFirst);$claim['rank_sources']['A']=$rFix['response_hash'];
    $ans=$solo(r4seal($claim,[$rawFirst,$rFix],$f));
    $ok($ans['public_candidates']===$base && $ans['rank_fallback_bands']===['1st'=>['A']],'an unauthorized rewritten ranking justification cannot restore an invalid rank');
    $rFix['repair_scope']=r5scope($rawFirst,[],[],[],[1]);$ans=$solo(r4seal($claim,[$rawFirst,$rFix],$f));
    $ok($ans['public_candidates']===$wanted && $ans['rank_fallback_bands']===[],'authorized justification can certify the same existing order');
    $outside=$o;$outside['horses'][5]['evidence'][0]['interpretation'].=' altered';
    $r1=r4receipt($f,r3frame($outside),1,true);$r1['repair_scope']=r5scope($r0,[5]);
    $res=$solo($repaired($r1));$ok($res['public_candidates']===$base && $res['attempt_issues']['1st'][1]==='W5_REPAIR_FIELD_SCOPE','authorized different horse cannot change horse 6');
    $r1['repair_scope']=r5scope($r0,[6]);$ok($solo($repaired($r1))['attempt_issues']===[],'scoped evidence correction');
    $new=$o;$h=&$new['horses'][5];$old=$h['evidence'][0];$w='h6_win_odds_6';
    $w21=str_replace('_6','_21',$w);
    $other=array_replace($old,['family'=>'market','market_claim'=>['kind'=>'OTHER','metric'=>null],'observation'=>'市場比較（支持加速を根拠にしない）','evidence_id'=>'second','dependency_group'=>'market','source_refs'=>[$w,$w21],'facts'=>[['source_ref'=>$w,'value'=>$in['source_registry'][$w]['value']],['source_ref'=>$w21,'value'=>$in['source_registry'][$w21]['value']]]]);
    $h['evidence']=[$other];$h['evidence_rank_evidence_ids']=['second'];$h['family_review']['ability']=['status'=>'NO_SUPPORT',
        'source_refs'=>$old['source_refs'],'evidence_ids'=>[],'reason'=>'能力資料を確認したが肯定根拠として採用しない'];
    $h['family_review']['market']=['status'=>'SUPPORTED','reason'=>'verified','source_refs'=>[$w,$w21],'evidence_ids'=>['second']];unset($h);
    $r1=r4receipt($f,r3frame($new),1);$r1['repair_scope']=r5scope($r0,[6]);
    $meld=$new;$meld['horses'][5]['evidence'][]=$old;$meld['horses'][5]['family_review']['ability']=$o['horses'][5]['family_review']['ability'];$meld['horses'][5]['tier']='A';
    $mDoc=r4recoveryDoc($f,$meld,$r0);$mDoc['row_sources']=(array)$mDoc['row_sources'];$mDoc['row_sources'][6]=[$r0['response_hash'],$r1['response_hash']];$mDoc['rank_sources']['A']=null;
    $res=$solo(r4seal($mDoc,[$r0,$r1],$f));$ok($res['role_issues']['1st']==='W4_RECOVERY_UNTRACED_ITEM' && $res['replayed_initial_roles']===['1st'],'two individually true claims from mutually exclusive versions cannot mint Tier A');
    $missing=$o;$missing['horses'][15]=r5missing($missing['horses'][15]);array_pop($missing['band_ranks']['D']);array_pop($missing['evidence_band_ranks']['D']);
    $missing=r5project($in,$missing);$raw=r3frame($missing);
    $ok(Sel::verifyOriginal($f,$raw,hash('sha256',$raw))['independent_groups'][16]===0,'truly unranked horse still admissible');
    foreach ([['observed_sum',45,'W2_SCORE_TOTAL'],['observed_cap',50,'W2_SCORE_TOTAL'],['rank_basis','SCORE','W5_INELIGIBLE_RANK']] as [$key,$val,$error]) {
        $q=$missing;$q['horses'][15][$key]=$val;$raw=r3frame($q);
        $bad(fn()=>Sel::verifyOriginal($f,$raw,hash('sha256',$raw)),$error);
        $rr=r4receipt($f,r3frame($missing));$res=$solo(r4recoveryRecord($f,$q,$rr));$ok($res['role_issues']['1st']===$error,'all horse totals checked in recovered records');
    }
    $inj=$o;$inj['horses'][15]['public_candidate']='wrong type';$rBad=r4receipt($f,r3frame($inj),0,false);$erase=r4recoveryDoc($f,$missing,$rBad);
    $res=$merge(['1st'=>r4seal($erase,[$rBad],$f),'2nd'=>$records['2nd']]);
    $ok($res['role_issues']['1st']==='W4_RECOVERY_REGRESSION','broken presentation alone cannot erase intact evidence');
    $a=$o;$a['horses'][15]['evidence'][]=['wrong'=>'entry'];$rBad=r4receipt($f,r3frame($a),0,false);
    $legit=r4recoveryDoc($f,$o,$rBad);$legit['rank_sources']['D']=null;
    $res=$solo(r4seal($legit,[$rBad],$f));$ok(!isset($res['role_issues']['1st']) && $res['rank_fallback_bands']===['1st'=>['D']],'malformed extra evidence can be removed locally');
    $a=$o;$a['horses'][15]['evidence'][]=['evidence_id'=>$o['horses'][15]['evidence'][0]['evidence_id']];$rBad=r4receipt($f,r3frame($a),0,false);
    $legit=r4recoveryDoc($f,$missing,$rBad);$legit['rank_sources']['D']=null;
    $legit['horses'][15]['family_review']['ability']=$legit['horses'][15]['family_review']['gap'];
    $res=$solo(r4seal($legit,[$rBad],$f));$ok(!isset($res['role_issues']['1st']),'duplicated evidence ID may not qualify old horse');
    echo "PASS $count R5 scoped correction, intact first response, per-horse totals and auditable rank recovery checks.\n";

    $groups=array_fill_keys($nos,1);$sizes=array_count_values($bands);$sets=[[],$nos,[1,2,3,4,5,6],[7,8,9,10],[11,12,13,14,15,16],[1,3,5,7,9,11,13,15]];
    foreach ($nos as $i=>$n) { $sets[]=[$n];foreach (array_slice($nos,$i+1) as $m)$sets[]=[$n,$m]; }
    $hist=$merge($records)['public_candidates'];$nCases=0;
    foreach ($sets as $gone) {
        $active=array_values(array_diff($nos,$gone));$quota=Ranking::afterCancellation($bands,$gone,$in['quota'],$in['allocation_mode'])['live_quota'];
        $per=[];$queues=['A'=>[],'B'=>[],'D'=>[]];
        foreach ($verified as $role=>$val) foreach ($val['selection_ranks'] as $band=>$list) foreach ($list as $i=>$n) if (in_array($n,$active,true)) {
            $per[$role][$n]=['r'=>$i+1,'K'=>$sizes[$band],'public'=>isset($proof[$role][$n]) && in_array($n,$val['public_candidates'],true),'groups'=>1];
        }
        foreach (Ranking::merge($per,$proof) as $row) $queues[$bands[$row['num']]][]=$row['num'];
        $wanted=[];if ($quota!==null) { $adjust=Ranking::fillShortfall($quota,array_map('count',$queues))['quota'];foreach ($queues as $band=>$queue) array_push($wanted,...array_slice($queue,0,$adjust[$band])); }
        $ans=Sel::merge($f,$verified,$quota,$active,$proof,array_intersect_key($groups,array_flip($active)));
        expect($ans['public_candidates']===$wanted && !array_intersect($wanted,$gone),'cancellation candidate order '.json_encode($gone));
        if (count($gone)!==2 || in_array(1,$gone,true)) {
            $actual=$merge($records,$active,$quota,!$active);expect($actual['public_candidates']===$wanted,'raw-to-public cancellation replay');
            $past=['release_id'=>Sel::VERSION,'public_candidates'=>$hist];
            $view=View::build(['race_id'=>$in['race_id'],'state'=>View::state(true,!$active,false,true),'publication'=>$past,
                'active_count'=>count($active),'available_candidate_count'=>count(array_diff($hist,$gone)),'cancellations'=>$gone]);
            expect($view['publication']===$past && $view['live_state']['cancellations']===$gone && $view['live_state']['active_count']===count($active) && $view['live_state']['available_candidate_count']===count(array_diff($hist,$gone)) && $view['state']===($active?'PUBLISHED':'CANCELLED'),'history and latest read counts');
        }
        ++$nCases;
    }
    echo "PASS $nCases R5 cancellations including every one- and two-horse withdrawal; exact ordered candidates match submitted PHP.\n";
})($argv[2]);

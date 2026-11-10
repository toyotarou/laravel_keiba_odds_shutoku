<?php
declare(strict_types=1);
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;

(function(string $dir) {
    $count=0;$ok=static function($value,$name) use(&$count){expect($value,$name);++$count;};
    $deny=static function(callable $test,string $code) use($ok){try{$test();}catch(InvalidArgumentException $e){$ok(str_starts_with($e->getMessage(),$code),$code.' / '.$e->getMessage());return;}throw new RuntimeException('accepted '.$code);};
    $records=[];$views=[];$proof=[];
    foreach (['1st','2nd'] as $role) {
        $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $ss=r3stored($in);$raw=r3frame($o);$or=r4original($ss,$raw);$records[$role]=$or;$views[$role]=$o;
        foreach (Sel::verifyOriginal($ss,$raw,hash('sha256',$raw))['base_candidates'] as $n) $proof[$role][$n]=hash('sha256',$raw);
    }
    $receipt=$or['receipts'][0];$seed=$records['1st']['input'];$nos=range(1,16);$q=$in['quota'];
    $merge=static fn($rs,$ctx=null)=>r9merge($seed,$rs,$q,$nos,$proof,$ctx??r4live($seed));
    $with=static fn($second)=>$merge(['1st'=>$records['1st'],'2nd'=>$second]);
    $issue=static function($second,$code)use($with,$ok){$res=$with($second);$ok(str_starts_with($res['role_issues']['2nd']??'', $code) && $res['public_candidates'] && max($res['agreement_bonus'])===0,'isolated '.$code.' / '.json_encode($res['role_issues']));};
    $doc=r4recoveryDoc($ss,$o,$receipt);$seal=static fn($d)=>r4seal($d,[$receipt],$ss);
    $ok($with($seal($doc))['role_issues']===[],'valid recovery');
    $bad=$doc;$bad['horses'][0]='corrupt cached row';$issue($seal($bad),'W3_SCHEMA_recovery');
    $bad=$doc;$bad['horses'][0]['evidence'][0]['facts'][0]['value']=999;$issue($seal($bad),'W3_FACT_VALUE');
    $bad=$doc;$bad['horses'][0]['evidence'][0]['interpretation'].=' invented';$issue($seal($bad),'W4_RECOVERY_UNTRACED_ITEM');
    $bad=$doc;$bad['horses'][0]['counterevidence'][]=['source_refs'=>['h1_past_performance_None'],'reason'=>'new'];$issue($seal($bad),'W4_RECOVERY_UNTRACED_ITEM');
    $bad=$doc;$bad['ai_role']='1st';$issue($seal($bad),'W4_RECOVERY_ID');
    $bad=$doc;$bad['rank_sources']['A']=str_repeat('a',64);$issue($seal($bad),'W4_RECOVERY_STALE_SOURCE');
    $bad=$seal($doc);$bad['recovery_json'].=' ';$issue($bad,'W4_RECOVERY_HASH');
    $bad=$doc;$bad['base_ranks']['A']=[2,1,3,4,5,6];$issue($seal($bad),'W4_UNPROVEN_RANK');
    $bad['rank_sources']['A']=null;$ok(isset($with($seal($bad))['retained_rank_sources']['2nd']['A']),'valid original rank retained when declared source missing');
    $ok($with($seal(array_replace($doc,['rank_sources'=>array_fill_keys(['A','B','D'],null)])))['role_issues']===[],'deterministic fallback');
    $fallback=$with($seal(array_replace($doc,['rank_sources'=>array_fill_keys(['A','B','D'],null)])));
    $ok(array_keys($fallback['retained_rank_sources']['2nd'])===['A','B','D'] && $fallback['rank_fallback_bands']===[],'existing verified rank takes precedence across all bands');
    $erased=$doc;$erased['row_sources']=(array)$erased['row_sources'];$erased['row_sources'][1]=[];
    $h=&$erased['horses'][0];$h['eligibility']=$h['tier']='UNVERIFIED';$h['rank_basis']='NOT_RANKED';$h['evidence']=[];$h['evidence_rank_evidence_ids']=[];
    $h['family_review']['ability']=$h['family_review']['gap'];unset($h);array_shift($erased['base_ranks']['A']);$erased['rank_sources']['A']=null;
    $issue($seal($erased),'W4_RECOVERY_REGRESSION');
    // An actually absent initial row does not bar local recovery of the other fifteen.
    $missing=$o;array_shift($missing['horses']);$rr=r4receipt($ss,r3frame($missing),0,false);$fillable=r4recoveryDoc($ss,$o,$rr);
    $fillable['row_sources']=(array)$fillable['row_sources'];$fillable['row_sources'][1]=[];$fillable['horses']=$erased['horses'];$fillable['base_ranks']=$erased['base_ranks'];$fillable['rank_sources']['A']=null;
    $ok($with(r4seal($fillable,[$rr],$ss))['role_issues']===[],'legitimately missing local row with fifteen preserved');
    foreach (['cross-band','reason','backup'] as $which) {
        $new=$o;
        if ($which==='cross-band') {$new['evidence_band_ranks']['A'][]=7;$new['band_ranks']['A'][]=7;}
        elseif ($which==='reason') $new['horses'][0]['evidence_rank_reason']=' ';
        else $new['band_ranks']['A']=[2,1,3,4,5,6];
        $rr=r4receipt($ss,r3frame($new));$issue(r4recoveryRecord($ss,$o,$rr),'W4_UNPROVEN_RANK');
    }
    $extra=static function(array $orig,array $in,bool $bridge):array {
        $h=&$orig['horses'][5];$e=$h['evidence'][0];$w='h6_win_odds_6';$f=['source_ref'=>$w,'value'=>$in['source_registry'][$w]['value']];
        $w21='h6_win_odds_21';$place='h6_place_min_6';$fact=static fn($id)=>['source_ref'=>$id,'value'=>$in['source_registry'][$id]['value']];
        $more=[array_replace($e,['family'=>'market','evidence_id'=>'second','dependency_group'=>$e['dependency_group'],'source_refs'=>[$w21,$w],'facts'=>[$fact($w21),$f]])];
        if ($bridge) $more[]=array_replace($e,['family'=>'win_place','evidence_id'=>'bridge','dependency_group'=>'other','source_refs'=>[$e['source_refs'][0],$w,$place],'facts'=>[$e['facts'][0],$f,$fact($place)]]);
        foreach ($more as $ev) {$h['evidence'][]=$ev;$h['family_review'][$ev['family']]=['status'=>'SUPPORTED','reason'=>'verified','source_refs'=>$ev['source_refs'],'evidence_ids'=>[$ev['evidence_id']]];}
        $h['tier']='A';return $orig;
    };
    $valid=$extra($o,$in,false);$raw=r3frame($valid);$ok(Sel::verifyOriginal($ss,$raw,hash('sha256',$raw))['independent_groups'][6]===2,'disjoint roots with same AI label');
    $adjust=$extra($o,$in,true);$raw=r3frame($adjust);$deny(fn()=>Sel::verifyOriginal($ss,$raw,hash('sha256',$raw)),'W3_TIER_FAMILIES');
    $adjust['horses'][5]['tier']='B';$raw=r3frame($adjust);$ok(Sel::verifyOriginal($ss,$raw,hash('sha256',$raw))['independent_groups'][6]===1,'transitive roots certify only one group');
    $fix=r4receipt($ss,r3frame($valid),1);$fix['repair_scope']=r5scope($receipt,[6]);$fixed=r4recoveryDoc($ss,$valid,$receipt);
    $fixed['row_sources']=(array)$fixed['row_sources'];$fixed['row_sources'][6]=[$fix['response_hash']];$fixed['rank_sources']['A']=$fix['response_hash'];
    $ok($with(r4seal($fixed,[$fix,$receipt],$ss))['role_issues']===[],'traced local correction accepted even if DB rows reversed');
    $fixed['rank_sources']['A']=$receipt['response_hash'];$issue(r4seal($fixed,[$fix,$receipt],$ss),'W4_CHANGED_RANK_EVIDENCE');
    $original=$or;$original['receipts']=[$receipt,$fix];$ok($with($original)['role_issues']===[],'full approved correction');
    foreach([['complete',false],['complete','false'],['status','FAILED'],['snapshot_hash',str_repeat('0',64)],['raw',$receipt['raw'].' '],['original_approved','false']] as [$k,$v]) {
        $bad=$or;$bad['receipts'][0][$k]=$v;$issue($bad,'W4_NO_ADMISSIBLE_RECEIPT');
    }
    $bad=$or;$bad['receipts'][0]['original_approved']=false;$issue($bad,'W4_NO_APPROVED_ORIGINAL');
    $bad=$or;$bad['receipts']=[$fix];$issue($bad,'W4_FIRST_REQUIRED');
    $bad=$or;$bad['receipts']=[$receipt,$receipt];$issue($bad,'W4_DUPLICATE_ATTEMPT');
    $bad=$original;$bad['receipts'][1]['sent_at']=$receipt['sent_at'];$ans=$with($bad);$ok($ans['attempt_issues']['2nd'][1]==='W4_CORRECTION_ORDER' && $ans['role_issues']===[],'first used when invalid correction');
    $bad['receipts'][0]['original_approved']=false;$issue($bad,'W4_NO_APPROVED_ORIGINAL');
    $end=$in['time_contract']['reply_deadline'];$pub=$in['time_contract']['publish_deadline'];
    $edge=$or;$edge['receipts'][0]['received_at']=$end;$ok($with($edge)['role_issues']===[],'receipt deadline inclusive');
    $edge['receipts'][0]['received_at']=r4time($end,'+1 microsecond');$issue($edge,'W4_NO_ADMISSIBLE_RECEIPT');
    $edge=$or;$edge['receipts'][0]['received_at']=$end;$ans=$merge(['1st'=>$records['1st'],'2nd'=>$edge],r4live($seed,$end,r4time($in['time_contract']['sales_close_at'],'-1 microsecond')));
    $ok($ans['attempt_issues']['2nd'][0]==='W4_RECEIPT_TIME' && $ans['role_issues']['2nd']==='W4_NO_ADMISSIBLE_RECEIPT','shortened live close');
    $ok($merge($records,r4live($seed,$pub))['public_candidates']!==[],'publish deadline inclusive');
    $deny(fn()=>$merge($records,r4live($seed,r4time($pub,'+1 microsecond'))),'W4_PUBLISH_WINDOW');
    $deny(fn()=>$merge($records,r4live($seed,null,null,true)),'W4_CANCELLATION_STATE');
    $deny(fn()=>Window::dispatch($seed,r4live($seed,null,null,true)),'W4_SEND_WINDOW');
    $pack=static fn($tree)=>Codec::compactWire($tree)['wire'];$back=static fn($wire)=>Codec::expandWire($wire)['input'];
    foreach (['+1 microsecond','-1 microsecond'] as $shift) {
        $i=0;$clock=static function()use(&$i,$seed,$end,$shift){return r4live($seed,$i++===0?$end:r4time($end,$shift));};
        $deny(fn()=>Bridge::prepareSend($seed,$pack,$back,static fn()=>true,$clock),$shift[0]==='+'?'W4_SEND_WINDOW':'W4_SEND_CLOCK');
    }
    $k='derived_acceptance';$parent='h1_past_performance_None';$t=$in;$t['source_registry'][$k]=array_replace($in['source_registry'][$parent],
        ['source_kind'=>'DERIVED','source_ref'=>$k,'parents'=>[$parent],'formula_id'=>'identity','formula_version'=>'1','computed_at'=>$in['input_freeze_at']]);
    $good=r3stored($t);$ok($good->sourceValue($k)===['number','2'],'legitimate own identity');
    foreach ([['value',999,'W4_DERIVATION_VALUE'],['parents',['h2_past_performance_None'],'W4_DERIVATION_OWNER'],['formula_id','unregistered','W4_DERIVATION_VALUE'],['formula_version','2','W4_DERIVATION_SCOPE_VERSION'],
        ['formula_id','ratio_12dp','W4_DERIVATION_VALUE'],['computed_at',r4time($in['source_registry'][$parent]['available_at'],'-1 microsecond'),'W4_COMPUTED_BEFORE_DATA'],
        ['computed_at',r4time($end,'+1 microsecond'),'W4_COMPUTED_AFTER_REPLY']] as [$field,$value,$code]) {
        $x=$t;$x['source_registry'][$k][$field]=$value;$deny(fn()=>r3stored($x),$code);
    }
    $x=$in;$x['source_registry'][$k]=array_replace($in['source_registry'][$parent],['source_ref'=>$k]);$deny(fn()=>r3stored($x),'W4_DUPLICATE_OBSERVATION');
    $x=$t;$x['source_registry'][$k]['computed_at']=$end;$later=r3stored($x);
    $ok(Window::dispatch($later,r4live($later,$end))['ready']===$later->readyAt(),'ready exactly at dispatch');
    $deny(fn()=>Window::dispatch($later,r4live($later,r4time($end,'-1 microsecond'))),'W4_SEND_WINDOW');
    $x=$in;$x['source_registry']['h6_past_performance_None']['dependency_role']='CONTEXT';$context=r3stored($x);
    $vr=$o;$vr['snapshot_hash']=Frozen::checked($context)['snapshot_hash'];$raw=r3frame($vr);
    $deny(fn()=>Sel::verifyOriginal($context,$raw,hash('sha256',$raw)),'W3_EVIDENCE_FACTS');
    echo "PASS $count R4 derived provenance, recovery lineage, isolated role, receipt sequencing and live deadline checks.\n";
})($argv[2]);

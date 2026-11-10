<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
use App\Http\Controllers\Api\BaganrikiPublicView as View;
use App\Http\Controllers\Api\BaganrikiResponse as Reply;

function r3bytes(array $a):string {return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
function r3frame(array $a):string {return Reply::BEGIN."\n".r3bytes($a)."\n".Reply::END;}
function r3stored(array $a,?array $proof=null):Frozen {
    // Historical/synthetic fixtures may construct their own intentionally changed inputs.
    // The production entry point only accepts proof saved separately by the calculator.
    if ($proof===null) {
        try { $binding=\App\Services\Baganriki\ExpectedPacketW2::bindingForFrozen($a); }
        catch (InvalidArgumentException $invalidFixture) { $binding=str_repeat('0',64); }
        try { $market=\App\Services\Baganriki\MarketStateW2::verify($a); }
        catch (InvalidArgumentException $invalidFixture) { $market=str_repeat('0',64); }
        $testBytes=r3bytes($a['expected_v01']+['odds_binding_sha256'=>$binding,'market_source_sha256'=>$market]);$proof=['json'=>$testBytes,'sha256'=>hash('sha256',$testBytes)];
    }
    $common=Codec::commonHash($a);$a['snapshot_hash']=$common['hash'];
    return Frozen::fromStored($common['json'],r3bytes($a),$a['snapshot_hash'],$a['ai_role'],$proof);
}
function r3upgrade(array $base,array $out):array {
    $base['schema_version']=Sel::SCHEMA;$base['prompt_version']=Sel::VERSION;$role=$base['ai_role'];
    $extra=array_intersect_key($base,array_flip(['role_cap','role_quota','ai_role']));
    foreach (['role_cap','role_quota','ai_role','snapshot_hash'] as $k) unset($base[$k]);
    // The original fixtures are explicitly synthetic. Supply stable IDs and withhold historical E.
    $c=['race_id'=>$base['race_id'],'N'=>$base['N'],'post_at'=>$base['time_contract']['anchor_at'],'input_freeze_at'=>$base['input_freeze_at'],
        'jurisdiction'=>'JRA','surface'=>'DIRT','race_type'=>'FLAT','class_group'=>'C1','distance_metres'=>1800,'roster'=>[],'batches'=>[]];
    foreach ($base['runners'] as $h) $c['roster'][]=['horse_no'=>$h['horse_no'],'horse_id'=>'SYN-'.$h['horse_no']];
    foreach ([6,21] as $slot) {
        $b=$base['collection_audit'][$slot];if ($b['status']!=='ADOPTED') continue;
        $q=[];foreach ($c['roster'] as $r) {
            $refs=$base['input_indices']['horse/'.$r['horse_no'].'/win_odds/'.$slot]??[];
            $q[]=['horse_id'=>$r['horse_id'],'win'=>$refs?$base['source_registry'][$refs[0]]['value']:null];
        }
        $c['batches'][$slot]=['batch_id'=>$b['batch_id'],'requested_at'=>$b['request_started_at'],'received_at'=>$b['response_received_at'],'available_at'=>$b['available_at'],'quotes'=>$q];
    }
    $in=Bridge::attach($base,$c,null)['common']+$extra;$in['snapshot_hash']=Codec::commonHash($in)['hash'];
    foreach (['schema_version','prompt_version','snapshot_hash'] as $k) $out[$k]=$in[$k];
    $res=Sel::apply($in,$out['horses'],$out['band_ranks']);
    $out['expected_reviews']=$res['expected_reviews'];$out['selection_ranks']=$res['selection_ranks'];
    return [$in,$out];
}
function patched(array $doc,array $changes):array {
    foreach ($changes as $ch) {
        [$op,$path]=$ch;
        if (in_array($path[0],['prompt_version','schema_version'],true) && $op==='set' && is_string($ch[2])) { $doc[$path[0]]=$ch[2];continue; }
        $ptr=&$doc;$k=array_pop($path);foreach ($path as $v) $ptr=&$ptr[$v];
        if ($op==='del') unset($ptr[$k]);else $ptr[$k]=$ch[2];unset($ptr);
    }
    return $doc;
}
(function(string $dir) {
    $suite=json_decode(gzdecode(file_get_contents(__DIR__.'/r12_reference_cases.json.gz')),true,512,JSON_THROW_ON_ERROR);
    $examples=[];$byRace=[];$pairs=0;$negative=0;
    foreach ($suite['normal'] as $id=>$n) {
        [$in,$out]=r3upgrade($n['input'],$n['output']);$snap=r3stored($in);
        $raw=r3frame($out);$verified=Sel::verifyOriginal($snap,$raw,hash('sha256',$raw));
        expect($verified['public_candidates']===$n['output']['public_candidates'],'pre-existing selection without E '.$id);
        $examples[$id]=[$in,$out];
        $byRace[$in['race_id']][$in['ai_role']]=r4original($snap,$raw);
        $pairs++;
    }
    foreach ($suite['negative'] as $case) {
        [$in,$out]=$examples[$case['base']];$id=$case['id'];
        try {
            if ($case['kind']==='input') r3stored(patched($in,$case['changes']));
            else { $raw=r3frame(patched($out,$case['changes']));Sel::verifyOriginal(r3stored($in),$raw,hash('sha256',$raw)); }
        } catch (InvalidArgumentException $e) { ++$negative;continue; }
        throw new RuntimeException('Historic invalid case passed: '.$id);
    }
    echo "PASS R3 replays $pairs full reference role pairs and rejects $negative independently supplied r12.2 negatives after protocol upgrade.\n";
    $checks=0;
    $ok=static function($v,$name)use(&$checks){expect($v,$name);++$checks;};
    $bad=static function(callable $fn,string $prefix,string $name)use($ok){try{$fn();}catch(InvalidArgumentException $e){$ok(str_starts_with($e->getMessage(),$prefix),$name.' / '.$e->getMessage());return;}throw new RuntimeException('Accepted invalid '.$name);};
    $records=[];$proof=[];
    foreach (['1st','2nd'] as $role) {
        $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $ss=r3stored($in);$raw=r3frame($o);$hash=hash('sha256',$raw);$r=Sel::verifyOriginal($ss,$raw,$hash);
        $records[$role]=r4original($ss,$raw);
        foreach ($r['base_candidates'] as $n) $proof[$role][$n]=$hash;
        foreach(['horses','shortfalls','public_candidates'] as $field) {
            $wrong=$o;$wrong[$field]=(object)$wrong[$field];$bytes=r3frame($wrong);
            $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W3_SCHEMA_output','raw object in '.$field);
        }
        $wrong=$o;$wrong['unexpected']='fake';$bytes=r3frame($wrong);
        $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W3_SCHEMA_output','undeclared root field');
        $wrong=$o;$wrong['horses'][0]['evidence'][0]['facts'][0]['value']='2.0';$bytes=r3frame($wrong);
        $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W3_FACT_VALUE','string cannot quote numeric evidence');
        $wrong=$o;$wrong['horses'][0]['evidence'][0]['facts'][0]['value']=999;$bytes=r3frame($wrong);
        $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W3_FACT_VALUE','invented value rejected');
        $wrong=$o;$wrong['horses'][0]['evidence'][0]['facts'][0]['source_ref']='h2_past_performance_None';$bytes=r3frame($wrong);
        $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W3_REPLY_REF_OWNER','other horse fact rejected');
        $wrong=$o;$wrong['horses'][0]['family_review']['ability']['evidence_ids']=['ghost'];$bytes=r3frame($wrong);
        $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W3_FAMILY_IDS','fabricated family ID rejected');
        $wrong=$o;$wrong['horses'][5]['tier']='A';$wrong['horses'][5]['evidence'][]=array_replace($wrong['horses'][5]['evidence'][0],['family'=>'gap','evidence_id'=>'dup','dependency_group'=>'new-label']);
        $wrong['horses'][5]['family_review']['gap']=array_replace($wrong['horses'][5]['family_review']['ability'],['evidence_ids'=>['dup']]);$bytes=r3frame($wrong);
        $bad(fn()=>Sel::verifyOriginal($ss,$bytes,hash('sha256',$bytes)),'W13_EVIDENCE_FAMILY_gap','two family labels on one actual signal cannot prove Tier A');
    }
    $seed=$records['1st']['input'];$tree=Frozen::checked($seed);$live=range(1,16);$g=array_fill_keys($live,1);
    $merge=static fn($rs,$q,$nos)=>r9merge($seed,$rs,$q,$nos,$proof,r4live($seed,null,null,$nos===[],$nos));
    $pub=$merge($records,$tree['quota'],$live);
    $ok($pub===$merge(array_reverse($records,true),$tree['quota'],$live) && count($pub['public_candidates'])===7 && $pub['role_issues']===[],'replayed publication stable on job reorder');
    $late=$merge($records,['A'=>3,'B'=>2,'D'=>1],array_values(array_diff($live,[10])));
    $ok(count($late['public_candidates'])===6 && !in_array(10,$late['public_candidates'],true),'cancelled horse removed after replaying stored decisions');
    $damaged=$records;$damaged['1st']['receipts'][0]['raw'].=' ';
    $solo=$merge($damaged,$tree['quota'],$live);$ok(isset($solo['role_issues']['1st']) && max($solo['agreement_bonus'])===0,'corrupted first role isolated while second stays usable');
    $damaged['2nd']['receipts'][0]['raw'].=' ';
    $bad(fn()=>$merge($damaged,$tree['quota'],$live),'W2_NO_VERIFIED_ROLE','two rejected replies cannot publish');
    $tamper=$records;$tamper['1st']['public_candidates']=[16];$only=$merge($tamper,$tree['quota'],$live);
    $ok($only['role_issues']['1st']==='W3_ORIGINAL_RECORD','cached amended public list excluded from publication');
    $recovered=$records;$recovered['2nd']=r4recoveryRecord($ss,$o,$records['2nd']['receipts'][0]);
    $mixed=$merge($recovered,$tree['quota'],$live);$ok(count($mixed['public_candidates'])===7 && max($mixed['agreement_bonus'])===0,'recovery keeps candidates without inheriting original bonus');
    foreach ([0,6] as $impossible) { $badGroups=$g;$badGroups[1]=$impossible;
       $bad(fn()=>Sel::merge($seed,[$r],$tree['quota'],$live,$proof,$badGroups),'W2_MERGE_GROUPS','impossible independence count');
    }
    $single=$merge(['2nd'=>$records['2nd']],$tree['quota'],$live);
    $ok($single['selection_state']==='CANDIDATES_READY' && $single['role_issues']===['1st'=>'W3_ROLE_MISSING'],'single valid role carries an explicit partial status');
    $cancel=$merge([],null,[]);$ok($cancel['selection_state']==='CANCELLED' && !$cancel['public_candidates'],'cancelled is distinct from absent replies');
    foreach ($byRace as $id=>$rs) {
        $snap=$rs['1st']['input'];$t=Frozen::checked($snap);$nos=array_column($t['runners'],'horse_no');$ans=r9merge($snap,$rs,$t['quota'],$nos,[],r4live($snap));
        $ready=$ans['selection_state']==='CANDIDATES_READY';
        $payload=$ready?['release_id'=>Sel::VERSION,'public_candidates'=>$ans['public_candidates']]:null;
        $view=View::build(['race_id'=>$id,'state'=>View::state($ready,false,!$t['selection_enabled'],true),'mode_status'=>$t['mode_status'],
            'allocation_mode'=>$t['allocation_mode'],'display_rating'=>$t['display_rating'],'publication'=>$payload,'shortfalls'=>$ans['shortfalls']]);
        $ok($view['publication']===$payload && $view['buy_recommended']===false && $view['purchase_policy_status']==='PENDING_VALIDATION','submitted read DTO retains saved candidates '.$id);
    }
    echo "PASS $checks R3 exact evidence, publication replay and read-DTO checks; no external AI or live DB.\n";
})($argv[2]);

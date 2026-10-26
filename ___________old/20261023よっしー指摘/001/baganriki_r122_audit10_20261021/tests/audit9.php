<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
require __DIR__.'/audit4_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiDiagnose as Diagnose,BaganrikiPrompt as P,BaganrikiTime as T,BaganrikiWorker as W,BaganrikiResponse as Response};
use App\Support\Baganriki\ExactJson;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function must4(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function check9(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function scope9(string $scope,bool $complete=true): array {
    [$src,$res,$c]=eligibleFixture4($complete);
    $src['source_registry']['horse-1-signal']=['scope'=>$scope,'horse_nos'=>$scope==='PAIR'?[1,2]:[1],
        'applicable_horse_nos'=>$scope==='COHORT'?[1]:[],'dependency_role'=>'SIGNAL'];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=ExactJson::encode($src);
    $src2=$src; $src2['ai_role']='2nd'; DB::$rows['baganriki_snapshots'][0]['input_json_2nd']=ExactJson::encode($src2);
    return [$src,$res,$c];
}
function publish9(array $res,AiController $c): array {
    $res['ai_role']='2nd'; $row=DB::$rows['baganriki_ai_attempts'][0];
    $row['id']=20; $row['role']='2nd'; $row['token']='second-token';
    $row['raw_response']=Response::BEGIN.ExactJson::encode($res).Response::END;
    $row['response_hash']=hash('sha256',$row['raw_response']); DB::$rows['baganriki_ai_attempts'][]=$row;
    $cutoff=W::nowUs()-5000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($cutoff+90000000);
    foreach(DB::$rows['baganriki_ai_attempts'] as &$a) $a['received_at']=W::toDb($cutoff-10+(int)$a['attempt_no']);
    unset($a); DB::$rows['baganriki_publication_intents']=[];
    return $c->r122Publish(1);
}
foreach(['HORSE','PAIR','COHORT'] as $scope) foreach([false,true] as $complete) {
    $label=$scope.' '.($complete?'complete':'partial');
    check9('applicable SIGNAL survives qualification: '.$label,function() use($scope,$complete) {
        [$src,$res,$c]=scope9($scope,$complete); $out=$c->r122InspectResponse(1,'1st');
        must4($out['ok'] && !$out['needs_fix'] && array_keys($out['normal'])===[1,2],'valid scope rejected or unnecessarily repaired');
        $used=$c->r122ResponseUsed(1,'1st',$c->r122AcceptedHorses(1,'1st'));
        must4($used['band_ranks']['A']===[1,2] && $used['proof']!==null,'valid original rank or publication proof lost');
    });
    check9('two-role publication retains scoped evidence: '.$label,function() use($scope,$complete) {
        [$src,$res,$c]=scope9($scope,$complete); $pub=publish9($res,$c);
        must4($pub['ok'] && array_column($pub['candidates'],'horse_no')===[1,2],'applicable signal disappeared at publication');
        foreach($pub['candidates'] as $h) must4($h['bonus']===5.0,'verified original-candidate agreement bonus incorrect');
    });
}
foreach(['PAIR','COHORT'] as $scope) {
    check9('bad score preserves applicable signal: '.$scope,function() use($scope) {
        [$src,$res,$c]=scope9($scope); $res['horses'][0]['scores']['H1']=99; setRaw($res);
        $out=$c->r122InspectResponse(1,'1st');
        must4(array_keys($out['normal'])===[1,2] && $out['normal'][1]['scores']['H1']===null && $out['normal'][1]['score_status']==='partial','local bad score erased applicable qualification');
    });
    check9('valid correction restores scoped score: '.$scope,function() use($scope) {
        [$src,$res,$c]=scope9($scope); $first=$res; $first['horses'][0]['scores']['H1']=99;
        setRaw($first); addFix4($res); $out=$c->r122ResolveCorrection(1,'1st');
        must4($out['merged'][1]['total_score']===10 && $out['merged'][1]['score_status']==='complete','valid scoped correction lost');
    });
}
foreach([
    'foreign HORSE'=>['scope'=>'HORSE','horse_nos'=>[2],'applicable_horse_nos'=>[1],'dependency_role'=>'SIGNAL'],
    'foreign PAIR'=>['scope'=>'PAIR','horse_nos'=>[2,3],'applicable_horse_nos'=>[1],'dependency_role'=>'SIGNAL'],
    'foreign COHORT'=>['scope'=>'COHORT','horse_nos'=>[1],'applicable_horse_nos'=>[2],'dependency_role'=>'SIGNAL'],
    'RACE only'=>['scope'=>'RACE','horse_nos'=>[],'applicable_horse_nos'=>[],'dependency_role'=>'SIGNAL'],
    'PAIR context'=>['scope'=>'PAIR','horse_nos'=>[1,2],'applicable_horse_nos'=>[],'dependency_role'=>'CONTEXT'],
    'COHORT context'=>['scope'=>'COHORT','horse_nos'=>[],'applicable_horse_nos'=>[1],'dependency_role'=>'CONTEXT'],
    'unknown scope'=>['scope'=>'UNKNOWN','horse_nos'=>[1],'applicable_horse_nos'=>[1],'dependency_role'=>'SIGNAL']
] as $label=>$entry) check9('unqualified evidence stays excluded: '.$label,function() use($entry) {
    [$src,$res,$c]=eligibleFixture4(true); $src['source_registry']['horse-1-signal']=$entry;
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=ExactJson::encode($src);
    $out=$c->r122InspectResponse(1,'1st');
    must4(!isset($out['normal'][1]) && isset($out['normal'][2]),'foreign/context/race evidence created qualification or damaged other horse');
});

foreach(['Infinity Stable','NaN recorded','text -Infinity only','説明にNaNという文字がある'] as $value) check9('ordinary text is not a nonfinite number: '.$value,function() use($value) {
    $out=P::encode(['note'=>$value]);
    must4($out['ok'] && json_decode($out['json'],true)['note']===$value,'literal text rejected');
});
foreach(['positive'=>INF,'negative'=>-INF,'nan'=>NAN] as $label=>$value) check9('actual nonfinite number rejected: '.$label,function() use($value) {
    must4(!P::encode(['value'=>$value])['ok'],'nonfinite number encoded');
});
foreach([-1,0,1] as $delta) check9('generated JSON enforces exact 4 MiB boundary: '.$delta,function() use($delta) {
    $out=P::encode(['note'=>str_repeat('x',4194304-11+$delta)]);
    must4($out['ok']===($delta<=0),'wrong generated-byte limit');
    if($delta<=0) must4($out['bytes']===4194304+$delta,'byte count changed');
});
check9('multibyte JSON limit counts bytes',function() {
    must4(!P::encode(['note'=>str_repeat('馬',1398100)])['ok'],'UTF-8 characters counted instead of bytes');
});
check9('canonical JSON keeps order independence and null/zero distinction',function() {
    $a=P::encode(['z'=>null,'a'=>0,'text'=>'NaN']); $b=P::encode(['text'=>'NaN','a'=>0,'z'=>null]);
    must4($a['ok'] && $b['ok'] && $a['json']===$b['json'],'canonical object order changed');
    must4($a['json']!==P::encode(['z'=>0,'a'=>null,'text'=>'NaN'])['json'],'null and zero merged');
});

// These two query-list constants are the only stand-ins for the absent Constants class.
class Audit9Constants { public const ODDS_DB_FIRST=30; public const ODDS_DB_LAST=0; }
class_alias(Audit9Constants::class,'App\\Constants\\Constants');
function batch9(int $slot,bool $cancel=false,string $id=''): array {
    $at=T::slotScheduled(T::us('2026-10-24T12:00:00+09:00'),$slot);
    $raw=json_encode([['num'=>1,'tan'=>'2.1','fuku_min'=>'1.1','fuku_max'=>'1.2'],
        ['num'=>2,'tan'=>$cancel?'取消':'3.2','fuku_min'=>$cancel?null:'1.2','fuku_max'=>$cancel?null:'1.4']]);
    return ['race_id'=>'20261024-4-5-7-1','batch_id'=>$id?:'batch-'.$slot,'slot'=>$slot,'committed'=>1,
        'raw_payload'=>$raw,'payload_hash'=>hash('sha256',$raw),'slot_scheduled_at'=>W::toDb($at),'source_updated_at'=>W::toDb($at),
        'request_started_at'=>W::toDb($at),'response_received_at'=>W::toDb($at+1000000),'available_at'=>W::toDb($at+2000000)];
}
function gather9(array $batches,mixed $declared=null,?array $entries=null): array {
    resetFixture(); $key=['date'=>'2026-10-24','kaisuu'=>4,'basho'=>5,'day'=>7,'race'=>1];
    DB::$rows['t_horse_odds_finder_races']=[$key+['start_time'=>'12:00:00','num_horses'=>$declared]];
    $entries??=[['num'=>1,'name'=>''],['num'=>2,'name'=>'']];
    DB::$rows['t_horse_odds_finder_horses']=array_map(fn($entry)=>$key+$entry,$entries);
    DB::$rows['t_horse_odds_finder_odds']=[]; DB::$rows['baganriki_acquisition_batches']=$batches;
    return AiController::r122GatherContext('2026-10-24',4,5,7,1);
}
foreach([21,18,15,12,9] as $slot) check9('later adopted odds cannot resurrect cancellation: '.$slot,function() use($slot) {
    $out=gather9([batch9($slot,true),batch9(6)]);
    must4(!$out['ok'] && $out['code']==='CANCEL_CONFLICT' && $out['ctx']===null,'contradictory input continued as confirmed cancellation');
});
check9('earlier odds then later cancellation remains valid',function() {
    $out=gather9([batch9(21),batch9(6,true)]);
    must4($out['ok'] && $out['ctx']['cancelled']===[2],'normal chronological cancellation rejected');
});
check9('repeated cancellation does not create conflict',function() {
    $out=gather9([batch9(21,true),batch9(6,true)]);
    must4($out['ok'] && $out['ctx']['cancelled']===[2],'consistent repeated cancellation rejected');
});
foreach(['uncommitted','hash-mismatch','late'] as $kind) check9('unadopted cancellation cannot pollute conflict detection: '.$kind,function() use($kind) {
    $bad=batch9(21,true);
    if($kind==='uncommitted') $bad['committed']=0;
    if($kind==='hash-mismatch') $bad['payload_hash']=str_repeat('0',64);
    if($kind==='late') $bad['available_at']='2026-10-24 12:00:00';
    $out=gather9([$bad,batch9(6)]); must4($out['ok'] && $out['ctx']['cancelled']===[],'unadopted cancellation leaked');
});
foreach([3,'3',0,-1,'2.5','broken'] as $value) check9('declared headcount mismatch or invalid value stops input: '.json_encode($value),function() use($value) {
    $out=gather9([batch9(6)],$value); must4(!$out['ok'] && $out['code']==='ENTRY_SET_MISMATCH','declared headcount ignored');
});
foreach([2,'2',null] as $value) check9('matching or absent declared headcount remains supported: '.json_encode($value),function() use($value) {
    must4(gather9([batch9(6)],$value)['ok'],'valid headcount compatibility lost');
});
foreach(['1x',1.5,true,[],null,'9223372036854775808',0,-1] as $value) check9('invalid entry horse identity cannot be coerced: '.json_encode($value),function() use($value) {
    $out=gather9([batch9(6)],null,[['num'=>$value,'name'=>'One'],['num'=>2,'name'=>'Two']]);
    must4(!$out['ok'] && $out['code']==='ENTRY_SET_MISMATCH','invalid authoritative horse identity was cast or silently dropped');
});
check9('conflicting duplicate entry cannot silently overwrite its name',function() {
    $out=gather9([batch9(6)],2,[['num'=>1,'name'=>'One'],['num'=>1,'name'=>'Different'],['num'=>2,'name'=>'Two']]);
    must4(!$out['ok'] && $out['code']==='ENTRY_SET_MISMATCH','conflicting duplicate entry accepted');
});
check9('identical duplicate and padded integer database key stay supported',function() {
    $out=gather9([batch9(6)],2,[['num'=>'01','name'=>''],['num'=>1,'name'=>''],['num'=>'2','name'=>'']]);
    must4($out['ok'],'equivalent database key or identical duplicate rejected');
});

function extra9(): array {
    resetFixture();
    DB::$rows['t_horse_odds_finder_odds_gap_recovery']=[['gap_min'=>-100,'gap_max'=>0,'popularity_min'=>1,'popularity_max'=>1,
        'gap_band'=>'30%以上下落','start_date'=>'2026-01-01','end_date'=>'2026-10-23','computed_at'=>'2026-10-24 10:00:00',
        'recovery_rate'=>'123.12345678901234567890123456789','win_rate'=>'0.12345678901234567890123456789','sample_count'=>'9223372036854775808']];
    DB::$rows['t_horse_odds_finder_similar_race_stats']=[['horse_count_band'=>'small','popularity_rank'=>1,'updated_at'=>'2026-10-24 10:00:00',
        'top3_rate'=>'0.12345678901234567890123456789','top5_rate'=>'0.22345678901234567890123456789',
        'avg_finishing_position'=>'1.12345678901234567890123456789','outside_rate'=>'0.32345678901234567890123456789',
        'sample_count'=>'9223372036854775808','reliability'=>'high']];
    return AiController::r122ExtraRegistry(['race_id'=>'20261024-4-5-7-1','audit'=>[6=>[
        'response_received_at'=>'2026-10-24T11:54:01+09:00','available_at'=>'2026-10-24T11:54:02+09:00']]],
        [1=>1],[1=>[21=>['win_odds'=>'10'],6=>['win_odds'=>'5']]],T::us('2026-10-24T11:55:00+09:00'));
}
foreach(['recovery_rate','win_rate','sample_count'] as $field) check9('recovery raw numeric precision retained: '.$field,function() use($field) {
    $out=extra9(); $value=DB::$rows['t_horse_odds_finder_odds_gap_recovery'][0][$field];
    must4((string)$out['registry']['gaprec_dn30_1_1_'.$field]['value']===$value,'database number rounded or overflowed');
});
foreach(['top3_rate','top5_rate','avg_finishing_position','outside_rate','sample_count','reliability'] as $field) check9('similarity raw value retained: '.$field,function() use($field) {
    $out=extra9(); $value=DB::$rows['t_horse_odds_finder_similar_race_stats'][0][$field];
    must4((string)$out['registry']['similar_small_p1_'.$field]['value']===$value,'database value rounded or overflowed');
});
function marketTables9(): array { return [
    'popavg'=>'t_horse_odds_finder_popularity_rank_average',
    'fukuavg'=>'t_horse_odds_finder_fuku_popularity_rank_average',
    'corr'=>'t_horse_odds_finder_compute_odds_correction']; }
function marketRows9(): void {
    resetFixture();
    foreach(marketTables9() as $table) DB::$rows[$table]=array_map(static fn($n)=>[
        'popularity_rank'=>$n,'start_date'=>'2026-01-01','end_date'=>'2026-10-23','computed_at'=>'2026-10-24 10:00:00',
        'odds_average'=>'2.12345678901234567890123456789','count'=>'9223372036854775808',
        'avg_correction_ratio'=>'1.12345678901234567890123456789','std_correction_ratio'=>'0.12345678901234567890123456789',
        'sample_count'=>'9223372036854775808'],[1,2]);
}
function market9(): array {
    return AiController::r122MarketPriorRegistry('20261024-4-5-7-1','2026-10-24',[1=>1,2=>2],
        [1=>[6=>['win_odds'=>'2','place_min'=>'1.2']],2=>[6=>['win_odds'=>'3','place_min'=>'1.4']]],'2026-10-24T11:55:00+09:00');
}
foreach(marketTables9() as $tag=>$table) foreach([null,'bad','2026-02-30 10:00:00','2026-10-24 11:55:00.000001'] as $time) {
    check9('invalid row cannot borrow another row timestamp: '.$tag.' '.json_encode($time),function() use($tag,$table,$time) {
        marketRows9(); DB::$rows[$table][0]['computed_at']=$time; $out=market9();
        foreach(array_keys($out['registry']) as $ref) must4(!str_starts_with($ref,$tag.'_rank1_'),'invalid row acquired another row timestamp');
        must4(count(array_filter(array_keys($out['registry']),fn($r)=>str_starts_with($r,$tag.'_rank2_')))>0,'valid peer row lost');
        $field=$tag==='popavg'?'opi':($tag==='fukuavg'?'fuku_opi':'estimated_final_odds');
        must4(!isset($out['registry']['h1_'.$field]),'derived value uses invalid row');
    });
}
foreach(marketTables9() as $tag=>$table) {
    check9('valid statistics keep their own row timestamp: '.$tag,function() use($tag,$table) {
        marketRows9(); DB::$rows[$table][0]['computed_at']='2026-10-24 10:00:00.123456';
        DB::$rows[$table][1]['computed_at']='2026-10-24 11:00:00'; $out=market9();
        foreach($out['registry'] as $ref=>$entry) if(str_starts_with($ref,$tag.'_rank1_')) {
            must4(T::us($entry['available_at'])===T::us('2026-10-24T10:00:00.123456+09:00'),'valid row received a peer timestamp or lost microseconds');
        }
    });
    check9('verified_at fallback and exact cutoff remain valid: '.$tag,function() use($tag,$table) {
        marketRows9(); DB::$rows[$table][0]['computed_at']=null; DB::$rows[$table][0]['verified_at']='2026-10-24 11:55:00.000000';
        $out=market9(); must4(count(array_filter(array_keys($out['registry']),fn($r)=>str_starts_with($r,$tag.'_rank1_')))>0,'legacy verified timestamp or inclusive cutoff rejected');
    });
    check9('malformed recorded computed_at cannot use verified_at fallback: '.$tag,function() use($tag,$table) {
        marketRows9(); DB::$rows[$table][0]['computed_at']='bad'; DB::$rows[$table][0]['verified_at']='2026-10-24 10:00:00';
        $out=market9(); must4(count(array_filter(array_keys($out['registry']),fn($r)=>str_starts_with($r,$tag.'_rank1_')))===0,'invalid recorded time silently replaced');
    });
}
foreach([
    'popavg_rank1_win_odds'=>['popavg','odds_average'],'popavg_rank1_count'=>['popavg','count'],
    'fukuavg_rank1_place_min'=>['fukuavg','odds_average'],'fukuavg_rank1_count'=>['fukuavg','count'],
    'corr_rank1_avg_correction_ratio'=>['corr','avg_correction_ratio'],'corr_rank1_std_correction_ratio'=>['corr','std_correction_ratio'],
    'corr_rank1_sample_count'=>['corr','sample_count']
] as $ref=>[$tag,$column]) check9('market raw numeric precision retained: '.$ref,function() use($ref,$tag,$column) {
    marketRows9(); $out=market9(); $value=DB::$rows[marketTables9()[$tag]][0][$column];
    must4((string)$out['registry'][$ref]['value']===$value,'market raw value rounded or overflowed');
});
check9('unavailable statistics stay unavailable when every timestamp is missing',function() {
    marketRows9(); foreach(marketTables9() as $table) foreach(DB::$rows[$table] as &$row) $row['computed_at']=null; unset($row);
    $out=market9(); must4($out['registry']===[] && $out['coverage']['OPI']['status']==='UNAVAILABLE' && $out['coverage']['estimated_odds']['status']==='UNAVAILABLE','missing timestamps fabricated');
});

foreach([1048,1451,1452,3819,1213,1205] as $code) check9('nonduplicate database error is not disguised: '.$code,function() use($code) {
    $e=new PDOException('SQLSTATE[23000]: Integrity constraint violation: '.$code.' failure'); $e->errorInfo=['23000',$code,'failure'];
    must4(!W::isDup($e),'unrelated SQL failure treated as an occupied slot');
});
check9('duplicate error identified by vendor code even when localized',function() {
    $e=new PDOException('一意制約違反'); $e->errorInfo=['23000',1062,'localized'];
    must4(W::isDup($e) && W::isDup(new RuntimeException('wrapped',0,$e)),'MySQL duplicate vendor code not recognized');
});
check9('unstructured SQL-like value is not a duplicate error',function() {
    must4(!W::isDup(new RuntimeException('connection failed for race 1062')),'arbitrary 1062 substring classified as duplicate');
});
check9('legacy duplicate-entry message remains recognized',function() {
    must4(W::isDup(new RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'unique'")),'real duplicate message no longer recognized');
});
echo "AUDIT9 RESULT: $passed passed, $failed failed. PHP with DB/HTTP doubles; no real database or external AI.\n";
exit($failed===0?0:1);

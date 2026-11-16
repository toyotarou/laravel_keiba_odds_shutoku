<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
require __DIR__.'/audit4_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiTime as T,BaganrikiAcquisition as Acquisition,BaganrikiWorker as W};
use App\Support\Baganriki\R122Jobs;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function must4(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function check8(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
function batch8(int $slot): array {
    $anchor=T::us('2026-10-24T12:00:00.123456+09:00');
    $scheduled=T::slotScheduled($anchor,$slot);
    return [$anchor,[
        'batch_id'=>'good','committed'=>true,'slot_scheduled_at'=>T::toJst($scheduled),
        'request_started_at'=>T::toJst($scheduled),
        'response_received_at'=>T::toJst($scheduled+1),
        'available_at'=>T::toJst($scheduled+2),
        'input_freeze_at'=>T::toJst(T::inputFreeze($anchor)),
        'source_updated_at'=>T::toJst($scheduled),
        'values'=>[1=>['win'=>'2.1'],2=>['win'=>'3.2']],
    ]];
}
function authorized8(callable $fn): mixed {
    $context=new ReflectionProperty(R122Jobs::class,'roleContext');
    $lock=new ReflectionProperty(R122Jobs::class,'roleLock');
    $previousContext=$context->getValue(); $previousLock=$lock->getValue();
    $pdo=(new ReflectionMethod(R122Jobs::class,'workLock'))->invoke(null,'role-run:1:1st');
    must4($pdo instanceof PDO,'test role lock unavailable');
    $context->setValue(null,[1,'1st']); $lock->setValue(null,$pdo);
    try { return $fn(); }
    finally {
        $context->setValue(null,$previousContext); $lock->setValue(null,$previousLock);
        (new ReflectionMethod(R122Jobs::class,'workUnlock'))->invoke(null,$pdo,'role-run:1:1st');
    }
}
function ready8(): int {
    $cutoff=W::nowUs()-5000000;
    DB::$rows['baganriki_snapshots'][0]['sales_close_at']=W::toDb($cutoff+90000000);
    foreach(DB::$rows['baganriki_ai_attempts'] as &$a) $a['received_at']=W::toDb($cutoff-10+(int)$a['attempt_no']);
    unset($a); DB::$rows['baganriki_publication_intents']=[];
    return $cutoff;
}
// The supplied package does not include the app's Constants class. Only these
// query-list constants are needed to exercise the real context/time adapter.
class Audit8Constants { public const ODDS_DB_FIRST=30; public const ODDS_DB_LAST=0; }
class_alias(Audit8Constants::class,'App\\Constants\\Constants');
function dbBatch8(): array {
    $payload=json_encode([['num'=>1,'tan'=>'2.1','fuku_min'=>'1.1','fuku_max'=>'1.2'],['num'=>2,'tan'=>'3.2','fuku_min'=>'1.2','fuku_max'=>'1.4']]);
    return ['race_id'=>'20261024-4-5-7-1','batch_id'=>'good','slot'=>6,'committed'=>1,
        'raw_payload'=>$payload,'payload_hash'=>hash('sha256',$payload),
        'slot_scheduled_at'=>'2026-10-24 11:54:00','source_updated_at'=>'2026-10-24 11:54:00',
        'request_started_at'=>'2026-10-24 11:54:00','response_received_at'=>'2026-10-24 11:54:01',
        'available_at'=>'2026-10-24 11:54:02'];
}
function gather8(array $batches): array {
    resetFixture();
    $key=['date'=>'2026-10-24','kaisuu'=>4,'basho'=>5,'day'=>7,'race'=>1];
    DB::$rows['t_horse_odds_finder_races']=[$key+['start_time'=>'12:00:00']];
    DB::$rows['t_horse_odds_finder_horses']=[$key+['num'=>1,'name'=>''],$key+['num'=>2,'name'=>'']];
    DB::$rows['t_horse_odds_finder_odds']=[];
    DB::$rows['baganriki_acquisition_batches']=$batches;
    return AiController::r122GatherContext('2026-10-24',4,5,7,1);
}

// RFC3339's numeric offset uses hours 00..23 and minutes 00..59 (section 5.6).
foreach([
    '2026-10-24T12:00:00+09:60','2026-10-24T12:00:00-03:60',
    '2026-10-24T12:00:00+24:00','2026-10-24T12:00:00-24:00',
    '2026-10-24T12:00:00+99:99','2026-10-24T12:00:00-99:99',
    "2026-10-24T12:00:00Z\n","2026-10-24T12:00:00+09:00\n",
    '2026-10-24T12:00:00.1234567Z','2026-10-24T12:00:00'
] as $value) check8('invalid RFC timestamp rejected: '.json_encode($value),function() use($value) {
    must4(T::us($value)===null,'invalid offset, suffix, precision or missing zone accepted');
});
foreach([
    '2026-10-24T03:04:05.123456Z','2026-10-24t03:04:05.123456z',
    '2026-10-24T03:04:05.123456+00:00','2026-10-24T03:04:05.123456-00:00',
    '2026-10-24T12:04:05.123456+09:00','2026-10-24T08:49:05.123456+05:45',
    '2026-10-23T23:34:05.123456-03:30','2026-10-25T03:03:05.123456+23:59',
    '2026-10-23T03:05:05.123456-23:59'
] as $value) check8('valid offset keeps the same instant: '.$value,function() use($value) {
    must4(T::us($value)===1792811045123456,'valid numeric offset or microseconds changed');
});

foreach(T::INPUT_SLOTS as $slot) {
    check8('acquisition cutoff is inclusive to one microsecond: slot '.$slot,function() use($slot) {
        [$a,$b]=batch8($slot); $limit=T::slotDeadline($a,$slot);
        foreach([-1,0,1] as $delta) {
            $b['available_at']=T::toJst($limit+$delta); $r=T::acceptBatch($slot,$b,$a);
            must4($r['ok']===($delta<=0),'wrong cutoff decision at delta '.$delta);
        }
    });
    check8('malformed explicit schedule is not reconstructed: slot '.$slot,function() use($slot) {
        [$a,$b]=batch8($slot); $b['slot_scheduled_at']='2026-02-30T12:00:00+09:00';
        $r=T::acceptBatch($slot,$b,$a);
        must4(!$r['ok'] && $r['code']==='TIME_UNREADABLE','malformed explicit schedule replaced by anchor');
    });
    check8('absent or null schedule retains documented anchor fallback: slot '.$slot,function() use($slot) {
        [$a,$b]=batch8($slot); unset($b['slot_scheduled_at']);
        must4(T::acceptBatch($slot,$b,$a)['ok'],'absent schedule cannot use anchor');
        $b['slot_scheduled_at']=null;
        must4(T::acceptBatch($slot,$b,$a)['ok'],'null schedule compatibility lost');
        must4(!T::acceptBatch($slot,$b,null)['ok'],'missing schedule and anchor accepted');
    });
    check8('complete audit timestamps do not require a second anchor: slot '.$slot,function() use($slot) {
        [$a,$b]=batch8($slot); must4(T::acceptBatch($slot,$b,null)['ok'],'valid explicit schedule rejected');
    });
}
$fields=['request_started_at','response_received_at','available_at','input_freeze_at','slot_scheduled_at','source_updated_at'];
foreach($fields as $field) foreach(['text'=>'not-a-time','array'=>[],'object'=>new stdClass()] as $kind=>$value) {
    check8('bad timestamp only rejects its own acquisition batch: '.$field.' '.$kind,function() use($field,$value) {
        [$a,$good]=batch8(6); $bad=$good; $bad['batch_id']='a-bad'; $bad[$field]=$value;
        $r=Acquisition::pick(6,[$bad,$good],[1,2],$a);
        must4($r['status']==='ADOPTED' && $r['batch']['batch_id']==='good','bad audit row adopted or good batch lost');
        must4(count($r['rejected'])===1 && $r['rejected'][0]['code']==='TIME_UNREADABLE','bad timestamp not recorded as a local failure');
    });
}
check8('invalid offsets cannot normalize into matching acquisition schedule',function() {
    [$a,$b]=batch8(6); $b['slot_scheduled_at']=str_replace('+09:00','+08:60',$b['slot_scheduled_at']);
    $r=T::acceptBatch(6,$b,$a);
    must4(!$r['ok'] && $r['code']==='TIME_UNREADABLE','invalid offset normalized into the expected schedule');
});
check8('all malformed schedules leave acquisition unavailable even with cancellation',function() {
    [$a,$b]=batch8(6); $b['slot_scheduled_at']='broken'; $b['cancelled']=[1,2]; $b['values']=[];
    $r=Acquisition::pick(6,[$b],[1,2],$a);
    must4($r['status']==='UNAVAILABLE' && $r['batch']===null,'cancellation bypassed the timestamp requirement');
});
check8('equivalent UTC and JST audit timestamps preserve selected batch',function() {
    [$a,$b]=batch8(6);
    foreach(['slot_scheduled_at','request_started_at','response_received_at','available_at','input_freeze_at','source_updated_at'] as $field) {
        $b[$field]=(new DateTimeImmutable($b[$field]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
    must4(Acquisition::pick(6,[$b],[1,2],$a)['status']==='ADOPTED','equivalent UTC representation changed adoption');
});
foreach(['slot_scheduled_at','source_updated_at'] as $field) foreach([
    'broken','2026-02-30 11:54:00','2026-10-24 11:54:00.1234567'
] as $value) check8('database adapter cannot erase invalid timestamp: '.$field.' '.$value,function() use($field,$value) {
    $good=dbBatch8(); $bad=$good; $bad['batch_id']='a-bad'; $bad[$field]=$value;
    $bad['raw_payload']=json_encode([['num'=>1,'tan'=>'9.9'],['num'=>2,'tan'=>'取消']]);
    $bad['payload_hash']=hash('sha256',$bad['raw_payload']);
    $out=gather8([$bad,$good]);
    must4($out['ok'],'valid alternative batch lost: '.json_encode($out));
    must4($out['ctx']['audit'][6]['batch_id']==='good','database adapter laundered invalid timestamp into an accepted batch');
    must4($out['ctx']['cancelled']===[],'invalid batch cancellation leaked into frozen runners');
});
foreach(['','.0','.00','.000','.0000','.00000','.000000'] as $fraction) check8('database schedule comparison uses instant, not fractional spelling: '.($fraction?:'seconds'),function() use($fraction) {
    $b=dbBatch8(); $b['slot_scheduled_at'].=$fraction; $out=gather8([$b]);
    must4($out['ok'] && $out['ctx']['audit'][6]['batch_id']==='good','same schedule instant rejected: '.json_encode($out));
});
check8('database adapter preserves nullable optional timestamps',function() {
    $b=dbBatch8(); $b['slot_scheduled_at']=$b['source_updated_at']=null;
    $out=gather8([$b]);
    must4($out['ok'] && $out['ctx']['audit'][6]['batch_id']==='good','documented null compatibility lost');
});
foreach(['2026-10-24 11:53:59.999999','2026-10-24 11:54:00.000001'] as $value) check8('database schedule mismatch of one microsecond is rejected: '.$value,function() use($value) {
    $b=dbBatch8(); $b['slot_scheduled_at']=$value; $out=gather8([$b]);
    must4(!$out['ok'],'schedule mismatch was rounded into the expected instant');
});
check8('database adapter cannot manufacture a usable batch from invalid optional dates',function() {
    $b=dbBatch8(); $b['slot_scheduled_at']=$b['source_updated_at']='broken';
    must4(!gather8([$b])['ok'],'invalid-only audit still generated usable context');
});

foreach(['broken','0000-00-00 00:00:00','2026-02-30 12:00:00','2026-10-24 12:00:00.1234567'] as $value) {
    check8('unreadable non-null live close cannot become no change: '.$value,function() use($value) {
        resetFixture(); $live=(object)DB::$rows['baganriki_live_states'][0]; $live->live_close=$value;
        $r=W::deadlines((object)DB::$rows['baganriki_snapshots'][0],$live);
        must4(!$r['ok'] && $r['code']==='TIME_UNCOMPUTABLE' && $r['reply']===null && $r['publish']===null,'frozen deadline was used despite unreadable live change');
    });
}
foreach(['null-row','missing-field','null-field','earlier','later','datetime'] as $kind) check8('valid live close keeps existing deadline policy: '.$kind,function() use($kind) {
    resetFixture(); $snap=(object)DB::$rows['baganriki_snapshots'][0]; $sales=W::dbUs($snap->sales_close_at);
    $live=match($kind) {
        'null-row'=>null,'missing-field'=>(object)[], 'null-field'=>(object)['live_close'=>null],
        'earlier'=>(object)['live_close'=>W::toDb($sales-60000000)],
        'later'=>(object)['live_close'=>W::toDb($sales+60000000)],
        'datetime'=>(object)['live_close'=>new DateTimeImmutable(T::toJst($sales-60000000))],
    };
    $r=W::deadlines($snap,$live); $close=in_array($kind,['earlier','datetime'],true)?$sales-60000000:$sales;
    must4($r['ok'] && $r['reply']===$close-90000000 && $r['publish']===$close-60000000,'normal live deadline changed');
});
check8('unreadable live deadline blocks reservation without consuming a slot',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts']=[];
    DB::$rows['baganriki_live_states'][0]['live_close']='broken'; $before=DB::$rows;
    $r=authorized8(fn()=>W::reserve(1,'1st',W::FIRST,W::nowUs()));
    must4(!$r['ok'] && $r['code']==='TIME_UNCOMPUTABLE','invalid deadline authorized a reservation');
    must4(DB::$rows===$before && TestHttp::$calls===[],'blocked reservation changed state');
});
check8('unreadable live deadline blocks send right and preserves reservation',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts'][0]['status']=W::RESERVED;
    DB::$rows['baganriki_live_states'][0]['live_close']='broken'; $before=DB::$rows;
    $r=authorized8(fn()=>W::allowSend('token',W::nowUs()));
    must4(!$r['ok'] && $r['code']==='TIME_UNCOMPUTABLE','invalid deadline authorized sending');
    must4(DB::$rows===$before && TestHttp::$calls===[],'blocked send consumed reservation');
});
check8('normal null live deadline still permits one reservation and one send right',function() {
    resetFixture(); DB::$rows['baganriki_ai_attempts']=[];
    authorized8(function() {
        $r=W::reserve(1,'1st',W::FIRST,W::nowUs()); must4($r['ok'],'normal reservation denied: '.json_encode($r));
        must4(W::allowSend($r['token'],W::nowUs())['ok'],'normal send right denied');
        must4(!W::allowSend($r['token'],W::nowUs())['ok'],'send right granted twice');
    });
    must4(count(DB::$rows['baganriki_ai_attempts'])===1 && TestHttp::$calls===[],'test unexpectedly sent HTTP or duplicated a slot');
});
check8('unreadable live close preserves received evidence only for audit',function() {
    [$src,$res,$c]=eligibleFixture4(); DB::$rows['baganriki_live_states'][0]['live_close']='broken';
    $r=$c->r122InspectResponse(1,'1st');
    must4(!$r['ok'] && $r['code']==='TIME_UNCOMPUTABLE' && $r['normal']===[],'response qualified without a readable effective deadline');
    must4(count($r['normal_kept'])===2,'received audit evidence lost');
});
check8('unreadable live close blocks publication without changing its pointer',function() {
    [$src,$res,$c]=eligibleFixture4(); ready8();
    DB::$rows['baganriki_live_states'][0]['live_close']='broken'; $before=DB::$rows['baganriki_live_states'][0];
    must4(R122Jobs::publicationGate(1)===false,'job gate allowed invalid deadline');
    $r=$c->r122Publish(1);
    must4(!$r['ok'] && DB::$rows['baganriki_publications']===[] && DB::$rows['baganriki_publication_intents']===[],'invalid deadline publication persisted');
    must4(DB::$rows['baganriki_live_states'][0]===$before,'latest publication pointer or revision changed');
});
check8('valid null live close still publishes at the frozen deadline',function() {
    [$src,$res,$c]=eligibleFixture4(); ready8(); $r=$c->r122Publish(1);
    must4($r['ok'] && array_column($r['candidates'],'horse_no')===[1,2],'valid frozen-deadline publication blocked');
    must4(count(DB::$rows['baganriki_publications'])===1,'normal publication missing');
});
foreach([-1,0,1] as $delta) check8('earlier close with cancellation and corrected score: receipt offset '.$delta,function() use($delta) {
    [$src,$res,$c]=eligibleFixture4(true); $first=$res; $first['horses'][0]['scores']['H1']=99;
    setRaw($first); addFix4($res);
    $cutoff=W::nowUs()-5000000;
    DB::$rows['baganriki_live_states'][0]['live_close']=W::toDb($cutoff+90000000);
    DB::$rows['baganriki_live_states'][0]['cancellations']='[2]';
    DB::$rows['baganriki_live_states'][0]['active_count']=1;
    DB::$rows['baganriki_ai_attempts'][0]['received_at']=W::toDb($cutoff-10);
    DB::$rows['baganriki_ai_attempts'][1]['received_at']=W::toDb($cutoff+$delta);
    DB::$rows['baganriki_publication_intents']=[];
    $accepted=$c->r122ResolveCorrection(1,'1st');
    must4($accepted['merged'][1]['scores']['H1']===($delta<=0?1:null),'late correction replaced initial score or valid correction was lost');
    $out=$c->r122Publish(1);
    must4($out['ok'] && array_column($out['candidates'],'horse_no')===[1],'cancelled runner published or remaining runner lost');
});
echo "AUDIT8 RESULT: $passed passed, $failed failed. PHP with DB/HTTP doubles; no production runtime or external AI.\n";
exit($failed===0?0:1);

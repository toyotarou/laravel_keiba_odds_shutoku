<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
use App\Http\Controllers\Api\{AiController,BaganrikiAcquisition as A,BaganrikiPrompt as P,BaganrikiSnapshot as S,BaganrikiTime as T,BaganrikiWorker as W};
use App\Support\Baganriki\{Decimal,ExactJson};
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$passed=0; $failed=0;
function must10(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function check10(string $name,callable $test): void {
    global $passed,$failed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; echo "FAIL $name: ",get_class($e),' ',$e->getMessage(),"\n"; }
}
// The actual application Constants class is absent from the supplied project.
class Audit10Constants { public const ODDS_DB_FIRST=30; public const ODDS_DB_LAST=0; }
class_alias(Audit10Constants::class,'App\\Constants\\Constants');
function rows10(): array {
    return [['num'=>1,'tan'=>'2.1','fuku_min'=>'1.1','fuku_max'=>'1.2'],
        ['num'=>2,'tan'=>'3.2','fuku_min'=>'1.2','fuku_max'=>'1.4']];
}
function batch10(string $raw,string $id='a-first',int $slot=6): array {
    $at=T::slotScheduled(T::us('2026-10-24T12:00:00+09:00'),$slot);
    return ['race_id'=>'20261024-4-5-7-1','batch_id'=>$id,'slot'=>$slot,'committed'=>1,
        'raw_payload'=>$raw,'payload_hash'=>hash('sha256',$raw),'slot_scheduled_at'=>W::toDb($at),'source_updated_at'=>W::toDb($at),
        'request_started_at'=>W::toDb($at),'response_received_at'=>W::toDb($at+1000000),'available_at'=>W::toDb($at+2000000)];
}
function gather10(array $batches): array {
    resetFixture(); $key=['date'=>'2026-10-24','kaisuu'=>4,'basho'=>5,'day'=>7,'race'=>1];
    DB::$rows['t_horse_odds_finder_races']=[$key+['start_time'=>'12:00:00','num_horses'=>2]];
    DB::$rows['t_horse_odds_finder_horses']=[$key+['num'=>1,'name'=>'One'],$key+['num'=>2,'name'=>'Two']];
    DB::$rows['t_horse_odds_finder_odds']=[]; DB::$rows['baganriki_acquisition_batches']=$batches;
    return AiController::r122GatherContext('2026-10-24',4,5,7,1);
}
function backup10(string $raw,int $slot=6): array {
    $batches=[batch10($raw,'a-first',$slot),batch10(ExactJson::encode(rows10()),'z-backup',$slot)];
    if($slot!==6) $batches[]=batch10(ExactJson::encode(rows10()),'six',6);
    $out=gather10($batches);
    must10($out['ok'],'bad batch stopped all input');
    must10(($out['ctx']['audit'][$slot]['batch_id']??null)==='z-backup','bad batch adopted or good backup lost');
    must10($out['ctx']['cancelled']===[],'rejected batch cancellation leaked');
    return $out;
}
function value10(array $ctx,string $field,int $slot=6): string {
    return P::numToDecimalString($ctx['registry']['h1_'.$field.'_'.$slot]['value']??null);
}

foreach([
    'above two'=>'2.0000000000000000000000000001',
    'below two'=>'1.9999999999999999999999999999',
    'large integer'=>'9223372036854775809',
    'small exponent'=>'1e-25',
    'fraction exponent'=>'1.234567890123456789012345678e1',
    '128 integer digits'=>str_repeat('9',128),
    '128 significant digits'=>'1.'.str_repeat('0',126).'1',
] as $label=>$token) foreach(['tan'=>'win_odds','fuku_min'=>'place_min','fuku_max'=>'place_max'] as $source=>$field) {
    check10('raw numeric value survives registry and wire: '.$label.' '.$source,function() use($token,$source,$field) {
        $rows=rows10(); $rows[0][$source]=new Decimal($token);
        $out=gather10([batch10(ExactJson::encode($rows))]);
        must10($out['ok'],'exact numeric batch rejected'); $ctx=$out['ctx'];
        $expected=(string)new Decimal($token);
        must10(value10($ctx,$field)===$expected,'raw number rounded or lost before registry');
        $built=P::buildInput($ctx,'1st'); must10($built['ok'],'exact input construction failed');
        $wire=P::encode($built['input']); must10($wire['ok'],'exact input serialization failed');
        $roundTrip=ExactJson::decode($wire['json']);
        must10(P::numToDecimalString($roundTrip['source_registry']['h1_'.$field.'_6']['value'])===$expected,'serialized registry lost exact value');
        if($source!=='fuku_max') {
            $horses=P::horsesForSignals($ctx['registry'],[1=>1,2=>2]);
            $key=$source==='tan'?'tan_series':'fuku_min_series';
            must10($horses[0][$key][6]===$expected,'signal input rounded raw value');
        }
    });
}
foreach([
    'fraction'=>['2.0000000000000000000000000001','2.0000000000000000000000000000'],
    'large integers'=>['9223372036854775810','9223372036854775809'],
] as $label=>[$higher,$lower]) check10('raw numeric popularity is exact: '.$label,function() use($higher,$lower) {
    $rows=rows10(); $rows[0]['tan']=new Decimal($higher); $rows[1]['tan']=new Decimal($lower);
    $out=gather10([batch10(ExactJson::encode($rows))]); must10($out['ok'],'gather failed');
    $b=P::buildInput($out['ctx'],'1st'); must10($b['ok'],'build failed');
    $ranks=array_column($b['input']['runners'],'win_rank','horse_no');
    must10($ranks[2]===1 && $ranks[1]===2,'rounding turned different odds into a horse-number tie');
});
check10('equal values with different numeric spellings keep horse-number tie break',function() {
    $rows=rows10(); $rows[0]['tan']=new Decimal('2.00'); $rows[1]['tan']=new Decimal('20e-1');
    $out=gather10([batch10(ExactJson::encode($rows))]); $b=P::buildInput($out['ctx'],'1st');
    must10($b['ok'] && array_column($b['input']['runners'],'win_rank','horse_no')===[1=>1,2=>2],'equal exact values no longer tie');
});

foreach([
    'cancel overwritten'=>'[{"num":1,"tan":"取消","tan":"2.1"},{"num":2,"tan":"3.2"}]',
    'escaped key alias'=>'[{"num":1,"tan":"取消","t\\u0061n":"2.1"},{"num":2,"tan":"3.2"}]',
    'identical duplicate'=>'[{"num":1,"tan":"2.1","tan":"2.1"},{"num":2,"tan":"3.2"}]',
    'horse overwritten'=>'[{"num":99,"num":1,"tan":"2.1"},{"num":2,"tan":"3.2"}]',
    'root key overwritten'=>'{"row":{"num":1,"tan":"取消"},"row":{"num":1,"tan":"2.1"},"second":{"num":2,"tan":"3.2"}}',
    'nested key overwritten'=>'[{"num":1,"tan":"2.1","meta":{"x":0,"x":1}},{"num":2,"tan":"3.2"}]',
] as $label=>$raw) foreach([6,21] as $slot) check10('duplicate JSON keys reject only their batch: '.$label.' slot '.$slot,function() use($raw,$slot) {
    backup10($raw,$slot);
});
foreach(['"1x"','1.9','1.0','1e0','true','false','[]','{}','null','0','-1','"9223372036854775808"','9223372036854775808'] as $token) {
    foreach([6,21] as $slot) check10('invalid raw horse number is never coerced: '.$token.' slot '.$slot,function() use($token,$slot) {
        backup10('[{"num":'.$token.',"tan":"2.1"},{"num":2,"tan":"3.2"}]',$slot);
    });
}
foreach(['null','true','123','"row"','[]','{}','{"tan":"4.5"}','{"num":0,"tan":"4.5"}'] as $row) check10('malformed extra row cannot silently disappear: '.$row,function() use($row) {
    backup10(substr(ExactJson::encode(rows10()),0,-1).','.$row.']');
});
foreach(['1','"1"','"01"','" 1 "'] as $token) check10('positive raw horse identity remains supported: '.$token,function() use($token) {
    $raw='[{"num":'.$token.',"tan":"2.1"},{"num":2,"tan":"3.2"}]';
    $out=gather10([batch10($raw)]);
    must10($out['ok'] && value10($out['ctx'],'win_odds')==='2.1','valid horse identity rejected');
});
foreach(['list','named object','numeric object'] as $shape) check10('legacy row collection shape remains supported: '.$shape,function() use($shape) {
    $rows=rows10();
    $raw=$shape==='list'?ExactJson::encode($rows):ExactJson::encode((object)($shape==='named object'?['one'=>$rows[0],'two'=>$rows[1]]:$rows));
    $out=gather10([batch10($raw)]);
    must10($out['ok'] && ($out['ctx']['audit'][6]['batch_id']??null)==='a-first','valid row container rejected');
});
foreach(['true','false','[]','{}','[2.1]','{"value":2.1}','null','0','-2.1','"NaN"','"Infinity"'] as $token) {
    check10('invalid raw win odds do not satisfy six-minute completeness: '.$token,function() use($token) {
        backup10('[{"num":1,"tan":'.$token.'},{"num":2,"tan":"3.2"}]');
    });
}
foreach(['tan'=>'win_odds','fuku_min'=>'place_min','fuku_max'=>'place_max'] as $source=>$field) {
    foreach(['true','[]','{}'] as $token) check10('partial earlier slot preserves missing odds: '.$source.' '.$token,function() use($source,$field,$token) {
        $rows=rows10(); $rows[0][$source]=ExactJson::decode($token);
        $out=gather10([batch10(ExactJson::encode($rows),'partial',21),batch10(ExactJson::encode(rows10()),'six',6)]);
        must10($out['ok'] && ($out['ctx']['audit'][21]['batch_id']??null)==='partial','partial earlier batch was unnecessarily rejected');
        must10(!isset($out['ctx']['registry']['h1_'.$field.'_21']),'invalid field became a numeric source');
        must10(isset($out['ctx']['registry']['h2_win_odds_21']),'valid other horse lost');
        must10(P::buildInput($out['ctx'],'1st')['ok'],'valid six-minute batch no longer supports input');
    });
}
foreach(['fuku_min'=>'place_min','fuku_max'=>'place_max'] as $source=>$field) check10('missing six-minute place odds do not erase valid wins: '.$source,function() use($source,$field) {
    $rows=rows10(); $rows[0][$source]=(object)['value'=>'1.2'];
    $out=gather10([batch10(ExactJson::encode($rows))]);
    must10($out['ok'] && ($out['ctx']['audit'][6]['batch_id']??null)==='a-first','optional place field rejected complete win batch');
    must10(!isset($out['ctx']['registry']['h1_'.$field.'_6']),'object became numeric place odds');
    must10(P::buildInput($out['ctx'],'1st')['ok'],'valid win input lost');
});
foreach(['取消','除外'] as $marker) check10('valid cancellation remains local to adopted batch: '.$marker,function() use($marker) {
    $rows=rows10(); $rows[1]['tan']=' '.$marker.' '; $rows[1]['fuku_min']=null; $rows[1]['fuku_max']=null;
    $out=gather10([batch10(ExactJson::encode($rows))]);
    must10($out['ok'] && $out['ctx']['cancelled']===[2],'valid cancellation lost');
    must10(!isset($out['ctx']['registry']['h2_win_odds_6']),'cancelled horse received odds');
});
check10('all malformed six-minute candidates stop input even with earlier data',function() {
    $bad='[{"num":1,"tan":"2.1","tan":"9.9"},{"num":2,"tan":"3.2"}]';
    $out=gather10([batch10($bad),batch10(ExactJson::encode(rows10()),'earlier',21)]);
    if($out['ok']) must10(!P::buildInput($out['ctx'],'1st')['ok'],'malformed freeze batch produced sendable input');
});
foreach(['hash mismatch','uncommitted','late'] as $kind) check10('raw parser does not weaken acquisition gates: '.$kind,function() use($kind) {
    $rows=rows10(); $rows[1]['tan']='取消'; $bad=batch10(ExactJson::encode($rows));
    if($kind==='hash mismatch') $bad['payload_hash']=str_repeat('0',64);
    if($kind==='uncommitted') $bad['committed']=0;
    if($kind==='late') $bad['available_at']='2026-10-24 12:00:00';
    $out=gather10([$bad,batch10(ExactJson::encode(rows10()),'z-backup')]);
    must10($out['ok'] && ($out['ctx']['audit'][6]['batch_id']??null)==='z-backup' && $out['ctx']['cancelled']===[],'old gate or cancellation isolation regressed');
});
foreach(['unknown horse'=>['num'=>3,'tan'=>'4.5'],'duplicate horse'=>['num'=>1,'tan'=>'4.5'],'duplicate padded horse'=>['num'=>'01','tan'=>'4.5'],'cancel conflict'=>['num'=>1,'tan'=>'取消']] as $label=>$extra) check10('existing raw set integrity remains enforced: '.$label,function() use($extra) {
    $rows=rows10(); $rows[]=$extra; backup10(ExactJson::encode($rows));
});

// ExactJson's common defensive limits apply at the raw acquisition boundary too.
foreach(['[]','{}','null','true','42','"text"','[','[NaN]','[Infinity]','[] trailing'] as $raw) check10('empty or malformed root cannot qualify six-minute input: '.$raw,function() use($raw) { backup10($raw); });
foreach([-1,0,1] as $delta) check10('raw payload byte boundary: '.$delta,function() use($delta) {
    $rows=rows10(); $rows[0]['note']=''; $base=ExactJson::encode($rows);
    $rows[0]['note']=str_repeat('x',4194304-strlen($base)+$delta); $raw=ExactJson::encode($rows);
    must10(strlen($raw)===4194304+$delta,'test payload size incorrect');
    $out=gather10([batch10($raw),batch10(ExactJson::encode(rows10()),'z-backup')]);
    must10($out['ok'] && ($out['ctx']['audit'][6]['batch_id']??null)===($delta<=0?'a-first':'z-backup'),'raw byte bound incorrect');
});
foreach([62,63] as $nested) check10('raw payload depth boundary: '.($nested+2),function() use($nested) {
    $raw='[{"num":1,"tan":"2.1","meta":'.str_repeat('[',$nested).'0'.str_repeat(']',$nested).'},{"num":2,"tan":"3.2"}]';
    $out=gather10([batch10($raw),batch10(ExactJson::encode(rows10()),'z-backup')]);
    must10($out['ok'] && ($out['ctx']['audit'][6]['batch_id']??null)===($nested===62?'a-first':'z-backup'),'raw depth bound incorrect');
});
foreach(['129 digits'=>str_repeat('9',129),'positive exponent'=>'1e309','negative exponent'=>'1e-309','invalid UTF-8'=>"\"\xff\""] as $label=>$token) check10('invalid raw token excludes batch: '.$label,function() use($token) {
    backup10('[{"num":1,"tan":"2.1","meta":'.$token.'},{"num":2,"tan":"3.2"}]');
});
foreach(['null'=>null,'true'=>true,'array'=>[],'object'=>new stdClass(),'nonfinite'=>INF,'nan'=>NAN,'stringable'=>new class { public function __toString(): string { return '2.1'; }}] as $label=>$value) check10('numeric predicate safely rejects unsupported PHP value: '.$label,function() use($value) {
    must10(!A::usableDecimal($value),'unsupported PHP value accepted');
});
foreach(['integer'=>2,'float'=>2.5,'string'=>' 2.10 ','decimal'=>new Decimal('2.00000000000000000001')] as $label=>$value) check10('numeric predicate keeps supported PHP values: '.$label,function() use($value) {
    must10(A::usableDecimal($value),'supported decimal rejected');
});
echo "RESULT: $passed passed, $failed failed\n";
exit($failed?1:0);

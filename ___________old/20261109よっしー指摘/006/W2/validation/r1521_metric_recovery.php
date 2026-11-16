<?php
declare(strict_types=1);
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\RaceMetricsW2 as Metrics;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
require $argv[1];require __DIR__.'/../php/ExpectedBridgeW2.php';require __DIR__.'/r4_fixtures.php';
function r3bytes(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
function r3frame(array $value):string {return "<<<BAGANRIKI_V041_R8_BEGIN>>>\n".r3bytes($value)."\n<<<BAGANRIKI_V041_R8_END>>>";}
$dir=$argv[2];$raw=file_get_contents($dir.'/1st_input.json');$in=json_decode($raw,true);$common=Codec::commonHash($in);
$proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true);
$f=Frozen::fromStored($common['json'],$raw,$common['hash'],'1st',$proof);
$original=json_decode(file_get_contents($dir.'/1st_output.json'),true);$values=['wave_level'=>3,'lower_entry'=>2,'big_gap_entry'=>2];$count=0;
$cases=[];
foreach (Metrics::KEYS as $key) {
    foreach (Metrics::KEYS as $remaining) if ($key!==$remaining) $cases[]=['partial',$key,$remaining];
    $cases[]=['object_refs',$key,null];$cases[]=['repair_object',$key,$key==='wave_level'?'lower_entry':'wave_level'];
}
$cases[]=['changed_valid','wave_level','lower_entry'];
foreach ($cases as [$kind,$key,$remaining]) {
    $first=$original;$fix=null;$expected=$values;
    if ($kind==='object_refs' || $kind==='repair_object') {
        $first['race_metrics'][$key]['source_refs']=(object)$first['race_metrics'][$key]['source_refs'];$expected[$key]=null;
    } else {$first['race_metrics'][$key]['value']=0;$expected[$key]=null;}
    if ($remaining!==null) {$first['race_metrics'][$remaining]['value']=0;$expected[$remaining]=null;}
    $receipts=[r4receipt($f,r3frame($first),0,false)];
    if ($kind!=='object_refs') {
        $fix=$original;$fix['race_metrics'][$remaining]['value']=0;
        if ($kind==='changed_valid') $fix['race_metrics']['big_gap_entry']['value']=1;
        else $expected[$key]=$values[$key];
        $row=r4receipt($f,r3frame($fix),1,false);$row['repair_scope']=r5scope($receipts[0]);$receipts[]=$row;
    }
    $record=r4seal(r4recoveryDoc($f,$original,$receipts[0]),$receipts,$f);
    $out=r9merge($f,['1st'=>$record],$in['quota'],array_column($in['runners'],'horse_no'),[],r4live($f));
    // This path supplies only 1st. Horse 10 belongs to the absent 2nd's independent evidence.
    if ($out['race_metrics']['values']!==$expected || $out['public_candidates']!==[16,15,14,5] || array_sum($out['agreement_bonus'])!==0)
        throw new RuntimeException(json_encode([$kind,$key,$expected,$out],JSON_UNESCAPED_UNICODE));
    $hash=($fix!==null && $kind!=='changed_valid')?$receipts[1]['response_hash']:$receipts[0]['response_hash'];
    if ($out['race_metrics']['response_hash']!==$hash) throw new RuntimeException('wrong metric origin');
    $display=Metrics::publicValues($in,$out['race_metrics']);
    if (array_values($display)!==array_values($expected)) throw new RuntimeException('public values mismatch');
    ++$count;
}
echo "PASS $count R15.21 stored-receipt recovery, exact JSON type, scope and response-hash checks\n";

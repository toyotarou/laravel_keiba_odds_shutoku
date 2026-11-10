<?php
declare(strict_types=1);
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\MarketStateW2 as Market;
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
(function(array $base,array $current,array $history) {
    global $passed;$start=$passed;
    $a=Bridge::attach($base,$current,$history);$proof=$a['audit']['expected_proof'];
    $ledger=json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR);
    check($ledger['market_source_sha256']===Market::verify($a['common']) && !isset($a['common']['expected_v01']['market_source_sha256']),'R8 attach seals six acquisition slots outside AI input');
    $ref=$ledger['market_source_sha256'];
    foreach ([21,18,15,12,9,6] as $slot) {
        foreach (['payload_hash','source_updated_at','batch_id'] as $key) {
            $s=$a['common'];$v=&$s['collection_audit'][$slot][$key];
            $v= $key==='payload_hash'?str_repeat('9',64):($key==='batch_id'?'other-batch':$s['collection_audit'][$slot]['request_started_at']);unset($v);
            check($ref!==Market::verify($s),'R8 adopted market identity changes '.$slot.' '.$key);
        }
        $x=$a['common'];$key=$x['input_indices']['horse/2/place_max/'.$slot][0];
        $x['source_registry'][$key]['value']+=1;check($ref!==Market::verify($x),'R8 each place maximum contributes '.$slot);
        $x=$a['common'];$x['source_registry'][$key]['value']=null;$empty=Market::verify($x);unset($x['source_registry'][$key]);unset($x['input_indices']['horse/2/place_max/'.$slot]);
        check($ref!==$empty && $empty!==Market::verify($x),'R8 distinguish missing source, observed null and actual price '.$slot);
        $x=$a['common'];$x['collection_audit'][$slot]['request_started_at']=Packet::utc(Packet::instant($x['collection_audit'][$slot]['request_started_at']));
        check(Market::verify($x)===$ref,'R8 market identity neutral to equivalent timezone '.$slot);
    }
    $s=$a['common'];$s['runners']=array_reverse($s['runners']);$s['source_registry']=array_reverse($s['source_registry'],true);
    $s['input_indices']=array_reverse($s['input_indices'],true);$s['collection_audit']=array_reverse($s['collection_audit'],true);
    check($ref===Market::verify($s),'R8 market witness invariant to retrieval order');
    $s=$a['common'];$s['source_registry']['h1_past_performance_None']['value']=null;
    check($ref===Market::verify($s),'R8 market projection independent of unrelated historical feature');
    $s=$a['common'];$id=$s['input_indices']['horse/2/place_max/9'][0];$s['source_registry'][$id]['slot_offset']=null;
    unset($s['input_indices']['horse/2/place_max/9']);reject(fn()=>Market::verify($s),'R8 unindexed market price cannot evade acquisition slot');
    $s=$a['common'];$q=$s['source_registry'][$id]['value'];$s['source_registry'][$id]['value']=(string)$q.'000';
    check($ref===Market::verify($s),'R8 exact equivalent quote notation');
    echo 'R8 regression checks: '.($passed-$start)."\n";
})($base,$c,$history);

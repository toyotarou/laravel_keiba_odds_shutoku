<?php
declare(strict_types=1);
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
(function(array $base,array $current,array $hist) {
    global $passed;$start=$passed;
    $verify=static function(array $places,?string $reason,string $name)use($current,$hist) {
        $r=$hist[0];foreach ($r['results'] as $i=>&$line)$line['finish']=$places[$i];unset($line);
        $reference=$hist[1];$got=Packet::build($current,[$reference,$r]);
        check($reason===null?($got['packet']['rows'][0]['M']===2 && !$got['audit']['excluded']):
            ($got['packet']['rows'][0]['M']===1 && ($got['audit']['excluded'][$reason]??0)===1),$name);
    };
    $verify(range(1,16),null,'R6 official distinct places');
    $verify([1,2,2,4,5,5,5,8,9,10,11,12,13,14,'DQ','DNF'],null,'R6 two and three way dead heats and nonfinishers');
    $verify([1,1,3,4,...range(5,16)],null,'R6 joint first then third place');
    $verify(array_fill(0,16,1),null,'R6 competition ranking has no arbitrary cap on a dead heat');
    $verify(array_merge([1],array_fill(0,15,'DNF')),null,'R6 one classified finisher');
    $verify([1,2,2,3,...range(5,16)],'HIST_FINISH_SEQUENCE','R6 contradictory third place after a second-place dead heat excluded');
    $verify([1,1,2,4,...range(5,16)],'HIST_FINISH_SEQUENCE','R6 contradictory second place after joint first excluded');
    $verify(array_merge(range(2,16),['DNF']),'HIST_FINISH_SEQUENCE','R6 winner missing from official result held');
    $verify(array_merge([1,3,4],range(5,16),['DQ']),'HIST_FINISH_SEQUENCE','R6 unexplained official position gap held');
    $verify(array_fill(0,16,'DQ'),'HIST_NO_CLASSIFIED_FINISH','R6 all unclassified do not contribute sixteen losses');
    $verify(array_merge(range(1,15),['UNKNOWN']),'UNKNOWN_RESULT','R6 unresolved outcome still excluded');
    $a=Bridge::attach($base,$current,$hist);$proof=$a['audit']['expected_proof'];
    check(json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR)===($a['common']['expected_v01']+['odds_binding_sha256'=>Packet::bindingForFrozen($a['common']),'market_source_sha256'=>\App\Services\Baganriki\MarketStateW2::verify($a['common'])]) && hash('sha256',$proof['json'])===$proof['sha256'],'R6 calculator emits separate exact original');
    check(!array_key_exists('expected_proof',$a['common']) && !array_key_exists('expected_proof',$a['common']['expected_v01']),'R6 internal proof stays out of the AI request');
    $rev=$current;$rev['roster']=array_reverse($rev['roster']);$rev['batches'][21]['quotes']=array_reverse($rev['batches'][21]['quotes']);
    check(Bridge::attach($base,$rev,$hist)['audit']['expected_proof']===$proof,'R6 database row order cannot detach the proof from its horse');
    $bad=$current;$bad['roster']='unusable';rejectsWith(fn()=>Bridge::attach($base,$bad,$hist),'ROSTER_BINDING','R6 typed roster rejection before array_column');
    $precision=ini_get('serialize_precision');
    try { ini_set('serialize_precision','3');rejectsWith(fn()=>Packet::build($current,$hist),'W6_E_ENCODE_PRECISION','R6 low PHP precision cannot certify rounded calculator results'); }
    finally { ini_set('serialize_precision',(string)$precision); }
    echo 'R6 regression checks: '.($passed-$start)."\n";
})($base,$c,$history);

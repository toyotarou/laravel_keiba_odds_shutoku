<?php
declare(strict_types=1);
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;

(function(array $base,array $current,array $history) {
    global $passed;$start=$passed;
    $r=$history[0];$other=$history[1];$one=Packet::build($current,[$r]);$two=Packet::build($current,[$r,$other]);
    foreach ([['post_at',null],['post_at','bad'],['post_at',$current['post_at']],['post_at',moveTime($current['post_at'],750)],
        ['jurisdiction',null],['jurisdiction','OTHER'],['race_type','JUMP'],['post_freeze_scratch',true],['N',0],['results',null]] as [$key,$value]) {
        $correction=$r;$correction[$key]=$value;$correction['result_available_at']=(new DateTimeImmutable($r['result_available_at']))->modify('+1 minute')->format(DATE_ATOM);
        $a=Packet::build($current,[$r,$correction,$other]);$b=Packet::build($current,[$other,$correction,$r]);
        check($a['packet']===$b['packet'] && $a['audit']['pool_ids']===[$other['race_id']] && $a['audit']['excluded']===['DUPLICATE_HISTORY_ID'=>2],
            'R7 published correction cannot disappear before duplicate check '.$key);
    }
    $midnight=(new DateTimeImmutable($current['post_at']))->setTime(0,0);
    foreach ([null,'bad',$midnight->format(DATE_ATOM),$midnight->modify('+1 microsecond')->format('Y-m-d\TH:i:s.uP')] as $stamp) {
        $bad=$r;$bad['post_at']=null;$bad['result_available_at']=$stamp;
        check(Packet::build($current,[$r,$bad])['packet']===$one['packet'],'R7 a version not demonstrably visible by midnight cannot displace known history');
    }
    $bad=$r;$bad['result_available_at']=$midnight->modify('-1 microsecond')->format('Y-m-d\TH:i:s.uP');$bad['post_at']=null;
    check(Packet::build($current,[$r,$bad])['packet']['rows'][0]['M']===0,'R7 conflicting revision just before cutoff quarantines both');
    $no=2;$missing=$r;$missing['batches'][21]['quotes']=array_values(array_filter($missing['batches'][21]['quotes'],static fn($q)=>$q['horse_id']!=='H2'));
    $expected=Packet::build($current,[$missing,$other]);$conflict=$r;$conflict['batches'][21]['quotes'][]=['horse_id'=>'H2','win'=>99];
    foreach ([false,true] as $reverse) {
        if ($reverse) { $conflict['batches'][21]['quotes']=array_reverse($conflict['batches'][21]['quotes']);$conflict['batches'][21]['quotes'][]=['horse_id'=>'H2','win'=>200]; }
        $actual=Packet::build($current,[$other,$conflict]);$diag=$actual['audit']['partial_history'];
        check($actual['packet']===$expected['packet'] && $diag===[['race_id'=>$r['race_id'],'unavailable_odds21_horse_nos'=>[2],'ambiguous_odds21_horse_nos'=>[2],'odds21_batch_issue'=>null]],'R7 no occurrence of an ambiguous early quote can win by row order');
        foreach ($actual['packet']['rows'] as $row)check($row['M']===2 && $row['K']===($row['horse_no']===$no?1:2),'R7 unaffected horse keeps identified early quote '.$row['horse_no']);
    }
    $ghost=$conflict;$ghost['batches'][21]['quotes'][]=['horse_id'=>'unknown','win'=>3];
    $x=Packet::build($current,[$ghost]);check(count($x['audit']['partial_history'][0]['unavailable_odds21_horse_nos'])===16 &&
        $x['audit']['partial_history'][0]['odds21_batch_issue']==='UNKNOWN_QUOTE_OWNER' && !array_filter(array_column($x['packet']['rows'],'K')),'R7 unknown extra owner holds entire early batch, audited once');
    $bad=$r;$bad['batches'][6]['quotes'][]=$bad['batches'][6]['quotes'][0];$x=Packet::build($current,[$bad,$other]);
    check($x['audit']['pool_ids']===[$other['race_id']] && $x['audit']['excluded']['HIST_ROSTER_OR_SCRATCH']===1,'R7 ambiguous 6-minute odds cannot enter the baseline');
    $bad=$current;$bad['batches'][21]['quotes'][]=$bad['batches'][21]['quotes'][0];rejectsWith(fn()=>Bridge::attach($base,$bad,$history),'QUOTE_OWNER','R7 current input still requires the official adopted batch');
    $late=$r;$late['batches'][21]['received_at']=$late['post_at'];$x=Packet::build($current,[$late]);
    check($x['packet']['rows'][0]['M']===1 && $x['packet']['rows'][0]['K']===0 && $x['audit']['partial_history'][0]['odds21_batch_issue']==='OUTSIDE_BATCH_WINDOW','R7 late early batch stays out, fully classified baseline stays in');
    $calc=Bridge::attach($base,$current,$history);$proof=$calc['audit']['expected_proof'];$w=json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR);
    check($w['odds_binding_sha256']===Packet::bindingForFrozen($calc['common']) && !isset($calc['common']['expected_v01']['odds_binding_sha256']),'R7 calculator and frozen inputs independently commit the same actual market outside AI wire');
    $hundred=array_slice($history,0,100);$prior=Packet::build($current,$hundred);$invalid=$hundred[0];unset($invalid['post_at']);
    $rewound=Packet::build($current,[...$hundred,$invalid]);$after=$invalid;$after['result_available_at']=$midnight->format(DATE_ATOM);
    check($prior['packet']['rows'][0]['status']==='COMPARISON_READY' && $rewound['packet']['rows'][0]['M']===99 &&
        $rewound['packet']['rows'][0]['status']==='LOW_SUPPORT','R7 visible conflict can correctly disable comparison at the 100-race boundary');
    check(Packet::build($current,[...$hundred,$after])['packet']===$prior['packet'],'R7 a midnight revision cannot retroactively remove competition readiness');
    echo 'R7 regression checks: '.($passed-$start)."\n";
})($base,$c,$history);

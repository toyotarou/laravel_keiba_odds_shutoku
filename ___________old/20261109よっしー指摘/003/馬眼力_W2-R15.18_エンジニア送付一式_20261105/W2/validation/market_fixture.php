<?php
declare(strict_types=1);
/** Deterministic pre-race markets for testing alternate modes; change the evidence as well as its label. */
function marketFixture(array $in,string $scenario):array
{
    $modes=['FIRM'=>['FIRM',0,1,null],'MIXED'=>['MIXED',2,3,3],'VOLATILE'=>['VOLATILE',4,5,4],
        'PROV_MIXED'=>['MIXED',1,2,null],'PROV_VOLATILE'=>['VOLATILE',3,4,null],'OFF'=>[null,1,4,null]];
    [$mode,$lo,$hi,$stars]=$modes[$scenario];$n=count($in['runners']);
    $q=$mode==='FIRM'?['A'=>5,'B'=>2,'D'=>0]:['A'=>3,'B'=>3,'D'=>1];
    $in['quota']=$in['base_quota']=$mode===null?null:$q;$in['quota_adjustments']=[];
    $in['role_quota']=$in['quota']; if ($mode!==null && $in['ai_role']==='2nd') $in['role_quota']['A']=5-$q['B']-$q['D'];
    $in['role_cap']=array_sum($in['role_quota']??[]);
    $prov=in_array($scenario,['PROV_MIXED','PROV_VOLATILE'],true);
    $in['mode']=$prov || $mode===null?'UNKNOWN':$mode;
    $in['allocation_mode']=$mode; $in['mode_status']=$mode===null?'UNRESOLVED':($prov?'PROVISIONAL':'CONFIRMED');
    $in['mode_policy_id']=$prov?'X6_ONLY_UPPER_MODE_V1':'BASE_TRI_STATE_V1';$in['selection_enabled']=$mode!==null;
    $in['S']=null;$in['S_min']=$lo;$in['S_max']=$hi;$in['display_rating']=$stars;
    $gap=in_array($scenario,['VOLATILE','PROV_VOLATILE'],true);$flow=in_array($scenario,['MIXED','VOLATILE'],true);
    $in['signals']=['X1'=>$mode===null?null:$gap,'X2'=>false,'X3'=>$scenario!=='FIRM','X4'=>$flow,'X5'=>false,'X6'=>null,'Z'=>false];
    $map=array_column($in['expected_v01']['rows'],null,'horse_no');
    foreach ($in['runners'] as $h) {
        $no=$h['horse_no'];$rank=$h['win_rank'];
        foreach ([21,18,15,12,9,6] as $slot) foreach (['win_odds','place_min','place_max'] as $f) {
            $key="horse/$no/$f/$slot";$ref="h{$no}_{$f}_{$slot}";
            if (!isset($in['source_registry'][$ref])) {
                $src=$in['source_registry']["h16_{$f}_{$slot}"];$src['source_ref']=$ref;$src['horse_nos']=[$no];
                $src['dependency_key']="h{$no}_{$f}";$in['source_registry'][$ref]=$src;$in['input_indices'][$key]=[$ref];
            }
            $win=10+$rank+($scenario==='FIRM' && $rank>=4?8:0);
            if ($slot===21 && $gap && $rank===2) $win=33;
            if ($slot===21 && $mode===null && $rank===2) $win=null;
            $place=$flow && $slot===21 && $rank>=7?3.3:3;
            $in['source_registry'][$ref]['value']=$f==='win_odds'?$win:($f==='place_min'?$place:4);
        }
        if (!isset($map[$no])) {$row=end($map);$row['horse_no']=$no;$row['formula_rank']=$rank;$map[$no]=$row;}
        if ($mode===null && $rank===2) {
            $row=&$map[$no];$row['status']='UNAVAILABLE_INPUT';$row['basis']='UNAVAILABLE';$row['reason']='MISSING_ODDS21';
            foreach (['E3','E5','p0_3','p0_5','p3_raw','p5_raw','K','M','W','n_eff'] as $k)$row[$k]=null;unset($row);
        }
    }
    $in['expected_v01']['rows']=array_values($map);
    return $in;
}

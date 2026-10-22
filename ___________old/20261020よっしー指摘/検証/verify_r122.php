<?php
/**
 * 馬眼力 r12.2 ─ 本番コードから抜き出した判定を、
 * 先方が同梱した期待値と突き合わせます（harness_r122.php は本番からの抜粋）。
 */
require __DIR__.'/harness_r122.php';
$V='/mnt/user-data/uploads/KEIBA/20261020よっしー指摘/001/馬眼力_エンジニア送付資料_20261019/validation';
$fail=0;

// ① mode_cases.json 729件
$d=json_decode(file_get_contents($V.'/mode_cases.json'),true);
$keys=['mode','mode_status','allocation_mode','mode_policy_id','selection_enabled','S','S_min','S_max','display_rating'];
$ng=0;$shown=0;
foreach($d['cases'] as $c){
    $r=H::r122Decide($c['signals']);
    foreach($keys as $k){
        if(!array_key_exists($k,$c['expected'])) continue;
        if($c['expected'][$k]!==$r[$k]){
            $ng++;
            if($shown++<5) printf("    %s %s 期待=%s 実際=%s %s\n",$c['id'],$k,
                var_export($c['expected'][$k],true),var_export($r[$k],true),json_encode($c['signals']));
            break;
        }
    }
}
printf("① mode_cases.json              : %4d件 / 不一致 %d件 → %s\n",count($d['cases']),$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ② acquisition_boundary_cases.json 4件
$d=json_decode(file_get_contents($V.'/acquisition_boundary_cases.json'),true);
$ng=0;
foreach($d['cases'] as $c){
    $frz=H::r122Us($c['input_freeze_at']); $anchor=$frz+330*1000000;
    $su=H::r122SlotScheduledUs($anchor,(int)$c['slot']);
    $sch=gmdate('Y-m-d\TH:i:s',intdiv($su,1000000)).sprintf('.%06d',$su%1000000).'+00:00';
    $r=H::r122AcceptBatch((int)$c['slot'],[
        'slot_scheduled_at'=>$sch,'request_started_at'=>$c['request_started_at'],
        'response_received_at'=>$c['response_received_at'],'available_at'=>$c['available_at'],
        'input_freeze_at'=>$c['input_freeze_at']]);
    $got=$r['ok']?'PASS':'FAIL';
    if($got!==$c['expected']){$ng++; printf("    %s 期待=%s 実際=%s %s\n",$c['id'],$c['expected'],$got,$r['reason']??'');}
}
printf("② acquisition_boundary_cases   : %4d件 / 不一致 %d件 → %s\n",count($d['cases']),$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ③ 変更計画書 付録A の33レース配分
$rows=json_decode(file_get_contents(__DIR__.'/appendixA.json'),true);
$L=['FIRM'=>'F','MIXED'=>'M','VOLATILE'=>'V']; $ng=0;
foreach($rows as $r){
    $n=(int)$r['N']; $s0=(int)$r['S0'];
    $use=($s0===1)?'MIXED':'VOLATILE'; $cmp=($s0===1)?'FIRM':'MIXED';
    $qc=H::r122StructuralQuota($n,$cmp)['quota'];
    $qu=H::r122StructuralQuota($n,$use)['quota'];
    $q2=H::r122SecondQuota($n,$qu);
    $g=[$L[$cmp].' '.H::r122Fmt($qc), $L[$use].' '.H::r122Fmt($qu), H::r122Fmt($q2)];
    $e=[$r['cmp1st'],$r['use1st'],$r['use2nd']];
    if($g!==$e){$ng++; printf("    %s 期待=%s 実際=%s\n",$r['race'],implode(' | ',$e),implode(' | ',$g));}
}
printf("③ 変更計画書 付録A の33レース配分: %4d件 / 不一致 %d件 → %s\n",count($rows),$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ④ 暫定規則の整合（X6だけ不明のとき S0=1→MIXED、S0=3→VOLATILE。それ以外は暫定にしない）
$ng=0;$cnt=0;
foreach([true,false,null] as $x1)foreach([true,false,null] as $x2)foreach([true,false,null] as $x3)
foreach([true,false,null] as $x4)foreach([true,false,null] as $x5)foreach([true,false,null] as $z){
    $r=H::r122Decide(['X1'=>$x1,'X2'=>$x2,'X3'=>$x3,'X4'=>$x4,'X5'=>$x5,'Z'=>$z,'X6'=>null]);
    $cnt++;
    $others=($x1!==null&&$x2!==null&&$x3!==null&&$x4!==null&&$x5!==null&&$z!==null);
    if($r['mode_status']==='PROVISIONAL'){
        if(!$others || !in_array($r['S0'],[1,3],true) ) $ng++;
        if($r['mode']!=='UNKNOWN' || $r['display_rating']!==null) $ng++;
        if($r['mode_policy_id']!=='X6_ONLY_UPPER_MODE_V1') $ng++;
        if($r['allocation_mode']!==($r['S0']===1?'MIXED':'VOLATILE')) $ng++;
    }
    if($r['mode_status']==='UNRESOLVED'){
        if($r['allocation_mode']!==null||$r['selection_enabled']!==false) $ng++;
    }
}
printf("④ 暫定例外がX6だけに閉じているか : %4d通り / 違反 %d件 → %s\n",$cnt,$ng,$ng?'失敗':'合格');
if($ng)$fail++;

echo "\n", $fail===0 ? "失敗した試験: 0件\n" : "失敗した試験: {$fail}件\n";

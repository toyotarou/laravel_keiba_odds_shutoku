<?php
/**
 * AiController.php から抜き出した r12.2 の判定を、
 * 先方が同梱した期待値（validation）と変更計画書 付録A に突き合わせます。
 */
require __DIR__.'/harness_ai.php';
$V='/mnt/user-data/uploads/KEIBA/20261020よっしー指摘/001/馬眼力_エンジニア送付資料_20261019/validation';
$tri=static fn($v)=>($v===true?'TRUE':($v===false?'FALSE':'UNKNOWN'));
$fail=0;

// ① mode_cases.json 729件
$d=json_decode(file_get_contents($V.'/mode_cases.json'),true);
$map=['mode'=>'mode','mode_status'=>'mode_status','allocation_mode'=>'allocation_mode',
      'mode_policy_id'=>'mode_policy_id','selection_enabled'=>'selection_enabled',
      'S'=>'S','S_min'=>'S_min','S_max'=>'S_max','display_rating'=>'display_rating'];
$ng=0;$shown=0;
foreach($d['cases'] as $c){
    $flags=[];
    foreach(['X1','X2','X3','X4','X5','X6','Z'] as $k) $flags[$k]=$tri($c['signals'][$k]??null);
    $r=AC::v04Classify($flags);
    foreach($map as $e=>$a){
        if(!array_key_exists($e,$c['expected'])) continue;
        if($c['expected'][$e]!==$r[$a]){
            $ng++;
            if($shown++<5) printf("    %s %s 期待=%s 実際=%s %s\n",$c['id'],$e,
                var_export($c['expected'][$e],true),var_export($r[$a],true),json_encode($c['signals']));
            break;
        }
    }
}
printf("① mode_cases.json（AiController）  : %4d件 / 不一致 %d件 → %s\n",count($d['cases']),$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ② 付録A の33レース配分
$rows=json_decode(file_get_contents(__DIR__.'/appendixA.json'),true);
$L=['FIRM'=>'F','MIXED'=>'M','VOLATILE'=>'V'];
$fmt=static fn($q)=>$q===null?'—':sprintf('%d/%d/%d',$q['A'],$q['B'],$q['D']);
$ng=0;
foreach($rows as $r){
    $n=(int)$r['N']; $s0=(int)$r['S0'];
    $use=($s0===1)?'MIXED':'VOLATILE'; $cmp=($s0===1)?'FIRM':'MIXED';
    $au=AC::v04Allocation($n,['allocation_mode'=>$use]);
    $ac=AC::v04Allocation($n,['allocation_mode'=>$cmp]);
    $g=[$L[$cmp].' '.$fmt($ac['quota']), $L[$use].' '.$fmt($au['quota']), $fmt($au['quota_2nd'])];
    $e=[$r['cmp1st'],$r['use1st'],$r['use2nd']];
    if($g!==$e){$ng++; printf("    %s 期待=%s 実際=%s\n",$r['race'],implode(' | ',$e),implode(' | ',$g));}
}
printf("② 変更計画書 付録A の33レース配分   : %4d件 / 不一致 %d件 → %s\n",count($rows),$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ③ UNRESOLVED では配分を作らないこと
$ng=0;
$r=AC::v04Classify(['X1'=>'UNKNOWN','X2'=>'TRUE','X3'=>'FALSE','X4'=>'FALSE','X5'=>'FALSE','X6'=>'FALSE','Z'=>'FALSE']);
if($r['mode_status']!=='UNRESOLVED'||$r['allocation_mode']!==null||$r['selection_enabled']!==false) $ng++;
$a=AC::v04Allocation(16,$r);
if($a['quota']!==null||$a['quota_2nd']!==null) $ng++;
printf("③ UNRESOLVEDで配分を作らない        : 違反 %d件 → %s\n",$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ④ C7混戦が 3/3/1、2ndが 1/3/1 になること（r12.2でC7 MIXEDが変わった）
$ng=0;
$a=AC::v04Allocation(16,['allocation_mode'=>'MIXED']);
if($fmt($a['quota'])!=='3/3/1') { $ng++; echo "    C7 MIXED 1st=".$fmt($a['quota'])."\n"; }
if($fmt($a['quota_2nd'])!=='1/3/1'){ $ng++; echo "    C7 MIXED 2nd=".$fmt($a['quota_2nd'])."\n"; }
printf("④ C7混戦 3/3/1・2nd 1/3/1           : 違反 %d件 → %s\n",$ng,$ng?'失敗':'合格');
if($ng)$fail++;

// ⑤ 構造調整（N=10 VOLATILE → 2/3/0。D帯がいないので1枠をBへ）
$ng=0;
$a=AC::v04Allocation(10,['allocation_mode'=>'VOLATILE']);
if($fmt($a['quota'])!=='2/3/0'){ $ng++; echo "    N=10 VOLATILE=".$fmt($a['quota'])."\n"; }
$mv=$a['quota_adjustments'];
if(count($mv)!==1||$mv[0]['requested_band']!=='D'||$mv[0]['actual_band']!=='B'||$mv[0]['reason']!=='BAND_CAPACITY'){
    $ng++; echo "    移動記録=".json_encode($mv,JSON_UNESCAPED_UNICODE)."\n";
}
printf("⑤ 構造調整と移動記録                : 違反 %d件 → %s\n",$ng,$ng?'失敗':'合格');
if($ng)$fail++;

echo "\n", $fail===0 ? "失敗した試験: 0件\n" : "失敗した試験: {$fail}件\n";

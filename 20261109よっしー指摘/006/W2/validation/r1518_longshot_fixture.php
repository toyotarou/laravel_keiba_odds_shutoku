<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
$w=dirname(__DIR__);
require_once $w.'/php/ExpectedBridgeW2.php';
require_once $w.'/validation/market_fixture.php';
require_once $w.'/validation/r1519_fixture.php';
[$file,$dir,$market,$finder,$pattern]=$argv;
function num(int $rank):int { return 17-$rank; }
function refText(string $s):string {
    $s=preg_replace_callback('/^h(\d+)_/',static fn($m)=>'h'.num((int)$m[1]).'_', $s);
    return preg_replace_callback('#^horse/(\d+)/#',static fn($m)=>'horse/'.num((int)$m[1]).'/', $s);
}
function remap(mixed $input):mixed {
    if (is_string($input)) return refText($input);
    if (!is_array($input)) return $input;
    $out=[];
    foreach ($input as $k=>$v) {
        if ($k==='horse_no') $v=num($v);
        elseif ($k==='horse_nos'||$k==='applicable_horse_nos') $v=array_map('num',$v);
        else $v=remap($v);
        $out[is_string($k)?refText($k):$k]=$v;
    }
    return $out;
}
function wire(array $a):string {return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
$f=json_decode(gzdecode(file_get_contents($w.'/validation/synthetic_base.json.gz')),true,512,JSON_THROW_ON_ERROR);
$base=$f['input'];
$base['schema_version']=Selection::SCHEMA;
$base['prompt_version']=Selection::VERSION;
$base['expected_v01']=['rows'=>array_map(static fn($r)=>['horse_no'=>$r['horse_no']],$base['runners'])];
$base=marketFixture($base,$market);
$base['race_id']=$argv[5]??'20261017-4-05-07-11';
foreach ($base['source_registry'] as &$node) $node['race_id']=$base['race_id'];
unset($node);
$base=remap($base);
usort($base['runners'],static fn($a,$b)=>$a['horse_no']<=>$b['horse_no']);
foreach (['snapshot_hash','ai_role','role_cap','role_quota','expected_v01'] as $key) unset($base[$key]);
$cur=['race_id'=>$base['race_id'],'N'=>$base['N'],'post_at'=>$base['time_contract']['anchor_at'],'input_freeze_at'=>$base['input_freeze_at'],'surface'=>'DIRT','race_type'=>'FLAT','jurisdiction'=>'JRA','distance_metres'=>1800,'class_group'=>'C1','roster'=>[],'batches'=>[]];
foreach ($base['runners'] as $r) $cur['roster'][]=['horse_no'=>$r['horse_no'],'horse_id'=>'entry-'.$r['horse_no']];
foreach ([6,21] as $slot) {
    $a=$base['collection_audit'][$slot];
    $batch=['batch_id'=>$a['batch_id'],'requested_at'=>$a['request_started_at'],'received_at'=>$a['response_received_at'],'available_at'=>$a['available_at'],'quotes'=>[]];
    foreach (array_reverse($cur['roster']) as $r) {
        $ref=$base['input_indices']['horse/'.$r['horse_no'].'/win_odds/'.$slot][0];
        $batch['quotes'][]=['horse_id'=>$r['horse_id'],'win'=>$base['source_registry'][$ref]['value']];
    }
    $cur['batches'][$slot]=$batch;
}
$base['prompt_text_revision']=getenv('W2_TEST_TEXT_REVISION')?:'COMPACT_TEXT_20261109_03';
$x=Bridge::attach($base,$cur,null);
$common=$x['common'];
$hash=hash('sha256',Frozen::nativeJson($common));
file_put_contents($dir.'/expected_proof.json',wire($x['audit']['expected_proof']));
foreach (['1st','2nd'] as $role) {
    $in=$common;
    $q=$common['quota'];
    if ($role==='2nd' && array_sum($q)>5) $q['A']=5-$q['B']-$q['D'];
    $in+=['ai_role'=>$role,'role_quota'=>$q,'role_cap'=>array_sum($q),'snapshot_hash'=>$hash];
    $o=$f[$role];
    foreach (['ai_role','quota','role_quota','role_cap','snapshot_hash','schema_version','prompt_version','mode','mode_status','allocation_mode','mode_policy_id','race_id','selection_enabled'] as $k) $o[$k]=$in[$k];
    $o['horses']=remap($o['horses']);
    $supported=($finder===$role || $pattern==='shared')?[12]:[7];
    $ranks=['A'=>[],'B'=>[],'D'=>[]];
    foreach ($o['horses'] as &$h) {
        $rank=17-$h['horse_no'];
        if ($pattern!=='empty' && ($rank<=6 || in_array($rank,$supported,true))) {
            $ranks[$h['band']][]=$h['horse_no'];
            continue;
        }
        $h['tier']=$h['eligibility']='UNVERIFIED';$h['rank_basis']='NOT_RANKED';
        $h['evidence']=$h['evidence_rank_evidence_ids']=[];
        foreach ($h['family_review'] as &$family) $family=['status'=>'DATA_MISSING','reason'=>'根拠となる資料不足','evidence_ids'=>[],'source_refs'=>[]];
        unset($family);
    }
    unset($h);
    $o['evidence_band_ranks']=$o['band_ranks']=$ranks;
    $p=Selection::apply($in,$o['horses'],$ranks);
    foreach (['selection_ranks','expected_reviews','public_candidates'] as $k) $o[$k]=$p[$k];
    foreach ($o['horses'] as &$h) {
        $has=in_array($h['horse_no'],$p['public_candidates'],true);$h['public_candidate']=$has;
        $h['nonselect_reason']=$has?null:($h['eligibility']==='ELIGIBLE'?['code'=>'BAND_QUOTA','reason'=>'選出枠外']:['code'=>'UNVERIFIED','reason'=>'該当する資料が不足']);
    }
    unset($h);
    $o['shortfalls']=[];
    foreach ($q as $b=>$need) {
        $eligible=count($ranks[$b]);if ($eligible<$need) $o['shortfalls'][]=['band'=>$b,'requested'=>$need,'eligible'=>$eligible,'missing'=>$need-$eligible,'reason'=>'確認できた根拠のある馬に限定'];
    }
    usort($o['horses'],static fn($a,$b)=>$a['horse_no']<=>$b['horse_no']);
    $o=r1519Reviews($in,$o);
    Selection::verifyReply($in,$o);
    file_put_contents($dir.'/'.$role.'_input.json',wire($in));
    file_put_contents($dir.'/'.$role.'_output.json',wire($o));
}

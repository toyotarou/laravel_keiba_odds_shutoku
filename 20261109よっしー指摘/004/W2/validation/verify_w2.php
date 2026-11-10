<?php
declare(strict_types=1);
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
require_once __DIR__.'/../php/ExpectedBridgeW2.php';
require_once __DIR__.'/market_fixture.php';
require_once __DIR__.'/r1519_fixture.php';

$passed=0;
function check(bool $x,string $name):void {
    global $passed;
    if (!$x) throw new RuntimeException("Failed: $name");
    ++$passed; echo "PASS $name\n";
}
function reject(callable $call,string $name):void {
    try { $call(); }
    catch (InvalidArgumentException $e) { check(true,$name);return; }
    throw new RuntimeException("Expected rejection: $name");
}
function ids(array $a):array {return array_column($a,null,'horse_no');}
function moveTime(string $s,int $days):string {return (new DateTimeImmutable($s))->modify("-$days days")->format(DATE_ATOM);}
function sortedTree(mixed $x):mixed {
    if (!is_array($x)) return $x;
    if (!Packet::isList($x)) ksort($x,SORT_STRING);
    foreach ($x as $k=>$v) $x[$k]=sortedTree($v);
    return $x;
}
// Synthetic alternative scenarios are separate frozen snapshots with their own common hash.
function freezeFixture(array $in):array {
    $common=$in;
    foreach (['snapshot_hash','ai_role','role_cap','role_quota'] as $k) unset($common[$k]);
    $in['snapshot_hash']=hash('sha256',json_encode(sortedTree($common),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    return $in;
}
function fixtureResponseHash(array $reply):string {
    $raw="<<<BAGANRIKI_V041_R8_BEGIN>>>\n".json_encode($reply,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)."\n<<<BAGANRIKI_V041_R8_END>>>";
    return hash('sha256',$raw);
}
function fixtureProof(array $roles):array {
    $proof=[];
    foreach ($roles as $role) foreach ($role['base_candidates'] as $n)
        $proof[$role['ai_role']][$n]=$role['public_proof_hash'];
    return $proof;
}
function historyRace(array $target,int $age,bool $near):array {
    $h=$target; $h['race_id']=sprintf('SYNTH-HISTORY-%03d',$age);
    foreach(['post_at','input_freeze_at'] as $k) $h[$k]=moveTime($h[$k],$age);
    foreach ($h['batches'] as $slot=>&$b) {
        foreach (['requested_at','received_at','available_at'] as $k) $b[$k]=moveTime($b[$k],$age);
        $b['batch_id']=$h['race_id'].'-'.$slot;
        $b['quotes']=array_reverse($b['quotes']);
    }
    unset($b);
    $h['distance_metres']=$near?1800:5000; $h['post_freeze_scratch']=false;
    $h['result_available_at']=(new DateTimeImmutable($h['post_at']))->modify('+1 hour')->format(DATE_ATOM);
    $pref=$near?[4,10,12,2,6]:[3,9,11,1,8];
    $order=array_merge($pref,array_values(array_diff(range(1,16),$pref)));
    $h['results']=[];
    foreach($order as $i=>$no) $h['results'][]=['horse_id'=>"H$no",'finish'=>$i+1];
    return $h;
}
function makeReply(array $roleInput,array $template):array {
    $template=r1519Reviews($roleInput,$template);
    foreach (['prompt_version','schema_version','snapshot_hash','race_id','selection_enabled','mode','mode_status','allocation_mode','mode_policy_id','role_cap','quota','role_quota','ai_role'] as $k) $template[$k]=$roleInput[$k];
    $r=Sel::apply($roleInput,$template['horses'],$template['band_ranks']);
    foreach (['selection_ranks','expected_reviews','public_candidates'] as $k)$template[$k]=$r[$k];
    foreach ($template['horses'] as &$horse) {
        $on=in_array($horse['horse_no'],$r['public_candidates'],true);
        $horse['public_candidate']=$on;
        if ($on) $horse['nonselect_reason']=null;
        elseif (!$roleInput['selection_enabled']) $horse['nonselect_reason']=['code'=>'SELECTION_DISABLED','reason'=>'合成試験の選出停止'];
        elseif ($horse['eligibility']==='ELIGIBLE') $horse['nonselect_reason']=['code'=>'BAND_QUOTA','reason'=>'帯内の選出後順位が枠外'];
    }
    unset($horse);
    return $template;
}
$fixture=json_decode(gzdecode(file_get_contents(__DIR__.'/synthetic_base.json.gz')),true,512,JSON_THROW_ON_ERROR);
$base=$fixture['input']; $base['schema_version']=Sel::SCHEMA;$base['prompt_version']=Sel::VERSION;
if (in_array($argv[3]??null,['--second-fixture','--sdk-fixture','--live-writer-fixture'],true)) {
    $base['race_id']=$argv[3]==='--sdk-fixture'?'20261017-4-05-07-01':'SYNTH_R9_OTHER_VENUE_N16';
    if ($argv[3]==='--live-writer-fixture') $base['race_id']='20261017-4-05-07-11';
    foreach ($base['source_registry'] as &$node) $node['race_id']=$base['race_id'];unset($node);
}
foreach(['snapshot_hash','role_quota','role_cap','ai_role'] as $k) unset($base[$k]);
$c=['race_id'=>$base['race_id'],'N'=>$base['N'],'post_at'=>$base['time_contract']['anchor_at'],'input_freeze_at'=>$base['input_freeze_at'],
    'jurisdiction'=>'JRA','surface'=>'DIRT','race_type'=>'FLAT','distance_metres'=>1800,'class_group'=>'C1','roster'=>[],'batches'=>[]];
foreach ($base['runners'] as $r) $c['roster'][]=['horse_no'=>$r['horse_no'],'horse_id'=>'H'.$r['horse_no']];
foreach ([6,21] as $s) {
    $a=$base['collection_audit'][$s];
    $b=['batch_id'=>$a['batch_id'],'requested_at'=>$a['request_started_at'],'received_at'=>$a['response_received_at'],'available_at'=>$a['available_at'],'quotes'=>[]];
    foreach ($c['roster'] as $r) {
        $n=$r['horse_no']; $ref=$base['input_indices']["horse/$n/win_odds/$s"][0];
        $b['quotes'][]=['horse_id'=>$r['horse_id'],'win'=>$base['source_registry'][$ref]['value']];
    }
    $c['batches'][$s]=$b;
}
$history=[];for ($i=1;$i<=120;$i++)$history[]=historyRace($c,$i,$i<=100);
$attached=Bridge::attach($base,$c,$history);
$common=$attached['common'];$packet=$common['expected_v01'];$r=ids($packet['rows']);
check(count($r)===16 && count(array_filter($r,fn($h)=>$h['status']==='COMPARISON_READY'))===16,'all 16 synthetic horses scored from 120 earlier results');
check(count($attached['audit']['pool_ids'])===120 && $r[10]['M']===120 && $r[10]['K']===100 && $r[10]['E3']>$r[9]['E3'] && $r[10]['p3_raw']>$r[10]['p0_3'],'B cutoff has positive local 3rd-place support');
check($r[12]['E5']>=$r[11]['E5'] && $r[4]['E5']>$r[3]['E5'],'both independent cutoff comparisons have support');
$onlyPrior=$history[0];$onlyPrior['class_group']=null;
$past=Packet::build($c,[$onlyPrior]);$pr=ids($past['packet']['rows']);
check($pr[1]['M']===1&&$pr[1]['K']===0&&$pr[1]['status']==='BASELINE_ONLY','missing historical class still forms baseline');
$past=Packet::build($c,[]);$pr=ids($past['packet']['rows']);
check($pr[1]['M']===0 && $pr[1]['E3']===null && $pr[1]['status']==='UNAVAILABLE_BASELINE','verified empty baseline is not 0.5');
$past=Packet::build($c,null);$unavailable=$past['packet'];
check($unavailable['rows'][0]['reason']==='HISTORY_NOT_PROVIDED'&&$unavailable['rows'][0]['M']===null,'unavailable archive distinct from empty archive');
$past=Packet::build($c,array_slice($history,0,12));
check($past['packet']['rows'][0]['status']==='LOW_SUPPORT','small cohort is not ready');
$late=$c;$late['batches'][6]['available_at']=(new DateTimeImmutable($c['post_at']))->modify('-329 seconds')->format(DATE_ATOM);
check(count(array_filter(Packet::build($late,[])['packet']['rows'],fn($h)=>$h['reason']==='INCOMPLETE_ODDS6'))===16,'6-minute deadline +1s removes scores, preserves all runners');
$within=$c;$within['batches'][6]['available_at']=$within['input_freeze_at'];
check(Packet::build($within,[])['packet']['rows'][0]['status']==='UNAVAILABLE_BASELINE','6-minute deadline equality accepted');
$bad21=$c;array_shift($bad21['batches'][21]['quotes']);
$p=ids(Packet::build($bad21,$history)['packet']['rows']);
check($p[1]['reason']==='MISSING_ODDS21' && $p[2]['status']==='COMPARISON_READY','one missing 21m value stays local');
$bad6=$c;array_shift($bad6['batches'][6]['quotes']);$p=Packet::build($bad6,$history)['packet']['rows'];
check(count($p)===16 && array_column($p,'formula_rank')===array_fill(0,16,null),'one missing 6m quote never shrinks N');
$bad=$c;$bad['roster'][1]['horse_id']=$bad['roster'][0]['horse_id'];reject(fn()=>Packet::build($bad,[]),'duplicate target horse id rejected');
$jump=$c;$jump['race_type']='JUMP';check(Packet::build($jump,null)['packet']['rows'][0]['status']==='OUT_OF_SCOPE','jump racing out of scope');
$late21=$c;$late21['batches'][21]['available_at']=(new DateTimeImmutable($c['post_at']))->modify('-1139 seconds')->format(DATE_ATOM);
check(Packet::build($late21,$history)['packet']['rows'][0]['reason']==='MISSING_ODDS21','21-minute deadline +1s');
$dup=Packet::build($c,[$history[0],$history[0],$history[1]]);
check($dup['packet']['rows'][0]['M']===1&&$dup['audit']['excluded']['DUPLICATE_HISTORY_ID']===2,'duplicate historical race excluded once per duplicate copy');
$sameday=historyRace($c,0,true);$old=historyRace($c,731,true);$edge=historyRace($c,730,true);
$unknown=$history[2];$unknown['results'][0]['finish']=-1;
$scratch=$history[3];$scratch['post_freeze_scratch']=true;
$future=$history[4];$future['result_available_at']=$c['post_at'];
$foreign=$history[5];$foreign['jurisdiction']='LOCAL';
$ties=$history[6];$ties['results'][1]['finish']=1;$ties['results'][14]['finish']='DQ';$ties['results'][15]['finish']='DNF';
$x=Packet::build($c,[$sameday,$old,$edge,$unknown,$scratch,$future,$foreign,$ties]);
check(count($x['audit']['pool_ids'])===2 && in_array($edge['race_id'],$x['audit']['pool_ids'],true),'historical cutoff, 730d equality, scratch/unknown, DNF/DQ/ties and JRA');
$t=$c;$t['batches'][6]['quotes']=array_reverse($t['batches'][6]['quotes']);$t['batches'][6]['quotes'][0]['win']=11;
$h=ids(Packet::build($t,null)['packet']['rows']);check($h[1]['formula_rank']===1 && $h[16]['formula_rank']===2,'equal odds sorted by horse number, independent of row order');
check(Packet::canonicalOdd('6.00')===Packet::canonicalOdd(6) && Packet::canonicalOdd('6e0')===Packet::canonicalOdd(6)
    && Packet::canonicalOdd('6.00000000000000001')!==Packet::canonicalOdd(6),'exact odds binding and decimal ties');
$rev=$c;$rev['batches'][21]['quotes']=array_reverse($rev['batches'][21]['quotes']);
check(Packet::build($rev,$history)['packet']['rows']===$packet['rows'],'21-minute quotes join by horse ID rather than position');
$bad=$c;$bad['results']=[];reject(fn()=>Packet::build($bad,[]),'target outcome injection rejected');
$bad=$c;$bad['race_id']='different';reject(fn()=>Bridge::attach($base,$bad,[]),'wrong race rejected by bridge');
$bad=$c;$bad['batches'][21]['quotes'][0]['win']=999;reject(fn()=>Bridge::attach($base,$bad,[]),'same-race wrong odds rejected');
$rcal=['formula_version'=>Packet::FORMULA,'scope'=>'JRA_FLAT_TURF_DIRT','id'=>'SYNTH-CAL-1','trained_through'=>'2026-01-01T00:00:00+09:00','approved_at'=>'2026-01-02T00:00:00+09:00','a'=>1.1,'b'=>0.01];
$calx=Packet::build($c,$history,$rcal)['packet'];check($calx['calibration_id']==='SYNTH-CAL-1' && $calx['rows'][0]['basis']==='CALIBRATION_APPLIED','pre-existing common synthetic calibrator');
$lateCal=$rcal;$lateCal['approved_at']=$c['post_at'];reject(fn()=>Packet::build($c,$history,$lateCal),'future calibration rejected');
$w1=Bridge::attach($base,$c,null)['common'];$roles=[];$ins=[];$replies=[];
foreach (['1st','2nd'] as $role) {
    $roleData=$fixture[$role]; $i=$common;
    // Canonical bytes for a synthetic scalar tree; production uses its original exact-decimal writer.
    $i['snapshot_hash']=hash('sha256',json_encode(sortedTree($common),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    foreach(['ai_role','role_cap','role_quota'] as $key)$i[$key]=$roleData[$key];
    $o=makeReply($i,$roleData); $r=Sel::verifyReply($i,$o);
    // Unit fixture for the merger. verify_backend.php obtains this proof through the real raw reply parser.
    $r['public_proof_hash']=fixtureResponseHash($o);
    $ins[$role]=$i;$replies[$role]=$o;$roles[$role]=$r;
    check($r['expected_reviews']['B']['decision']==='SWAP' && $r['expected_reviews']['D']['decision']==='SWAP',"$role E3 and E5 cause real B/D membership changes");
    check(in_array(10,$r['public_candidates'],true) && in_array(12,$r['public_candidates'],true) && !in_array(9,$r['public_candidates'],true) && !in_array(11,$r['public_candidates'],true),"$role boundary changes only eligible neighbours");
    $i0=$i;$i0['expected_v01']=$unavailable;$u=Sel::apply(freezeFixture($i0),$o['horses'],$o['band_ranks']);
    check($u['selection_ranks']===$o['band_ranks'] && $u['public_candidates']===$u['base_candidates'],"$role missing E reproduces base exactly");
}
check($ins['1st']['snapshot_hash']===$ins['2nd']['snapshot_hash'],'shared immutable common hash');
$i=$ins['1st'];$o=$replies['1st'];$r=$roles['1st'];
check($r['expected_reviews']['A']['decision']==='SWAP' && in_array(4,$r['public_candidates'],true),'A membership changes through E5 with E3 noninferior');
$hh=$o['horses'];foreach($hh as &$h) if($h['horse_no']===10)$h['tier']='A';unset($h);
check(Sel::apply($i,$hh,$o['band_ranks'])['expected_reviews']['B']['decision']==='TIER_MISMATCH','different verified Tier not displaced');
function controlledRow(array &$input,int $horse,float $p3,float $p5,float $p03,float $p05):void {
    foreach ($input['expected_v01']['rows'] as &$row) if ($row['horse_no']===$horse) {
        $row['p0_3']=$p03; $row['p0_5']=$p05;$row['p3_raw']=$p3;$row['p5_raw']=$p5;
        $row['E3']=100.0*$p3;$row['E5']=100.0*$p5;
    }
    unset($row);
}
$control=$i;controlledRow($control,9,.2001,.45,.1,.3);controlledRow($control,10,.21,.44,.1,.3);
check(Sel::apply(freezeFixture($control),$o['horses'],$o['band_ranks'])['expected_reviews']['B']['decision']==='KEEP','E3 alone cannot displace a runner with stronger E5');
controlledRow($control,10,.2002,.45,.1,.3);
check(round(100*.2001,1)===round(100*.2002,1) && Sel::apply(freezeFixture($control),$o['horses'],$o['band_ranks'])['expected_reviews']['B']['decision']==='SWAP','comparison uses unrounded values even when the display ties');
controlledRow($control,10,.2001,.45,.1,.3);
check(Sel::apply(freezeFixture($control),$o['horses'],$o['band_ranks'])['expected_reviews']['B']['decision']==='KEEP','primary E equality holds order');
controlledRow($control,10,.21,.45,.21,.35);
check(Sel::apply(freezeFixture($control),$o['horses'],$o['band_ranks'])['expected_reviews']['B']['decision']==='KEEP','challenger must exceed own rank baseline strictly');
$zero=$i;$zero['expected_v01']['rows'][9]=$unavailable['rows'][9];
check(Sel::apply(freezeFixture($zero),$o['horses'],$o['band_ranks'])['expected_reviews']['B']['decision']==='E_UNAVAILABLE','no leapfrogging a missing next horse');
$badDate=$i;$badDate['expected_v01']['history_before']=$c['post_at'];reject(fn()=>Sel::apply(freezeFixture($badDate),$o['horses'],$o['band_ranks']),'wrong history cutoff rejected');
$off=marketFixture($i,'OFF');
$off=freezeFixture($off);$inactive=Sel::apply($off,$o['horses'],$o['band_ranks']);check($inactive['public_candidates']===[] && $inactive['expected_reviews']['A']['decision']==='SELECTION_DISABLED','unresolved selection returns no candidates');
$none=marketFixture($i,'FIRM');$none=freezeFixture($none);check(Sel::apply($none,$o['horses'],$o['band_ranks'])['expected_reviews']['D']['decision']==='NO_BOUNDARY','zero quota cannot receive E swap');
$broken=$i;$broken['expected_v01']['rows'][0]['horse_no']=2;reject(fn()=>Sel::apply(freezeFixture($broken),$o['horses'],$o['band_ranks']),'duplicate/missing E row rejected');
$broken=$i;$broken['expected_v01']['rows'][0]['W']=9.999;reject(fn()=>Sel::apply(freezeFixture($broken),$o['horses'],$o['band_ranks']),'false READY rejected');
$broken=$i;$broken['expected_v01']['rows'][0]['E3']=round($broken['expected_v01']['rows'][0]['E3'],1);reject(fn()=>Sel::apply(freezeFixture($broken),$o['horses'],$o['band_ranks']),'rounded display cannot replace raw E');
$fail=$o;$fail['public_candidates']=$roles['1st']['base_candidates'];reject(fn()=>Sel::verifyReply($i,$fail),'prompt-only mention with old candidate list rejected');
$fail=$o;$fail['expected_reviews']['B']['decision']='KEEP';reject(fn()=>Sel::verifyReply($i,$fail),'misreported reason rejected');
$fail=$o;$fail['selection_ranks']['D']=$o['band_ranks']['D'];reject(fn()=>Sel::verifyReply($i,$fail),'misordered final D rejected');
$wrong=$i;$wrong['expected_v01']['race_id']='wrong';reject(fn()=>Sel::apply(freezeFixture($wrong),$o['horses'],$o['band_ranks']),'cross-race score cannot enter replay');
$nr=range(1,16);$groups=array_fill_keys($nr,1);
$m=Sel::merge($i,array_values($roles),$i['quota'],$nr,fixtureProof($roles),$groups);
check(count($m['public_candidates'])===7 && count(array_unique($m['public_candidates']))===7 && in_array(10,$m['public_candidates'],true) && in_array(12,$m['public_candidates'],true),'E-aware selections survive the two-role merge');
check($m['agreement_bonus'][10]===0&&$m['agreement_bonus'][12]===0&&$m['agreement_bonus'][7]===5,'common E-only swaps do not create a second bonus');
$active=array_values(array_diff($nr,[10]));$m2=Sel::merge($i,array_values($roles),['A'=>3,'B'=>2,'D'=>1],$active,fixtureProof($roles),$groups);
check(!in_array(10,$m2['public_candidates'],true)&&count($m2['public_candidates'])===6,'late cancellation 16 to 15: six slots, frozen E ranks');
$other=$roles['2nd'];$other['snapshot_hash']=str_repeat('0',64);reject(fn()=>Sel::merge($i,[$roles['1st'],$other],$i['quota'],$nr,[],$groups),'cross-snapshot merge rejected');
// A non-production probe codec: it removes/rebuilds the registry; the wrapper must also protect all E and coverage fields.
$pack=static function($d){$d['probe_table']=$d['source_registry'];unset($d['source_registry']);return $d;};
$expand=static function($d){$d['source_registry']=$d['probe_table'];unset($d['probe_table']);return $d;};
function treeEq($a,$b):bool {
    if (!is_array($a)||!is_array($b)) return $a===$b;
    if (Packet::isList($a)!==Packet::isList($b)||count($a)!==count($b)) return false;
    foreach ($a as $k=>$v) if (!array_key_exists($k,$b)||!treeEq($v,$b[$k]))return false;
    return true;
}
$wire=Bridge::compact($i,$pack,$expand,'treeEq');
check(treeEq(Bridge::expand($wire,$expand),$i),'wire wrapper roundtrip including E, input_indices and coverage');
$damage=static function($w)use($expand){$d=$expand($w);$d['input_indices']=[];return $d;};
reject(fn()=>Bridge::compact($i,$pack,$damage,'treeEq'),'reference loss rejected');
$wireBad=$wire;unset($wireBad['expected_v01']);reject(fn()=>Bridge::expand($wireBad,$expand),'lost E packet rejected');
$priorCount=$passed;
require __DIR__.'/r1_cases.php';
echo 'R1 regression checks: '.($passed-$priorCount)."\n";
$r2Before=$passed;
require __DIR__.'/r2_cases.php';
echo 'R2 regression checks: '.($passed-$r2Before)."\n";
$r3Before=$passed;require __DIR__.'/r3_cases.php';
echo 'R3 regression checks: '.($passed-$r3Before)."\n";
require __DIR__.'/r6_cases.php';
require __DIR__.'/r7_cases.php';
require __DIR__.'/r8_cases.php';
require __DIR__.'/r9_cases.php';

if (in_array($argc,[3,4],true) && $argv[1]==='--examples') {
    $dir=$argv[2]; if(!is_dir($dir))mkdir($dir,0700,true);
    foreach ($ins as $role=>$i)foreach (['input'=>$i,'output'=>$replies[$role]] as $kind=>$item)
        file_put_contents("$dir/{$role}_$kind.json",json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    file_put_contents("$dir/expected_proof.json",json_encode($attached['audit']['expected_proof'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    file_put_contents("$dir/merge.json",json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
}
echo "W2 tests passed: $passed (synthetic history; no live AI).\n";

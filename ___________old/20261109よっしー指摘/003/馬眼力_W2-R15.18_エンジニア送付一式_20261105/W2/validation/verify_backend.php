<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiJson as ExactJson;
use App\Http\Controllers\Api\BaganrikiPrompt as Wire;
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
require_once __DIR__.'/../php/ExpectedBridgeW2.php';
require_once __DIR__.'/market_fixture.php';
if ($argc !== 3) throw new InvalidArgumentException('TRUSTED_BACKEND_BOOTSTRAP EXAMPLES_DIR');
require_once $argv[1];
function expect(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function exactFile(string $path):array {
    $json=file_get_contents($path);$decoded=ExactJson::decode($json);
    expect($decoded['ok']===true,'JSON decode');
    $again=ExactJson::encode($decoded['value'],$decoded['map']);
    expect(ExactJson::sameJson($json,$again,true)['same']===true,'exact JSON source round trip');
    return $decoded['value'];
}
foreach (['1st','2nd'] as $role) {
    $in=exactFile($argv[2]."/{$role}_input.json");$out=exactFile($argv[2]."/{$role}_output.json");
    $back=static function($wire) {
        $r=Wire::expandWire($wire); expect($r['ok'],'expand');return $r['input'];
    };
    $pack=static function($tree) {
        $r=Wire::compactWire($tree); expect($r['ok'],'compact');return $r['wire'];
    };
    $same=static fn($a,$b)=>ExactJson::sameJson(Wire::canonical($a),Wire::canonical($b),true)['same'];
    $wire=Bridge::compact($in,$pack,$back,$same);
    $wireBytes=Wire::canonical($wire);$wireRead=ExactJson::decode($wireBytes);
    expect($wireRead['ok']===true,'wire read');
    $restored=Bridge::expand($wireRead['value'],$back);
    expect($same($restored,$in) && Wire::commonHash($restored)['hash']===$in['snapshot_hash'],'restored hash');
    expect(Selection::verifyReply($restored,$out)['public_candidates']===$out['public_candidates'],'decoded reply selection');
    echo "PASS $role archive strict JSON, transmitted compact bytes, restored input/hash and reply selection.\n";
}
expect(!ExactJson::sameJson('{"x":1.99999999999999999999}','{"x":2}',true)['same'],'decimal loss detected');
expect(!ExactJson::sameJson('{"x":1.99999999999999999999}','{"x":"1.99999999999999999999"}',true)['same'],'JSON type loss detected');
$seed=$in;
$seed['runners'][]=['horse_no'=>17,'win_rank'=>17,'band'=>'D'];
$seed['runners'][]=['horse_no'=>18,'win_rank'=>18,'band'=>'D'];$seed['N']=18;
$band=array_column($seed['runners'],'band','horse_no');
$groups=array_fill_keys(array_keys($band),1);
$ranks=['A'=>range(1,6),'B'=>range(7,10),'D'=>range(11,18)];
$cases=0;
foreach (['FIRM','MIXED','VOLATILE'] as $mode) {
    $seed=marketFixture($seed,$mode);
    $seed['quota']=Ranking::afterCancellation($band,[],[],$mode)['live_quota'];
    $commonSeed=$seed;
    foreach (['snapshot_hash','ai_role','role_cap','role_quota'] as $field) unset($commonSeed[$field]);
    $seed['snapshot_hash']=hash('sha256',Wire::canonical($commonSeed));
    $public=[]; $reviews=[];
    foreach ($ranks as $b=>$list) {
        $q=$seed['quota'][$b]; $boundary=$q>0 && count($list)>$q;
        array_push($public,...array_slice($list,0,$q));
        $reviews[$b]=['incumbent'=>$boundary?$list[$q-1]:null,'challenger'=>$boundary?$list[$q]:null,'decision'=>$boundary?'KEEP':'NO_BOUNDARY'];
    }
    $role=['prompt_version'=>Selection::VERSION,'ai_role'=>'1st','race_id'=>$seed['race_id'],'snapshot_hash'=>$seed['snapshot_hash'],
           'selection_ranks'=>$ranks,'expected_reviews'=>$reviews,'base_candidates'=>$public,'public_candidates'=>$public];
    for ($a=0;$a<=6;$a++) for ($b=0;$b<=4;$b++) for ($d=0;$d<=8;$d++) {
        $alive=array_merge(array_slice($ranks['A'],0,$a),array_slice($ranks['B'],0,$b),array_slice($ranks['D'],0,$d));
        $cancelled=array_values(array_diff(array_keys($band),$alive));
        $ref=Ranking::afterCancellation($band,$cancelled,$seed['quota'],$mode);
        $m=Selection::merge($seed,[$role],$ref['live_quota'],$alive,[],array_intersect_key($groups,array_flip($alive)));
        $picks=$m['public_candidates'];
        $actual=['A'=>0,'B'=>0,'D'=>0]; foreach ($picks as $n) $actual[$band[$n]]++;
        expect(count($picks)===$ref['target_count'] && count(array_unique($picks))===count($picks) &&
            array_diff($picks,$alive)===[] && $actual===($ref['live_quota']??['A'=>0,'B'=>0,'D'=>0]) && $m['fill']===[],
            "frozen capacities mismatch $mode/$a/$b/$d");
        $cases++;
    }
}
echo "PASS $cases cancellation capacity/mode combinations against the submitted Laravel ranking class.\n";

require_once __DIR__.'/r4_fixtures.php';
require __DIR__.'/r2_backend.php';

require __DIR__.'/r3_backend.php';

require __DIR__.'/r4_backend.php';

require __DIR__.'/r5_backend.php';

require __DIR__.'/r5_scope_reference.php';

require_once __DIR__.'/r12_score_fixtures.php';
require __DIR__.'/r6_backend.php';

require __DIR__.'/r7_backend.php';

require __DIR__.'/r7_quota_replay.php';
require __DIR__.'/r8_backend.php';

require __DIR__.'/r8_withdrawals.php';

require __DIR__.'/r9_backend.php';

require __DIR__.'/r9_journals.php';

require __DIR__.'/r10_backend.php';

require __DIR__.'/r12_backend.php';

require __DIR__.'/r13_backend.php';

require __DIR__.'/r14_backend.php';

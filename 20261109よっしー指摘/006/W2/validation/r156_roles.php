<?php
declare(strict_types=1);
namespace App\Http\Controllers { class Controller {} }
namespace {
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
use App\Http\Controllers\Api\BaganrikiPublicView as View;
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\RoleJournalW2 as Journal;
function env($key,$default=null){return $default;}
require __DIR__.'/r7_storage_probe.php';
function check156($ok,$why){if(!$ok)throw new \RuntimeException($why);}
function testedFields(array $a):array{
    return array_intersect_key($a,array_flip(['public_candidates','agreement_bonus','public_proof_hashes','fill','shortfalls','actual_quota']));
}
$data=preparedRows($argv[2]);$records=[];$votes=[];
foreach($data['roles'] as $row){
    $role=$row['role'];$f=Frozen::fromStored($data['common'],$row['input'],$data['hash'],$role,$data['witness']);
    $records[$role]=['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$row['receipt']+['raw'=>$row['original']]]];
    foreach($row['proof_horses'] as $n)$votes[$role][$n]=$row['receipt']['response_hash'];
}
$f=$records['1st']['input'];$in=Frozen::checked($f);$bands=array_column($in['runners'],'band','horse_no');
$total=$single=$both=$none=$stale=0;$modes=['OK','ABSENT','UNKNOWN_RESULT','INCOMPLETE','BAD_HASH'];
foreach([[],[$data['roles'][0]['proof_horses'][0]],[$data['roles'][0]['proof_horses'][0],$data['numbers'][count($data['numbers'])-1]]] as $withdrawn){
    $active=array_values(array_diff($data['numbers'],$withdrawn));
    $quota=Ranking::afterCancellation($bands,$withdrawn,$in['quota'],$in['allocation_mode'])['live_quota'];
    $live=r4live($f,null,null,false,$active);
    $only=[];
    foreach(['1st','2nd','both'] as $source){
        $rr=$source==='both'?$records:[$source=>$records[$source]];
        $vv=$source==='both'?$votes:[$source=>$votes[$source]];
        $same=$live;$same['role_journal']=Journal::witness($f,$rr,$vv);
        $only[$source]=testedFields(Selection::mergeStored($f,$rr,$quota,$active,$vv,$same));
    }
    foreach($modes as $first)foreach($modes as $second){
        $rr=$records;$vv=$votes;$valid=[];$m=['1st'=>$first,'2nd'=>$second];$why=$first.'/'.$second.' cancelled='.implode(',',$withdrawn);
        foreach($m as $r=>$mode){
            if($mode==='OK'){$valid[]=$r;continue;}
            if($mode==='ABSENT'){unset($rr[$r],$vv[$r]);continue;}
            $receipt=&$rr[$r]['receipts'][0];
            if($mode==='UNKNOWN_RESULT')$receipt['status']='UNKNOWN_RESULT';
            if($mode==='INCOMPLETE')$receipt['complete']=false;
            if($mode==='BAD_HASH')$receipt['raw'].="\n";
            unset($receipt);
        }
        $fresh=$live;$fresh['role_journal']=Journal::witness($f,$rr,$vv);
        $winner=null;
        try {$winner=Selection::mergeStored($f,$rr,$quota,$active,$vv,$fresh);}
        catch(\InvalidArgumentException $e){check156(!$valid&&$e->getMessage()==='W2_NO_VERIFIED_ROLE',$why.' unexpected '.$e->getMessage());}
        if(!$valid){check156($winner===null,$why.' permitted publication');++$none;}
        else{
            $source=count($valid)===2?'both':$valid[0];
            check156($winner!==null&&testedFields($winner)===$only[$source],$why.' rank, proof or quota drift');
            $picks=$winner['public_candidates'];
            check156(count($picks)>0&&count($picks)<=min(7,array_sum($quota))
                &&count($picks)===count(array_unique($picks))&&!array_diff($picks,$active),$why.' public candidate bounds');
            Window::assertPublicationCommit($f,$winner,$fresh['now'],$fresh);
            if(count($valid)===1){
                check156(array_sum($winner['agreement_bonus'])===0&&!$winner['public_proof_hashes'],$why.' false consensus');
                ++$single;
            }else{++$both;}
        }
        $publication=$winner===null?null:['public_candidates'=>$winner['public_candidates']];
        $dto=View::build(['race_id'=>$data['race_id'],'publication'=>$publication,'buy_recommended'=>true,
            'learning_stage'=>'BAD','purchase_policy_status'=>'BAD']);
        check156($dto['publication']===$publication&&$dto['buy_recommended']===false
            &&$dto['learning_stage']==='COLLECT_ONLY'&&$dto['purchase_policy_status']==='PENDING_VALIDATION',$why.' purchase/public isolation');
        if($first!=='OK'||$second!=='OK'){
            $old=$fresh;$old['role_journal']=$data['role_journal'];$code=null;
            try{Selection::mergeStored($f,$rr,$quota,$active,$vv,$old);}
            catch(\InvalidArgumentException $e){$code=$e->getMessage();}
            $expect=$first==='OK'?'2nd':'1st';
            check156($code==='W9_JOURNAL_MISMATCH_'.$expect,$why.' stale journal');++$stale;
        }++$total;
    }
}
echo "PASS $total publication scenarios ($single one verified role, $both both, $none neither); purchase recommendation false in all, $stale stale journals refused.\n";
}

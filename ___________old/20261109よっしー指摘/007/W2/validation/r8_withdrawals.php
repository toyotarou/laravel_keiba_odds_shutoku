<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
(function(string $dir) {
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);
    $attempts=[];$verified=[];
    foreach (['1st','2nd'] as $role) {
        $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $reply=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $f=r3stored($in,$proof);$raw=r3frame($reply);$attempts[$role]=r4original($f,$raw);
        $verified[]=Sel::verifyOriginal($f,$raw,hash('sha256',$raw));if ($role==='1st') $seed=$f;
    }
    $by=array_column($in['runners'],'band','horse_no');$all=array_keys($by);$sets=[];
    foreach ($all as $a) {
        $sets[1][]=[$a];
        foreach ($all as $b) if ($a<$b) $sets[2][]=[$a,$b];
    }
    $attempts=r9fixtureRecords($attempts);$journal=\App\Services\Baganriki\RoleJournalW2::witness($seed,$attempts,[]);
    $total=$mismatched=$same=0;$different=[];
    foreach ($sets as $choices) {
        $params=[];
        foreach ($choices as $list) {
            $active=array_values(array_diff($all,$list));
            $quota=Ranking::afterCancellation($by,$list,$in['quota'],$in['allocation_mode'])['live_quota'];
            $params[]=[$active,$quota,$list];
        }
        foreach ($params as $i=>[$active,$quota,$official]) {
            $fresh=r4live($seed,null,null,false,$active);$fresh['state_revision']=1+$i;$fresh['role_journal']=$journal;
            foreach ($params as $j=>[$readActive,$readQuota]) {
                ++$total;
                if ($i!==$j) {
                    try { Sel::mergeStored($seed,$attempts,$readQuota,$readActive,[],$fresh); }
                    catch (InvalidArgumentException $e) {
                        expect($e->getMessage()==='W8_LIVE_ROSTER_MISMATCH','R8 wrong refusal '.$e->getMessage());++$mismatched;continue;
                    }
                    throw new RuntimeException('R8 stale equal-size roster was published');
                }
                $correct=Sel::mergeStored($seed,$attempts,$quota,$active,[],$fresh);
                $reference=Sel::merge($seed,$verified,$quota,$active,[],array_fill_keys($all,1));
                expect($correct['public_candidates']===$reference['public_candidates'] && !array_intersect($official,$correct['public_candidates']),'R8 withdrawn number or order');
                Window::assertPublicationCommit($seed,$correct,$fresh['now'],$fresh);++$same;
                $different[implode(',',$correct['public_candidates'])]=true;
            }
        }
    }
    expect($total===14656 && $same===136 && $mismatched===14520 && count($different)>10,'R8 withdrawals coverage');
    echo "PASS $total R8 independent read pairs for 1- and 2-horse withdrawals; $mismatched stale equal-size rosters rejected, $same matched rosters verified in exact order.\n";
})($argv[2]);

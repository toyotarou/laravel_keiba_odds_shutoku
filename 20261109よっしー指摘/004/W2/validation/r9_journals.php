<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\RoleJournalW2 as Journal;
use App\Services\Baganriki\AttemptWindowW2 as Window;
(function(string $dir) {
    $states=[];
    foreach (['1st','2nd'] as $role) {
        $c=r9scenario($dir,$role);extract($c);
        $seed=$f;$nos=array_column($in['runners'],'horse_no');$activeGroups=array_fill_keys($nos,1);
        $initial=Sel::verifyOriginal($f,$raw,$first['response_hash']);$corrected=Sel::verifyOriginal($f,$rawFix,$fix['response_hash']);
        $recResult=$initial;$recResult['public_proof_hash']=null;
        $orig=['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$first]];
        $latest=$orig;$latest['receipts'][]=$fix;$failed=$declined=$orig;$failed['receipts'][0]['status']='FAILED';$declined['receipts'][0]['original_approved']=false;
        $votes=static fn($x)=>array_fill_keys($x['base_candidates'],$x['public_proof_hash']);
        $states[$role]=[
            [null,null,[]],[$orig,$initial,$votes($initial)],[$latest,$corrected,$votes($corrected)],
            [r4recoveryRecord($f,$o,$first),$recResult,[]],[$declined,null,[]],[$failed,null,[]],[$orig,$initial,[]],
        ];
    }
    $versions=[];
    foreach ($states['1st'] as $left) foreach ($states['2nd'] as $right) {
        $rows=[];$proof=[];$roles=[];
        foreach (['1st'=>$left,'2nd'=>$right] as $role=>[$record,$expected,$votes]) {
            if ($record!==null) $rows[$role]=$record;
            if ($expected!==null)$roles[]=$expected;
            if ($votes)$proof[$role]=$votes;
        }
        $known=$roles?Sel::merge($seed,$roles,$in['quota'],$nos,$proof,$activeGroups):null;
        $versions[]=['records'=>$rows,'proof'=>$proof,'journal'=>Journal::witness($seed,$rows,$proof),'known'=>$known];
    }
    $checked=$stale=$published=$unavailable=0;
    foreach ($versions as $i=>$truth) {
        $ctx=r4live($seed);$ctx['response_revision']=7;$ctx['role_journal']=$truth['journal'];
        foreach ($versions as $j=>$read) {
            ++$checked;
            try {
                $actual=Sel::mergeStored($seed,$read['records'],$in['quota'],$nos,$read['proof'],$ctx);
                expect($i===$j && $truth['known']!==null && $actual['public_candidates']===$truth['known']['public_candidates'] &&
                    $actual['agreement_bonus']===$truth['known']['agreement_bonus'],'R9 stale role or vote changed picks');
                Window::assertPublicationCommit($seed,$actual,$ctx['now'],$ctx);++$published;
            } catch (InvalidArgumentException $e) {
                if ($i===$j) { expect($truth['known']===null && $e->getMessage()==='W2_NO_VERIFIED_ROLE','R9 aligned availability '.$e->getMessage());++$unavailable; }
                else { expect(str_starts_with($e->getMessage(),'W9_JOURNAL_MISMATCH_'),'R9 torn source did not stop before selection '.$e->getMessage());++$stale; }
            }
        }
    }
    expect($checked===2401 && $stale===2352 && $published===40 && $unavailable===9,'R9 journal matrix coverage');
    echo "PASS $checked R9 pairs of authoritative and worker journal reads; $stale stale pairs rejected, $published aligned selections and $unavailable aligned no-role cases.\n";
})($argv[2]);

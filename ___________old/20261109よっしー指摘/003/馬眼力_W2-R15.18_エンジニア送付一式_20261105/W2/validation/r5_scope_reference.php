<?php
declare(strict_types=1);
use App\Http\Controllers\Api\AiController as Controller;
use App\Http\Controllers\Api\BaganrikiResponse as Reply;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
(function(string $dir) {
    if (!method_exists(Controller::class,'invalidBandsOf')) {
        echo "SKIP optional custom bootstrap scope oracle: use --submitted-php for the pinned method.\n";return;
    }
    $in=json_decode(file_get_contents("$dir/1st_input.json"),true,512,JSON_THROW_ON_ERROR);
    $o=json_decode(file_get_contents("$dir/1st_output.json"),true,512,JSON_THROW_ON_ERROR);
    $f=r3stored($in);$initial=r4original($f,r3frame($o));$r0=$initial['receipts'][0];
    $caps=range(1,16);$count=0;
    $perms=[[],['horses'=>[6]],['summary_horses'=>[6]],['presentation_horses'=>[6]],['rank_bands'=>['A']],['rank_bands'=>['band_ranks']],['horses'=>[5]],['summary_horses'=>[5],'presentation_horses'=>[5],'rank_bands'=>['D']]];
    foreach ($perms as $permissions) {
        $scope=$permissions+array_fill_keys(['horses','summary_horses','presentation_horses','rank_bands'],[]);
        $grant=Window::repairGrant($r0['response_hash'],$scope);expect($grant['rank_bands']===Controller::invalidBandsOf($scope),'rank grant matches submitted parser');
        foreach (['narrative','rank','evidence-rank','support','review','unchanged'] as $kind) {
            $edited=$o;$h=&$edited['horses'][5];
            if ($kind==='narrative') $h['explanation'].=' revised';
            if ($kind==='rank') $h['rank_reason'].=' revised';
            if ($kind==='evidence-rank') $h['evidence_rank_reason'].=' revised';
            if ($kind==='support') $h['evidence'][0]['interpretation'].=' revised';
            if ($kind==='review') $h['family_review']['ability']['reason'].=' revised';
            unset($h);
            $ref=Reply::acceptCorrection([6=>$o['horses'][5]],[6=>$edited['horses'][5]],null,null,$caps,$scope,[6=>true]);
            $fix=r4receipt($f,r3frame($edited),1,true);$fix['repair_scope']=$grant;$rs=$initial;$rs['receipts'][]=$fix;
            $got=r9merge($f,['1st'=>$rs],$in['quota'],$caps,[],r4live($f));
            $applied=!isset($got['attempt_issues']['1st'][1]);
            expect($applied===$ref['ok'],'R5 permission disagrees '.$kind.' / '.json_encode($scope).' / '.json_encode($ref));
            ++$count;
        }
    }
    echo "PASS $count R5 correction permission combinations against the original submitted Laravel acceptance method.\n";
})($argv[2]);

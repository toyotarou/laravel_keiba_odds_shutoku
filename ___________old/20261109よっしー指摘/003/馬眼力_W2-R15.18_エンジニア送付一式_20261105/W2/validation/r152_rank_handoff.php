<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\FrozenInputW2 as Frozen;

function r152RankHandoff(string $role,$ctrl,Frozen $baseInput,array $in,array $out,$db,$saved,array $proof):int
{
    $count=0;
    $check=static function(bool $valid,string $label)use(&$count,$role):void {
        if (!$valid) throw new \RuntimeException("R15.2 $role $label");
        ++$count;
    };
    $project=static function(Frozen $input,array $reply):array {
        $calc=Sel::apply($input,$reply['horses'],$reply['band_ranks']);
        foreach (['public_candidates','selection_ranks','expected_reviews'] as $field) $reply[$field]=$calc[$field];
        foreach ($reply['horses'] as &$horse) {
            $selected=in_array($horse['horse_no'],$calc['public_candidates'],true);
            if ($selected!==$horse['public_candidate']) $horse['nonselect_reason']=$selected?null:['code'=>'BAND_QUOTA','reason'=>'帯の枠外'];
            $horse['public_candidate']=$selected;
        }unset($horse);return $reply;
    };
    $swap=static function(array $reply,string $band,int $i,int $j,bool $backupOnly=false):array {
        foreach ($backupOnly?['evidence_band_ranks']:['band_ranks','evidence_band_ranks'] as $field) {
            $list=&$reply[$field][$band];[$list[$i],$list[$j]]=[$list[$j],$list[$i]];unset($list);
        }
        return $reply;
    };
    $merge=static function(Frozen $input,array $record)use($role) {
        $i=Frozen::checked($input);
        return \r9merge($input,[$role=>$record],$i['quota'],array_column($i['runners'],'horse_no'),[],\r4live($input));
    };
    $validRole=static function(Frozen $input,array $rec)use($merge,$role):bool {
        try {$m=$merge($input,$rec);return !isset($m['role_issues'][$role]) && empty($m['attempt_issues']);}
        catch (\InvalidArgumentException $ex) {if ($ex->getMessage()!=='W2_NO_VERIFIED_ROLE') throw $ex;return false;}
    };
    $cases=[];$blank=$out;$blank['horses'][0]['explanation']='　';
    $cases['explanation only']=[$baseInput,$blank,$out,'FIX_APPLIED'];
    $cases['unrequested displayed order']=[$baseInput,$blank,$project($baseInput,$swap($out,'B',0,1)),'FIX_PARTIAL'];
    $cases['unrequested unused tail']=[$baseInput,$blank,$project($baseInput,$swap($out,'D',4,5)),'FIX_PARTIAL'];
    $needsRank=$out;array_pop($needsRank['band_ranks']['D']);array_pop($needsRank['evidence_band_ranks']['D']);
    $cases['explicitly allowed D']=[$baseInput,$needsRank,$project($baseInput,$swap($out,'D',4,5)),'FIX_APPLIED'];
    $fact=$out;$fact['horses'][6]['evidence'][0]['facts'][0]['value']='8765';
    $cases['changed fact permits its band']=[$baseInput,$fact,$project($baseInput,$swap($out,'B',0,1)),'FIX_APPLIED'];
    $cases['changed fact cannot unlock other band']=[$baseInput,$fact,$project($baseInput,$swap($out,'D',4,5)),'FIX_PARTIAL'];
    $bad=$out;$bad['shortfalls']=[['band'=>'A','requested'=>3,'eligible'=>1,'missing'=>2,'reason'=>'架空の不足']];
    $cases['top-only grant cannot change ranks']=[$baseInput,$bad,$project($baseInput,$swap($out,'D',4,5)),'FIX_REJECTED'];

    // Primary order from complete scores and a structurally valid independent backup in the same band.
    require_once __DIR__.'/r12_score_fixtures.php';
    $enriched=\r12ScoreInput($in);$common=BaganrikiPrompt::commonHash($enriched);
    $enriched['snapshot_hash']=$common['hash'];$scoreInput=Frozen::fromStored($common['json'],\r3bytes($enriched),$common['hash'],$role,$proof);
    $score=$out;$score['snapshot_hash']=$common['hash'];
    foreach ($score['horses'] as &$h) if ($h['band']==='A') {
        $h['scores']=Sel::scoreCaps();$h['score_status']='complete';$h['total_score']=$h['observed_cap']=$h['observed_sum']=array_sum($h['scores']);
        $h['rank_basis']='SCORE';foreach (\r12Refs($h['horse_no']) as $key=>$ids) $h['score_evidence'][$key]=['reason'=>'検証済み資料','source_refs'=>$ids];
    }unset($h);
    $score['evidence_band_ranks']['A']=array_reverse($score['band_ranks']['A']);
    $score=$project($scoreInput,$score);$s0=$score;$s0['horses'][0]['evidence_rank_reason']='　';$s0['horses'][0]['evidence_rank_evidence_ids']=['absent'];
    $cases['unchanged distinct score backup']=[$scoreInput,$s0,$score,'FIX_APPLIED'];
    $sb=$score;$sb['horses'][6]['explanation']='　';
    $cases['hidden score-mode backup switched']=[$scoreInput,$sb,$swap($score,'A',0,1,true),'FIX_PARTIAL'];
    $sr=$score;array_pop($sr['evidence_band_ranks']['A']);
    $cases['authorized score-mode backup']=[$scoreInput,$sr,$swap($score,'A',0,1,true),'FIX_APPLIED'];
    $sc=$score;$sc['horses'][0]['scores']['H1']=999;
    $sc['horses'][0]['observed_sum']=999;$sc['horses'][0]['total_score']=999;
    $cases['complete score repaired, old backup kept']=[$scoreInput,$sc,$score,'FIX_APPLIED'];

    foreach ($cases as $label=>[$input,$original,$corrected,$expected]) {
        $first=\r3frame($original);$fix=\r3frame($corrected);$db($first,$fix,$input);
        $inspection=$ctrl->inspectAiResponse(1,$role);$fixInsp=$ctrl->inspectAiResponse(1,$role,1);
        $check($inspection['needs_fix'] && $fixInsp['ok'] && !$fixInsp['needs_fix'],$label.' typed fixture '.json_encode($fixInsp['diag']['findings']??[]));
        $a=$ctrl->resolveCorrection(1,$role);$used=$ctrl->markResponseUsed(1,$role,$a);
        $check($a['code']===$expected,$label.' resolution '.json_encode([$a['code'],$a['reason'],$inspection['repair_scope']],JSON_UNESCAPED_UNICODE));
        $rec=$saved($first,$fix,$inspection,$input);
        $check($validRole($input,$rec)===($expected==='FIX_APPLIED'),$label.' independent final original');
        if ($expected==='FIX_APPLIED') {
            $rawhash=hash('sha256',$fix);
            $check($a['rank_receipts']===[hash('sha256',$first),$rawhash],$label.' exact receipt pair');
            $check($used['attempt_no']===1 && $used['band_ranks']===$corrected['band_ranks'] && $used['public_candidates']===$corrected['public_candidates'],$label.' single ordered source');
            $rebuilt=Sel::apply($input,array_values($a['merged']),$used['band_ranks']);
            $check($rebuilt['public_candidates']===$used['public_candidates'] && ($used['proof'][1]??null)===$rawhash,$label.' projection with original proof');
        } else {
            $check(($a['rank_issue']??null)==='W5_REPAIR_RANK_SCOPE' && $used['proof']===null,$label.' rank edit has no original proof');
            $check($used['attempt_no']===0 && $used['band_ranks']===$original['band_ranks'],$label.' keep original band order');
            if ($expected==='FIX_PARTIAL') $check(!$used['public_candidates'],$label.' recalculate candidates from retained rows');
            if (in_array($label,['unrequested displayed order','changed fact cannot unlock other band'],true)) {
                $rec['receipts'][1]['original_approved']=false;$doc=\r4recoveryDoc($input,$out,$rec['receipts'][0]);
                // Display-only repairs cannot serve as sources for evidence. A repaired fact can.
                if ($label==='changed fact cannot unlock other band') {
                    $rows=(array)$doc['row_sources'];$rows[7]=[$rec['receipts'][1]['response_hash']];$doc['row_sources']=(object)$rows;
                    $doc['rank_sources']['B']=null;
                }
                $recovered=$merge($input,\r4seal($doc,$rec['receipts'],$input));
                $solo=$merge($input,\r4original($input,\r3frame($out)));
                $check($recovered['public_candidates']===$solo['public_candidates'] && max($recovered['agreement_bonus'])===0,'recover the authorized row only');
                $pretend=$a;$pretend['code']='FIX_APPLIED';$pretend['rank_receipts']=[hash('sha256',$first),hash('sha256',$fix)];
                $guard=$ctrl->markResponseUsed(1,$role,$pretend);
                $check(!$guard['band_ranks'] && !$guard['public_candidates'] && $guard['proof']===null,'label and receipt hashes alone cannot authorize reorder');
            }
        }
        if ($label==='changed fact permits its band') {
            foreach ([0,1] as $slot) {
                $db($first,$fix,$input);$row=\R15Table::$tables['baganriki_ai_attempts'][$slot];
                $replacement=$slot===0?$blank:$out;$row->raw_response=\r3frame($replacement);$row->response_hash=hash('sha256',$row->raw_response);
                $stale=$ctrl->markResponseUsed(1,$role,$a);
                $check(!$stale['public_candidates'] && $stale['proof']===null && $stale['attempt_no']===null,'stale resolution held on replaced receipt '.$slot);
            }
        }
    }
    return $count;
}

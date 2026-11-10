<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\ResponseInspectionW2 as Inspection;

/** Executed inside the same two-role frozen fixture as R15, through the patched submitted PHP. */
function r151Corrections($role,$ctrl,$f,$in,$o,$sparse,$db,$saved,$merge):int
{
    $n=0;
    $ok=static function(bool $pass,string $why)use(&$n,$role):void {
        if (!$pass) throw new \RuntimeException("R15.1 $role $why");
        ++$n;
    };
    $healthy=\r3frame($o);$db($healthy,$healthy);
    $cfg=json_decode(file_get_contents(__DIR__.'/../config/w2_contract.json'),true,512,JSON_THROW_ON_ERROR);
    $ok($cfg['validator_version']===Inspection::REVISION && $cfg['prompt_version']===Sel::VERSION,'configuration pins both model and validator');
    $reference=$merge(\r4original($f,$healthy));
    $changes=[
        'fact'=>static function(&$h){$h['evidence'][0]['facts'][0]['value']='8765';},
        'rank ID'=>static function(&$h){$h['evidence_rank_evidence_ids']=['missing'];},
        'extra field'=>static function(&$h){$h['extra_note']='X';},
        'blank explanation'=>static function(&$h){$h['explanation']='　';},
        'family'=>static function(&$h){$h['family_review']['ability']['evidence_ids']=['absent'];},
        'sum'=>static function(&$h){$h['observed_sum']=557;},
        'counter'=>static function(&$h){$h['counterevidence']=[['source_refs'=>['unknown'],'reason'=>'資料誤り']];},
        'typed score field'=>static function(&$h){$h['score_evidence']['H1']['extra']='unknown';},
    ];
    $names=array_keys($changes);
    foreach ($names as $i=>$left) foreach (array_slice($names,$i+1) as $right) {
        $bad=$o;$changes[$left]($bad['horses'][0]);$changes[$right]($bad['horses'][0]);
        $first=\r3frame($bad);$db($first,$healthy);
        $d=$ctrl->inspectAiResponse(1,$role);$grant=$d['repair_scope'];$label="$left + $right";
        $ok($d['needs_fix'] && !array_diff(array_merge($grant['horses'],$grant['presentation_horses'],$grant['summary_horses']),[1]),"registered horse scope / $label");
        if ($left==='fact' && $right==='blank explanation') $ok($grant['horses']===[1] && $grant['presentation_horses']===[1],"source and presentation diagnosed");
        if ($left==='rank ID' && $right==='extra field') $ok(!$grant['horses'] && $grant['presentation_horses']===[1] && $grant['summary_horses']===[1],"narrow independent fields");
        $a=$ctrl->resolveCorrection(1,$role);
        $ok($a['code']==='FIX_APPLIED' && count($a['merged'])===count($o['horses']),"full fix / $label ".json_encode([$a['code'],$a['reason'],$grant,$d['diag']['findings']],JSON_UNESCAPED_UNICODE));
        $result=$merge($saved($first,$healthy,$d));
        $ok($result['role_issues']===$reference['role_issues'] && !$result['attempt_issues'] && $result['public_candidates']===$reference['public_candidates'],"independent final check / $label");
        $sneak=$o;$sneak['horses'][14]['explanation'].='無関係な変更';$db($first,\r3frame($sneak));
        $ok($ctrl->resolveCorrection(1,$role)['code']==='FIX_REJECTED',"unrelated horse / $label");
    }
    // Restoring a row isolated for a summary error cannot silently change even valid evidence on that same horse.
    $bad=$o;$changes['family']($bad['horses'][0]);$changes['rank ID']($bad['horses'][0]);
    $initial=\r3frame($bad);$alternate=$o;$alternate['horses'][0]['evidence'][0]['interpretation'].='許可外';
    $raw=\r3frame($alternate);$db($initial,$raw);$d=$ctrl->inspectAiResponse(1,$role);
    $ok(!$d['repair_scope']['horses'] && $ctrl->resolveCorrection(1,$role)['code']==='FIX_REJECTED','scoped rescue checks unchanged raw evidence on same horse');
    $denied=false;try {$merge($saved($initial,$raw,$d));} catch (\InvalidArgumentException $e) {$denied=$e->getMessage()==='W2_NO_VERIFIED_ROLE';}
    $ok($denied,'independent publication guard rejects same-horse evidence substitution');
    $short=['band'=>'A','requested'=>3,'eligible'=>1,'missing'=>2,'reason'=>'不足'];
    foreach (['declared shortage','presentation','evidence'] as $left) {
        $bad=$o;
        if ($left==='declared shortage') $bad['shortfalls']=[$short];
        if ($left==='presentation') $changes['blank explanation']($bad['horses'][0]);
        if ($left==='evidence') $changes['fact']($bad['horses'][0]);
        $raw=\r3frame($bad);$db($raw,$raw);
        $d=$ctrl->inspectAiResponse(1,$role);$a=$ctrl->resolveCorrection(1,$role);
        $ok($a['code']==='FIX_REJECTED' && !$a['applied'] && !$a['rescued'] && $a['merged']===$d['normal'],"same unresolved $left preserves first");
        $ok($ctrl->markResponseUsed(1,$role,$a)['proof']===null,"no original proof for unresolved $left");
    }
    // A valid repaired row may be used for recovery, but cannot validate a still-faulty full response.
    $bad=$o;$changes['fact']($bad['horses'][0]);$bad2=$o;$bad2['shortfalls']=[$short];
    $first=\r3frame($bad);$fix=\r3frame($bad2);$db($first,$fix);
    $d=$ctrl->inspectAiResponse(1,$role);$a=$ctrl->resolveCorrection(1,$role);$use=$ctrl->markResponseUsed(1,$role,$a);
    $ok($a['code']==='FIX_PARTIAL' && $a['rescued']===[1] && count($a['merged'])===16,'recovers authorized horse only');
    $ok($use['proof']===null && $use['public_candidates']===[] && $use['attempt_no']===0,'partial cannot supply original proof or candidate list');
    $record=$saved($first,$fix,$d);$refused=false;
    try {$merge($record);} catch (\InvalidArgumentException $ex) {$refused=$ex->getMessage()==='W2_NO_VERIFIED_ROLE';}
    $ok($refused,'final original cannot use partial response');
    $record['receipts'][1]['original_approved']=false;
    $doc=\r4recoveryDoc($f,$o,$record['receipts'][0]);$sources=(array)$doc['row_sources'];$sources[1]=[$record['receipts'][1]['response_hash']];$doc['row_sources']=(object)$sources;
    $doc['rank_sources']['A']=null;
    $recovered=$merge(\r4seal($doc,$record['receipts'],$f));
    $ok($recovered['public_candidates']===$reference['public_candidates'] && !isset($recovered['role_issues'][$role]) && max($recovered['agreement_bonus'])===0,'verified recovered rows / no cross-role bonus');

    $bad=$o;$changes['fact']($bad['horses'][0]);$changes['blank explanation']($bad['horses'][1]);
    $fix=$o;$changes['blank explanation']($fix['horses'][1]);$db(\r3frame($bad),\r3frame($fix));$d=$ctrl->inspectAiResponse(1,$role);$a=$ctrl->resolveCorrection(1,$role);
    $ok($a['code']==='FIX_PARTIAL' && in_array(2,$a['skipped'],true) && $a['merged'][2]===$d['normal'][2] && $a['rescued']===[1],'keep first on still-invalid presentation row');

    foreach ([0,1] as $slot) foreach (['incomplete','late','invalid SHA'] as $variant) {
        $db($first,$healthy);$row=\R15Table::$tables['baganriki_ai_attempts'][$slot];
        if ($variant==='incomplete') $row->complete=0;
        if ($variant==='late') $row->received_at='2099-01-01 00:00:00.000001';
        if ($variant==='invalid SHA') $row->response_hash=str_repeat('0',64);
        $d=$ctrl->inspectAiResponse(1,$role,$slot);$a=$ctrl->resolveCorrection(1,$role);
        $ok(!$d['ok'] && !$d['accepted'] && !$d['normal'] && $a['code']===($slot===0?'FIRST_NOT_USABLE':'FIX_NOT_USABLE'),"$variant attempt $slot held");
        $ok($slot===0?$a['merged']===[]:$a['merged']===$ctrl->inspectAiResponse(1,$role,0)['normal'],"previous eligible rows only");
        if ($variant==='incomplete') $ok($d['code']==='INCOMPLETE_RESPONSE' && !$d['needs_fix'] && $d['normal_kept']!==[],'retain incomplete diagnostics only');
        if ($variant==='invalid SHA') $ok($d['code']==='RESPONSE_HASH_MISMATCH' && !$d['needs_fix'],'corrupt saved response cannot request a paid fix');
    }
    foreach ([$o,$sparse] as $out) {
        $raw=\r3frame($out);$db($raw,$raw);
        $snap=\R15Table::$tables['baganriki_snapshots'][0];
        $otherCol=$role==='1st'?'input_json_2nd':'input_json_1st';
        $snap->$otherCol='{}';
        $a=$ctrl->resolveCorrection(1,$role);$use=$ctrl->markResponseUsed(1,$role,$a);
        $ok($use['band_ranks']===$out['band_ranks'] && $use['public_candidates']===$out['public_candidates'],'eligible ranks despite damaged other role');
        $ok(count($use['proof']??[])===16 && count(array_unique($use['proof']))===1 && $use['proof'][1]===hash('sha256',$raw),'proof uses exact healthy role receipt');
        $ownCol=$role==='1st'?'input_json_1st':'input_json_2nd';$snap->$ownCol='{}';
        $use=$ctrl->markResponseUsed(1,$role,$a);
        $ok(!$use['band_ranks'] && !$use['public_candidates'] && $use['proof']===null,'own damaged input fails closed on replay');
    }
    $db($healthy,$healthy);
    \R151Schema::$record=true;
    try {
        $old=BaganrikiDiagnose::VERSION;
        \R15Table::$tables['baganriki_validation_results']=[(object)['id'=>1,'attempt_id'=>1,'validator_version'=>$old,'normal_part'=>'{"old":true}']];
        $ok($ctrl->normalizedPartOf(1)['code']==='NO_ROW','older validation is not current');
        $d=$ctrl->inspectAiResponse(1,$role);$ctrl->inspectAiResponse(1,$role);
        $audit=\R15Table::$tables['baganriki_validation_results'];
        $ok(count($audit)===2 && $audit[1]->validator_version===Inspection::REVISION && $d['validator_version']===Inspection::REVISION,'two versions retained and re-read is idempotent');
        $ok(count($ctrl->normalizedPartOf(1)['value'])===16 && $ctrl->normalizedPartOf(1,$old)['value']===['old'=>true],'version-specific saved normal readback');
    } finally { \R151Schema::$record=false; }
    return $n;
}

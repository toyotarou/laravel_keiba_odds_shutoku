<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Services\Baganriki\CandidateSelectionW2 as Current;
use App\Services\Baganriki\FrozenInputW2 as StrictInput;
use App\Services\Baganriki\ScoreMaterialsW2 as Materials;

(function(string $dir):void {
    $count=0;$check=static function($ok,$name)use(&$count){yes($ok,'R14 actual diagnostic '.$name);++$count;};
    $diagnose=static fn($out,$in)=>BaganrikiDiagnose::run($out,$in);
    $code=static fn($d,$name)=>in_array($name,array_column($d['findings'],'code'),true);
    $project=static function(array $out,array $in):array {
        $calc=Current::apply($in,$out['horses'],$out['band_ranks']);
        foreach (['selection_ranks','expected_reviews','public_candidates'] as $k) $out[$k]=$calc[$k];
        foreach ($out['horses'] as &$horse) {
            $keep=in_array($horse['horse_no'],$calc['public_candidates'],true);$horse['public_candidate']=$keep;
            $reason=$horse['eligibility']==='ELIGIBLE'?'BAND_QUOTA':$horse['eligibility'];
            $horse['nonselect_reason']=$keep?null:['code'=>$reason,'reason'=>'順位と根拠を確認'];
        }
        return $out;
    };
    foreach (['1st','2nd'] as $role) {
        $in=StrictInput::decodeForInspection(file_get_contents("$dir/{$role}_input.json"));
        $out=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
        $raw=BaganrikiResponse::BEGIN."\n".json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)."\n".BaganrikiResponse::END;
        $parsed=\App\Services\Baganriki\ResponseFrameW2::extract($raw);
        $accepted=BaganrikiResponse::checkEnvelope($parsed['json'],$in);
        $isolated=BaganrikiResponse::isolateHorses($parsed['json']['horses'],array_column($in['runners'],'horse_no'));
        $check($parsed['ok'] && $accepted['ok'] && count($isolated['kept'])===16 && !$isolated['isolated'],'normal inspection stages before diagnosis');
        $d=$diagnose($out,$in);$check($d['ok'] && !$d['findings'] && count(array_filter($d['row_valid']))===16,'actual full healthy role with E swaps');
        $legacy=$in;$legacy['prompt_version']=Materials::R13;
        $check($code($diagnose($out,$legacy),'PC_ORDER'),'historical R13 keeps its original routing');
        $bad=$out;$bad['selection_ranks']['D']=$out['band_ranks']['D'];
        $d=$diagnose($bad,$in);$check(!$d['ok'] && $code($d,'W14_SELECTION_RANKS'),'manufactured final band rejected');
        $bad=$out;$bad['expected_reviews']['D']['decision']='KEEP';$d=$diagnose($bad,$in);
        $check(!$d['ok'] && $code($d,'W14_EXPECTED_REVIEW'),'manufactured boundary reason');
        $bad=$out;$bad['public_candidates']=[];foreach(Current::BANDS as $b)
            array_push($bad['public_candidates'],...array_slice($out['band_ranks'][$b],0,$in['role_quota'][$b]));
        foreach ($bad['horses'] as &$h) {
            $h['public_candidate']=in_array($h['horse_no'],$bad['public_candidates'],true);
            $h['nonselect_reason']=$h['public_candidate']?null:['code'=>'BAND_QUOTA','reason'=>'旧順'];
        }unset($h);
        $d=$diagnose($bad,$in);$check(!$d['ok'] && $code($d,'W14_PUBLIC_ORDER'),'pre-swap candidate list and self-consistent wrong flags');
        $delta=array_merge(array_diff($bad['public_candidates'],$out['public_candidates']),array_diff($out['public_candidates'],$bad['public_candidates']));sort($delta);
        $check($d['repair_scope']['presentation_horses']===$delta,'only affected flags granted');
        $bad['selection_ranks']=$out['band_ranks'];$bad['expected_reviews']['D']['decision']='KEEP';
        $d=$diagnose($bad,$in);$check($code($d,'W14_PUBLIC_ORDER') && $code($d,'W14_EXPECTED_REVIEW') && $code($d,'W14_SELECTION_RANKS'),'all three projection claims changed together');
        $bad=$out;$bad['public_candidates']=array_reverse($out['public_candidates']);
        $d=$diagnose($bad,$in);$check($code($d,'W14_PUBLIC_ORDER') && !$d['repair_scope']['presentation_horses'],'same horses but wrong order');
        $short=$out;$h=&$short['horses'][0];$h['eligibility']=$h['tier']='UNVERIFIED';
        $h['rank_basis']='NOT_RANKED';$h['evidence']=$h['evidence_rank_evidence_ids']=[];$h['family_review']['ability']=$h['family_review']['gap'];unset($h);
        foreach(['band_ranks','evidence_band_ranks'] as $k) array_shift($short[$k]['A']);
        $short=$project($short,$in);$check($diagnose($short,$in)['ok'],'supported rank rule excludes unverified horse');
        $check($code($diagnose($short,$legacy),'BAND_SET'),'historical R13 old baseline preserved');
        $bad=$short;$bad['band_ranks']['A'][]=1;$check($code($diagnose($bad,$in),'BAND_SET'),'ineligible horse inserted in ranks');
        $bad=$short;array_shift($bad['evidence_band_ranks']['A']);$check($code($diagnose($bad,$in),'BAND_SET'),'eligible horse missing in backup ranks');
        $bad=$out;$bad['horses'][0]['family_review']['gap']=['status'=>'NO_SUPPORT','evidence_ids'=>[],'source_refs'=>['h1_win_odds_6'],'reason'=>'未評価'];
        $d=$diagnose($bad,$in);$check($code($d,'W14_NO_SUPPORT_MATERIAL') && $d['repair_scope']['summary_horses']===[1] && !$d['repair_scope']['horses'],'isolated review while eligibility otherwise supported');
        $bad=$short;$bad['horses'][0]['family_review']['gap']=['status'=>'NO_SUPPORT','evidence_ids'=>[],'source_refs'=>['h1_win_odds_6'],'reason'=>'未評価'];
        $d=$diagnose($bad,$in);$check($d['repair_scope']['horses']===[1] && $d['repair_scope']['presentation_horses']===[1,2,3,4,5,6] && !$d['repair_scope']['rank_bands'],'targeted readmission and display correction confined to base band');
        $enriched=\r12ScoreInput($in);$err='h1_win_final_odds_error';$enriched['source_registry'][$err]['slot_offset']=21;
        $bad=$out;$bad['horses'][0]['scores']['V4']=6;$bad['horses'][0]['score_evidence']['V4']=['reason'=>'異なる予測時点','source_refs'=>['h1_estimated_final_odds',$err]];
        $bad['horses'][0]['observed_cap']=$bad['horses'][0]['observed_sum']=6;
        $d=$diagnose($bad,$enriched);$check(($d['bad_scores'][1]??null)===['V4'],'only wrong forecast score is marked');
        $sal=salvaged($bad['horses'][0],$enriched,1,$d['bad_scores'][1]);$check($sal['scores']['V4']===null && $sal['evidence']===$out['horses'][0]['evidence'],'local forecast salvage retains observed evidence');
        $enriched['prompt_version']=Materials::R13;$check(empty($diagnose($bad,$enriched)['bad_scores'][1]),'saved R13 horizon rule preserved');
        $complete=\r12ScoreInput($in);$complete['snapshot_hash']=BaganrikiPrompt::commonHash($complete)['hash'];$all=$out;$all['snapshot_hash']=$complete['snapshot_hash'];
        foreach ($all['horses'] as &$horse) {
            if ($horse['band']!=='A') continue;
            $horse['scores']=Current::scoreCaps();$horse['score_status']='complete';$horse['total_score']=$horse['observed_cap']=$horse['observed_sum']=100;
            $horse['rank_basis']='SCORE';$r=\r12Refs($horse['horse_no']);
            foreach ($r as $k=>$ids) $horse['score_evidence'][$k]=['reason'=>'検証済み資料','source_refs'=>$ids];
        }unset($horse);
        $all=$project($all,$complete);$check($diagnose($all,$complete)['ok'],'complete score ties retain correct deterministic ordering');
        $all['band_ranks']['A']=array_reverse($all['band_ranks']['A']);$d=$diagnose($all,$complete);
        $check($code($d,'W14_BASE_ORDER') && $d['repair_scope']['rank_bands']===['A'],'wrong deterministic ordering grants only the affected band');
        $bound=StrictInput::decodeForInspection('{"prompt_version":"'.Current::VERSION.'","v":0.999999999999999999999}');
        $check($bound['v'] instanceof BaganrikiDecimal && $bound['v']->decimal()==='0.999999999999999999999','preserve exact saved value near range boundary');
        $old=StrictInput::decodeForInspection('{"prompt_version":"'.Materials::R13.'","v":0.999999999999999999999}');
        $check($old['v']===1.0 && StrictInput::decodeForInspection('null')===null,'old tree and rejected non-object case');
    }
    echo ','.$count;
})($argv[3]);

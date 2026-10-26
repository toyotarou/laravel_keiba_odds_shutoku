<?php
use App\Support\Baganriki\Decimal;
use App\Http\Controllers\Api\BaganrikiDiagnose;
$decimal='0.12345678901234567890123456789';
$src=['prompt_version'=>'test','schema_version'=>'test','race_id'=>'20261024-4-5-7-1',
      'ai_role'=>'1st','snapshot_hash'=>'fixture-hash','mode'=>'test','mode_status'=>'test',
      'allocation_mode'=>'test','mode_policy_id'=>'test','target_count'=>1,'role_cap'=>1,
      'quota'=>['A'=>1,'B'=>0,'D'=>0],'role_quota'=>['A'=>1,'B'=>0,'D'=>0],
      'learning_stage'=>'test','selection_enabled'=>true,'runners'=>[['horse_no'=>1,'band'=>'A']],
      'source_registry'=>[]];
$h=['horse_no'=>1,'band'=>'A','tier'=>'UNVERIFIED','eligibility'=>'NO_EVIDENCE',
    'family_review'=>[],'evidence'=>[['value'=>new Decimal($decimal),'empty_object'=>new stdClass(),'empty_list'=>[]]],
    'counterevidence'=>[],'scores'=>[],'score_evidence'=>[],'score_status'=>'partial','total_score'=>null,
    'observed_sum'=>0,'observed_cap'=>0,'rank_basis'=>'NOT_RANKED','rank_reason'=>'No evidence',
    'public_candidate'=>false,'nonselect_reason'=>['code'=>'NO_EVIDENCE','reason'=>'No evidence'],
    'explanation'=>'Fixture','evidence_rank_evidence_ids'=>[],'evidence_rank_reason'=>'No evidence'];
foreach(BaganrikiDiagnose::FAMILIES as $k) $h['family_review'][$k]=['status'=>'DATA_MISSING','source_refs'=>[]];
foreach(BaganrikiDiagnose::SCORE_CAP as $k=>$cap) { $h['scores'][$k]=null; $h['score_evidence'][$k]=['source_refs'=>[],'reason'=>'Not observed']; }
$res=$src; unset($res['runners'],$res['source_registry']);
$res+=['horses'=>[$h],'band_ranks'=>['A'=>[1],'B'=>[],'D'=>[]],
       'evidence_band_ranks'=>['A'=>[1],'B'=>[],'D'=>[]],'public_candidates'=>[],'shortfalls'=>[],'validation_issues'=>[]];

return [$src,$res];

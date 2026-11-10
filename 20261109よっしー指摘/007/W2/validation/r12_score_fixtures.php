<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiPrompt as WireR12;
use App\Services\Baganriki\ReturnStatsW2 as StatsR12;

function r12Record(array $in,string $ticket='PLACE'):array {
    return ['source_id'=>'synthetic-return-v1-'.$ticket,'ticket'=>$ticket,'odds_slot'=>'6','period_start'=>'2025-01-01','period_end'=>'2026-10-15',
        'horse_nos'=>array_column($in['runners'],'horse_no'),'return_pct'=>'84.25','sample_count'=>40,'available_at'=>$in['input_freeze_at']];
}
function r12Refs(int $n):array {
    $stem='h'.$n.'_';$win=$stem.'win_odds_6';$lo=$stem.'place_min_6';$past=$stem.'past_performance_None';
    $v3='v3_'.hash('sha256','synthetic-return-v1-PLACE').'_';
    return ['H1'=>[$past],'H2'=>[$stem.'win_odds_21',$win],'H3'=>['r12_gap_'.min($n,15)],
        'H4'=>[$stem.'similar_top3_rate',$stem.'similar_sample_count'],'H5'=>[$win,$lo],
        'V1'=>[$stem.'opi'],'V2'=>[$past,$win],'V3'=>[$v3.'rate',$v3.'count'],
        'V4'=>[$stem.'estimated_final_odds',$stem.'win_final_odds_error'],'V5'=>[$lo,$stem.'place_max_6']];
}
function r12ScoreInput(array $in):array {
    $add=static function(array $e)use(&$in){
        $in['source_registry'][$e['source_ref']]=$e;
        $in['field_catalog'][$e['field']]=['unit'=>$e['unit'],'scope'=>$e['scope'],'value_type'=>'number','display_label'=>$e['field'],'missing_allowed'=>false,'minimum'=>0,'maximum'=>null];
        $in['input_indices'][WireR12::indexKey($e)][]=$e['source_ref'];
    };
    foreach ($in['runners'] as $row) {
        $n=$row['horse_no'];$stem='h'.$n.'_';$seed=$in['source_registry'][$stem.'win_odds_6'];
        foreach (['opi'=>['ratio',1.1],'estimated_final_odds'=>['odds',12],'win_final_odds_error'=>['odds',1.5],
            'similar_top3_rate'=>['percent',22.5],'similar_sample_count'=>['count',40]] as $f=>[$unit,$val]) {
            $id=$stem.$f;$key=str_starts_with($f,'similar_')?$stem.'similar_cohort':$id;
            $add(array_replace($seed,['field'=>$f,'source_ref'=>$id,'dependency_key'=>$key,'unit'=>$unit,'value'=>$val,'dependency_role'=>'CONTEXT']));
        }
        if ($n===16) continue;
        $next=$in['source_registry']['h'.($n+1).'_win_odds_6'];$id='r12_gap_'.$n;
        $add(array_replace($seed,['scope'=>'PAIR','horse_nos'=>[$n,$n+1],'source_ref'=>$id,'field'=>'fixed_gap_ratio','unit'=>'ratio','dependency_key'=>$id,
            'source_kind'=>'DERIVED','formula_id'=>'ratio_12dp','formula_version'=>'1','computed_at'=>$in['input_freeze_at'],
            'parents'=>[$seed['source_ref'],$next['source_ref']],'value'=>round($next['value']/$seed['value'],12)]));
    }
    return StatsR12::attach($in,[r12Record($in)])['common'];
}

function r12Current(array $base):array {
    $current=['race_id'=>$base['race_id'],'N'=>$base['N'],'post_at'=>$base['time_contract']['anchor_at'],'input_freeze_at'=>$base['input_freeze_at'],
        'jurisdiction'=>'JRA','surface'=>'DIRT','race_type'=>'FLAT','class_group'=>'C1','distance_metres'=>1800,'roster'=>[],'batches'=>[]];
    foreach ($base['runners'] as $h) $current['roster'][]=['horse_no'=>$h['horse_no'],'horse_id'=>'H'.$h['horse_no']];
    foreach ([6,21] as $slot) {
        $s=$base['collection_audit'][$slot];$quotes=[];
        foreach ($current['roster'] as $h) {$id=$base['input_indices']['horse/'.$h['horse_no'].'/win_odds/'.$slot][0];$quotes[]=['horse_id'=>$h['horse_id'],'win'=>$base['source_registry'][$id]['value']];}
        $current['batches'][$slot]=['batch_id'=>$s['batch_id'],'requested_at'=>$s['request_started_at'],'received_at'=>$s['response_received_at'],'available_at'=>$s['available_at'],'quotes'=>$quotes];
    }
    return $current;
}

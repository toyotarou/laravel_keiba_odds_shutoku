<?php
declare(strict_types=1);

/** The synthetic fixture has these three material groups. Availability is not positive support. */
function r1519Reviews(array $input,array $output):array
{
    foreach ($output['horses'] as &$horse) {
        $no=$horse['horse_no'];
        $groups=['ability'=>['h'.$no.'_past_performance_None'],
            'gap'=>['r12_gap_'.min($no,15)],
            'similar_value'=>['h'.$no.'_similar_top3_rate','h'.$no.'_similar_sample_count'],
            'market'=>['h'.$no.'_win_odds_21','h'.$no.'_win_odds_6'],
            'win_place'=>['h'.$no.'_win_odds_6','h'.$no.'_place_min_6']];
        // Some historical cases deliberately lack the 21-minute quote but still
        // contain a complete 12/6-minute series. These are materials, not support.
        foreach (['win_odds','place_min','place_max'] as $field) {
            $series=[];foreach ([6,21,12,9,15,18] as $slot) {
                $ref='h'.$no.'_'.$field.'_'.$slot;
                if (($input['source_registry'][$ref]['value']??null)!==null) $series[]=$ref;
            }
            if (count($series)>=2) {$groups['market']=array_slice($series,0,2);break;}
        }
        foreach ($groups as $family=>$refs) {
            if ($horse['family_review'][$family]['status']!=='DATA_MISSING') continue;
            $ready=true;foreach ($refs as $ref) if (($input['source_registry'][$ref]['value']??null)===null) $ready=false;
            if ($ready) $horse['family_review'][$family]=['status'=>'NO_SUPPORT','source_refs'=>$refs,'evidence_ids'=>[],
                'reason'=>'合成入力に比較資料はあるが、この回答では肯定根拠として採用しない'];
        }
    }
    unset($horse);
    return $output;
}

<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiDecimal;

/** Minimum materials for a known H/V score. FrozenInputW2 has checked types, lineage and dates. */
final class ScoreMaterialsW2
{
    private static function numeric(mixed $value):bool
    {
        return is_int($value) || (is_float($value) && is_finite($value)) || $value instanceof BaganrikiDecimal;
    }

    private static function ability(string $field):bool
    {
        return preg_match('/^(?:ability_(?:base|distance|track|pace|rotation|course)_score|past_(?:performance|best_finish|avg_finish|top3_rate)|past_(?:finish|time_diff|last_3f|last3f|speed_index)(?:_[1-9][0-9]*)?)$/D',$field)===1;
    }

    public const PREVIOUS='baganriki-v041-r12-2-w2-e35-r12-20261104';

    public const R13='baganriki-v041-r12-2-w2-e35-r13-20261104';

    public const R14='baganriki-v041-r12-2-w2-e35-r14-20261104';

    public static function usesR14Rules(mixed $version):bool
    {
        return $version===self::R14 || $version===CandidateSelectionW2::VERSION;
    }

    public static function handlesVersion(mixed $version):bool
    {
        return $version===self::PREVIOUS || self::handlesFamilies($version);
    }

    public static function handlesFamilies(mixed $version):bool
    {
        return $version===self::R13 || self::usesR14Rules($version);
    }

    /** Use the original decimal on precise inputs, including values close to an endpoint. */
    private static function inRange(mixed $value,int $minimum,?int $maximum=null):bool
    {
        if ($value instanceof BaganrikiDecimal) {
            $decimal=$value->decimal();
            return SchemaW2::decimals($decimal,(string)$minimum)>=0 &&
                ($maximum===null || SchemaW2::decimals($decimal,(string)$maximum)<=0);
        }
        return $value>=$minimum && ($maximum===null || $value<=$maximum);
    }

    private static function abilityObservation(array $e,mixed $version):bool
    {
        if ($e['source_kind']!=='OBSERVED' || $e['scope']!=='HORSE' || !self::ability($e['field']) || !self::numeric($e['value'])) return false;
        if (self::usesR14Rules($version)) {
            if ($e['field']==='past_avg_finish') return $e['unit']==='rank' && self::inRange($e['value'],1);
            if ($e['field']==='past_top3_rate') {
                $ceiling=['percent'=>100,'ratio'=>1][$e['unit']]??null;
                return $ceiling!==null && self::inRange($e['value'],0,$ceiling);
            }
        }
        if ($version===self::PREVIOUS || !preg_match('/^past_(?:best_finish|finish(?:_[1-9][0-9]*)?)$/D',$e['field'])) return true;
        $v=$e['value'];
        if ($e['unit']!=='rank') return false;
        if ($v instanceof BaganrikiDecimal) return preg_match('/^[1-9][0-9]*$/D',$v->decimal())===1;
        return $v>0 && (is_int($v) || floor($v)===$v);
    }

    /** A verified two-horse comparison of the same ability measurement includes that horse's own observation. */
    private static function abilityPair(array $reg,array $e,int $no,mixed $version):bool
    {
        if ($e['scope']!=='PAIR' || $e['formula_id']!=='ratio_12dp' || count($e['parents'])!==2 || !in_array($no,$e['horse_nos'],true)) return false;
        [$a,$b]=array_map(static fn($id)=>$reg[$id],$e['parents']);
        return self::abilityObservation($a,$version) && self::abilityObservation($b,$version) &&
            $a['field']===$b['field'] && $a['unit']===$b['unit'] && $a['horse_nos']!==$b['horse_nos'] &&
            in_array($a['horse_nos'][0],$e['horse_nos'],true) && in_array($b['horse_nos'][0],$e['horse_nos'],true);
    }

    private static function adjacent(array $in,array $e,int $no):bool
    {
        if ($e['scope']!=='PAIR' || $e['formula_id']!=='ratio_12dp' || count($e['parents'])!==2 ||
            !in_array($no,$e['horse_nos'],true)) return false;
        [$a,$b]=array_map(static fn($ref)=>$in['source_registry'][$ref],$e['parents']);
        $rank=array_column($in['runners'],'win_rank','horse_no');
        if ($a['scope']!=='HORSE' || $b['scope']!=='HORSE' || $a['source_kind']!=='OBSERVED' || $b['source_kind']!=='OBSERVED' ||
            !in_array($a['field'],['win_odds','place_min'],true) || $b['field']!==$a['field'] ||
            $a['slot_offset']!==$b['slot_offset'] || $e['slot_offset']!==$a['slot_offset'] ||
            !in_array($a['slot_offset'],$in['time_contract']['snapshot_offsets'],true)) return false;
        [$left,$right]=[$a['horse_nos'][0],$b['horse_nos'][0]];
        return $e['horse_nos']===[$left,$right] && isset($rank[$left],$rank[$right]) && $rank[$right]===$rank[$left]+1;
    }

    // Bit 1 identifies a usable observation. Bit 2 identifies a participating, personally applicable SIGNAL.
    private static function joined(array $left,array $right):int
    {
        $result=0;
        foreach ($left as $slot=>$a) if (isset($right[$slot])) $result|=$a|$right[$slot];
        return $result;
    }

    private static function series(array $prices):int
    {
        if (count($prices)<2) return 0;
        return in_array(3,$prices,true)?3:1;
    }

    public static function supports(array $in,int $no,string $key,array $refs,array &$cache,bool $forFamily=false,bool $requireSignal=true):bool
    {
        $version=$in['prompt_version']??null;$forecast=[];$errors=[];
        $reg=$in['source_registry'];$prices=[];$fields=[];$stats=[];$ability=0;$gap=0;$seen=[];$pending=[];$explicit=[];
        foreach ($refs as $id) {
            $direct=$forFamily && $reg[$id]['dependency_role']==='SIGNAL';
            $pending[]=[$id,$direct];
            if ($direct && $reg[$id]['value']!==null && SourceEvidenceW2::signalKeys($reg,$id,$cache,$no)) $explicit[$id]=true;
        }
        while ($pending) {
            [$id,$direct]=array_pop($pending);
            if (isset($seen[(int)$direct][$id])) continue;
            $seen[(int)$direct][$id]=true;$e=$reg[$id];$v=$e['value'];$f=$e['field'];
            if (!self::numeric($v)) continue;
            $mask=$direct && SourceEvidenceW2::signalKeys($reg,$id,$cache,$no)?3:1;
            if ($forFamily && $key==='H1' && self::abilityPair($reg,$e,$no,$version)) $ability|=$mask;
            if ($key==='H3' && self::adjacent($in,$e,$no)) {
                // A CONTEXT ratio can be corroborated by its explicitly cited own-horse SIGNAL parent.
                $gap|=array_intersect_key($explicit,array_flip($e['parents']))?3:$mask;
            }
            if ($e['source_kind']==='DERIVED' && $e['formula_id']==='identity') {
                $pending[]=[$e['parents'][0],$direct];continue;
            }
            if (($key==='H2' && $e['formula_id']==='shortening_12dp') || ($key==='H5' && $e['formula_id']==='ratio_12dp'))
                foreach ($e['parents'] as $parent) $pending[]=[$parent,$direct];
            $own=$e['scope']==='HORSE' && $e['horse_nos']===[$no];
            $group=$e['scope']==='COHORT' && in_array($no,$e['applicable_horse_nos'],true);
            if ((!$own && !$group) || $e['source_kind']!=='OBSERVED') continue;
            $value=$v instanceof BaganrikiDecimal?$v->decimal():$v;
            $nonnegative=$v instanceof BaganrikiDecimal ? SchemaW2::decimals($value,'0')>=0 : $v>=0;
            $withinPercent=$v instanceof BaganrikiDecimal ? SchemaW2::decimals($value,'100')<=0 : $v<=100;
            // At R14 the quote horizon is part of a forecast/error pair. A past measurement can have an earlier observed_at.
            $slot=$e['slot_offset'];
            $horizon=self::usesR14Rules($version) ? $slot : 0;
            $matchedHorizon=!self::usesR14Rules($version) || in_array($slot,$in['time_contract']['snapshot_offsets'],true);
            if ($matchedHorizon && $nonnegative && in_array($f,['win_final_odds_error','place_final_odds_error'],true) && $e['unit']==='odds')
                $errors[$f][$horizon]=1;
            if ($own && self::abilityObservation($e,$version)) $ability|=$mask;
            if ($own && in_array($f,['win_odds','place_min','place_max'],true) &&
                in_array($e['slot_offset'],$in['time_contract']['snapshot_offsets'],true) && ExpectedPacketW2::oddsParts($v)!==null) {
                $slot=$e['slot_offset'];$prices[$f][$slot]=($prices[$f][$slot]??0)|$mask;
            }
            if (in_array($f,['similar_top3_rate','similar_top5_rate','similar_sample_count'],true)) {
                $owners=$own?[$no]:$e['applicable_horse_nos'];sort($owners,SORT_NUMERIC);
                $bucket=serialize([$e['dependency_key'],$e['scope'],$owners,$e['slot_offset'],ExpectedPacketW2::utc(ExpectedPacketW2::instant($e['observed_at']))]);
                if ($f==='similar_sample_count' && $e['unit']==='count' && is_int($v) && $v>0) $stats[$bucket]['count']=true;
                if ($f!=='similar_sample_count' && $e['unit']==='percent' && $nonnegative && $withinPercent)
                    $stats[$bucket]['rate']=($stats[$bucket]['rate']??0)|$mask;
            }
            if ($own && ExpectedPacketW2::oddsParts($v)!==null) {
                if (in_array($f,['opi','fuku_opi','estimated_opi'],true) && $e['unit']==='ratio')
                    $fields[$f]=($fields[$f]??0)|$mask;
                if ($matchedHorizon && in_array($f,['estimated_final_odds','estimated_final_place_min'],true) && $e['unit']==='odds')
                    $forecast[$f][$horizon]=1;
            }
        }
        $similar=0;foreach ($stats as $s) if (isset($s['count'],$s['rate'])) $similar|=$s['rate'];
        $win=$prices['win_odds']??[];$lo=$prices['place_min']??[];$hi=$prices['place_max']??[];
        $opi=($fields['opi']??0)|($fields['fuku_opi']??0)|($fields['estimated_opi']??0);
        $material=match($key) {
            'H1'=>$ability, 'H2'=>self::series($win)|self::series($lo)|self::series($hi),
            'H3'=>$gap, 'H4'=>$similar, 'H5'=>self::joined($win,$lo)|self::joined($win,$hi),
            'V1'=>$opi, 'V2'=>(int)($ability && ($opi || isset($win[6]) || isset($lo[6]) || isset($hi[6]))),
            'V4'=>self::joined($forecast['estimated_final_odds']??[],$errors['win_final_odds_error']??[]) |
                  self::joined($forecast['estimated_final_place_min']??[],$errors['place_final_odds_error']??[]),
            'V5'=>self::joined($lo,$hi)|$similar, default=>0,
        };
        return (bool)($material & ($forFamily && $requireSignal?2:1));
    }
}

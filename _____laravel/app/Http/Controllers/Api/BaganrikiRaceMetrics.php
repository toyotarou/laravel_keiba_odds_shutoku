<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

final class BaganrikiRaceMetrics
{
    public const REVISION='COMPACT_TEXT_20261109_04';
    public const POLICY='RACE_METRICS_V1';
    public const KEYS=['wave_level','lower_entry','big_gap_entry'];
    public const LABELS=['wave_level'=>'波乱度','lower_entry'=>'下位進入度','big_gap_entry'=>'大穴進入度'];

    /** @ANCHOR-20261116-PREDICTION-TEXT05  3指標を返す本文版（なる11/15 別紙01【必須1】）。_04 の保存済みの予想も、今までどおり検証・表示します。未知の版は許可しません */
    // public const REVISIONS=['COMPACT_TEXT_20261109_04','COMPACT_TEXT_20261109_05'];   // 旧（_06 の前）
    public const REVISIONS=['COMPACT_TEXT_20261109_04','COMPACT_TEXT_20261109_05','COMPACT_TEXT_20261109_06'];   // 20261116 _06 も3指標を返す

    /**
     * @ANCHOR-20261124-TEXT07-SERVER-METRICS  3指標をサーバが計算する本文版（なる 11/24 問1 A）。
     *   この版では、1st の本文・付録から3指標の出力要求を外してあります。AI の応答に race_metrics があっても、読まずに捨てます
     *   （検査しない・不通過にしない・保存しない）。_04〜_06 の保存済み予想は、上の REVISIONS で今までどおり検査・再現します。
     */
    public const SERVER_COMPUTED_REVISIONS=['COMPACT_TEXT_20261124_07'];

    public static function serverComputed(array $in):bool
    {
        return in_array($in['prompt_text_revision']??null,self::SERVER_COMPUTED_REVISIONS,true);
    }

    public static function enabled(array $in):bool
    {
        // return ($in['prompt_text_revision']??null)===self::REVISION;   // 旧（_04 だけ）
        return in_array($in['prompt_text_revision']??null,self::REVISIONS,true);
    }

    /** Validate one assessment independently so an invalid neighbour cannot erase it. */
    public static function entry(array $in,string $key,mixed $row,?array $map=null):array
    {
        if (!is_array($row) || count($row)!==3 || array_diff(['value','source_refs','reason'],array_keys($row)))
            throw new \InvalidArgumentException('EXPECTED_METRIC_SHAPE');
        $v=$row['value'];$refs=$row['source_refs'];
        // Raw responses must retain the strict decoder's object/array and exact-number identity.
        // PHP associative decoding alone makes {"0":"ref"} indistinguishable from ["ref"].
        if ($map!==null) {
            $path=['race_metrics',$key];
            if (BaganrikiOutputSchema::scalar($row,$map,$path)[0]!=='object' ||
                BaganrikiOutputSchema::scalar($refs,$map,[...$path,'source_refs'])[0]!=='array' ||
                BaganrikiOutputSchema::scalar($row['reason'],$map,[...$path,'reason'])[0]!=='string')
                throw new \InvalidArgumentException('EXPECTED_METRIC_JSON_TYPE');
            [$type,$decimal]=BaganrikiOutputSchema::scalar($v,$map,[...$path,'value']);
            if ($type==='number' && preg_match('/^[1-5]$/D',$decimal)===1) $v=(int)$decimal;
            elseif ($type!=='null') throw new \InvalidArgumentException('EXPECTED_METRIC_RANGE');
            foreach ($refs as $index=>$ref)
                if (BaganrikiOutputSchema::scalar($ref,$map,[...$path,'source_refs',$index])[0]!=='string')
                    throw new \InvalidArgumentException('EXPECTED_METRIC_JSON_TYPE');
        }
        // JSON Schema's integer includes numeric 3.0; normalize it without accepting "3" or true.
        if (is_float($v) && is_finite($v) && $v>=1 && $v<=5 && floor($v)===$v) $v=(int)$v;
        if ($v!==null && (!is_int($v) || $v<1 || $v>5)) throw new \InvalidArgumentException('EXPECTED_METRIC_RANGE');
        if (!BaganrikiText::visible($row['reason']) || mb_strlen($row['reason'],'UTF-8')>500)
            throw new \InvalidArgumentException('EXPECTED_METRIC_REASON');
        if (!is_array($refs) || !BaganrikiExpectedPacket::isList($refs) || count($refs)>128)
            throw new \InvalidArgumentException('EXPECTED_METRIC_REFS');
        $seen=[];$quotes=[];$ranks=array_column($in['runners'],'win_rank','horse_no');
        foreach ($refs as $ref) {
            if (!is_string($ref) || isset($seen[$ref])) throw new \InvalidArgumentException('EXPECTED_METRIC_REFS');
            $seen[$ref]=true;$e=$in['source_registry'][$ref]??null;
            if (!is_array($e) || ($e['race_id']??null)!==$in['race_id'] || ($e['value']??null)===null)
                throw new \InvalidArgumentException('EXPECTED_METRIC_SOURCE');
            // Only the adopted, current-race, horse-owned odds series is a numeric rating basis.
            if (($e['scope']??null)!=='HORSE' || count($e['horse_nos']??[])!==1 ||
                !in_array($e['field']??null,['win_odds','place_min','place_max'],true) ||
                !in_array($e['slot_offset']??null,[21,18,15,12,9,6],true) || ($e['unit']??null)!=='odds' ||
                ($e['source_kind']??null)!=='OBSERVED')
                throw new \InvalidArgumentException('EXPECTED_METRIC_SOURCE');
            $n=$e['horse_nos'][0];
            if (!isset($ranks[$n])) throw new \InvalidArgumentException('EXPECTED_METRIC_SOURCE');
            $parts=BaganrikiExpectedPacket::oddsParts($e['value']);
            $odd=$parts===null?null:array_slice($parts,0,2);
            if ($odd===null) throw new \InvalidArgumentException('EXPECTED_METRIC_SOURCE');
            $slot=$e['slot_offset'];$field=$e['field'];
            if (isset($quotes[$n][$field][$slot]) && $quotes[$n][$field][$slot]!==$odd)
                throw new \InvalidArgumentException('EXPECTED_METRIC_AMBIGUOUS');
            $quotes[$n][$field][$slot]=$odd;
        }
        if ($v!==null) {
            $eligible=[];
            foreach ($quotes as $n=>$series) {
                $rank=$ranks[$n];
                if (($key==='lower_entry' && ($rank<7 || $rank>10)) || ($key==='big_gap_entry' && $rank<11)) continue;
                // At least one same-horse series must include the frozen 6-minute point and an earlier point.
                foreach ($series as $field=>$slots) if (isset($slots[6]) && count($slots)>=2) $eligible[$n]=true;
            }
            if (!$eligible || ($key==='wave_level' && count($eligible)<2))
                throw new \InvalidArgumentException('EXPECTED_METRIC_COVERAGE');
            if ($key==='big_gap_entry' && $v>=3) {
                $continuous=false;
                foreach (array_keys($eligible) as $n) foreach (['place_min','place_max'] as $field) {
                    $s=$quotes[$n][$field]??[];
                    if (isset($s[12],$s[9],$s[6]) && self::compareOdd($s[12],$s[9])>0 && self::compareOdd($s[9],$s[6])>0)
                        $continuous=true;
                }
                if (!$continuous) throw new \InvalidArgumentException('EXPECTED_METRIC_CONTINUITY');
            }
        }
        return ['value'=>$v,'source_refs'=>$refs,'reason'=>$row['reason']];
    }

    private static function compareOdd(array $a,array $b):int
    {
        return ($a[0]<=>$b[0]) ?: strcmp($a[1],$b[1]);
    }

    public static function issues(array $in,array $reply,?array $map=null):array
    {
        $has=array_key_exists('race_metrics',$reply);
        // @ANCHOR-20261124-TEXT07-SERVER-METRICS  サーバ計算の版の 1st：race_metrics があっても読まずに捨てる（指摘にしない）
        if (self::serverComputed($in) && ($in['ai_role']??null)==='1st') return [];
        if (!self::enabled($in) || ($in['ai_role']??null)!=='1st') return $has?['race_metrics'=>'EXPECTED_METRIC_ROLE_OR_REVISION']:[];
        $rows=$reply['race_metrics']??null;$issues=[];
        if (!is_array($rows) || array_diff(array_keys($rows),self::KEYS)) $issues['race_metrics']='EXPECTED_METRIC_SHAPE';
        if ($map!==null && $has && BaganrikiOutputSchema::scalar($rows,$map,['race_metrics'])[0]!=='object')
            $issues['race_metrics']='EXPECTED_METRIC_JSON_TYPE';
        foreach (self::KEYS as $key) {
            try { self::entry($in,$key,is_array($rows)?($rows[$key]??null):null,$map); }
            catch (\InvalidArgumentException $e) { $issues[$key]=$e->getMessage(); }
        }
        return $issues;
    }

    public static function unknown(array $in):array
    {
        return ['policy_id'=>self::POLICY,'snapshot_hash'=>$in['snapshot_hash'],'response_hash'=>null,
            'values'=>array_fill_keys(self::KEYS,null),'status'=>array_fill_keys(self::KEYS,'UNAVAILABLE'),'assessments'=>[]];
    }

    /** Input and original-response hash must already have passed the caller's identity/receipt checks. */
    public static function fromResponse(array $in,array $reply,string $hash,?array $map=null):array
    {
        $out=self::unknown($in);
        if (!self::enabled($in) || ($in['ai_role']??null)!=='1st') return $out;
        foreach (['race_id','snapshot_hash','ai_role','prompt_version','schema_version'] as $key)
            if (($reply[$key]??null)!==$in[$key]) return $out;
        if (preg_match('/^[a-f0-9]{64}$/D',$hash)!==1) return $out;
        $out['response_hash']=$hash;$issues=self::issues($in,$reply,$map);
        foreach (self::KEYS as $key) {
            if (isset($issues[$key]) || isset($issues['race_metrics'])) { $out['status'][$key]='INVALID';continue; }
            $row=self::entry($in,$key,$reply['race_metrics'][$key],$map);
            $out['values'][$key]=$row['value'];$out['status'][$key]=$row['value']===null?'UNKNOWN':'VALID';
            $out['assessments'][$key]=$row;
        }
        return $out;
    }

    /** A normal first assessment is immutable even when a different horse needs correction. */
    public static function checkCorrection(array $in,?array $first,array $fix,?array $firstMap=null,?array $fixMap=null):void
    {
        if (!self::enabled($in) || ($in['ai_role']??null)!=='1st' || $first===null) return;
        $issues=self::issues($in,$first,$firstMap);
        foreach (self::KEYS as $key) {
            if (isset($issues[$key])) continue;
            $old=self::entry($in,$key,$first['race_metrics'][$key],$firstMap);
            try { $new=self::entry($in,$key,$fix['race_metrics'][$key]??null,$fixMap); }
            catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('EXPECTED_METRIC_CORRECTION_SCOPE',0,$e); }
            if ($old!==$new) throw new \InvalidArgumentException('EXPECTED_METRIC_CORRECTION_SCOPE');
        }
    }

    /** A repaired display field is useful even if another display field remains invalid. */
    public static function repairedKeys(array $in,array $first,array $fix,array $firstMap,array $fixMap):array
    {
        if (!self::enabled($in) || ($in['ai_role']??null)!=='1st') return [];
        self::checkCorrection($in,$first,$fix,$firstMap,$fixMap);
        $before=self::issues($in,$first,$firstMap);$after=self::issues($in,$fix,$fixMap);
        if (isset($after['race_metrics'])) return [];
        return array_values(array_filter(self::KEYS,static fn($key)=>
            (isset($before[$key]) || isset($before['race_metrics'])) && !isset($after[$key])));
    }

    /** Check publication-bound metadata before exposing the three small public values. */
    public static function publicValues(array $in,mixed $stored):array
    {
        $bad=static function():void { throw new \InvalidArgumentException('PUBLICATION_DATA_INVALID'); };
        if (!is_array($stored) || ($stored['policy_id']??null)!==self::POLICY || ($stored['snapshot_hash']??null)!==$in['snapshot_hash']) $bad();
        $values=$stored['values']??null;$status=$stored['status']??null;$hash=$stored['response_hash']??null;
        if (!is_array($values) || count($values)!==3 || !is_array($status) || count($status)!==3 ||
            ($hash!==null && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D',$hash)!==1))) $bad();
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key,$values) || !isset($status[$key])) $bad();
            $v=$values[$key];$s=$status[$key];
            if (!in_array($s,['VALID','UNKNOWN','INVALID','UNAVAILABLE'],true) ||
                ($v!==null && (!is_int($v) || $v<1 || $v>5)) || (($v!==null)!==($s==='VALID'))) $bad();
            if (in_array($s,['VALID','UNKNOWN'],true)) {
                if ($hash===null) $bad();
                try { $entry=self::entry($in,$key,$stored['assessments'][$key]??null); }
                catch (\InvalidArgumentException $e) { $bad(); }
                if ($entry['value']!==$v) $bad();
            }
        }
        return array_combine(array_values(self::LABELS),array_map(static fn($key)=>$values[$key],self::KEYS));
    }
}

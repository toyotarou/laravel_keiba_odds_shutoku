<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use InvalidArgumentException;
require_once __DIR__.'/ExpectedPacketW2.php';
require_once __DIR__.'/DerivedValueW2.php';

/** Reproduce the frozen r12.2 market classification and its evidence from the same race. */
final class MarketStateW2
{
    private const WEIGHTS=['X1'=>2,'X2'=>1,'X3'=>1,'X4'=>1,'X5'=>1,'X6'=>1,'Z'=>-2];
    private const SLOTS=[21,18,15,12,9,6];
    private static function need(bool $ok,string $code):void { if (!$ok) throw new InvalidArgumentException($code); }

    private static function atLeast(array $values,int $required=1):?bool
    {
        $yes=0; $unknown=0;
        foreach ($values as $v) { if ($v===true) ++$yes; elseif ($v===null) ++$unknown; }
        if ($yes >= $required) return true;
        return $yes+$unknown<$required ? false : null;
    }

    private static function product(int $factor,array $terms):?array
    {
        $d=(string)$factor; $exp=0;
        foreach ($terms as $term) {
            if ($term===null) return null;
            $digits=rtrim($term[1],'0');
            $d=DerivedValueW2::multiply($d,$digits); $exp+=$term[0]-strlen($digits);
        }
        return [strlen($d)+$exp,$d];
    }
    private static function cmp(?array $a,?array $b):?int
    {
        if ($a===null || $b===null) return null;
        if ($a[0]!==$b[0]) return $a[0]<=>$b[0];
        $n=max(strlen($a[1]),strlen($b[1]));
        return strcmp(str_pad($a[1],$n,'0'),str_pad($b[1],$n,'0'))<=>0;
    }
    /** left >= right. All terms are exact positive decimals, or null for missing. */
    private static function ge(int $kl,array $left,int $kr,array $right):?bool
    {
        $c=self::cmp(self::product($kl,$left),self::product($kr,$right));
        return $c===null?null:$c>=0;
    }
    private static function inverse(?bool $v):?bool { return $v===null?null:!$v; }

    /** Rows use frozen win_rank and original horse number, with four time series indexed by slot. */
    public static function derive(array $rows):array
    {
        usort($rows,static fn($a,$b)=>$a['rank']<=>$b['rank']);
        $n=count($rows); self::need($n>=1 && $n<=18,'W3_MARKET_N');
        $fields=['win21','win6','place21','place9','place6'];
        $x1=$x2=$x3=$x4=$x5=$z=[]; $last=null; $nos=[]; $ranked=[]; $complete=true;
        foreach ($rows as $i=>&$r) {
            $no=$r['no'];self::need($r['rank']===$i+1 && is_int($no) && $no>=1 && $no<=18 && !isset($nos[$no]),'W3_MARKET_ROSTER');
            $nos[$no]=true;
            foreach ($fields as $f) {
                $v=$r[$f]??null; $r[$f]=$v===null?null:ExpectedPacketW2::oddsParts($v);
                self::need($v===null || $r[$f]!==null,'W3_ODDS_VALUE');
            }
            $win=$r['win6'];
            if ($win===null) $complete=false;
            else { self::need($last===null || self::cmp($last,$win)<=0,'W3_FROZEN_WIN_ORDER');$last=$win; $ranked[$no]=$win; }
            if ($i>=6) $x4[]=self::atLeast([self::ge(95,[$r['place21']],100,[$r['place6']]),self::ge(97,[$r['place9']],100,[$r['place6']])]);
            if ($i===0) continue;
            $u=$rows[$i-1];
            $w21=self::ge(1,[$r['win21']],2,[$u['win21']]);
            $w6=self::ge(1,[$win],2,[$u['win6']]);
            $p6=self::ge(2,[$r['place6']],3,[$u['place6']]);
            if ($i<=6) {
                $shrink=self::ge(4,[$r['win21'],$u['win6']],5,[$win,$u['win21']]);
                $x1[]=self::atLeast([$w21,self::atLeast([self::inverse($w6),$shrink])],2);
            }
            if ($i<=10) $x2[]=self::atLeast([$w6,self::inverse($p6)],2);
            if ($i>=3 && $i<=6) $x3[]=self::inverse(self::ge(2,[$win],3,[$u['win6']]));
            if ($i>=7 && $i<=10) $x5[]=$w6;
            if ($i<=5) $z[]=self::atLeast([$w21,$w6,$p6],3);
        }
        unset($r);
        $rankMap=array_fill_keys(array_keys($nos),null);
        if ($complete) {
            $order=array_keys($ranked);
            usort($order,static fn($a,$b)=>self::cmp($ranked[$a],$ranked[$b]) ?: ($a<=>$b));
            foreach ($order as $i=>$no) $rankMap[$no]=$i+1;
        }
        $signals=['X1'=>self::atLeast($x1),'X2'=>self::atLeast($x2),'X3'=>$n<7?false:self::atLeast($x3,4),
            'X4'=>self::atLeast($x4,2),'X5'=>self::atLeast($x5),'X6'=>$n>=7?null:false,'Z'=>self::atLeast($z,2)];
        return ['signals'=>$signals,'formula_ranks'=>$rankMap]+self::classify($signals,$n);
    }

    private static function mode(int $s):string { return $s<=1?'FIRM':($s<=3?'MIXED':'VOLATILE'); }
    private static function stars(int $s):int { return $s<=0?1:($s===1?2:($s<=3?3:($s<=5?4:5))); }

    public static function classify(array $signals,int $n):array
    {
        self::need(count($signals)===count(self::WEIGHTS) && $n>=1 && $n<=18,'W3_SIGNAL_SET');
        $fixed=0;$lo=0;$hi=0;$unknown=[];
        foreach (self::WEIGHTS as $k=>$weight) {
            self::need(array_key_exists($k,$signals) && (is_bool($signals[$k])||$signals[$k]===null),'W3_SIGNAL_SET');
            if ($signals[$k]===true) $fixed+=$weight;
            if ($signals[$k]===null) { $unknown[]=$k;$lo+=min(0,$weight);$hi+=max(0,$weight); }
        }
        self::need($signals['X6']===($n>=7?null:false),'W3_X6_STAGE');
        $lo+=$fixed;$hi+=$fixed;$status='UNRESOLVED';$allocation=null;$mode='UNKNOWN';$policy='BASE_TRI_STATE_V1';
        if (self::mode($lo)===self::mode($hi)) { $status='CONFIRMED';$allocation=$mode=self::mode($lo); }
        elseif ($unknown===['X6'] && ($fixed===1 || $fixed===3)) {
            $status='PROVISIONAL';$allocation=self::mode($hi);$policy='X6_ONLY_UPPER_MODE_V1';
        }
        return ['S'=>$unknown?null:$fixed,'S_min'=>$lo,'S_max'=>$hi,'mode'=>$mode,'mode_status'=>$status,
            'allocation_mode'=>$allocation,'mode_policy_id'=>$policy,'selection_enabled'=>$allocation!==null,
            'display_rating'=>$status==='CONFIRMED' && self::stars($lo)===self::stars($hi)?self::stars($lo):null];
    }

    /** Collect each published quote only from its owner, slot, adopted batch and freeze time. */
    public static function verify(array $in):string
    {
        $utc=static fn($v)=>ExpectedPacketW2::instant($v);
        self::need(($in['learning_stage']??null)==='COLLECT_ONLY' && array_key_exists('model_version',$in) && $in['model_version']===null,'W3_LEARNING_STAGE');
        $tc=$in['time_contract']; $post=$utc($tc['anchor_at']);$freeze=$utc($in['input_freeze_at']);
        $sales=$utc($tc['sales_close_at']);
        self::need($post->modify('-330 seconds')==$freeze && $post->modify('-360 seconds')==$utc($in['cutoff_at']) &&
            $sales->modify('-60 seconds')==$utc($tc['publish_deadline']) && $sales->modify('-90 seconds')==$utc($tc['reply_deadline']),'W3_TIME_CONTRACT');
        $rows=[];$odds=[];
        foreach ($in['runners'] as $h) $rows[$h['horse_no']]=['no'=>$h['horse_no'],'rank'=>$h['win_rank']];
        $adopted=[];$batches=[];$book=[];
        foreach (self::SLOTS as $slot) {
            $a=$in['collection_audit'][$slot]??null;
            self::need(is_array($a) && in_array($a['status']??null,['ADOPTED','UNAVAILABLE'],true),'W3_BATCH');
            if ($a['status']==='UNAVAILABLE') { $batches[$slot]=null;continue; }
            $start=$post->modify("-$slot minutes");$request=$utc($a['request_started_at']);$received=$utc($a['response_received_at']);$available=$utc($a['available_at']);
            self::need($utc($a['slot_scheduled_at'])==$start && $start<=$request && $request<=$received && $received<=$available &&
                $available<=$freeze && $available<=$start->modify($slot===6?'+30 seconds':'+120 seconds'),'W3_BATCH_TIME');
            if ($a['source_updated_at']!==null) self::need($utc($a['source_updated_at'])<=$received,'W3_PROVIDER_FUTURE');
            $adopted[$slot]=[$received,$available,$a['response_received_at'],$a['available_at']];
            $batches[$slot]=[$a['batch_id'],$a['payload_hash'],ExpectedPacketW2::utc($request),ExpectedPacketW2::utc($received),
                ExpectedPacketW2::utc($available),$a['source_updated_at']===null?null:ExpectedPacketW2::utc($utc($a['source_updated_at']))];
        }
        $fields=['win_odds'=>'win','place_min'=>'place','place_max'=>'max'];
        foreach ($in['source_registry'] as $ref=>$e) {
            $ref=(string)$ref;
            if (!isset($fields[$e['field']??''])) continue;
            self::need(($e['scope']??null)==='HORSE' && is_int($e['slot_offset']??null),'W3_ODDS_SOURCE');
            $slot=$e['slot_offset'];$no=$e['horse_nos'][0]??null;$field=$e['field'];$index="horse/$no/$field/$slot";
            self::need(is_int($no) && isset($rows[$no]) && $e['horse_nos']===[$no] && $e['race_id']===$in['race_id'] &&
                $e['source_ref']===(string)$ref && ($e['unit']??null)==='odds' && ($e['source_kind']??null)==='OBSERVED' &&
                is_int($slot) && isset($adopted[$slot]) && ($in['input_indices'][$index]??null)===[$ref],'W3_ODDS_SOURCE');
            [$at,$ready,$rawAt,$rawReady]=$adopted[$slot];
            self::need(($e['observed_at']===$rawAt || $utc($e['observed_at'])==$at) &&
                ($e['available_at']===$rawReady || $utc($e['available_at'])==$ready),'W3_ODDS_TIME');
            $val=$e['value']; self::need($val===null || ExpectedPacketW2::oddsParts($val)!==null,'W3_ODDS_VALUE');
            $odds[$index]=$ref;
            $book[$index]=[$ref,ExpectedPacketW2::canonicalOdd($val)];
            $rows[$no][$fields[$field].$slot]=$val;
        }
        foreach ($in['input_indices'] as $index=>$refs) {
            if (preg_match('~^horse/[^/]+/(?:win_odds|place_min|place_max)/[^/]+$~D',(string)$index)!==1) continue;
            self::need($refs===[] || (array_key_exists($index,$odds) && $refs===[$odds[$index]]),'W3_ODDS_INDEX');
        }
        foreach ($rows as $row) foreach (self::SLOTS as $slot) {
            if (isset($row['place'.$slot],$row['max'.$slot])) self::need(
                self::cmp(ExpectedPacketW2::oddsParts($row['place'.$slot]),ExpectedPacketW2::oddsParts($row['max'.$slot]))<=0,'W3_PLACE_RANGE');
        }
        $calc=self::derive(array_values($rows));$declared=$in['signals'];$signals=$calc['signals'];ksort($declared);ksort($signals);
        self::need($declared===$signals,'W3_MARKET_SIGNALS');
        foreach (['S','S_min','S_max','mode','mode_status','allocation_mode','mode_policy_id','selection_enabled','display_rating'] as $key)
            self::need(array_key_exists($key,$in) && $in[$key]===$calc[$key],'W3_MARKET_STATE_'.$key);
        if (isset($in['expected_v01'])) foreach ($in['expected_v01']['rows'] as $e) {
            $no=$e['horse_no'];self::need(array_key_exists($no,$calc['formula_ranks']) && $e['formula_rank']===$calc['formula_ranks'][$no],'W3_FORMULA_RANK_BINDING');
            if ($calc['formula_ranks'][$no]===null || ($rows[$no]['win21']??null)===null)
                self::need(in_array($e['status'],['UNAVAILABLE_INPUT','OUT_OF_SCOPE'],true),'W3_SCORE_WITHOUT_ODDS');
        }
        $ranks=array_column($in['runners'],'win_rank','horse_no');ksort($ranks,SORT_NUMERIC);ksort($book,SORT_STRING);
        $source=['W8_FROZEN_MARKET_V1',$in['race_id'],ExpectedPacketW2::utc($freeze),$ranks,$batches,$book];
        return hash('sha256',json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}

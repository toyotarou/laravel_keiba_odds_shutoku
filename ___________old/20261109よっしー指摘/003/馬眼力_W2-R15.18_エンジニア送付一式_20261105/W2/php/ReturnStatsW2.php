<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use App\Http\Controllers\Api\BaganrikiPrompt;

/** Turns a versioned, pre-race DB aggregate into a pair of observed facts. */
final class ReturnStatsW2
{
    private const OFFSETS = ['S','21','18','15','12','9','6','3','FINAL'];
    private const KEY = '~^v3\|(WIN|PLACE)\|(S|21|18|15|12|9|6|3|FINAL)\|(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})\|([a-f0-9]{64})$~D';

    private static function dateOk(string $s): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$s,$p) === 1 && checkdate((int)$p[2],(int)$p[3],(int)$p[1]);
    }

    private static function day(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
    }

    private static function spec(string $ticket): array
    {
        $stem = 'v3_'.strtolower($ticket);
        return ['rate'=>[$stem.'_return_pct','percent'], 'count'=>[$stem.'_sample_count','count']];
    }

    private static function number(mixed $v): int|float|null
    {
        if (is_int($v) || is_float($v)) return $v>=0 && is_finite((float)$v) ? $v : null;
        if (!is_string($v) || !preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D',$v)) return null;
        // Reject DB decimals that the transport would round to a different value.
        $parts=explode('.',$v); $digits=ltrim($parts[0],'0')?:'0'; $fraction=rtrim($parts[1]??'','0');
        $canonical=$digits.($fraction===''?'':'.'.$fraction);
        if ($fraction==='') { $i=(int)$canonical; return (string)$i===$canonical ? $i : null; }
        $f=(float)$canonical;
        return ExpectedPacketW2::canonicalOdd($f)===ExpectedPacketW2::canonicalOdd($canonical) ? $f : null;
    }

    /** Return rows use one canonical group namespace and can never act as a horse SIGNAL. */
    public static function reserved(string $field): bool { return str_starts_with($field,'v3_'); }

    /** Before hashing the common input. Only an identified, versioned DB row may supply these values. */
    public static function attach(array $common, array $records): array
    {
        $audit=['included'=>0,'skipped'=>[],'sources'=>[]]; $seen=[]; $byId=[];
        foreach ($records as $r) {
            $id=is_array($r)?($r['source_id']??null):null;
            if (!is_string($id) || trim($id)==='' || strlen($id)>512) { $audit['skipped'][]=['reason'=>'NO_SOURCE_ID'];continue; }
            $token=hash('sha256',$id);$seen[$token]=($seen[$token]??0)+1;$byId[$token]=$r;
        }
        ksort($byId,SORT_STRING);
        $roster=array_column($common['runners'],'horse_no');$freeze=ExpectedPacketW2::instant($common['input_freeze_at']);
        $raceDay=self::day(ExpectedPacketW2::instant($common['time_contract']['anchor_at']));
        foreach ($byId as $token=>$r) {
            $why=null;$ticket=$r['ticket']??null;$slot=$r['odds_slot']??null;$from=$r['period_start']??null;$to=$r['period_end']??null;
            $members=$r['horse_nos']??null;$rate=self::number($r['return_pct']??null);$count=self::number($r['sample_count']??null);
            if ($seen[$token]!==1) $why='AMBIGUOUS_SOURCE_ID';
            elseif (!in_array($ticket,['WIN','PLACE'],true) || !in_array($slot,self::OFFSETS,true)) $why='UNKNOWN_TICKET_OR_SLOT';
            elseif (!is_string($from) || !is_string($to) || !self::dateOk($from) || !self::dateOk($to) || $from>$to || $to>=$raceDay) $why='INVALID_PERIOD';
            elseif (!is_int($count) || $count<=0 || $rate===null) $why='INVALID_RATE_OR_COUNT';
            elseif (!is_array($members) || !ExpectedPacketW2::isList($members) || !$members || count(array_filter($members,'is_int'))!==count($members) || count(array_unique($members))!==count($members) || array_diff($members,$roster)) $why='INVALID_MEMBERS';
            if ($why===null) {
                try { $at=ExpectedPacketW2::instant($r['available_at']??null); if ($at>$freeze || self::day($at)<$to) $why='INVALID_AVAILABLE_AT'; }
                catch (InvalidArgumentException $e) { $why='INVALID_AVAILABLE_AT'; }
            }
            if ($why!==null) { $audit['skipped'][]=['source_sha256'=>$token,'reason'=>$why];continue; }
            sort($members,SORT_NUMERIC);$key=implode('|',['v3',$ticket,$slot,$from,$to,$token]);
            $time=ExpectedPacketW2::utc($at);$refs=[];
            foreach (self::spec($ticket) as $part=>[$field,$unit]) {
                $id='v3_'.$token.'_'.$part;$refs[]=$id;
                $entry=['source_ref'=>$id,'race_id'=>$common['race_id'],'value'=>$part==='rate'?$rate:$count,'scope'=>'COHORT','horse_nos'=>[],
                    'applicable_horse_nos'=>$members,'field'=>$field,'unit'=>$unit,'slot_offset'=>null,'observed_at'=>$time,'available_at'=>$time,
                    'computed_at'=>null,'source_kind'=>'OBSERVED','parents'=>[],'formula_id'=>null,'formula_version'=>null,'dependency_key'=>$key,'dependency_role'=>'CONTEXT'];
                if (isset($common['source_registry'][$id]) && $common['source_registry'][$id]!=$entry) throw new InvalidArgumentException('W12_CONFLICTING_RETURN_SOURCE');
                $catalog=['unit'=>$unit,'scope'=>'COHORT','display_label'=>$field,'value_type'=>'number','missing_allowed'=>false,'minimum'=>$part==='rate'?0:1,'maximum'=>null];
                if (isset($common['field_catalog'][$field]) && $common['field_catalog'][$field]!=$catalog) throw new InvalidArgumentException('W12_CONFLICTING_RETURN_FIELD');
                $common['source_registry'][$id]=$entry;$common['field_catalog'][$field]=$catalog;
                $address=BaganrikiPrompt::indexKey($entry); $idx=$common['input_indices'][$address]??[];
                if (!in_array($id,$idx,true)) $idx[]=$id;sort($idx,SORT_STRING);$common['input_indices'][$address]=$idx;
            }
            $old=$common['coverage']['recovery_statistics']['source_refs']??[];$all=array_values(array_unique(array_merge($old,$refs)));sort($all,SORT_STRING);
            $common['coverage']['recovery_statistics']=['status'=>'INCLUDED','source_refs'=>$all];++$audit['included'];
            $audit['sources'][$token]=['source_id'=>$r['source_id'],'ticket'=>$ticket,'odds_slot'=>$slot,'period_start'=>$from,'period_end'=>$to,'horse_nos'=>$members,'available_at'=>$time,'source_refs'=>$refs];
        }
        return ['common'=>$common,'audit'=>$audit];
    }

    /** Every cited ref must participate in a complete rate/count pair from the same frozen row. */
    public static function supports(array $in,int $no,array $refs,int $points): bool
    {
        if (!$refs || count($refs)%2!==0 || count(array_unique($refs))!==count($refs)) return false;
        $coverage=$in['coverage']['recovery_statistics']??[];
        if (($coverage['status']??null)!=='INCLUDED') return false;
        $covered=array_fill_keys($coverage['source_refs'],true);$groups=[];$positive=false;
        foreach ($refs as $id) {
            $e=$in['source_registry'][$id]??null;
            if (!isset($covered[$id]) || !is_array($e) || !preg_match(self::KEY,$e['dependency_key'],$m)) return false;
            if (!self::dateOk($m[3]) || !self::dateOk($m[4]) || $m[3]>$m[4] || $m[4]>=self::day(ExpectedPacketW2::instant($in['time_contract']['anchor_at']))) return false;
            if ($e['scope']!=='COHORT' || !in_array($no,$e['applicable_horse_nos'],true) || $e['source_kind']!=='OBSERVED' || $e['dependency_role']!=='CONTEXT' || $e['slot_offset']!==null || $e['race_id']!==$in['race_id']) return false;
            $at=ExpectedPacketW2::instant($e['available_at']); if ($at!=ExpectedPacketW2::instant($e['observed_at']) || $at>ExpectedPacketW2::instant($in['input_freeze_at']) || self::day($at)<$m[4]) return false;
            $types=self::spec($m[1]);$part=$e['field']===$types['rate'][0]?'rate':'count';
            if ($id!=='v3_'.$m[5].'_'.$part || $e['source_ref']!==$id || [$e['field'],$e['unit']]!==$types[$part]) return false;
            $value=$e['value'];
            if ($value instanceof \App\Http\Controllers\Api\BaganrikiDecimal && $part==='rate') $value=self::number($value->decimal());
            if ($part==='count' ? (!is_int($value) || $value<1) : ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<0)) return false;
            if ($part==='rate' && $value>0) $positive=true;
            $groups[$e['dependency_key']][$part]=[$e['applicable_horse_nos'],ExpectedPacketW2::utc($at)];
        }
        foreach ($groups as $pair) if (!isset($pair['rate'],$pair['count']) || $pair['rate']!==$pair['count']) return false;
        return $points===0 || $positive;
    }
}

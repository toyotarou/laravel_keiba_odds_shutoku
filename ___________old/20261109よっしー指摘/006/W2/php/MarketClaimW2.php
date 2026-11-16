<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use InvalidArgumentException;

/** Numerical claims are typed. A subjective interpretation is never proof of acceleration. */
final class MarketClaimW2
{
    private static function digits(string $decimal):array
    {
        [$whole,$fraction]=array_pad(explode('.',$decimal,2),2,'');
        return [ltrim($whole.$fraction,'0')?:'0',strlen($fraction)];
    }

    public static function accelerating(string $a,string $b,string $c):bool
    {
        if (SchemaW2::decimals($c,'0')<=0 || SchemaW2::decimals($a,$b)<=0 || SchemaW2::decimals($b,$c)<=0) return false;
        [$ad,$as]=self::digits($a);[$bd,$bs]=self::digits($b);[$cd,$cs]=self::digits($c);
        $scale=max(2*$bs,$as+$cs);
        $left=DerivedValueW2::multiply($bd,$bd).str_repeat('0',$scale-2*$bs);
        $right=DerivedValueW2::multiply($ad,$cd).str_repeat('0',$scale-$as-$cs);
        return SchemaW2::decimals($left,$right)>0;
    }

    public static function verify(array $reg,callable $value,array $item,int $no):void
    {
        if ($item['family']!=='market') {
            if (array_key_exists('market_claim',$item)) throw new InvalidArgumentException('W19_MARKET_SCOPE');
            return;
        }
        $claim=$item['market_claim']??null;
        if (!is_array($claim) || count($claim)!==2 || !array_key_exists('kind',$claim) || !array_key_exists('metric',$claim))
            throw new InvalidArgumentException('W19_MARKET_CLAIM');
        if ($claim['kind']==='OTHER') {
            if ($claim['metric']!==null || $item['observation']!=='市場比較（支持加速を根拠にしない）')
                throw new InvalidArgumentException('W19_MARKET_OBSERVATION');
            return;
        }
        if ($claim['kind']!=='ACCELERATION' || !in_array($claim['metric'],['win_odds','place_min','place_max'],true) ||
            $item['observation']!=='支持加速（12→9→6分）') throw new InvalidArgumentException('W19_MARKET_CLAIM');
        $quotes=[];
        foreach ($item['source_refs'] as $ref) {
            $s=$reg[$ref]??null;
            if (!$s || $s['scope']!=='HORSE' || $s['horse_nos']!==[$no] || $s['source_kind']!=='OBSERVED' ||
                $s['field']!==$claim['metric'] || $s['unit']!=='odds' || !in_array($s['slot_offset'],[12,9,6],true)) continue;
            $slot=$s['slot_offset'];$token=$value($ref);
            if (isset($quotes[$slot]) || $token[0]!=='number') throw new InvalidArgumentException('W19_ACCELERATION_QUOTES');
            $quotes[$slot]=$token[1];
        }
        if (count($quotes)!==3 || !self::accelerating($quotes[12],$quotes[9],$quotes[6]))
            throw new InvalidArgumentException('W19_FALSE_ACCELERATION');
    }
}

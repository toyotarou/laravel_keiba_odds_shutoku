<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use InvalidArgumentException;
require_once __DIR__.'/SchemaW2.php';

/** Exact r12 derivations from stored numeric tokens; no optional PHP extensions or float rounding. */
final class DerivedValueW2
{
    // Base-10 multiplication (the submitted r12.2 integer algorithm). No division or float threshold.
    public static function multiply(string $a,string $b):string
    {
        if (strlen($a)+strlen($b)<=18 && PHP_INT_SIZE>=8) return (string)((int)$a*(int)$b);
        $buf=array_fill(0,strlen($a)+strlen($b),0);
        for ($i=strlen($a)-1;$i>=0;--$i) {
            $carry=0;
            for ($j=strlen($b)-1;$j>=0;--$j) {
                $k=$i+$j+1; $v=$buf[$k]+((int)$a[$i])*((int)$b[$j])+$carry;
                $buf[$k]=$v%10; $carry=intdiv($v,10);
            }
            $buf[$i]+=$carry;
        }
        return ltrim(implode('',$buf),'0')?:'0';
    }
    private static function magnitude(string $a,string $b):int
    {
        return (strlen($a)<=>strlen($b)) ?: (strcmp($a,$b)<=>0);
    }

    /** Unsigned a-b, with a >= b. */
    private static function difference(string $a,string $b):string
    {
        $i=strlen($a)-1;$j=strlen($b)-1;$borrow=0;
        for (;$i>=0;--$i,--$j) {
            $digit=(int)$a[$i]-($j>=0?(int)$b[$j]:0)-$borrow;
            $borrow=(int)($digit<0);
            $a[$i]=(string)($digit+10*$borrow);
        }
        return ltrim($a,'0')?:'0';
    }

    private static function parts(string $decimal):array
    {
        [$whole,$frac]=array_pad(explode('.',ltrim($decimal,'-'),2),2,'');
        return [ltrim($whole.$frac,'0')?:'0',strlen($frac)];
    }

    private static function scaled(string $digits,int $zeros):string
    {
        return $digits==='0'?'0':$digits.str_repeat('0',$zeros);
    }

    /** Check whether the supplied decimal is exactly the HALF_UP quotient at 12 decimal places. */
    public static function matches(string $formula,array $parents,array $claimed):bool
    {
        if ($formula==='identity') return count($parents)===1 && $parents[0]===$claimed;
        if (!in_array($formula,['ratio_12dp','shortening_12dp'],true) || count($parents)!==2) return false;
        [$a,$b]=$parents;
        if ($a[0]==='null'||$b[0]==='null') return $claimed===['null',null];
        if ($a[0]!=='number' || $b[0]!=='number' || $claimed[0]!=='number' ||
            $a[1]==='0' || $b[1]==='0' || $a[1][0]==='-' || $b[1][0]==='-') return false;
        [$ad,$as]=self::parts($a[1]);[$bd,$bs]=self::parts($b[1]);$scale=max($as,$bs);
        $den=self::scaled($ad,$scale-$as);$num=self::scaled($bd,$scale-$bs);
        $negative=false;
        if ($formula==='shortening_12dp') {
            $negative=self::magnitude($den,$num)<0;
            $num=$negative?self::difference($num,$den):self::difference($den,$num);
        }
        [$units,$frac]=self::parts($claimed[1]);
        if ($frac>12 || ($units!=='0' && ($claimed[1][0]==='-')!==$negative)) return false;
        $units=self::scaled($units,12-$frac);
        $actual=self::multiply('2',self::scaled($num,12));
        $center=self::multiply('2',self::multiply($units,$den));
        // In absolute units [k-1/2, k+1/2) rounds to k. At a half, move away from zero.
        if (self::magnitude($actual,$center)>=0) return self::magnitude(self::difference($actual,$center),$den)<0;
        return self::magnitude(self::difference($center,$actual),$den)<=0;
    }

    public static function verify(string $id,array $reg,array $map):void
    {
        $entry=$reg[$id];
        if (!in_array($entry['scope'],['HORSE','PAIR'],true) || $entry['formula_version']!=='1')
            throw new InvalidArgumentException('W4_DERIVATION_SCOPE_VERSION');
        $parents=[];
        foreach ($entry['parents'] as $ref) {
            $p=$reg[$ref];
            if (!in_array($p['scope'],['HORSE','PAIR'],true) || array_diff($p['horse_nos'],$entry['horse_nos']))
                throw new InvalidArgumentException('W4_DERIVATION_OWNER');
            $parents[]=SchemaW2::scalar($p['value'],$map,['source_registry',$ref,'value']);
        }
        if (!self::matches($entry['formula_id'],$parents,SchemaW2::scalar($entry['value'],$map,['source_registry',$id,'value'])))
            throw new InvalidArgumentException('W4_DERIVATION_VALUE');
    }
}

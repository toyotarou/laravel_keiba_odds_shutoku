<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** Exact r12 derivations from stored numeric tokens; no optional PHP extensions or float rounding. */
final class BaganrikiDerivedValue
{
    // Base-10 multiplication (the submitted 新方式 integer algorithm). No division or float threshold.
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

    /**
     * サーバが計算した値（10/20版の入力の作り方そのまま）を受け入れる一覧（よっしー11/9【2】A の ②）。
     *   'calc' は計算し直して値が一致するかを確かめるもの（親の値から同じ式・同じ丸めで計算）。
     *   'stat' はDBの集計表から読む統計（回収率・勝率・母数・過去走のまとめ）。式は再計算できないので、
     *         対象の範囲（同じレース・馬）・型・値の範囲・母数（同じ集計グループの sample_count）を確かめます。
     *   一覧にない formula_id・版は止めます（一括で無検査にはしません）。
     */
    private const SERVER_FORMULAS = [
        'ODDS_GAP_PCT_21_TO_6'                   => ['calc', 'HORSE', 'gap_pct'],
        'OPI_AVG_WIN_DIV_WIN6'                   => ['calc', 'HORSE', 'div2'],
        'FUKU_OPI_AVG_PLACE_DIV_PLACE6'          => ['calc', 'HORSE', 'div2'],
        'EST_FINAL_ODDS_WIN6_X_RATIO'            => ['calc', 'HORSE', 'mul2'],
        'EST_FINAL_PLACE_MIN_PLACE6_X_WIN_RATIO' => ['calc', 'HORSE', 'mul2'],
        'EST_OPI_AVG_WIN_DIV_EST_FINAL'          => ['calc', 'HORSE', 'div2'],
        'SummaryOddsGapRecoveryRate'             => ['stat', 'COHORT', null],
        'SummarySimilarRaceStats'                => ['stat', 'COHORT', null],
        'PAST_RUNS_SUMMARY'                      => ['stat', null, null],
    ];

    /**
     * @ANCHOR-20261114-EXPECTED-TABLE-PARENT
     * 親がDBの集計元テーブル名かどうか。
     *   サーバの統計（一覧で 'stat'、版 'v1'、DERIVED）の項目で、親が t_horse_odds_finder_ で始まる
     *   テーブル名のときだけ true。登録IDの親や、計算し直す項目（calc）には当てはめません。
     */
    public static function tableParent(array $e,mixed $parent):bool
    {
        if (!is_string($parent) || preg_match('/^t_horse_odds_finder_[a-z0-9_]+$/D',$parent)!==1) return false;
        if (($e['source_kind']??null)!=='DERIVED' || ($e['formula_version']??null)!=='v1') return false;
        $def=self::SERVER_FORMULAS[$e['formula_id']??'']??null;
        return $def!==null && $def[0]==='stat';
    }

    /** 止めた理由の詳細（凍結の理由に添えて記録する。試験と調査用） */
    public static ?string $lastProblem = null;

    private static function fail(string $id,string $code,string $detail=''):\InvalidArgumentException
    {
        self::$lastProblem=$id.' '.$code.($detail!==''?'（'.$detail.'）':'');
        return new \InvalidArgumentException($code);
    }

    private static function verifyServer(string $id,array $reg,array $map):void
    {
        $e=$reg[$id];
        $def=self::SERVER_FORMULAS[$e['formula_id']??'']??null;
        if ($def===null || ($e['formula_version']??null)!=='v1') throw self::fail($id,'EXPECTED_DERIVATION_SCOPE_VERSION');
        [$kind,$scope,$op]=$def;
        if ($scope!==null && $e['scope']!==$scope) throw self::fail($id,'EXPECTED_DERIVATION_SCOPE_VERSION');
        $v=BaganrikiOutputSchema::scalar($e['value'],$map,['source_registry',$id,'value']);
        foreach ($e['parents'] as $ref) {
            // @ANCHOR-20261114-EXPECTED-TABLE-PARENT  集計元のテーブル名（入力の外の由来）は登録表に無いので、ここでは確かめません
            if (!isset($reg[$ref]) && self::tableParent($e,$ref)) continue;
            $p=$reg[$ref]??null;
            if (!is_array($p) || ($p['race_id']??null)!==($e['race_id']??null)) throw self::fail($id,'EXPECTED_DERIVATION_OWNER');
            if ($e['scope']==='HORSE' && in_array($p['scope'],['HORSE','PAIR'],true) && array_diff($p['horse_nos'],$e['horse_nos']))
                throw self::fail($id,'EXPECTED_DERIVATION_OWNER');
        }
        if ($v[0]==='null') return;                                    // 計算できなかった値（null）はそのまま
        if ($kind==='stat' && in_array($v[0],['string','boolean'],true)) return;   // 過去走のまとめなどの文字・真偽はそのまま（型だけ確認済み）
        if ($v[0]!=='number' || !is_numeric($v[1])) throw self::fail($id,'EXPECTED_DERIVATION_VALUE','数でも文字でもない値：'.$v[0]);
        $x=(float) $v[1];
        if (!is_finite($x)) throw self::fail($id,'EXPECTED_DERIVATION_VALUE');
        if ($kind==='calc') {
            if (count($e['parents'])!==2) throw self::fail($id,'EXPECTED_DERIVATION_VALUE');
            $ps=[];
            foreach ($e['parents'] as $ref) {
                $pv=BaganrikiOutputSchema::scalar($reg[$ref]['value'],$map,['source_registry',$ref,'value']);
                if ($pv[0]!=='number' || !is_numeric($pv[1])) throw self::fail($id,'EXPECTED_DERIVATION_VALUE');
                $ps[]=(float) $pv[1];
            }
            [$a,$b]=$ps;
            if ($op==='gap_pct') { if ($a<=0) throw self::fail($id,'EXPECTED_DERIVATION_VALUE'); $want=round((($b-$a)/$a)*100,4); }
            elseif ($op==='div2') { if ($b<=0) throw self::fail($id,'EXPECTED_DERIVATION_VALUE'); $want=round($a/$b,2); }
            else { $want=round($a*$b,2); }
            if (abs($want-$x)>1e-9) throw self::fail($id,'EXPECTED_DERIVATION_VALUE','計算し直すと '.$want.'、入力は '.$x);
            return;
        }
        // 統計：型と範囲、母数
        $field=(string) $e['field'];
        // 値の範囲：数は0以上の整数、率（回収率以外）は0〜100。それ以外（着差・平均着順など）は型だけ
        if (str_ends_with($field,'_count') && ($x<0 || floor($x)!==$x)) throw self::fail($id,'EXPECTED_DERIVATION_VALUE');
        if (preg_match('/_(win|place|top\d+)_rate$|_rate$/',$field) && !str_contains($field,'recovery') && ($x<0 || $x>100)) throw self::fail($id,'EXPECTED_DERIVATION_VALUE','率が0〜100の外：'.$x);
        if ($e['scope']==='COHORT' && !str_ends_with($field,'_count')) {
            $n=null;
            foreach ($reg as $other) {
                if (($other['dependency_key']??null)===($e['dependency_key']??null) && str_ends_with((string) ($other['field']??''),'_count')) {
                    $c=BaganrikiOutputSchema::scalar($other['value'],$map,['source_registry',(string) $other['source_ref'],'value']);
                    if ($c[0]==='number' && is_numeric($c[1])) { $n=(float) $c[1]; break; }
                }
            }
            // 母数が無い統計は使えない。母数0なら値は0だけを認める
            if ($n===null || ($n<=0 && $x!=0.0)) throw self::fail($id,'EXPECTED_DERIVATION_SAMPLE');
        }
    }

    public static function verify(string $id,array $reg,array $map):void
    {
        $entry=$reg[$id];
        // よっしー11/9【2】A：よっしーの3種類（そのまま・割合・縮小率、版 '1'）は今までどおり計算し直して照合します。
        //   それ以外は、サーバが計算した値（版 'v1'）として、下の一覧にある項目だけを受け入れます（一覧にない計算は止めます）。
        if ($entry['formula_version']!=='1') { self::verifyServer($id,$reg,$map); return; }
        if (!in_array($entry['scope'],['HORSE','PAIR'],true))
            throw new \InvalidArgumentException('EXPECTED_DERIVATION_SCOPE_VERSION');
        $parents=[];
        foreach ($entry['parents'] as $ref) {
            $p=$reg[$ref];
            if (!in_array($p['scope'],['HORSE','PAIR'],true) || array_diff($p['horse_nos'],$entry['horse_nos']))
                throw new \InvalidArgumentException('EXPECTED_DERIVATION_OWNER');
            $parents[]=BaganrikiOutputSchema::scalar($p['value'],$map,['source_registry',$ref,'value']);
        }
        if (!self::matches($entry['formula_id'],$parents,BaganrikiOutputSchema::scalar($entry['value'],$map,['source_registry',$id,'value'])))
            throw new \InvalidArgumentException('EXPECTED_DERIVATION_VALUE');
    }
}

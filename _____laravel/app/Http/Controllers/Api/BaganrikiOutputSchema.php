<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** Evaluates the vocabulary used by the two bundled schemas, keeping the strict decoder's JSON types. */
final class BaganrikiOutputSchema
{
    public static function scalar(mixed $v,array $map,array $path):array
    {
        $key=BaganrikiJson::pathKey($path); $number=$map['num'][$key]??null;
        if ($number!==null) return ['number',$number['decimal']];
        $container=$map['kind'][$key]??null;
        if ($container!==null) return [$container==='obj'?'object':'array',null];
        if (is_string($v)) return ['string',$v];
        if (is_bool($v)) return ['boolean',$v];
        if ($v===null) return ['null',null];
        throw new \InvalidArgumentException('EXPECTED_JSON_TYPE_MAP');
    }

    /** Inputs are normalized, exponent-expanded JSON decimals returned by the submitted strict parser. */
    public static function decimals(string $a,string $b):int
    {
        if ($a===$b) return 0;
        $neg=$a[0]==='-';$other=$b[0]==='-';
        if ($neg!==$other) return $neg?-1:1;
        $left=explode('.',ltrim($a,'-'),2);$right=explode('.',ltrim($b,'-'),2);
        $cmp=(strlen($left[0])<=>strlen($right[0])) ?: strcmp($left[0],$right[0]);
        if ($cmp===0) { $x=$left[1]??'';$y=$right[1]??'';$n=max(strlen($x),strlen($y));$cmp=strcmp(str_pad($x,$n,'0'),str_pad($y,$n,'0')); }
        return $neg?-$cmp:$cmp;
    }

    private static function literal(mixed $expected,mixed $v,array $map,array $path):bool
    {
        [$type,$value]=self::scalar($v,$map,$path);
        if (is_int($expected)) return $type==='number' && self::decimals($value,(string)$expected)===0;
        if (is_array($expected)) {
            if ($type!=='array' || count($v)!==count($expected)) return false;
            foreach ($expected as $i=>$x) if (!self::literal($x,$v[$i],$map,[...$path,$i])) return false;
            return true;
        }
        return in_array($type,['null','boolean','string'],true) && $value===$expected;
    }

    /** Returns the first failing JSON path, or null. The schema is trusted release data. */
    private static function issue(array $s,mixed $v,array &$map,array $path):?array
    {
        [$type,$val]=self::scalar($v,$map,$path);
        if (isset($s['type'])) {
            $types=(array)$s['type'];
            if (!in_array($type,$types,true) && !($type==='number' && in_array('integer',$types,true) && strpos($val,'.')===false)) return $path;
        }
        if (array_key_exists('const',$s) && !self::literal($s['const'],$v,$map,$path)) return $path;
        if (isset($s['enum'])) {
            $found=false; foreach ($s['enum'] as $item) if (self::literal($item,$v,$map,$path)) { $found=true;break; }
            if (!$found) return $path;
        }
        if ($type==='number') {
            if (isset($s['minimum']) && self::decimals($val,(string)$s['minimum'])<0) return $path;
            if (isset($s['maximum']) && self::decimals($val,(string)$s['maximum'])>0) return $path;
        }
        if ($type==='string') {
            if (isset($s['minLength']) && mb_strlen($val,'UTF-8')<$s['minLength']) return $path;
            if (isset($s['maxLength']) && mb_strlen($val,'UTF-8')>$s['maxLength']) return $path;
            if (isset($s['pattern']) && preg_match('~'.$s['pattern'].'~uD',$val)!==1) return $path;
            if (isset($s['format'])) {
                try { BaganrikiExpectedPacket::instant($val); } catch (\Throwable $e) { return $path; }
            }
        }
        if ($type==='array') {
            if (count($v)<($s['minItems']??0) || count($v)>($s['maxItems']??PHP_INT_MAX)) return $path;
            $seen=[];
            foreach ($v as $i=>$child) {
                $where=[...$path,(int)$i];
                if (isset($s['items']) && ($error=self::issue($s['items'],$child,$map,$where))!==null) return $error;
                if (!empty($s['uniqueItems'])) {
                    // Every uniqueItems collection in this release has scalar items.
                    $k=serialize(self::scalar($child,$map,$where));
                    if (isset($seen[$k])) return $where; $seen[$k]=true;
                }
            }
        }
        if ($type==='object') {
            foreach ($s['required']??[] as $k) if (!array_key_exists($k,$v)) return [...$path,$k];
            foreach ($v as $k=>$child) {
                $prop=$s['properties'][$k]??($s['additionalProperties']??true);$where=[...$path,(string)$k];
                if ($prop===false) return $where;
                if (is_array($prop) && ($error=self::issue($prop,$child,$map,$where))!==null) return $error;
            }
        }
        foreach ($s['allOf']??[] as $sub) if (($error=self::issue($sub,$v,$map,$path))!==null) return $error;
        foreach (['anyOf','oneOf'] as $op) if (isset($s[$op])) {
            $matches=0;
            foreach ($s[$op] as $sub) if (self::issue($sub,$v,$map,$path)===null) {
                ++$matches; if ($op==='anyOf') break;
            }
            if ($matches===0 || ($op==='oneOf' && $matches!==1)) return $path;
        }
        if (isset($s['if'])) {
            $branch=self::issue($s['if'],$v,$map,$path)===null?'then':'else';
            if (isset($s[$branch])) return self::issue($s[$branch],$v,$map,$path);
        }
        return null;
    }

    private static function schema(string $kind):array
    {
        static $schemas=[];
        if (!in_array($kind,['input','output','recovery'],true)) throw new \InvalidArgumentException('EXPECTED_SCHEMA_KIND');
        // return $schemas[$kind]??=json_decode(file_get_contents(BaganrikiExpectedFiles::path("schemas/{$kind}_schema.json")),true,512,JSON_THROW_ON_ERROR);   // 旧（〜11/27：版は1つ）
        // @ANCHOR-20261125-E5-SCORE8  E5専用・8項目の版の形は schemas/e5_score8/ に置く。E3・E5版（〜11/27 の凍結分）は今までのファイル
        $dir=BaganrikiCandidateSelection::e5Only(BaganrikiCandidateSelection::contextVersion()) ? 'schemas/e5_score8/' : 'schemas/';
        return $schemas[$dir.$kind]??=json_decode(file_get_contents(BaganrikiExpectedFiles::path("{$dir}{$kind}_schema.json")),true,512,JSON_THROW_ON_ERROR);
    }

    private static function horseSchema():array
    {
        return self::schema('output')['properties']['horses']['items'];
    }

    public static function knownHorseField(string $field):bool
    {
        return array_key_exists($field,self::horseSchema()['properties']);
    }

    /** Collect disjoint field errors for the one allowed correction. Keep the raw JSON type map. */
    public static function outputIssues(array $reply,array $map):array
    {
        return self::outputIssuesWith(self::schema('output'),$reply,$map);
    }

    /**
     * @ANCHOR-20261125-ZIP-ORIGINAL-SHAPE  なるの元ファイル（ZIP）の「回答の形」に照らすと、どこが合わないかを数える（記録だけ。採否には使わない）
     *   豊田さん 11/25：ZIPの形をそのまま採否に使うと、11/21〜23 の 1st の「候補になれる馬」が 466→321 に減り、0頭になるレースも出たので、採否は今の形のまま。
     *   返すもの：['合わない場所（番号は * に置き換え）'=>件数]。E5専用版でないとき・ファイルが無いときは空。
     */
    public static function zipOriginalShapeNotes(array $reply,array $map):array
    {
        if (!BaganrikiCandidateSelection::e5Only(BaganrikiCandidateSelection::contextVersion())) return [];
        static $original=null;
        $file=BaganrikiExpectedFiles::path('schemas/e5_score8/output_schema_zip_original.json');
        if ($original===null) $original=is_readable($file)?json_decode((string)file_get_contents($file),true):false;
        if (!is_array($original)) return [];
        $notes=[];
        foreach (self::outputIssuesWith($original,$reply,$map,$original['properties']['horses']['items']) as $path) {
            $where=preg_replace('/(?<=[\[,])\d+(?=[,\]])/','*',BaganrikiJson::pathKey($path));
            $notes[$where]=($notes[$where]??0)+1;
        }
        arsort($notes);
        return array_slice($notes,0,80,true);
    }

    private static function outputIssuesWith(array $schema,array $reply,array $map,?array $horseSchema=null):array
    {
        $out=[];$horseSchema??=self::horseSchema();
        $fields=static function(array $schema,array $row,array $path,bool $skipRows=false)use(&$out,&$map):void {
            foreach ($schema['required'] as $key) if (!array_key_exists($key,$row)) $out[]=[...$path,$key];
            foreach ($row as $key=>$value) {
                $where=[...$path,(string)$key];$field=$schema['properties'][$key]??null;
                if ($field===null) { $out[]=$where;continue; }
                if ($skipRows && $key==='horses') unset($field['items']);
                $issue=self::issue($field,$value,$map,$where);
                if ($issue!==null) $out[]=$issue;
            }
            foreach ($schema['allOf']??[] as $condition) {
                $issue=self::issue($condition,$row,$map,$path);
                if ($issue!==null && !in_array($issue,$out,true)) $out[]=$issue;
            }
        };
        $fields($schema,$reply,[],true);
        if (is_array($reply['horses']??null) && self::scalar($reply['horses'],$map,['horses'])[0]==='array')
            foreach ($reply['horses'] as $i=>$row) {
                $path=['horses',$i];
                if (self::scalar($row,$map,$path)[0]!=='object') { $out[]=$path;continue; }
                $fields($horseSchema,$row,$path);
            }
        return $out;
    }

    public static function validOriginalHorse(array $value,array $map,array $path):bool
    {
        return self::issue(self::horseSchema(),$value,$map,$path)===null;
    }

    public static function validHorseItem(string $field,mixed $value,array $map,array $path):bool
    {
        return self::issue(self::horseSchema()['properties'][$field]['items'],$value,$map,$path)===null;
    }

    public static function validate(string $kind,array $value,array $map):void
    {
        $bad=self::issue(self::schema($kind),$value,$map,[]);
        if ($bad!==null) throw new \InvalidArgumentException('EXPECTED_SCHEMA_'.$kind.' '.BaganrikiJson::pathKey($bad));
    }
}

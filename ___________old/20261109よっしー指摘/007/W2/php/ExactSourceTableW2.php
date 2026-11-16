<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use InvalidArgumentException;
require_once __DIR__.'/SourceTableV2W2.php';

/** Revision 07 projects JSON type maps along with the V2 values. Older receipts keep their original codec. */
final class ExactSourceTableW2
{
    public const REVISION='COMPACT_TEXT_20261109_07';
    // These columns are encoded as dictionary/prefix references, not copied as source values.
    private const TRANSFORMED=['observed_at','available_at','computed_at','dependency_key'];

    private static function need(bool $ok,string $why):void { if (!$ok) throw new InvalidArgumentException($why); }

    /** Remap only unchanged source cells; never apply the original number map to restored wire values. */
    private static function project(array $map,array $table,bool $packing,bool $derived):array
    {
        $columns=$table['columns'];$refs=[];$positions=[];
        $refColumn=array_search('source_ref',$columns,true);
        foreach ($table['rows'] as $i=>$row) { $refs[$i]=$row[$refColumn];$positions[$row[$refColumn]]=$i; }
        $out=BaganrikiJson::emptyMap();
        foreach (['num','kind'] as $section) foreach ($map[$section]??[] as $key=>$value) {
            $path=json_decode($key,true,512,JSON_THROW_ON_ERROR);$head=$path[0]??null;
            if ($packing && $head==='source_registry') {
                if (count($path)<3) continue; // registry and row objects become the table's row arrays
                $field=$path[2];$column=array_search($field,$columns,true);
                if ($column===false || in_array($field,self::TRANSFORMED,true)) continue;
                $path=['source_table','rows',$positions[$path[1]],$column,...array_slice($path,3)];
            } elseif (!$packing && $head==='source_table') {
                if (count($path)<4 || $path[1]!=='rows') continue;
                $field=$columns[$path[3]];
                if (in_array($field,self::TRANSFORMED,true)) continue;
                $path=['source_registry',$refs[$path[2]],$field,...array_slice($path,4)];
            } elseif (($derived && $head==='input_indices') || $head==='input_indices_derived') continue;
            $out[$section][BaganrikiJson::pathKey($path)]=$value;
        }
        if (!$packing) {
            // Both are required JSON objects in the already-validated frozen schema.
            $out=BaganrikiJson::mapWithKind($out,['source_registry'],'obj');
            foreach ($refs as $ref) $out=BaganrikiJson::mapWithKind($out,['source_registry',$ref],'obj');
            if ($derived) $out=BaganrikiJson::mapWithKind($out,['input_indices'],'obj');
        }
        return $out;
    }

    /** Serialize V2 without losing exponent tokens or object keys that resemble PHP list indices. */
    public static function pack(FrozenInputW2 $frozen):string
    {
        $decoded=BaganrikiJson::decode($frozen->raw());self::need($decoded['ok'],'W24_FROZEN_JSON');
        $wire=SourceTableV2W2::pack(FrozenInputW2::typedValues($decoded));
        $map=self::project($decoded['map'],$wire['source_table'],true,isset($wire['input_indices_derived']));
        return BaganrikiJson::encode($wire,$map);
    }

    /** Reconstruct from the transmitted JSON's own map; this method has no access to the original input. */
    public static function expand(string $raw):string
    {
        $decoded=BaganrikiJson::decode($raw);self::need($decoded['ok'],'W24_WIRE_JSON');
        $wire=FrozenInputW2::typedValues($decoded);$full=SourceTableV2W2::expand($wire);
        $map=self::project($decoded['map'],$wire['source_table'],false,isset($wire['input_indices_derived']));
        return BaganrikiJson::encode($full,$map);
    }

    public static function inputBody(FrozenInputW2 $frozen):string
    {
        $bytes=self::pack($frozen);$restored=self::expand($bytes);
        $same=BaganrikiJson::sameJson($frozen->raw(),$restored,true);
        self::need($same['ok'] && $same['same'],'W24_WIRE_EXACT_LOSS');
        return $bytes;
    }
}

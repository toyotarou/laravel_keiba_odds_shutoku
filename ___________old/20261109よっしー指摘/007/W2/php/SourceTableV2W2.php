<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiPrompt;
use InvalidArgumentException;

/** V2 keeps coverage order and any non-derivable named indices. V1 remains byte-identical. */
final class SourceTableV2W2
{
    public const FORMAT='SOURCE_TABLE_V2';

    private static function need(bool $ok,string $why):void { if (!$ok) throw new InvalidArgumentException($why); }

    public static function pack(array $full):array
    {
        self::need(is_array($full['source_registry']??null) && is_array($full['input_indices']??null) &&
            is_array($full['coverage']??null) && !array_key_exists('source_table',$full) &&
            !array_key_exists('input_indices_derived',$full),'W23_TABLE_INPUT');
        // The V1 registry codec restores row-number parents through PHP array keys.
        // A numeric-string source_ref then becomes an integer. Keep original parent
        // strings in V2 while reusing its column/default/time compaction unchanged.
        $registry=$full['source_registry'];$parents=[];
        foreach ($registry as $ref=>&$entry) {
            self::need(is_array($entry) && is_array($entry['parents']??null),'W23_TABLE_INPUT');
            $parents[(string)$ref]=$entry['parents'];$entry['parents']=[];
        }
        unset($entry);
        $packed=BaganrikiPrompt::compactRegistry($registry);
        self::need(($packed['ok']??false)===true && is_array($packed['table']??null),'W23_TABLE_PACK');
        $refColumn=array_search('source_ref',$packed['table']['columns'],true);
        $parentColumn=array_search('parents',$packed['table']['columns'],true);
        self::need($refColumn!==false && $parentColumn!==false,'W23_TABLE_COLUMNS');
        foreach ($packed['table']['rows'] as &$row) $row[$parentColumn]=$parents[$row[$refColumn]];
        unset($row);
        $wire=$full;unset($wire['source_registry']);
        $wire['source_table']=$packed['table'];$wire['source_table']['encoding']=self::FORMAT;
        $derived=BaganrikiPrompt::indicesFor($full['source_registry']);
        if (BaganrikiPrompt::canonical($derived)===BaganrikiPrompt::canonical($full['input_indices'])) {
            unset($wire['input_indices']);$wire['input_indices_derived']=true;
        }
        // Coverage is small and stays verbatim: no set comparison may reorder a reference list.
        return $wire;
    }

    public static function expand(array $wire):array
    {
        $table=$wire['source_table']??null;
        self::need(is_array($table) && ($table['encoding']??null)===self::FORMAT &&
            !array_key_exists('source_registry',$wire),'W23_TABLE_FORMAT');
        $columns=$table['columns']??null;$rows=$table['rows']??null;
        $required=['source_ref','value','field','horse_nos','applicable_horse_nos','slot_offset',
            'observed_at','available_at','computed_at','parents','dependency_key'];
        $optional=['unit','dependency_role','source_kind','scope','formula_id','formula_version'];
        self::need(is_array($columns) && array_values($columns)===$columns &&
            count(array_filter($columns,'is_string'))===count($columns) &&
            count(array_unique($columns))===count($columns) && !array_diff($required,$columns) &&
            !array_diff($columns,array_merge($required,$optional)) &&
            is_array($rows) && array_values($rows)===$rows && count($rows)>0,'W23_TABLE_COLUMNS');
        self::need(is_array($table['time_dict']??null) &&
            (is_array($table['field_defaults']??null) || ($table['field_defaults']??null) instanceof \stdClass),'W23_TABLE_DICTIONARY');
        $pos=array_search('source_ref',$columns,true);$seen=[];
        foreach ($rows as $row) {
            self::need(is_array($row) && array_values($row)===$row && count($row)===count($columns),'W23_TABLE_ROW');
            $ref=$row[$pos];self::need(is_string($ref) && $ref!=='' && !isset($seen[$ref]),'W23_TABLE_REFS');$seen[$ref]=true;
        }
        $table['encoding']='SOURCE_TABLE_V1';$decoded=BaganrikiPrompt::expandRegistry($table);
        self::need(($decoded['ok']??false)===true && is_array($decoded['registry']??null),'W23_TABLE_EXPAND');
        $full=$wire;unset($full['source_table']);$full['source_registry']=$decoded['registry'];
        if (array_key_exists('input_indices_derived',$wire)) {
            self::need($wire['input_indices_derived']===true && !array_key_exists('input_indices',$wire),'W23_INDEX_MODE');
            unset($full['input_indices_derived']);$full['input_indices']=BaganrikiPrompt::indicesFor($full['source_registry']);
        } else self::need(is_array($wire['input_indices']??null),'W23_INDEX_MODE');
        self::need(is_array($wire['coverage']??null),'W23_COVERAGE');
        foreach ($wire['coverage'] as $entry)
            self::need(is_array($entry) && !array_key_exists('source_fields',$entry),'W23_COVERAGE');
        return $full;
    }
}

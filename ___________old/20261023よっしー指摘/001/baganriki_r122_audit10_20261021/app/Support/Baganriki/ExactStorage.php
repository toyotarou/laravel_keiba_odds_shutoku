<?php
declare(strict_types=1);
namespace App\Support\Baganriki;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fail closed: JSON/DOUBLE columns cannot preserve arbitrary raw JSON decimals. */
final class ExactStorage
{
    public static function assertReady(): void
    {
        foreach (['baganriki_ai_attempts'=>'raw_response','baganriki_validation_results'=>'normal_part'] as $table=>$column) {
            if (!Schema::hasTable($table)) throw new \RuntimeException('EXACT_STORAGE_MISSING: '.$table);
            $row = DB::selectOne(
                'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length FROM INFORMATION_SCHEMA.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [DB::connection()->getTablePrefix().$table,$column]
            );
            if ($row === null || !in_array(strtolower((string)$row->data_type),['mediumtext','longtext'],true)) {
                throw new \RuntimeException('EXACT_STORAGE_UNSAFE: '.$table.'.'.$column.' requires MEDIUMTEXT or LONGTEXT');
            }
        }
    }
}

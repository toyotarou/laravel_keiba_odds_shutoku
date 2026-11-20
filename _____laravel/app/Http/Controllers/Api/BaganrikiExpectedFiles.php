<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * 予想の版（E3・E5を使う版）が読むデータファイルの置き場所。
 *   置き場所  storage/app/baganriki/prompts/expected/（.env の BAGANRIKI_PREDICTION_EXPECTED_DIR で変えられます）
 *   中身      config/contract.json・schemas/*_schema.json・prompts/（よっしーの配布物をそのまま置く）
 *   本文や設定を PHP に書き写さないのは、写し間違いに気づけなくなるからです（10/20版の本文と同じ考え方）。
 */
final class BaganrikiExpectedFiles
{
    public static function dir(): string
    {
        $d = (string) (function_exists('env') ? env('BAGANRIKI_PREDICTION_EXPECTED_DIR', '') : (getenv('BAGANRIKI_PREDICTION_EXPECTED_DIR') ?: ''));
        if ($d !== '') { return rtrim($d, '/'); }
        return rtrim(function_exists('storage_path') ? storage_path('app/baganriki/prompts/expected') : __DIR__ . '/expected', '/');
    }

    public static function path(string $rel): string
    {
        return self::dir() . '/' . ltrim($rel, '/');
    }
}

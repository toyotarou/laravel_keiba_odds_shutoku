<?php
declare(strict_types=1);
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
require_once __DIR__.'/../php/ExpectedBridgeW2.php';

/* Optional developer-side check. Pass a trusted class-only export or an application autoload/bootstrap
 * which ONLY loads the existing BaganrikiPrompt codec; this script calls its pure methods. */
if ($argc!==3) {fwrite(STDERR,"Usage: php verify_source_table.php TRUSTED_CODEC EXAMPLES_DIR\n");exit(2);}
require_once $argv[1];
foreach (['1st','2nd'] as $role) {
    $input=json_decode(file_get_contents($argv[2]."/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
    $pack=static function($d){$r=Codec::compactWire($d);if (!$r['ok'])throw new RuntimeException($r['reason']);return $r['wire'];};
    $expand=static function($d){$r=Codec::expandWire($d);if (!$r['ok'])throw new RuntimeException($r['reason']);return $r['input'];};
    // The checked normal fixtures carry native scalars and no ambiguous empty-object markers.
    $equal=static fn($a,$b)=>Codec::canonical($a)===Codec::canonical($b);
    $wire=Bridge::compact($input,$pack,$expand,$equal);
    $out=Bridge::expand($wire,$expand);
    if (!$equal($out,$input) || !isset($wire['source_table']) || $wire['expected_v01']!==$input['expected_v01'] ||
        Codec::commonHash($input)['hash']!==$input['snapshot_hash']) throw new RuntimeException('W2 transport or hash mismatch');
    printf("PASS %s archived SOURCE_TABLE_V1; %d refs, %d index keys, %d coverage groups, %d expected rows and common hash.\n",
        $role,count($out['source_registry']),count($out['input_indices']),count($out['coverage']),count($out['expected_v01']['rows']));
}

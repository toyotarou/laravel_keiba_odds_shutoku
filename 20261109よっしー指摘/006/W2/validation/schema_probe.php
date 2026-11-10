<?php
declare(strict_types=1);
require_once __DIR__.'/../php/ExpectedBridgeW2.php';
require_once $argv[1];
use App\Http\Controllers\Api\BaganrikiJson;
use App\Services\Baganriki\SchemaW2;
while (($line=fgets(STDIN))!==false) {
    [$kind,$raw]=explode("\t",$line,2);$j=BaganrikiJson::decode($raw);$valid=false;
    if ($j['ok'] && is_array($j['value'])) {
        try { SchemaW2::validate($kind,$j['value'],$j['map']);$valid=true; }
        catch (InvalidArgumentException $e) {}
    }
    echo $valid?"1\n":"0\n";
}

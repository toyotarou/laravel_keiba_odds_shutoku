<?php
declare(strict_types=1);
require_once __DIR__.'/../php/ExpectedBridgeW2.php';
use App\Services\Baganriki\MarketStateW2 as Market;
while (($line=fgets(STDIN))!==false) {
    $input=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    echo Market::verify($input),"\n";
}

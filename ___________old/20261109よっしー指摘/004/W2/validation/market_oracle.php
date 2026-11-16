<?php
declare(strict_types=1);
require_once __DIR__.'/../php/MarketStateW2.php';
use App\Services\Baganriki\MarketStateW2 as Market;
while (($line=fgets(STDIN))!==false) {
    $q=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    try {$value=isset($q['market'])?Market::derive($q['market']):Market::classify($q['signals'],$q['N']);}
    catch (InvalidArgumentException $e) {$value=['error'=>$e->getMessage()];}
    echo json_encode($value,JSON_THROW_ON_ERROR),"\n";
}

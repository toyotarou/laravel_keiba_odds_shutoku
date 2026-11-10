<?php
declare(strict_types=1);
require __DIR__.'/../php/DerivedValueW2.php';
require __DIR__.'/../php/MarketClaimW2.php';
foreach (json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR) as $row)
    echo \App\Services\Baganriki\MarketClaimW2::accelerating(...$row)?"true\n":"false\n";

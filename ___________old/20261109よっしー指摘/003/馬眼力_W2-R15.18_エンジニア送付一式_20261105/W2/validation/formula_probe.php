<?php
declare(strict_types=1);
require_once __DIR__.'/../formula/ExpectedValueV01.php';
while (($line=fgets(STDIN))!==false) {
    $v=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    $a=App\Services\Baganriki\ExpectedValueV01::calculate($v['M'],$v['b3'],$v['b5'],$v['rows'],20.0,$v['cal']);
    $cols=['p3_raw','p5_raw','p3_cal','p5_cal','W','n_eff','support_status'];
    echo json_encode(array_intersect_key($a,array_fill_keys($cols,true)),JSON_THROW_ON_ERROR),"\n";
}

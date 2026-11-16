<?php
declare(strict_types=1);
require_once __DIR__.'/../php/SourceEvidenceW2.php';
use App\Services\Baganriki\SourceEvidenceW2 as Source;
use App\Services\Baganriki\DerivedValueW2 as Derived;
while (($line=fgets(STDIN))!==false) {
    $x=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    if ($x[0]==='formula') $answer=Derived::matches($x[1],$x[2],$x[3]);
    elseif ($x[0]==='components') {
        $entries=[];foreach ($x[1] as $ev) $entries[]=[$ev['family'],array_fill_keys($ev['verified_roots'],true)];
        $answer=Source::independentCount($entries);
    } else {
        $cache=[];$answer=[];foreach ($x[2] as $id) { $keys=array_keys(Source::signalKeys($x[1],$id,$cache));sort($keys,SORT_STRING);$answer[]=$keys; }
    }
    echo json_encode($answer,JSON_THROW_ON_ERROR)."\n";
}

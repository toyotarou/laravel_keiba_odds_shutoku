<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_fixture.php';
use App\Support\Baganriki\Decimal;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
function mutationPaths(array $x,array $prefix=[]): array {
    $out=[];
    foreach($x as $k=>$v) {
        $p=[...$prefix,$k]; $out[]=$p;
        if(is_array($v)) $out=array_merge($out,mutationPaths($v,$p));
    }
    return $out;
}
[$src,$valid]=require __DIR__.'/fixture.php';
$variants=[null,true,7,'invalid-string',[],new stdClass(),new Decimal('1.5'),['bad'=>new stdClass()],[new stdClass()]];
$passed=0;$failed=0;
foreach(mutationPaths($valid) as $path) foreach($variants as $i=>$value) {
    [$src,$res,$c]=resetFixture(); $slot=&$res;
    foreach($path as $key) $slot=&$slot[$key]; $slot=$value; unset($slot);
    try { setRaw($res); $c->r122InspectResponse(1,'1st'); ++$passed; }
    catch(Throwable $e) { ++$failed; echo 'FAIL ',json_encode($path),' variant=',$i,' ',get_class($e),' ',$e->getMessage(),"\n"; }
}
echo "MUTATION RESULT: $passed passed, $failed failed. Invalid JSON field types must not crash inspection; DB/HTTP doubles.\n";
exit($failed===0?0:1);

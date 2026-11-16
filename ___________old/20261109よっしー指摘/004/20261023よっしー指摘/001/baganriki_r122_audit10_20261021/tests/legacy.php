<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$r=App\Http\Controllers\Api\AiController::v04SelfTest();
// Reports must also remain serializable by the existing command/UI consumers.
json_encode($r,JSON_THROW_ON_ERROR);
foreach($r['results'] as $test) if($test['結果']==='FAIL') echo 'FAIL ',$test['id'],' ',$test['確認'],"\n";
echo 'LEGACY RESULT: ',$r['pass'],' passed, ',$r['fail']," failed. No database concurrency exercised.\n";
exit($r['fail'] ? 1 : 0);

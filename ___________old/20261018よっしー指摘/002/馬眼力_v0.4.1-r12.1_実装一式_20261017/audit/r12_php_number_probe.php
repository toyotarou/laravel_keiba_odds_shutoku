<?php
$values=json_decode('[9e308,123456789012345678901234567890123456789,1.000000000000000001]',true,512,JSON_THROW_ON_ERROR);
foreach($values as $value) {echo get_debug_type($value),':';var_export($value);echo "\n";}
try {echo json_encode($values,JSON_THROW_ON_ERROR);}
catch(Throwable $ex) {echo get_class($ex),':',$ex->getMessage(),"\n";}

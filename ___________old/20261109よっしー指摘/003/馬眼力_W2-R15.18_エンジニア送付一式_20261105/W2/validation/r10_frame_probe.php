<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/../php/ResponseFrameW2.php';
use App\Services\Baganriki\ResponseFrameW2 as Frame;
use App\Http\Controllers\Api\BaganrikiJson as Exact;
while (($line=fgets(STDIN))!==false) {
    $q=json_decode($line,true,512,JSON_THROW_ON_ERROR);$raw=base64_decode($q['raw'],true);
    if ($raw===false)throw new RuntimeException('test transport');
    $p=Frame::extract($raw);$o=['ok'=>$p['ok']];
    if ($p['ok']) {
        $o['json']=base64_encode(Exact::encode($p['json'],$p['map']));
        $o['has_numeric_map']=is_array($p['number_paths']) && is_array($p['map']);
    } else {$o['code']=$p['w2_error'];$o['reason']=$p['reason'];}
    echo json_encode($o,JSON_THROW_ON_ERROR)."\n";
}

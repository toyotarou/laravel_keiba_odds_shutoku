<?php
declare(strict_types=1);
require_once __DIR__.'/../php/ExpectedPacketW2.php';
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
$data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$x=Packet::build($data['current'],$data['history'],$data['calibration']);
$near=[];$dist=[];foreach ($x['audit']['horses'] as $no=>$h) {
    $near[$no]=array_column($h['neighbours'],'race_id');
    if (!empty($data['with_distances'])) $dist[$no]=(object)array_column($h['neighbours'],'d2','race_id');
}
echo json_encode(['rows'=>$x['packet']['rows'],'near'=>(object)$near,'excluded'=>$x['audit']['excluded'],
    'partial'=>$x['audit']['partial_history'],'history_sha256'=>$x['packet']['history_sha256'],'dist'=>(object)$dist,'ids'=>$x['audit']['pool_ids'],'proven'=>hash('sha256',$x['audit']['expected_proof']['json'])===$x['audit']['expected_proof']['sha256']],JSON_THROW_ON_ERROR);

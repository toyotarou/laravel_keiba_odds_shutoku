<?php
declare(strict_types=1);
require __DIR__ . '/BaganrikiR12Bridge.php';
$root = dirname(__DIR__);
$python = getenv('BAGANRIKI_PYTHON') ?: 'python3';
$bridge = new \Baganriki\BaganrikiR12Bridge($python, $root, sys_get_temp_dir() . '/baganriki-bridge-test.sqlite');
$response = json_decode($bridge->callRaw(file_get_contents($root . '/demo_replay_request.json')), true, 512, JSON_THROW_ON_ERROR);
if (($response['ok'] ?? false) !== true || in_array(7, $response['result']['public_candidates'], true) || count($response['result']['public_candidates']) !== 5) {
    throw new RuntimeException('Bridge replay failed: ' . json_encode($response));
}
echo json_encode(['php_version'=>PHP_VERSION,'bridge_replay_passed'=>true,'selected'=>$response['result']['public_candidates']], JSON_UNESCAPED_UNICODE), "\n";

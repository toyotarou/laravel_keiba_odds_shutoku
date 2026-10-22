<?php
declare(strict_types=1);
namespace Baganriki;
require_once __DIR__.'/ExactJson.php';

/** PHP 8.0+. Preserve nested decimal JSON as strings; do not decode/re-encode it in PHP. */
final class BaganrikiR12Bridge
{
    public function __construct(private string $python, private string $engineDir, private string $database, private float $timeoutSeconds = 30.0)
    {
        if (!is_file($engineDir . '/cli.py') || $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Invalid engine path or timeout');
        }
    }
    public function prepare(string $seedJson): array
    {
        return $this->call(['action' => 'prepare', 'seed_json' => $seedJson]);
    }
    public function prepareExport(string $exportJson): array
    {
        return $this->call(['action' => 'prepare_export', 'export_json' => $exportJson]);
    }
    public function inspect(string $inputJson, string $text, string $receivedAt): array
    {
        return $this->call(['action' => 'inspect', 'input_json' => $inputJson, 'text' => $text, 'received_at' => $receivedAt]);
    }
    public function install(string $inputJson, int $generation): array
    {
        return $this->call(['action' => 'install', 'input_json' => $inputJson, 'generation' => $generation]);
    }
    public function replay(string $inputJson, array $responses, array $cancelled, string $replayAt): array
    {
        return $this->call(['action' => 'replay', 'input_json' => $inputJson, 'responses' => $responses, 'cancelled' => $cancelled, 'replay_at' => $replayAt]);
    }
    public function publish(string $raceId, int $generation, int $stateRevision, int $publicationRevision, string $jobId, array $responses): array
    {
        return $this->call(['action' => 'publish', 'race_id' => $raceId, 'generation' => $generation, 'state_revision' => $stateRevision, 'publication_revision' => $publicationRevision, 'job_id' => $jobId, 'responses' => $responses]);
    }
    public function updateLive(string $raceId, int $revision, array $cancelled, ?string $close = null): array
    {
        return $this->call(['action' => 'live', 'race_id' => $raceId, 'expected_revision' => $revision, 'cancelled' => $cancelled, 'live_close' => $close]);
    }
    public function read(string $raceId): array { return $this->call(['action' => 'read', 'race_id' => $raceId]); }
    public function claim(string $inputJson, int $generation, string $system): array
    {
        return $this->call(['action'=>'claim','input_json'=>$inputJson,'generation'=>$generation,'system'=>$system]);
    }
    public function complete(string $token, string $text, string $receivedAt, bool $complete): array
    {
        return $this->call(['action'=>'complete','token'=>$token,'text'=>$text,'received_at'=>$receivedAt,'complete'=>$complete]);
    }
    public function fail(string $token, string $code): array
    {
        return $this->call(['action'=>'fail','token'=>$token,'error_code'=>$code]);
    }
    public function workerState(string $inputJson, int $generation): array
    {
        return $this->call(['action'=>'worker_state','input_json'=>$inputJson,'generation'=>$generation]);
    }
    public function publishSaved(string $raceId, int $generation, int $stateRevision, int $publicationRevision, int $responseRevision, string $jobId): array
    {
        return $this->call(['action'=>'publish_saved','race_id'=>$raceId,'generation'=>$generation,
            'state_revision'=>$stateRevision,'publication_revision'=>$publicationRevision,'response_revision'=>$responseRevision,'job_id'=>$jobId]);
    }

    private function call(array $request): array
    {
        $json = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->callRaw($json);
        // Decimal facts must survive both PHP decoding and the HTTP JSON response.
        // Native input_json/common_json strings remain the canonical AI inputs.
        $response = ExactJson::decode($raw);
        if (($response['ok'] ?? null) !== true) {
            throw new \RuntimeException('r12 validation failed: ' . ($response['message'] ?? 'unknown error'));
        }
        return $response['result'];
    }
    public function callRaw(string $json): string
    {
        if (strlen($json) > 64 * 1024 * 1024) throw new \InvalidArgumentException('Request too large');
        // Escape Unicode code points without decoding numeric JSON tokens.
        $ascii = preg_replace_callback('/[\x{80}-\x{10ffff}]/u', static function (array $m): string {
            return substr(json_encode($m[0], JSON_THROW_ON_ERROR), 1, -1);
        }, $json);
        if ($ascii === null || strlen($ascii) > 64 * 1024 * 1024) throw new \InvalidArgumentException('UTF-8/transport size');
        $json = $ascii;
        $inputPath = tempnam(sys_get_temp_dir(), 'bgr12_');
        if ($inputPath === false) throw new \RuntimeException('Cannot create private input path');
        chmod($inputPath, 0600);
        $input = fopen($inputPath, 'w+b');
        if ($input === false) throw new \RuntimeException('Cannot create private input buffer');
        $proc = null; $pipes = []; $output = ''; $error = ''; $exit = -1;
        try {
            $offset = 0;
            while ($offset < strlen($json)) {
                $n = fwrite($input, substr($json, $offset));
                if ($n === false || $n === 0) throw new \RuntimeException('Input write failed');
                $offset += $n;
            }
            fflush($input);
            $proc = proc_open([$this->python, $this->engineDir . '/cli.py', '--db', $this->database, '--request-file', $inputPath],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->engineDir);
            if (!is_resource($proc)) throw new \RuntimeException('Python worker could not start');
            fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $this->timeoutSeconds;
            while (true) {
                $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
                if (strlen($output) + strlen($error) > 64 * 1024 * 1024) throw new \RuntimeException('Worker output too large');
                $status = proc_get_status($proc);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (microtime(true) >= $deadline) throw new \RuntimeException('Worker timeout');
                $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
                stream_select($read, $write, $except, 0, 100000);
            }
            $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            if (strlen($output) + strlen($error) > 64 * 1024 * 1024) throw new \RuntimeException('Worker output too large');
            if ($output === '') throw new \RuntimeException('Worker produced no response, exit=' . $exit);
            return $output;
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($proc)) {
                $status = proc_get_status($proc);
                if ($status['running']) proc_terminate($proc, 9);
                proc_close($proc);
            }
            fclose($input);
            unlink($inputPath);
        }
    }
}

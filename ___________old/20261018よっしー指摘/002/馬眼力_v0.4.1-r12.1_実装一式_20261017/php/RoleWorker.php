<?php
declare(strict_types=1);
namespace Baganriki;
require_once __DIR__.'/Timestamp.php';
/** One durable journal per race/generation/role; all workers share the same DB. */
final class RoleWorker
{
    public function __construct(private BaganrikiR12Bridge $bridge) {}
    /** Provider must disable its own retries. Each granted slot invokes it at most once. */
    public function run(string $system, string $inputJson, string $replyDeadline, callable $callAI, int $generation = 1): array
    {
        // Decode only for comparison; never send re-encoded numeric input to the AI.
        $meta = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);
        $deadlineTime = Timestamp::parse($replyDeadline);
        $frozenDeadline = Timestamp::parse($meta['time_contract']['reply_deadline']);
        if ($deadlineTime != $frozenDeadline) throw new \InvalidArgumentException('Reply deadline differs from frozen input');
        $deadline = (float)$deadlineTime->format('U.u');
        for ($i = 0; $i < 2; ++$i) {
            $grant = $this->bridge->claim($inputJson, $generation, $system);
            if (!$grant['granted']) return $grant['attempts'];
            $callDeadline = (float)Timestamp::parse($grant['call_deadline'])->format('U.u');
            $remaining = min($deadline, $callDeadline) - microtime(true) - 1.0;
            if ($remaining <= 0) {
                $this->bridge->fail($grant['token'], 'DEADLINE_BEFORE_CALL');
                break;
            }
            try {
                $reply = $callAI($system, $grant['user'], $remaining);
                $received = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
            } catch (\Throwable $ex) {
                // A transport error can mean the provider did execute. Never dispatch this slot again.
                $this->bridge->fail($grant['token'], 'PROVIDER_ERROR');
                break;
            }
            if (!is_array($reply) || !is_string($reply['text'] ?? null) || !is_bool($reply['complete'] ?? null)) {
                $this->bridge->fail($grant['token'], 'INVALID_PROVIDER_REPLY');
                break;
            }
            // Commit original reply BEFORE diagnosing or starting correction.
            // An unknown save acknowledgement must never trigger another provider call.
            $this->bridge->complete($grant['token'], $reply['text'], $received, $reply['complete']);
        }
        return $this->bridge->workerState($inputJson, $generation)['attempts'];
    }
}

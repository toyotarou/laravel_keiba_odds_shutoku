<?php
declare(strict_types=1);
/** Integration example for a Laravel queue job; adapt service binding to your application. */
use Baganriki\BaganrikiR12Bridge;
use Baganriki\RoleWorker;

function runBaganrikiRole(BaganrikiR12Bridge $bridge, array $prepared, string $role, string $replyDeadline, callable $provider, int $generation): array
{
    if (!in_array($role, ['1st','2nd'], true)) throw new InvalidArgumentException('role');
    // provider must use ONLY this system and this user message; no legacy brain/prompt append.
    return (new RoleWorker($bridge))->run($prepared['prompts'][$role], $prepared['input_json'][$role], $replyDeadline, $provider, $generation);
}
function prepareBaganrikiPublication(BaganrikiR12Bridge $bridge, string $raceId, string $jobId): array
{
    $state = $bridge->read($raceId);
    $live = $state['live_state'];
    return ['race_id'=>$raceId, 'generation'=>$live['generation'], 'state_revision'=>$live['state_revision'],
        'publication_revision'=>$live['publication_revision'], 'response_revision'=>$state['worker_state']['response_revision'], 'job_id'=>$jobId];
}
function commitBaganrikiPublication(BaganrikiR12Bridge $bridge, array $intent): array
{
    return $bridge->publishSaved($intent['race_id'],$intent['generation'],$intent['state_revision'],
        $intent['publication_revision'],$intent['response_revision'],$intent['job_id']);
}
// Parent queue job: prepareExport($exportJson) -> install(input_json['1st'], generation).
// Dispatch 1st and 2nd independently. RoleWorker persists every response immediately.
// Aggregation uses publishSaved and a frozen tuple of revisions. Persist this intent with the queue job.
// Retry with the SAME tuple/jobId. On stale revisions, enqueue a NEW job with a fresh tuple/jobId.
// prepareBaganrikiPublication creates the intent ONCE, before dispatching the aggregation job.
// Store this array in the job payload. handle() calls commitBaganrikiPublication with that SAME array.
// A later successful role may trigger another publish before publish_deadline using new publication_revision.
// HTTP read endpoint returns read($raceId). Render publication candidates with live_state.cancelled overlay.
// Never pass these candidates through _mergeAiResults / _trimAiCandidates / old candidate filters.

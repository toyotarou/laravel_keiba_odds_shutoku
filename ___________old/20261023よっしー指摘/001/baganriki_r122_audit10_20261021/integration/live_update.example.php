<?php
// Invoke ONLY after committing a trusted, authenticated race-state update.
// Variables come from the application's existing official ingestion path.
// Do not expose this as an unauthenticated public endpoint.
\App\Jobs\Baganriki\UpdateRaceLiveJob::dispatch(
    $snapshotId, $confirmedCancelledHorseNumbers, $confirmedEarlierSalesClose,
    $readStateRevision, $officialSourceEventId
)->onConnection('baganriki_database')->onQueue('r122-deadline')->afterCommit();


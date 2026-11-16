<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiPrompt;
use App\Http\Controllers\Api\BaganrikiWorker;
use App\Http\Controllers\Api\BaganrikiTime;
use InvalidArgumentException;

/** Read the committed publication and official live overlay in the caller's read transaction. */
final class PublishedReadW2
{
    private static function need(bool $ok, string $code): void
    {
        if (!$ok) { throw new InvalidArgumentException($code); }
    }

    /** Works for the full unsigned database revision range, including decimal strings from PDO. */
    private static function immediatelyBefore(string $old, string $now): bool
    {
        $head = rtrim($old, '9');
        $prefix = $head === '' ? '1' : substr($head, 0, -1) . ((int) substr($head, -1) + 1);
        return $prefix . str_repeat('0', strlen($old) - strlen($head)) === $now;
    }

    /** Read the immutable clock and compare the denormalized SQL column in integer microseconds. */
    public static function frozenSales(object $snap, ?array &$input = null): int
    {
        $raw = $snap->common_json ?? null;
        $hash = $snap->snapshot_hash ?? null;
        self::need(is_string($raw) && is_string($hash) && hash_equals(hash('sha256',$raw),$hash), 'TIME_UNCOMPUTABLE');
        $input = FrozenInputW2::decodeForInspection($raw);
        self::need(is_array($input) && ($input['race_id'] ?? null) === ($snap->race_id ?? null)
            && is_string($snap->race_id ?? null) && ($input['prompt_version'] ?? null) === CandidateSelectionW2::VERSION,
            'TIME_UNCOMPUTABLE');
        $time = $input['time_contract']['sales_close_at'] ?? null;
        $cutoff = is_string($time) ? BaganrikiTime::us($time) : null;
        self::need($cutoff !== null && $cutoff === BaganrikiWorker::dbUs($snap->sales_close_at ?? null), 'TIME_UNCOMPUTABLE');
        return $cutoff;
    }

    /** The submitted writer records each state revision and both cumulative withdrawal sets in the same transaction. */
    public static function checkHistory(object $snap, object $live, array $roster, ?string $asOf = null, ?array &$atWithdrawn = null, ?array &$timeline = null): array
    {
        $sales = self::frozenSales($snap,$frozen);
        $stateRev = AttemptWindowW2::revision($live->state_revision ?? null);
        $events = \DB::table('baganriki_audit_events')->where('snapshot_id',$snap->id)
            ->where('kind','R122_LIVE_APPLIED')->orderBy('new_revision')
            ->get(['race_id','old_revision','new_revision','diagnostics']);
        $revision = '0'; $withdrawn = []; $close = null;
        if ($timeline !== null) { $timeline['0']=[]; }
        foreach ($events as $event) {
            if ($asOf !== null && $revision === $asOf) { break; }
            $next = AttemptWindowW2::revision($event->new_revision ?? null);
            self::need($event->race_id === $snap->race_id && AttemptWindowW2::revision($event->old_revision ?? null) === $revision
                && self::immediatelyBefore($revision,$next), 'LIVE_STATE_UNAVAILABLE');
            $revision = $next;
            $raw = $event->diagnostics ?? null;
            self::need(is_string($raw) && BaganrikiJson::decode($raw)['ok'], 'LIVE_STATE_UNAVAILABLE');
            $data = json_decode($raw);
            self::need(is_object($data) && (property_exists($data,'cancellations') || property_exists($data,'live_close')), 'LIVE_STATE_UNAVAILABLE');
            if (property_exists($data,'cancellations')) {
                $delta = $data->cancellations;
                self::need(is_array($delta->{'前'} ?? null) && is_array($delta->{'後'} ?? null), 'LIVE_STATE_UNAVAILABLE');
                try {
                    $before = AttemptWindowW2::horseSet($delta->{'前'},$roster['roster']);
                    $after = AttemptWindowW2::horseSet($delta->{'後'},$roster['roster']);
                } catch (InvalidArgumentException $e) { throw new InvalidArgumentException('LIVE_STATE_UNAVAILABLE',0,$e); }
                self::need($before === $withdrawn && count($after) > count($before) && !array_diff($before,$after), 'LIVE_STATE_UNAVAILABLE');
                $withdrawn = $after;
            }
            if (property_exists($data,'live_close')) {
                $delta = $data->live_close;
                self::need(is_object($delta) && property_exists($delta,'前') && property_exists($delta,'後'), 'TIME_UNCOMPUTABLE');
                $before = $delta->{'前'}; $after = $delta->{'後'};
                self::need(($close === null ? $before === null : (is_int($before) || is_string($before)) && (string)$before === (string)$close)
                    && (is_int($after) || is_string($after)) && (string)(int)$after === (string)$after
                    && (int)$after > 0 && (int)$after < $sales && ($close === null || (int)$after < $close), 'TIME_UNCOMPUTABLE');
                $close = (int)$after;
            }
            if ($timeline !== null) { $timeline[$revision]=$withdrawn; }
        }
        if ($asOf !== null) {
            self::need($revision === $asOf && SchemaW2::decimals($asOf,$stateRev) <= 0, 'LIVE_STATE_UNAVAILABLE');
            $atWithdrawn = $withdrawn;
            return $frozen;
        }
        self::need($revision === $stateRev && $withdrawn === $roster['withdrawn'], 'LIVE_STATE_UNAVAILABLE');
        $stored = $live->live_close ?? null;
        $current = BaganrikiWorker::dbUs($stored);
        self::need(($stored === null || $current !== null) && $current === $close, 'TIME_UNCOMPUTABLE');
        return $frozen;
    }

    private static function rows(mixed $raw): array
    {
        self::need(is_string($raw), 'PUBLICATION_DATA_INVALID');
        $parsed = BaganrikiJson::decode($raw);
        self::need($parsed['ok'] && ($parsed['map']['kind']['[]'] ?? null) === 'arr', 'PUBLICATION_DATA_INVALID');
        // Preserve the pre-existing public API's JSON scalar representations after strict structural validation.
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** The sealed selection, or the original normalized record, must support every displayed agreement claim. */
    private static function checkBonuses(array $rows, ?array $bonuses, ?array $proofs): void
    {
        foreach ($rows as $row) {
            $n = (int)$row['horse_no'];
            $value = $bonuses === null ? ($row['bonus'] ?? null) : ($bonuses[$n] ?? null);
            // The historical normalized writer encoded integer-valued bonuses as 0.0 and 5.0.
            self::need(in_array($value,[0,5,0.0,5.0],true), 'PUBLICATION_DATA_INVALID');
            $proven = (int)$value === 5;
            if (array_key_exists('bonus',$row)) {
                self::need(in_array($row['bonus'],[0,5,0.0,5.0],true) && (int)$row['bonus'] === (int)$value, 'PUBLICATION_DATA_INVALID');
            }
            self::need(!array_key_exists('bonus_proven',$row) || $row['bonus_proven'] === $proven, 'PUBLICATION_DATA_INVALID');
            if ($proven && array_key_exists('both_public',$row)) {
                self::need($row['both_public'] === true, 'PUBLICATION_DATA_INVALID');
            }
            $hasCopy = array_key_exists('public_proof_hash',$row);
            $copy = $row['public_proof_hash'] ?? null;
            if ($proofs === null) {
                // Compact legacy rows bind the number in the intent; proof claims require an independent stored copy.
                self::need($copy === null && (!$proven || !array_key_exists('bonus_proven',$row)), 'PUBLICATION_DATA_INVALID');
                continue;
            }
            $hashes = $proofs[$n] ?? null;
            if (!$proven) {
                self::need($hashes === null && $copy === null, 'PUBLICATION_DATA_INVALID');
                continue;
            }
            self::need(is_array($hashes) && count($hashes) === 2 && (!$hasCopy || (is_array($copy) && count($copy) === 2)), 'PUBLICATION_DATA_INVALID');
            foreach (['1st','2nd'] as $role) {
                $hash = $hashes[$role] ?? null;
                self::need(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D',$hash) === 1
                    && (!$hasCopy || ($copy[$role] ?? null) === $hash), 'PUBLICATION_DATA_INVALID');
            }
        }
    }

    /** Only the authenticated race input and its official withdrawals determine the size of the public pool. */
    public static function allocation(array $frozen, array $roster, array $withdrawn): array
    {
        $mode = $frozen['allocation_mode'] ?? null;
        self::need(array_key_exists('allocation_mode',$frozen) && array_key_exists('quota',$frozen)
            && ($mode === null || in_array($mode,['FIRM','MIXED','VOLATILE'],true))
            && ($frozen['selection_enabled'] ?? null) === ($mode !== null)
            && is_array($frozen['runners'] ?? null), 'PUBLICATION_DATA_INVALID');
        $by = []; $ranks = [];
        foreach ($frozen['runners'] as $horse) {
            $n = $horse['horse_no'] ?? null; $rank = $horse['win_rank'] ?? null;
            self::need(is_int($n) && in_array($n,$roster,true) && !isset($by[$n])
                && is_int($rank) && $rank >= 1 && $rank <= count($roster) && !isset($ranks[$rank])
                && ($horse['band'] ?? null) === ($rank <= 6 ? 'A' : ($rank <= 10 ? 'B' : 'D')), 'PUBLICATION_DATA_INVALID');
            $by[$n] = $horse['band']; $ranks[$rank] = true;
        }
        self::need(count($by) === count($roster) && ($frozen['N'] ?? null) === count($by), 'PUBLICATION_DATA_INVALID');
        $prediction = CandidateSelectionW2::publicationAllocation($mode,$by);
        self::need(($frozen['target_count'] ?? null) === $prediction['target_count']
            && self::sameQuota($frozen['quota'],$prediction['quota']), 'PUBLICATION_DATA_INVALID');
        $active = array_diff_key($by,array_fill_keys($withdrawn,true));
        $current = $withdrawn ? CandidateSelectionW2::publicationAllocation($mode,$active) : $prediction;
        return $current + ['active_count'=>count($active),'prediction_quota'=>$prediction['quota'],
            'prediction_target'=>$prediction['target_count']];
    }

    private static function sameQuota(mixed $value, ?array $expected): bool
    {
        if ($expected === null) { return $value === null; }
        return is_array($value) && count($value) === 3 && ($value['A'] ?? null) === $expected['A']
            && ($value['B'] ?? null) === $expected['B'] && ($value['D'] ?? null) === $expected['D'];
    }

    /** MySQL PDO may return integers as canonical decimal strings. */
    private static function countColumn(object $pub, string $name, int $expected): void
    {
        $value = $pub->$name ?? null;
        self::need($value === null || ((is_int($value) || is_string($value)) && (string)$value === (string)$expected), 'PUBLICATION_DATA_INVALID');
        $pub->$name = $expected;
    }

    private static function mapColumn(mixed $raw): array
    {
        self::need(is_string($raw), 'PUBLICATION_DATA_INVALID');
        $parsed = BaganrikiJson::decode($raw);
        self::need($parsed['ok'] && ($parsed['map']['kind']['[]'] ?? null) === 'obj', 'PUBLICATION_DATA_INVALID');
        return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }

    /** A transfer satisfies its requested band but the horse stays in its real popularity band. */
    private static function checkFilled(array $quota, array $owners, array $moves, array $shortfalls): void
    {
        $filled = array_count_values($owners) + ['A'=>0,'B'=>0,'D'=>0];
        $from = ['D'=>['B'],'B'=>['D'],'A'=>['B','D']];
        $seen = [];
        foreach ($moves as $move) {
            $n = $move['horse_no'] ?? null; $src = $move['actual'] ?? null; $dst = $move['requested'] ?? null;
            self::need(is_int($n) && !isset($seen[$n]) && is_string($src) && ($owners[$n] ?? null) === $src
                && is_string($dst) && isset($from[$dst]) && in_array($src,$from[$dst],true), 'PUBLICATION_DATA_INVALID');
            $seen[$n]=true; --$filled[$src]; ++$filled[$dst];
        }
        $expected = [];
        foreach (['A','B','D'] as $b) {
            $missing = $quota[$b]-$filled[$b];
            self::need($filled[$b]>=0 && $missing>=0, 'PUBLICATION_DATA_INVALID');
            if ($missing>0) { $expected[]=['band'=>$b,'requested'=>$quota[$b],'filled'=>$filled[$b],'missing'=>$missing]; }
        }
        self::need(self::sameFacts($shortfalls,$expected), 'PUBLICATION_DATA_INVALID');
    }

    /** Compare JSON values strictly, ignoring object key order but retaining the order of every list. */
    private static function sameFacts(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) { return $left === $right; }
        if (count($left)!==count($right) || ExpectedPacketW2::isList($left)!==ExpectedPacketW2::isList($right)) { return false; }
        foreach ($right as $key=>$value) {
            if (!array_key_exists($key,$left) || !self::sameFacts($left[$key],$value)) { return false; }
        }
        return true;
    }

    private static function publicationSummary(object $pub, array $allocation, array $withdrawn, array $owners, ?array $selection): void
    {
        self::need(is_array($allocation['prediction_quota']) && is_array($allocation['quota'])
            && !array_intersect(array_keys($owners),$withdrawn), 'PUBLICATION_DATA_INVALID');
        $evidence = isset($pub->evidence) ? self::mapColumn($pub->evidence) : [];
        $public = isset($pub->publication_quota) ? self::mapColumn($pub->publication_quota) : null;
        if ($public === null && $selection !== null) {
            $public = $allocation['quota']; $pub->publication_quota = json_encode($public,JSON_THROW_ON_ERROR);
        }
        self::need(is_array($public) && count($public)===3, 'PUBLICATION_DATA_INVALID');
        foreach (['A','B','D'] as $b) {
            self::need(is_int($public[$b] ?? null) && $public[$b]>=0 && $public[$b]<=7, 'PUBLICATION_DATA_INVALID');
        }
        $modern = $selection !== null || array_key_exists('actual_quota',$evidence) || array_key_exists('fill',$evidence);
        // Pre-R15.12 normalized records can bind a post-eligibility allocation in their intent.
        self::need($modern ? self::sameQuota($public,$allocation['quota']) : array_sum($public)<=$allocation['target_count'], 'PUBLICATION_DATA_INVALID');
        if (isset($pub->prediction_quota)) {
            self::need(self::sameQuota(self::mapColumn($pub->prediction_quota),$allocation['prediction_quota']), 'PUBLICATION_DATA_INVALID');
        } else {
            $pub->prediction_quota = json_encode($allocation['prediction_quota'],JSON_THROW_ON_ERROR);
        }
        self::countColumn($pub,'active_count',$allocation['active_count']);
        self::countColumn($pub,'prediction_target_count',$allocation['prediction_target']);
        self::countColumn($pub,'publication_target_count',array_sum($public));
        $actual = array_count_values($owners) + ['A'=>0,'B'=>0,'D'=>0];
        if (array_key_exists('cancelled',$evidence)) {
            self::need(is_array($evidence['cancelled']) && ExpectedPacketW2::isList($evidence['cancelled'])
                && AttemptWindowW2::horseSet($evidence['cancelled'],$withdrawn)===$withdrawn, 'PUBLICATION_DATA_INVALID');
        }
        if (array_key_exists('actual_quota',$evidence)) {
            self::need(self::sameQuota($evidence['actual_quota'],$actual), 'PUBLICATION_DATA_INVALID');
        }
        $short = isset($pub->shortfalls) ? self::rows($pub->shortfalls) : ($selection['shortfalls'] ?? $evidence['shortfalls'] ?? []);
        self::need(is_array($short) && ExpectedPacketW2::isList($short)
            && (!array_key_exists('shortfalls',$evidence) || self::sameFacts($evidence['shortfalls'],$short)), 'PUBLICATION_DATA_INVALID');
        if ($selection !== null) {
            self::need(self::sameQuota($selection['actual_quota'] ?? null,$actual)
                && self::sameFacts($selection['shortfalls'] ?? null,$short)
                && (!array_key_exists('fill',$evidence) || self::sameFacts($evidence['fill'],$selection['fill'] ?? null)), 'PUBLICATION_DATA_INVALID');
        }
        if ($modern) {
            $moves = $selection['fill'] ?? $evidence['fill'] ?? null;
            self::need(is_array($moves) && ExpectedPacketW2::isList($moves), 'PUBLICATION_DATA_INVALID');
            self::checkFilled($public,$owners,$moves,$short);
        }
        if (!isset($pub->shortfalls)) { $pub->shortfalls=json_encode($short,JSON_THROW_ON_ERROR); }
    }

    /** A new write may advance only a consistent prior publication, including the initial empty state. */
    public static function checkPrevious(object $snap, object $live): void
    {
        if (($live->latest_publication_id ?? null) === null && AttemptWindowW2::revision($live->publication_revision ?? null) === '0') {
            foreach (['baganriki_publications','baganriki_publication_intents'] as $table) {
                self::need(!\DB::table($table)->where('snapshot_id',$snap->id)->exists(), 'PUBLICATION_REFERENCE_MISMATCH');
            }
            return;
        }
        self::need(self::load($snap,$live)['pub'] !== null, 'PUBLICATION_REFERENCE_MISMATCH');
    }

    /** A completed retry returns the original saved result while the writer holds the live row and intent locks. */
    public static function replay(object $snap, object $live, object $intent, string $hash, array $read): array
    {
        self::need(($intent->intent_hash ?? null) === $hash && ($intent->state ?? null) === 'COMMITTED'
            && ($intent->publication_id ?? null) !== null
            && AttemptWindowW2::revision($intent->read_state_revision ?? null) === (string)$read['state']
            && AttemptWindowW2::revision($intent->read_response_revision ?? null) === (string)$read['response'],
            'PUBLICATION_REFERENCE_MISMATCH');
        if ((string)$intent->publication_id !== (string)($live->latest_publication_id ?? '')) {
            $head = $live->latest_publication_id ?? null;
            self::need($head !== null, 'PUBLICATION_REFERENCE_MISMATCH');
            $latestIntent = \DB::table('baganriki_publication_intents')->where('snapshot_id',$snap->id)
                ->where('publication_id',$head)->where('state','COMMITTED')->first();
            self::need($latestIntent !== null, 'PUBLICATION_REFERENCE_MISMATCH');
            self::load($snap,$live,$latestIntent);
        }
        $saved = self::load($snap,$live,$intent);
        $pub = $saved['pub'];
        $proof = [];
        if (($pub->evidence ?? null) !== null) {
            self::need(is_string($pub->evidence), 'PUBLICATION_DATA_INVALID');
            $parsed = BaganrikiJson::decode($pub->evidence);
            self::need($parsed['ok'] && ($parsed['map']['kind']['[]'] ?? null) === 'obj', 'PUBLICATION_DATA_INVALID');
            $proof = json_decode($pub->evidence,true);
        }
        $quota = json_decode((string)($pub->publication_quota ?? ''),true);
        $predicted = json_decode((string)($pub->prediction_quota ?? ''),true);
        self::need(is_array($quota) && is_array($predicted), 'PUBLICATION_DATA_INVALID');
        $actual = ['A'=>0,'B'=>0,'D'=>0];
        foreach ($saved['candidates'] as $horse) { ++$actual[$horse['band']]; }
        $replayed = ['candidates'=>$saved['candidates'],'quota'=>$quota,'prediction_quota'=>$predicted,
            'shortfalls'=>isset($pub->shortfalls) ? self::rows($pub->shortfalls) : [],
            'actual_quota'=>$actual,'proof'=>$proof['proof_roles'] ?? [],
            'used'=>['state'=>$read['state'],'response'=>$read['response'],'publication'=>(int)$intent->read_publication_revision]];
        foreach (['fill','role_issues','rank_source','rank_fallback','rank_notes'] as $field) {
            $replayed[$field] = $proof[$field] ?? [];
        }
        if (array_key_exists('race_metrics',$proof)) $replayed['race_metrics']=$proof['race_metrics'];
        return $replayed;
    }

    public static function load(object $snap, ?object $live, ?object $completed = null): array
    {
        self::need($live !== null && \Schema::hasTable('baganriki_audit_events'), 'LIVE_STATE_UNAVAILABLE');
        if ($completed === null) {
            $roster = BaganrikiWorker::verifiedLiveRoster($snap,$live,false);
            self::need($roster !== null, 'LIVE_STATE_UNAVAILABLE');
            $timeline = [];
            $frozen = self::checkHistory($snap,$live,$roster,null,$unused,$timeline);
            $clock = BaganrikiWorker::deadlines($snap,$live);
            self::need($clock['ok'] && $clock['race_started'] !== null, 'PUBLICATION_CLOCK_UNAVAILABLE');
        } else {
            // An already committed ID uses the official withdrawal history at its own revision.
            $raw = $snap->frozen_nums ?? null;
            $nums = is_string($raw) ? json_decode($raw) : $raw;
            self::need(is_array($nums) && ExpectedPacketW2::isList($nums), 'PUBLICATION_DATA_INVALID');
            $roster = ['roster'=>AttemptWindowW2::horseSet($nums,range(1,18))];
            $asOf = AttemptWindowW2::revision($completed->read_state_revision ?? null);
            $frozen = self::checkHistory($snap,$live,$roster,$asOf,$atWithdrawn);
            $roster['withdrawn'] = $atWithdrawn;
            $clock = null;
        }
        self::need(is_array($frozen['runners'] ?? null), 'PUBLICATION_DATA_INVALID');
        // common_json omits the self-hash; frozenSales has already verified its exact bytes.
        $frozen['snapshot_hash']=(string)$snap->snapshot_hash;
        $bands = array_column($frozen['runners'],'band','horse_no');
        $numbers = array_keys($bands); sort($numbers,SORT_NUMERIC);
        self::need($numbers === $roster['roster'] && count($numbers) === count($frozen['runners']), 'PUBLICATION_DATA_INVALID');
        $revision = AttemptWindowW2::revision($live->publication_revision ?? null);
        $stateRev = AttemptWindowW2::revision($live->state_revision ?? null);
        $responseRev = AttemptWindowW2::revision($live->response_revision ?? null);
        $liveAllocation = self::allocation($frozen,$roster['roster'],$roster['withdrawn']);
        $out = ['allocation'=>$liveAllocation,'pub'=>null, 'candidates'=>null, 'available'=>null, 'quota'=>null,
                'roster'=>$roster, 'clock'=>$clock, 'race_metrics'=>null];
        self::need(\Schema::hasTable('baganriki_publications'), 'PUBLICATION_REFERENCE_MISMATCH');
        $pubs = \DB::table('baganriki_publications')->where('snapshot_id', $snap->id);
        $head = $live->latest_publication_id ?? null;
        $id = $completed === null ? $head : ($completed->publication_id ?? null);
        if ($completed !== null) {
            self::need($id !== null && $head !== null && ($completed->state ?? null) === 'COMMITTED'
                && (string)($completed->snapshot_id ?? '') === (string)$snap->id, 'PUBLICATION_REFERENCE_MISMATCH');
        }
        if ($id === null) {
            self::need($revision === '0' && !$pubs->exists(), 'PUBLICATION_REFERENCE_MISMATCH');
            return $out;
        }

        self::need((is_int($id) && $id > 0) || (is_string($id) && $id !== '' && trim($id) === $id), 'PUBLICATION_REFERENCE_MISMATCH');
        $pub = $pubs->where('publication_id', $id)->first();
        self::need($pub !== null && (string) $pub->publication_id === (string) $id
            && \Schema::hasTable('baganriki_publication_intents'), 'PUBLICATION_REFERENCE_MISMATCH');
        $intent = \DB::table('baganriki_publication_intents')->where('snapshot_id', $snap->id)
            ->where('id', $pub->intent_id)->where('publication_id', $id)->where('state', 'COMMITTED')->first();
        self::need($intent !== null && (string) $intent->publication_id === (string) $id && $intent->state === 'COMMITTED',
            'PUBLICATION_REFERENCE_MISMATCH');
        $previous = AttemptWindowW2::revision($intent->read_publication_revision ?? null);
        $latest = (string)$id === (string)$head;
        self::need(SchemaW2::decimals($previous,$revision) < 0 && self::immediatelyBefore($previous,$revision) === $latest,
            'PUBLICATION_REFERENCE_MISMATCH');
        if ($completed !== null) {
            self::need((string)$intent->id === (string)($completed->id ?? '')
                && $intent->intent_hash === ($completed->intent_hash ?? null), 'PUBLICATION_REFERENCE_MISMATCH');
        }
        $usedState = AttemptWindowW2::revision($pub->used_state_revision ?? null);
        $usedResponse = AttemptWindowW2::revision($pub->used_response_revision ?? null);
        self::need($usedState === AttemptWindowW2::revision($intent->read_state_revision ?? null)
            && $usedResponse === AttemptWindowW2::revision($intent->read_response_revision ?? null)
            && SchemaW2::decimals($usedState,$stateRev) <= 0 && SchemaW2::decimals($usedResponse,$responseRev) <= 0, 'PUBLICATION_REFERENCE_MISMATCH');

        $rows = self::rows($pub->candidates ?? null);
        $numbers=[]; $seen=[]; $identity=[]; $available=[];
        $quota=['A'=>0,'B'=>0,'D'=>0];
        self::need(count($rows) <= 7, 'PUBLICATION_DATA_INVALID');
        foreach ($rows as $row) {
            $n=$row['horse_no'] ?? null; $band=$row['band'] ?? null;
            if (is_string($n) && preg_match('/^(?:[1-9]|1[0-8])$/D',$n) === 1) { $n=(int) $n; }
            self::need(is_array($row) && is_int($n) && in_array($n,$roster['roster'],true) && !isset($seen[$n])
                && is_string($band) && isset($quota[$band]) && $bands[$n] === $band, 'PUBLICATION_DATA_INVALID');
            $seen[$n]=true; $numbers[]=$n;
            $identity[]=['horse_no'=>$n,'band'=>$band,'bonus'=>$row['bonus'] ?? null];
            if (!in_array($n,$roster['withdrawn'],true)) { $available[]=$n; ++$quota[$band]; }
        }
        if ($completed !== null) {
            self::need(!array_intersect($numbers,$roster['withdrawn']), 'PUBLICATION_DATA_INVALID');
        }
        $hash=$intent->intent_hash ?? null;
        self::need(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D',$hash) === 1, 'PUBLICATION_REFERENCE_MISMATCH');
        // Some deployments store the W9 fields on the linked intent. Both copies must agree when present.
        $seal=[];
        foreach (['selection_json','payload_sha256','publication_sha256'] as $key) {
            $a=$pub->$key ?? null; $b=$intent->$key ?? null;
            self::need($a===null || $b===null || $a===$b, 'PUBLICATION_DATA_INVALID');
            if ($a!==null || $b!==null) { $seal[$key]=$a ?? $b; }
        }
        $selection = null;
        if ($seal) {
            $saved=$seal+['race_id'=>$snap->race_id,'snapshot_hash'=>$snap->snapshot_hash,'intent_hash'=>$hash,'publication_id'=>$id];
            try { $selection=AttemptWindowW2::savedPublication($snap->race_id,$snap->snapshot_hash,$hash,$saved)['selection']; }
            catch (InvalidArgumentException $e) { throw new InvalidArgumentException('PUBLICATION_DATA_INVALID',0,$e); }
            self::need(($selection['public_candidates'] ?? null)===$numbers, 'PUBLICATION_DATA_INVALID');
            self::checkBonuses($rows,$selection['agreement_bonus'] ?? [],$selection['public_proof_hashes'] ?? []);
            if (RaceMetricsW2::enabled($frozen)) $out['race_metrics']=RaceMetricsW2::publicValues($frozen,$selection['race_metrics']??null);
            $guard = $selection['publication_guard'];
            self::need(($guard['revisions']['publication_revision'] ?? null) === (string)$intent->read_publication_revision
                && ($guard['revisions']['state_revision'] ?? null) === $usedState
                && ($guard['revisions']['response_revision'] ?? null) === $usedResponse
                && is_array($guard['active_horse_nos'] ?? null)
                && !array_diff(array_diff($roster['roster'],$roster['withdrawn']),$guard['active_horse_nos'])
                && ($completed === null || array_values(array_diff($roster['roster'],$roster['withdrawn'])) === $guard['active_horse_nos']),
                'PUBLICATION_REFERENCE_MISMATCH');
        } else {
            // The submitted publisher's original row format binds the display order, bands and bonus to the intent.
            $storedProofs = null;
            if (($pub->public_proof_hash ?? null) !== null) {
                self::need(is_string($pub->public_proof_hash), 'PUBLICATION_DATA_INVALID');
                $decoded = BaganrikiJson::decode($pub->public_proof_hash);
                self::need($decoded['ok'] && is_array($decoded['value'])
                    && (($decoded['map']['kind']['[]'] ?? null) === 'obj' || $decoded['value'] === []), 'PUBLICATION_DATA_INVALID');
                $storedProofs = $decoded['value'];
            }
            self::checkBonuses($rows,null,$storedProofs);
            $savedQuota=json_decode((string) ($pub->publication_quota ?? ''),true);
            self::need(is_array($savedQuota) && count($savedQuota)===3 && isset($savedQuota['A'],$savedQuota['B'],$savedQuota['D']),
                'PUBLICATION_DATA_INVALID');
            self::need((string)$id === 'pub-'.$snap->race_id.'-'.$snap->generation.'-'.substr($hash,0,12), 'PUBLICATION_REFERENCE_MISMATCH');
            $intentData=['snapshot_hash'=>(string)$snap->snapshot_hash,'quota'=>$savedQuota,'candidates'=>$identity,
                'revisions'=>['state'=>(int)$usedState,'response'=>(int)$usedResponse]];
            if (RaceMetricsW2::enabled($frozen)) {
                $evidenceRaw=$pub->evidence??null;
                self::need(is_string($evidenceRaw),'PUBLICATION_DATA_INVALID');
                $decoded=BaganrikiJson::decode($evidenceRaw);
                self::need($decoded['ok'] && ($decoded['map']['kind']['[]']??null)==='obj','PUBLICATION_DATA_INVALID');
                $savedEvidence=json_decode($evidenceRaw,true,512,JSON_THROW_ON_ERROR);
                self::need(array_key_exists('race_metrics',$savedEvidence),'PUBLICATION_DATA_INVALID');
                $intentData['race_metrics']=$savedEvidence['race_metrics'];
                $out['race_metrics']=RaceMetricsW2::publicValues($frozen,$savedEvidence['race_metrics']);
            }
            $canon=BaganrikiPrompt::canonical($intentData);
            self::need((string)(int)$usedState===$usedState && (string)(int)$usedResponse===$usedResponse
                && $canon !== null && hash_equals($hash,hash('sha256',$canon)), 'PUBLICATION_DATA_INVALID');
        }
        $atPublication = $completed !== null ? $roster['withdrawn'] : ($timeline[$usedState] ?? null);
        self::need(is_array($atPublication), 'PUBLICATION_REFERENCE_MISMATCH');
        if ($selection !== null) {
            self::need(array_values(array_diff($roster['roster'],$atPublication)) === $selection['publication_guard']['active_horse_nos'], 'PUBLICATION_REFERENCE_MISMATCH');
        }
        $allocation = $atPublication === $roster['withdrawn'] ? $liveAllocation : self::allocation($frozen,$roster['roster'],$atPublication);
        self::publicationSummary($pub,$allocation,$atPublication,array_intersect_key($bands,$seen),$selection);
        $out['pub']=$pub; $out['candidates']=$rows; $out['available']=$available; $out['quota']=$quota;
        return $out;
    }
}

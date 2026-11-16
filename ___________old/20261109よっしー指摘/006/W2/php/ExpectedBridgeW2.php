<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use InvalidArgumentException;
use App\Http\Controllers\Api\BaganrikiJson;
require_once __DIR__.'/CandidateSelectionW2.php';

require_once __DIR__.'/ReturnStatsW2.php';
require_once __DIR__.'/PublishedReadW2.php';

final class ExpectedBridgeW2
{
    /** Use a NEW common tree before the application's original exact-decimal serializer/hash stage. */
    public static function attach(array $base, array $current, ?array $history, ?array $calibration=null, array $returnStatistics=[]): array
    {
        if (!array_key_exists('prompt_text_revision',$base)) $base['prompt_text_revision']=DispatchW2::activeRevision();
        if (!is_string($base['prompt_text_revision'])) throw new InvalidArgumentException('W19_TEXT_REVISION');
        // Validate the pinned revision before either role is frozen or sent.
        foreach (['1st','2nd'] as $role) DispatchW2::system($role,'FULL',$base['prompt_text_revision']);
        foreach (['snapshot_hash','ai_role','role_cap','role_quota','expected_v01'] as $key)
            if (array_key_exists($key,$base)) throw new InvalidArgumentException('EXPECTED_PRE_HASH_COMMON');
        if (($base['prompt_version']??null)!==CandidateSelectionW2::VERSION || ($base['schema_version']??null)!==CandidateSelectionW2::SCHEMA ||
            ($current['race_id']??null)!==($base['race_id']??null) || ($current['N']??null)!==($base['N']??null) ||
            ExpectedPacketW2::instant($current['post_at'])!=ExpectedPacketW2::instant($base['time_contract']['anchor_at']) ||
            ExpectedPacketW2::instant($current['input_freeze_at'])!=ExpectedPacketW2::instant($base['input_freeze_at'])) {
            throw new InvalidArgumentException('ROOT_BINDING');
        }
        if (!is_array($current['roster']??null) || !ExpectedPacketW2::isList($current['roster']) || !is_array($base['runners']??null)) throw new InvalidArgumentException('ROSTER_BINDING');
        $nos=array_column($base['runners'],'horse_no'); $ids=array_column($current['roster'],'horse_id','horse_no');
        $other=array_keys($ids);sort($nos,SORT_NUMERIC);sort($other,SORT_NUMERIC);
        if ($nos!==$other || count($ids)!==$current['N']) throw new InvalidArgumentException('ROSTER_BINDING');
        $byId=array_flip($ids);
        foreach ([6,21] as $slot) {
            $source=$base['collection_audit'][(string)$slot]??null;
            $batch=$current['batches'][(string)$slot]??null;
            if (($source['status']??null)!=='ADOPTED') {
                if ($batch!==null) throw new InvalidArgumentException('UNADOPTED_BATCH');
                continue;
            }
            if (!is_array($batch)) throw new InvalidArgumentException('MISSING_ADOPTED_BATCH');
            if (($batch['batch_id']??null)!==$source['batch_id']) throw new InvalidArgumentException('BATCH_BINDING');
            foreach (['requested_at'=>'request_started_at','received_at'=>'response_received_at','available_at'=>'available_at'] as $a=>$b)
                if (ExpectedPacketW2::instant($batch[$a]??null)!=ExpectedPacketW2::instant($source[$b]??null))
                    throw new InvalidArgumentException('BATCH_BINDING');
            if (($batch['source_updated_at']??null)!==null &&
                (($source['source_updated_at']??null)===null || ExpectedPacketW2::instant($batch['source_updated_at'])!=ExpectedPacketW2::instant($source['source_updated_at'])))
                throw new InvalidArgumentException('BATCH_SOURCE_TIME_BINDING');
            $current['batches'][$slot]['source_updated_at']=$source['source_updated_at']??null;
            if (!is_array($batch['quotes']??null) || !ExpectedPacketW2::isList($batch['quotes']))
                throw new InvalidArgumentException('INVALID_ADOPTED_QUOTES');
            $seen=[];
            foreach ($batch['quotes'] as $row) {
                $id=$row['horse_id']??null;
                if (!is_string($id) || !isset($byId[$id]) || array_key_exists($byId[$id],$seen)) throw new InvalidArgumentException('QUOTE_OWNER');
                $seen[$byId[$id]]=$row['win']??null;
            }
            foreach($nos as $n) {
                $refs=$base['input_indices']["horse/$n/win_odds/$slot"]??[];
                if (count($refs)>1) throw new InvalidArgumentException('MULTIPLE_ODDS_SOURCES');
                $v=null;
                if ($refs) {
                    $e=$base['source_registry'][$refs[0]]??null;
                    if (!$e || $e['race_id']!==$base['race_id'] || $e['horse_nos']!==[$n] || $e['field']!=='win_odds' || $e['slot_offset']!==$slot || $e['scope']!=='HORSE')
                        throw new InvalidArgumentException('ODDS_REFERENCE');
                    $v=$e['value'];
                }
                $q=$seen[$n]??null;
                // Compare exact decimal values; 6 and 6.0 are equal, distinct close decimals remain distinct.
                if (ExpectedPacketW2::canonicalOdd($q)!==ExpectedPacketW2::canonicalOdd($v)) throw new InvalidArgumentException('ODDS_VALUE_BINDING');
            }
        }
        $current['input_freeze_at']=$base['input_freeze_at'];
        foreach ($base['source_registry'] as $fact) if (ReturnStatsW2::reserved($fact['field']))
            throw new InvalidArgumentException('W12_UNSEALED_RETURN_SOURCE');
        $x=ExpectedPacketW2::build($current,$history,$calibration);
        if ($returnStatistics) {
            $r=ReturnStatsW2::attach($base,$returnStatistics);$base=$r['common'];$x['audit']['return_statistics']=$r['audit'];
        } else $x['audit']['return_statistics']=['included'=>0,'skipped'=>[],'sources'=>[]];
        $base['expected_v01']=$x['packet'];
        $market=MarketStateW2::verify($base);
        $witness=json_decode($x['audit']['expected_proof']['json'],true,512,JSON_THROW_ON_ERROR);
        $x['audit']['expected_proof']=ExpectedPacketW2::sealProof($witness+['market_source_sha256'=>$market]);
        return ['common'=>$base,'audit'=>$x['audit']];
    }

    /** Low-level tree operation only. Workers send bytes through prepareSend().
     * The supplied codec handles the existing SOURCE_TABLE_V1 fields; it never owns the E packet.
     * $sameTree is the application's exact-decimal, type- and ordered-reference-aware comparator.
     * The input must already have passed W2 schema and semantic checks. If older codecs require
     * an older version identifier, upgrade the allow-list explicitly before using this path.
     */
    public static function compact(array $full, callable $pack, callable $expand, callable $sameTree): array
    {
        if (!isset($full['expected_v01']) || ($full['prompt_version']??null)!==CandidateSelectionW2::VERSION)
            throw new InvalidArgumentException('EXPECTED_W2_INPUT');
        $rest=$full; $packet=$rest['expected_v01'];$revision=$rest['prompt_text_revision']??null;
        unset($rest['expected_v01'],$rest['prompt_text_revision']);
        $wire=$pack($rest);
        if (!is_array($wire) || array_key_exists('expected_v01',$wire)) throw new InvalidArgumentException('INVALID_CODEC_OUTPUT');
        $wire['expected_v01']=$packet;
        if ($revision!==null) $wire['prompt_text_revision']=$revision;
        $roundtrip=self::expand($wire,$expand);
        if ($sameTree($full,$roundtrip)!==true) throw new InvalidArgumentException('W2_COMPRESSION_LOSS');
        return $wire;
    }
    /**
     * Worker send path. Budget approval sees the actual JSON bytes plus the selected system mode.
     * A compressed body is eligible only if decoding its exact bytes reproduces the saved role JSON.
     */
    public static function prepareSend(FrozenInputW2 $frozen, callable $pack, callable $expand, callable $withinBudget, callable $liveState, bool $allowFullFallback=false, ?array $repair=null, ?string $preferredFormat=null): array
    {
        $start=AttemptWindowW2::dispatch($frozen,$liveState());$input=FrozenInputW2::checked($frozen);
        $first=null;$scope=null;$required=null;
        if ($preferredFormat!==null && !in_array($preferredFormat,['FULL','SOURCE_TABLE_V1'],true)) throw new InvalidArgumentException('W20_SEND_FORMAT');
        $pinnedFull=RaceMetricsW2::enabled($input) && $input['ai_role']==='1st';
        if ($pinnedFull && $preferredFormat==='SOURCE_TABLE_V1') throw new InvalidArgumentException('W20_SEND_POLICY');
        $preferredFormat=$pinnedFull?'FULL':$preferredFormat;
        if ($repair!==null) {
            [$initial]=AttemptWindowW2::receipts($frozen,[$repair['first_receipt']??null],$start);
            if (!isset($initial[0])) throw new InvalidArgumentException('W9_FIX_SOURCE');
            $first=$initial[0];$required=$first['format'];$scope=AttemptWindowW2::scope($repair['repair_scope']??null,$first['hash'],$input);
            if (($repair['inspection_sha256']??null)!==DispatchW2::inspectionSeal($frozen,$first['hash'],$repair['repair_scope'],$repair['diagnostics']??null))
                throw new InvalidArgumentException('W9_FIX_INSPECTION');
            if ($preferredFormat!==null && $preferredFormat!==$required) throw new InvalidArgumentException('W9_FIX_FORMAT');
            if ($required==='FULL' && !$allowFullFallback && $preferredFormat!=='FULL') throw new InvalidArgumentException('W9_FIX_FORMAT');
        }
        $raw=$frozen->raw();$kind='FULL';$body=$raw;$reason=null;
        if (($required??$preferredFormat)!=='FULL') {
            try {
                $sameInput=static fn($a,$b)=>$frozen->transportJson($a)===$frozen->transportJson($b);
                $wire=self::compact($input,$pack,$expand,$sameInput);
                $packed=$frozen->transportJson($wire);
                $decoded=BaganrikiJson::decode($packed);
                if (!$decoded['ok']) throw new InvalidArgumentException('W2_WIRE_JSON');
                $back=self::expand(FrozenInputW2::typedValues($decoded),$expand);
                $comparison=BaganrikiJson::sameJson($raw,$frozen->transportJson($back),true);
                if (!$comparison['ok'] || !$comparison['same']) throw new InvalidArgumentException('W2_WIRE_EXACT_LOSS');
                if ($packed!==DispatchW2::inputBody($frozen,'SOURCE_TABLE_V1')) throw new InvalidArgumentException('W9_WIRE_SURPLUS');
                $kind='SOURCE_TABLE_V1';$body=$packed;
            } catch (\RuntimeException | \InvalidArgumentException | \JsonException $e) {
                if (!$allowFullFallback || $required==='SOURCE_TABLE_V1') throw new InvalidArgumentException('W2_FULL_NOT_ALLOWED',0,$e);
                $reason=$e->getMessage();
            }
        }
        $number=$repair===null?0:1;
        if ($repair!==null) $body=DispatchW2::correctionBody($frozen,$body,$first['raw'],$scope,$repair['diagnostics']??null);
        if (strlen($body)>4194304) throw new InvalidArgumentException('W9_SEND_SIZE');
        $system=$first!==null?$first['system_text']:DispatchW2::systemFor($input,$kind);
        $messages=[['role'=>'system','content'=>$system],['role'=>'user','content'=>$body]];
        $audit=DispatchW2::record($frozen,$kind,$number,$system,$body);
        if ($withinBudget($kind,$body,$system)!==true) throw new InvalidArgumentException('W2_SEND_BUDGET');
        if ($kind!==$audit['format'] || $body!==$messages[1]['content'] || $system!==$messages[0]['content'])
            throw new InvalidArgumentException('W9_BUDGET_MUTATED_REQUEST');
        $end=AttemptWindowW2::dispatch($frozen,$liveState());
        if ($end['now']<$start['now'] || $end['now']>$start['reply']) throw new InvalidArgumentException('W4_SEND_CLOCK');
        return ['format'=>$kind,'user_json'=>$body,'user_sha256'=>$audit['user_sha256'],'system_text'=>$system,'system_sha256'=>$audit['system_sha256'],
            'messages'=>$messages,'sent_request'=>$audit,'fallback_reason'=>$reason];
    }

    public static function expand(array $wire, callable $expand): array
    {
        if (!isset($wire['expected_v01'])) throw new InvalidArgumentException('MISSING_W2_PACKET');
        $packet=$wire['expected_v01'];$revision=$wire['prompt_text_revision']??null;
        unset($wire['expected_v01'],$wire['prompt_text_revision']);
        $tree=$expand($wire);
        if (!is_array($tree) || array_key_exists('expected_v01',$tree)) throw new InvalidArgumentException('INVALID_CODEC_OUTPUT');
        $tree['expected_v01']=$packet;
        if ($revision!==null) $tree['prompt_text_revision']=$revision;
        return $tree;
    }
}

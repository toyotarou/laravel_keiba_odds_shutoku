<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

final class BaganrikiExpectedBridge
{
    /** Use a NEW common tree before the application's original exact-decimal serializer/hash stage. */
    public static function attach(array $base, array $current, ?array $history, ?array $calibration=null, array $returnStatistics=[]): array
    {
        if (!array_key_exists('prompt_text_revision',$base)) $base['prompt_text_revision']=BaganrikiDispatch::activeRevision();
        if (!is_string($base['prompt_text_revision'])) throw new \InvalidArgumentException('EXPECTED_TEXT_REVISION');
        // Validate the pinned revision before either role is frozen or sent.
        foreach (['1st','2nd'] as $role) BaganrikiDispatch::system($role,'FULL',$base['prompt_text_revision']);
        foreach (['snapshot_hash','ai_role','role_cap','role_quota','expected_v01'] as $key)
            if (array_key_exists($key,$base)) throw new \InvalidArgumentException('EXPECTED_PRE_HASH_COMMON');
        if (($base['prompt_version']??null)!==BaganrikiCandidateSelection::VERSION || ($base['schema_version']??null)!==BaganrikiCandidateSelection::SCHEMA ||
            ($current['race_id']??null)!==($base['race_id']??null) || ($current['N']??null)!==($base['N']??null) ||
            BaganrikiExpectedPacket::instant($current['post_at'])!=BaganrikiExpectedPacket::instant($base['time_contract']['anchor_at']) ||
            BaganrikiExpectedPacket::instant($current['input_freeze_at'])!=BaganrikiExpectedPacket::instant($base['input_freeze_at'])) {
            throw new \InvalidArgumentException('ROOT_BINDING');
        }
        if (!is_array($current['roster']??null) || !BaganrikiExpectedPacket::isList($current['roster']) || !is_array($base['runners']??null)) throw new \InvalidArgumentException('ROSTER_BINDING');
        $nos=array_column($base['runners'],'horse_no'); $ids=array_column($current['roster'],'horse_id','horse_no');
        $other=array_keys($ids);sort($nos,SORT_NUMERIC);sort($other,SORT_NUMERIC);
        if ($nos!==$other || count($ids)!==$current['N']) throw new \InvalidArgumentException('ROSTER_BINDING');
        $byId=array_flip($ids);
        foreach ([6,21] as $slot) {
            $source=$base['collection_audit'][(string)$slot]??null;
            $batch=$current['batches'][(string)$slot]??null;
            if (($source['status']??null)!=='ADOPTED') {
                if ($batch!==null) throw new \InvalidArgumentException('UNADOPTED_BATCH');
                continue;
            }
            if (!is_array($batch)) throw new \InvalidArgumentException('MISSING_ADOPTED_BATCH');
            if (($batch['batch_id']??null)!==$source['batch_id']) throw new \InvalidArgumentException('BATCH_BINDING');
            foreach (['requested_at'=>'request_started_at','received_at'=>'response_received_at','available_at'=>'available_at'] as $a=>$b)
                if (BaganrikiExpectedPacket::instant($batch[$a]??null)!=BaganrikiExpectedPacket::instant($source[$b]??null))
                    throw new \InvalidArgumentException('BATCH_BINDING');
            if (($batch['source_updated_at']??null)!==null &&
                (($source['source_updated_at']??null)===null || BaganrikiExpectedPacket::instant($batch['source_updated_at'])!=BaganrikiExpectedPacket::instant($source['source_updated_at'])))
                throw new \InvalidArgumentException('BATCH_SOURCE_TIME_BINDING');
            $current['batches'][$slot]['source_updated_at']=$source['source_updated_at']??null;
            if (!is_array($batch['quotes']??null) || !BaganrikiExpectedPacket::isList($batch['quotes']))
                throw new \InvalidArgumentException('INVALID_ADOPTED_QUOTES');
            $seen=[];
            foreach ($batch['quotes'] as $row) {
                $id=$row['horse_id']??null;
                if (!is_string($id) || !isset($byId[$id]) || array_key_exists($byId[$id],$seen)) throw new \InvalidArgumentException('QUOTE_OWNER');
                $seen[$byId[$id]]=$row['win']??null;
            }
            foreach($nos as $n) {
                $refs=$base['input_indices']["horse/$n/win_odds/$slot"]??[];
                if (count($refs)>1) throw new \InvalidArgumentException('MULTIPLE_ODDS_SOURCES');
                $v=null;
                if ($refs) {
                    $e=$base['source_registry'][$refs[0]]??null;
                    if (!$e || $e['race_id']!==$base['race_id'] || $e['horse_nos']!==[$n] || $e['field']!=='win_odds' || $e['slot_offset']!==$slot || $e['scope']!=='HORSE')
                        throw new \InvalidArgumentException('ODDS_REFERENCE');
                    $v=$e['value'];
                }
                $q=$seen[$n]??null;
                // Compare exact decimal values; 6 and 6.0 are equal, distinct close decimals remain distinct.
                if (BaganrikiExpectedPacket::canonicalOdd($q)!==BaganrikiExpectedPacket::canonicalOdd($v)) throw new \InvalidArgumentException('ODDS_VALUE_BINDING');
            }
        }
        $current['input_freeze_at']=$base['input_freeze_at'];
        foreach ($base['source_registry'] as $fact) if (BaganrikiReturnStats::reserved($fact['field']))
            throw new \InvalidArgumentException('EXPECTED_UNSEALED_RETURN_SOURCE');
        $x=BaganrikiExpectedPacket::build($current,$history,$calibration);
        if ($returnStatistics) {
            $r=BaganrikiReturnStats::attach($base,$returnStatistics);$base=$r['common'];$x['audit']['return_statistics']=$r['audit'];
        } else $x['audit']['return_statistics']=['included'=>0,'skipped'=>[],'sources'=>[]];
        $base['expected_v01']=$x['packet'];
        $market=BaganrikiMarketState::verify($base);
        $witness=json_decode($x['audit']['expected_proof']['json'],true,512,JSON_THROW_ON_ERROR);
        $x['audit']['expected_proof']=BaganrikiExpectedPacket::sealProof($witness+['market_source_sha256'=>$market]);
        return ['common'=>$base,'audit'=>$x['audit']];
    }

    /** Low-level tree operation only. Workers send bytes through prepareSend().
     * The supplied codec handles the existing SOURCE_TABLE_V1 fields; it never owns the E packet.
     * $sameTree is the application's exact-decimal, type- and ordered-reference-aware comparator.
     * The input must already have passed E3・E5版の schema and semantic checks. If older codecs require
     * an older version identifier, upgrade the allow-list explicitly before using this path.
     */
    /** 圧縮して戻したときに違った場所（調査用） */
    public static ?string $lastLoss = null;

    private static function sortForDiff(mixed $v): mixed
    {
        if (is_object($v) && !($v instanceof \JsonSerializable)) $v=(array)$v;
        if (!is_array($v)) return $v;
        if ($v!==[] && array_keys($v)!==range(0,count($v)-1)) ksort($v,SORT_STRING);
        foreach ($v as $k=>$c) $v[$k]=self::sortForDiff($c);
        return $v;
    }

    private static function firstDifference(string|false $a, string|false $b): string
    {
        $a=(string)$a; $b=(string)$b; $n=min(strlen($a),strlen($b)); $i=0;
        while ($i<$n && $a[$i]===$b[$i]) $i++;
        $from=max(0,$i-120);
        return '位置'.$i.' 元「'.mb_strcut($a,$from,240).'」 戻し「'.mb_strcut($b,$from,240).'」';
    }

    public static function compact(array $full, callable $pack, callable $expand, callable $sameTree): array
    {
        if (!isset($full['expected_v01']) || ($full['prompt_version']??null)!==BaganrikiCandidateSelection::VERSION)
            throw new \InvalidArgumentException('EXPECTED_SCORE_INPUT');
        $rest=$full; $packet=$rest['expected_v01'];$revision=$rest['prompt_text_revision']??null;
        unset($rest['expected_v01'],$rest['prompt_text_revision']);
        $wire=$pack($rest);
        if (!is_array($wire) || array_key_exists('expected_v01',$wire)) throw new \InvalidArgumentException('INVALID_CODEC_OUTPUT');
        $wire['expected_v01']=$packet;
        if ($revision!==null) $wire['prompt_text_revision']=$revision;
        $roundtrip=self::expand($wire,$expand);
        if ($sameTree($full,$roundtrip)!==true) {
            // 調査用：どこが違ったかを記録（最初に違う位置の前後）。値の中身は監査ログに残すだけでAIには送らない
            self::$lastLoss=self::firstDifference(json_encode(self::sortForDiff($full),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
                                                  json_encode(self::sortForDiff($roundtrip),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
            throw new \InvalidArgumentException('EXPECTED_COMPRESSION_LOSS');
        }
        return $wire;
    }
    /**
     * Worker send path. Budget approval sees the actual JSON bytes plus the selected system mode.
     * A compressed body is eligible only if decoding its exact bytes reproduces the saved role JSON.
     */
    public static function prepareSend(BaganrikiFrozenInput $frozen, callable $pack, callable $expand, callable $withinBudget, callable $liveState, bool $allowFullFallback=false, ?array $repair=null, ?string $preferredFormat=null): array
    {
        $start=BaganrikiAttemptWindow::dispatch($frozen,$liveState());$input=BaganrikiFrozenInput::checked($frozen);
        $first=null;$scope=null;$required=null;
        if ($preferredFormat!==null && !in_array($preferredFormat,BaganrikiDispatch::FORMATS,true)) throw new \InvalidArgumentException('EXPECTED_SEND_FORMAT');
        // 【R15.21】1st は FULL 固定でした。【いま】よっしー11/9「1stはA（名前で引ける圧縮形式）に統一」により、
        //   1st は SOURCE_DICT_V3（名前で引ける形）に固定します。2nd は SOURCE_TABLE_V1 のまま（BaganrikiDispatch::wireFormatFor）。
        $pinned=BaganrikiDispatch::wireFormatFor($input['ai_role']);
        if ($input['ai_role']==='1st' && $preferredFormat!==null && $preferredFormat!==$pinned) throw new \InvalidArgumentException('EXPECTED_SEND_POLICY');
        $preferredFormat=$preferredFormat??$pinned;
        if ($repair!==null) {
            [$initial]=BaganrikiAttemptWindow::receipts($frozen,[$repair['first_receipt']??null],$start);
            if (!isset($initial[0])) throw new \InvalidArgumentException('EXPECTED_FIX_SOURCE');
            $first=$initial[0];$required=$first['format'];$scope=BaganrikiAttemptWindow::scope($repair['repair_scope']??null,$first['hash'],$input);
            if (($repair['inspection_sha256']??null)!==BaganrikiDispatch::inspectionSeal($frozen,$first['hash'],$repair['repair_scope'],$repair['diagnostics']??null))
                throw new \InvalidArgumentException('EXPECTED_FIX_INSPECTION');
            if ($preferredFormat!==null && $preferredFormat!==$required) throw new \InvalidArgumentException('EXPECTED_FIX_FORMAT');
            if ($required==='FULL' && !$allowFullFallback && $preferredFormat!=='FULL') throw new \InvalidArgumentException('EXPECTED_FIX_FORMAT');
        }
        $raw=$frozen->raw();$kind='FULL';$body=$raw;$reason=null;
        if (($required??$preferredFormat)!=='FULL') {
            try {
                $sameInput=static fn($a,$b)=>$frozen->transportJson($a)===$frozen->transportJson($b);
                $wire=self::compact($input,$pack,$expand,$sameInput);
                $packed=$frozen->transportJson($wire);
                $decoded=BaganrikiJson::decode($packed);
                if (!$decoded['ok']) throw new \InvalidArgumentException('EXPECTED_WIRE_JSON');
                $back=self::expand(BaganrikiFrozenInput::typedValues($decoded),$expand);
                $comparison=BaganrikiJson::sameJson($raw,$frozen->transportJson($back),true);
                if (!$comparison['ok'] || !$comparison['same']) throw new \InvalidArgumentException('EXPECTED_WIRE_EXACT_LOSS');
                $wireFormat=$required??$preferredFormat;
                if ($packed!==BaganrikiDispatch::inputBody($frozen,$wireFormat)) throw new \InvalidArgumentException('EXPECTED_WIRE_SURPLUS');
                $kind=$wireFormat;$body=$packed;
            } catch (\RuntimeException | \InvalidArgumentException | \JsonException $e) {
                if (!$allowFullFallback || ($required!==null && $required!=='FULL')) throw new \InvalidArgumentException('EXPECTED_FULL_NOT_ALLOWED',0,$e);
                $reason=$e->getMessage();
            }
        }
        $number=$repair===null?0:1;
        if ($repair!==null) $body=BaganrikiDispatch::correctionBody($frozen,$body,$first['raw'],$scope,$repair['diagnostics']??null);
        if (strlen($body)>4194304) throw new \InvalidArgumentException('EXPECTED_SEND_SIZE');
        $system=$first!==null?$first['system_text']:BaganrikiDispatch::systemFor($input,$kind);
        $messages=[['role'=>'system','content'=>$system],['role'=>'user','content'=>$body]];
        $audit=BaganrikiDispatch::record($frozen,$kind,$number,$system,$body);
        if ($withinBudget($kind,$body,$system)!==true) throw new \InvalidArgumentException('EXPECTED_SEND_BUDGET');
        if ($kind!==$audit['format'] || $body!==$messages[1]['content'] || $system!==$messages[0]['content'])
            throw new \InvalidArgumentException('EXPECTED_BUDGET_MUTATED_REQUEST');
        $end=BaganrikiAttemptWindow::dispatch($frozen,$liveState());
        if ($end['now']<$start['now'] || $end['now']>$start['reply']) throw new \InvalidArgumentException('EXPECTED_SEND_CLOCK');
        return ['format'=>$kind,'user_json'=>$body,'user_sha256'=>$audit['user_sha256'],'system_text'=>$system,'system_sha256'=>$audit['system_sha256'],
            'messages'=>$messages,'sent_request'=>$audit,'fallback_reason'=>$reason];
    }

    public static function expand(array $wire, callable $expand): array
    {
        if (!isset($wire['expected_v01'])) throw new \InvalidArgumentException('MISSING_EXPECTED_PACKET');
        $packet=$wire['expected_v01'];$revision=$wire['prompt_text_revision']??null;
        unset($wire['expected_v01'],$wire['prompt_text_revision']);
        $tree=$expand($wire);
        if (!is_array($tree) || array_key_exists('expected_v01',$tree)) throw new \InvalidArgumentException('INVALID_CODEC_OUTPUT');
        $tree['expected_v01']=$packet;
        if ($revision!==null) $tree['prompt_text_revision']=$revision;
        return $tree;
    }
}

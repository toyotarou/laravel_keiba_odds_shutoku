<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
require_once __DIR__ . '/../formula/ExpectedValueV01.php';

/** 凍結済みのデータから両AI共通のE3/E5パケットを作る。DB取得・公開は呼出側。 */
final class ExpectedPacketW2
{
    public const POLICY = 'E35_BAND_BOUNDARY_V1';
    public const FORMULA = 'baganriki-expected-v0.1-draft-20261025';
    public const ENGINE = 'baganriki-expected-impl-v0.1.4';
    private const CLASSES = ['NEW','MAIDEN','C1','C2','C3','OP','L','G1','G2','G3'];

    private static function need(bool $ok, string $why): void
    {
        if (!$ok) throw new InvalidArgumentException($why);
    }

    public static function instant(mixed $text): DateTimeImmutable
    {
        self::need(is_string($text) && preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/Di',$text)===1, 'INVALID_TIME');
        try { $time = new DateTimeImmutable($text); } catch (\Exception $e) { throw new InvalidArgumentException('INVALID_TIME',0,$e); }
        $err = DateTimeImmutable::getLastErrors();
        self::need(!$err || (!$err['warning_count'] && !$err['error_count']), 'INVALID_TIME');
        return $time;
    }

    public static function isList(array $a): bool
    {
        return $a===[] || array_keys($a)===range(0,count($a)-1);
    }

    /** Compare input odds exactly for ranking before conversion to the formula's float implementation. */
    public static function oddsParts(mixed $value): ?array
    {
        if ($value instanceof \App\Http\Controllers\Api\BaganrikiDecimal) $value=$value->raw();
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        if (is_float($value) && !is_finite($value)) return null;
        $s = is_string($value) ? $value : json_encode($value, JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        if (!preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D',$s,$m)) return null;
        $digits=$m[1].($m[2]??'');
        $stripped=ltrim($digits,'0');
        if ($stripped==='' || strlen($stripped)>128 || strlen(ltrim($m[3]??'0','+-0'))>6) return null;
        $places=strlen($m[1])+(int)($m[3]??0)-(strlen($digits)-strlen($stripped));
        if ($places-1 < -308 || $places-1 > 308) return null;
        return [$places,str_pad(rtrim($stripped,'0'),128,'0'),$s];
    }

    public static function canonicalOdd(mixed $value): ?string
    {
        if ($value===null) return null;
        $parts=self::oddsParts($value);
        self::need($parts!==null,'INVALID_ODDS_VALUE');
        return $parts[0].':'.$parts[1];
    }

    private static function roster(array $r): array
    {
        $n=$r['N']??null; $list=$r['roster']??null;
        self::need(is_int($n) && $n>=1 && $n<=18 && is_array($list) && self::isList($list) && count($list)===$n,'INVALID_FROZEN_ROSTER');
        $out=[]; $ids=[];
        foreach ($list as $h) {
            $no=$h['horse_no']??null; $id=$h['horse_id']??null;
            self::need(is_int($no) && $no>=1 && $no<=18 && !isset($out[$no]) && is_string($id) && trim($id)!=='' && !isset($ids['h:'.$id]),'INVALID_HORSE_ID');
            $out[$no]=$id; $ids['h:'.$id]=true;
        }
        ksort($out,SORT_NUMERIC);
        return $out;
    }

    private static function quotes(array $r, array $roster, int $slot, DateTimeImmutable $post, DateTimeImmutable $freeze, bool $partialHistory=false,array &$ambiguous=[],?string &$batchIssue=null): array
    {
        $b=$r['batches'][(string)$slot]??null;
        if (!is_array($b) || !is_string($b['batch_id']??null) || trim($b['batch_id'])==='') { $batchIssue='MISSING_BATCH';return []; }
        try {
            $request=self::instant($b['requested_at']??null); $received=self::instant($b['received_at']??null);
            $available=self::instant($b['available_at']??null);
            if (($b['source_updated_at']??null)!==null && self::instant($b['source_updated_at'])>$received) { $batchIssue='FUTURE_SOURCE_UPDATE';return []; }
        } catch (Throwable $e) { $batchIssue='INVALID_BATCH_TIME';return []; }
        $start=$post->modify("-$slot minutes");
        $end=$start->modify($slot===6?'+30 seconds':'+120 seconds');
        if ($request<$start || $request>$received || $received>$available || $available>$end || $available>$freeze) { $batchIssue='OUTSIDE_BATCH_WINDOW';return []; }
        $quotes=$b['quotes']??null;
        if (!is_array($quotes) || !self::isList($quotes)) { $batchIssue='INVALID_QUOTE_LIST';return []; }
        $byId=[]; $seen=[]; $nos=array_flip($roster);
        foreach ($quotes as $q) {
            $id=$q['horse_id']??null;
            if (!is_string($id) || !isset($nos[$id])) { $ambiguous=[];$batchIssue='UNKNOWN_QUOTE_OWNER';return []; }
            if (isset($seen['h:'.$id])) {
                if (!$partialHistory || $slot!==21) { $batchIssue='DUPLICATE_QUOTE_OWNER';return []; }
                // Reject every version of this horse, including the first one we saw.
                unset($byId[$nos[$id]]);$ambiguous[$nos[$id]]=true;continue;
            }
            $seen['h:'.$id]=true; $value=self::oddsParts($q['win']??null);
            if ($value!==null) $byId[$nos[$id]]=$value;
        }
        return $byId;
    }

    public static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Digest of admitted formula inputs. Raw provider payload hashes remain in the acquisition audit. */
    private static function batchIdentity(?array $batch,array $quotes): ?array
    {
        if (!$quotes) return null;
        ksort($quotes,SORT_NUMERIC); $values=[];
        foreach ($quotes as $no=>$token) $values[$no]=$token[0].':'.rtrim($token[1],'0');
        self::need($batch!==null,'W7_ODDS_BATCH_MISSING');
        return [$batch['batch_id'],self::utc(self::instant($batch['requested_at'])),self::utc(self::instant($batch['received_at'])),
            self::utc(self::instant($batch['available_at'])),$values];
    }

    private static function binding(string $race,DateTimeImmutable $freeze,array $numbers,?array $win6,?array $win21):string
    {
        sort($numbers,SORT_NUMERIC);
        return hash('sha256',json_encode(['W7_ODDS_BINDING_V1',$race,self::utc($freeze),$numbers,$win6,$win21],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /** Compare independently with the calculator's committed odds, not just with its output probabilities. */
    public static function bindingForFrozen(array $in):string
    {
        $numbers=array_column($in['runners'],'horse_no');$identities=[];
        foreach ([6,21] as $slot) {
            $audit=$in['collection_audit'][$slot]??null;$batch=null;$quotes=[];
            if (($audit['status']??null)==='ADOPTED') {
                $batch=['batch_id'=>$audit['batch_id'],'requested_at'=>$audit['request_started_at'],
                        'received_at'=>$audit['response_received_at'],'available_at'=>$audit['available_at']];
                foreach ($numbers as $n) {
                    $refs=$in['input_indices']["horse/$n/win_odds/$slot"]??[];
                    $value=$refs?($in['source_registry'][$refs[0]]['value']??null):null;
                    if ($value===null) continue;
                    $parts=self::oddsParts($value);self::need($parts!==null,'W3_ODDS_VALUE');
                    $quotes[$n]=$parts;
                }
            }
            $identities[$slot]=self::batchIdentity($batch,$quotes);
        }
        return self::binding($in['race_id'],self::instant($in['input_freeze_at']),$numbers,$identities[6],$identities[21]);
    }

    private static function prepare(array $r, bool $history): array
    {
        $roster=self::roster($r);
        self::need(is_string($r['race_id']??null) && trim($r['race_id'])!=='','INVALID_RACE_ID');
        $post=self::instant($r['post_at']??null); $freeze=self::instant($r['input_freeze_at']??null);
        self::need($post->modify('-330 seconds')==$freeze,'INVALID_FREEZE');
        $day=$post->setTimezone(new DateTimeZone('Asia/Tokyo'))->setTime(0,0);
        $ambiguous=[];$win=self::quotes($r,$roster,6,$post,$freeze);
        $earlyIssue=null;$early=self::quotes($r,$roster,21,$post,$freeze,$history,$ambiguous,$earlyIssue);
        ksort($ambiguous,SORT_NUMERIC);
        $all=count($win)===count($roster);
        $order=$all ? array_keys($win) : [];
        usort($order,static fn($a,$b)=>($win[$a][0]<=>$win[$b][0]) ?: strcmp($win[$a][1],$win[$b][1]) ?: ($a<=>$b));
        $features=['surface'=>$r['surface']??null,'race_type'=>$r['race_type']??null,
            'class_group'=>$r['class_group']??null,'distance_metres'=>$r['distance_metres']??null,
            'odds6_by_rank'=>array_map(static fn($n)=>$win[$n][2],$order)];
        $finiteSix=true; foreach ($win as $odd) if (!is_finite((float)$odd[2])) { $finiteSix=false;break; }
        $validMeta=in_array($features['class_group'],self::CLASSES,true) && is_int($features['distance_metres']) && $features['distance_metres']>0;
        $finish=[];
        if ($history) {
            self::need(($r['post_freeze_scratch']??null)===false && $all, 'HIST_ROSTER_OR_SCRATCH');
            $outcomes=$r['results']??null;
            self::need(is_array($outcomes) && self::isList($outcomes) && count($outcomes)===count($roster),'INCOMPLETE_RESULTS');
            $nos=array_flip($roster);
            foreach($outcomes as $one) {
                $id=$one['horse_id']??null; $pos=$one['finish']??null;
                self::need(is_string($id) && isset($nos[$id]) && !isset($finish[$nos[$id]]),'HIST_RESULT_ID');
                self::need((is_int($pos)&&$pos>=1&&$pos<=count($roster)) || in_array($pos,['DNF','DQ'],true),'UNKNOWN_RESULT');
                $finish[$nos[$id]]=is_int($pos)?$pos:PHP_INT_MAX;
            }
        }
        if ($history) {
            $places=[];
            foreach ($finish as $place) if ($place!==PHP_INT_MAX) $places[$place]=($places[$place]??0)+1;
            self::need($places!==[],'HIST_NO_CLASSIFIED_FINISH');
            ksort($places,SORT_NUMERIC);$next=1;
            foreach ($places as $place=>$count) {
                self::need($place===$next,'HIST_FINISH_SEQUENCE');
                $next+=$count;
            }
        }
        ksort($finish,SORT_NUMERIC);
        $win6=self::batchIdentity(is_array($r['batches'][6]??null)?$r['batches'][6]:null,$win);
        $win21=self::batchIdentity(is_array($r['batches'][21]??null)?$r['batches'][21]:null,$early);
        $fingerprint=['race'=>$r['race_id'],'jurisdiction'=>$r['jurisdiction']??null,'post'=>self::utc($post),'freeze'=>self::utc($freeze),
            'roster'=>$roster,'surface'=>$features['surface'],'type'=>$features['race_type'],'class'=>$features['class_group'],
            'distance'=>$features['distance_metres'],'win6'=>$win6,'win21'=>$win21];
        if ($history) {
            $fingerprint['finish']=$finish;
            $fingerprint['result_available_at']=self::utc(self::instant($r['result_available_at']));
        }
        $sourceHash=hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return ['source_hash'=>$sourceHash,'market_binding'=>$history?null:self::binding($r['race_id'],$freeze,array_keys($roster),$win6,$win21),'id'=>$r['race_id'],'roster'=>$roster,'day'=>$day,'features'=>$features,'valid_meta'=>$validMeta,
            'order'=>$order,'early'=>$early,'ambiguous_21'=>array_keys($ambiguous),'early_issue'=>$earlyIssue,'finish'=>$finish,'freeze'=>$freeze,'complete'=>$all,'finite_six'=>$finiteSix];
    }

    private static function nullRow(int $no, ?int $rank, string $status, string $why): array
    {
        return ['horse_no'=>$no,'formula_rank'=>$rank,'status'=>$status,'basis'=>'UNAVAILABLE',
            'M'=>null,'K'=>null,'W'=>null,'n_eff'=>null,'p0_3'=>null,'p0_5'=>null,
            'p3_raw'=>null,'p5_raw'=>null,'E3'=>null,'E5'=>null,'reason'=>$why];
    }

    public static function sealProof(array $witness):array
    {
        $json=json_encode($witness,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        self::need(json_decode($json,true,512,JSON_THROW_ON_ERROR)===$witness,'W6_E_ENCODE_PRECISION');
        return ['json'=>$json,'sha256'=>hash('sha256',$json)];
    }

    /** $history=null means the archive is unavailable; [] means the archive was queried and P is empty. */
    public static function build(array $current, ?array $history, ?array $calibration=null): array
    {
        foreach (['results','result_available_at','post_freeze_scratch','payout'] as $key) {
            self::need(!array_key_exists($key,$current),'OUTCOME_IN_TARGET');
        }
        $c=self::prepare($current,false); $day=$c['day']; $excluded=[];
        $cal=null;
        if ($calibration!==null) {
            self::need(($calibration['formula_version']??null)===self::FORMULA && ($calibration['scope']??null)==='JRA_FLAT_TURF_DIRT'
                && is_string($calibration['id']??null) && trim($calibration['id'])!=='' && self::instant($calibration['trained_through']??null)<$day
                && self::instant($calibration['approved_at']??null)<$day && self::instant($calibration['trained_through'])<=self::instant($calibration['approved_at']),'INVALID_CALIBRATOR');
            foreach (['a','b'] as $key) self::need(is_int($calibration[$key]??null)||is_float($calibration[$key]??null),'INVALID_COEFFICIENT');
            self::need(is_finite((float)$calibration['a']) && is_finite((float)$calibration['b']) && $calibration['a']>=0.1 && $calibration['a']<=10 && abs($calibration['b'])<=10,'INVALID_COEFFICIENT');
            $cal=['a'=>$calibration['a'],'b'=>$calibration['b']];
        }
        $pool=[]; $hashes=[]; $visible=[]; $partial=[];
        foreach ($history??[] as $r) {
            try {
                self::need(is_array($r),'INVALID_HISTORY_SCOPE');
                $id=$r['race_id']??null;
                self::need(is_string($id) && trim($id)!=='' && $id!==$c['id'],'INVALID_HISTORY_ID');
                self::need(self::instant($r['result_available_at']??null)<$day,'HISTORY_AVAILABILITY');
                // Race identity and as-of visibility precede mutable fields of a particular revision.
                $visible['r:'.$id][]=$r;
            } catch (Throwable $e) { $why=$e->getMessage(); $excluded[$why]=($excluded[$why]??0)+1; }
        }
        foreach ($visible as $key=>$versions) {
            if (count($versions)>1) {
                $excluded['DUPLICATE_HISTORY_ID']=($excluded['DUPLICATE_HISTORY_ID']??0)+count($versions);
                continue;
            }
            $r=$versions[0];
            try {
                self::need(($r['jurisdiction']??null)==='JRA','INVALID_HISTORY_SCOPE');
                $post=self::instant($r['post_at']??null);
                self::need(self::instant($r['result_available_at'])>=$post,'HISTORY_AVAILABILITY');
                $raceDay=$post->setTimezone($day->getTimezone())->setTime(0,0);
                self::need($raceDay<$day && $raceDay>=$day->modify('-730 days'),'HISTORY_DATE');
                $h=self::prepare($r,true);
                self::need($h['features']['surface']===$c['features']['surface'] && $h['features']['race_type']==='FLAT' && count($h['roster'])===count($c['roster']),'HISTORY_SCOPE');
                $pool[$key]=$h;
                $hashes[$key]=$h['source_hash'];
                $missing=array_keys(array_diff_key($h['roster'],$h['early']));
                if ($missing) $partial[$key]=['race_id'=>$h['id'],'unavailable_odds21_horse_nos'=>$missing,
                    'ambiguous_odds21_horse_nos'=>$h['ambiguous_21'],'odds21_batch_issue'=>$h['early_issue']];
            } catch (Throwable $e) { $why=$e->getMessage(); $excluded[$why]=($excluded[$why]??0)+1; }
        }
        ksort($pool,SORT_STRING); ksort($hashes,SORT_STRING); ksort($partial,SORT_STRING); ksort($excluded,SORT_STRING);
        $historyHash=$history===null?null:hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR));
        $scope=($current['jurisdiction']??null)==='JRA' && $c['features']['race_type']==='FLAT' && in_array($c['features']['surface'],['TURF','DIRT'],true);
        $rankMap=array_flip($c['order']); $rows=[]; $audit=[];
        foreach ($c['roster'] as $no=>$horseId) {
            $rank=isset($rankMap[$no])?$rankMap[$no]+1:null;
            $reason=null;
            if (!$scope) $reason='OUT_OF_SCOPE';
            elseif (!$c['complete']) $reason='INCOMPLETE_ODDS6';
            elseif (!$c['valid_meta']) $reason='MISSING_CONDITIONS';
            elseif (!isset($c['early'][$no])) $reason='MISSING_ODDS21';
            elseif (!$c['finite_six'] || !is_finite((float)$c['early'][$no][2])) $reason='FORMULA_NUMERIC_RANGE';
            elseif ($history===null) $reason='HISTORY_NOT_PROVIDED';
            if ($reason!==null) {
                $rows[]=self::nullRow($no,$rank,$scope?'UNAVAILABLE_INPUT':'OUT_OF_SCOPE',$reason); continue;
            }
            $b3=0; $b5=0; $near=[];
            $currentFeatures=$c['features']+['odds21_at_rank'=>$c['early'][$no][2]];
            foreach ($pool as $h) {
                $prevNo=$h['order'][$rank-1]; $pos=$h['finish'][$prevNo];
                $y3=(int)($pos<=3); $y5=(int)($pos<=5); $b3+=$y3; $b5+=$y5;
                if (!$h['valid_meta'] || !$h['finite_six'] || !isset($h['early'][$prevNo]) || !is_finite((float)$h['early'][$prevNo][2])) continue;
                $histFeatures=$h['features']+['odds21_at_rank'=>$h['early'][$prevNo][2]];
                $dist=ExpectedValueV01::distanceSquared($currentFeatures,$histFeatures,$rank);
                if ($dist>9) continue;
                $age=(int)$h['day']->diff($day)->days;
                $near[]=['race_id'=>$h['id'],'date'=>$h['day']->format('Y-m-d'),'d2'=>$dist,
                    'w'=>ExpectedValueV01::weight($dist,$age),'y3'=>$y3,'y5'=>$y5];
            }
            usort($near,static fn($a,$b)=>($b['w']<=>$a['w']) ?: ($a['d2']<=>$b['d2']) ?: strcmp($b['date'],$a['date']) ?: strcmp($a['race_id'],$b['race_id']));
            $near=array_slice($near,0,200);
            $x=ExpectedValueV01::calculate(count($pool),$b3,$b5,$near,20.0,$cal);
            $p3=$x['p3_cal']??$x['p3_raw']; $p5=$x['p5_cal']??$x['p5_raw'];
            $rows[]=['horse_no'=>$no,'formula_rank'=>$rank,'status'=>$x['support_status'],'basis'=>$x['score_basis'],
                'M'=>count($pool),'K'=>$x['neighbour_count'],'W'=>$x['W'],'n_eff'=>$x['n_eff'],
                'p0_3'=>$x['baseline']['p0_3'],'p0_5'=>$x['baseline']['p0_5'],'p3_raw'=>$x['p3_raw'],'p5_raw'=>$x['p5_raw'],
                'E3'=>$p3===null?null:100*$p3,'E5'=>$p5===null?null:100*$p5,
                'reason'=>$x['support_status']==='COMPARISON_READY'?null:$x['support_status']];
            $audit[$no]=['horse_id'=>$horseId,'calculator'=>$x,'neighbours'=>$near];
        }
        $packet=['policy_id'=>self::POLICY,'formula_version'=>self::FORMULA,'implementation_version'=>self::ENGINE,
            'race_id'=>$c['id'],'input_freeze_at'=>$current['input_freeze_at'],'history_before'=>$day->format(DATE_ATOM),
            'current_sha256'=>$c['source_hash'],'history_sha256'=>$historyHash,'calibration_id'=>$calibration['id']??null,'rows'=>$rows];
        // Store these original calculator bytes alongside the input audit, before an AI payload is assembled.
        $witness=$packet+['odds_binding_sha256'=>$c['market_binding']];
        return ['packet'=>$packet,'audit'=>['pool_ids'=>array_column($pool,'id'),'source_hashes'=>$hashes,'excluded'=>$excluded,'partial_history'=>array_values($partial),
            'calibration'=>$calibration,'horses'=>$audit,'expected_proof'=>self::sealProof($witness)]];
    }
}

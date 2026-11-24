<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** W1の検証済み基礎順位から一度だけ適用する。AIの自己申告・表示用の丸め値を使わない。 */
final class BaganrikiCandidateSelection
{
    /** AIとやり取りする予想の版（E3・E5を使う版）。AIが返事にこの値を書き、検査でも照合する契約の値なので中身は変えない */
    public const VERSION='baganriki-v041-r12-2-w2-e35-r15-20261104';
    public const SCHEMA='baganriki-v041-r12-2-w2-e35-r15';
    public const BANDS=['A','B','D'];
    /** 直前の submittedPublication で、各AIの返事を使えなかった理由（公開できなかったときに理由へ書き足します） */
    public static array $lastRoleIssues=[];
    public const REASONS=['SELECTION_DISABLED','NO_BOUNDARY','TIER_MISMATCH','E_UNAVAILABLE','BASIS_MISMATCH','KEEP','SWAP'];

    private static function need(bool $ok, string $message): void
    {
        if (!$ok) throw new \InvalidArgumentException($message);
    }
    private static function setEq(array $a,array $b): bool
    {
        sort($a,SORT_NUMERIC); sort($b,SORT_NUMERIC);
        return $a===$b;
    }
    private static function num(mixed $n): bool
    {
        return (is_int($n)||is_float($n)) && is_finite((float)$n);
    }
    private static function sameMap(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) return $a === $b;
        ksort($a); ksort($b);
        return $a === $b;
    }

    private static function target(int $n): int
    {
        if ($n <= 8) return min(4, $n);
        if ($n <= 13) return 5;
        return $n <= 15 ? 6 : 7;
    }

    /** A runner's identity and popularity band remain frozen even after a cancellation. */
    private static function frozenBands(array $in): array
    {
        $bands = []; $ranks = [];
        foreach ($in['runners'] as $h) {
            $n = $h['horse_no']; $rank = $h['win_rank'];
            self::need(is_int($n) && $n >= 1 && $n <= 18 && !isset($bands[$n]) &&
                is_int($rank) && $rank >= 1 && $rank <= $in['N'] && !isset($ranks[$rank]) &&
                $h['band'] === ($rank <= 6 ? 'A' : ($rank <= 10 ? 'B' : 'D')), 'EXPECTED_BASE_ROSTER');
            $bands[$n] = $h['band']; $ranks[$rank] = true;
        }
        self::need(count($bands) === $in['N'] && $in['N'] >= 1 && $in['N'] <= 18 &&
            $in['target_count'] === self::target($in['N']) &&
            ($in['schema_version'] ?? null) === self::SCHEMA && ($in['prompt_version'] ?? null) === self::VERSION &&
            is_string($in['snapshot_hash']) && preg_match('/^[0-9a-f]{64}$/D', $in['snapshot_hash']) === 1, 'EXPECTED_BASE_ID');
        $mode = $in['allocation_mode'];
        $confirmed = $in['mode_status'] === 'CONFIRMED' && $in['mode'] === $mode &&
            $in['mode_policy_id'] === 'BASE_TRI_STATE_V1' && in_array($mode, ['FIRM','MIXED','VOLATILE'], true);
        $provisional = $in['mode_status'] === 'PROVISIONAL' && $in['mode'] === 'UNKNOWN' &&
            $in['mode_policy_id'] === 'X6_ONLY_UPPER_MODE_V1' && in_array($mode, ['MIXED','VOLATILE'], true);
        $unresolved = $in['mode_status'] === 'UNRESOLVED' && $in['mode'] === 'UNKNOWN' &&
            $mode === null && $in['mode_policy_id'] === 'BASE_TRI_STATE_V1';
        self::need(($in['selection_enabled'] === true && ($confirmed || $provisional)) ||
            ($in['selection_enabled'] === false && $unresolved), 'EXPECTED_SELECTION_STATE');
        return $bands;
    }

    /** Recalculate the 新方式 structural quota using only the remaining frozen bands. */
    private static function structuralQuota(?string $mode, array $bands, ?array &$base=null, ?array &$moves=null): ?array
    {
        $n = count($bands); $base=null; $moves=[];
        if ($n === 0 || $mode === null) return null;
        $cap = self::target($n);
        if ($cap <= 3) {
            $q = ['A'=>$cap,'B'=>0,'D'=>0];
        } else {
            $q = array_combine(self::BANDS, self::contract()['base_quota'][$cap][$mode]);
        }
        $base=$q;
        $capacity = array_count_values($bands) + array_fill_keys(self::BANDS, 0);
        foreach (['D'=>['B','A'], 'B'=>['D','A'], 'A'=>['B','D']] as $from=>$destinations) {
            foreach ($destinations as $to) {
                $move = min(max(0, $q[$from]-$capacity[$from]), max(0, $capacity[$to]-$q[$to]));
                $q[$from] -= $move; $q[$to] += $move;
                if ($move>0) $moves[$from.'>'.$to]=$move;
            }
        }
        return $q;
    }

    /** Use the selection engine's existing allocation table for a verified set of active, frozen bands. */
    public static function publicationAllocation(?string $mode, array $bands): array
    {
        return ['target_count'=>self::target(count($bands)), 'quota'=>self::structuralQuota($mode,$bands)];
    }

    private static function roleQuota(?array $quota, string $role): ?array
    {
        self::need(in_array($role, ['1st','2nd'], true), 'EXPECTED_ROLE');
        if ($quota !== null && $role === '2nd' && array_sum($quota) > 5) $quota['A'] = 5-$quota['B']-$quota['D'];
        return $quota;
    }

    private static function contract(): array
    {
        static $contract=null;
        if ($contract===null) {
            $contract=json_decode(file_get_contents(BaganrikiExpectedFiles::path('config/contract.json')),true,512,JSON_THROW_ON_ERROR);
        }
        self::need($contract['prompt_version']===self::VERSION,'EXPECTED_CONFIG_VERSION');
        return $contract;
    }

    /** Validate score components already verified against their source evidence by 新方式. */
    private static function scoreKey(array $horse,bool $checkTotals=true): ?array
    {
        $scores=$horse['scores']??null; $caps=self::contract()['score_caps'];
        self::need(is_array($scores) && count($scores)===count($caps),'EXPECTED_SCORE_FIELDS');
        $sum=0; $capSum=0; $v=0;
        foreach ($caps as $key=>$cap) {
            self::need(array_key_exists($key,$scores),'EXPECTED_SCORE_FIELDS');
            $points=$scores[$key];
            if ($points===null) continue;
            self::need(is_int($points) && $points>=0 && $points<=$cap,'EXPECTED_SCORE_COMPONENT');
            $sum+=$points; $capSum+=$cap;
            if ($key[0]==='V') $v+=$points;
        }
        $complete=$capSum===array_sum($caps);
        if ($checkTotals) self::need($horse['observed_sum']===$sum && $horse['observed_cap']===$capSum &&
            $horse['score_status']===($complete?'complete':'partial') &&
            $horse['total_score']===($complete?$sum:null),'EXPECTED_SCORE_TOTAL');
        return $complete?[$sum,$v,$sum-$v]:null;
    }

    /** Complete, verified scores determine a whole band's order; otherwise the evidence order is needed. */
    public static function scoreOrder(array $horses):?array
    {
        return self::orderFromScores($horses,true);
    }

    private static function orderFromScores(array $horses,bool $checkTotals):?array
    {
        $keys=[];$complete=true;
        foreach ($horses as $horse) {
            $key=self::scoreKey($horse,$checkTotals);$keys[$horse['horse_no']]=$key;
            if ($key===null) $complete=false;
        }
        if (!$complete) return null;
        $order=array_keys($keys);
        usort($order,static fn($a,$b)=>($keys[$b]<=>$keys[$a]) ?: ($a<=>$b));
        return $order;
    }

    public static function scoreCaps():array { return self::contract()['score_caps']; }

    /** Used on all model or recovered rows, including a horse that has no public rank. */
    public static function validateHorseSummary(array $h):void
    {
        self::scoreKey($h);
        if ($h['eligibility']!=='ELIGIBLE') self::need($h['rank_basis']==='NOT_RANKED' && $h['evidence_rank_evidence_ids']===[],'EXPECTED_INELIGIBLE_RANK');
    }

    /** A proof belongs to one horse, role and the verified adopted raw response. */
    private static function proofFor(array $proof,string $role,int $horse,mixed $expected): ?string
    {
        if (!is_string($expected) || preg_match('/^[0-9a-f]{64}$/D',$expected)!==1) return null;
        $perRole=is_array($proof[$role]??null)?($proof[$role][$horse]??null):null;
        $perHorse=is_array($proof[$horse]??null)?($proof[$horse][$role]??null):null;
        if ($perRole!==null && $perHorse!==null && $perRole!==$perHorse) return null;
        return ($perRole??$perHorse)===$expected?$expected:null;
    }

    private static function checkPacket(array $in, array $nos): array
    {
        $p=$in['expected_v01']??[];
        self::need(($in['prompt_version']??null)===self::VERSION && ($in['schema_version']??null)===self::SCHEMA
            && ($p['policy_id']??null)===BaganrikiExpectedPacket::POLICY && ($p['formula_version']??null)===BaganrikiExpectedPacket::FORMULA
            && ($p['implementation_version']??null)===BaganrikiExpectedPacket::ENGINE && ($p['race_id']??null)===$in['race_id']
            && is_string($p['current_sha256']??null) && preg_match('/^[0-9a-f]{64}$/D',$p['current_sha256'])===1
            && BaganrikiExpectedPacket::instant($p['input_freeze_at']??null)==BaganrikiExpectedPacket::instant($in['input_freeze_at']), 'EXPECTED_PACKET_IDENTITY');
        $day=(new \DateTimeImmutable($in['time_contract']['anchor_at']))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->setTime(0,0);
        self::need(BaganrikiExpectedPacket::instant($p['history_before']??null)==$day,'EXPECTED_HISTORY_CUTOFF');
        $by=[]; $ranks=[]; $poolSize=null;
        foreach ($p['rows'] as $row) {
            $no=$row['horse_no']; self::need(is_int($no)&&!isset($by[$no]),'EXPECTED_DUPLICATE_ROW');
            if ($row['formula_rank']!==null) { $rank=$row['formula_rank']; self::need(is_int($rank) && $rank>=1 && $rank<=count($nos) && !isset($ranks[$rank]),'EXPECTED_FORMULA_RANK'); $ranks[$rank]=true; }
            $status=$row['status'];
            if ($status==='UNAVAILABLE_BASELINE') self::need($row['formula_rank']!==null && $row['M']===0 && $row['K']===0 &&
                is_string($p['history_sha256']) && preg_match('/^[0-9a-f]{64}$/D',$p['history_sha256'])===1,'EXPECTED_EMPTY_BASELINE');

            self::need(in_array($status,['COMPARISON_READY','LOW_SUPPORT','BASELINE_ONLY','UNAVAILABLE_BASELINE','UNAVAILABLE_INPUT','OUT_OF_SCOPE'],true),'EXPECTED_SUPPORT_STATUS');
            $values=['E3','E5','p0_3','p0_5','p3_raw','p5_raw'];
            if (in_array($status,['UNAVAILABLE_INPUT','OUT_OF_SCOPE','UNAVAILABLE_BASELINE'],true)) {
                self::need($row['basis']==='UNAVAILABLE' && is_string($row['reason']) && BaganrikiText::visible($row['reason']),'EXPECTED_MISSING_BASIS');
                foreach ($values as $key) self::need(array_key_exists($key,$row) && $row[$key]===null,'EXPECTED_FAKE_MISSING_SCORE');
                foreach (['M','K','W','n_eff'] as $key) self::need(array_key_exists($key,$row) && ($status==='UNAVAILABLE_BASELINE'
                    ? (self::num($row[$key]) && (float)$row[$key]===0.0) : $row[$key]===null),'EXPECTED_MISSING_DIAGNOSTICS');
            } else {
                self::need($row['formula_rank']!==null && is_string($p['history_sha256']) && preg_match('/^[0-9a-f]{64}$/D',$p['history_sha256'])===1
                    && is_int($row['M']) && $row['M']>0 && is_int($row['K']) && $row['K']>=0 && $row['K']<=min(200,$row['M'])
                    && self::num($row['W']) && $row['W']>=0 && $row['W']<=$row['K']
                    && self::num($row['n_eff']) && $row['n_eff']>=0,'EXPECTED_DIAGNOSTICS');
                if ($row['K']===0) self::need((float)$row['W']===0.0 && (float)$row['n_eff']===0.0,'EXPECTED_ZERO_NEIGHBOURS');
                else {
                    $tol=32*PHP_FLOAT_EPSILON*max(1,$row['K']);
                    self::need($row['W']>0 && $row['n_eff']>=$row['W']-$tol && $row['n_eff']>=1-$tol &&
                        $row['n_eff']<=$row['K']+$tol,'EXPECTED_EFFECTIVE_SAMPLE_SIZE');
                }
                $expected=$row['K']===0?'BASELINE_ONLY':(($row['M']<100 || $row['K']<20 || $row['W']<10 || $row['n_eff']<15) ? 'LOW_SUPPORT':'COMPARISON_READY');
                self::need($status===$expected,'EXPECTED_SUPPORT_CONFLICT');
                self::need($expected==='COMPARISON_READY' ? $row['reason']===null : is_string($row['reason']) && BaganrikiText::visible($row['reason']),'EXPECTED_SUPPORT_REASON');
                foreach ($values as $key) {
                    self::need(self::num($row[$key]) && $row[$key]>=0 && $row[$key]<=($key[0]==='E'?100:1),'EXPECTED_SCORE_RANGE');
                }
                self::need($row['E3']<=$row['E5'] && $row['p3_raw']<=$row['p5_raw'] && $row['p0_3']<=$row['p0_5'],'EXPECTED_SCORE_ORDER');
                self::need(in_array($row['basis'],['RAW_UNCALIBRATED','CALIBRATION_APPLIED'],true) &&
                    ($row['basis']!=='CALIBRATION_APPLIED' || (is_string($p['calibration_id']) && BaganrikiText::visible($p['calibration_id']))),'EXPECTED_BASIS');
                if ($row['K']>0) self::need(($row['basis']==='CALIBRATION_APPLIED')===($p['calibration_id']!==null),'EXPECTED_COMMON_CALIBRATION');
                if ($row['basis']==='RAW_UNCALIBRATED') {
                    self::need((float)$row['E3']===100.0*$row['p3_raw'] && (float)$row['E5']===100.0*$row['p5_raw'],'EXPECTED_ROUNDED_OR_WRONG_RAW');
                }
                if ($status==='BASELINE_ONLY') self::need($row['basis']==='RAW_UNCALIBRATED' &&
                    (float)$row['p3_raw']===(float)$row['p0_3'] && (float)$row['p5_raw']===(float)$row['p0_5'],'EXPECTED_BASELINE_ONLY');
            }
            if ($row['M']!==null) {
                self::need($poolSize===null || $poolSize===$row['M'],'EXPECTED_POOL_SIZE');
                $poolSize=$row['M'];
            }
            $by[$no]=$row;
        }
        self::need(self::setEq(array_keys($by),$nos) && (count($ranks)===0 || count($ranks)===count($nos)),'EXPECTED_ROSTER_MISMATCH');
        return $by;
    }

    private static function roleScores(array $in,array $roster): array
    {
        $quota=self::structuralQuota($in['allocation_mode'],$roster,$base,$moves);
        self::need(array_key_exists('base_quota',$in) && self::sameMap($in['base_quota'],$base),'EXPECTED_BASE_QUOTA');
        $seen=[];
        foreach ($in['quota_adjustments'] as $row) {
            self::need($row['reason']==='BAND_CAPACITY' && is_int($row['count']) && $row['count']>0,'EXPECTED_STRUCTURAL_MOVES');
            $key=$row['requested_band'].'>'.$row['actual_band'];$seen[$key]=($seen[$key]??0)+$row['count'];
        }
        self::need(self::sameMap($seen,$moves),'EXPECTED_STRUCTURAL_MOVES');
        $roleQuota=self::roleQuota($quota,$in['ai_role']);
        self::need(self::sameMap($in['quota'],$quota) && self::sameMap($in['role_quota'],$roleQuota) &&
            $in['role_cap']===array_sum($roleQuota??[]),'EXPECTED_FROZEN_QUOTA');
        return self::checkPacket($in,array_keys($roster));
    }

    public static function validateInput(BaganrikiFrozenInput|array $input): void
    {
        $in=BaganrikiFrozenInput::checked($input);
        self::checkInspectionInput($in);
    }

    /** Preflight of role quotas and band geometry does not issue a frozen proof or a publication decision. */
    public static function checkInspectionInput(array $in):void
    {
        self::roleScores($in,self::frozenBands($in));
    }

    /** $horses and $baseRanks must be resolved/validated W1-style rows of this same role and snapshot. */
    public static function apply(BaganrikiFrozenInput|array $in, array $horses, array $baseRanks): array
    {
        return self::applyChecked(BaganrikiFrozenInput::checked($in),$horses,$baseRanks);
    }

    private static function applyChecked(array $in,array $horses,array $baseRanks): array
    {
        $roster=self::frozenBands($in); $info=[]; $eligible=array_fill_keys(self::BANDS,[]);
        $scores=self::roleScores($in,$roster);
        foreach ($horses as $h) {
            $n=$h['horse_no'];
            self::need(is_int($n)&&isset($roster[$n])&&!isset($info[$n])&&$h['band']===$roster[$n],'EXPECTED_BASE_HORSE');
            if ($h['eligibility']==='ELIGIBLE') {
                self::need(in_array($h['tier'],['A','B'],true),'EXPECTED_ELIGIBLE_TIER');
                $eligible[$h['band']][$n]=$h;
            }
            $info[$n]=$h;
        }
        self::need(self::setEq(array_keys($info),array_keys($roster)),'EXPECTED_MISSING_HORSE');
        $select=$in['selection_enabled'];
        $final=[]; $reviews=[]; $basePublic=[]; $chosen=[]; $requested=0;
        foreach (self::BANDS as $b) {
            $list=$baseRanks[$b];$q=$select?$in['role_quota'][$b]:0;
            self::need(is_int($q)&&$q>=0,'EXPECTED_QUOTA_VALUE');
            $requested+=$q;
            [$final[$b],$reviews[$b]]=self::projectBand($b,$select,$q,$list,$eligible[$b],$scores,true);
            array_push($basePublic,...array_slice($list,0,$q));
            $list=$final[$b];
            array_push($chosen,...array_slice($list,0,$q));
        }
        self::need($requested===$in['role_cap'] && count($chosen)<=$in['role_cap'],'EXPECTED_ROLE_CAP');
        return ['public_proof_hash'=>null,'prompt_version'=>self::VERSION,'ai_role'=>$in['ai_role'],'race_id'=>$in['race_id'],'snapshot_hash'=>$in['snapshot_hash'],
                'selection_ranks'=>$final,'expected_reviews'=>$reviews,'base_candidates'=>$basePublic,'public_candidates'=>$chosen];
    }

    private static function projectBand(string $b,bool $select,int $q,mixed $list,array $members,array $scores,bool $checkTotals):array
    {
        self::need(is_array($list)&&BaganrikiExpectedPacket::isList($list)&&self::setEq($list,array_keys($members)),'EXPECTED_LOST_OR_DUPLICATE_BAND');
        $ordered=self::orderFromScores($members,$checkTotals);
        foreach ($list as $n) self::need($members[$n]['rank_basis']===($ordered!==null?'SCORE':'EVIDENCE'),'EXPECTED_BAND_RANK_BASIS_'.$b);
        if ($ordered!==null) self::need($ordered===$list,'EXPECTED_BASE_SCORE_ORDER_'.$b);
        $i=null; $j=null;
        if (!$select) $code='SELECTION_DISABLED';
        elseif ($q===0 || count($list)<=$q) $code='NO_BOUNDARY';
        else {
            $i=$list[$q-1]; $j=$list[$q]; $a=$scores[$i]; $c=$scores[$j];
            if ($members[$i]['tier']!==$members[$j]['tier']) $code='TIER_MISMATCH';
            elseif ($a['status']!=='COMPARISON_READY'||$c['status']!=='COMPARISON_READY') $code='E_UNAVAILABLE';
            elseif ($a['basis']!==$c['basis']) $code='BASIS_MISMATCH';
            else {
                $swap=$b==='A'
                    ? ($c['E5']>$a['E5'] && $c['E3']>=$a['E3'] && $c['p5_raw']>$c['p0_5'])
                    : ($c['E3']>$a['E3'] && $c['E5']>=$a['E5'] && $c['p3_raw']>$c['p0_3']);
                $code=$swap?'SWAP':'KEEP';
                if ($swap) [$list[$q-1],$list[$q]]=[$j,$i];
            }
        }
        return [$list,['incumbent'=>$i,'challenger'=>$j,'decision'=>$code]];
    }

    public static function checkEnvelope(array $in,array $reply):void
    {
        foreach (['schema_version','prompt_version','race_id','snapshot_hash','ai_role','selection_enabled',
                  'mode','mode_status','allocation_mode','mode_policy_id','target_count','role_cap','quota','role_quota','learning_stage'] as $k) {
            self::need(array_key_exists($k,$reply) && array_key_exists($k,$in) &&
                self::sameMap($reply[$k],$in[$k]), 'EXPECTED_REPLY_ID_'.$k);
        }
    }

    /** Compare the preliminary response with the same deterministic projection as the final raw-response verifier.
     * Only called after the controller has validated the horse rows and base ranks. Does not authorize publication.
     */
    public static function diagnoseSelection(array $in,array $reply,callable $add):void
    {
        if (($in['prompt_version']??null)===BaganrikiScoreMaterials::R14 && ($in['schema_version']??null)==='baganriki-v041-r12-2-w2-e35-r14') {
            // R14 and R15 share the band algorithm. This local copy is only the preliminary diagnostic.
            $in['prompt_version']=self::VERSION;$in['schema_version']=self::SCHEMA;
        }
        try { $projected=self::applyChecked($in,$reply['horses'],$reply['band_ranks']); }
        catch (\InvalidArgumentException $error) {
            $code=$error->getMessage();
            if (preg_match('/^EXPECTED_(?:BASE_SCORE_ORDER|BAND_RANK_BASIS)_([ABD])$/D',$code,$match))
                $add('rank_bands',$match[1],'EXPECTED_BASE_ORDER',$code);
            else $add('public_candidates','public_candidates','EXPECTED_PROJECTION_INPUT',$code);
            return;
        }
        foreach (self::BANDS as $band) {
            if (($reply['selection_ranks'][$band]??null)!==$projected['selection_ranks'][$band])
                $add('public_candidates','public_candidates','EXPECTED_SELECTION_RANKS',$band.'帯のE適用後順位を基礎順位から再計算してください');
            if (!self::sameMap($reply['expected_reviews'][$band]??null,$projected['expected_reviews'][$band]))
                $add('public_candidates','public_candidates','EXPECTED_EXPECTED_REVIEW',$band.'帯のE比較記録が計算結果と違います');
        }
        $expected=$projected['public_candidates'];$reported=$reply['public_candidates'];
        if ($reported!==$expected) {
            $add('public_candidates','public_candidates','EXPECTED_PUBLIC_ORDER','E適用後の公開馬番・並びを再計算してください');
            foreach ($reply['horses'] as $h) if (in_array($h['horse_no'],$expected,true)!==$h['public_candidate'])
                $add('presentation_horses',$h['horse_no'],'EXPECTED_PROJECTED_FLAG','再計算した公開候補に表示を合わせてください');
        }
    }

    /** Recheck each trustworthy band even when the other AI fields need their own corrections. */
    public static function inspectSelection(array $in,array $res,array $findings,callable $add):void
    {
        $roster=self::frozenBands($in);$scores=self::roleScores($in,$roster);$held=[];$by=[];$members=array_fill_keys(self::BANDS,[]);
        foreach ($findings as $finding) {
            $target=$finding['target'];$scope=$finding['scope'];
            if ($scope==='rank_bands' && in_array($target,self::BANDS,true)) $held[$target]=true;
            if (is_int($target) && isset($roster[$target]) &&
                ($scope==='horses' || ($scope==='summary_horses' && !in_array($finding['code'],[
                    'OBSERVED_SUM','OBSERVED_CAP','TOTAL_SCORE','EXPECTED_SCORE_TOTAL','EXPECTED_EXTRA_ROW_FIELD'
                ],true)))) $held[$roster[$target]]=true;
        }
        foreach ((array)($res['horses']??[]) as $h) {
            if (!is_array($h) || !is_int($h['horse_no']??null) || !isset($roster[$h['horse_no']])) continue;
            $n=$h['horse_no'];$b=$roster[$n];
            if (isset($by[$n]) || ($h['band']??null)!==$b || !in_array($h['eligibility']??null,['ELIGIBLE','NO_EVIDENCE','UNVERIFIED'],true)) $held[$b]=true;
            $by[$n]=$h;
            if (($h['eligibility']??null)==='ELIGIBLE') $members[$b][$n]=$h;
        }
        $allRows=count($by)===count($roster);
        foreach ($roster as $n=>$b) if (!isset($by[$n])) $held[$b]=true;
        $knownCounts=$allRows;
        foreach ($by as $n=>$h) if (($h['band']??null)!==$roster[$n] || !in_array($h['eligibility']??null,['ELIGIBLE','NO_EVIDENCE','UNVERIFIED'],true)) $knownCounts=false;
        if ($knownCounts && !self::validShortfalls($in,array_map('count',$members),$res['shortfalls']??null))
            $add('top_fields','shortfalls','EXPECTED_SHORTFALLS','不足した帯、役割別枠、適格頭数、差、理由を照合してください');
        $pc=is_array($res['public_candidates']??null)?$res['public_candidates']:[];$final=[];$checked=0;
        foreach (self::BANDS as $b) {
            if (isset($held[$b])) continue;
            $q=$in['role_quota'][$b]??0;
            try {
                [$order,$review]=self::projectBand($b,$in['selection_enabled'],$q,$res['band_ranks'][$b]??null,$members[$b],$scores,false);
            } catch (\InvalidArgumentException $problem) {
                $add('rank_bands',$b,'EXPECTED_BASE_ORDER',$problem->getMessage());continue;
            }
            ++$checked;
            if (($res['selection_ranks'][$b]??null)!==$order) $add('public_candidates','public_candidates','EXPECTED_SELECTION_RANKS',$b.'帯のE適用後順位が違います');
            if (!self::sameMap($res['expected_reviews'][$b]??null,$review)) $add('public_candidates','public_candidates','EXPECTED_EXPECTED_REVIEW',$b.'帯の境界判断が違います');
            $picks=array_slice($order,0,$q);array_push($final,...$picks);
            $actual=array_values(array_filter($pc,static fn($n)=>is_int($n) && ($roster[$n]??null)===$b));
            if ($actual!==$picks) $add('public_candidates','public_candidates','EXPECTED_PUBLIC_ORDER',$b.'帯の公開馬番・順序を照合してください');
            foreach ($roster as $n=>$band) if ($band===$b && ($by[$n]['public_candidate']??null)!==in_array($n,$picks,true))
                $add('presentation_horses',$n,'EXPECTED_PROJECTED_FLAG','この馬の表示を検証した候補と合わせてください');
        }
        if ($checked===count(self::BANDS) && $pc!==$final)
            $add('public_candidates','public_candidates','EXPECTED_PUBLIC_ORDER','A・B・D順の最終馬番と異なります');
    }

    /** Used by both preliminary diagnosis and final raw-response validation. */
    private static function validShortfalls(array $in,array $counts,mixed $list):bool
    {
        if (!is_array($list) || !BaganrikiExpectedPacket::isList($list)) return false;
        $missing=[];
        foreach (self::BANDS as $b) {
            $q=$in['role_quota'][$b]??0;
            if ($q>$counts[$b]) $missing[$b]=['requested'=>$q,'eligible'=>$counts[$b],'missing'=>$q-$counts[$b]];
        }
        foreach ($list as $short) {
            if (!is_array($short) || !is_string($b=$short['band']??null) || !isset($missing[$b]) ||
                !is_string($short['reason']??null) || preg_match('/[^\s\x{200B}\x{FEFF}]/u',$short['reason'])!==1) return false;
            foreach ($missing[$b] as $key=>$value) if (($short[$key]??null)!==$value) return false;
            unset($missing[$b]);
        }
        return !$missing;
    }

    public static function nonselectCode(array $in,string $eligibility):string
    {
        return !$in['selection_enabled']?'SELECTION_DISABLED':($eligibility==='ELIGIBLE'?'BAND_QUOTA':$eligibility);
    }

    /** Call after ordinary 新方式 core validation; the return value is reusable by the merger. */
    public static function verifyReply(BaganrikiFrozenInput|array $input, array $reply): array
    {
        $in=BaganrikiFrozenInput::checked($input);
        if ($input instanceof BaganrikiFrozenInput) {
            $cache=[];
            self::need(is_array($reply['horses']??null),'EXPECTED_SCORE_ROWS');
            foreach ($reply['horses'] as $horse) {
                self::need(is_array($horse) && is_int($horse['horse_no']??null) && is_array($horse['scores']??null),'EXPECTED_SCORE_ROW');
                self::need(is_array($horse['evidence']??null) && BaganrikiExpectedPacket::isList($horse['evidence']),'EXPECTED_EVIDENCE_ITEMS');
                foreach ($horse['evidence'] as $e) {
                    self::need(is_array($e) && BaganrikiSourceEvidence::familyKnown($in,$horse['horse_no'],
                        $e['family']??null,$e['source_refs']??null,$cache),'EXPECTED_EVIDENCE_FAMILY');
                    BaganrikiMarketClaim::verify($in['source_registry'],[$input,'sourceValue'],$e,$horse['horse_no']);
                    BaganrikiGapClaim::verify($in['source_registry'],[$input,'sourceValue'],$e,$horse['horse_no']);   // @ANCHOR-20261110-PREDICTION-GAP-CLAIM
                }
                self::need(is_array($horse['family_review']??null),'EXPECTED_FAMILY_REVIEWS');
                self::need(count($horse['family_review'])===5 && !array_diff(['ability','gap','market','similar_value','win_place'],array_keys($horse['family_review'])),'EXPECTED_FAMILY_REVIEWS');
                $available=BaganrikiSourceEvidence::availableRefs($in,$horse['horse_no']);
                foreach ($horse['family_review'] as $key=>$review) {
                    self::need(is_array($review) && in_array($review['status']??null,['SUPPORTED','NO_SUPPORT','DATA_MISSING'],true),'EXPECTED_FAMILY_REVIEWS');
                    if ($review['status']==='NO_SUPPORT')
                        self::need(BaganrikiSourceEvidence::familyKnown($in,$horse['horse_no'],$key,$review['source_refs']??null,$cache,false),'EXPECTED_NO_SUPPORT_MATERIAL_'.$key);
                    if ($review['status']==='DATA_MISSING')
                        self::need(!BaganrikiSourceEvidence::familyKnown($in,$horse['horse_no'],$key,$available,$cache,false),'EXPECTED_FALSE_MISSING_'.$key);
                }
                foreach ($horse['scores'] as $key=>$points) if ($points!==null)
                    self::need(is_string($key) && BaganrikiSourceEvidence::scoreKnown($in,$horse['horse_no'],$key,$points,
                        $horse['score_evidence'][$key]??null,$cache),'EXPECTED_SCORE_MATERIAL_'.$key);
            }
        }
        return self::selectionReply($in,$reply);
    }

    private static function selectionReply(array $in,array $reply):array
    {
        self::checkEnvelope($in,$reply);
        $result=self::applyChecked($in,$reply['horses'],$reply['band_ranks']);
        $evidenceMode=[];
        foreach ($reply['horses'] as $h) {
            if ($h['eligibility']==='ELIGIBLE' && $h['score_status']!=='complete') $evidenceMode[$h['band']]=true;
        }
        $counts=[];
        foreach (self::BANDS as $b) {
            $base=$reply['band_ranks'][$b]; $backup=$reply['evidence_band_ranks'][$b]??null;
            self::need(is_array($backup) && BaganrikiExpectedPacket::isList($backup) && self::setEq($backup,$base) &&
                (!isset($evidenceMode[$b]) || $backup===$base),'EXPECTED_REPLY_EVIDENCE_RANK_'.$b);
            $counts[$b]=count($base);
        }
        self::need(self::validShortfalls($in,$counts,$reply['shortfalls']??null),'EXPECTED_REPLY_SHORTFALLS');
        foreach (['selection_ranks','expected_reviews'] as $k) {
            foreach (self::BANDS as $b) {
                $actual=$reply[$k][$b]??null; $expected=$result[$k][$b];
                if ($k==='expected_reviews' && is_array($actual)) { ksort($actual); ksort($expected); }
                self::need($actual===$expected,'EXPECTED_REPLY_'.$k.'_'.$b);
            }
        }
        self::need($result['public_candidates']===$reply['public_candidates'],'EXPECTED_REPLY_PUBLIC');
        foreach ($reply['horses'] as $h) {
            $flag=in_array($h['horse_no'],$result['public_candidates'],true);
            self::need($h['public_candidate']===$flag && (($h['nonselect_reason']===null)===$flag),'EXPECTED_REPLY_FLAG');
            if (!$flag) {
                $reason=$h['nonselect_reason'];
                $code=self::nonselectCode($in,$h['eligibility']);
                self::need(($reason['code']??null)===$code && is_string($reason['reason']??null) && BaganrikiText::visible($reason['reason']),'EXPECTED_REPLY_NONSELECTION');
            }
        }
        return $result;
    }

    /** Parses bytes, not a reconstructed reply object, using the submitted strict JSON reader. */
    public static function parseOriginal(string $raw,string $savedResponseHash):array
    {
        self::need(strlen($raw)<=4194400 && hash_equals(hash('sha256',$raw),$savedResponseHash),'EXPECTED_RESPONSE_PROOF_HASH');
        $parsed=BaganrikiResponseFrame::extract($raw);
        self::need($parsed['ok']===true,$parsed['w2_error']??'EXPECTED_RESPONSE_JSON');
        return $parsed;
    }

    /** Use after 新方式 has verified the whole adopted response against its effective evidence rows. */
    public static function verifyOriginal(BaganrikiFrozenInput $snapshot,string $raw,string $savedResponseHash):array
    {
        $parsed=self::parseOriginal($raw,$savedResponseHash);
        // Auxiliary display errors cannot erase independently valid horse selections.
        $core=$parsed['json'];unset($core['race_metrics']);
        BaganrikiOutputSchema::validate('output',$core,$parsed['map']);
        $in=BaganrikiFrozenInput::checked($snapshot);
        $metricIssues=BaganrikiRaceMetrics::issues($in,$parsed['json'],$parsed['map']);
        $result=self::selectionReply($in,$parsed['json']);
        $result['independent_groups']=BaganrikiSourceEvidence::reply($snapshot,$parsed['json'],$parsed['map']);
        $result['public_proof_hash']=$metricIssues?null:$savedResponseHash;
        if (BaganrikiRaceMetrics::enabled(BaganrikiFrozenInput::checked($snapshot)) && $result['ai_role']==='1st')
            $result['race_metrics']=BaganrikiRaceMetrics::fromResponse(BaganrikiFrozenInput::checked($snapshot),$parsed['json'],$savedResponseHash,$parsed['map']);
        return $result;
    }

    /**
     * Publication entry point for accepted DB attempts. Rebuild the result for every live revision.
     * ORIGINAL uses stored exact bytes. RECOVERED accepts only the effective rows/ranks already
     * validated by 新方式; it receives no original-reply bonus. Never consume a cached list of picks.
     */
    public static function mergeStored(BaganrikiFrozenInput $snapshot,array $attempts,?array $liveQuota,array $activeNos,array $proof,array $context):array
    {
        $seed=BaganrikiFrozenInput::checked($snapshot);$w=BaganrikiAttemptWindow::current($snapshot,$context);
        self::need($w['cancelled']===($activeNos===[]),'EXPECTED_CANCELLATION_STATE');
        $guard=BaganrikiAttemptWindow::publicationGuard($snapshot,$context,$w);
        $roster=array_column($seed['runners'],'horse_no');
        self::need(BaganrikiAttemptWindow::horseSet($activeNos,$roster)===$guard['active_horse_nos'],'EXPECTED_LIVE_ROSTER_MISMATCH');
        $issues=[];$attemptIssues=[];$rankFallback=[];$rankSources=[];$rebuilt=[];$replayed=[];$ignored=0;$roles=[];$groups=[];
        if (!$activeNos || !$seed['selection_enabled']) {
            $out=self::merge($snapshot,[],$liveQuota,$activeNos,[],[]);
            $out['role_issues']=[];$out['attempt_issues']=[];$out['rank_fallback_bands']=[];$out['retained_rank_sources']=[];$out['rebuilt_base_bands']=[];$out['replayed_initial_roles']=[];$out['ignored_role_count']=0;$out['selection_state']=$activeNos?'SELECTION_DISABLED':'CANCELLED';$out['publication_guard']=$guard;
            return self::sealSelection($out);
        }
        self::need($w['now']>=$w['ready'] && $w['now']<=$w['publish'],'EXPECTED_PUBLISH_WINDOW');
        $actualJournal=BaganrikiRoleJournal::witness($snapshot,$attempts,$proof);
        foreach ($actualJournal as $role=>$digest) self::need($guard['role_journal'][$role]===$digest,'EXPECTED_JOURNAL_MISMATCH_'.$role);
        foreach (['1st','2nd'] as $role) if (!isset($attempts[$role])) $issues[$role]='EXPECTED_ROLE_MISSING';
        foreach ($attempts as $role=>$attempt) {
            if (!in_array($role,['1st','2nd'],true)) { ++$ignored;continue; }
            try {
                self::need(is_array($attempt) && ($attempt['input']??null) instanceof BaganrikiFrozenInput && is_array($attempt['receipts']??null),'EXPECTED_ATTEMPT_RECORD');
                $in=BaganrikiFrozenInput::checked($attempt['input']);
                self::need($in['ai_role']===$role && $in['snapshot_hash']===$seed['snapshot_hash'] && $in['race_id']===$seed['race_id'],'EXPECTED_PUBLICATION_ID');
                [$valid,$errors]=BaganrikiAttemptWindow::receipts($attempt['input'],$attempt['receipts'],$w);
                if ($errors) $attemptIssues[$role]=$errors;
                self::need((bool)$valid,'EXPECTED_NO_ADMISSIBLE_RECEIPT');
                if (($attempt['kind']??null)==='ORIGINAL') {
                    self::need(count($attempt)===3,'EXPECTED_ORIGINAL_RECORD');
                    $result=null;
                    foreach (array_reverse($valid,true) as $no=>$receipt) {
                        if (!$receipt['approved']) continue;
                        try {
                            $candidate=self::verifyOriginal($attempt['input'],$receipt['raw'],$receipt['hash']);
                            if ($no===1) BaganrikiRecovery::checkOriginalCorrection($attempt['input'],$valid[0],$receipt);
                            $result=$candidate;break;
                        }
                        catch (\InvalidArgumentException $ex) { $attemptIssues[$role][$no]=$ex->getMessage(); }
                    }
                    self::need($result!==null,'EXPECTED_NO_APPROVED_ORIGINAL');
                } else {
                    self::need(($attempt['kind']??null)==='RECOVERED','EXPECTED_RECOVERY_RECORD');
                    try {
                        self::need(count($attempt)===5 && is_string($attempt['recovery_json']??null) && is_string($attempt['recovery_hash']??null),'EXPECTED_RECOVERY_RECORD');
                        $result=BaganrikiRecovery::verify($attempt['input'],$attempt['recovery_json'],$attempt['recovery_hash'],$valid);
                    }
                    catch (\InvalidArgumentException $recoveryError) {
                        $initial=$valid[0]??null;
                        if (!$initial || !$initial['approved']) throw $recoveryError;
                        try { $result=self::verifyOriginal($attempt['input'],$initial['raw'],$initial['hash']); }
                        catch (\InvalidArgumentException $invalidInitial) { throw $recoveryError; }
                        // A separately usable first result does not certify the failed recovery.
                        $result['public_proof_hash']=null;$issues[$role]=$recoveryError->getMessage();$replayed[]=$role;
                    }
                }
                $roles[]=$result;
                if (!empty($result['retained_rank_sources'])) $rankSources[$role]=$result['retained_rank_sources'];
                if (!empty($result['rebuilt_base_bands'])) $rebuilt[$role]=$result['rebuilt_base_bands'];
                if (!empty($result['rank_fallback_bands'])) $rankFallback[$role]=$result['rank_fallback_bands'];
                foreach ($result['independent_groups'] as $n=>$count) $groups[$n]=max($groups[$n]??0,$count);
            } catch (\InvalidArgumentException $ex) { $issues[$role]=$ex->getMessage(); }
        }
        $out=self::merge($snapshot,$roles,$liveQuota,$activeNos,$proof,$groups);
        if (BaganrikiRaceMetrics::enabled($seed)) {
            $out['race_metrics']=BaganrikiRaceMetrics::unknown($seed);
            foreach ($roles as $r) if ($r['ai_role']==='1st' && isset($r['race_metrics'])) $out['race_metrics']=$r['race_metrics'];
        }
        ksort($issues,SORT_STRING);ksort($attemptIssues,SORT_STRING);foreach ($attemptIssues as &$errors) ksort($errors,SORT_STRING);unset($errors);
        ksort($rebuilt,SORT_STRING);$out['rebuilt_base_bands']=$rebuilt;
        ksort($rankFallback,SORT_STRING);$out['rank_fallback_bands']=$rankFallback;
        ksort($rankSources,SORT_STRING);$out['retained_rank_sources']=$rankSources;
        sort($replayed,SORT_STRING);$out['replayed_initial_roles']=$replayed;$out['ignored_role_count']=$ignored;
        $out['role_issues']=$issues;$out['attempt_issues']=$attemptIssues;
        $out['publication_guard']=$guard;
        $out['selection_state']=$out['public_candidates']?'CANDIDATES_READY':'VERIFIED_NO_CANDIDATES';
        return self::sealSelection($out);
    }

    private static function sealSelection(array $selection):array
    {
        $selection['publication_guard']['selection_sha256']=hash('sha256',BaganrikiFrozenInput::nativeJson($selection));
        return $selection;
    }

    /**
     * Project the application's independently inspected rows with the same boundary and merge rules
     * used when replaying stored E3・E5版の receipts. No candidate list supplied by a caller is authoritative.
     */
    /** @ANCHOR-20261116-EXPECTED-COMPARE  試験用（なる02 の3）：保存済みの凍結入力の E3・E5 の部分だけを差し替えて、本番と同じ流れで最終候補まで作る。本番は null */
    public static ?\Closure $testInputOverride=null;
    /** 試験用：直近の submittedPublication で作った役割ごとの結果（帯ごとの KEEP/SWAP を数えるため） */
    public static array $lastProjected=[];

    public static function submittedPublication(object $snapshot, array $active, callable $readRole):array
    {
        $seed=null; $roles=[]; $proof=[]; $groups=[]; $issues=[]; $sources=[]; $fallback=[]; $notes=[]; $metrics=null;
        self::$lastRoleIssues=[]; $looked=[]; $issueAt=[]; $roleHorses=[];   // @ANCHOR-20261116-METRICS-SELECTION
        foreach (['1st','2nd'] as $role) {
            try {
                $input=BaganrikiFrozenInput::forInspection($snapshot,$role);
                if (self::$testInputOverride!==null) $input=(self::$testInputOverride)($input,$role);   // @ANCHOR-20261116-EXPECTED-COMPARE  試験だけ
                self::need(is_array($input) && $input['prompt_version']===self::VERSION,'EXPECTED_STORED_ID');
                $seed=$input;
                if (!$input['selection_enabled']) continue;
                [$accepted,$rank]=$readRole($role);
                $looked[$role]=['ok'=>$accepted['ok']??null,'code'=>$accepted['code']??null,'complete'=>$accepted['complete']??null,
                    'merged'=>is_array($accepted['merged']??null)?count($accepted['merged']):null,
                    'bands'=>is_array($rank)?array_map(static fn($v)=>is_array($v)?count($v):null,(array)($rank['band_ranks']??[])):null];
                self::need(($accepted['ok']??false)===true && ($accepted['complete']??false)===true
                    && is_array($rank) && isset($rank['band_ranks']['A'],$rank['band_ranks']['B'],$rank['band_ranks']['D']),
                    'EXPECTED_ROLE_NOT_VERIFIED');
                $by=self::frozenBands($input);$horses=$accepted['merged'];$counts=[];$cache=[];$eligible=array_fill_keys(self::BANDS,[]);
                self::need(is_array($horses) && $horses!==[] && !array_diff_key($horses,$by),'EXPECTED_BASE_HORSE');
                foreach ($by as $n=>$band) {
                    // A row isolated by the existing validator has no eligible rank or evidence.
                    if (!isset($horses[$n])) {
                        $horses[$n]=['horse_no'=>$n,'band'=>$band,'eligibility'=>'UNVERIFIED'];
                        continue;
                    }
                    $h=$horses[$n];
                    self::need($h['horse_no']===$n && $h['band']===$band,'EXPECTED_BASE_HORSE');
                    if ($h['eligibility']==='ELIGIBLE') {
                        $eligible[$band][$n]=$h;
                        $counts[$n]=BaganrikiSourceEvidence::acceptedGroupCount($input['source_registry'],$h['evidence'],$cache);
                        self::need($counts[$n]>=1 && $counts[$n]<=5,'EXPECTED_MERGE_GROUPS');
                    }
                }
                $base=$rank['band_ranks'];
                // Rebuild a complete score order or a permitted fallback exactly as the recovery verifier does.
                foreach (self::BANDS as $band) {
                    $scoreOrder=self::scoreOrder($eligible[$band]);
                    if ($scoreOrder!==null) { $base[$band]=$scoreOrder; }
                    elseif (in_array($band,$rank['fallback']??[],true)) {
                        usort($base[$band],static fn($a,$b)=>($counts[$b]<=>$counts[$a]) ?: ($a<=>$b));
                    }
                }
                $projected=self::applyChecked($input,$horses,$base);
                $hash=$rank['public_proof_hash']??null;
                if (is_string($hash) && $base===$rank['band_ranks'] && $projected['public_candidates']===($rank['public_candidates']??null)) {
                    $projected['public_proof_hash']=$hash; $proof[$role]=$rank['proof']??[];
                }
                $roles[]=$projected;
                $roleHorses[$role]=$horses;   // @ANCHOR-20261116-METRICS-SELECTION  3指標の補正で、次点の馬が ELIGIBLE かを確かめるため
                if ($role==='1st' && BaganrikiRaceMetrics::enabled($input)) $metrics=$rank['race_metrics']??BaganrikiRaceMetrics::unknown($input);
                foreach ($counts as $n=>$c) $groups[$n]=max($groups[$n]??0,$c);
                $sources[$role]=$rank['rank_source']??[];
                if (!empty($rank['fallback'])) $fallback[$role]=$rank['fallback'];
                foreach ($rank['rank_notes']??[] as $note) $notes[]=$role.'：'.$note;
            } catch (\InvalidArgumentException $error) { $issues[$role]=$error->getMessage(); $issueAt[$role]=$error->getMessage().'@'.$error->getLine().'<'.implode('<',array_map(static fn($t)=>($t['function']??'').':'.($t['line']??''),array_slice($error->getTrace(),0,4))); }
        }
        self::$lastRoleIssues=['issues'=>$issueAt,'looked'=>$looked];
        self::$lastProjected=$roles;   // @ANCHOR-20261116-EXPECTED-COMPARE
        self::need($seed!==null,'EXPECTED_STORED_ID');
        self::need($seed['selection_enabled'],'EXPECTED_SELECTION_DISABLED');
        $roster=self::frozenBands($seed);
        $quota=self::structuralQuota($seed['allocation_mode'],array_intersect_key($roster,array_flip($active)));
        $detail=[]; $selection=self::mergeChecked($seed,$roles,$quota,$active,$proof,$groups,$detail);
        // @ANCHOR-20261116-METRICS-SELECTION  3指標による最終配分の補正（凍結入力の本文版が _06 のときだけ。凍結時に決まる）
        $metricsSelection=null;
        if (BaganrikiMetricsSelection::enabled($seed)) {
            // @ANCHOR-20261116-METRICS-SPEC-FREEZE  凍結時に記録した仕様と一致するときだけ補正する（なる11/16 仕上げ②）
            $specCheck=BaganrikiMetricsSelection::frozenSpecCheck($snapshot,$seed);
            if (!$specCheck['ok']) {
                $metricsSelection=['policy'=>BaganrikiMetricsSelection::POLICY,'status'=>'KEEP','reason'=>$specCheck['reason'],
                    'before'=>$selection['public_candidates'],'after'=>$selection['public_candidates'],'frozen_spec'=>$specCheck['frozen_spec']];
            } elseif (BaganrikiMetricsSelection::SWAP_PAUSED && BaganrikiMetricsSelection::$force!==true) {
                // @ANCHOR-20261124-METRICS-SWAP-PAUSED  最初の2週末は入れ替えない（なる 11/24 問4 B）。基本の選出のまま公開し、理由を残す
                $metricsSelection=['policy'=>BaganrikiMetricsSelection::POLICY,'status'=>'KEEP','reason'=>BaganrikiMetricsSelection::SWAP_PAUSED_REASON,
                    'before'=>$selection['public_candidates'],'after'=>$selection['public_candidates'],'frozen_spec'=>$specCheck['frozen_spec']];
            } else {
                $metricsSelection=BaganrikiMetricsSelection::apply($seed,$roles,$roleHorses,$selection,$quota,$metrics);
                $metricsSelection['frozen_spec']=$specCheck['frozen_spec'];
            }
            if ($metricsSelection['status']==='APPLIED') {
                $selection['public_candidates']=$metricsSelection['after'];
                $selection['actual_quota']=$metricsSelection['effective_quota'];
            }
        }
        $rows=[];
        foreach ($selection['public_candidates'] as $n) $rows[]=$detail[$n];
        return (BaganrikiRaceMetrics::enabled($seed)?['race_metrics'=>$metrics??BaganrikiRaceMetrics::unknown($seed)]:[]) + ['candidates'=>$rows,'quota'=>$quota,'prediction_quota'=>$seed['quota'],
            'shortfalls'=>$selection['shortfalls'],'fill'=>$selection['fill'],'actual_quota'=>$selection['actual_quota'],
            'proof'=>array_keys($proof),'rank_source'=>$sources,'rank_fallback'=>$fallback,'rank_notes'=>$notes,'role_issues'=>$issues]
            + ($metricsSelection!==null ? ['metrics_selection'=>$metricsSelection] : []);   // @ANCHOR-20261116-METRICS-SELECTION
    }

    /**
     * $roles: outputs of apply/verifyReply from the same immutable snapshot.
     * $proof[role][horse_no] or $proof[horse_no][role]: server-side SHA-256 of the matching adopted raw decision.
     * $groups[horse_no]: independent source-group counts verified by the existing merger (exclude E3/E5).
     * $quota and $activeNos come from the existing, versioned cancellation/structure calculation.
     */
    public static function merge(BaganrikiFrozenInput|array $input, array $roles, ?array $quota, array $activeNos, array $proof, array $groups): array
    {
        return self::mergeChecked(BaganrikiFrozenInput::checked($input),$roles,$quota,$activeNos,$proof,$groups);
    }

    private static function mergeChecked(array $in,array $roles,?array $quota,array $activeNos,array $proof,array $groups,?array &$details=null):array
    {
        $by=self::frozenBands($in);
        $sizes=array_count_values($by)+array_fill_keys(self::BANDS,0);
        $active=[];
        foreach (BaganrikiAttemptWindow::horseSet($activeNos,array_keys($by)) as $n) $active[$n]=$by[$n];
        $frozenQuota=self::structuralQuota($in['allocation_mode'],$by);
        $liveQuota=self::structuralQuota($in['allocation_mode'],$active);
        self::need(self::sameMap($in['quota'],$frozenQuota),'EXPECTED_FROZEN_QUOTA');
        self::need(self::sameMap($quota,$liveQuota),'EXPECTED_EFFECTIVE_QUOTA');
        if ($liveQuota===null) return ['public_candidates'=>[],'agreement_bonus'=>[],'public_proof_hashes'=>[],'fill'=>[],'shortfalls'=>[],'actual_quota'=>array_fill_keys(self::BANDS,0)];
        self::need($roles!==[],'EXPECTED_NO_VERIFIED_ROLE');
        $orders=array_fill_keys(self::BANDS,[]); $seenRoles=[]; $eligibleBonus=[]; $nominations=[];
        foreach ($roles as $r) {
            $role=$r['ai_role']; self::need(in_array($role,['1st','2nd'],true) && !isset($seenRoles[$role]) && ($r['prompt_version']??null)===self::VERSION && $r['snapshot_hash']===$in['snapshot_hash'] && $r['race_id']===$in['race_id'],'EXPECTED_MERGE_SOURCE');
            $seenRoles[$role]=true;
            $roleQuota=self::roleQuota($frozenQuota,$role);
            $ranked=[]; $prefix=[]; $before=[];
            foreach (self::BANDS as $b) {
                $rankList=$r['selection_ranks'][$b]??null;
                self::need(is_array($rankList) && BaganrikiExpectedPacket::isList($rankList),'EXPECTED_MERGE_RANKS');
                $q=$roleQuota[$b];
                $chosen=array_slice($rankList,0,$q);
                array_push($prefix,...$chosen);
                $review=$r['expected_reviews'][$b]??null;
                $hasBoundary=$q>0 && count($rankList)>$q;
                self::need(is_array($review) && array_key_exists('incumbent',$review) &&
                    array_key_exists('challenger',$review),'EXPECTED_MERGE_REVIEW');
                $decision=$review['decision']??null;
                if (!$hasBoundary) {
                    self::need($decision==='NO_BOUNDARY' && $review['incumbent']===null &&
                        $review['challenger']===null,'EXPECTED_MERGE_REVIEW');
                } else {
                    self::need(in_array($decision,['KEEP','SWAP','TIER_MISMATCH','E_UNAVAILABLE','BASIS_MISMATCH'],true),'EXPECTED_MERGE_REVIEW');
                    $swap=$decision==='SWAP';
                    self::need($review['incumbent']===$rankList[$swap?$q:$q-1] &&
                        $review['challenger']===$rankList[$swap?$q-1:$q],'EXPECTED_MERGE_REVIEW');
                    if ($swap) $chosen[$q-1]=$review['incumbent'];
                }
                array_push($before,...$chosen);
                foreach ($rankList as $i=>$n) {
                    self::need(is_int($n) && isset($by[$n]) && $by[$n]===$b && !isset($ranked[$n]),'EXPECTED_MERGE_BAND');
                    $ranked[$n]=true;
                    if (!isset($active[$n])) continue;
                    self::need(is_int($groups[$n]??null) && $groups[$n]>=1 && $groups[$n]<=5,'EXPECTED_MERGE_GROUPS');
                    $rank=$i+1;
                    $orders[$b][$n]=min($orders[$b][$n]??$rank,$rank);
                }
            }
            self::need($prefix===$r['public_candidates'] && $before===$r['base_candidates'],'EXPECTED_MERGE_CANDIDATES');
            if ($details!==null) foreach ($prefix as $n) $nominations[$n]=1+($nominations[$n]??0);
            foreach (array_intersect($before,$prefix,array_keys($active)) as $n) {
                $hash=self::proofFor($proof,$role,$n,$r['public_proof_hash']??null);
                if ($hash!==null) $eligibleBonus[$n][$role]=$hash;
            }
        }
        $bonus=[]; $queues=[]; $proofs=[];
        foreach(self::BANDS as $b) {
            $points=[];
            foreach($orders[$b] as $n=>$rank) {
                $proven=isset($eligibleBonus[$n]['1st'],$eligibleBonus[$n]['2nd']) && $eligibleBonus[$n]['1st']!==$eligibleBonus[$n]['2nd'];
                $bonus[$n]=$proven?5:0;
                if ($proven) $proofs[$n]=['1st'=>$eligibleBonus[$n]['1st'],'2nd'=>$eligibleBonus[$n]['2nd']];
                $points[$n]=100*($sizes[$b]-$rank+1)+$bonus[$n]*$sizes[$b];
                if ($details!==null) $details[$n]=[
                    'horse_no'=>$n,'band'=>$b,'score'=>$points[$n]/$sizes[$b],'bonus'=>$bonus[$n],
                    'both_public'=>($nominations[$n]??0)===2,'bonus_proven'=>$proven,
                    'independent_groups'=>$groups[$n],'public_proof_hash'=>$proofs[$n]??null,
                ];
            }
            $qs=array_keys($points);
            usort($qs,static fn($a,$c)=>($points[$c]<=>$points[$a]) ?: ($bonus[$c]<=>$bonus[$a]) ?: ($groups[$c]<=>$groups[$a]) ?: ($a<=>$c));
            $queues[$b]=$qs;
        }
        $picks=[];$missing=[];$fill=[];
        $bandOrder=$queues;   // @ANCHOR-20261116-METRICS-SELECTION  帯ごとの統合順位（選ぶ前）。3指標の補正で「その帯の未選出の先頭」を決めるために返す
        foreach (self::BANDS as $b) {
            self::need(is_int($quota[$b]??null) && $quota[$b]>=0,'EXPECTED_EFFECTIVE_QUOTA');
            $picks[$b]=array_splice($queues[$b],0,$quota[$b]);
            $missing[$b]=$quota[$b]-count($picks[$b]);
        }
        foreach (['D'=>['B'],'B'=>['D'],'A'=>['B','D']] as $dest=>$donors) {
            foreach ($donors as $from) {
                $extra=array_splice($queues[$from],0,$missing[$dest]);$missing[$dest]-=count($extra);
                foreach ($extra as $n) {
                    // It fills the destination's vacancy but keeps its actual band and rank for publication.
                    $picks[$from][]=$n;$fill[]=['horse_no'=>$n,'requested'=>$dest,'actual'=>$from];
                }
            }
        }
        $shortfalls=[];
        foreach (self::BANDS as $b) if ($missing[$b]>0)
            $shortfalls[]=['band'=>$b,'requested'=>$quota[$b],'filled'=>$quota[$b]-$missing[$b],'missing'=>$missing[$b]];
        ksort($bonus,SORT_NUMERIC); ksort($proofs,SORT_NUMERIC);
        return ['public_candidates'=>array_merge($picks['A'],$picks['B'],$picks['D']),'agreement_bonus'=>$bonus,'public_proof_hashes'=>$proofs,'fill'=>$fill,'shortfalls'=>$shortfalls,'actual_quota'=>array_map('count',$picks),
            'band_order'=>$bandOrder];   // @ANCHOR-20261116-METRICS-SELECTION
    }
}

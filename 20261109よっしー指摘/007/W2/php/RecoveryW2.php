<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiResponse;
use InvalidArgumentException;

final class RecoveryW2
{
    private static function need(bool $ok,string $code):void { if (!$ok) throw new InvalidArgumentException($code); }

    /** Exact semantic comparison across two different original paths. Normalize only numeric notation and object-key order. */
    private static function token(mixed $value,array $map,array $path):string
    {
        [$type,$scalar]=SchemaW2::scalar($value,$map,$path);
        if ($type!=='object' && $type!=='array') return hash('sha256',serialize([$type,$scalar]));
        $parts=[];foreach ($value as $key=>$child) {
            $key=$type==='object'?(string)$key:(int)$key;
            $parts[$key]=self::token($child,$map,[...$path,$key]);
        }
        if ($type==='object') ksort($parts,SORT_STRING);
        return hash('sha256',serialize([$type,$parts]));
    }

    private static function items(array $horse,array $map,array $path,string $field,?array $in=null,?callable $sourceValue=null,array &$cache=[]):array
    {
        $out=[];$list=$horse[$field]??null;
        if (!is_array($list) || SchemaW2::scalar($list,$map,[...$path,$field])[0]!=='array') return [];
        $duplicates=[];$ids=[];
        if ($field==='evidence') foreach ($list as $item) {
            if (!is_array($item) || !is_string($item['evidence_id']??null)) continue;
            $id=$item['evidence_id'];if (isset($ids[$id])) $duplicates[$id]=true;$ids[$id]=true;
        }
        foreach ($list as $i=>$item) {
            if (!is_array($item) || ($field==='evidence' && (!is_string($item['evidence_id']??null) || isset($duplicates[$item['evidence_id']])))) continue;
            $at=[...$path,$field,(int)$i];
            if ($in!==null) {
                if (!SchemaW2::validHorseItem($field,$item,$map,$at)) continue;
                $reg=$in['source_registry'];
                try {
                    if ($field==='evidence') SourceEvidenceW2::checkedEvidence($in,$reg,$sourceValue,$item,$horse['horse_no'],$map,$at,$cache);
                    else SourceEvidenceW2::counterItem($reg,$item,$horse['horse_no']);
                } catch (InvalidArgumentException $ex) { continue; }
            }
            $token=self::token($item,$map,$at);$out[$token]=($out[$token]??0)+1;
        }
        ksort($out,SORT_STRING);return $out;
    }

    private static function origin(array $input,array $parsed):array
    {
        $raw=$parsed['json'];$map=$parsed['map'];CandidateSelectionW2::checkEnvelope($input,$raw);
        $rows=$raw['horses']??null;self::need(is_array($rows) && SchemaW2::scalar($rows,$map,['horses'])[0]==='array','W4_ORIGIN_HORSES');
        $by=[];foreach ($rows as $pos=>$horse) {
            if (!is_array($horse) || !is_int($horse['horse_no']??null)) continue;
            $n=$horse['horse_no'];
            if (array_key_exists($n,$by)) { $by[$n]=null;continue; }
            $by[$n]=['row'=>$horse,'path'=>['horses',$pos]];
        }
        return ['by'=>$by,'map'=>$map,'reply'=>$raw];
    }

    private static function originOf(array $in,array $receipt):array
    {
        return self::origin($in,CandidateSelectionW2::parseOriginal($receipt['raw'],$receipt['hash']));
    }

    private static function facts(array $in,callable $sourceValue,array $entry,array $map,array &$cache):array
    {
        $out=[];$h=$entry['row'];$at=$entry['path'];
        foreach (['evidence','counterevidence'] as $field) $out[$field]=self::items($h,$map,$at,$field,$in,$sourceValue,$cache);
        return $out;
    }

    private static function scoreFacts(array $in,array $entry,array $map,array &$cache):array
    {
        $h=$entry['row'];$result=[];
        foreach (CandidateSelectionW2::scoreCaps() as $key=>$cap) {
            $v=$h['scores'][$key]??null;$proof=$h['score_evidence'][$key]??null;
            try { $ok=SourceEvidenceW2::scoreKnown($in,$h['horse_no'],$key,$v,$proof,$cache); }
            catch (InvalidArgumentException $ex) { $ok=false; }
            if ($ok && SchemaW2::scalar($proof,$map,[...$entry['path'],'score_evidence',$key])[0]==='object' &&
                SchemaW2::scalar($proof['source_refs'],$map,[...$entry['path'],'score_evidence',$key,'source_refs'])[0]==='array')
                $result[$key]=[$v,self::token(['reason'=>$proof['reason'],'source_refs'=>$proof['source_refs']],$map,[...$entry['path'],'score_evidence',$key])];
        }
        return $result;
    }

    private static function checkRowScope(?array $first,array $fix,int $no,string $band,array $scope):void
    {
        if (isset($scope['horses'][$no])) return;
        $a=$first['by'][$no]??null;$b=$fix['by'][$no]??null;
        self::need(is_array($a) && is_array($b),'W5_REPAIR_HORSE_SCOPE');
        $fields=[];
        if (isset($scope['summary_horses'][$no])) $fields=BaganrikiResponse::FIX_FIELDS_SUMMARY;
        if (isset($scope['presentation_horses'][$no])) $fields=array_merge($fields,BaganrikiResponse::FIX_FIELDS_PRESENTATION);
        if (isset($scope['rank_bands'][$band])) $fields=array_merge($fields,['rank_basis','rank_reason']);
        foreach (array_keys($a['row']+$b['row']) as $k) {
            if (in_array($k,$fields,true)) continue;
            // An unknown original property may only be removed, never changed into a new fact.
            if (isset($scope['summary_horses'][$no]) && !array_key_exists($k,$b['row']) && !SchemaW2::knownHorseField((string)$k)) continue;
            self::need(array_key_exists($k,$a['row']) && array_key_exists($k,$b['row']) &&
                self::token($a['row'][$k],$first['map'],[...$a['path'],$k])===self::token($b['row'][$k],$fix['map'],[...$b['path'],$k]),'W5_REPAIR_FIELD_SCOPE');
        }
    }

    /** Rows isolated from normal may still have an identifiable original. Verify field limits before supplying them to the legacy row comparator. */
    public static function scopedRescues(array $in,array $firstRow,array $fixRow,array $inspection,array $missing):array
    {
        $first=self::originOf($in,$firstRow);$fix=self::originOf($in,$fixRow);
        $hash=$firstRow['hash'];$scope=AttemptWindowW2::scope(AttemptWindowW2::repairGrant($hash,$inspection),$hash,$in);
        $bands=array_column($in['runners'],'band','horse_no');$permitted=[];
        foreach ($missing as $no) {
            if (!isset($bands[$no]) || isset($scope['horses'][$no])) continue;
            if (!isset($scope['summary_horses'][$no]) && !isset($scope['presentation_horses'][$no]) && !isset($scope['rank_bands'][$bands[$no]])) continue;
            self::checkRowScope($first,$fix,$no,$bands[$no],$scope);
            $permitted[]=$no;
        }
        return $permitted;
    }

    /** Validate a raw backup order against the current eligible horses and each horse's retained evidence. */
    private static function rankClaim(array $origin,string $band,array $by,array $sets):array
    {
        $decl=$origin['reply']['evidence_band_ranks'][$band]??null;$map=$origin['map'];$eligible=[];
        self::need(is_array($decl) && SchemaW2::scalar($decl,$map,['evidence_band_ranks',$band])[0]==='array' &&
            count(array_filter($decl,'is_int'))===count($decl) && count(array_unique($decl))===count($decl),'W4_UNPROVEN_RANK');
        foreach ($decl as $no) self::need(isset($by[$no]) && $by[$no]['band']===$band,'W4_UNPROVEN_RANK');
        foreach ($by as $no=>$h) {
            if ($h['band']!==$band) continue;
            $entry=$origin['by'][$no]??null;
            if ($h['eligibility']!=='ELIGIBLE') {
                self::need(empty($entry['row']['evidence']),'W4_CHANGED_RANK_EVIDENCE');continue;
            }
            $eligible[$no]=true;self::need(is_array($entry) && in_array($no,$decl,true),'W4_UNPROVEN_RANK');
            $old=$entry['row'];$at=$entry['path'];
            if (($old['rank_basis']??null)==='EVIDENCE') {
                $primary=$origin['reply']['band_ranks'][$band]??null;
                self::need(is_array($primary) && SchemaW2::scalar($primary,$map,['band_ranks',$band])[0]==='array' && $primary===$decl,'W4_UNPROVEN_RANK');
            }
            $ids=$old['evidence_rank_evidence_ids']??null;$actual=array_column($h['evidence'],'evidence_id');
            self::need(is_array($ids) && $ids && SchemaW2::scalar($ids,$map,[...$at,'evidence_rank_evidence_ids'])[0]==='array' &&
                count(array_filter($ids,'is_string'))===count($ids) && count(array_unique($ids))===count($ids) && !array_diff($ids,$actual) &&
                is_string($old['evidence_rank_reason']??null) && TextW2::visible($old['evidence_rank_reason']),'W4_UNPROVEN_RANK');
            foreach (['evidence','counterevidence'] as $field) {
                $before=$old[$field]??null;
                self::need(is_array($before) && SchemaW2::scalar($before,$map,[...$at,$field])[0]==='array' &&
                    count($before)===count($h[$field]) && self::items($old,$map,$at,$field)===$sets[$no][$field],'W4_CHANGED_RANK_EVIDENCE');
            }
        }
        return array_values(array_filter($decl,static fn($no)=>isset($eligible[$no])));
    }

    private static function allowedRankProofs(?array $first,array $fix,array $scope,string $band,array $by):bool
    {
        foreach ($by as $no=>$h) {
            if ($h['band']!==$band || $h['eligibility']!=='ELIGIBLE' || isset($scope['horses'][$no])) continue;
            $prior=$first['by'][$no]??null;$next=$fix['by'][$no]??null;
            if (!$prior || !$next) return false;
            foreach (['rank_basis','evidence_rank_reason','evidence_rank_evidence_ids'] as $field) {
                $mayEdit=$field==='rank_basis'?(isset($scope['rank_bands'][$band]) || isset($scope['summary_horses'][$no])):isset($scope['presentation_horses'][$no]);
                if ($mayEdit) continue;
                if (!array_key_exists($field,$prior['row']) || !array_key_exists($field,$next['row']) ||
                    self::token($prior['row'][$field],$first['map'],[...$prior['path'],$field])!==self::token($next['row'][$field],$fix['map'],[...$next['path'],$field])) return false;
            }
        }
        return true;
    }

    /** A repaired fact may invalidate a band's first ranking. A narrative or score-only edit in evidence mode cannot. */
    private static function canUseFixRank(array $in,callable $sourceValue,?array $first,array $scope,string $band,array $order,array $by,array $sets,array &$cache):bool
    {
        if (isset($scope['rank_bands'][$band])) return true;
        foreach ($by as $no=>$h) {
            if ($h['band']!==$band || !isset($scope['horses'][$no])) continue;
            $old=$first['by'][$no]??null;
            $was=$old?self::facts($in,$sourceValue,$old,$first['map'],$cache):['evidence'=>[],'counterevidence'=>[]];
            if (($was['evidence'] || $sets[$no]['evidence']) && $was!==$sets[$no]) return true;
        }
        // A corrected explanation can substantiate the very same old order without receiving permission to reorder.
        if (!$first) return false;
        $priorBackup=$first['reply']['evidence_band_ranks'][$band]??null;
        if ($priorBackup!==$order) return false;
        // A score-derived primary order can legitimately differ from its unchanged evidence backup.
        if ($order && ($by[$order[0]]['rank_basis']??null)==='SCORE') return true;
        return ($first['reply']['band_ranks'][$band]??null)===$order;
    }

    public static function checkOriginalCorrection(FrozenInputW2 $snapshot,array $prior,array $corrected):void
    {
        self::checkCorrection(FrozenInputW2::checked($snapshot),[$snapshot,'sourceValue'],$prior,$corrected);
    }

    /** Preliminary comparison cannot authorize publication. Run the same field and rank scope rules on the saved raw pair. */
    public static function inspectCorrection(array $in,array $types,array $first,array $fix,array $inspection):void
    {
        $hash=$first['hash'];
        $fix['scope']=AttemptWindowW2::scope(AttemptWindowW2::repairGrant($hash,$inspection),$hash,$in);
        $sourceValue=static fn(string $ref):array=>SchemaW2::scalar($in['source_registry'][$ref]['value'],$types,['source_registry',$ref,'value']);
        self::checkCorrection($in,$sourceValue,$first,$fix);
    }

    private static function checkCorrection(array $in,callable $sourceValue,array $prior,array $corrected):void
    {
        $fix=self::originOf($in,$corrected);
        try { $first=self::originOf($in,$prior); } catch (InvalidArgumentException $ex) { $first=null; }
        RaceMetricsW2::checkCorrection($in,$first['reply']??null,$fix['reply'],$first['map']??null,$fix['map']);
        $scope=$corrected['scope'];$by=[];$sets=[];$cache=[];
        foreach ($in['runners'] as $runner) {
            $no=$runner['horse_no'];$band=$runner['band'];self::checkRowScope($first,$fix,$no,$band,$scope);
            $entry=$fix['by'][$no];$h=$entry['row'];$by[$no]=$h;
            $sets[$no]=self::facts($in,$sourceValue,$entry,$fix['map'],$cache);
            $old=$first['by'][$no]??null;
            if ($old!==null) {
                if (!$h['evidence']) self::need(!self::facts($in,$sourceValue,$old,$first['map'],$cache)['evidence'],'W4_RECOVERY_REGRESSION');
                if ($h['score_status']!=='complete' || (isset($scope['summary_horses'][$no]) && !isset($scope['horses'][$no]))) {
                    $before=self::scoreFacts($in,$old,$first['map'],$cache);
                    $after=self::scoreFacts($in,$entry,$fix['map'],$cache);
                    self::need(!array_diff_key($before,$after),'W4_RECOVERY_REGRESSION');
                    if (isset($scope['summary_horses'][$no]) && !isset($scope['horses'][$no]))
                        self::need(array_intersect_key($after,$before)===$before,'W12_UNCHANGED_SCORE');
                }
            }
        }
        foreach ($fix['reply']['evidence_band_ranks'] as $band=>$order) {
            if (!$order || isset($scope['rank_bands'][$band])) continue;
            $initial=null;
            if ($first) {
                try { $initial=self::rankClaim($first,$band,$by,$sets); } catch (InvalidArgumentException $ex) { /* An incompatible first claim cannot set this order. */ }
            }
            self::need($initial!==null?$order===$initial:self::canUseFixRank($in,$sourceValue,$first,$scope,$band,$order,$by,$sets,$cache),'W5_REPAIR_RANK_SCOPE');
        }
    }

    public static function verify(FrozenInputW2 $snapshot,string $raw,string $hash,array $receipts):array
    {
        self::need(strlen($raw)<=4194304 && hash_equals(hash('sha256',$raw),$hash),'W4_RECOVERY_HASH');
        $parsed=BaganrikiJson::decode($raw);
        self::need($parsed['ok'] && is_array($parsed['value']),'W4_RECOVERY_JSON');
        $v=$parsed['value'];$map=$parsed['map'];SchemaW2::validate('recovery',$v,$map);
        $in=FrozenInputW2::checked($snapshot);$sourceValue=[$snapshot,'sourceValue'];
        foreach (['schema_version','prompt_version','race_id','ai_role','snapshot_hash'] as $key) self::need($in[$key]===$v[$key],'W4_RECOVERY_ID');
        $counts=SourceEvidenceW2::reply($snapshot,$v,$map);
        $origins=[];$received=[];$originFailure=[];
        foreach ($receipts as $r) {
            $id=$r['hash'];$received[$id]=true;
            if (isset($origins[$id]) || isset($originFailure[$id])) continue;
            try { $origins[$id]=self::originOf($in,$r); }
            catch (InvalidArgumentException $ex) { $originFailure[$id]=$ex->getMessage(); }
        }
        $refHashes=[];foreach ($v['row_sources'] as $list) foreach ($list as $id) $refHashes[$id]=true;
        foreach ($v['rank_sources'] as $id) if ($id!==null) $refHashes[$id]=true;
        foreach (array_keys($refHashes) as $id) {
            self::need(isset($received[$id]),'W4_RECOVERY_STALE_SOURCE');
            self::need(isset($origins[$id]),$originFailure[$id]??'W4_RECOVERY_STALE_SOURCE');
        }
        $firstId=$receipts[0]['hash'];$first=$origins[$firstId]??null;
        $fixId=$receipts[1]['hash']??null;$scope=$receipts[1]['scope']??[];
        $cache=[];$retainedRanks=[];
        $by=[];$evidenceSets=[];$fallbackBands=[];$eligible=array_fill_keys(CandidateSelectionW2::BANDS,[]);
        self::need(count($v['row_sources'])===count($v['horses']),'W4_ROW_SOURCE_SET');
        foreach ($v['horses'] as $pos=>$h) {
            $no=$h['horse_no'];$by[$no]=$h;$path=['horses',$pos];
            if ($h['eligibility']==='ELIGIBLE') $eligible[$h['band']][$no]=$h;
            self::need(array_key_exists($no,$v['row_sources']),'W4_ROW_SOURCE_SET');
            $entry=['row'=>$h,'path'=>$path];$used=self::facts($in,$sourceValue,$entry,$map,$cache);
            $scores=self::scoreFacts($in,$entry,$map,$cache);$evidenceSets[$no]=$used;
            $prior=$first['by'][$no]??null;$allowed=$v['row_sources'][$no];
            if ($prior!==null) {
                if (!$used['evidence']) self::need(!self::facts($in,$sourceValue,$prior,$first['map'],$cache)['evidence'],'W4_RECOVERY_REGRESSION');
                if ($h['score_status']!=='complete')
                    self::need(!array_diff_key(self::scoreFacts($in,$prior,$first['map'],$cache),$scores),'W4_RECOVERY_REGRESSION');
            }
            self::need($allowed!==[] || $h['eligibility']==='UNVERIFIED','W4_MISSING_ROW_SOURCE');
            $foundFacts=(!$allowed && !$used['evidence'] && !$used['counterevidence']);
            $foundScores=(!$allowed && !$scores);$coherent=($foundFacts && $foundScores);$reviews=[];
            foreach ($allowed as $id) {
                $origin=$origins[$id];$row=$origin['by'][$no]??null;self::need(is_array($row),'W4_MISSING_ROW_SOURCE');
                if ($id===$fixId && $id!==$firstId) {
                    self::checkRowScope($first,$origin,$no,$h['band'],$scope);
                    self::need(isset($scope['horses'][$no]) || isset($scope['summary_horses'][$no]),'W5_RESTATED_FACT_SOURCE');
                    if ($prior && !isset($scope['horses'][$no])) {
                        $before=self::scoreFacts($in,$prior,$first['map'],$cache);
                        $after=self::scoreFacts($in,$row,$origin['map'],$cache);
                        self::need(array_intersect_key($after,$before)===$before,'W12_UNCHANGED_SCORE');
                    }
                    self::need(SchemaW2::validOriginalHorse($row['row'],$origin['map'],$row['path']),'W5_INVALID_CORRECTION_ROW');
                    // This is a real AI correction row, never a server placeholder.
                    SourceEvidenceW2::reply($snapshot,['horses'=>[$row['path'][1]=>$row['row']],'band_ranks'=>[]],$origin['map']);
                    self::need($row['row']['band']===$h['band'],'W5_INVALID_CORRECTION_ROW');
                }
                $sameFacts=self::facts($in,$sourceValue,$row,$origin['map'],$cache)===$used;
                $sameScores=self::scoreFacts($in,$row,$origin['map'],$cache)===$scores;
                $foundFacts=$foundFacts || $sameFacts;$foundScores=$foundScores || $sameScores;
                $coherent=$coherent || ($sameFacts && $sameScores);
                foreach ($h['family_review'] as $k=>$rv) if ($rv['status']==='NO_SUPPORT' && is_array($row['row']['family_review'][$k]??null))
                    $reviews[$k][self::token($row['row']['family_review'][$k],$origin['map'],[...$row['path'],'family_review',$k])]=true;
            }
            self::need($foundFacts,'W4_RECOVERY_UNTRACED_ITEM');self::need($foundScores,'W4_RECOVERY_UNTRACED_SCORE');
            self::need($coherent || isset($scope['summary_horses'][$no]),'W5_RECOVERY_MIXED_ROW');
            foreach ($h['family_review'] as $k=>$rv) if ($rv['status']==='NO_SUPPORT')
                self::need(isset($reviews[$k][self::token($rv,$map,[...$path,'family_review',$k])]),'W4_RECOVERY_UNTRACED_REVIEW');
        }
        $ranks=[];$rebuilt=[];
        foreach (CandidateSelectionW2::BANDS as $band) {
            $members=array_keys($eligible[$band]);$claimed=$v['base_ranks'][$band]??null;$id=$v['rank_sources'][$band];
            if ($id!==null) {
                self::need(is_array($claimed) && count($claimed)===count($members) && !array_diff($members,$claimed),'W2_LOST_OR_DUPLICATE_BAND');
            }
            $scoreOrder=CandidateSelectionW2::scoreOrder($eligible[$band]);
            if ($scoreOrder!==null) {
                if ($id!==null) {
                    self::need($scoreOrder===$claimed,'W2_BASE_SCORE_ORDER_'.$band);
                    $origin=$origins[$id];$rawOrder=$origin['reply']['band_ranks'][$band]??null;
                    self::need(is_array($rawOrder) && $rawOrder===$scoreOrder &&
                        SchemaW2::scalar($rawOrder,$origin['map'],['band_ranks',$band])[0]==='array','W7_UNPROVEN_SCORE_RANK');
                }
                $ranks[$band]=$scoreOrder;continue;
            }
            $order=$id===null?$members:$claimed;$claims=[];$errors=[];
            foreach ($origins as $hash=>$origin) {
                try { $claims[$hash]=self::rankClaim($origin,$band,$by,$evidenceSets); }
                catch (InvalidArgumentException $ex) { $errors[$hash]=$ex->getMessage(); }
            }
            if ($id!==null) self::need(isset($claims[$id]),$errors[$id]??'W4_UNPROVEN_RANK');
            if ($fixId!==null && $fixId!==$firstId && isset($claims[$fixId]) &&
                (!self::canUseFixRank($in,$sourceValue,$first,$scope,$band,$claims[$fixId],$by,$evidenceSets,$cache) ||
                 !self::allowedRankProofs($first,$origins[$fixId],$scope,$band,$by))) unset($claims[$fixId]);
            $preferred=isset($scope['rank_bands'][$band])?[$fixId,$firstId]:[$firstId,$fixId];
            $chosen=null;
            foreach ($preferred as $candidate) if ($candidate!==null && isset($claims[$candidate])) { $chosen=$candidate;break; }
            if ($chosen!==null) {
                // A stale or missing source must never supersede an otherwise usable authorized ranking.
                if ($id===$chosen) self::need($claims[$chosen]===$order,'W4_UNPROVEN_RANK');
                else $retainedRanks[$band]=$chosen;
                $ranks[$band]=$claims[$chosen];continue;
            }
            $fallbackBands[]=$band;$fallback=$order;
            usort($fallback,static fn($a,$b)=>($counts[$b]<=>$counts[$a]) ?: ($a<=>$b));
            $ranks[$band]=$fallback;
        }
        foreach ($ranks as $band=>$order) if ($v['rank_sources'][$band]===null && ($v['base_ranks'][$band]??null)!==$order) $rebuilt[]=$band;
        $result=CandidateSelectionW2::apply($snapshot,$v['horses'],$ranks);
        $result['rebuilt_base_bands']=$rebuilt;
        if (RaceMetricsW2::enabled($in) && $in['ai_role']==='1st') {
            $result['race_metrics']=RaceMetricsW2::unknown($in);
            if ($first!==null) $result['race_metrics']=RaceMetricsW2::fromResponse($in,$first['reply'],$firstId,$first['map']);
            if ($fixId!==null && isset($origins[$fixId])) {
                try {
                    RaceMetricsW2::checkCorrection($in,$first['reply']??null,$origins[$fixId]['reply'],$first['map']??null,$origins[$fixId]['map']);
                    $next=RaceMetricsW2::fromResponse($in,$origins[$fixId]['reply'],$fixId,$origins[$fixId]['map']);
                    // The guard already preserved every valid initial field. Adopt each repaired field
                    // even when a different field is still invalid; all values remain bound to this receipt.
                    $metricIssues=RaceMetricsW2::issues($in,$origins[$fixId]['reply'],$origins[$fixId]['map']);
                    if (!isset($metricIssues['race_metrics'])) $result['race_metrics']=$next;
                } catch (InvalidArgumentException $e) { /* Keep the inspected initial assessment. */ }
            }
        }
        $result['independent_groups']=$counts;$result['rank_fallback_bands']=$fallbackBands;$result['retained_rank_sources']=$retainedRanks;
        return $result;
    }
}

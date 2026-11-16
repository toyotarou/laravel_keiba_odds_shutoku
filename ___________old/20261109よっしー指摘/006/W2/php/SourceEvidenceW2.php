<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use InvalidArgumentException;
use App\Http\Controllers\Api\BaganrikiPrompt;
require_once __DIR__.'/SchemaW2.php';
require_once __DIR__.'/DerivedValueW2.php';
require_once __DIR__.'/ReturnStatsW2.php';
require_once __DIR__.'/ScoreMaterialsW2.php';
require_once __DIR__.'/MarketClaimW2.php';

final class SourceEvidenceW2
{
    private static function need(bool $ok,string $code):void { if (!$ok) throw new InvalidArgumentException($code); }

    /** Earliest usable instant of the frozen input, including any later derived calculation. */
    public static function readyAt(array $in):\DateTimeImmutable
    {
        $ready=ExpectedPacketW2::instant($in['input_freeze_at']);
        foreach ($in['source_registry'] as $source) {
            if ($source['source_kind']==='DERIVED') $ready=max($ready,ExpectedPacketW2::instant($source['computed_at']));
        }
        self::need($ready<=ExpectedPacketW2::instant($in['time_contract']['reply_deadline']),'W4_COMPUTED_AFTER_REPLY');
        return $ready;
    }

    public static function input(array $in,array $map):\DateTimeImmutable
    {
        $reg=$in['source_registry']; $roster=array_fill_keys(array_column($in['runners'],'horse_no'),true);
        self::need(count($reg)<=20000,'W3_REGISTRY_SIZE');
        $freeze=ExpectedPacketW2::instant($in['input_freeze_at']);$degree=[];$children=[];$queue=[];$observations=[];
        foreach ($reg as $id=>$e) {
            $id=(string)$id; $catalog=$in['field_catalog'][$e['field']]??null;
            self::need($id===$e['source_ref'] && $e['race_id']===$in['race_id'] && $catalog!==null &&
                $catalog['unit']===$e['unit'] && $catalog['scope']===$e['scope'],'W3_SOURCE_ID');
            foreach (array_merge($e['horse_nos'],$e['applicable_horse_nos']) as $n)
                self::need(is_int($n) && isset($roster[$n]),'W3_SOURCE_HORSE');
            if (ReturnStatsW2::reserved($e['field'])) self::need($e['dependency_role']==='CONTEXT' && $e['scope']==='COHORT' && $e['source_kind']==='OBSERVED' && preg_match('/^v3_(win|place)_(return_pct|sample_count)$/D',$e['field'])===1,'W12_RETURN_FIELD');
            $path=['source_registry',$id,'value'];
            [$type,$number]=SchemaW2::scalar($e['value'],$map,$path);
            self::need($type===$catalog['value_type'] || ($type==='null' && $catalog['missing_allowed']),'W3_SOURCE_TYPE');
            if ($type==='number') foreach (['minimum','maximum'] as $key) if ($catalog[$key]!==null) {
                [, $bound]=SchemaW2::scalar($catalog[$key],$map,['field_catalog',$e['field'],$key]);
                $c=SchemaW2::decimals($number,$bound);
                self::need($key==='minimum'?$c>=0:$c<=0,'W3_SOURCE_RANGE');
            }
            $observed=ExpectedPacketW2::instant($e['observed_at']);$available=ExpectedPacketW2::instant($e['available_at']);
            self::need($observed<=$available && $available<=$freeze,'W3_SOURCE_TIME');
            if ($e['source_kind']==='DERIVED') {
                $computed=ExpectedPacketW2::instant($e['computed_at']);
                self::need($computed>=$available,'W4_COMPUTED_BEFORE_DATA');
            } else {
                $owners=$e['horse_nos'];$members=$e['applicable_horse_nos'];sort($owners,SORT_NUMERIC);sort($members,SORT_NUMERIC);
                $observation=serialize([$e['scope'],$owners,$members,$e['field'],$e['unit'],$e['slot_offset'],$observed->format('U.u'),
                    ReturnStatsW2::reserved($e['field'])?$e['dependency_key']:null]);
                self::need(!isset($observations[$observation]),'W4_DUPLICATE_OBSERVATION');
                $observations[$observation]=true;
            }
            $degree[$id]=count($e['parents']);
            if ($degree[$id]===0) $queue[]=$id;
            foreach ($e['parents'] as $p) {
                self::need(isset($reg[$p]),'W3_SOURCE_PARENT');
                $parent=$reg[$p];
                self::need(ExpectedPacketW2::instant($parent['observed_at'])<=$observed &&
                    ExpectedPacketW2::instant($parent['available_at'])<=$available,'W3_PARENT_TIME');
                if ($parent['computed_at']!==null && $e['computed_at']!==null)
                    self::need(ExpectedPacketW2::instant($parent['computed_at'])<=ExpectedPacketW2::instant($e['computed_at']),'W3_PARENT_TIME');
                $children[$p][]=$id;
            }
        }
        for ($i=0;$i<count($queue);$i++) foreach ($children[$queue[$i]]??[] as $child) if (--$degree[$child]===0) $queue[]=$child;
        self::need(count($queue)===count($reg),'W3_SOURCE_CYCLE');
        foreach ($queue as $id) if ($reg[$id]['source_kind']==='DERIVED') DerivedValueW2::verify((string)$id,$reg,$map);
        foreach ($in['input_indices'] as $key=>$refs) {
            $address=(string)$key;
            $typed=preg_match('~^(?:horse|pair|cohort|race)/~D',$address)===1;
            $seen=[];
            foreach ($refs as $id) {
                self::need(isset($reg[$id]),'W3_UNKNOWN_INDEX_REF');
                self::need(!isset($seen[$id]),'W7_DUPLICATE_INDEX_REF');$seen[$id]=true;
                if ($typed) self::need(BaganrikiPrompt::indexKey($reg[$id])===$address,'W7_INDEX_BINDING');
            }
        }
        foreach ($in['coverage'] as $entry) {
            foreach ($entry['source_refs']??[] as $id) self::need(isset($reg[$id]),'W3_UNKNOWN_COVERAGE_REF');
            if ($entry['status']==='UNAVAILABLE') self::need(TextW2::visible($entry['reason']),'W3_COVERAGE_REASON');
        }
        return self::readyAt($in);
    }

    private static function source(array $reg,string $ref,int $no):array
    {
        $s=$reg[$ref]??null;self::need($s!==null,'W3_UNKNOWN_REPLY_REF');
        $ok=$s['scope']==='RACE' || in_array($no,$s['scope']==='COHORT'?$s['applicable_horse_nos']:$s['horse_nos'],true);
        self::need($ok,'W3_REPLY_REF_OWNER');
        return $s;
    }
    /** On local salvage keep only references that were actually present and belonged to this horse. */
    public static function retainedRefs(array $reg,int $no,mixed $refs):array
    {
        $kept=[];foreach (is_array($refs)?$refs:[] as $ref) {
            if (!is_string($ref) || isset($kept[$ref])) continue;
            try { self::source($reg,$ref,$no);$kept[$ref]=true; }
            catch (InvalidArgumentException $invalid) { continue; }
        }
        return array_map('strval',array_keys($kept));
    }

    private static function sameSet(array $a,array $b):bool { sort($a,SORT_STRING);sort($b,SORT_STRING);return $a===$b; }

    /** A directly cited CONTEXT is not a signal. A SIGNAL derivation follows all parents to real signal leaves. */
    public static function signalKeys(array $reg,string $ref,array &$cache,?int $horse=null):array
    {
        if ($reg[$ref]['dependency_role']!=='SIGNAL') return [];
        $slice=$horse??0;
        if (isset($cache[$slice][$ref])) return $cache[$slice][$ref];
        $todo=[$ref];$seen=[];$keys=[];
        while ($todo) {
            $id=array_pop($todo); if (isset($seen[$id])) continue; $seen[$id]=true;
            if (isset($cache[$slice][$id])) { $keys+=$cache[$slice][$id];continue; }
            $e=$reg[$id];
            if ($e['parents']) foreach ($e['parents'] as $parent) $todo[]=$parent;
            elseif ($e['dependency_role']==='SIGNAL' && ($horse===null ||
                ($e['value']!==null && in_array($horse,$e['scope']==='COHORT'?$e['applicable_horse_nos']:$e['horse_nos'],true))))
                $keys[$e['dependency_key']]=true;
        }
        return $cache[$slice][$ref]=$keys;
    }

    /** Count distinct roots of previously inspected evidence, using the same family/leaf graph as checkHorse. */
    public static function acceptedGroupCount(array $registry,array $items,array &$cache):int
    {
        $evidence=[];
        foreach ($items as $item) {
            $roots=[];
            foreach ($item['facts'] as $fact) {
                $ref=$fact['source_ref'];
                self::need(is_string($ref) && isset($registry[$ref]),'W3_EVIDENCE_FACTS');
                if ($registry[$ref]['value']!==null) $roots+=self::signalKeys($registry,$ref,$cache);
            }
            self::need($roots!==[],'W3_EVIDENCE_FACTS');
            $evidence[]=[$item['family'],$roots];
        }
        return self::independentCount($evidence);
    }

    /** Components have disjoint root and family sets, so at most five survive. */
    public static function independentCount(array $evidence):int
    {
        $components=[];
        foreach ($evidence as [$family,$roots]) {
            $families=[$family=>true];$kept=[];
            foreach ($components as [$oldFamilies,$oldRoots]) {
                if (array_intersect_key($oldFamilies,$families) || array_intersect_key($oldRoots,$roots)) {
                    $families+=$oldFamilies;$roots+=$oldRoots;
                } else $kept[]=[$oldFamilies,$oldRoots];
            }
            $kept[]=[$families,$roots];$components=$kept;
        }
        return count($components);
    }

    public static function familyKnown(array $in,int $no,mixed $family,mixed $refs,array &$cache,bool $requireSignal=true):bool
    {
        if (!is_string($family) || !is_array($refs) || !$refs || !ExpectedPacketW2::isList($refs)) return false;
        $seen=[];
        foreach ($refs as $id) {
            if (!is_string($id) || isset($seen[$id])) return false;
            $seen[$id]=true;
            try { self::source($in['source_registry'],$id,$no); } catch (InvalidArgumentException $invalid) { return false; }
        }
        $keys=['ability'=>['H1'],'market'=>['H2'],'gap'=>['H3'],'win_place'=>['H5'],'similar_value'=>['H4','V1']];
        foreach ($keys[$family]??[] as $key) if (ScoreMaterialsW2::supports($in,$no,$key,$refs,$cache,true,$requireSignal)) return true;
        return false;
    }

    /** Availability is independent of the AI's selected citations and of positive support. */
    public static function availableRefs(array $in,int $no):array
    {
        $refs=[];
        foreach ($in['source_registry'] as $id=>$source) {
            if ($source['scope']==='RACE' || $source['value']===null) continue;
            $owners=$source['scope']==='COHORT'?$source['applicable_horse_nos']:$source['horse_nos'];
            if (in_array($no,$owners,true)) $refs[]=(string)$id;
        }
        return $refs;
    }

    public static function evidenceItem(FrozenInputW2 $frozen,array $reg,array $item,int $no,array $map,array $path,array &$cache):array
    {
        return self::checkedEvidence(FrozenInputW2::checked($frozen),$reg,[$frozen,'sourceValue'],$item,$no,$map,$path,$cache);
    }

    /** Shared by receipt comparison and frozen-input verification; values come from the respective exact JSON type map. */
    public static function checkedEvidence(array $in,array $reg,callable $sourceValue,array $item,int $no,array $map,array $path,array &$cache):array
    {
        self::need(TextW2::visible($item['interpretation']),'W4_EVIDENCE_INTERPRETATION');
        $facts=[];$signal=false;$keys=[];
        foreach ($item['facts'] as $f=>$fact) {
            $ref=$fact['source_ref'];$src=self::source($reg,$ref,$no);$facts[]=$ref;
            $got=SchemaW2::scalar($fact['value'],$map,[...$path,'facts',$f,'value']);
            self::need($got===$sourceValue($ref),'W3_FACT_VALUE');
            // A missing observation has no value to share between two otherwise independent families.
            if ($src['value']===null) continue;
            $factRoots=self::signalKeys($reg,$ref,$cache);$keys+=$factRoots;
            if ($factRoots && $src['value']!==null && self::signalKeys($reg,$ref,$cache,$no)) $signal=true;
        }
        self::need(self::sameSet($item['source_refs'],$facts) && $signal,'W3_EVIDENCE_FACTS');
        self::need(self::familyKnown($in,$no,$item['family'],$facts,$cache),'W13_EVIDENCE_FAMILY_'.$item['family']);
        MarketClaimW2::verify($reg,$sourceValue,$item,$no);
        return [$facts,$keys];
    }

    public static function counterItem(array $reg,array $item,int $no):void
    {
        $known=false;
        foreach ($item['source_refs'] as $id) if (self::source($reg,$id,$no)['value']!==null) $known=true;
        self::need($known && TextW2::visible($item['reason']),'W3_COUNTEREVIDENCE');
    }

    /** Applied identically to initial, corrected and restored responses. Input must be the verified frozen tree. */
    public static function scoreKnown(array $in,int $no,string $key,mixed $points,mixed $proof,array &$cache):bool
    {
        $cap=CandidateSelectionW2::scoreCaps()[$key]??null;
        if ($cap===null || !is_int($points) || $points<0 || $points>$cap || !is_array($proof) ||
            !is_array($proof['source_refs']??null) || !ExpectedPacketW2::isList($proof['source_refs']) ||
            !is_string($proof['reason']??null) || !TextW2::visible($proof['reason'])) return false;
        $seen=[];$refs=$proof['source_refs'];
        foreach ($refs as $id) {
            if (!is_string($id) || isset($seen[$id])) return false;
            $seen[$id]=true;self::source($in['source_registry'],$id,$no);
        }
        return $key==='V3' ? ReturnStatsW2::supports($in,$no,$refs,$points) : ScoreMaterialsW2::supports($in,$no,$key,$refs,$cache);
    }

    /** Raw values on each side retain the decoder's numeric-vs-string distinction. */
    public static function reply(FrozenInputW2 $frozen,array $reply,array $map):array
    {
        $cache=[];
        return self::checkRows(FrozenInputW2::checked($frozen),[$frozen,'sourceValue'],$reply,$map,$cache);
    }

    /** The original public entry point still throws the first defect on an otherwise well-shaped row. */
    public static function inspectRow(array $in,array $horse,int $index,array $inputMap,array $replyMap,array &$cache):void
    {
        $issues=self::inspectIssues($in,$horse,$index,$inputMap,$replyMap,$cache,[]);
        if ($issues) throw new InvalidArgumentException($issues[0]);
    }

    /** Collect independent findings on a registered horse. The final verifier uses the same checks in fail-fast mode. */
    public static function inspectIssues(array $in,array $horse,int $index,array $inputMap,array $replyMap,array &$cache,array $malformed):array
    {
        $sourceValue=static fn(string $ref):array=>SchemaW2::scalar($in['source_registry'][$ref]['value'],$inputMap,['source_registry',$ref,'value']);
        $codes=[];
        $report=static function(string $code)use(&$codes):void { $codes[$code]=true; };
        self::checkHorse($in,$sourceValue,$horse,$index,$replyMap,$cache,true,$malformed,$report);
        return array_keys($codes);
    }

    private static function checkRows(array $in,callable $sourceValue,array $reply,array $map,array &$cache):array
    {
        $counts=[];
        foreach ($reply['horses'] as $index=>$horse)
            $counts[$horse['horse_no']]=self::checkHorse($in,$sourceValue,$horse,$index,$map,$cache,array_key_exists('band_ranks',$reply),[],null);
        return $counts;
    }

    private static function checkHorse(array $in,callable $sourceValue,array $horse,int $index,array $map,array &$cache,bool $rankRequired,array $malformed,?callable $report):int
    {
        $reg=$in['source_registry'];$no=$horse['horse_no'];$ids=[];$families=[];$familyRefs=[];$groups=[];
        $check=static function(callable $rule)use($report):bool {
            try { $rule();return true; }
            catch (InvalidArgumentException $ex) { if ($report===null) throw $ex;$report($ex->getMessage());return false; }
        };
        // A malformed field does not hide an independent fact, score or ranking field on the same horse.
        $evidenceKnown=!isset($malformed['evidence']);
        if ($evidenceKnown) foreach ($horse['evidence'] as $j=>$item) {
            $id=$item['evidence_id'];$family=$item['family'];
            $check(static fn()=>self::need(!isset($ids[$id]),'W3_EVIDENCE_ID'));$ids[$id]=true;
            $valid=$check(static function()use($in,$reg,$sourceValue,$item,$no,$map,$index,$j,&$cache,$family,&$groups):void {
                [, $keys]=self::checkedEvidence($in,$reg,$sourceValue,$item,$no,$map,['horses',$index,'evidence',$j],$cache);
                $groups[]=[$family,$keys];
            });
            if (!$valid) $evidenceKnown=false;
            $families[$family][]=$id;
            foreach ($item['facts'] as $fact) $familyRefs[$family][$fact['source_ref']]=true;
        }
        $available=null;
        if (!isset($malformed['family_review'])) foreach ($horse['family_review'] as $name=>$review) {
            $check(static function()use($reg,$no,$name,$review,$families,$familyRefs,$malformed,$in,&$cache,&$available,$rankRequired):void {
                $refs=$review['source_refs'];$nonempty=false;
                foreach ($refs as $ref) if (self::source($reg,$ref,$no)['value']!==null) $nonempty=true;
                self::need(TextW2::visible($review['reason']),'W4_FAMILY_REASON');
                if (!isset($malformed['evidence'])) {
                    $expected=$families[$name]??[];
                    self::need(self::sameSet($expected,$review['evidence_ids']) && ($review['status']==='SUPPORTED')===(bool)$expected,'W3_FAMILY_IDS');
                    foreach (array_keys($familyRefs[$name]??[]) as $ref) self::need(in_array((string)$ref,$refs,true),'W3_FAMILY_REFS');
                }
                self::need($review['status']!=='NO_SUPPORT' || $nonempty,'W3_FAMILY_NO_SUPPORT');
                if ($review['status']==='NO_SUPPORT') self::need(self::familyKnown($in,$no,$name,$refs,$cache,false),'W14_NO_SUPPORT_MATERIAL_'.$name);
                // Original/repair answers assert that they reviewed the whole input. A server recovery
                // row can instead be an UNVERIFIED placeholder for a genuinely absent answer row;
                // RecoveryW2 separately proves that no valid original evidence was erased.
                if ($review['status']==='DATA_MISSING' && $rankRequired) {
                    if ($available===null) $available=self::availableRefs($in,$no);
                    self::need(!self::familyKnown($in,$no,$name,$available,$cache,false),'W19_FALSE_MISSING_'.$name);
                }
            });
        }
        if (!isset($malformed['evidence']) && !isset($malformed['evidence_rank_evidence_ids']))
            foreach ($horse['evidence_rank_evidence_ids'] as $id) $check(static fn()=>self::need(isset($ids[$id]),'W3_RANK_EVIDENCE_ID'));
        if (!isset($malformed['counterevidence']))
            foreach ($horse['counterevidence'] as $counter) $check(static fn()=>self::counterItem($reg,$counter,$no));
        if (!isset($malformed['scores']) && !isset($malformed['score_evidence'])) foreach ($horse['score_evidence'] as $key=>$proof) {
            $check(static function()use($horse,$key,$proof,$reg,$in,$no,&$cache):void {
                if ($horse['scores'][$key]!==null) self::need(self::scoreKnown($in,$no,$key,$horse['scores'][$key],$proof,$cache),'W12_SCORE_MATERIAL_'.$key);
                else {
                    self::need(TextW2::visible($proof['reason']),'W12_SCORE_MISSING_REASON');
                    foreach ($proof['source_refs'] as $ref) self::source($reg,$ref,$no);
                }
            });
        }
        if (!isset($malformed['evidence']) && !isset($malformed['eligibility']))
            $check(static fn()=>self::need(($horse['eligibility']==='ELIGIBLE')===(bool)$ids,'W3_ELIGIBILITY_EVIDENCE'));
        $count=self::independentCount($groups);
        if ($ids && $rankRequired && !isset($malformed['evidence_rank_evidence_ids']) && !isset($malformed['evidence_rank_reason']))
            $check(static fn()=>self::need($horse['evidence_rank_evidence_ids']!==[] && TextW2::visible($horse['evidence_rank_reason']),'W4_ORIGINAL_RANK_REASON'));
        if ($ids && $evidenceKnown && !isset($malformed['tier']))
            $check(static fn()=>self::need($horse['tier']===($count>=2?'A':'B'),'W3_TIER_FAMILIES'));
        if (!array_intersect(array_keys($malformed),['scores','score_status','total_score','observed_sum','observed_cap','eligibility','rank_basis','evidence_rank_evidence_ids']))
            $check(static fn()=>CandidateSelectionW2::validateHorseSummary($horse));
        if (!$ids && $evidenceKnown && !isset($malformed['eligibility']) && !isset($malformed['family_review'])) {
            $missing=in_array('DATA_MISSING',array_column($horse['family_review'],'status'),true);
            $check(static fn()=>self::need($horse['eligibility']===($missing?'UNVERIFIED':'NO_EVIDENCE'),'W3_MISSING_VS_NO_EVIDENCE'));
        }
        return $count;
    }
}

<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

final class BaganrikiSourceEvidence
{
    private static function need(bool $ok,string $code):void { if (!$ok) throw new \InvalidArgumentException($code); }

    /** Earliest usable instant of the frozen input, including any later derived calculation. */
    public static function readyAt(array $in):\DateTimeImmutable
    {
        $ready=BaganrikiExpectedPacket::instant($in['input_freeze_at']);
        foreach ($in['source_registry'] as $source) {
            if ($source['source_kind']==='DERIVED') $ready=max($ready,BaganrikiExpectedPacket::instant($source['computed_at']));
        }
        self::need($ready<=BaganrikiExpectedPacket::instant($in['time_contract']['reply_deadline']),'EXPECTED_COMPUTED_AFTER_REPLY');
        return $ready;
    }

    public static function input(array $in,array $map):\DateTimeImmutable
    {
        $reg=$in['source_registry']; $roster=array_fill_keys(array_column($in['runners'],'horse_no'),true);
        self::need(count($reg)<=20000,'EXPECTED_REGISTRY_SIZE');
        $freeze=BaganrikiExpectedPacket::instant($in['input_freeze_at']);$degree=[];$children=[];$queue=[];$observations=[];
        foreach ($reg as $id=>$e) {
            $id=(string)$id; $catalog=$in['field_catalog'][$e['field']]??null;
            self::need($id===$e['source_ref'] && $e['race_id']===$in['race_id'] && $catalog!==null &&
                $catalog['unit']===$e['unit'] && $catalog['scope']===$e['scope'],'EXPECTED_SOURCE_ID');
            foreach (array_merge($e['horse_nos'],$e['applicable_horse_nos']) as $n)
                self::need(is_int($n) && isset($roster[$n]),'EXPECTED_SOURCE_HORSE');
            if (BaganrikiReturnStats::reserved($e['field'])) self::need($e['dependency_role']==='CONTEXT' && $e['scope']==='COHORT' && $e['source_kind']==='OBSERVED' && preg_match('/^v3_(win|place)_(return_pct|sample_count)$/D',$e['field'])===1,'EXPECTED_RETURN_FIELD');
            $path=['source_registry',$id,'value'];
            [$type,$number]=BaganrikiOutputSchema::scalar($e['value'],$map,$path);
            self::need($type===$catalog['value_type'] || ($type==='null' && $catalog['missing_allowed']),'EXPECTED_SOURCE_TYPE');
            if ($type==='number') foreach (['minimum','maximum'] as $key) if ($catalog[$key]!==null) {
                [, $bound]=BaganrikiOutputSchema::scalar($catalog[$key],$map,['field_catalog',$e['field'],$key]);
                $c=BaganrikiOutputSchema::decimals($number,$bound);
                self::need($key==='minimum'?$c>=0:$c<=0,'EXPECTED_SOURCE_RANGE');
            }
            $observed=BaganrikiExpectedPacket::instant($e['observed_at']);$available=BaganrikiExpectedPacket::instant($e['available_at']);
            self::need($observed<=$available && $available<=$freeze,'EXPECTED_SOURCE_TIME');
            if ($e['source_kind']==='DERIVED') {
                $computed=BaganrikiExpectedPacket::instant($e['computed_at']);
                self::need($computed>=$available,'EXPECTED_COMPUTED_BEFORE_DATA');
            } else {
                $owners=$e['horse_nos'];$members=$e['applicable_horse_nos'];sort($owners,SORT_NUMERIC);sort($members,SORT_NUMERIC);
                $observation=serialize([$e['scope'],$owners,$members,$e['field'],$e['unit'],$e['slot_offset'],$observed->format('U.u'),
                    BaganrikiReturnStats::reserved($e['field'])?$e['dependency_key']:null]);
                self::need(!isset($observations[$observation]),'EXPECTED_DUPLICATE_OBSERVATION');
                $observations[$observation]=true;
            }
            // @ANCHOR-20261114-EXPECTED-TABLE-PARENT
            //   帯別の統計（回収率・似たレース）は、設計どおり parents に集計元のテーブル名が入っています
            //   （buildExtraSourceRegistry の「由来の残し方」）。テーブル名は登録表の中には無いので、
            //   【旧】ここで EXPECTED_SOURCE_PARENT になり、11/14 福島1Rの凍結が止まりました。
            //   統計（stat）の項目の、テーブル名の親だけを「入力の外の由来」として数えずに通します。
            //   それ以外の親は今までどおり登録表に無ければ止めます。
            // $degree[$id]=count($e['parents']);
            // if ($degree[$id]===0) $queue[]=$id;
            $degree[$id]=0;
            foreach ($e['parents'] as $p) {
                if (!isset($reg[$p]) && BaganrikiDerivedValue::tableParent($e,$p)) continue;
                $degree[$id]++;
                self::need(isset($reg[$p]),'EXPECTED_SOURCE_PARENT');
                $parent=$reg[$p];
                self::need(BaganrikiExpectedPacket::instant($parent['observed_at'])<=$observed &&
                    BaganrikiExpectedPacket::instant($parent['available_at'])<=$available,'EXPECTED_PARENT_TIME');
                if ($parent['computed_at']!==null && $e['computed_at']!==null)
                    self::need(BaganrikiExpectedPacket::instant($parent['computed_at'])<=BaganrikiExpectedPacket::instant($e['computed_at']),'EXPECTED_PARENT_TIME');
                $children[$p][]=$id;
            }
            if ($degree[$id]===0) $queue[]=$id;
        }
        for ($i=0;$i<count($queue);$i++) foreach ($children[$queue[$i]]??[] as $child) if (--$degree[$child]===0) $queue[]=$child;
        self::need(count($queue)===count($reg),'EXPECTED_SOURCE_CYCLE');
        foreach ($queue as $id) if ($reg[$id]['source_kind']==='DERIVED') BaganrikiDerivedValue::verify((string)$id,$reg,$map);
        foreach ($in['input_indices'] as $key=>$refs) {
            $address=(string)$key;
            $typed=preg_match('~^(?:horse|pair|cohort|race)/~D',$address)===1;
            $seen=[];
            foreach ($refs as $id) {
                self::need(isset($reg[$id]),'EXPECTED_UNKNOWN_INDEX_REF');
                self::need(!isset($seen[$id]),'EXPECTED_DUPLICATE_INDEX_REF');$seen[$id]=true;
                if ($typed) self::need(BaganrikiPrompt::indexKey($reg[$id])===$address,'EXPECTED_INDEX_BINDING');
            }
        }
        foreach ($in['coverage'] as $entry) {
            foreach ($entry['source_refs']??[] as $id) self::need(isset($reg[$id]),'EXPECTED_UNKNOWN_COVERAGE_REF');
            if ($entry['status']==='UNAVAILABLE') self::need(BaganrikiText::visible($entry['reason']),'EXPECTED_COVERAGE_REASON');
        }
        return self::readyAt($in);
    }

    private static function source(array $reg,string $ref,int $no):array
    {
        $s=$reg[$ref]??null;self::need($s!==null,'EXPECTED_UNKNOWN_REPLY_REF');
        $ok=$s['scope']==='RACE' || in_array($no,$s['scope']==='COHORT'?$s['applicable_horse_nos']:$s['horse_nos'],true);
        self::need($ok,'EXPECTED_REPLY_REF_OWNER');
        return $s;
    }
    /** On local salvage keep only references that were actually present and belonged to this horse. */
    public static function retainedRefs(array $reg,int $no,mixed $refs):array
    {
        $kept=[];foreach (is_array($refs)?$refs:[] as $ref) {
            if (!is_string($ref) || isset($kept[$ref])) continue;
            try { self::source($reg,$ref,$no);$kept[$ref]=true; }
            catch (\InvalidArgumentException $invalid) { continue; }
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
                self::need(is_string($ref) && isset($registry[$ref]),'EXPECTED_EVIDENCE_FACTS');
                if ($registry[$ref]['value']!==null) $roots+=self::signalKeys($registry,$ref,$cache);
            }
            self::need($roots!==[],'EXPECTED_EVIDENCE_FACTS');
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
        if (!is_string($family) || !is_array($refs) || !$refs || !BaganrikiExpectedPacket::isList($refs)) return false;
        $seen=[];
        foreach ($refs as $id) {
            if (!is_string($id) || isset($seen[$id])) return false;
            $seen[$id]=true;
            try { self::source($in['source_registry'],$id,$no); } catch (\InvalidArgumentException $invalid) { return false; }
        }
        $keys=['ability'=>['H1'],'market'=>['H2'],'gap'=>['H3'],'win_place'=>['H5'],'similar_value'=>['H4','V1']];
        foreach ($keys[$family]??[] as $key) if (BaganrikiScoreMaterials::supports($in,$no,$key,$refs,$cache,true,$requireSignal)) return true;
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

    public static function evidenceItem(BaganrikiFrozenInput $frozen,array $reg,array $item,int $no,array $map,array $path,array &$cache):array
    {
        return self::checkedEvidence(BaganrikiFrozenInput::checked($frozen),$reg,[$frozen,'sourceValue'],$item,$no,$map,$path,$cache);
    }

    /** Shared by receipt comparison and frozen-input verification; values come from the respective exact JSON type map. */
    public static function checkedEvidence(array $in,array $reg,callable $sourceValue,array $item,int $no,array $map,array $path,array &$cache):array
    {
        self::need(BaganrikiText::visible($item['interpretation']),'EXPECTED_EVIDENCE_INTERPRETATION');
        $facts=[];$signal=false;$keys=[];
        foreach ($item['facts'] as $f=>$fact) {
            $ref=$fact['source_ref'];$src=self::source($reg,$ref,$no);$facts[]=$ref;
            $got=BaganrikiOutputSchema::scalar($fact['value'],$map,[...$path,'facts',$f,'value']);
            self::need($got===$sourceValue($ref),'EXPECTED_FACT_VALUE');
            // A missing observation has no value to share between two otherwise independent families.
            if ($src['value']===null) continue;
            $factRoots=self::signalKeys($reg,$ref,$cache);$keys+=$factRoots;
            if ($factRoots && $src['value']!==null && self::signalKeys($reg,$ref,$cache,$no)) $signal=true;
        }
        self::need(self::sameSet($item['source_refs'],$facts) && $signal,'EXPECTED_EVIDENCE_FACTS');
        self::need(self::familyKnown($in,$no,$item['family'],$facts,$cache),'EXPECTED_EVIDENCE_FAMILY_'.$item['family']);
        BaganrikiMarketClaim::verify($reg,$sourceValue,$item,$no);
        BaganrikiGapClaim::verify($reg,$sourceValue,$item,$no);   // @ANCHOR-20261110-PREDICTION-GAP-CLAIM
        return [$facts,$keys];
    }

    public static function counterItem(array $reg,array $item,int $no):void
    {
        $known=false;
        foreach ($item['source_refs'] as $id) if (self::source($reg,$id,$no)['value']!==null) $known=true;
        self::need($known && BaganrikiText::visible($item['reason']),'EXPECTED_COUNTEREVIDENCE');
    }

    /** Applied identically to initial, corrected and restored responses. Input must be the verified frozen tree. */
    public static function scoreKnown(array $in,int $no,string $key,mixed $points,mixed $proof,array &$cache):bool
    {
        $cap=BaganrikiCandidateSelection::scoreCaps()[$key]??null;
        if ($cap===null || !is_int($points) || $points<0 || $points>$cap || !is_array($proof) ||
            !is_array($proof['source_refs']??null) || !BaganrikiExpectedPacket::isList($proof['source_refs']) ||
            !is_string($proof['reason']??null) || !BaganrikiText::visible($proof['reason'])) return false;
        $seen=[];$refs=$proof['source_refs'];
        foreach ($refs as $id) {
            if (!is_string($id) || isset($seen[$id])) return false;
            $seen[$id]=true;self::source($in['source_registry'],$id,$no);
        }
        return $key==='V3' ? BaganrikiReturnStats::supports($in,$no,$refs,$points) : BaganrikiScoreMaterials::supports($in,$no,$key,$refs,$cache);
    }

    /** Raw values on each side retain the decoder's numeric-vs-string distinction. */
    public static function reply(BaganrikiFrozenInput $frozen,array $reply,array $map):array
    {
        $cache=[];
        return self::checkRows(BaganrikiFrozenInput::checked($frozen),[$frozen,'sourceValue'],$reply,$map,$cache);
    }

    /** The original public entry point still throws the first defect on an otherwise well-shaped row. */
    public static function inspectRow(array $in,array $horse,int $index,array $inputMap,array $replyMap,array &$cache):void
    {
        $issues=self::inspectIssues($in,$horse,$index,$inputMap,$replyMap,$cache,[]);
        if ($issues) throw new \InvalidArgumentException($issues[0]);
    }

    /** Collect independent findings on a registered horse. The final verifier uses the same checks in fail-fast mode. */
    public static function inspectIssues(array $in,array $horse,int $index,array $inputMap,array $replyMap,array &$cache,array $malformed):array
    {
        $sourceValue=static fn(string $ref):array=>BaganrikiOutputSchema::scalar($in['source_registry'][$ref]['value'],$inputMap,['source_registry',$ref,'value']);
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
            catch (\InvalidArgumentException $ex) { if ($report===null) throw $ex;$report($ex->getMessage());return false; }
        };
        // A malformed field does not hide an independent fact, score or ranking field on the same horse.
        $evidenceKnown=!isset($malformed['evidence']);
        if ($evidenceKnown) foreach ($horse['evidence'] as $j=>$item) {
            $id=$item['evidence_id'];$family=$item['family'];
            $check(static fn()=>self::need(!isset($ids[$id]),'EXPECTED_EVIDENCE_ID'));$ids[$id]=true;
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
                self::need(BaganrikiText::visible($review['reason']),'EXPECTED_FAMILY_REASON');
                if (!isset($malformed['evidence'])) {
                    $expected=$families[$name]??[];
                    self::need(self::sameSet($expected,$review['evidence_ids']) && ($review['status']==='SUPPORTED')===(bool)$expected,'EXPECTED_FAMILY_IDS');
                    foreach (array_keys($familyRefs[$name]??[]) as $ref) self::need(in_array((string)$ref,$refs,true),'EXPECTED_FAMILY_REFS');
                }
                self::need($review['status']!=='NO_SUPPORT' || $nonempty,'EXPECTED_FAMILY_NO_SUPPORT');
                if ($review['status']==='NO_SUPPORT') self::need(self::familyKnown($in,$no,$name,$refs,$cache,false),'EXPECTED_NO_SUPPORT_MATERIAL_'.$name);
                // Original/repair answers assert that they reviewed the whole input. A server recovery
                // row can instead be an UNVERIFIED placeholder for a genuinely absent answer row;
                // BaganrikiRecovery separately proves that no valid original evidence was erased.
                if ($review['status']==='DATA_MISSING' && $rankRequired) {
                    if ($available===null) $available=self::availableRefs($in,$no);
                    self::need(!self::familyKnown($in,$no,$name,$available,$cache,false),'EXPECTED_FALSE_MISSING_'.$name);
                }
            });
        }
        if (!isset($malformed['evidence']) && !isset($malformed['evidence_rank_evidence_ids']))
            foreach ($horse['evidence_rank_evidence_ids'] as $id) $check(static fn()=>self::need(isset($ids[$id]),'EXPECTED_RANK_EVIDENCE_ID'));
        if (!isset($malformed['counterevidence']))
            foreach ($horse['counterevidence'] as $counter) $check(static fn()=>self::counterItem($reg,$counter,$no));
        if (!isset($malformed['scores']) && !isset($malformed['score_evidence'])) foreach ($horse['score_evidence'] as $key=>$proof) {
            $check(static function()use($horse,$key,$proof,$reg,$in,$no,&$cache):void {
                if ($horse['scores'][$key]!==null) self::need(self::scoreKnown($in,$no,$key,$horse['scores'][$key],$proof,$cache),'EXPECTED_SCORE_MATERIAL_'.$key);
                else {
                    self::need(BaganrikiText::visible($proof['reason']),'EXPECTED_SCORE_MISSING_REASON');
                    foreach ($proof['source_refs'] as $ref) self::source($reg,$ref,$no);
                }
            });
        }
        if (!isset($malformed['evidence']) && !isset($malformed['eligibility']))
            $check(static fn()=>self::need(($horse['eligibility']==='ELIGIBLE')===(bool)$ids,'EXPECTED_ELIGIBILITY_EVIDENCE'));
        $count=self::independentCount($groups);
        if ($ids && $rankRequired && !isset($malformed['evidence_rank_evidence_ids']) && !isset($malformed['evidence_rank_reason']))
            $check(static fn()=>self::need($horse['evidence_rank_evidence_ids']!==[] && BaganrikiText::visible($horse['evidence_rank_reason']),'EXPECTED_ORIGINAL_RANK_REASON'));
        if ($ids && $evidenceKnown && !isset($malformed['tier']))
            $check(static fn()=>self::need($horse['tier']===($count>=2?'A':'B'),'EXPECTED_TIER_FAMILIES'));
        if (!array_intersect(array_keys($malformed),['scores','score_status','total_score','observed_sum','observed_cap','eligibility','rank_basis','evidence_rank_evidence_ids']))
            $check(static fn()=>BaganrikiCandidateSelection::validateHorseSummary($horse));
        if (!$ids && $evidenceKnown && !isset($malformed['eligibility']) && !isset($malformed['family_review'])) {
            $missing=in_array('DATA_MISSING',array_column($horse['family_review'],'status'),true);
            $check(static fn()=>self::need($horse['eligibility']===($missing?'UNVERIFIED':'NO_EVIDENCE'),'EXPECTED_MISSING_VS_NO_EVIDENCE'));
        }
        return $count;
    }
}

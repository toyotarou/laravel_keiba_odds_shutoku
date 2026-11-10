<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiPrompt as R12Codec;
use App\Services\Baganriki\AttemptWindowW2 as R12Window;
use App\Services\Baganriki\CandidateSelectionW2 as R12Sel;
use App\Services\Baganriki\DispatchW2 as R12Dispatch;
use App\Services\Baganriki\FrozenInputW2 as R12Frozen;
use App\Services\Baganriki\SourceEvidenceW2 as R12Evidence;
use App\Services\Baganriki\ReturnStatsW2 as R12Stats;

(function(string $dir) {
    $count=0;$assert=static function($test,string $name)use(&$count){expect($test,'R12 '.$name);++$count;};
    $deny=static function(callable $op,string $prefix)use($assert){try{$op();}catch(InvalidArgumentException $ex){$assert(str_starts_with($ex->getMessage(),$prefix),$prefix.' / '.$ex->getMessage());return;}throw new RuntimeException('R12 accepted '.$prefix);};
    $ev=static fn(array $refs)=>['reason'=>'採点資料を照合','source_refs'=>$refs];
    $material=static function(array $in,int $n,string $key,int $pts,array $refs)use($ev){
        $cache=[];try { return R12Evidence::scoreKnown($in,$n,$key,$pts,$ev($refs),$cache); } catch (InvalidArgumentException $ex) { return false; }
    };
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true);
    $set=static function(array $reply,string $key,?int $points,array $refs)use($ev){
        $h=&$reply['horses'][0];$h['scores'][$key]=$points;$h['score_evidence'][$key]=$ev($refs);
        $known=array_filter($h['scores'],static fn($v)=>$v!==null);
        $h['observed_sum']=array_sum($known);$h['observed_cap']=array_sum(array_intersect_key(R12Sel::scoreCaps(),$known));
        $h['score_status']=count($known)===10?'complete':'partial';$h['total_score']=count($known)===10?$h['observed_sum']:null;return $reply;
    };
    foreach (['1st','2nd'] as $role) {
        $base=json_decode(file_get_contents("$dir/{$role}_input.json"),true);
        $out=json_decode(file_get_contents("$dir/{$role}_output.json"),true);$nos=array_column($base['runners'],'horse_no');
        $snap=r3stored($base,$proof);$odds=['h1_win_odds_6'];$invalid=$set($out,'V3',8,$odds);
        $orig=static function($f,$o){$raw=r3frame($o);return R12Sel::verifyOriginal($f,$raw,hash('sha256',$raw));};
        $deny(fn()=>$orig($snap,$invalid),'W12_SCORE_MATERIAL_V3');
        $deny(fn()=>R12Sel::verifyReply($snap,$invalid),'W12_SCORE_MATERIAL_V3');
        $all=$out;foreach (R12Sel::scoreCaps() as $key=>$cap) {
            $all=$set($all,$key,$cap,$odds);$assert(!$material($base,1,$key,$cap,$odds),'one odds cannot supply '.$key);
        }
        $assert($all['horses'][0]['total_score']===100,'old 100-point counterexample');
        $deny(fn()=>$orig($snap,$all),'W12_SCORE_MATERIAL_');
        $enriched=r12ScoreInput($base);$s=r3stored($enriched,$proof);$in=R12Frozen::checked($s);$x=$out;$x['snapshot_hash']=$in['snapshot_hash'];
        $refs=r12Refs(1);foreach (R12Sel::scoreCaps() as $key=>$cap) {
            $assert($material($in,1,$key,$cap,$refs[$key]),'genuine positive '.$key);
            $pos=$set($x,$key,$cap,$refs[$key]);$assert($orig($s,$pos)['public_candidates']===$x['public_candidates'],'full exact-byte positive '.$key);
        }
        foreach (['H2'=>['h1_win_odds_6','h1_place_min_21'],'H3'=>['r12_gap_2'],'H4'=>['h1_similar_top3_rate'],
            'H5'=>['h1_win_odds_6','h1_place_min_21'],'V2'=>['h1_past_performance_None'],'V4'=>['h1_estimated_final_odds'],
            'V5'=>['h1_place_min_6','h1_place_max_21']] as $key=>$wrong)
            $assert(!$material($in,1,$key,0,$wrong),'incomplete/mismatched '.$key);
        $assert(!$material($in,2,'H1',1,$refs['H1']),'other horse');
        $exact=$in;$tiny='100.00000000000000000000001';$exact['source_registry']['h1_similar_top3_rate']['value']=new \App\Http\Controllers\Api\BaganrikiDecimal($tiny,$tiny);
        $assert(!$material($exact,1,'H4',0,$refs['H4']),'exact rate above 100 is invalid');
        $badRow=$x;$badRow['horses'][0]['horse_no']='1';$deny(fn()=>R12Sel::verifyReply($s,$badRow),'W12_SCORE_ROW');
        $reverse=$in;$reverse['source_registry']['r12_gap_1']['horse_nos']=[2,1];
        $assert(!$material($reverse,1,'H3',1,$refs['H3']),'fixed pair order is authoritative');
        [$rate,$n]=$refs['V3'];$assert(!$material($in,1,'V3',0,[$rate]),'rate only even at 0 score');
        $assert(!$material($in,1,'V3',0,[$n]),'count only');
        $assert(!$material($in,1,'V3',1,[$rate,$n,$rate,$n]),'duplicate pair');
        $assert(!$material($in,1,'V3',1,[$rate,$n,...$odds]),'extra unrelated evidence');
        $cacheR12=[];$assert(R12Evidence::signalKeys($in['source_registry'],$rate,$cacheR12,1)===[],'return data does not add a signal');
        $without=$in;$without['coverage']['recovery_statistics']=['status'=>'UNAVAILABLE','reason'=>'missing'];$assert(!$material($without,1,'V3',0,$refs['V3']),'missing coverage');
        $without=$in;$without['coverage']['recovery_statistics']['source_refs']=[$rate];$assert(!$material($without,1,'V3',0,$refs['V3']),'count not covered');
        $record=r12Record($base);$winRecord=r12Record($base,'WIN');$winPair='v3_'.hash('sha256',$winRecord['source_id']).'_';
        $both=R12Stats::attach($base,[$record,$winRecord])['common'];$bs=r3stored($both,$proof);
        $assert($material(R12Frozen::checked($bs),1,'V3',1,[$winPair.'rate',$winPair.'count',...$refs['V3']]),'two intact ticket groups');
        $assert(!$material($both,1,'V3',1,[$winPair.'rate',$n]),'cross-ticket count');
        $other=array_replace($record,['source_id'=>'other-v1','period_start'=>'2025-03-01']);$t=R12Stats::attach($base,[$other,$record]);$ss=r3stored($t['common'],$proof);
        $newCount='v3_'.hash('sha256',$other['source_id']).'_count';
        $assert(!$material(R12Frozen::checked($ss),1,'V3',1,[$rate,$newCount]),'unrelated period/group with the same observation time');
        $t=R12Stats::attach($base,[array_replace($record,['horse_nos'=>[2]])]);$assert(!$material($t['common'],1,'V3',1,$refs['V3']) && $material($t['common'],2,'V3',1,$refs['V3']),'cohort applicability');
        foreach ([['0',0,true],['0',1,false],['0.0000001',1,true],['84.25',1,true]] as [$pct,$pts,$want]) {
            $t=R12Stats::attach($base,[array_replace($record,['return_pct'=>$pct])]);$ss=r3stored($t['common'],$proof);
            $assert($material(R12Frozen::checked($ss),1,'V3',$pts,$refs['V3'])===$want,'return zero/decimal '.$pct.'/'.$pts);
        }
        foreach ([['sample_count',0],['sample_count',-1],['sample_count','1.25'],['return_pct',null],['return_pct',-1],['ticket','unknown'],
            ['odds_slot','unknown'],['period_end','2026-10-17'],['period_start','2026-02-30'],['horse_nos',[99]],['source_id',''],
            ['available_at',r4time($record['available_at'],'+1 microsecond')],['available_at','2020-01-01T00:00:00Z']] as [$field,$bad]) {
            $t=R12Stats::attach($base,[array_replace($record,[$field=>$bad])]);$assert($t['common']===$base && $t['audit']['included']===0 && count($t['audit']['skipped'])===1,'unusable source isolated '.$field);
        }
        $t=R12Stats::attach($base,[$record,$record]);$assert($t['common']===$base && $t['audit']['skipped'][0]['reason']==='AMBIGUOUS_SOURCE_ID','duplicate immutable source ID');
        $t=R12Stats::attach($base,[$record,array_replace($record,['source_id'=>'invalid','sample_count'=>0])]);$assert($t['audit']['included']===1 && $material($t['common'],1,'V3',1,$refs['V3']),'good row survives adjacent bad data');
        $common=$base;foreach (['snapshot_hash','role_quota','role_cap','ai_role','expected_v01'] as $key) unset($common[$key]);
        $current=r12Current($base);$bridge=\App\Services\Baganriki\ExpectedBridgeW2::attach($common,$current,null,null,[r12Record($base)]);
        $bi=$bridge['common'];$token=hash('sha256',$record['source_id']);
        $assert($bridge['audit']['return_statistics']['sources'][$token]['source_id']===$record['source_id'] && !str_contains(json_encode($bi),$record['source_id']) &&
            $material($bi,1,'V3',1,$refs['V3']),'normal DB bridge and private provenance');
        $denied=$bi;unset($denied['expected_v01']);$deny(fn()=>\App\Services\Baganriki\ExpectedBridgeW2::attach($denied,$current,null),'W12_UNSEALED_RETURN_SOURCE');
        $original=$set($set($out,'H1',4,$refs['H1']),'V3',8,$odds);$initial=r4receipt($snap,r3frame($original),0,false);
        $clean=$set($original,'V3',null,[]);$fixed=r4receipt($snap,r3frame($clean),1,true);$fixed['repair_scope']=r5scope($initial,[],[],[1]);
        $record=['kind'=>'ORIGINAL','input'=>$snap,'receipts'=>[$initial,$fixed]];
        $merge=static fn($r)=>r9merge($snap,[$role=>$r],$base['quota'],$nos,[],r4live($snap));
        $expected=$merge(r4original($snap,r3frame($out)))['public_candidates'];$result=$merge($record);$assert($result['public_candidates']===$expected && $result['attempt_issues']===[],'valid H1 survives correction and original V3 cannot qualify');
        $bad=$fixed;$dropped=$set($clean,'H1',null,[]);$bad['raw']=r3frame($dropped);$bad['response_hash']=hash('sha256',$bad['raw']);$r=$record;$r['receipts'][1]=$bad;
        $deny(fn()=>$merge($r),'W2_NO_VERIFIED_ROLE');
        $rewritten=$set($clean,'H1',3,$refs['H1']);$changed=r4receipt($snap,r3frame($rewritten),1,true);$changed['repair_scope']=r5scope($initial,[],[],[1]);
        $r=$record;$r['receipts'][1]=$changed;$deny(fn()=>$merge($r),'W2_NO_VERIFIED_ROLE');
        $doc=r4recoveryDoc($snap,$clean,$initial);$assert($merge(r4seal($doc,[$initial],$snap))['public_candidates']===$expected,'saved recovery removes only V3');
        $dirty=r4recoveryDoc($snap,$original,$initial);$deny(fn()=>$merge(r4seal($dirty,[$initial],$snap)),'W2_NO_VERIFIED_ROLE');
        $grant=R12Window::scope(r5scope($initial,[],[],[1]),$initial['response_hash'],$base);
        $wire=json_decode(R12Dispatch::correctionBody($snap,$snap->raw(),$initial['raw'],$grant,r9diagnostics()),true);
        $names=array_keys($wire['fix_request']['repair_scope']);sort($names);
        $assert($names===['horses','presentation_horses','rank_bands','summary_horses'] && $wire['fix_request']['repair_scope']['summary_horses']===[1],'actual four correction grants');
        $text=R12Dispatch::system($role,'SOURCE_TABLE_V1');
        $assert(str_contains($text,'basisが異なればBASIS_MISMATCH') && !str_contains($text,'score_basis') && !str_contains($text,'public_candidates=true') && !str_contains($text,'top_fields') && str_contains($text,'先頭9バイト'),'actual sent system contract');
        $utf=$both;$old='h1_past_performance_None';$id='h1_能力_6';$e=$utf['source_registry'][$old];$e['source_ref']=$id;$e['dependency_key']='h1_能力';$utf['source_registry'][$id]=$e;unset($utf['source_registry'][$old]);
        $utf['input_indices']=R12Codec::indicesFor($utf['source_registry']);
        foreach ($utf['coverage'] as &$c) if (isset($c['source_refs'])) {
            $c['source_refs']=array_map(static fn($v)=>$v===$old?$id:$v,$c['source_refs']);sort($c['source_refs'],SORT_STRING);
        } unset($c);
        $ss=r3stored($utf,$proof);$body=R12Dispatch::inputBody($ss,'SOURCE_TABLE_V1');$b=json_decode($body,true);$table=$b['source_table'];
        $cols=array_flip($table['columns']);$row=array_values(array_filter($table['rows'],static fn($r)=>$r[$cols['source_ref']]===$id));
        $e=$b['expected_v01'];unset($b['expected_v01']);$expanded=R12Codec::expandWire($b);
        $assert($row[0][$cols['dependency_key']]===9 && $expanded['input']['source_registry'][$id]['dependency_key']==='h1_能力' && $e['rows'][0]['basis']!==null,'byte length and real E basis');
        $assert($material($expanded['input'],1,'V3',1,$refs['V3']),'return-stat references survive real compact/expand');
    }
    echo "PASS $count R12 score-material, return-source, scope, Unicode, original/correction/recovery checks across both roles.\n";
})($argv[2]);

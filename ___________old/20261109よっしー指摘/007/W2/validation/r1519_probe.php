<?php
declare(strict_types=1);
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\ExpectedPacketW2 as Packet;
use App\Services\Baganriki\MarketStateW2 as Market;
use App\Services\Baganriki\MarketClaimW2 as Claim;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Http\Controllers\Api\BaganrikiPrompt as Wire;
[$script,$bootstrap,$fixtures,$destination]=$argv;
require $bootstrap;
require __DIR__.'/../php/ExpectedBridgeW2.php';
require __DIR__.'/r1519_fixture.php';
if (!is_dir($destination)) mkdir($destination,0777,true);
function bytes(array $x):string {return json_encode($x,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
function frame(array $x):string {return "<<<BAGANRIKI_V041_R8_BEGIN>>>\n".bytes($x)."\n<<<BAGANRIKI_V041_R8_END>>>";}
function freeze(array $in,array $proof):Frozen {
    $witness=json_decode($proof['json'],true,512,JSON_THROW_ON_ERROR);
    $witness['market_source_sha256']=Market::verify($in);$proof=Packet::sealProof($witness);
    $common=Wire::commonHash($in);$in['snapshot_hash']=$common['hash'];
    return Frozen::fromStored($common['json'],bytes($in),$in['snapshot_hash'],$in['ai_role'],$proof);
}
function project(Frozen $f,array $out):array {
    $in=Frozen::checked($f);$out['snapshot_hash']=$in['snapshot_hash'];
    $p=Selection::apply($f,$out['horses'],$out['band_ranks']);
    foreach (['selection_ranks','expected_reviews','public_candidates'] as $key) $out[$key]=$p[$key];
    foreach ($out['horses'] as &$h) {
        $flag=in_array($h['horse_no'],$p['public_candidates'],true);$h['public_candidate']=$flag;
        $h['nonselect_reason']=$flag?null:['code'=>$h['eligibility']==='ELIGIBLE'?'BAND_QUOTA':'UNVERIFIED','reason'=>'選出枠または評価資料の条件'];
    }unset($h);
    $out['shortfalls']=[];
    foreach ($in['role_quota'] as $band=>$need) {
        $n=count($out['band_ranks'][$band]);if ($n<$need) $out['shortfalls'][]=['band'=>$band,'requested'=>$need,'eligible'=>$n,'missing'=>$need-$n,'reason'=>'確認できた根拠に限定'];
    }
    return $out;
}
$results=[];
function check(Frozen $f,array $out,string $label,bool $accept,?string $code=null):void {
    global $results,$destination;
    $raw=frame($out);$row=['role'=>$out['ai_role'],'case'=>$label,'expected_accept'=>$accept];
    try {$valid=Selection::verifyOriginal($f,$raw,hash('sha256',$raw));$row+=['accepted'=>true,'candidates'=>$valid['public_candidates']];}
    catch (InvalidArgumentException $e) {$row+=['accepted'=>false,'error'=>$e->getMessage()];}
    if ($row['accepted']!==$accept || ($code!==null && !str_contains($row['error']??'',$code)))
        throw new RuntimeException('Unexpected result '.bytes($row));
    $results[]=$row;file_put_contents($destination.'/'.$out['ai_role'].'_'.$label.'.txt',$raw);
}
$proof=json_decode(file_get_contents($fixtures.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);
foreach (['1st','2nd'] as $role) {
    $in=json_decode(file_get_contents($fixtures.'/'.$role.'_input.json'),true,512,JSON_THROW_ON_ERROR);
    $f=freeze($in,$proof);$base=r1519Reviews($in,json_decode(file_get_contents($fixtures.'/'.$role.'_output.json'),true,512,JSON_THROW_ON_ERROR));
    check($f,$base,'baseline',true);
    $bad=$base;
    foreach ($bad['horses'] as &$h) if ($h['horse_no']===5) {
        $h['evidence']=$h['evidence_rank_evidence_ids']=[];$h['tier']=$h['eligibility']='UNVERIFIED';$h['rank_basis']='NOT_RANKED';
        foreach ($h['family_review'] as &$r) $r=['status'=>'DATA_MISSING','source_refs'=>[],'evidence_ids'=>[],'reason'=>'資料不足と誤申告'];unset($r);
    }unset($h);
    foreach (['band_ranks','evidence_band_ranks'] as $k) $bad[$k]['D']=array_values(array_diff($bad[$k]['D'],[5]));
    $bad=project($f,$bad);check($f,$bad,'false_missing',false,'W19_FALSE_MISSING_ability');
    $none=$in;$none['source_registry']['h4_past_performance_None']['value']=null;$nf=freeze($none,$proof);$ok=$base;
    foreach ($ok['horses'] as &$h) if ($h['horse_no']===4) $h['family_review']['ability']=['status'=>'DATA_MISSING','source_refs'=>[],'evidence_ids'=>[],'reason'=>'実際に能力資料なし'];unset($h);
    check($nf,project($nf,$ok),'actual_missing_other_horse',true);
    // Structured acceleration must cite the same horse, metric and all three original slots.
    $x=$base;
    foreach ($x['horses'] as &$h) if ($h['horse_no']===4) {
        $refs=['h4_win_odds_12','h4_win_odds_9','h4_win_odds_6'];$facts=[];
        foreach ($refs as $ref) $facts[]=['source_ref'=>$ref,'value'=>$in['source_registry'][$ref]['value']];
        $h['tier']='B';$h['eligibility']='ELIGIBLE';$h['rank_basis']='EVIDENCE';
        $h['evidence']=[['evidence_id'=>'acceleration','family'=>'market','dependency_group'=>'h4_market','source_refs'=>$refs,'facts'=>$facts,
            'observation'=>'支持加速（12→9→6分）','interpretation'=>'支持の変化を好走根拠として比較','market_claim'=>['kind'=>'ACCELERATION','metric'=>'win_odds']]];
        $h['family_review']['market']=['status'=>'SUPPORTED','source_refs'=>$refs,'evidence_ids'=>['acceleration'],'reason'=>'時系列の引用'];
        $h['evidence_rank_evidence_ids']=['acceleration'];$h['evidence_rank_reason']=$h['rank_reason']='時系列材料を比較';
    }unset($h);
    array_unshift($x['band_ranks']['D'],4);$x['evidence_band_ranks']['D']=$x['band_ranks']['D'];$x=project($f,$x);
    check($f,$x,'false_acceleration',false,'W19_FALSE_ACCELERATION');
    $positive=$in;$positive['source_registry']['h4_win_odds_12']['value']=30;$positive['source_registry']['h4_win_odds_9']['value']=28;
    $pf=freeze($positive,$proof);$y=$x;
    foreach ($y['horses'] as &$h) if ($h['horse_no']===4) foreach ($h['evidence'][0]['facts'] as &$fact) $fact['value']=$positive['source_registry'][$fact['source_ref']]['value'];unset($fact,$h);
    check($pf,project($pf,$y),'true_acceleration',true);
    $y=$x;foreach ($y['horses'] as &$h) if ($h['horse_no']===4) {$h['evidence'][0]['market_claim']=['kind'=>'OTHER','metric'=>null];$h['evidence'][0]['observation']='市場比較（支持加速を根拠にしない）';}unset($h);
    check($f,$y,'other_market_not_forced_to_accelerate',true);
    // A false market item must not erase the same horse's independently valid ability evidence.
    foreach ([false,true] as $first) foreach ([false,true] as $precise) {
        $mixed=$base;$source=$in;
        foreach ($mixed['horses'] as $index=>&$h) if ($h['horse_no']===5) {
            if (!$h['evidence']) {
                $supported=r1519Reviews($in,json_decode(file_get_contents($fixtures.'/1st_output.json'),true,512,JSON_THROW_ON_ERROR));
                foreach ($supported['horses'] as $row) if ($row['horse_no']===5) {$h=$row;break;}
            }
            $refs=['h5_win_odds_12','h5_win_odds_9','h5_win_odds_6'];$facts=[];
            foreach ($refs as $ref) $facts[]=['source_ref'=>$ref,'value'=>$in['source_registry'][$ref]['value']];
            $item=['evidence_id'=>'false_market','family'=>'market','dependency_group'=>'h5_market','source_refs'=>$refs,'facts'=>$facts,
                'observation'=>'支持加速（12→9→6分）','interpretation'=>'比較資料','market_claim'=>['kind'=>'ACCELERATION','metric'=>'win_odds']];
            if ($precise) {$source['source_registry']['h5_past_performance_None']['value']=-99999;$h['evidence'][0]['facts'][0]['value']=-99999;}
            if ($first) array_unshift($h['evidence'],$item);else $h['evidence'][]=$item;
            $h['tier']='A';$h['evidence_rank_evidence_ids']=array_column($h['evidence'],'evidence_id');
            $h['family_review']['market']=['status'=>'SUPPORTED','source_refs'=>$refs,'evidence_ids'=>['false_market'],'reason'=>'比較資料'];
            $horseIndex=$index;
        }unset($h);
        $token='2.000000000000000000001';
        $sourceBytes=str_replace('-99999',$token,bytes($source));$replyBytes=str_replace('-99999',$token,bytes($mixed));
        $sourceTree=Frozen::decodeForInspection($sourceBytes,$sourceMap);
        $parsed=\App\Http\Controllers\Api\BaganrikiJson::decode($replyBytes);
        $sal=\App\Services\Baganriki\ResponseInspectionW2::salvageMarket($sourceTree,$parsed['value']['horses'][$horseIndex],$horseIndex,$sourceMap,$parsed['map']);
        if (!$sal || count($sal['row']['evidence'])!==1 || $sal['row']['tier']!=='B' || $sal['removed_evidence_ids']!==['false_market'])
            throw new RuntimeException('mixed same-horse evidence lost '.bytes(['role'=>$role,'first'=>$first,'precise'=>$precise,'H2'=>$mixed['horses'][$horseIndex]['scores']['H2']]));
        if ($precise && \App\Services\Baganriki\SchemaW2::scalar($sal['row']['evidence'][0]['facts'][0]['value'],$sal['map'],['evidence',0,'facts',0,'value'])!==['number',$token])
            throw new RuntimeException('surviving fact token changed after filtering');
        $results[]=['role'=>$role,'case'=>'mixed_item_'.($first?'first':'last').'_'.($precise?'precise':'normal'),'passed'=>true];
    }
    $blank=['fullwidth'=>"\u{3000}",'zero_width'=>"\u{200B}",'bom'=>"\u{FEFF}",'nbsp'=>"\u{00A0}",'control'=>"\u{0009}\u{000A}",'filler'=>"\u{3164}"];
    foreach ($blank as $kind=>$text) foreach (['evidence_rank_reason','rank_reason','explanation','interpretation','family_reason','score_reason','observation'] as $field) {
        $bad=$base;foreach ($bad['horses'] as &$h) if ($h['horse_no']===5) {
            if ($field==='interpretation'||$field==='observation') $h['evidence'][0][$field]=$text;
            elseif ($field==='family_reason') $h['family_review']['ability']['reason']=$text;
            elseif ($field==='score_reason') $h['score_evidence']['H1']['reason']=$text;
            else $h[$field]=$text;
        }unset($h);check($f,$bad,$field.'_'.$kind,false,'W3_SCHEMA_output');
    }
    $pin=$in;$pin['prompt_text_revision']='COMPACT_TEXT_20261109_03';$pinned=freeze($pin,$proof);
    foreach (['FULL','SOURCE_TABLE_V1'] as $format) {
        $system=Dispatch::systemFor(Frozen::checked($pinned),$format);$body=Dispatch::inputBody($pinned,$format);
        $r=Dispatch::record($pinned,$format,0,$system,$body);Dispatch::verifyReceipt($pinned,$r,0,null,null,null);
        foreach (['ORIGINAL_R15_18','COMPACT_TEXT_20261109_02'] as $old) {
            try {Dispatch::record($pinned,$format,0,Dispatch::system($role,$format,$old),$body);throw new RuntimeException('Old text escaped pin');}
            catch (InvalidArgumentException $e) {if ($e->getMessage()!=='W9_SENT_SYSTEM') throw $e;}
        }
        $results[]=['role'=>$role,'case'=>'pinned_'.$format,'passed'=>true];
    }
}
file_put_contents($destination.'/results.json',json_encode($results,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");
echo 'PASS '.count($results)." cases; original/fixed responses written for independent integration checks.\n";

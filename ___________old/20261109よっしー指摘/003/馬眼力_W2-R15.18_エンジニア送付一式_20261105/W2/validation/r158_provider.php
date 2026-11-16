<?php
declare(strict_types=1);
use App\Http\Controllers\Api\AiController;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\RoleJournalW2 as Journal;

require __DIR__.'/r156_sdk.php';
function expect158(bool $valid, string $case):void {
    if (!$valid) throw new RuntimeException($case);
}
function omit158(array $selection):array {
    return array_intersect_key($selection,array_flip(['public_candidates','agreement_bonus','public_proof_hashes','actual_quota','shortfalls','fill']));
}
function reply158(string $role,string $raw,?string $stop,mixed $parts=null):array {
    if ($role==='2nd') return ['choices'=>[['message'=>['content'=>$parts??$raw],'finish_reason'=>$stop]]];
    return ['content'=>$parts??[['type'=>'text','text'=>$raw]],'stop_reason'=>$stop];
}
function record158(array $saved,array $call):array {
    $saved['receipts'][0]['raw']=$call['raw'];
    $saved['receipts'][0]['response_hash']=hash('sha256',$call['raw']);
    $saved['receipts'][0]['complete']=$call['complete'];
    return $saved;
}
function publish158(array $records,array $frozen,array $votes,array $gone=[]):array {
    $f=$frozen['1st'];$in=Frozen::checked($f);$by=array_column($in['runners'],'band','horse_no');
    $active=array_values(array_diff(array_keys($by),$gone));
    $q=\App\Http\Controllers\Api\BaganrikiRanking::afterCancellation($by,$gone,$in['quota'],$in['allocation_mode'])['live_quota'];
    $live=r4live($f,null,null,false,$active);$live['role_journal']=Journal::witness($f,$records,$votes);
    $selection=Sel::mergeStored($f,$records,$q,$active,$votes,$live);
    Window::assertPublicationCommit($f,$selection,$live['now'],$live);
    return $selection;
}
$p=preparedRows($argv[2]);$controller=new AiController(new \App\Services\AnthropicService());
$good=[];$frozen=[];$votes=[];
foreach($p['roles'] as $s){
    $r=$s['role'];$frozen[$r]=Frozen::fromStored($p['common'],$s['input'],$p['hash'],$r,$p['witness']);
    $good[$r]=['kind'=>'ORIGINAL','input'=>$frozen[$r],'receipts'=>[$s['receipt']+['raw'=>$s['original']]]];
    foreach($s['proof_horses'] as $n)$votes[$r][$n]=$s['receipt']['response_hash'];
}
$checks=$usable=$stopped=$legacy=0;$samples=[];
foreach($p['roles'] as $s){
    $role=$s['role'];$f=$frozen[$role];$in=Frozen::checked($f);$raw=$s['original'];
    $normal=$role==='1st'?'end_turn':'stop';$short=$role==='1st'?'max_tokens':'length';
    $cases=[];
    foreach(['plain'=>$raw,'one_lf'=>$raw."\n",'crlf'=>$raw."\r\n",'mixed_tail'=>$raw."\n\r\n",
             'crlf_lines'=>str_replace("\n","\r\n",$raw)] as $label=>$wire)
        $cases[$label]=[$wire,$normal,null,true,true];
    foreach(['prefix_space'=>' '.$raw,'prefix_tab'=>"\t".$raw,'prefix_lf'=>"\n".$raw,'prefix_nul'=>"\0".$raw,
             'suffix_space'=>$raw.' ','suffix_tab'=>$raw."\t",'suffix_vtab'=>$raw."\x0b",'lone_cr'=>$raw."\r",
             'suffix_nul'=>$raw."\0",'garbage_after_newline'=>$raw."\r\nX",'missing_footer'=>substr($raw,0,-9),
             'truncated_body'=>substr($raw,0,132),'two_frames'=>$raw."\n".$raw] as $label=>$wire)
        $cases[$label]=[$wire,$normal,null,true,false];
    $cases['empty']=['',$normal,null,false,false];
    $cases['whitespace']=[" \r\n\t",$normal,null,false,false];
    $cases['limit_full']=[$raw,$short,null,false,false];
    $cases['limit_partial']=[substr($raw,0,132),$short,null,false,false];
    $cases['missing_finish']=[$raw,null,null,false,false];
    if ($role==='1st'){
        $parts=[['type'=>'thinking','thinking'=>'hidden'],['type'=>'text','text'=>substr($raw,0,5)],
                ['type'=>'text','text'=>''],['type'=>'text','text'=>substr($raw,5)]];
        $cases['split_text']=[$raw,$normal,$parts,true,true];
        foreach([['type'=>'text'],['type'=>'text','text'=>[]],null,[]] as $n=>$malformed){
            $bad=$parts;array_splice($bad,2,0,[$malformed]);
            $cases['invalid_block_'.$n]=[$raw,$normal,$bad,false,false];
        }
        $cases['non_list']=[$raw,$normal,['alien'=>['type'=>'text','text'=>$raw]],false,false];
    }else{
        $cases['array_content']=['',$normal,['text'=>$raw],false,false];
    }
    $scope=Window::scope(r5scope($s['receipt'],[],['A']),$s['receipt']['response_hash'],$in);
    foreach($role==='1st'?['','claude-haiku-4-5']:[''] as $model){
        TestIO::$firstModel=$model;
        foreach(['FULL','SOURCE_TABLE_V1'] as $format)foreach([0,1] as $slot){
            $system=Dispatch::system($role,$format);$input=Dispatch::inputBody($f,$format);
            $user=$slot?Dispatch::correctionBody($f,$input,$raw,$scope,r9diagnostics()):$input;
            foreach($cases as $name=>[$bytes,$stop,$parts,$complete,$mayUse]){
                $label="$role / $model / $format / $slot / $name";
                TestIO::reset($p,$in,30000000);TestIO::$providerResponse=reply158($role,$bytes,$stop,$parts);
                TestIO::readyBinding($f,$format,$slot,$system,$user);
                $call=$controller->callPredictionAi($role,$system,$user,40,1);
                expect158($call['code']==='OK'&&$call['raw']===$bytes&&$call['complete']===$complete,$label.' exact response/finish');
                expect158(count(TestIO::$calls)===1 && TestIO::$calls[0][1]===hash('sha256',$system)
                    && TestIO::$calls[0][2]===hash('sha256',$user),$label.' additional or mismatched provider call');
                $taken=false;
                if($call['complete']){
                    try { Sel::verifyOriginal($f,$call['raw'],hash('sha256',$bytes));$taken=true; }
                    catch(InvalidArgumentException $badFrame){ if($mayUse)throw $badFrame; }
                }
                expect158($taken===$mayUse,$label.' original admissibility');
                if($mayUse)++$usable;else ++$stopped;
                ++$checks;
                if($format==='FULL' && !$slot && $model==='')$samples[$role][$name]=record158($good[$role],$call);
            }
        }
        TestIO::reset($p,$in,30000000);TestIO::$providerResponse=reply158($role," \tlegacy \r\n",$normal);
        $old=$controller->callPredictionAi($role,'legacy','{"race_id":"old"}',17);
        expect158($old['raw']==='legacy'&&$old['complete']===true,'legacy response');
        ++$legacy;
    }
}
// Export the actual decoded provider output for the SQLite queue-to-publication check.
if (($argv[3]??null)==='--export-callbacks') {
    $selected=[];
    foreach($samples as $role=>$rows)foreach($rows as $name=>$row){
        $receipt=$row['receipts'][0];
        $selected[$role][$name]=['raw'=>$receipt['raw'],'complete'=>$receipt['complete'],'hash'=>$receipt['response_hash']];
    }
    if(file_put_contents($argv[4],dbJson($selected))===false)throw new RuntimeException('callback export');
}
$publication=0;$single=$both=$none=0;
foreach([[],[1],[2,10,12]] as $gone){
    $ref=[];foreach(['1st','2nd'] as $r)$ref[$r]=omit158(publish158([$r=>$good[$r]],$frozen,[$r=>$votes[$r]],$gone));
    foreach(['plain','crlf','prefix_nul','suffix_vtab','missing_footer','limit_full','missing_finish'] as $first)
    foreach(['plain','one_lf','suffix_space','prefix_tab','truncated_body','limit_partial','array_content'] as $second){
        $records=['1st'=>$samples['1st'][$first],'2nd'=>$samples['2nd'][$second]];$v=$votes;
        foreach($v as $r=>&$proof) foreach($proof as &$hash) $hash=$records[$r]['receipts'][0]['response_hash'];
        unset($proof,$hash);
        $a=in_array($first,['plain','crlf'],true);$b=in_array($second,['plain','one_lf'],true);
        try { $out=publish158($records,$frozen,$v,$gone);expect158($a||$b,'both invalid accepted'); }
        catch(InvalidArgumentException $e){
            expect158(!$a&&!$b&&$e->getMessage()==='W2_NO_VERIFIED_ROLE','unexpected rejection '.$e->getMessage());
            ++$none;++$publication;continue;
        }
        if($a&&$b){
            $baseline=publish158($good,$frozen,$votes,$gone);
            expect158($out['public_candidates']===$baseline['public_candidates'] && $out['agreement_bonus']===$baseline['agreement_bonus'],'two roles order/bonus');
            foreach($out['public_proof_hashes'] as $n=>$hashes)expect158($hashes===['1st'=>$v['1st'][$n],'2nd'=>$v['2nd'][$n]],'transformed response proof');
            ++$both;
        } else {
            $role=$a?'1st':'2nd';expect158(omit158($out)===$ref[$role] && !array_sum($out['agreement_bonus']),'single role order or spurious bonus');
            ++$single;
        }
        expect158(!array_intersect($out['public_candidates'],$gone),'withdrawn horse published');
        ++$publication;
    }
}
printf("PASS %d provider response cases (%d usable, %d unusable), both roles, two first-provider routes, full/compressed and original/correction. %d legacy checks.\n",$checks,$usable,$stopped,$legacy);
printf("PASS %d saved-response to publication cases (%d both valid, %d one valid, %d both stopped), including partial withdrawals and exact response hashes.\n",$publication,$both,$single,$none);

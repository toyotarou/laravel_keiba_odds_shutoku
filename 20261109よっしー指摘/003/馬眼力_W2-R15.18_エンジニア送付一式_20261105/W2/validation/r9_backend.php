<?php
declare(strict_types=1);
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\RoleJournalW2 as Journal;
use App\Http\Controllers\Api\BaganrikiPrompt as Codec;

function r9pack(array $tree):array {
    $x=Codec::compactWire($tree);if (!$x['ok'])throw new InvalidArgumentException('test-codec');return $x['wire'];
}
function r9expand(array $wire):array {
    $x=Codec::expandWire($wire);if (!$x['ok'])throw new InvalidArgumentException('test-codec');return $x['input'];
}
function r9send(Frozen $snapshot,?array $repair=null,bool $full=false,?callable $budget=null):array {
    $pack=$full?static function(){throw new InvalidArgumentException('permitted full test');}:'r9pack';
    return Bridge::prepareSend($snapshot,$pack,'r9expand',$budget??static fn()=>true,static fn()=>r4live($snapshot),$full,$repair);
}
function r9scenario(string $dir,string $role):array {
    $in=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
    $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
    $proof=json_decode(file_get_contents($dir.'/expected_proof.json'),true,512,JSON_THROW_ON_ERROR);$f=r3stored($in,$proof);
    $raw=r3frame($o);$first=r4receipt($f,$raw);$send=r9send($f);$first['sent_request']=Dispatch::recordSent($f,$send,$send['messages']);
    $grant=r5scope($first,[],['A'],[],range(1,6));$diag=r9diagnostics();
    $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diag,'inspection_sha256'=>Dispatch::inspectionSeal($f,$first['response_hash'],$grant,$diag)];$request=r9send($f,$repair);
    $rev=$o;$rev['band_ranks']['A']=$rev['evidence_band_ranks']['A']=array_reverse($o['band_ranks']['A']);$rev=r5project($in,$rev);
    $rawFix=r3frame($rev);$fix=r4receipt($f,$rawFix,1,true);$fix['repair_scope']=$grant;$fix['repair_diagnostics']=$diag;$fix['inspection_sha256']=$repair['inspection_sha256'];
    $fix['sent_request']=Dispatch::recordSent($f,$request,$request['messages']);
    return compact('in','o','f','raw','first','send','grant','diag','repair','request','rev','rawFix','fix');
}

(function(string $dir) {
    $count=0;$ok=static function(bool $v,string $label)use(&$count){expect($v,'R9 '.$label);++$count;};
    $deny=static function(callable $fn,string $code)use($ok){try{$fn();}catch(InvalidArgumentException $e){$ok($e->getMessage()===$code,$code.' / '.$e->getMessage());return;}throw new RuntimeException('R9 accepted '.$code);};
    foreach (['1st','2nd'] as $role) {
        $case=r9scenario($dir,$role);extract($case);$other=$role==='1st'?'2nd':'1st';$ctx=r4live($f);$nos=array_column($in['runners'],'horse_no');
        $record=['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$first,$fix]];$valid=[$role=>$record];$ctx['role_journal']=Journal::witness($f,$valid,[]);
        $result=Sel::mergeStored($f,$valid,$in['quota'],$nos,[],$ctx);
        $validFix=Sel::verifyOriginal($f,$rawFix,$fix['response_hash']);
        $win=Window::current($f,$ctx);$scope=Window::scope($grant,$first['response_hash'],$in);
        $ok($result['public_candidates']===Sel::merge($f,[$validFix],$in['quota'],$nos,[],array_fill_keys($nos,1))['public_candidates'] && !$result['attempt_issues'],'verified corrected choice');
        foreach (['FULL','SOURCE_TABLE_V1'] as $mode) {
            $init=r9send($f,null,$mode==='FULL');
            $text=file_get_contents(__DIR__.'/../prompts/prompt_'.$role.'.txt');
            if ($mode==='SOURCE_TABLE_V1') $text.="\n\n".file_get_contents(__DIR__.'/../prompts/SOURCE_TABLE_V1_ADDENDUM_V2.txt');
            $ok($init['system_text']===$text && $init['messages'][0]===['role'=>'system','content'=>$text] && $init['messages'][1]===['role'=>'user','content'=>$init['user_json']],'pinned role and one matching wire definition');
            $x=$repair;$x['first_receipt']['sent_request']=$init['sent_request'];$seen=null;
            $validBudget=static function($fmt,$user,$sys)use(&$seen){$seen=[$fmt,$user,$sys];return true;};
            $fixed=r9send($f,$x,$mode==='FULL',$validBudget);
            $ok($seen===[$mode,$fixed['user_json'],$fixed['system_text']] && $fixed['system_text']===$init['system_text'],'actual final correction cost inputs');
            $body=json_decode($fixed['user_json'],true,512,JSON_THROW_ON_ERROR);
            $ok($body['first_response']===$raw && $body['fix_request']['ai_role']===$role && $body['fix_request']['errors']===$diag['errors'] &&
                str_ends_with($fixed['user_json'],',"input":'.$init['user_json'].'}'),'exact nested input and complete first response');
            $limit=strlen($init['user_json'])+strlen($text);$deny(fn()=>r9send($f,$x,$mode==='FULL',static fn($k,$b,$sys)=>strlen($b)+strlen($sys)<=$limit),'W2_SEND_BUDGET');
            $r=$first;$r['sent_request']=$init['sent_request'];$v=$fix;$v['sent_request']=$fixed['sent_request'];
            [$accepted,$errors]=Window::receipts($f,[$v,$r],$win);$ok(count($accepted)===2 && !$errors,'both stored roles, formats and exact request texts');
            foreach (['system','user','order','extra'] as $edit) {
                $m=$fixed['messages'];
                if($edit==='system') $m[0]['content']=Dispatch::system($other,$mode);
                if($edit==='user') $m[1]['content'].=' ';
                if($edit==='order') $m=array_reverse($m);
                if($edit==='extra') $m[]=['role'=>'assistant','content'=>'injected'];
                $deny(fn()=>Dispatch::recordSent($f,$fixed,$m),'W9_OUTBOUND_MESSAGES');
            }
            $a=$r['sent_request'];$a['system_text']=Dispatch::system($other,$mode);
            $deny(fn()=>Dispatch::verifyReceipt($f,$a,0,null,null,null),'W9_SENT_SYSTEM');
            foreach (['request_sha256','user_sha256','system_sha256'] as $k) {
                $a=$r['sent_request'];$a[$k]=str_repeat('f',64);$deny(fn()=>Dispatch::verifyReceipt($f,$a,0,null,null,null),'W9_SENT_HASH');
            }
            $a=Dispatch::record($f,$mode,0,$text,$init['user_json'].' ');
            $deny(fn()=>Dispatch::verifyReceipt($f,$a,0,null,null,null),'W9_SENT_INPUT');
            foreach (['first_response','scope','diagnostic','input'] as $edit) {
                $bad=$fixed['user_json'];
                if($edit==='first_response') $bad=str_replace('ability1','ability_OTHER',$bad);
                if($edit==='scope') $bad=str_replace('"rank_bands":["A"]','"rank_bands":["B"]',$bad);
                if($edit==='diagnostic') $bad=str_replace('saved initial inspection','unrelated test marker',$bad);
                if($edit==='input') $bad=substr($bad,0,-1).',"unexpected":true}';
                $ok($bad!==$fixed['user_json'],'test actually changed '.$edit);
                $a=Dispatch::record($f,$mode,1,$text,$bad);
                $deny(fn()=>Dispatch::verifyReceipt($f,$a,1,$raw,$scope,$diag),'W9_SENT_FIX');
            }
        }
        $deny(fn()=>r9send($f,['first_receipt'=>array_replace($first,['ai_role'=>$other]),'repair_scope'=>$grant,'diagnostics'=>$diag]),'W9_FIX_SOURCE');
        $x=$repair;$x['repair_scope']['first_response_hash']=str_repeat('e',64);$deny(fn()=>r9send($f,$x),'W5_REPAIR_SCOPE');
        $x=$repair;$x['diagnostics']['extra']='after race';$deny(fn()=>r9send($f,$x),'W9_FIX_DIAGNOSTICS');
        $x=$repair;$x['diagnostics']['errors'][0]['reason']=str_repeat('x',4194304);$x['inspection_sha256']=Dispatch::inspectionSeal($f,$first['response_hash'],$grant,$x['diagnostics']);$deny(fn()=>r9send($f,$x),'W9_SEND_SIZE');
        $x=$repair;$x['diagnostics']['errors'][0]['reason']='different recorded race';
        $deny(fn()=>r9send($f,$x),'W9_FIX_INSPECTION');
        $x=$repair;$x['diagnostics']['errors'][0]=array_reverse($x['diagnostics']['errors'][0],true);$x['repair_scope']['presentation_horses']=array_reverse($x['repair_scope']['presentation_horses']);
        $same=r9send($f,$x);$ok($same['user_json']===$request['user_json'],'reordered DB objects and scope sets produce the same actual correction bytes');
        $z=$fix;$z['repair_diagnostics']['errors'][0]['reason']='different';[, $issues]=Window::receipts($f,[$first,$z],$win);
        $ok(($issues[1]??null)==='W9_FIX_INSPECTION','diagnostics changed after inspection before receipt');
        $x=$repair;$full=r9send($f,null,true);$x['first_receipt']['sent_request']=$full['sent_request'];$deny(fn()=>r9send($f,$x),'W9_FIX_FORMAT');
        $deny(fn()=>Bridge::prepareSend($f,static fn()=>[], 'r9expand',static fn()=>true,static fn()=>$ctx,true,$repair),'W2_FULL_NOT_ALLOWED');
        foreach (['shadow','indices','extra','dictionary'] as $edit) {
            $pack=static function($tree)use($edit) {$w=r9pack($tree);
                if ($edit==='shadow') $w['source_registry']=['not_a_source'=>'UNUSED_MARKER'];
                if ($edit==='indices') $w['input_indices']=['unused'=>['UNUSED_MARKER']];
                if ($edit==='extra') $w['source_table']['note']='UNUSED_MARKER';
                if ($edit==='dictionary') $w['source_table']['time_dict'][]='UNUSED_MARKER';
                return $w;};
            $deny(fn()=>Bridge::prepareSend($f,$pack,'r9expand',static fn()=>true,static fn()=>$ctx),'W2_FULL_NOT_ALLOWED');
            $safe=Bridge::prepareSend($f,$pack,'r9expand',static fn()=>true,static fn()=>$ctx,true);
            $ok($safe['format']==='FULL' && $safe['fallback_reason']==='W9_WIRE_SURPLUS' && $safe['user_json']===$f->raw() && !str_contains($safe['user_json'],'UNUSED_MARKER'),'no shadow data in explicitly permitted fallback');
        }
        $deny(fn()=>r9send($f,null,false,static function($kind,&$body,$sys){$body.='changed';return true;}),'W9_BUDGET_MUTATED_REQUEST');
        $z=$ctx;unset($z['role_journal']);$deny(fn()=>Sel::mergeStored($f,$valid,$in['quota'],$nos,[],$z),'W9_JOURNAL_REQUIRED');
        foreach ([[$role=>null],['1st'=>null,'2nd'=>'wrong'],['1st'=>null,'2nd'=>null,'extra'=>null]] as $bad) {
            $z=$ctx;$z['role_journal']=$bad;$deny(fn()=>Sel::mergeStored($f,$valid,$in['quota'],$nos,[],$z),'W9_JOURNAL_REQUIRED');
        }
        $permuted=$valid;$permuted[$role]['receipts']=array_reverse($record['receipts']);$permuted[$role]['receipts'][0]['received_at']=\App\Services\Baganriki\ExpectedPacketW2::utc(\App\Services\Baganriki\ExpectedPacketW2::instant($fix['received_at']));
        $permuted[$role]['receipts'][1]['original_approved']='1';$ok(Journal::witness($f,$permuted,[])===$ctx['role_journal'],'receipt order, DB booleans and equivalent clocks stable');
        $z=$ctx;$z['role_journal'][$role]=str_repeat('5',64);
        $deny(fn()=>Window::assertPublicationCommit($f,$result,$ctx['now'],$z),'W9_JOURNAL_CHANGED');
    }
    echo "PASS $count R9 dispatch, correction-budget, swallowed-wire-field and authoritative journal checks.\n";
})($argv[2]);

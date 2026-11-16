<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiResponse as Reply;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Services\Baganriki\RoleJournalW2 as Journal;
use App\Services\Baganriki\ResponseFrameW2 as Frame;
use App\Services\Baganriki\AttemptWindowW2 as Window;

(function(string $dir) {
    $count=0;$ok=static function(bool $v,string $why)use(&$count){expect($v,'R10 '.$why);++$count;};
    $deny=static function(callable $f,string $code)use($ok){try{$f();}catch(InvalidArgumentException $e){$ok($e->getMessage()===$code,$code.' / '.$e->getMessage());return;}throw new RuntimeException('R10 accepted '.$code);};
    $records=[];$votes=[];$fixtures=[];
    foreach (['1st','2nd'] as $role) {
        $s=r9scenario($dir,$role);$f=$s['f'];$in=$s['in'];$o=$s['o'];$nos=array_column($in['runners'],'horse_no');
        $base=Sel::verifyOriginal($f,$s['raw'],$s['first']['response_hash']);
        foreach ([Reply::BEGIN,Reply::END,Reply::END.Reply::BEGIN,Reply::BEGIN."\n".Reply::END,'"\\'.Reply::END.'\\"'] as $text) {
            foreach (["\n","\r\n"] as $lf) {
                $reply=$o;$reply['horses'][0]['explanation'].=$text;
                $body=json_encode($reply,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_PRETTY_PRINT);
                $raw=Reply::BEGIN.$lf.$body.$lf.Reply::END.$lf;
                $got=Sel::verifyOriginal($f,$raw,hash('sha256',$raw));
                $parsed=Frame::extract($raw);
                $ok($got['public_candidates']===$base['public_candidates'] && $got['selection_ranks']===$base['selection_ranks'] &&
                    $got['independent_groups']===$base['independent_groups'] && $parsed['json']['horses'][0]['explanation']===$reply['horses'][0]['explanation'],
                    'literal markers preserve every selected horse, rank, signal count and source explanation '.$role);
            }
        }
        $reply=$o;$reply['horses'][0]['explanation'].=Reply::BEGIN.' / '.Reply::END;
        $reply['horses'][0]['evidence'][0]['interpretation'].=Reply::END;
        $raw=r3frame($reply);$first=r4receipt($f,$raw);$send=r9send($f);$first['sent_request']=Dispatch::recordSent($f,$send,$send['messages']);
        $record=['kind'=>'ORIGINAL','input'=>$f,'receipts'=>[$first]];
        $verified=Sel::verifyOriginal($f,$raw,$first['response_hash']);
        $records[$role]=$record;foreach ($verified['base_candidates'] as $n)$votes[$role][$n]=$first['response_hash'];
        $fixtures[$role]=compact('f','in','reply','first','record','nos');
        $run=static function($rs,$proof=[])use($f,$in,$nos){$ctx=r4live($f);$ctx['role_journal']=Journal::witness($f,$rs,$proof);return Sel::mergeStored($f,$rs,$in['quota'],$nos,$proof,$ctx);};
        $single=$run([$role=>$record]);
        $ok($single['public_candidates']===Sel::merge($f,[$verified],$in['quota'],$nos,[],$verified['independent_groups'])['public_candidates'] && !$single['attempt_issues'],'actual dispatch plus stored marked original');
        $rec=r4recoveryRecord($f,$reply,$first);$recovered=$run([$role=>$rec]);
        $ok($recovered['public_candidates']===$single['public_candidates'] && !isset($recovered['role_issues'][$role]) && max($recovered['agreement_bonus'])===0,'recovery traces actual marked facts without granting original bonus');
        $fixReply=$reply;$fixReply['horses'][0]['explanation'].=' revised '.Reply::BEGIN.Reply::END;
        $grant=r5scope($first,[],[],[],[1]);$diag=r9diagnostics();
        $repair=['first_receipt'=>$first,'repair_scope'=>$grant,'diagnostics'=>$diag,'inspection_sha256'=>Dispatch::inspectionSeal($f,$first['response_hash'],$grant,$diag)];
        $request=r9send($f,$repair);$fix=r4receipt($f,r3frame($fixReply),1,true);
        $fix['sent_request']=Dispatch::recordSent($f,$request,$request['messages']);$fix['repair_scope']=$grant;$fix['repair_diagnostics']=$diag;$fix['inspection_sha256']=$repair['inspection_sha256'];
        $stored=$record;$stored['receipts'][]=$fix;$out=$run([$role=>$stored]);
        $requestBody=json_decode($request['user_json'],true,512,JSON_THROW_ON_ERROR);
        $ok(!$out['attempt_issues'] && $out['public_candidates']===$single['public_candidates'] && $requestBody['first_response']===$raw,'scoped correction keeps exact first marked response');
        foreach (["\0","\x0B","\x0C"] as $control) foreach (['before','after'] as $position) {
            $body=r3bytes($fixReply);$bad=Reply::BEGIN."\n".($position==='before'?$control:'').$body.($position==='after'?$control:'')."\n".Reply::END;
            $badFix=$fix;$badFix['raw']=$bad;$badFix['response_hash']=hash('sha256',$bad);
            $badRecord=$record;$badRecord['receipts'][]=$badFix;$retained=$run([$role=>$badRecord]);
            $ok(($retained['attempt_issues'][$role][1]??null)==='W2_RESPONSE_JSON' && $retained['public_candidates']===$single['public_candidates'],
                'invalid control byte in approved correction cannot replace intact first');
            $deny(fn()=>Sel::verifyOriginal($f,$bad,hash('sha256',$bad)),'W2_RESPONSE_JSON');
        }
        $wrong=$fixReply;$wrong['horses'][1]['explanation'].=' unapproved '.Reply::END;$badFix=$fix;$badFix['raw']=r3frame($wrong);$badFix['response_hash']=hash('sha256',$badFix['raw']);
        $badRecord=$record;$badRecord['receipts'][]=$badFix;$retained=$run([$role=>$badRecord]);
        $ok(($retained['attempt_issues'][$role][1]??null)==='W5_REPAIR_FIELD_SCOPE' && $retained['public_candidates']===$single['public_candidates'],'literal markers never authorize another horse correction');
        $badHash=$first['response_hash'];$deny(fn()=>Sel::verifyOriginal($f,$raw.'\n',$badHash),'W2_RESPONSE_PROOF_HASH');
    }
    $s=$fixtures['1st'];extract($s);$ctx=r4live($f);$ctx['role_journal']=Journal::witness($f,$records,$votes);
    $both=Sel::mergeStored($f,$records,$in['quota'],$nos,$votes,$ctx);$roles=[];$groups=[];
    foreach ($records as $role=>$record) {
        $r=$record['receipts'][0];$verified=Sel::verifyOriginal($record['input'],$r['raw'],$r['response_hash']);$roles[]=$verified;
        foreach ($verified['independent_groups'] as $n=>$c)$groups[$n]=max($groups[$n]??0,$c);
    }
    $expect=Sel::merge($f,$roles,$in['quota'],$nos,$votes,$groups);
    $ok($both['public_candidates']===$expect['public_candidates'] && $both['agreement_bonus']===$expect['agreement_bonus'] && !$both['role_issues'],'both actual stored marked role responses retain votes and exact selection');
    Window::assertPublicationCommit($f,$both,$ctx['now'],$ctx);$ok(true,'marked selections pass unchanged publication guard');
    foreach (['1st','2nd'] as $role) {
        $bad=$records;$r=&$bad[$role]['receipts'][0];$r['raw']=str_replace("\n{","\n\0{",$r['raw']);$r['response_hash']=hash('sha256',$r['raw']);unset($r);
        $ctx['role_journal']=Journal::witness($f,$bad,$votes);$one=Sel::mergeStored($f,$bad,$in['quota'],$nos,$votes,$ctx);
        $ok(($one['role_issues'][$role]??null)==='W4_NO_APPROVED_ORIGINAL' && ($one['attempt_issues'][$role][0]??null)==='W2_RESPONSE_JSON' && max($one['agreement_bonus'])===0,'invalid first is isolated and cannot issue agreement proof');
    }
    echo "PASS $count R10 original-response, exact marked text, scoped correction, recovery and two-role publication checks.\n";
})($argv[2]);

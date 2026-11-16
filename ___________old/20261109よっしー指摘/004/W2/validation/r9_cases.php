<?php
declare(strict_types=1);
use App\Services\Baganriki\DispatchW2 as Dispatch;
use App\Services\Baganriki\RoleJournalW2 as Journal;
(function(){
    global $passed;$n=$passed;$p=__DIR__.'/../prompts/';$hash=str_repeat('0',64);
    foreach (['1st','2nd'] as $role) foreach (['FULL','SOURCE_TABLE_V1'] as $format) {
        $file=file_get_contents($p.'prompt_'.$role.'.txt');
        if ($format==='SOURCE_TABLE_V1') $file.="\n\n".file_get_contents($p.'SOURCE_TABLE_V1_ADDENDUM_V2.txt');
        check(Dispatch::system($role,$format)===$file,'R9 independently pinned deployed system '.$role.' '.$format);
    }
    foreach (['1st','2nd'] as $role) reject(fn()=>Dispatch::system($role,'UNKNOWN'),'R9 unknown wire format refused '.$role);
    reject(fn()=>Dispatch::system('other','FULL'),'R9 unknown system role refused');
    foreach ([null,$hash] as $a) foreach ([null,$hash] as $b)
        check(Journal::read(['2nd'=>$b,'1st'=>$a])===['1st'=>$a,'2nd'=>$b],'R9 canonical both authoritative role states, absence explicit');
    foreach ([null,[],['1st'=>$hash],['2nd'=>null],['1st'=>true,'2nd'=>null],['1st'=>null,'2nd'=>''],
        ['1st'=>null,'2nd'=>str_repeat('F',64)],['1st'=>null,'2nd'=>null,'rogue'=>null]] as $bad)
        rejectsWith(fn()=>Journal::read($bad),'W9_JOURNAL_REQUIRED','R9 absent or ambiguous authoritative read refused');
    echo 'R9 regression checks: '.($passed-$n)."\n";
})();

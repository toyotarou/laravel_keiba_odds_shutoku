<?php
declare(strict_types=1);
use App\Http\Controllers\Api\BaganrikiRanking as Ranking;
use App\Services\Baganriki\CandidateSelectionW2 as Sel;
use App\Services\Baganriki\FrozenInputW2 as Frozen;

(function(string $dir) {
    $modes=['FIRM','MIXED','VOLATILE'];$bands=['A','B','D'];$original=[];$tpl=[];
    foreach (['1st','2nd'] as $role) {
        $original[$role]=json_decode(file_get_contents("$dir/{$role}_input.json"),true,512,JSON_THROW_ON_ERROR);
        $tpl[$role]=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
    }
    $owners=array_column($original['1st']['runners'],'band','horse_no');$capacity=array_count_values($owners);$all=array_keys($owners);
    $withdrawals=[[],[1,3,8,15],range(1,6),range(7,10),range(11,16),$all];$checked=0;$filled=0;$short=0;$different=0;
    foreach ($modes as $mode) {
        $snapshots=[];foreach ($original as $role=>$in) $snapshots[$role]=r3stored(marketFixture($in,$mode));
        $seed=$snapshots['1st'];$input=Frozen::checked($seed);
        foreach (range(0,6) as $a) foreach (range(0,4) as $b) foreach (range(0,6) as $d) {
            $case="$mode/$a/$b/$d";$allowed=[];$slots=['A'=>$a,'B'=>$b,'D'=>$d];
            foreach ($bands as $band) {
                $pool=array_keys(array_filter($owners,static fn($v)=>$v===$band));
                usort($pool,static fn($i,$j)=>strcmp(hash('sha256',"pool/$case/$i"),hash('sha256',"pool/$case/$j")));
                foreach (array_slice($pool,0,$slots[$band]) as $n)$allowed[$n]=true;
            }
            $results=[];$proof=[];$groups=[];$basePer=['1st'=>[],'2nd'=>[]];
            foreach ($snapshots as $role=>$snapshot) {
                $ix=$role==='1st'?0:1;$rows=$tpl[$role]['horses'];$ranked=array_fill_keys($bands,[]);
                foreach ($rows as &$horse) {
                    $n=$horse['horse_no'];
                    if (!isset($allowed[$n]) || ($n+$a+$d)%4===$ix) { $horse=r5missing($horse);continue; }
                    $ranked[$horse['band']][]=$n;
                }unset($horse);
                foreach ($ranked as &$list)usort($list,static fn($i,$j)=>strcmp(hash('sha256',"$role/$case/$i"),hash('sha256',"$role/$case/$j")));unset($list);
                $result=Sel::apply($snapshot,$rows,$ranked);$digest=hash('sha256',"synthetic-verified-order/$case/$role");$result['public_proof_hash']=$digest;
                $results[$role]=$result;
                foreach ($result['selection_ranks'] as $band=>$list) foreach ($list as $pos=>$n) {
                    $claimed=in_array($n,$result['public_candidates'],true) && in_array($n,$result['base_candidates'],true) && ($n+$b)%5!==0;
                    if ($claimed)$proof[$role][$n]=$digest;
                    $g=1+($n+$ix+$d)%4;$groups[$n]=max($groups[$n]??0,$g);
                    $basePer[$role][$n]=['r'=>$pos+1,'K'=>$capacity[$band],'public'=>$claimed,'groups'=>$g];
                }
            }
            if ($results['1st']['selection_ranks']!==$results['2nd']['selection_ranks'])++$different;
            foreach ($withdrawals as $gone) {
                $active=array_values(array_diff($all,$gone));$keys=array_fill_keys($active,true);
                $q=Ranking::afterCancellation($owners,$gone,$input['quota'],$mode)['live_quota'];$queues=array_fill_keys($bands,[]);
                $per=array_map(static fn($rows)=>array_intersect_key($rows,$keys),$basePer);
                foreach (Ranking::merge($per,$proof) as $row) $queues[$owners[$row['num']]][]=$row['num'];
                $ref=$q===null?['quota'=>array_fill_keys($bands,0),'shortfalls'=>[]]:Ranking::fillShortfall($q,array_map('count',$queues));
                $byBand=[];$want=[];foreach ($queues as $band=>$nums) { $byBand[$band]=array_slice($nums,0,$ref['quota'][$band]);array_push($want,...$byBand[$band]); }
                $remaining=[];foreach ($ref['shortfalls'] as $u) if ($u['shortfall']>0) $remaining[]=['band'=>$u['band'],'requested'=>$u['requested'],'filled'=>$u['requested']-$u['shortfall'],'missing'=>$u['shortfall']];
                $ans=Sel::merge($seed,$results,$q,$active,$proof,$groups);$back=Sel::merge($seed,array_reverse($results,true),$q,array_reverse($active),$proof,$groups);
                $actualMissing=array_column($ans['shortfalls'],'missing','band');$wantMissing=array_column($remaining,'missing','band');ksort($actualMissing);ksort($wantMissing);
                expect($ans===$back && $ans['public_candidates']===$want && $ans['actual_quota']===array_map('count',$byBand) &&
                    $actualMissing===$wantMissing && count($want)===count(array_unique($want)) && !array_intersect($gone,$want),'R7 ordered release/remaining quota '.$case.'/'.json_encode($gone));
                foreach ($ans['shortfalls'] as $u)expect($u['filled']+$u['missing']===$q[$u['band']],'R7 vacant requested slots');
                $moved=[];foreach ($ans['fill'] as $row) {
                    $n=$row['horse_no'];expect(!isset($moved[$n]) && in_array($n,$want,true) && $owners[$n]===$row['actual'] &&
                        in_array($row['actual'],['D'=>['B'],'B'=>['D'],'A'=>['B','D']][$row['requested']],true),'R7 fill audit belongs to the actual donor');
                    $moved[$n]=true;
                }
                $filled+=(int)(bool)$ans['fill'];$short+=(int)(bool)$remaining;++$checked;
            }
        }
    }
    expect($checked===4410 && $filled>0 && $short>0 && $different>0,'R7 combinatorial coverage');
    echo "PASS $checked R7 mode, eligibility and withdrawal combinations; $filled cross-band fills and $short remaining shortfalls match the submitted ranking in exact display order.\n";
})($argv[2]);

<?php
// Two-horse fixtures derived from audit3; helper only, no production dependencies.
use App\Http\Controllers\Api\{BaganrikiDiagnose as Diagnose,BaganrikiResponse as Response,BaganrikiWorker as W};
use App\Support\Baganriki\ExactJson;
function eligibleFixture4(bool $complete=false): array {
    [$src,$res,$c]=resetFixture();
    $src['runners']=[['horse_no'=>1,'band'=>'A'],['horse_no'=>2,'band'=>'A']];
    foreach(['target_count','role_cap'] as $k) $src[$k]=$res[$k]=2;
    foreach(['quota','role_quota'] as $k) $src[$k]=$res[$k]=['A'=>2,'B'=>0,'D'=>0];
    $template=$res['horses'][0]; $res['horses']=[];
    foreach([1,2] as $n) {
        $ref='horse-'.$n.'-signal';
        $src['source_registry'][$ref]=['scope'=>'HORSE','horse_nos'=>[$n],'applicable_horse_nos'=>[],'dependency_role'=>'SIGNAL'];
        $h=$template; $h['horse_no']=$n; $h['eligibility']='ELIGIBLE'; $h['tier']='A';
        $h['rank_basis']=$complete?'SCORE':'EVIDENCE'; $h['rank_reason']='Confirmed own signal';
        $h['public_candidate']=true; $h['nonselect_reason']=null;
        $h['family_review']['ability']=['status'=>'SUPPORTED','source_refs'=>[$ref]];
        $h['evidence'][0]['source_refs']=[$ref];
        if($complete) {
            foreach(Diagnose::SCORE_CAP as $k=>$cap) {
                $h['scores'][$k]=1;
                $h['score_evidence'][$k]=['source_refs'=>[$ref],'reason'=>'Fixture signal'];
            }
            $h['score_status']='complete'; $h['observed_cap']=100;
            $h['observed_sum']=$h['total_score']=10;
        }
        $res['horses'][]=$h;
    }
    $res['band_ranks']['A']=$res['evidence_band_ranks']['A']=$res['public_candidates']=[1,2];
    DB::$rows['baganriki_snapshots'][0]['input_json_1st']=json_encode($src);
    $src2=$src; $src2['ai_role']='2nd';
    DB::$rows['baganriki_snapshots'][0]['input_json_2nd']=json_encode($src2);
    DB::$rows['baganriki_snapshots'][0]['frozen_nums']='[1,2]';
    DB::$rows['baganriki_snapshots'][0]['quota_1st']='{"A":2,"B":0,"D":0}';
    DB::$rows['baganriki_live_states'][0]['active_count']=2;
    setRaw($res);
    $d=Diagnose::run($res,$src);
    must4($d['ok'],'invalid test fixture: '.json_encode($d['findings']));
    return [$src,$res,$c];
}
function addFix4(array $res): void {
    $raw=Response::BEGIN.ExactJson::encode($res).Response::END;
    $row=DB::$rows['baganriki_ai_attempts'][0];
    $row['id']=11; $row['attempt_no']=W::FIX; $row['token']='fix-token';
    $row['raw_response']=$raw; $row['response_hash']=hash('sha256',$raw);
    $row['received_at']=W::toDb(W::nowUs());
    DB::$rows['baganriki_ai_attempts'][]=$row;
}

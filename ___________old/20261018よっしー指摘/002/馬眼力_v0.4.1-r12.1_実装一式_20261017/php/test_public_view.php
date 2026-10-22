<?php
declare(strict_types=1);
require (getenv('BAGANRIKI_PHP_SUBJECT') ?: __DIR__).'/PublicView.php';
$live=['generation'=>1,'state_revision'=>0,'publication_revision'=>0,'cancelled'=>[],
    'active_count'=>13,'publication_target_count'=>5,'publication_quota'=>['A'=>2,'B'=>2,'D'=>1],
    'effective_publish_deadline'=>'2026-10-17T14:58:00.123456+09:00'];
$cases=[];
$record=static function (string $key,bool $ok) use (&$cases):void {$cases[$key]=$ok ? 'PASSED' : 'FAILED';};
$stored=['publication'=>null,'live_state'=>$live];
$pending=\Baganriki\PublicView::build($stored,[],'2026-10-17T14:58:00.123456+09:00');
$record('deadline_exact_pending',$pending['status']==='PENDING');
$expired=\Baganriki\PublicView::build($stored,[],'2026-10-17T14:58:00.123457+09:00');
$record('expired_is_unavailable',$expired['status']==='UNAVAILABLE');
$empty=$stored;$empty['live_state']['active_count']=0;
$record('cancelled_before_publication',\Baganriki\PublicView::build($empty,[],'2026-10-17T14:57:00+09:00')['status']==='CANCELLED');
$pub=['publication_id'=>1,'generation'=>1,'publication_revision'=>1,'state_revision'=>0,'mode'=>'MIXED','display_rating'=>3,
    'active_count'=>13,'publication_target_count'=>5,'publication_quota'=>['A'=>2,'B'=>2,'D'=>1],
    'actual_quota'=>['A'=>2,'B'=>2,'D'=>1],'status_flags'=>[],
    'candidates'=>[['horse_no'=>1,'band'=>'A'],['horse_no'=>2,'band'=>'A'],['horse_no'=>10,'band'=>'B'],['horse_no'=>9,'band'=>'B'],['horse_no'=>11,'band'=>'D']]];
$stored['publication']=$pub;$stored['live_state']['state_revision']=1;$stored['live_state']['cancelled']=[10];$stored['live_state']['active_count']=12;
$dto=\Baganriki\PublicView::build($stored,[],'2026-10-17T15:00:00+09:00');
$record('current_active_count',$dto['active_count']===12);
$record('current_actual_quota',$dto['actual_quota']===['A'=>2,'B'=>1,'D'=>1]);
$record('immutable_prediction_counts',($dto['prediction_active_count'] ?? null)===13 && ($dto['prediction_actual_quota'] ?? null)===$pub['actual_quota']);
$record('late_cancel_never_refills',count($dto['candidates'])===5 && $dto['candidates'][2]['cancelled']===true && ($dto['available_candidate_count'] ?? null)===4);
$record('shortage_visible',in_array('PARTIAL_COUNT',$dto['status_flags'],true));
$record('input_unchanged',$stored['publication']===$pub);
$boundary=$stored;$boundary['publication']['active_count']=16;$boundary['publication']['publication_target_count']=7;
$boundary['publication']['publication_quota']=['A'=>4,'B'=>2,'D'=>1];$boundary['publication']['actual_quota']=['A'=>4,'B'=>2,'D'=>1];
$boundary['publication']['candidates'][]=['horse_no'=>3,'band'=>'A'];$boundary['publication']['candidates'][]=['horse_no'=>4,'band'=>'A'];
$boundary['live_state']['active_count']=15;$boundary['live_state']['cancelled']=[16];$boundary['live_state']['publication_target_count']=6;
$boundary['live_state']['publication_quota']=['A'=>3,'B'=>2,'D'=>1];
$dto=\Baganriki\PublicView::build($boundary,[],'2026-10-17T15:00:00+09:00');
$record('frozen_quota_separate_from_live_target',$dto['publication_target_count']===7 && ($dto['live_target_count'] ?? null)===6 && count($dto['candidates'])===7);
echo json_encode(['php_version'=>PHP_VERSION,'public_view'=>$cases]),"\n";
if (in_array('FAILED',$cases,true)) exit(1);

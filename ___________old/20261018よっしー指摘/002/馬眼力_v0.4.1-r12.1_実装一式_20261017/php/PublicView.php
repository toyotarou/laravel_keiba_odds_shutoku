<?php
declare(strict_types=1);
namespace Baganriki;
require_once __DIR__.'/Timestamp.php';
/** Explicit new API DTO. No conversion to legacy "おすすめ度" candidate rows. */
final class PublicView
{
    public static function build(array $stored, array $horseNamesByNo = [], ?string $now = null): array
    {
        $pub=$stored['publication'];$live=$stored['live_state'];
        if ($pub===null) {
            $allCancelled=($live['active_count'] ?? null)===0;
            $current=$now===null ? new \DateTimeImmutable('now',new \DateTimeZone('UTC')) : Timestamp::parse($now);
            $expired=$current > Timestamp::parse($live['effective_publish_deadline']);
            return ['status'=>$allCancelled ? 'CANCELLED' : ($expired ? 'UNAVAILABLE' : 'PENDING'),
                'status_flags'=>$allCancelled ? ['ALL_CANCELLED'] : ($expired ? ['PUBLICATION_DEADLINE_PASSED'] : []),
                'candidates'=>[],'live_state'=>$live];
        }
        $candidates=[];$actual=['A'=>0,'B'=>0,'D'=>0];$available=0;$hasCancelled=false;
        foreach ($pub['candidates'] as $candidate) {
            $no=$candidate['horse_no'];$cancelled=in_array($no,$live['cancelled'],true);
            $candidate['horse_name']=$horseNamesByNo[$no] ?? null;
            $candidate['cancelled']=$cancelled;$candidate['buy_recommended']=false;
            $candidate['display_status']=$cancelled ? 'CANCELLED' : 'CANDIDATE';
            if ($cancelled) $hasCancelled=true;
            else {++$actual[$candidate['band']];++$available;}
            $candidates[]=$candidate;
        }
        $active=$live['active_count'];$target=min($pub['publication_target_count'],$live['publication_target_count']);
        $flags=array_values(array_diff($pub['status_flags'],['PARTIAL_COUNT','ALL_CANCELLED']));
        if ($hasCancelled || $active!==$pub['active_count']) $flags[]='CANCELLATION_OVERLAY';
        if ($live['state_revision']!==$pub['state_revision']) $flags[]='LIVE_STATE_CHANGED';
        if ($available<$target) $flags[]='PARTIAL_COUNT';
        if ($active===0) $flags[]='ALL_CANCELLED';
        return ['status'=>$active===0 ? 'CANCELLED' : 'PUBLISHED',
            'publication_id'=>$pub['publication_id'],'generation'=>$pub['generation'],
            'publication_revision'=>$pub['publication_revision'],'prediction_state_revision'=>$pub['state_revision'],
            'live_state_revision'=>$live['state_revision'],'mode'=>$pub['mode'],'display_rating'=>$pub['display_rating'],
            'active_count'=>$active,'publication_target_count'=>$pub['publication_target_count'],
            'publication_quota'=>$pub['publication_quota'],'actual_quota'=>$actual,'available_candidate_count'=>$available,
            'live_target_count'=>$live['publication_target_count'],'live_quota'=>$live['publication_quota'],
            'prediction_active_count'=>$pub['active_count'],'prediction_target_count'=>$pub['publication_target_count'],
            'prediction_quota'=>$pub['publication_quota'],'prediction_actual_quota'=>$pub['actual_quota'],
            'prediction_status_flags'=>$pub['status_flags'],
            'status_flags'=>array_values(array_unique($flags)),'candidates'=>$candidates,'live_state'=>$live,
            'prediction_basis'=>'6分前評価','purchase_status'=>'PENDING_VALIDATION'];
    }
}

<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-PREDICTION-VIEW  読取API用のDTOと表示状態
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 8章・11章／詳細設計書 12.2
 *
 *   state は公開状態で、mode_status とは別物です。
 *     PENDING     処理中で期限内
 *     PUBLISHED   有効な正式公開がある
 *     UNAVAILABLE 未公開のまま実効公開期限を過ぎた／固定入力が計算不能
 *     CANCELLED   全頭取消
 *   正式公開の履歴と現在のライブ状態を分けて返します。
 *   原AI応答・予約token・内部認証情報は返しません。
 */
final class BaganrikiPublicView
{
    /** 表示状態を決めます */
    public static function state(bool $hasPublication, bool $allCancelled,
                                 bool $inputInvalid, bool $pastDeadline): string
    {
        if ($allCancelled)   { return 'CANCELLED'; }
        if ($hasPublication) { return 'PUBLISHED'; }
        if ($inputInvalid)   { return 'UNAVAILABLE'; }
        return $pastDeadline ? 'UNAVAILABLE' : 'PENDING';
    }

    /** 暫定のときの表示文。確定した波乱度や星としては出しません */
    public static function provisionalLabel(?string $allocationMode): ?string
    {
        if ($allocationMode === 'MIXED')    { return '混戦として暫定選出'; }
        if ($allocationMode === 'VOLATILE') { return '波乱として暫定選出'; }
        return null;
    }

    public const PROVISIONAL_NOTE = '過去類似判定が未確定のため';

    /**
     * 読取APIが返す形を組み立てます。
     * 数値は十進数文字列のまま渡し、一律 double にしません。
     */
    public static function build(array $a): array
    {
        $modeStatus = $a['mode_status'] ?? null;
        $alloc      = $a['allocation_mode'] ?? null;
        return [
            'schema_version'  => 'baganriki-v041-r12-2',
            'race_id'         => $a['race_id'] ?? null,
            'state'           => $a['state'] ?? null,
            'mode'            => $a['mode'] ?? null,
            'mode_status'     => $modeStatus,
            'allocation_mode' => $alloc,
            'mode_policy_id'  => $a['mode_policy_id'] ?? null,
            'display_rating'  => ($modeStatus === 'PROVISIONAL') ? null : ($a['display_rating'] ?? null),
            'provisional'     => ($modeStatus === 'PROVISIONAL') ? [
                'label' => self::provisionalLabel($alloc),
                'note'  => self::PROVISIONAL_NOTE,
            ] : null,
            'publication'     => $a['publication'] ?? null,   // 未公開は null
            'live_state'      => [
                'state_revision'    => $a['state_revision'] ?? null,
                'cancellations'     => array_values($a['cancellations'] ?? []),
                'active_count'      => $a['active_count'] ?? null,
                'live_target_count' => $a['live_target_count'] ?? null,
                'live_quota'        => $a['live_quota'] ?? null,
                // @ANCHOR-20261020-PREDICTION-API  契約8章・11章で返すと決めている項目
                //   3つの版をそろえて返します（state だけでは足りません）。
                //   actual_quota と available_candidate_count は<b>いまの取消</b>を
                //   映します。prediction_* は公開したときの履歴なので動かしません。
                'response_revision'    => $a['response_revision'] ?? null,
                'publication_revision' => $a['publication_revision'] ?? null,
                'latest_publication_id' => $a['latest_publication_id'] ?? null,
                'actual_quota'             => $a['actual_quota'] ?? null,
                'available_candidate_count' => $a['available_candidate_count'] ?? null,
                'live_close'        => $a['live_close'] ?? null,
                'effective_publish_deadline' => $a['effective_publish_deadline'] ?? null,
                'effective_reply_deadline'   => $a['effective_reply_deadline'] ?? null,
            ],
            // @ANCHOR-20261020-PREDICTION-API  ワーカーの状態。
            //   予約token と原応答は入れません（契約10章）。
            'worker_state'    => $a['worker_state'] ?? null,
            'input_audit'     => [
                'collection_audit'      => $a['collection_audit'] ?? null,
                'input_freeze_at'       => $a['input_freeze_at'] ?? null,
                // @ANCHOR-20261023-PREDICTION-COUNTS  凍結時の頭数
                'frozen_count'          => $a['frozen_count'] ?? null,
                'coverage'              => $a['coverage'] ?? null,
                'time_contract_version' => 'baganriki-post-slot-grace-v1',
                'anchor_kind'           => 'POST_TIME',
                'sampling_policy_id'    => 'SLOT_FIRST_VALID_COMMITTED_V1',
                // @ANCHOR-20261020-PREDICTION-CLOSE  投票締切の出どころ
                'sales_close_at'        => $a['sales_close_at'] ?? null,
                'sales_close_source'    => $a['sales_close_source'] ?? null,
            ],
            'diagnostics'     => array_values($a['diagnostics'] ?? []),
            'shortfalls'      => array_values($a['shortfalls'] ?? []),
            'buy_recommended' => false,
            'purchase_policy_status' => 'PENDING_VALIDATION',
            'learning_stage'  => 'COLLECT_ONLY',
            'model_version'   => null,
        ];
    }

}

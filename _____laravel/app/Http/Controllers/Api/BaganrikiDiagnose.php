<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

// AiController.php と同じ PHP 8.0 用の array_is_list（このファイル単独で読み込まれても動くように同じものを置く）
// @ANCHOR-20261023-NARU-011  PHP 8.0 には array_is_list が無いので、この名前空間に用意します
//   （011再精査版の直しが array_is_list を使うため。本番は PHP 8.0.30）
if (!function_exists(__NAMESPACE__ . '\\array_is_list')) {
    function array_is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) { return false; }
        }
        return true;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-PREDICTION-DIAG  応答の診断（inspect と resolve で同じ関数を使う）
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 6章・7章
 *   「訂正最大1回。inspectとresolveは同じ診断関数を使い、repair_scopeを返す」
 *   「馬番欠落、重複、未知馬、局所フィールド不正はその範囲だけ隔離し、
 *     正常な他馬を残す」
 *   「公開リストやフラグの不一致だけでは、無関係な評価行を訂正対象へ
 *     広げない。欠落馬は凍結runner集合に存在しhorses対象内なら新規救済する」
 *
 * ■ 直してよい範囲（repair_scope）の分け方
 *   horses              評価行そのもの（欠落・重複・未知馬・局所の項目不正）
 *   rank_bands          順位関連項目（band_ranks / evidence_band_ranks）
 *   presentation_horses 公開フラグ・非選出理由
 *   summary_horses      帯・資格・Tier・採点集計・5系統レビュー・
 *                       非適格馬の順位欄
 *   public_candidates   公開リスト
 *   top_fields          最上位項目
 *
 *   <b>公開リストと公開フラグの不一致では、horses を広げません。</b>
 *   その馬だけを presentation_horses / public_candidates に入れます。
 *
 * ■ 採点辞書（10月20日の指定）
 *   H1 能力適性18／H2 時系列15／H3 断層12／H4 類似統計9／H5 単複差6
 *   V1 補正OPI12／V2 能力価格乖離10／V3 回収率母数8／V4 推定確定オッズ6
 *   V5 情報価格信頼性4   合計100
 *
 * 読むだけです。DBへは書きません（保存は呼出側が行います）。
 */
final class BaganrikiDiagnose
{
    public const VERSION = 'r122-diagnose-v1';

    /** 採点辞書（満点） */
    public const SCORE_CAP = ['H1' => 18, 'H2' => 15, 'H3' => 12, 'H4' => 9, 'H5' => 6,
                              'V1' => 12, 'V2' => 10, 'V3' => 8,  'V4' => 6, 'V5' => 4];

    /** 1頭ぶんに必ずある項目 */
    public const HORSE_KEYS = ['horse_no', 'band', 'tier', 'eligibility', 'family_review',
                               'evidence', 'counterevidence', 'scores', 'score_evidence',
                               'score_status', 'total_score', 'observed_sum', 'observed_cap',
                               'rank_basis', 'rank_reason', 'public_candidate',
                               'nonselect_reason', 'explanation',
                               'evidence_rank_evidence_ids', 'evidence_rank_reason'];

    public const ENUMS = [
        'band'         => ['A', 'B', 'D'],
        'tier'         => ['A', 'B', 'C', 'UNVERIFIED'],
        'eligibility'  => ['ELIGIBLE', 'NO_EVIDENCE', 'UNVERIFIED'],
        'score_status' => ['complete', 'partial'],
        'rank_basis'   => ['SCORE', 'EVIDENCE', 'NOT_RANKED'],
    ];
    public const NONSELECT_CODES = ['BAND_QUOTA', 'NO_EVIDENCE', 'UNVERIFIED',
                                    'SELECTION_DISABLED'];
    public const FAMILIES = ['ability', 'gap', 'market', 'similar_value', 'win_place'];
    public const FAMILY_STATUS = ['SUPPORTED', 'NO_SUPPORT', 'DATA_MISSING'];
    public const BANDS = ['A', 'B', 'D'];

    /** @ANCHOR-20261023-NARU-011  出てくる source_refs はすべて「文字列の配列」であること */
    public static function validSourceRefs($v): bool
    {
        if (!is_array($v)) { return true; }
        foreach ($v as $key => $item) {
            if ($key === 'source_refs') {
                if (!is_array($item) || !array_is_list($item)) { return false; }
                foreach ($item as $ref) { if (!is_string($ref)) { return false; } }
            } elseif (!self::validSourceRefs($item)) {
                return false;
            }
        }
        return true;
    }

    public static function run(array $res, array $src, ?array $responseMap = null, ?array $inputMap = null): array
    {
        $find = [];
        $scope = ['horses' => [], 'rank_bands' => [], 'presentation_horses' => [],
                  'summary_horses' => [], 'public_candidates' => false,
                  'top_fields' => []];
        $add = static function (string $sc, $target, string $code, string $why)
               use (&$find, &$scope) {
            $find[] = ['scope' => $sc, 'target' => $target, 'code' => $code,
                       'reason' => $why];
            if ($sc === 'public_candidates') { $scope['public_candidates'] = true; return; }
            if (!isset($scope[$sc])) { return; }
            if (!in_array($target, $scope[$sc], true)) { $scope[$sc][] = $target; }
        };
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-SCOREKEY
        // ══════════════════════════════════════════════════════════════
        //   【旧】採点に不備があることは分かっても、<b>どの項目が
        //     おかしいのかを外へ出していませんでした。</b>そのため
        //     救済するときに H1〜V5 を全部 null にしていました。
        //     observed_sum だけが誤っている馬でも、正しい10項目が消えます。
        //   【いま】項目ごとに印を付けて外へ出します。
        //     救済は<b>印の付いた項目だけ</b>を null にし、
        //     合計は残った項目から計算し直します（契約6章）。
        //   observed_sum・observed_cap・total_score は集計の誤りなので、
        //   ここには入れません（計算し直せば直るためです）。
        $badScore = [];
        $markScore = static function (int $no, $keys) use (&$badScore) {
            foreach ((array) $keys as $k) {
                if (!is_string($k) || $k === '') { continue; }
                $badScore[$no][$k] = true;
            }
        };

        // ── 凍結集合（帯も凍結入力の値を正とします）───────────────────
        $frozenBand = []; $frozenNums = [];
        foreach ((array) ($src['runners'] ?? []) as $r) {
            $no = (int) ($r['horse_no'] ?? 0);
            if ($no <= 0) { continue; }
            $frozenNums[] = $no;
            $frozenBand[$no] = (string) ($r['band'] ?? '');
        }
        sort($frozenNums);

        $w2Projection = BaganrikiScoreMaterials::usesR14Rules($src['prompt_version'] ?? null);
        $w15 = ($src['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION;
        $typedInvalid = $unsafeNested = [];
        if ($w15 && $responseMap !== null && $inputMap !== null) {
            [$typedInvalid, $unsafeNested] = BaganrikiResponseInspection::raw($src, $res, $inputMap, $responseMap, $add, $markScore);
        }
        // ═══ ① 最上位項目 ═══════════════════════════════════════════
        foreach (['target_count', 'role_cap', 'quota', 'role_quota', 'learning_stage',
                  'selection_enabled'] as $k) {
            if (!array_key_exists($k, $res)) {
                $add('top_fields', $k, 'TOP_MISSING', $k . 'がありません');
                continue;
            }
            // @ANCHOR-20261023-NARU-011  JSON の object に並び順は無いので、quota／role_quota は
            //   並び順の違いだけなら同じとみなします（値と型は厳密に比べます。011再精査6版）
            $sameQ = static function ($x, $y): bool {
                if (!is_array($x) || !is_array($y)) { return $x === $y; }
                if (array_is_list($x) || array_is_list($y)) { return $x === $y; }
                ksort($x, SORT_STRING); ksort($y, SORT_STRING);
                return $x === $y;
            };
            if (array_key_exists($k, $src)
                && (in_array($k, ['quota', 'role_quota'], true)
                    ? !$sameQ($res[$k], $src[$k]) : $res[$k] !== $src[$k])) {
                $add('top_fields', $k, 'TOP_MISMATCH',
                     $k . 'が固定入力と違います');
            }
        }
        foreach (['shortfalls', 'validation_issues'] as $k) {
            if (!array_key_exists($k, $res) || !is_array($res[$k])) {
                $add('top_fields', $k, 'TOP_TYPE', $k . 'は配列です');
            }
        }
        $selEnabled = ($res['selection_enabled'] ?? null) === true;

        // ═══ ② 評価行（欠落・重複・未知馬・局所の項目不正）═══════════
        $rows = $res['horses'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            $add('horses', null, 'HORSES_TYPE', 'horses は評価行の配列です');
            $rows = [];
        }
        $byNo = []; $dup = []; $unknown = [];
        foreach ($rows as $h) {
            if (!is_array($h) || !array_key_exists('horse_no', $h)
                || !is_int($h['horse_no'])) {
                $add('horses', null, 'HORSE_NO_INVALID', 'horse_no が整数ではありません');
                continue;
            }
            $no = (int) $h['horse_no'];
            if (isset($byNo[$no])) {
                if (!in_array($no, $dup, true)) { $dup[] = $no; }
                $add('horses', $no, 'HORSE_DUPLICATED', '同じ馬番が2行あります');
                continue;
            }
            if ($frozenNums !== [] && !in_array($no, $frozenNums, true)) {
                $unknown[] = $no;
                $add('horses', $no, 'HORSE_UNKNOWN', '凍結集合にいない馬番です');
                continue;
            }
            $byNo[$no] = $h;
        }
        $missing = array_values(array_diff($frozenNums, array_keys($byNo)));
        sort($missing);
        foreach ($missing as $no) {
            // 凍結集合にいる馬の欠落は、horses の対象として救済します
            $add('horses', $no, 'HORSE_MISSING', '凍結集合にいるのに評価行がありません');
        }

        $rowValid = [];
        // @ANCHOR-20261020-PREDICTION-REFCHECK  固定入力の出典台帳（無ければ検査しません）
        // @ANCHOR-20261023-NARU-011  空でも資格検証を省略しません（011再精査4〜6版）
        $reg = (array) ($src['source_registry'] ?? []);
        $refStats = [];
        $scoreComplete = [];
        $rankEligible = [];
        foreach ($byNo as $no => $h) {
            // @ANCHOR-20261023-NARU-011  重複した馬の行は正常扱いしない。evidence／counterevidence は
            //   object の配列、source_refs は文字列の配列でなければ行を不正にします
            $okRow = !in_array($no, $dup, true) && !isset($typedInvalid[$no]);
            if (isset($unsafeNested[$no])) {
                $rowValid[$no] = $rankEligible[$no] = $scoreComplete[$no] = false;
                continue;
            }
            foreach (['evidence', 'counterevidence'] as $field) {
                $entries = $h[$field] ?? null;
                $shape = is_array($entries) && array_is_list($entries);
                if ($shape) {
                    foreach ($entries as $entry) {
                        if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) { $shape = false; break; }
                    }
                }
                if (!$shape) { $add('horses', $no, 'FIELD_TYPE', $field . ' はobjectの配列です'); $okRow = false; }
            }
            if (!self::validSourceRefs($h)) {
                $add('horses', $no, 'FIELD_TYPE', 'source_refs は文字列の配列です'); $okRow = false;
            }
            foreach (self::HORSE_KEYS as $k) {
                if (!array_key_exists($k, $h)) {
                    $add('horses', $no, 'FIELD_MISSING', $k . 'がありません');
                    $okRow = false;
                }
            }
            foreach (self::ENUMS as $k => $allow) {
                if (array_key_exists($k, $h)
                    && !in_array($h[$k], $allow, true)) {
                    $add('horses', $no, 'FIELD_ENUM',
                         $k . 'の値が決められた中にありません');
                    $okRow = false;
                }
            }
            if (array_key_exists('public_candidate', $h)
                && !is_bool($h['public_candidate'])) {
                $add('horses', $no, 'FIELD_TYPE', 'public_candidate は真偽です');
                $okRow = false;
            }
            // 採点の内訳と集計
            $sc = $h['scores'] ?? null;
            if (!is_array($sc)) {
                $add('horses', $no, 'SCORES_TYPE', 'scores がありません');
                $okRow = false;
            } else {
                $extra = array_values(array_diff(array_keys($sc),
                                                 array_keys(self::SCORE_CAP)));
                if ($extra !== []) {
                    $add('horses', $no, 'SCORES_UNKNOWN_KEY',
                         '採点辞書に無い項目があります（' . implode('・', $extra) . '）');
                    $markScore($no, $extra);          // @ANCHOR-...-SCOREKEY
                    $okRow = false;
                }
                $sum = 0; $cap = 0; $bad = [];
                foreach (self::SCORE_CAP as $k => $mx) {
                    if (!array_key_exists($k, $sc)) {
                        $add('horses', $no, 'SCORES_MISSING', $k . 'がありません');
                        $markScore($no, [$k]);        // @ANCHOR-...-SCOREKEY
                        $okRow = false;
                        continue;
                    }
                    $v = $sc[$k];
                    if ($v === null) { continue; }
                    if (!is_int($v) || $v < 0 || $v > $mx) { $bad[] = $k; continue; }
                    $sum += $v; $cap += $mx;
                }
                if ($bad !== []) {
                    $add('summary_horses', $no, 'SCORES_RANGE',
                         '満点を超える・整数でない採点があります（'
                         . implode('・', $bad) . '）');
                    $markScore($no, $bad);            // @ANCHOR-...-SCOREKEY
                    $okRow = false;
                }
                if ($bad === [] && $extra === []) {
                    if (($h['observed_sum'] ?? null) !== $sum) {
                        $add('summary_horses', $no, 'OBSERVED_SUM',
                             'observed_sum が採点の合計と合いません');
                        $okRow = false;
                    }
                    if (($h['observed_cap'] ?? null) !== $cap) {
                        $add('summary_horses', $no, 'OBSERVED_CAP',
                             'observed_cap が採点した項目の満点合計と合いません');
                        $okRow = false;
                    }
                    $expectedStatus = ($cap === array_sum(self::SCORE_CAP)) ? 'complete' : 'partial';
                    if (($h['score_status'] ?? null) !== $expectedStatus
                        || ($expectedStatus === 'complete'
                            && (!is_int($h['total_score'] ?? null) || $h['total_score'] !== $sum))
                        || ($expectedStatus === 'partial' && ($h['total_score'] ?? null) !== null)) {
                        $add('summary_horses', $no, 'TOTAL_SCORE',
                             'score_status または total_score が有効な採点項目と合いません');
                        $okRow = false;
                    }
                }
            }

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-SCOREEV  採点と出典の結びつき
            // ══════════════════════════════════════════════════════════
            // 【10月20日005の5章末・10-1】
            //   採点の算数（満点・合計・observed_sum/cap・total_score）は
            //   上の段ですでに見ています。<b>見ていなかったのは、
            //   その点数が何を根拠に付いたか</b>でした。
            //     ・点が付いているのに出典が空 … 根拠なしの採点です
            //     ・採点していないのに理由が空 … なぜ採れなかったか分かりません
            //     ・出典が実在しない／この馬のものでない
            //
            //   どの資料がどの項目を裏づけるかの対応（接続表）は設計書に
            //   書きました。<b>ここで項目ごとに資料の種類まで決め打ちにはしません。</b>
            //   決め打ちにすると、新しい資料が増えたときに正しい採点を
            //   誤って弾くためです。見るのは「実在・所有・空でないこと」です。
            $se = $h['score_evidence'] ?? null;
            if (!is_array($se)) {
                $add('horses', $no, 'SCORE_EVIDENCE_TYPE',
                     'score_evidence がありません');
                // No item has verifiable score evidence when the container is unusable.
                $markScore($no, array_keys(self::SCORE_CAP));
                $okRow = false;
            } else {
                $exSe = array_values(array_diff(array_keys($se),
                                                array_keys(self::SCORE_CAP)));
                if ($exSe !== []) {
                    $add('horses', $no, 'SCORE_EVIDENCE_UNKNOWN_KEY',
                         '採点辞書に無い項目の出典があります（'
                         . implode('・', $exSe) . '）');
                    $markScore($no, $exSe);           // @ANCHOR-...-SCOREKEY
                    $okRow = false;
                }
                foreach (self::SCORE_CAP as $k => $mx) {
                    if (!array_key_exists($k, $se)) {
                        $add('horses', $no, 'SCORE_EVIDENCE_MISSING',
                             $k . 'の出典欄がありません');
                        $markScore($no, [$k]);        // @ANCHOR-...-SCOREKEY
                        $okRow = false;
                        continue;
                    }
                    $ent = (array) ($se[$k] ?? []);
                    $reason = is_string($ent['reason'] ?? null)
                            ? trim((string) $ent['reason']) : '';
                    $srf = [];
                    foreach ((array) ($ent['source_refs'] ?? []) as $x) {
                        if (is_string($x)) { $srf[] = $x; }
                    }
                    $v = is_array($sc) ? ($sc[$k] ?? null) : null;
                    if (BaganrikiScoreMaterials::handlesFamilies($src['prompt_version'] ?? null) && array_diff(array_keys($ent), ['reason','source_refs']) !== []) {
                        $add('summary_horses', $no, 'EXPECTED_SCORE_PROOF_FIELDS', $k . 'の出典欄に未定義の項目があります');
                        $okRow = false;
                    }
                    if ($v !== null && BaganrikiScoreMaterials::handlesVersion($src['prompt_version'] ?? null)) {
                        $w2ScoreCache ??= [];
                        try {
                            $material = BaganrikiSourceEvidence::scoreKnown($src, $no, $k, $v, $ent, $w2ScoreCache);
                        } catch (\InvalidArgumentException $invalidSource) { $material = false; }
                        if (!$material) {
                            $add('summary_horses', $no, 'EXPECTED_SCORE_MATERIAL', $k . 'の採点に対応する資料が不足しています');
                            $markScore($no, [$k]);
                            $okRow = false;
                        }
                    }
                    // Zero also claims that the item was observed and scored.
                    // Without a source it must stay unknown, not make the band complete.
                    if (is_int($v) && $v >= 0 && $srf === []) {
                        $add('summary_horses', $no, 'SCORE_WITHOUT_EVIDENCE',
                             $k . 'に点が付いているのに出典がありません');
                        $markScore($no, [$k]);        // @ANCHOR-...-SCOREKEY
                        $okRow = false;
                    }
                    if ($v === null && $reason === '') {
                        $add('summary_horses', $no, 'SCORE_NULL_WITHOUT_REASON',
                             $k . 'を採点していないのに理由がありません');
                        $markScore($no, [$k]);        // @ANCHOR-...-SCOREKEY
                        $okRow = false;
                    }
                    if ($reg !== []) {
                        $uk = []; $nw = [];
                        foreach ($srf as $rf) {
                            $e2 = $reg[$rf] ?? null;
                            if (!is_array($e2)) { $uk[] = $rf; continue; }
                            $sc3 = (string) ($e2['scope'] ?? '');
                            $hn3 = array_map('intval', (array) ($e2['horse_nos'] ?? []));
                            $ap3 = array_map('intval', (array) ($e2['applicable_horse_nos'] ?? []));
                            if (!(($sc3 === 'RACE')
                                  || (in_array($sc3, ['HORSE','PAIR'], true) && in_array($no, $hn3, true))
                                  || ($sc3 === 'COHORT' && in_array($no, $ap3, true)))) {
                                $nw[] = $rf;
                            }
                        }
                        if ($uk !== []) {
                            $add('horses', $no, 'SCORE_EVIDENCE_REF_UNKNOWN',
                                 $k . 'の出典に固定入力に無いIDがあります（'
                                 . implode('・', array_slice($uk, 0, 3)) . '）');
                            $markScore($no, [$k]);    // @ANCHOR-...-SCOREKEY
                        }
                        if ($nw !== []) {
                            $add('horses', $no, 'SCORE_EVIDENCE_REF_NOT_OWNED',
                                 $k . 'の出典にこの馬のものでないIDがあります（'
                                 . implode('・', array_slice($nw, 0, 3)) . '）');
                            $markScore($no, [$k]);    // @ANCHOR-...-SCOREKEY
                        }
                    }
                }
            }

            // 帯は凍結入力が正
            if (isset($frozenBand[$no]) && $frozenBand[$no] !== ''
                && ($h['band'] ?? null) !== $frozenBand[$no]) {
                $add('summary_horses', $no, 'BAND_MISMATCH',
                     '帯が凍結入力と違います');
                $okRow = false;
            }
            // 5系統レビュー
            $fr = $h['family_review'] ?? null;
            $sup = false;
            if (!is_array($fr)) {
                $add('summary_horses', $no, 'FAMILY_TYPE', 'family_review がありません');
                $okRow = false;
            } else {
                foreach (self::FAMILIES as $fam) {
                    if (!isset($fr[$fam]) || !is_array($fr[$fam])) {
                        $add('summary_horses', $no, 'FAMILY_MISSING',
                             $fam . ' のレビューがありません');
                        $okRow = false;
                        continue;
                    }
                    $st = $fr[$fam]['status'] ?? null;
                    if (!in_array($st, self::FAMILY_STATUS, true)) {
                        $add('summary_horses', $no, 'FAMILY_STATUS',
                             $fam . ' の status が決められた中にありません');
                        $okRow = false;
                        continue;
                    }
                    if ($st === 'SUPPORTED') { $sup = true; }
                    if ($st === 'NO_SUPPORT' && BaganrikiScoreMaterials::usesR14Rules($src['prompt_version'] ?? null)) {
                        $w2ScoreCache ??= [];
                        if (!BaganrikiSourceEvidence::familyKnown(
                            $src, $no, $fam, $fr[$fam]['source_refs'] ?? null, $w2ScoreCache, false
                        )) {
                            // If this decision removed the horse, allow a supported row to be restored and the same band's display to follow.
                            $scopeName = ($h['eligibility'] ?? null) === 'ELIGIBLE' ? 'summary_horses' : 'horses';
                            $add($scopeName, $no, 'EXPECTED_NO_SUPPORT_MATERIAL', $fam . 'の見送りを判定する資料が足りません');
                            if ($scopeName === 'horses' && isset($frozenBand[$no])) {
                                foreach ($frozenBand as $peer => $band) {
                                    if ($band === $frozenBand[$no] && !in_array($peer, $scope['presentation_horses'], true))
                                        $scope['presentation_horses'][] = $peer;
                                }
                            }
                            $okRow = false;
                        }
                    }
                }
                $extraFam = array_values(array_diff(array_keys($fr), self::FAMILIES));
                if ($extraFam !== []) {
                    $add('summary_horses', $no, 'FAMILY_UNKNOWN',
                         '5系統に無いものがあります（' . implode('・', $extraFam) . '）');
                    $okRow = false;
                }
            }
            // 資格と5系統レビューの整合
            $elig = $h['eligibility'] ?? null;
            if (in_array($elig, self::ENUMS['eligibility'], true) && is_array($fr)) {
                if (($elig === 'ELIGIBLE') !== $sup) {
                    $add('summary_horses', $no, 'ELIGIBILITY_VS_FAMILY',
                         $sup ? '支持する系統があるのに ELIGIBLE ではありません'
                              : '支持する系統が1つも無いのに ELIGIBLE です');
                    $okRow = false;
                }
            }
            // 非適格馬の順位欄
            if ($elig !== null && $elig !== 'ELIGIBLE'
                && ($h['rank_basis'] ?? null) !== 'NOT_RANKED') {
                $add('summary_horses', $no, 'NOT_RANKED',
                     '非適格馬の rank_basis は NOT_RANKED です');
                $okRow = false;
            }

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-REFCHECK  参照根拠の意味検証
            // ══════════════════════════════════════════════════════════
            // 【10月20日005の5章末。未実装として残っていた分です】
            //   これまでは各馬の事実出典hashを<b>記録</b>するだけで、
            //   「その参照IDが固定入力に実在するか」を見ていませんでした。
            //   AIが参照IDを作り話で書いても、気づかずに通ります。
            //
            //   見るのは3つです。
            //     ① 実在   … source_registry にそのIDがあるか
            //     ② 所有馬 … その登録がこの馬に当てはまるか
            //                 HORSE/PAIR は horse_nos、COHORT は applicable_horse_nos、
            //                 RACE は全馬に当てはまります
            //     ③ 自分のSIGNAL … 適用されるHORSE/PAIR/COHORT×SIGNALが1系統でもあるか
            //
            //   契約7章「RACE情報のみ、CONTEXTのみでは資格を作らない」が③です。
            //   逆に「確認済み個別SIGNALが1系統あれば候補資格を保持する」ので、
            //   <b>①②で汚れがあっても、③が立っていれば行は落としません。</b>
            //   汚れたところだけ repair_scope に入れて訂正へ回します。
            $refs = [];
            self::collectRefs($h, $refs);
            $refs = array_values(array_unique($refs));
            sort($refs, SORT_STRING);
            $unknownRef = []; $notOwned = []; $ownSignal = 0;
            foreach ($refs as $rf) {
                $e = $reg[$rf] ?? null;
                if (!is_array($e)) { $unknownRef[] = $rf; continue; }
                $sc2 = (string) ($e['scope'] ?? '');
                $hn  = array_map('intval', (array) ($e['horse_nos'] ?? []));
                $ap  = array_map('intval', (array) ($e['applicable_horse_nos'] ?? []));
                $fit = ($sc2 === 'RACE')
                    || (in_array($sc2, ['HORSE','PAIR'], true) && in_array($no, $hn, true))
                    || ($sc2 === 'COHORT' && in_array($no, $ap, true));
                if (!$fit) { $notOwned[] = $rf; continue; }
                if (in_array($sc2, ['HORSE','PAIR','COHORT'], true)
                    && (string) ($e['dependency_role'] ?? '') === 'SIGNAL') {
                    $ownSignal++;
                }
            }
            // An empty or missing registry is not proof of an own-horse signal.
            if ($unknownRef !== []) {
                $add('horses', $no, 'EVIDENCE_REF_UNKNOWN',
                     '固定入力に無い参照IDがあります（'
                     . implode('・', array_slice($unknownRef, 0, 3))
                     . (count($unknownRef) > 3
                        ? '…ほか' . (count($unknownRef) - 3) . '件' : '') . '）');
            }
            if ($notOwned !== []) {
                $add('horses', $no, 'EVIDENCE_REF_NOT_OWNED',
                     'この馬に当てはまらない参照IDがあります（'
                     . implode('・', array_slice($notOwned, 0, 3))
                     . (count($notOwned) > 3
                        ? '…ほか' . (count($notOwned) - 3) . '件' : '') . '）');
            }
            if (BaganrikiScoreMaterials::handlesFamilies($src['prompt_version'] ?? null)) {
                $w2ScoreCache ??= [];
                foreach ((array) ($h['evidence'] ?? []) as $item) {
                    if (!is_array($item) || !BaganrikiSourceEvidence::familyKnown(
                        $src, $no, $item['family'] ?? null, $item['source_refs'] ?? null, $w2ScoreCache
                    )) {
                        $add('horses', $no, 'EXPECTED_EVIDENCE_FAMILY', '根拠系統に対応するこの馬の資料がありません');
                        $okRow = false;
                    }
                }
            }
            // ③ 資格を名乗るなら、自分のSIGNALが1系統は要ります
            if ($elig === 'ELIGIBLE' && $ownSignal === 0) {
                $add('summary_horses', $no, 'EVIDENCE_NO_OWN_SIGNAL',
                     'この馬自身のSIGNALが1系統もありません'
                     . '（RACE情報だけ・CONTEXTだけでは資格になりません）');
                $okRow = false;
            }
            $refStats[$no] = ['refs' => count($refs), 'own_signal' => $ownSignal,
                              'unknown' => count($unknownRef),
                              'not_owned' => count($notOwned)];

            // Rank mode follows the scores that survive validation and local salvage.
            // A claimed 'complete' must not preserve SCORE order after an item is nulled.
            $scoreComplete[$no] = is_array($sc);
            foreach (self::SCORE_CAP as $key => $max) {
                $value = is_array($sc) ? ($sc[$key] ?? null) : null;
                if (!is_int($value) || $value < 0 || $value > $max
                    || isset($badScore[$no][$key])) {
                    $scoreComplete[$no] = false; break;
                }
            }
            $rowValid[$no] = $okRow;
            // Match the normal-row retention rule used by inspectAiResponse:
            // a score-only failure may be salvaged; invalid eligibility/identity may not.
            $rowCodes = [];
            foreach ($find as $finding) {
                if ($finding['target'] === $no) { $rowCodes[] = $finding['code']; }
            }
            $salvageable = $ownSignal > 0 && $rowCodes !== []
                && array_diff($rowCodes, array_merge(AiController::SCORE_ONLY_ERROR_CODES,
                    ['EVIDENCE_REF_UNKNOWN', 'EVIDENCE_REF_NOT_OWNED'])) === [];
            $rankEligible[$no] = $elig === 'ELIGIBLE' && ($okRow || $salvageable);
        }

        // ═══ ③ 順位関連項目（band_ranks / evidence_band_ranks）═══════
        foreach (['band_ranks', 'evidence_band_ranks'] as $key) {
            $br = $res[$key] ?? null;
            if (!is_array($br)) {
                $add('rank_bands', $key, 'BANDS_TYPE', $key . 'がありません');
                continue;
            }
            $extraB = array_values(array_diff(array_keys($br), self::BANDS));
            if ($extraB !== []) {
                $add('rank_bands', $key, 'BANDS_UNKNOWN',
                     'A・B・D 以外の帯があります（' . implode('・', $extraB) . '）');
            }
            foreach (self::BANDS as $b) {
                $want = [];
                foreach ($frozenBand as $no => $bb) {
                    if ($bb === $b && (!$w2Projection || ($byNo[$no]['eligibility'] ?? null) === 'ELIGIBLE')) { $want[] = (int) $no; }
                }
                sort($want);
                $list = $br[$b] ?? null;
                if (!is_array($list) || !array_is_list($list) || count(array_filter($list,'is_int')) !== count($list)) {
                    $add('rank_bands',$b,'BAND_TYPE',$b.'帯の順位は整数馬番の配列です');
                    continue;
                }
                $got = $list;
                $gotSorted = $got; sort($gotSorted);
                if (count(array_unique($got)) !== count($got)) {
                    $add('rank_bands', $b, 'BAND_DUPLICATED',
                         $b . '帯の順位に同じ馬番があります');
                    continue;
                }
                if ($gotSorted !== $want) {
                    $add('rank_bands', $b, 'BAND_SET',
                         $b . '帯の馬番の集合が凍結入力と違います');
                }
            }
        }
        // 方式（検証後の適格馬だけで判定。全適格馬completeならSCORE、それ以外はEVIDENCE）
        $br = (array) ($res['band_ranks'] ?? []);
        foreach (self::BANDS as $b) {
            $nums = array_values(array_filter((array) ($br[$b] ?? []),'is_int'));
            if ($nums === []) { continue; }
            $allComplete = true; $have = false;
            foreach ($nums as $no) {
                if (!($rankEligible[$no] ?? false)) { continue; }
                $have = true;
                if (!($scoreComplete[$no] ?? false)) {
                    $allComplete = false;
                }
            }
            if (!$have) { continue; }
            $wantBasis = $allComplete ? 'SCORE' : 'EVIDENCE';
            foreach ($nums as $no) {
                if (!isset($byNo[$no])) { continue; }
                if (!($rankEligible[$no] ?? false)) { continue; }
                if (($byNo[$no]['rank_basis'] ?? null) !== $wantBasis) {
                    $add('rank_bands', $b, 'BASIS_MISMATCH',
                         $b . '帯は' . $wantBasis . 'のはずです（馬番 ' . $no . '）');
                    break;
                }
            }
        }
        // EVIDENCE の帯は両方の順位を一致させます
        if (isset($res['band_ranks'], $res['evidence_band_ranks'])
            && is_array($res['band_ranks']) && is_array($res['evidence_band_ranks'])) {
            foreach (self::BANDS as $b) {
                $a1 = array_values(array_filter((array) ($res['band_ranks'][$b] ?? []),'is_int'));
                $a2 = array_values(array_filter((array) ($res['evidence_band_ranks'][$b] ?? []),'is_int'));
                if ($a1 === [] && $a2 === []) { continue; }
                $allComplete = true; $have = false;
                foreach ($a1 as $no) {
                    if (!($rankEligible[$no] ?? false)) { continue; }
                    $have = true;
                    if (!($scoreComplete[$no] ?? false)) {
                        $allComplete = false;
                    }
                }
                if ($have && !$allComplete && $a1 !== $a2) {
                    $add('rank_bands', $b, 'EVIDENCE_ORDER',
                         $b . '帯は点数を順位キーに使えないので'
                         . '両方の順位をそろえます');
                }
            }
        }

        // ═══ ④ 公開リスト ═══════════════════════════════════════════
        $projectionReady = $w2Projection && $find === [];
        $pc = $res['public_candidates'] ?? null;
        if (!is_array($pc) || !array_is_list($pc) || count(array_filter($pc,'is_int')) !== count($pc)) {
            $add('public_candidates', 'public_candidates', 'PC_TYPE',
                 'public_candidates がありません');
            $pc = [];
        } else {
            $pc = array_map('intval', $pc);
            if (count(array_unique($pc)) !== count($pc)) {
                $add('public_candidates', 'public_candidates', 'PC_DUPLICATED',
                     '公開リストに同じ馬番があります');
            }
            $out = array_values(array_diff($pc, $frozenNums));
            if ($frozenNums !== [] && $out !== []) {
                $add('public_candidates', 'public_candidates', 'PC_UNKNOWN',
                     '公開リストに凍結集合にいない馬番があります（'
                     . implode('・', $out) . '）');
            }
            if (!$selEnabled && $pc !== []) {
                $add('public_candidates', 'public_candidates', 'PC_NOT_EMPTY',
                     '選出しない状態なので公開リストは空です');
            }
            if ($w2Projection) {
                if (!$w15 && $projectionReady) {
                    BaganrikiCandidateSelection::diagnoseSelection($src, $res, $add);
                }
            } elseif ($selEnabled) {
                // 帯の先頭から枠数ぶん、適格馬だけを取ります（飛ばし取りはしません）
                $q = (array) ($res['role_quota'] ?? []);
                $want = [];
                foreach (self::BANDS as $b) {
                    $k = is_int($q[$b] ?? null) ? $q[$b] : 0;
                    $taken = 0;
                    foreach (array_values(array_filter((array) ($br[$b] ?? []),'is_int')) as $no) {
                        if ($taken >= $k) { break; }
                        if (($byNo[$no]['eligibility'] ?? null) === 'ELIGIBLE') {
                            $want[] = $no; $taken++;
                        }
                    }
                }
                if ($want !== $pc) {
                    $add('public_candidates', 'public_candidates', 'PC_ORDER',
                         '帯の先頭から枠数ぶん取っていません（期待 '
                         . implode('-', $want) . '）');
                }
            }
        }

        if ($w15) {
            BaganrikiCandidateSelection::inspectSelection($src, $res, $find, $add);
        }
        // ═══ ⑤ 公開フラグと非選出理由 ═══════════════════════════════
        //   ここでの不一致では horses を広げません（契約6章）。
        foreach ($byNo as $no => $h) {
            $flag = $h['public_candidate'] ?? null;
            if (is_bool($flag) && $flag !== in_array((int) $no, $pc, true)) {
                $add('presentation_horses', $no, 'FLAG_VS_LIST',
                     'public_candidate が公開リストと合いません');
            }
            $nsr = $h['nonselect_reason'] ?? null;
            if ($flag === true) {
                if ($nsr !== null) {
                    $add('presentation_horses', $no, 'NSR_PRESENT',
                         '公開する馬に非選出理由があります');
                }
                continue;
            }
            if ($nsr === null) {
                $add('presentation_horses', $no, 'NSR_MISSING',
                     '公開しない馬に非選出理由がありません');
                continue;
            }
            if (!is_array($nsr)) {
                // 配列・objectでない値でも、この馬の表示項目だけの話にします
                $add('presentation_horses', $no, 'NSR_TYPE',
                     '非選出理由の形が違います');
                continue;
            }
            $code = $nsr['code'] ?? null;
            if (!is_string($code) || !in_array($code, self::NONSELECT_CODES, true)) {
                $add('presentation_horses', $no, 'NSR_CODE',
                     '非選出理由の code が決められた中にありません');
                continue;
            }
            if (!isset($nsr['reason']) || !is_string($nsr['reason'])
                || trim($nsr['reason']) === '') {
                $add('presentation_horses', $no, 'NSR_REASON',
                     '非選出理由の説明が空です');
            }
            if ($w15 && $selEnabled && is_string($h['eligibility'] ?? null) &&
                $code !== BaganrikiCandidateSelection::nonselectCode($src, $h['eligibility'])) {
                $add('presentation_horses', $no, 'EXPECTED_NONSELECT_CODE', '枠外、根拠なし、資料不足を当該馬の資格に合わせてください');
            }
            if (!$selEnabled && $code !== 'SELECTION_DISABLED') {
                $add('presentation_horses', $no, 'NSR_DISABLED',
                     '選出しない状態の非選出理由は SELECTION_DISABLED です');
            }
        }

        if ($w15) {
            // Keep the problem in findings, but never issue a grant for an unknown horse or a dictionary name.
            $scope['horses'] = array_values(array_intersect($frozenNums, $scope['horses']));
            if (in_array('BANDS_TYPE', array_column($find, 'code'), true)) $scope['rank_bands'] = self::BANDS;
            else $scope['rank_bands'] = array_values(array_intersect(self::BANDS, $scope['rank_bands']));
            foreach (['presentation_horses','summary_horses'] as $kind)
                $scope[$kind] = array_values(array_intersect($frozenNums, $scope[$kind]));
        }
        sort($scope['horses']);
        sort($scope['presentation_horses']);
        sort($scope['summary_horses']);
        sort($scope['top_fields']);
        sort($unknown);
        return [
            'ok' => ($find === []),
            'validator_version' => self::revision($src['prompt_version'] ?? null),
            'row_valid'    => $rowValid,
            'repair_scope' => $scope,
            'findings'     => $find,
            'missing'      => $missing,
            'duplicated'   => $dup,
            'unknown'      => $unknown,
            // @ANCHOR-20261020-PREDICTION-REFCHECK  馬ごとの参照の内訳
            'ref_stats'    => $refStats,
            // @ANCHOR-20261020-PREDICTION-SCOREKEY  馬ごとの「おかしい採点項目」
            //   救済はこの項目だけを null にします（全部ではありません）。
            'bad_scores'   => array_map(
                static fn($m) => array_values(array_keys((array) $m)), $badScore),
        ];
    }

    public static function revision(mixed $release):string
    {
        return $release === BaganrikiCandidateSelection::VERSION
            ? BaganrikiResponseInspection::REVISION : self::VERSION;
    }

    /**
     * 診断の見出し（監査やログに1行で残すため）
     */
    public static function summary(array $d): string
    {
        if ($d['ok']) { return '直すところはありません'; }
        $by = [];
        foreach ($d['findings'] as $f) {
            $by[$f['scope']] = ($by[$f['scope']] ?? 0) + 1;
        }
        $p = [];
        foreach ($by as $k => $n) { $p[] = $k . ' ' . $n . '件'; }
        return implode('／', $p);
    }

    /**
     * @ANCHOR-20261020-PREDICTION-REFCHECK  入れ子のどこにある参照IDも拾います
     *
     *   応答の形は role や版で変わり得るので、キーの名前で拾います。
     *   `source_ref`（1件）と `source_refs`（配列）の両方を見ます。
     *   拾いすぎても、実在して所有が合っていれば何も起きません。
     */
    private static function collectRefs($node, array &$out): void
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if ($k === 'source_ref' && is_string($v)) { $out[] = $v; continue; }
                if ($k === 'source_refs' && is_array($v)) {
                    foreach ($v as $x) { if (is_string($x)) { $out[] = $x; } }
                    continue;
                }
                self::collectRefs($v, $out);
            }
        }
    }
}

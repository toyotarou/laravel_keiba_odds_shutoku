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
 * BaganrikiInput  AIへ渡す入力（INPUT_JSON）の材料を集める：出馬表・オッズ・過去走・市場の事前分布
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BaganrikiInput
{
    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  新方式 の race_id（YYYYMMDD-回-場-日-R）
     * ═══════════════════════════════════════════════════════════════════════
     */
    public static function buildPredictionRaceId($date, $kaisuu, $basho, $day, $race): string
    {
        return str_replace('-', '', (string) $date) . '-' . (string) $kaisuu . '-'
             . (string) $basho . '-' . (string) $day . '-' . (int) $race;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  INPUT_JSON の材料を正本から集める
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 2章〜5章・11章／詳細設計書 4章・14章
     *
     * ■ 作り話をしないための決まり
     *   ・投票締切 sales_close_at の正本がDBに無いので、既定では作りません
     *     （契約11.2）。$override['sales_close_at'] に正本を渡したときだけ
     *     時刻契約を作ります。発走時刻で代用しません。
     *   ・取得監査の実時刻（request_started_at / response_received_at /
     *     available_at / batch_id / raw_payload）も既定ではDBにありません。
     *     baganriki_acquisition_batches に確定行があればそれを使います。
     *     無ければ作りません。$override['assume_audit'] を立てたときだけ、
     *     「予定時刻で埋めた仮の監査」で組み立て、結果に assumed を立てます
     *     （本番送信には使いません。送信文の形を確かめる用です）。
     *
     * ■ 取消の扱い
     *   このDBは取消馬のオッズ列へ「取消」「除外」の文字を入れています。
     *   情報上限（6分前+30秒）までの時点に印があるものを正式取消とし、
     *   凍結集合から外します。3分前・発走時だけに印があるものは
     *   凍結後の取消なので、凍結集合には残し cancelled_post として返します。
     *
     * @return array ['ok','ctx','code','reason','race_id','notes']
     */
    public static function gatherPredictionContext($date, $kaisuu, $basho, $day, $race,
                                             array $override = []): array
    {
        $raceId = AiController::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);
        $ng = static fn(string $code, string $why) =>
            ['ok' => false, 'ctx' => null, 'code' => $code, 'reason' => $why,
             'race_id' => $raceId, 'notes' => []];
        $notes = [];

        // ── 正本のレース情報（発走時刻） ───────────────────────────────
        $r = \DB::table('t_horse_odds_finder_races')
                ->where('date', $date)->where('kaisuu', $kaisuu)
                ->where('basho', $basho)->where('day', $day)
                ->where('race', (int) $race)->first();
        if ($r === null)  return $ng('ENTRY_MISSING', 'このレースは正本にありません');
        $st = trim((string) ($r->start_time ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $st)) {
            return $ng('TIME_CONTRACT_INVALID', '発走時刻を読めません: ' . $st);
        }
        $st = BaganrikiTime::normalizeRaceStartClock($st);
        $anchorAt = (string) $date . 'T' . $st . '+09:00';
        $anchorUs = BaganrikiTime::us($anchorAt);
        if ($anchorUs === null) {
            return $ng('TIME_CONTRACT_INVALID', '発走時刻を組み立てられません: ' . $anchorAt);
        }

        // ── 出馬表（正本の馬番） ──────────────────────────────────────
        $entryNums = [];
        // @ANCHOR-20261020-PREDICTION-PAST  馬名も取ります（過去走を引くため）
        $entryNames = [];
        foreach (\DB::table('t_horse_odds_finder_horses')
                    ->where('date', $date)->where('kaisuu', $kaisuu)
                    ->where('basho', $basho)->where('day', $day)
                    ->where('race', (int) $race)->get(['num', 'name']) as $h) {
            $rawNo = $h->num ?? null;
            $digits = (is_int($rawNo) || is_string($rawNo)) ? trim((string) $rawNo) : '';
            $n = preg_match('/^\d+\z/', $digits)
                ? filter_var(ltrim($digits, '0'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])
                : false;
            if ($n === false) {
                return $ng('ENTRY_SET_MISMATCH', '出馬表に読めない馬番があります');
            }
            $nm = trim((string) ($h->name ?? ''));
            if (isset($entryNums[$n]) && ($entryNames[$n] ?? '') !== $nm) {
                return $ng('ENTRY_SET_MISMATCH', '出馬表の重複した馬番で馬名が矛盾しています（馬番 ' . $n . '）');
            }
            if ($n > 0) {
                $entryNums[$n] = true;
                if ($nm !== '') { $entryNames[$n] = $nm; }
            }
        }
        if ($entryNums === []) {
            return $ng('ENTRY_MISSING', '出馬表の馬番がありません（契約2章で必須）');
        }
        ksort($entryNums);
        $entryNums = array_keys($entryNums);
        // A declared race count is independent evidence, not the count of the
        // odds that happened to arrive. Null remains the legacy unknown case.
        $declaredRaw = $r->num_horses ?? null;
        if ($declaredRaw !== null) {
            $declared = (is_int($declaredRaw) || is_string($declaredRaw))
                ? filter_var($declaredRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])
                : false;
            if ($declared === false || $declared !== count($entryNums)) {
                return $ng('ENTRY_SET_MISMATCH',
                    'レース情報の出走頭数と出馬表の頭数が合わないか、宣言頭数を読めません');
            }
        }

        // ── オッズ（信号に使う6時点＋取消の見張り2時点） ───────────────
        $first = \App\Constants\Constants::ODDS_DB_FIRST;
        $last  = \App\Constants\Constants::ODDS_DB_LAST;
        $slots = BaganrikiTime::INPUT_SLOTS;                    // 21,18,15,12,9,6
        $watch = BaganrikiTime::WATCH_SLOTS;                    // 3,0
        $read  = array_merge($slots, [3, $last, $first]);

        $cells = [];          // [馬番][スロット][フィールド] => 元の文字列
        $cancelPre = [];      // 情報上限までに取消の印があった馬番
        $cancelPost = [];     // 3分前・発走時だけに印があった馬番
        foreach (\DB::table('t_horse_odds_finder_odds')
                    ->where('date', $date)->where('kaisuu', $kaisuu)
                    ->where('basho', $basho)->where('day', $day)
                    ->where('race', (int) $race)
                    ->whereIn('minutes_before_start', $read)
                    ->get(['num', 'minutes_before_start', 'odds', 'fuku_min', 'fuku_max'])
                 as $o) {
            $n = (int) $o->num;
            $t = (int) $o->minutes_before_start;
            $raw = trim((string) $o->odds);
            if ($raw === '取消' || $raw === '除外') {
                if (in_array($t, $slots, true))      $cancelPre[$n]  = true;
                elseif ($t === 3 || $t === $last)    $cancelPost[$n] = true;
                continue;
            }
            if (!in_array($t, $slots, true)) continue;          // 将来のオッズは混ぜない
            if (BaganrikiAcquisition::usableDecimal($o->odds)) {
                $cells[$n][$t]['win_odds'] = trim((string) $o->odds);
            }
            if (BaganrikiAcquisition::usableDecimal($o->fuku_min)) {
                $cells[$n][$t]['place_min'] = trim((string) $o->fuku_min);
            }
            if (BaganrikiAcquisition::usableDecimal($o->fuku_max)) {
                $cells[$n][$t]['place_max'] = trim((string) $o->fuku_max);
            }
        }
        $cancelled = array_keys($cancelPre);
        sort($cancelled);
        $cancelledPost = array_values(array_diff(array_keys($cancelPost), $cancelled));
        sort($cancelledPost);

        // ── 取得監査（実時刻）────────────────────────────────────────
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-ACQFIX
        // ══════════════════════════════════════════════════════════════
        // 2-1 DBの日時を RFC3339 へ直していませんでした。
        //     DBは「2026-10-18 15:04:00.123456」で保存します。
        //     BaganrikiTime::us は T区切りとタイムゾーンを必須にするので、
        //     そのまま渡すと実際の監査行が必ず TIME_UNREADABLE で落ちます。
        //     マイクロ秒を保ったまま RFC3339（+09:00）へ変換します。
        // 2-2 input_freeze_at を採用判定へ渡していませんでした。
        //     acceptBatch はこのキーを必須で読みます。
        //     正本の発走時刻から出した情報上限を渡し、
        //     バッチが自己申告する予定時刻が正本と一致するかも検査します。
        // 2-3 採用バッチの原値ではなく、オッズ表の共通の値を使っていました。
        //     採用した batch_id の raw_payload を厳密に読み、
        //     SHA-256 を再計算して payload_hash と照合し、
        //     <b>そのバッチの値だけ</b>を入力に使います。
        //     別のバッチの馬や値が混ざらないようにします。
        $assumeAudit = (bool) ($override['assume_audit'] ?? false);
        // $freezeUs    = BaganrikiTime::inputFreeze($anchorUs);   // 旧（T−300秒固定）
        // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  情報上限は、凍結する側が決めた時刻（override の input_freeze_us）。無ければ今の時刻から決める
        $timeVersion = BaganrikiTime::timeContractVersion();
        $freezeUs    = (isset($override['input_freeze_us']) && is_int($override['input_freeze_us']))
                     ? $override['input_freeze_us']
                     : BaganrikiTime::freezeFor($anchorUs, BaganrikiWorker::nowUs(), $timeVersion);
        if (!BaganrikiTime::freezeInWindow($anchorUs, $freezeUs, $timeVersion)) {
            return $ng('TIME_CONTRACT_INVALID', '情報上限が決まりの範囲にありません（' . BaganrikiTime::toJst($freezeUs) . '）');
        }
        $freezeAt    = BaganrikiPrompt::rfc3339($freezeUs);
        $audit  = null;
        $batchCells = [];     // 採用バッチの原値 [馬番][スロット][項目]
        $batchCancel = [];    // 採用バッチの中の取消 [馬番] => [スロット]
        if (\Schema::hasTable('baganriki_acquisition_batches')) {
            $rows = \DB::table('baganriki_acquisition_batches')
                       ->where('race_id', $raceId)->get();
            if (count($rows) > 0) {
                $bySlot = [];
                foreach ($rows as $b) {
                    $slot = (int) $b->slot;
                    if (!in_array($slot, $slots, true)) continue;   // 3分前・発走時は入力に使わない
                    $raw  = (string) ($b->raw_payload ?? '');
                    $hash = (string) ($b->payload_hash ?? '');
                    $bad  = null;
                    if ($raw === '')                                  $bad = '原ペイロードがありません';
                    elseif ($hash === '')                             $bad = 'payload_hash がありません';
                    elseif (hash('sha256', $raw) !== $hash)           $bad = '原ペイロードのSHA-256が保存hashと違います';
                    // 予定時刻は正本の発走時刻から作ったものと一致させます
                    $schAt = BaganrikiPrompt::rfc3339(BaganrikiTime::slotScheduled($anchorUs, $slot));
                    // @ANCHOR-20261023-NARU-011  日時の読み替え（011再精査8版）
                    //   読めない日時を「無いもの」にせず、そのバッチだけを不採用にします。
                    //   同じ予定時刻の小数秒の書き方の違いは、マイクロ秒で比べて同じとみなします。
                    $declRaw = $b->slot_scheduled_at ?? null;
                    $decl    = AiController::dbTimeToRfc3339($declRaw);
                    $updatedRaw      = $b->source_updated_at ?? null;
                    $sourceUpdatedAt = AiController::dbTimeToRfc3339($updatedRaw);
                    if ($bad === null && $declRaw !== null && $decl === null) {
                        $bad = 'slot_scheduled_at が日時として読めません';
                    } elseif ($bad === null && $updatedRaw !== null && $sourceUpdatedAt === null) {
                        $bad = 'source_updated_at が日時として読めません';
                    }
                    if ($bad === null && $decl !== null
                        && BaganrikiTime::us($decl) !== BaganrikiTime::us($schAt)) {
                        $bad = '自己申告の予定時刻が正本の発走時刻から出した値と違います（'
                             . $decl . ' / ' . $schAt . '）';
                    }
                    $vals = [];
                    $cncl = [];
                    if ($bad === null) {
                        // ══════════════════════════════════════════════
                        // @ANCHOR-20261023-NARU-RAWEXACT  原オッズを丸めずに読みます（なる 10/23 重要5・011再精査10版の1〜3）
                        // ══════════════════════════════════════════════
                        //   【旧】json_decode で読んでいたので、
                        //     ・引用符の無い長い小数が float に丸まる（2.0000…0001 と 2.0 が同じになる）
                        //     ・重複したキーは後の値で上書きされる（取消の記載が消える）
                        //   【いま】入力JSONと同じ厳密な読み取り（BaganrikiJson::strict）を使います。
                        //     丸まる数値は十進数の文字列のまま、重複キー・4 MiB超・深さ64超・
                        //     129桁以上・指数の範囲外・不正なUTF-8 はそのバッチを不採用にします。
                        //   原ペイロードと payload_hash は書き換えません。
                        $plR = BaganrikiJson::strict($raw);
                        $pl  = $plR['ok'] ? $plR['value'] : null;
                        if (!$plR['ok']) {
                            $bad = '原ペイロードのJSONが不正です（' . (string) $plR['code'] . '：'
                                 . (string) $plR['reason'] . '）';
                        } elseif (!is_array($pl)) {
                            $bad = '原ペイロードが行の集合ではありません';
                        } else {
                            // ══════════════════════════════════════════
                            // @ANCHOR-20261020-PREDICTION-PAYSET
                            // ══════════════════════════════════════════
                            //   【旧】馬番をキーに $vals へ<b>上書き</b>して
                            //     いました。同じ馬番が2回出てきても気づかず、
                            //     出馬表に無い馬番も素通りし、取消の行と数値の
                            //     行が併存していても最後に読んだ方が勝ちます。
                            //     005・006で求められていた集合検査が、
                            //     この場所に入っていませんでした。
                            //   【いま】読みながら数え、<b>理由を付けて不採用に
                            //     します。</b>バッチごとに見るので、1つの
                            //     バッチが汚れても他のバッチは使えます。
                            $seenNum = []; $dupNum = []; $unkNum = []; $bothNum = [];
                            // @ANCHOR-20261023-NARU-RAWEXACT  行と馬番を検査します（011再精査10版の2）
                            //   【旧】(int) で直接変換していたので「1x」・1.9・true も1番として扱い、
                            //     null 行・馬番なし・0番は黙って読み飛ばしていました。
                            //   【いま】行は配列であること、馬番は正の整数（JSONの整数か数字だけの文字列。
                            //     前後の空白と先頭の0は正規化）であることを確かめ、違えばバッチを不採用にします。
                            $invalidRow = false;
                            foreach ($pl as $h) {
                                if (!is_array($h)) { $invalidRow = true; continue; }
                                $rawNo  = $h['num'] ?? null;
                                $digits = (is_int($rawNo) || is_string($rawNo)) ? trim((string) $rawNo) : '';
                                $n = preg_match('/^\d+\z/', $digits)
                                    ? filter_var(ltrim($digits, '0'), FILTER_VALIDATE_INT,
                                                 ['options' => ['min_range' => 1]])
                                    : false;
                                if ($n === false) { $invalidRow = true; continue; }
                                if (isset($seenNum[$n])) { $dupNum[$n] = true; }
                                $seenNum[$n] = true;
                                if ($entryNums !== [] && !in_array($n, $entryNums, true)) {
                                    $unkNum[$n] = true;
                                }
                                // @ANCHOR-20261023-NARU-RAWEXACT  tan は型を保ったまま検査します（011再精査10版の3）
                                //   【旧】文字列へ一律変換していたので tan=true が「1」（単勝1倍）になり、
                                //     配列・object では変換エラーで処理が止まりました。
                                $t = $h['tan'] ?? null;
                                if (is_string($t)) { $t = trim($t); }
                                if ($t === '取消' || $t === '除外') {
                                    if (isset($vals[$n])) { $bothNum[$n] = true; }
                                    $cncl[] = $n;
                                    continue;
                                }
                                if (in_array($n, $cncl, true)) { $bothNum[$n] = true; }
                                $vals[$n] = [
                                    'win'       => BaganrikiAcquisition::usableDecimal($t)
                                                   ? BaganrikiAcquisition::decimalText($t) : null,
                                    'place_min' => BaganrikiAcquisition::usableDecimal($h['fuku_min'] ?? null)
                                                   ? BaganrikiAcquisition::decimalText($h['fuku_min']) : null,
                                    'place_max' => BaganrikiAcquisition::usableDecimal($h['fuku_max'] ?? null)
                                                   ? BaganrikiAcquisition::decimalText($h['fuku_max']) : null,
                                ];
                            }
                            $why = [];
                            if ($invalidRow) {
                                $why[] = '行の形または馬番が不正です';
                            }
                            if ($dupNum !== []) {
                                $why[] = '同じ馬番が2回以上あります（'
                                       . implode('・', array_keys($dupNum)) . '）';
                            }
                            if ($unkNum !== []) {
                                $why[] = '出馬表に無い馬番があります（'
                                       . implode('・', array_keys($unkNum)) . '）';
                            }
                            if ($bothNum !== []) {
                                $why[] = '取消の行と数値の行が同じ馬番で併存しています（'
                                       . implode('・', array_keys($bothNum)) . '）';
                            }
                            if ($why !== []) { $bad = implode('／', $why); }
                            $cncl = array_values(array_unique($cncl));
                        }
                    }
                    $bySlot[$slot][] = [
                        'batch_id'             => (string) $b->batch_id,
                        'payload_hash'         => $hash,
                        'slot_scheduled_at'    => $schAt,
                        'source_updated_at'    => $sourceUpdatedAt,
                        'request_started_at'   => AiController::dbTimeToRfc3339($b->request_started_at),
                        'response_received_at' => AiController::dbTimeToRfc3339($b->response_received_at),
                        'available_at'         => AiController::dbTimeToRfc3339($b->available_at),
                        'input_freeze_at'      => $freezeAt,
                        'committed'            => ((int) $b->committed === 1) && ($bad === null),
                        'payload_ng'           => $bad,
                        'values'               => $vals,
                        'cancelled'            => $cncl,
                    ];
                }
                if ($bySlot !== []) {
                    // 6分前の全頭確認は、出馬表から「そのバッチで取消でない馬」を活動馬とします
                    $picked = [];
                    foreach ($slots as $slot) {
                        $cand = $bySlot[$slot] ?? [];
                        // ══════════════════════════════════════════════
                        // @ANCHOR-20261020-PREDICTION-CANDCANCEL
                        // ══════════════════════════════════════════════
                        //   【本物の不具合でした】候補バッチ<b>全件</b>の取消を
                        //   先に差し引いて活動馬を作っていました。
                        //   期限を過ぎて不採用になるバッチの取消まで効くので、
                        //   「同じバッチに全活動馬の単勝がそろうか」という
                        //   6分前の検査が<b>弱まります。</b>
                        //   例：期限内のAに16番の単勝が無く、期限外のBだけに
                        //   16番の取消がある。16番が活動馬から落ちるので、
                        //   Aが全頭確認を通ってしまいます。
                        //   時刻の条件を満たしたバッチの取消だけを使います。
                        // ══════════════════════════════════════════════
                        // @ANCHOR-20261020-PREDICTION-COMMITFIRST
                        // ══════════════════════════════════════════════
                        //   【まだ残っていました】ここは acceptBatch（時刻）
                        //   しか見ていませんでした。<b>committed を見るのは
                        //   後段の pick です。</b>そのため、
                        //     ・Aは時刻適合・commit済み。16番の単勝だけ欠落
                        //     ・Bは時刻適合・<b>未commit</b>。16番取消あり
                        //   という並びだと、Bの取消で16番が活動馬から落ち、
                        //   Aが「全頭そろった」として採用されます。
                        //   hash不一致で committed=false にした候補でも同じです。
                        //   【いま】pick と<b>同じ条件</b>を先に当てます。
                        // ══════════════════════════════════════════════
                        // @ANCHOR-20261021-PREDICTION-PERCAND
                        // ══════════════════════════════════════════════
                        //   【まだ足りませんでした】commit済み・時刻適合の
                        //   候補<b>全部</b>の取消を合算した1つの集合を、
                        //   各バッチの全頭検査に使っていました。
                        //   「採用され得る」は「全頭検査を通った」ではありません。
                        //   別の候補の取消で欠損が隠れ、取消を持たない候補が
                        //   採用され、採用判定と凍結集合が食い違います。
                        //   【いま】合算しません。基礎集合（出馬表）だけを渡し、
                        //   <b>その候補自身の取消</b>は pick の中で引きます。
                        $cancelSrc = [];
                        foreach ($cand as $cb) {
                            if ((array) $cb['cancelled'] !== []) {
                                $cancelSrc[] = (string) $cb['batch_id']
                                             . '（' . implode('・',
                                               array_map('strval', (array) $cb['cancelled']))
                                             . '）';
                            }
                        }
                        if ($cancelSrc !== []) {
                            $notes[] = $slot . '分前の全頭検査は、候補ごとに'
                                     . '<b>その候補自身の取消だけ</b>を引いて行いました'
                                     . '（取消を持つ候補 ' . implode('・', $cancelSrc) . '）';
                        }
                        $picked[$slot] = BaganrikiAcquisition::pick($slot, $cand,
                                                                    $entryNums, $anchorUs);
                        if (($picked[$slot]['status'] ?? '') === 'ADOPTED') {
                            $bt = $picked[$slot]['batch'];
                            foreach ((array) $bt['values'] as $n => $v) {
                                foreach (['win' => 'win_odds', 'place_min' => 'place_min',
                                          'place_max' => 'place_max'] as $k => $f) {
                                    if (($v[$k] ?? null) !== null) $batchCells[$n][$slot][$f] = $v[$k];
                                }
                            }
                            foreach ((array) $bt['cancelled'] as $n) $batchCancel[$n][] = $slot;
                        }
                    }
                    $audit = BaganrikiAcquisition::buildAudit($picked);
                    $notes[] = 'collection_audit と原値は、採用した監査バッチの'
                             . 'raw_payload から作りました（SHA-256照合つき）';
                }
            }
        }
        if ($audit === null) {
            if (!$assumeAudit) {
                return $ng('ACQUISITION_AUDIT_MISSING',
                    '取得監査の実時刻（batch_id・request_started_at・'
                  . 'response_received_at・available_at・raw_payload）が'
                  . '既存DBにありません。お願い②（A案）の記録を入れてから送れます');
            }
            // 仮の監査。予定時刻で埋めたことを必ず残します。
            $audit = [];
            foreach ($slots as $slot) {
                $has = false;
                foreach ($entryNums as $n) {
                    if (isset($cells[$n][$slot]['win_odds'])) { $has = true; break; }
                }
                if (!$has) {
                    $audit[(string) $slot] = ['status' => 'UNAVAILABLE',
                        'reason' => 'このスロットの単勝オッズが1頭も読めません'];
                    continue;
                }
                $sch = BaganrikiTime::slotScheduled($anchorUs, $slot);
                $audit[(string) $slot] = [
                    'status'               => 'ADOPTED',
                    'batch_id'             => 'ASSUMED-' . $raceId . '-' . $slot,
                    'payload_hash'         => hash('sha256',
                        BaganrikiPrompt::canonical(['race_id' => $raceId, 'slot' => $slot,
                                                    'cells' => $cells]) ?? ''),
                    'slot_scheduled_at'    => BaganrikiPrompt::rfc3339($sch),
                    'source_updated_at'    => null,
                    'request_started_at'   => BaganrikiPrompt::rfc3339($sch),
                    'response_received_at' => BaganrikiPrompt::rfc3339($sch),
                    'available_at'         => BaganrikiPrompt::rfc3339($sch),
                ];
            }
            $notes[] = '【仮】collection_audit の実時刻は予定時刻で埋めました'
                     . '（本番送信には使いません）';
        } else {
            // 実監査行があるときは、採用バッチの原値だけを使います
            $cells = $batchCells;
            // Only adopted audit batches may establish a cancellation conflict.
            // A later available numeric observation cannot silently resurrect
            // a horse whose cancellation was already available before freeze.
            foreach ($batchCancel as $no => $cancelSlots) {
                foreach ($cancelSlots as $cancelSlot) {
                    $cancelUs = BaganrikiTime::us($audit[(string) $cancelSlot]['available_at'] ?? null);
                    foreach ((array) ($batchCells[$no] ?? []) as $valueSlot => $values) {
                        $valueUs = BaganrikiTime::us($audit[(string) $valueSlot]['available_at'] ?? null);
                        if ($values !== [] && $cancelUs !== null && $valueUs !== null
                            && $valueUs > $cancelUs) {
                            return $ng('CANCEL_CONFLICT',
                                '取消の時点より後に実オッズがあります（馬番 ' . $no . '）。実出走頭数を確定できません');
                        }
                    }
                }
            }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-CANCELSRC
            // ══════════════════════════════════════════════════════════
            //   【本物の不具合でした】数値は $batchCells へ入れ替えるのに、
            //   <b>$cancelPre は通常オッズ表のまま残していました。</b>
            //   通常表は後から上書きされるので、採用しなかったバッチや
            //   情報上限より後に書かれた取消が凍結集合へ混ざります。
            //   例：上限内の採用バッチAでは16頭が活動中。その後の期限超過
            //   バッチBで16番が取消になり通常表の6分前行が更新される。
            //   Aの数値を使っていても、B由来の取消で16番が消えていました。
            //   取消は<b>情報上限までに利用可能だった採用バッチ</b>だけで決めます。
            //   名目スロットが6だから上限内、という扱いもしません。
            $cancelPre = [];
            foreach ($batchCancel as $n => $sl) {
                if (array_intersect($sl, $slots) !== []) $cancelPre[(int) $n] = true;
            }
            $notes[] = '取消は採用した監査バッチだけから決めました'
                     . '（通常オッズ表の取消は凍結集合に入れていません）';
            $cancelled = array_keys($cancelPre);
            sort($cancelled);
            $cancelledPost = array_values(array_diff(array_keys($cancelPost), $cancelled));
            sort($cancelledPost);
        }

        // ── source_registry（原値のまま・丸めません）──────────────────
        $registry = [];
        foreach ($entryNums as $n) {
            if (in_array($n, $cancelled, true)) continue;
            foreach ($slots as $slot) {
                foreach (['win_odds', 'place_min', 'place_max'] as $f) {
                    $v = $cells[$n][$slot][$f] ?? null;
                    if ($v === null) continue;              // 欠損は入れません
                    $a = $audit[(string) $slot] ?? null;
                    if ($a === null || ($a['status'] ?? '') !== 'ADOPTED') continue;
                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261020-PREDICTION-DIGITS2
                    // ══════════════════════════════════════════════════
                    //   【旧】PHPの数値で正確に持てない値は<b>登録から落として
                    //     言いながら、後段で捨てていたので直っていませんでした。
                    //   【いま】落としません。<b>元の十進数の文字列をそのまま
                    //     value に入れます。</b>
                    //     ・source_registry Schema の value は
                    //       ["string","number","boolean","null"] なので、
                    //       文字列のままでも適合します（Schemaは変えていません）。
                    //     ・信号の計算は BaganrikiPrompt::horsesForSignals() が
                    //       文字列をそのまま受け取り、多倍長整数のたすき掛けで
                    //       比べます。floatを通らないので桁は落ちません。
                    //     ・数値で持てたものは今までどおり数値で入れます
                    //       （送信文のバイト列を変えないため）。
                    $nv = AiController::parseNumericValue($v);
                    if (!is_int($nv) && !is_float($nv)) {
                        // 元の十進数の形だけは確かめます（契約9章・最大128桁）。
                        //   形が違うものは作り話になるので入れません。
                        if (!preg_match('/^-?\d{1,128}(?:\.\d{1,128})?$/',
                                        trim((string) $v))) {
                            $notes[] = '馬番 ' . $n . ' の ' . $f . '（' . $slot
                                     . '分前）は十進数の形ではないので登録しません'
                                     . 'でした: ' . $v;
                            continue;
                        }
                        $nv = trim((string) $v);
                        $notes[] = '馬番 ' . $n . ' の ' . $f . '（' . $slot . '分前）は'
                                 . 'PHPの数値では丸めが起きるので、十進数の文字列'
                                 . 'のまま登録しました（桁は落としていません）: ' . $nv;
                    }
                    $ref = 'h' . $n . '_' . $f . '_' . $slot;
                    $registry[$ref] = [
                        'source_ref'           => $ref,
                        'race_id'              => $raceId,
                        'value'                => $nv,
                        'scope'                => 'HORSE',
                        'horse_nos'            => [$n],
                        'applicable_horse_nos' => [],
                        'field'                => $f,
                        'unit'                 => 'odds',
                        'slot_offset'          => $slot,
                        'observed_at'          => $a['response_received_at'],
                        'available_at'         => $a['available_at'],
                        'computed_at'          => null,
                        'source_kind'          => 'OBSERVED',
                        'parents'              => [],
                        'formula_id'           => null,
                        'formula_version'      => null,
                        'dependency_key'       => 'h' . $n . '_' . $f,
                        'dependency_role'      => 'SIGNAL',
                    ];
                }
            }
        }
        if ($registry === []) {
            return $ng('SOURCE_REGISTRY_EMPTY', '登録できる原値がありません');
        }

        // ── オッズ以外の材料（@ANCHOR-20261020-PREDICTION-COVERAGE）─────────
        //   断層・回収率統計・過去類似統計を載せます。
        //   「無いから UNAVAILABLE」ではなく「有るのに繋いでいない」状態を
        //   なくすための接続です（契約3章・10月20日004 のご判断2）。
        $extraCov = [];
        {
            $win6 = [];
            foreach ($entryNums as $n) {
                if (in_array($n, $cancelled, true)) continue;
                $v = $cells[$n][6]['win_odds'] ?? null;
                if ($v !== null) $win6[$n] = $v;
            }
            $fr = BaganrikiSnapshot::frozenRank($win6);
            if (($fr['ok'] ?? false) === true) {
                $ex = AiController::buildExtraSourceRegistry(
                    ['race_id' => $raceId, 'audit' => $audit],
                    $fr['rank'], $cells, $freezeUs);   // @ANCHOR-20261116-PREDICTION-EARLYFREEZE（旧 BaganrikiTime::inputFreeze($anchorUs)）
                foreach ($ex['registry'] as $k => $v) $registry[$k] = $v;
                $extraCov = $ex['coverage'];
                if ($ex['registry'] !== []) {
                    $notes[] = '追加材料を ' . count($ex['registry']) . ' 件 載せました'
                             . '（断層・回収率統計・過去類似統計）';
                }

                // ── @ANCHOR-20261020-PREDICTION-MARKET  OPI と推定確定オッズ ──
                //   式はいまの商品と同じものを使います（作り直しません）。
                //   当日を含む集計・情報上限より後に算出された集計は載せません。
                $mp = AiController::buildMarketPriorRegistry($raceId, (string) $date,
                          $fr['rank'], $cells,
                          BaganrikiPrompt::rfc3339($freezeUs));   // @ANCHOR-20261116-PREDICTION-EARLYFREEZE（旧 BaganrikiTime::inputFreeze($anchorUs)）
                foreach ($mp['registry'] as $k => $v) { $registry[$k] = $v; }
                $extraCov = $mp['coverage'] + $extraCov;
                if ($mp['registry'] !== []) {
                    $notes[] = 'OPI と推定確定オッズを ' . count($mp['registry'])
                             . ' 件 載せました';
                }
            }
        }

        // ── @ANCHOR-20261020-PREDICTION-PAST  過去走 ─────────────────────────
        //   取消になった馬は凍結集合に残るので、過去走も載せます。
        //   情報上限の切り方は buildPastRunsRegistry の中に書いてあります。
        // $pr = AiController::buildPastRunsRegistry($raceId, (string) $date, $entryNames,
        //           BaganrikiPrompt::rfc3339(BaganrikiTime::inputFreeze($anchorUs)));   // 旧
        // @ANCHOR-20261115-PREDICTION-PRECANCEL  凍結より前に取消になった馬の過去走は載せません
        //   11/15 京都10R：6番が発走前（情報上限まで）に取消。出走馬（runners）からは外れているのに、
        //   出馬表の全頭で過去走を作っていたため、6番を指す出典が入力に残り、E3・E5を付けた入力の検査
        //   （EXPECTED_SOURCE_HORSE：出典の馬番が出走馬にいるか）で凍結が止まりました。
        //   凍結の後に取消になった馬（cancelledPost）は凍結集合に残るので、今までどおり載せます。
        $pastNames = array_diff_key($entryNames, array_flip($cancelled));
        $pr = AiController::buildPastRunsRegistry($raceId, (string) $date, $pastNames,
                  BaganrikiPrompt::rfc3339($freezeUs));   // @ANCHOR-20261116-PREDICTION-EARLYFREEZE（旧 BaganrikiTime::inputFreeze($anchorUs)）
        foreach ($pr['registry'] as $k => $v) { $registry[$k] = $v; }
        $extraCov = $pr['coverage'] + $extraCov;
        if ($pr['registry'] !== []) {
            $notes[] = '過去走を ' . count($pr['registry']) . ' 件 載せました'
                     . '（' . ($pr['horses_with_past'] ?? 0) . '頭ぶん・直近5走まで）';
        }

        // ── coverage（入れていない区分は理由を書いて UNAVAILABLE）─────
        $coverage = [
            'past_runs'  => ['status' => 'UNAVAILABLE',
                'reason' => '過去走は今回の入力へ入れていません（残作業・source_registry未整備）'],
            'OPI'        => ['status' => 'UNAVAILABLE',
                'reason' => 'OPIは今回の入力へ入れていません（残作業・source_registry未整備）'],
            'estimated_odds' => ['status' => 'UNAVAILABLE',
                'reason' => '推定確定オッズは今回の入力へ入れていません（残作業）'],
            'recovery_statistics' => ['status' => 'UNAVAILABLE',
                'reason' => '回収率統計は今回の入力へ入れていません（残作業）'],
            'similar_statistics'  => ['status' => 'UNAVAILABLE',
                'reason' => 'この頭数帯・人気順位の集計がありませんでした'],
            'legacy_qualifications' => ['status' => 'UNAVAILABLE',
                'reason' => '旧資格は r12.2 では使いません'],
            'gap_history' => ['status' => 'UNAVAILABLE',
                'reason' => '断層履歴は今回の入力へ入れていません（残作業）'],
        ];
        // 実際に載せた区分は INCLUDED と source_refs で上書きします
        if ($extraCov !== []) {
            $coverage = $extraCov + $coverage;
        }
        if (isset($override['coverage']) && is_array($override['coverage'])) {
            $coverage = $override['coverage'] + $coverage;
        }

        $ctx = [
            'race_id'         => $raceId,
            'anchor_at'       => BaganrikiPrompt::rfc3339($anchorUs),
            // @ANCHOR-20261020-PREDICTION-CLOSE  正本を渡されていなければ、
            //   JRA公式の規則（インターネット投票は発走時刻の1分前）で出します。
            //   発走時刻で代用しているのではありません。公開された規則から
            //   発走時刻を起点に導いた値です。出どころは notes に残します。
            'sales_close_at'  => $override['sales_close_at']
                                 ?? (($d = BaganrikiTime::salesCloseFromPost($anchorUs))
                                     === null ? null : BaganrikiPrompt::rfc3339($d)),
            'registry'        => $registry,
            'audit'           => $audit,
            'coverage'        => $coverage,
            'entry_nums'      => $entryNums,
            'cancelled'       => $cancelled,
            'cancelled_post'  => $cancelledPost,
            'legacy_gap_type' => null,
            'similar'         => $override['similar'] ?? null,
            // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  情報上限と時刻契約の版（buildInput がこの値で入力を作る）
            'input_freeze_us'       => $freezeUs,
            'time_contract_version' => $timeVersion,
        ];
        // @ANCHOR-20261020-PREDICTION-CLOSE  締切の出どころを返します
        //   （INPUT_JSON の time_contract は閉じた形なので、そこには入れません。
        //     凍結のときに監査へ1行残します）
        $closeSrc = isset($override['sales_close_at'])
            ? 'OVERRIDE'
            : (BaganrikiTime::salesCloseDerive()
                ? BaganrikiTime::SALES_CLOSE_SOURCE
                  . '(T-' . BaganrikiTime::salesCloseOffsetSec() . 's)'
                : 'NOT_DERIVED');
        return ['ok' => true, 'ctx' => $ctx, 'code' => null, 'reason' => null,
                'race_id' => $raceId, 'notes' => $notes,
                'sales_close_source' => $closeSrc];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-ACQFIX  DBの日時を RFC3339（JST）へ
     * ═══════════════════════════════════════════════════════════════════════
     * DATETIME(6) は「2026-10-18 15:04:00.123456」の形で返ります。
     * マイクロ秒を落とさずに「2026-10-18T15:04:00.123456+09:00」へ直します。
     * 小数部が無ければ付けません。読めない値は null を返します（埋めません）。
     *
     * DBの保存タイムゾーンはサーバのローカル時刻（JST）です。
     * ここで +09:00 を付けるのは、その前提を明示するためです。
     */
    public static function dbTimeToRfc3339($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '' || strncmp($s, '0000', 4) === 0) return null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d{1,6})?\z/', $s, $m)) {
            $frac = $m[3] ?? '';
            if ($frac === '.000000' || $frac === '.0') $frac = '';
            $rfc = $m[1] . 'T' . $m[2] . $frac . '+09:00';
            // Validate the full calendar/time before passing it to acquisition or correction.
            return BaganrikiTime::us($rfc) === null ? null : $rfc;
        }
        return null;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PROMPT  十進数の文字列を JSON の数値へ
     *   「2.1」→ 2.1、「11」→ 11。桁を増やしたり減らしたりしません。
     *   読めないものは文字列のまま返します（作り話をしないため）。
     */
    public static function parseNumericValue(string $s)
    {
        // @ANCHOR-20261020-PREDICTION-DIGITS
        //   契約9章は「整数・小数は最大128桁」です。
        //   【旧】'/^\d+(?:\.\d{1,4})?$/' … 小数4桁より下を黙って捨てていました。
        if (!preg_match('/^\d{1,128}(?:\.\d{1,128})?$/', $s)) return $s;
        if (strpos($s, '.') === false) {
            // PHPの整数で正確に表せる範囲だけ数値にします。
            // 超えるものは文字列のまま返し、呼び出し側が登録しません。
            return (strlen(ltrim($s, '0')) <= 18) ? (int) $s : $s;
        }
        // 小数は「floatにして文字列へ戻すと元と一致する」ものだけ数値にします。
        // 一致しないものは丸めが起きているので、数値にしません。
        //   PHPの (string)(float) は、元へ戻せる最短の表記を返します
        //   （serialize_precision=-1）。sprintf('%.17F') は
        //   2.1 を '2.10000000000000009' にしてしまうので使いません。
        $norm = rtrim(rtrim($s, '0'), '.');
        if ($norm === '') { $norm = '0'; }
        $back = (string) (float) $s;
        return ($back === $norm) ? (float) $s : $s;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-COVERAGE  オッズ以外の材料を source_registry へ載せる
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 3章「利用できるデータを黙って捨てない」
     *       10月20日004 のご判断2（A案）／21_追加材料7区分の接続表
     *
     * ■ 今回つなぐ3区分
     *   gap_history          断層（21→6分前の単勝の増減率）
     *   recovery_statistics  その増減率帯と人気帯の回収率
     *   similar_statistics   人気順位×頭数帯の過去類似成績
     *
     *   この3つを先につなぐ理由は、<b>算出時刻の列を持っているから</b>です。
     *   過去走とOPIは取得・算出時刻の列が無いので、時刻の出どころが決まるまで
     *   載せません（接続表のA/B/Cのご判断待ち）。作り話の時刻で埋めません。
     *
     * ■ 由来の残し方
     *   ・1頭ごとの増減率は <b>DERIVED</b> にし、
     *     parents にその馬の21分前と6分前の登録IDをそのまま入れます。
     *     どの元値から出したかが1件ずつ辿れます。
     *   ・帯別の統計は <b>DERIVED</b> で、parents には集計元のテーブル名を入れます。
     *     元になった18万行の履歴は入力に載せないので、個々の行IDではなく
     *     テーブル名を由来として書きます。ここは正直に書いておきます。
     *   ・computed_at は、集計テーブルが持っている実際の算出時刻です。
     *
     * ■ 情報上限
     *   増減率は21分前と6分前の値だけから出します。3分前・発走時は使いません。
     *   帯別統計の computed_at が input_freeze_at より後なら載せません。
     *
     * @param array $ctx        これまでに組み立てた文脈（registry・audit を含む）
     * @param array $frozenRank 馬番 => 凍結人気
     * @param array $cells      [馬番][スロット][項目] => 元の十進数文字列
     * @param int   $freezeUs   情報上限（マイクロ秒）
     * @return array ['registry'=>追加分, 'coverage'=>区分ごとの状態]
     */
    public static function buildExtraSourceRegistry(array $ctx, array $frozenRank,
                                             array $cells, ?int $freezeUs): array
    {
        // @ANCHOR-20261020-PREDICTION-DERIVEDAT  1回の組み立てで1つの計算時刻にします
        $buildAt = BaganrikiPrompt::rfc3339(BaganrikiWorker::nowUs());
        $raceId = (string) ($ctx['race_id'] ?? '');
        $add    = [];
        $refs   = ['gap_history' => [], 'recovery_statistics' => [],
                   'similar_statistics' => []];
        // @ANCHOR-20261020-PREDICTION-COVERAGE  載せなかった理由を取り違えないための控え
        //   「集計が無い」と「情報上限より後に算出された」は別の話です。
        $late   = ['recovery_statistics' => null, 'similar_statistics' => null];
        // @ANCHOR-20261020-PREDICTION-COVWIN  集計期間が対象レースの開催日を含んでいた控え
        //   OPI・推定確定オッズと同じ判定を、列を持っている回収率にも当てます。
        //   （過去類似成績の表には集計期間の列がありません。無い列は作りません）
        $covWin = ['recovery_statistics' => null];
        $freezeAt = ($freezeUs === null) ? null : BaganrikiPrompt::rfc3339($freezeUs);
        $N      = count($frozenRank);
        if ($N < 1) {
            return ['registry' => [], 'coverage' => []];
        }
        // @ANCHOR-20261020-PREDICTION-COVWIN  対象レースの開催日（race_id の先頭8桁）
        $day = '';
        if (preg_match('/^(\d{4})(\d{2})(\d{2})-/', $raceId, $mm) === 1) {
            $day = $mm[1] . '-' . $mm[2] . '-' . $mm[3];
        }
        $audit6 = $ctx['audit']['6'] ?? ($ctx['audit'][6] ?? null);
        $obs6   = is_array($audit6) ? ($audit6['response_received_at'] ?? null) : null;
        $av6    = is_array($audit6) ? ($audit6['available_at'] ?? null) : null;
        if ($obs6 === null || $av6 === null) {
            return ['registry' => [], 'coverage' => []];
        }

        // ── ① 1頭ごとの増減率（21分前 → 6分前の単勝）──────────────────
        $gapPct = [];
        foreach (array_keys($frozenRank) as $no) {
            $o21 = $cells[$no][21]['win_odds'] ?? null;
            $o6  = $cells[$no][6]['win_odds']  ?? null;
            if ($o21 === null || $o6 === null) continue;
            // @ANCHOR-20261020-PREDICTION-DEC  小数5桁以上でも落としません
            //   【旧】BaganrikiSnapshot::scale は小数4桁までで、
            //     1.23456 のオッズだと増減率を作れずに捨てていました。
            $a = AiController::decimalOrNull($o21);
            $b = AiController::decimalOrNull($o6);
            if ($a === null || $b === null || $a <= 0) continue;
            $pct = round((($b - $a) / $a) * 100, 4);
            $gapPct[$no] = $pct;
            $ref = 'h' . $no . '_odds_gap_pct_21_6';
            $add[$ref] = [
                'source_ref'           => $ref,
                'race_id'              => $raceId,
                'value'                => $pct,
                'scope'                => 'HORSE',
                'horse_nos'            => [(int) $no],
                'applicable_horse_nos' => [],
                'field'                => 'odds_gap_pct_21_6',
                'unit'                 => 'percent',
                'slot_offset'          => 6,
                'observed_at'          => $obs6,
                'available_at'         => $av6,
                // @ANCHOR-20261020-PREDICTION-DERIVEDAT
                //   【旧】今回こちらで出した増減率なのに、原オッズの
                //     利用可能時刻を computed_at に入れていました。
                'computed_at'          => $buildAt,
                'source_kind'          => 'DERIVED',
                'parents'              => ['h' . $no . '_win_odds_21', 'h' . $no . '_win_odds_6'],
                'formula_id'           => 'ODDS_GAP_PCT_21_TO_6',
                'formula_version'      => 'v1',
                'dependency_key'       => 'h' . $no . '_odds_gap_pct',
                'dependency_role'      => 'CONTEXT',
            ];
            $refs['gap_history'][] = $ref;
        }

        // ── ② 増減率帯 × 人気帯 の回収率 ────────────────────────────────
        if ($gapPct !== [] && \Schema::hasTable('t_horse_odds_finder_odds_gap_recovery')) {
            foreach (\DB::table('t_horse_odds_finder_odds_gap_recovery')->get() as $r) {
                $gmin = (float) $r->gap_min; $gmax = (float) $r->gap_max;
                $pmin = (int) $r->popularity_min; $pmax = (int) $r->popularity_max;
                $hit = [];
                foreach ($gapPct as $no => $pct) {
                    $pop = (int) $frozenRank[$no];
                    if ($pct < $gmin || $pct > $gmax) continue;
                    if ($pop < $pmin || $pop > $pmax) continue;
                    $hit[] = (int) $no;
                }
                if ($hit === []) continue;
                sort($hit);
                // @ANCHOR-20261020-PREDICTION-COVWIN  当日を含む集計でその日のレースを評価しない
                //   この表は start_date / end_date を持っているので、
                //   OPI・推定確定オッズとまったく同じ判定を当てます。
                $ed = substr(str_replace('/', '-', (string) ($r->end_date ?? '')), 0, 10);
                if ($day !== '' && $ed !== '' && strcmp($ed, $day) >= 0) {
                    if ($covWin['recovery_statistics'] === null) {
                        $covWin['recovery_statistics'] = $ed;
                    }
                    continue;
                }
                $c = AiController::formatDbTime($r->computed_at);
                if ($c === null) continue;
                if ($freezeUs !== null) {
                    $cu = BaganrikiTime::us($c);
                    if ($cu === null || $cu > $freezeUs) {
                        // 情報上限より後に算出された集計は載せません。
                        // 「集計が無い」のではないので、理由を分けて残します。
                        if ($late['recovery_statistics'] === null
                            || strcmp($c, (string) $late['recovery_statistics']) < 0) {
                            $late['recovery_statistics'] = $c;
                        }
                        continue;
                    }
                }
                // @ANCHOR-20261020-PREDICTION-COVWIN  使った集計の期間も残します（OPIと同じ）
                $sd = substr(str_replace('/', '-', (string) ($r->start_date ?? '')), 0, 10);
                foreach (['start_date' => $sd, 'end_date' => $ed] as $wf => $wv) {
                    if ($wv === '') { continue; }
                    $wref = 'gaprec_' . $wf;
                    if (isset($add[$wref])) { continue; }
                    $add[$wref] = [
                        'source_ref'           => $wref,
                        'race_id'              => $raceId,
                        'value'                => $wv,
                        'scope'                => 'RACE',
                        'horse_nos'            => [],
                        'applicable_horse_nos' => [],
                        'field'                => 'gaprec_' . $wf,
                        'unit'                 => 'date',
                        'slot_offset'          => null,
                        'observed_at'          => $c,
                        'available_at'         => $c,
                        'computed_at'          => null,
                        'source_kind'          => 'OBSERVED',
                        'parents'              => [],
                        'formula_id'           => null,
                        'formula_version'      => null,
                        'dependency_key'       => 'gaprec_' . $wf,
                        'dependency_role'      => 'CONTEXT',
                    ];
                    $refs['recovery_statistics'][] = $wref;
                }
                $key = AiController::slugify((string) $r->gap_band) . '_' . $pmin . '_' . $pmax;
                foreach (['recovery_rate' => 'percent', 'win_rate' => 'percent',
                          'sample_count'  => 'races'] as $f => $unit) {
                    $v = $r->{$f};
                    if ($v === null) continue;
                    $ref = 'gaprec_' . $key . '_' . $f;
                    $add[$ref] = [
                        'source_ref'           => $ref,
                        'race_id'              => $raceId,
                        'value'                => AiController::parseNumericValue(trim((string) $v)),
                        'scope'                => 'COHORT',
                        'horse_nos'            => [],
                        'applicable_horse_nos' => $hit,
                        'field'                => 'gap_recovery_' . $f,
                        'unit'                 => $unit,
                        'slot_offset'          => null,
                        'observed_at'          => $c,
                        'available_at'         => $c,
                        'computed_at'          => $c,
                        'source_kind'          => 'DERIVED',
                        'parents'              => ['t_horse_odds_finder_race_result_history'],
                        'formula_id'           => 'SummaryOddsGapRecoveryRate',
                        'formula_version'      => 'v1',
                        'dependency_key'       => 'gaprec_' . $key,
                        'dependency_role'      => 'CONTEXT',
                    ];
                    $refs['recovery_statistics'][] = $ref;
                }
            }
        }

        // ── ③ 人気順位 × 頭数帯 の過去類似成績 ──────────────────────────
        //   頭数帯は small=8以下／medium=9〜13／large=14以上
        if (\Schema::hasTable('t_horse_odds_finder_similar_race_stats')) {
            $band = ($N <= 8) ? 'small' : (($N <= 13) ? 'medium' : 'large');
            foreach (\DB::table('t_horse_odds_finder_similar_race_stats')
                        ->where('horse_count_band', $band)->get() as $r) {
                $pop = (int) $r->popularity_rank;
                $hit = [];
                foreach ($frozenRank as $no => $p) {
                    if ((int) $p === $pop) $hit[] = (int) $no;
                }
                if ($hit === []) continue;
                sort($hit);
                $c = AiController::formatDbTime($r->updated_at);
                if ($c === null) continue;
                if ($freezeUs !== null) {
                    $cu = BaganrikiTime::us($c);
                    if ($cu === null || $cu > $freezeUs) {
                        if ($late['similar_statistics'] === null
                            || strcmp($c, (string) $late['similar_statistics']) < 0) {
                            $late['similar_statistics'] = $c;
                        }
                        continue;
                    }
                }
                foreach (['top3_rate' => 'rate', 'top5_rate' => 'rate',
                          'avg_finishing_position' => 'position',
                          'outside_rate' => 'rate', 'sample_count' => 'races',
                          'reliability' => 'label'] as $f => $unit) {
                    $v = $r->{$f};
                    if ($v === null) continue;
                    $ref = 'similar_' . $band . '_p' . $pop . '_' . $f;
                    // Keep the recorded statistic exactly, including integers beyond PHP's range.
                    $val = ($f === 'reliability') ? (string) $v
                         : AiController::parseNumericValue(trim((string) $v));
                    $add[$ref] = [
                        'source_ref'           => $ref,
                        'race_id'              => $raceId,
                        'value'                => $val,
                        'scope'                => 'COHORT',
                        'horse_nos'            => [],
                        'applicable_horse_nos' => $hit,
                        'field'                => 'similar_' . $f,
                        'unit'                 => $unit,
                        'slot_offset'          => null,
                        'observed_at'          => $c,
                        'available_at'         => $c,
                        'computed_at'          => $c,
                        'source_kind'          => 'DERIVED',
                        'parents'              => ['t_horse_odds_finder_race_result_history'],
                        'formula_id'           => 'SummarySimilarRaceStats',
                        'formula_version'      => 'v1',
                        'dependency_key'       => 'similar_' . $band . '_p' . $pop,
                        'dependency_role'      => 'CONTEXT',
                    ];
                    $refs['similar_statistics'][] = $ref;
                }
            }
        }

        // ── coverage ──────────────────────────────────────────────────
        $cov = [];
        $why = [
            'gap_history'         => '21→6分前の単勝の増減率を1頭ずつ出せませんでした',
            'recovery_statistics' => '当てはまる増減率帯・人気帯の集計がありませんでした',
            'similar_statistics'  => 'この頭数帯・人気順位の集計がありませんでした',
        ];
        foreach ($refs as $k => $list) {
            $list = array_values(array_unique($list));
            sort($list, SORT_STRING);
            if ($list !== []) {
                $cov[$k] = ['status' => 'INCLUDED', 'source_refs' => $list];
                continue;
            }
            // 「集計が無い」のか「情報上限より後に算出された」のか
            // 「当日を含む集計だった」のかを分けます。
            $reason = $why[$k];
            if (!empty($covWin[$k])) {
                $reason = '集計期間の終わり（' . $covWin[$k] . '）が対象レースの開催日（'
                        . $day . '）以降です。当日を含む集計で'
                        . 'その日のレースを評価しないためです。';
            } elseif (!empty($late[$k])) {
                $reason = 'この集計は情報上限より後に算出されたものしかありません'
                        . '（いちばん早い算出時刻 ' . $late[$k]
                        . '／情報上限 ' . ($freezeAt ?? '不明') . '）。'
                        . '過去のレースを後から作った集計で評価しないためです。';
            }
            $cov[$k] = ['status' => 'UNAVAILABLE', 'reason' => $reason];
        }
        return ['registry' => $add, 'coverage' => $cov];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-COVERAGE  DBの日時をRFC3339（JST）へ
     *
     * ══════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-MICRO
     * ══════════════════════════════════════════════════════════════
     *   【旧】strtotime は<b>秒の整数</b>しか返しません。
     *     それに1,000,000を掛けていたので、
     *     `09:34:30.000001` が `09:34:30` へ切り捨てられ、
     *     情報上限 `09:34:30` を<b>超えているのに上限内として通り得ました。</b>
     *     取得側の dbTimeToRfc3339 は小数秒を保っていたのに、
     *     追加材料側のこの関数だけが落としていました。
     *   【いま】小数秒を取り出して、マイクロ秒まで保ちます。
     *     保存タイムゾーンは日本時間（DBのDATETIMEはJSTで入っています）。
     */
    public static function formatDbTime($v): ?string
    {
        if ($v === null) { return null; }
        $s = trim((string) $v);
        if ($s === '' || strncmp($s, '0000', 4) === 0) { return null; }
        // DBの DATETIME / DATE をそのまま読みます（区切りは空白でもTでも可）
        $re = '/^(\d{4})-(\d{2})-(\d{2})'
            . '(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?)?\z/';
        if (preg_match($re, $s, $m) !== 1) { return null; }
        $rfc = $m[1] . '-' . $m[2] . '-' . $m[3] . 'T'
             . ($m[4] ?? '00') . ':' . ($m[5] ?? '00') . ':' . ($m[6] ?? '00')
             . (isset($m[7]) ? '.' . $m[7] : '') . '+09:00';
        $us = BaganrikiTime::us($rfc);
        return $us === null ? null : BaganrikiPrompt::rfc3339($us);
    }

    /** @ANCHOR-20261020-PREDICTION-COVERAGE  帯の名前を source_ref に使える形へ */
    public static function slugify(string $s): string
    {
        $map = ['30%以上下落' => 'dn30', '20〜30%下落' => 'dn20', '10〜20%下落' => 'dn10',
                '5〜10%下落' => 'dn05', '±5%以内' => 'flat', '5〜10%上昇' => 'up05',
                '10〜20%上昇' => 'up10', '20〜30%上昇' => 'up20', '30%以上上昇' => 'up30'];
        if (isset($map[$s])) return $map[$s];
        $h = substr(hash('sha256', $s), 0, 8);
        return 'band' . $h;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PAST  過去走を source_registry へ載せます
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 3章・5章
     *   「past_runs は coverage で INCLUDED または理由付き UNAVAILABLE を
     *     必須記録。利用できるデータを黙って捨てない」
     *
     * ■ 正本
     *   t_horse_odds_finder_shutsuba_history（馬名で引きます）
     *   対象レースの<b>開催日より前</b>のレースだけを使います。
     *   同じ日・後の日のものは入れません（結果の先読みを防ぎます）。
     *
     * ■ 情報上限
     *   その行を<b>いつ手に入れたか</b>で切ります。
     *   created_at と updated_at の<b>遅いほう</b>を available_at とし、
     *   情報上限（入力の凍結時刻）より後のものは載せません。
     *   後から取り直した内容で過去のレースを評価しないためです。
     *
     * ■ observed_at について
     *   過去走の行には発走時刻がありません。
     *   <b>無い時刻を作らない</b>ので、observed_at には
     *   「こちらが手に入れた時刻」を入れ、
     *   そのレースの開催日は past_date として別の項目で渡します。
     *
     * ■ 1頭あたり直近5走まで（.env で変えられます）
     *   生の事実（着順・頭数・人気・距離・馬場・上がり3F・着差・開催日）と、
     *   そこから出した3つのまとめ（走数・最高着順・3着内率）を載せます。
     *
     *   BAGANRIKI_PAST_RUNS_MAX  既定 5（0〜10）。
     *   <b>0 にすると過去走を載せません。</b>
     *   送信文が長くなると応答時間も伸びます。応答期限は発走の150秒前なので、
     *   実測を見てここで絞れるようにしてあります。
     *
     * @param array $nums   馬番 => 馬名
     * @return array ['registry'=>..., 'coverage'=>['past_runs'=>...]]
     */
    public static function buildPastRunsRegistry(string $raceId, string $date,
                                                array $nums, ?string $freezeAt): array
    {
        // @ANCHOR-20261020-PREDICTION-DERIVEDAT  1回の組み立てで1つの計算時刻にします
        //   （同じ組み立ての中で時刻がばらつくと、snapshot_hash が揺れます）
        $buildAt = BaganrikiPrompt::rfc3339(BaganrikiWorker::nowUs());
        $empty = static fn(string $why) => ['registry' => [],
            'coverage' => ['past_runs' => ['status' => 'UNAVAILABLE', 'reason' => $why]]];

        if (!\Schema::hasTable('t_horse_odds_finder_shutsuba_history')) {
            return $empty('過去走の表（shutsuba_history）がありません');
        }
        $names = [];
        foreach ($nums as $no => $nm) {
            $nm = trim((string) $nm);
            if ($nm !== '') { $names[trim((string) $nm)][] = (int) $no; }
        }
        if ($names === []) {
            return $empty('出馬表に馬名がありません');
        }

        $max = AiController::pastRunsMax();
        if ($max <= 0) {
            return $empty('.env の BAGANRIKI_PAST_RUNS_MAX が 0 のため載せていません');
        }
        $freezeUs = ($freezeAt === null) ? null : BaganrikiTime::us($freezeAt);
        $add = []; $refs = []; $late = null; $used = 0;

        // 馬名ごとに、対象レースより前の開催日のものを新しい順で取ります
        $rows = \DB::table('t_horse_odds_finder_shutsuba_history')
                  ->whereIn('name', array_keys($names))
                  ->where('date', '<', $date)
                  ->orderBy('name')->orderByDesc('date')->orderBy('race')
                  ->get(['name', 'date', 'basho_code', 'race', 'grade',
                         'finishing_position', 'num_horses', 'gate', 'popularity',
                         'dist', 'time', 'condition', 'last_3f', 'fin_time_diff',
                         'created_at', 'updated_at']);

        $byName = [];
        foreach ($rows as $r) {
            $byName[(string) $r->name][] = $r;
        }

        foreach ($names as $nm => $horseNos) {
            $list = $byName[$nm] ?? [];
            if ($list === []) { continue; }
            foreach ($horseNos as $no) {
                $i = 0; $fin = []; $parents = []; $avail = null;
                foreach ($list as $r) {
                    if ($i >= $max) { break; }
                    // 手に入れた時刻（created_at と updated_at の遅いほう）
                    $cAt = AiController::formatDbTime($r->created_at);
                    $uAt = AiController::formatDbTime($r->updated_at);
                    $av  = $cAt;
                    if ($uAt !== null && ($av === null || strcmp($uAt, $av) > 0)) { $av = $uAt; }
                    if ($av === null) { continue; }
                    if ($freezeUs !== null) {
                        $au = BaganrikiTime::us($av);
                        if ($au === null || $au > $freezeUs) {
                            if ($late === null || strcmp($av, (string) $late) < 0) { $late = $av; }
                            continue;
                        }
                    }
                    $i++;
                    if ($avail === null || strcmp($av, $avail) > 0) { $avail = $av; }

                    $pdate = substr((string) $r->date, 0, 10);
                    $vals = [
                        'past_date'       => ['v' => $pdate,                        'u' => 'date'],
                        'past_finish'     => ['v' => AiController::intOrNull($r->finishing_position), 'u' => 'rank'],
                        'past_num_horses' => ['v' => AiController::intOrNull($r->num_horses),         'u' => 'horses'],
                        'past_popularity' => ['v' => AiController::intOrNull($r->popularity),         'u' => 'rank'],
                        'past_dist'       => ['v' => AiController::strOrNull($r->dist),      'u' => 'text'],
                        'past_condition'  => ['v' => AiController::strOrNull($r->condition), 'u' => 'text'],
                        'past_last_3f'    => ['v' => AiController::strOrNull($r->last_3f),   'u' => 'second'],
                        'past_time_diff'  => ['v' => AiController::strOrNull($r->fin_time_diff), 'u' => 'second'],
                    ];
                    foreach ($vals as $f => $x) {
                        if ($x['v'] === null) { continue; }
                        $ref = 'h' . $no . '_' . $f . '_' . $i;
                        $add[$ref] = [
                            'source_ref'           => $ref,
                            'race_id'              => $raceId,
                            'value'                => $x['v'],
                            'scope'                => 'HORSE',
                            'horse_nos'            => [(int) $no],
                            'applicable_horse_nos' => [],
                            'field'                => $f . '_' . $i,
                            'unit'                 => $x['u'],
                            'slot_offset'          => null,
                            // 過去走の行に発走時刻はありません。
                            // 無い時刻は作らず、手に入れた時刻を入れます。
                            'observed_at'          => $av,
                            'available_at'         => $av,
                            'computed_at'          => null,
                            'source_kind'          => 'OBSERVED',
                            'parents'              => [],
                            'formula_id'           => null,
                            'formula_version'      => null,
                            'dependency_key'       => 'h' . $no . '_past_' . $i,
                            'dependency_role'      => 'CONTEXT',
                        ];
                        $refs[] = $ref;
                        $parents[] = $ref;
                    }
                    $f0 = AiController::intOrNull($r->finishing_position);
                    if ($f0 !== null && $f0 > 0) { $fin[] = $f0; }
                }
                if ($i === 0 || $avail === null || $parents === []) { continue; }
                $used++;

                // ── まとめ（走数・最高着順・3着内率）────────────────────
                $sum = [
                    'past_runs_count' => ['v' => $i, 'u' => 'races'],
                ];
                if ($fin !== []) {
                    $top3 = 0;
                    foreach ($fin as $x) { if ($x <= 3) { $top3++; } }
                    $sum['past_best_finish'] = ['v' => min($fin), 'u' => 'rank'];
                    // 割り切れるときに整数へ落ちないよう、十進数の文字列で渡します
                    $sum['past_top3_rate'] = [
                        'v' => number_format($top3 / count($fin), 4, '.', ''),
                        'u' => 'ratio',
                    ];
                }
                foreach ($sum as $f => $x) {
                    $ref = 'h' . $no . '_' . $f;
                    $add[$ref] = [
                        'source_ref'           => $ref,
                        'race_id'              => $raceId,
                        'value'                => $x['v'],
                        'scope'                => 'HORSE',
                        'horse_nos'            => [(int) $no],
                        'applicable_horse_nos' => [],
                        'field'                => $f,
                        'unit'                 => $x['u'],
                        'slot_offset'          => null,
                        'observed_at'          => $avail,
                        'available_at'         => $avail,
                        // @ANCHOR-20261020-PREDICTION-DERIVEDAT
                        //   【旧】今回まとめた値なのに、過去走を手に入れた時刻を
                        //     computed_at に入れていました。実際の計算時刻ではありません。
                        'computed_at'          => $buildAt,
                        'source_kind'          => 'DERIVED',
                        'parents'              => array_values(array_unique($parents)),
                        'formula_id'           => 'PAST_RUNS_SUMMARY',
                        'formula_version'      => 'v1',
                        'dependency_key'       => 'h' . $no . '_past_summary',
                        'dependency_role'      => 'CONTEXT',
                    ];
                    $refs[] = $ref;
                }
            }
        }

        if ($refs === []) {
            $why = '対象レースより前の過去走が見つかりませんでした';
            if ($late !== null) {
                $why = '過去走は情報上限より後に手に入れたものしかありません'
                     . '（いちばん早い取得時刻 ' . $late
                     . '／情報上限 ' . ($freezeAt ?? '不明') . '）。'
                     . '後から取り直した内容で過去のレースを評価しないためです。';
            }
            return $empty($why);
        }
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);
        return [
            'registry' => $add,
            'coverage' => ['past_runs' => ['status' => 'INCLUDED', 'source_refs' => $refs]],
            'horses_with_past' => $used,
        ];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PAST  1頭あたり何走まで載せるか
     *   既定5。0 で載せません。10 を超える指定は10に丸めます。
     *   送信文の長さと応答時間の兼ね合いで絞れるようにしています。
     */
    public static function pastRunsMax(): int
    {
        $v = env('BAGANRIKI_PAST_RUNS_MAX', null);
        if ($v === null || !is_numeric((string) $v)) { return 5; }
        $n = (int) $v;
        if ($n < 0)  { return 0; }
        if ($n > 10) { return 10; }
        return $n;
    }

    /** @ANCHOR-20261020-PREDICTION-PAST  整数として読めるときだけ整数を返します */
    public static function intOrNull($v): ?int
    {
        if ($v === null) { return null; }
        $s = trim((string) $v);
        if ($s === '' || !preg_match('/^-?\d+$/', $s)) { return null; }
        return (int) $s;
    }

    /** @ANCHOR-20261020-PREDICTION-PAST  空でない文字列のときだけ返します */
    public static function strOrNull($v): ?string
    {
        if ($v === null) { return null; }
        $s = trim((string) $v);
        return ($s === '') ? null : $s;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-MARKET  OPI と推定確定オッズを source_registry へ
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 3章・5章／採点辞書 V1・V3・V4
     *
     * ■ 正本と式（いまの商品と同じものを使います。作り直しません）
     *   t_horse_odds_finder_popularity_rank_average       人気順位別の過去平均単勝
     *   t_horse_odds_finder_fuku_popularity_rank_average  人気順位別の過去平均複勝最小
     *   t_horse_odds_finder_compute_odds_correction       人気順位別の補正係数
     *
     *     OPI            = 過去平均単勝 ÷ 6分前単勝
     *     複勝OPI        = 過去平均複勝最小 ÷ 6分前複勝最小
     *     推定確定オッズ = 6分前単勝 × 補正係数
     *     推定確定複勝   = 6分前複勝最小 × 補正係数（複勝専用の係数は未実装）
     *     予測補正OPI    = 過去平均単勝 ÷ 推定確定オッズ
     *
     * ■ 使っていいかの判定（2つとも満たしたときだけ載せます）
     *   ① 集計期間の終わり（end_date）が<b>対象レースの開催日より前</b>
     *      当日を含む集計で、その当日のレースを評価しないためです。
     *   ② 算出時刻（computed_at）が情報上限より前
     *      この列が無い表は①だけで見ます。<b>無い時刻は作りません。</b>
     *      読み取った時刻（入力の凍結時刻）を observed_at に入れ、
     *      集計期間は start_date / end_date として別項目で渡します。
     *
     * ■ .env の BAGANRIKI_MARKET_PRIOR を 0 にすると載せません（既定は1）。
     *
     * @param array $frozenRank 馬番 => 凍結人気
     * @param array $cells      $cells[馬番][スロット]['win_odds'|'place_min']
     * @return array ['registry'=>..., 'coverage'=>['OPI'=>..,'estimated_odds'=>..]]
     */
    public static function buildMarketPriorRegistry(string $raceId, string $date,
                                                   array $frozenRank, array $cells,
                                                   ?string $freezeAt): array
    {
        $ng = static fn(string $o, string $e) => ['registry' => [], 'coverage' => [
            'OPI'            => ['status' => 'UNAVAILABLE', 'reason' => $o],
            'estimated_odds' => ['status' => 'UNAVAILABLE', 'reason' => $e],
        ]];

        if (!AiController::marketPriorEnabled()) {
            $m = '.env の BAGANRIKI_MARKET_PRIOR が 0 のため載せていません';
            return $ng($m, $m);
        }
        foreach (['t_horse_odds_finder_popularity_rank_average',
                  't_horse_odds_finder_fuku_popularity_rank_average',
                  't_horse_odds_finder_compute_odds_correction'] as $t) {
            if (!\Schema::hasTable($t)) {
                $m = $t . ' がありません';
                return $ng($m, $m);
            }
        }
        if ($frozenRank === []) {
            $m = '凍結人気が出ていないので人気順位別の集計を引けません';
            return $ng($m, $m);
        }

        $freezeUs = ($freezeAt === null) ? null : BaganrikiTime::us($freezeAt);
        $day = substr($date, 0, 10);

        // 使っていいかを見る共通の道具
        $check = static function ($endDate, $computedAt) use ($day, $freezeUs, $freezeAt) {
            $ed = ($endDate === null) ? '' : substr(str_replace('/', '-', (string) $endDate), 0, 10);
            if ($ed === '') {
                return [false, '集計期間の終わりが分かりません', null];
            }
            if (strcmp($ed, $day) >= 0) {
                return [false, '集計期間の終わり（' . $ed . '）が対象レースの開催日（'
                             . $day . '）以降です。当日を含む集計で'
                             . 'その日のレースを評価しないためです。', null];
            }
            $cAt = ($computedAt === null) ? null : AiController::formatDbTime($computedAt);
            // Validate each row before loading it. Another popularity rank's
            // valid timestamp cannot establish availability for this row.
            if ($cAt === null) {
                return [false, 'この集計行の算出・利用可能時刻を確認できません', null];
            }
            if ($freezeUs !== null) {
                $cu = BaganrikiTime::us($cAt);
                if ($cu === null || $cu > $freezeUs) {
                    return [false, 'この集計は情報上限より後に算出されています（算出 '
                                 . $cAt . '／情報上限 ' . ($freezeAt ?? '不明') . '）', null];
                }
            }
            return [true, null, $cAt];
        };

        // 人気順位 => その順位の馬番
        $byRank = [];
        foreach ($frozenRank as $no => $rk) {
            $byRank[(int) $rk][] = (int) $no;
        }
        foreach ($byRank as $rk => $list) { sort($list); $byRank[$rk] = $list; }

        $add = []; $refs = ['OPI' => [], 'estimated_odds' => []];
        $why = ['OPI' => null, 'estimated_odds' => null];

        // ── 表を読む ────────────────────────────────────────────────
        $load = static function (string $table, string $valueCol, string $countCol)
                use ($check) {
            $rows = []; $rowTimes = []; $win = null; $bad = null; $at = null; $atSrc = null;
            foreach (\DB::table($table)->get() as $r) {
                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261021-PREDICTION-VERIFIEDAT
                // ══════════════════════════════════════════════════════
                //   computed_at（実際に算出した時刻）を正とします。
                //   列を足す前から在った行は computed_at が無いので、
                //   その行だけ verified_at（この時刻に使えることを確かめた）
                //   で代えます。どちらを使ったかは下の理由文に出ます。
                $cSrc = 'computed_at';
                $cRaw = $r->computed_at ?? null;
                if ($cRaw === null && property_exists($r, 'verified_at')) {
                    $cRaw = $r->verified_at ?? null;
                    if ($cRaw !== null) { $cSrc = 'verified_at'; }
                }
                [$ok, $reason, $cAt] = $check($r->end_date ?? null, $cRaw);
                if (!$ok) { if ($bad === null) { $bad = $reason; } continue; }
                $rk = (int) ($r->popularity_rank ?? 0);
                if ($rk <= 0) { continue; }
                $rows[$rk] = $r;
                $rowTimes[$rk] = $cAt;
                if ($at === null || ($cAt !== null && strcmp($cAt, (string) $at) > 0)) {
                    $at = $cAt; $atSrc = $cSrc;
                }
                if ($win === null) {
                    $win = ['start' => substr((string) ($r->start_date ?? ''), 0, 10),
                            'end'   => substr((string) ($r->end_date ?? ''), 0, 10)];
                }
            }
            return ['rows' => $rows, 'row_times' => $rowTimes, 'window' => $win, 'bad' => $bad,
                    'computed_at' => $at,
                    // @ANCHOR-20261021-PREDICTION-VERIFIEDAT  どちらの時刻を使ったか
                    'time_source' => $atSrc];
        };

        $popAvg  = $load('t_horse_odds_finder_popularity_rank_average', 'odds_average', 'count');
        $fukuAvg = $load('t_horse_odds_finder_fuku_popularity_rank_average', 'odds_average', 'count');
        $corr    = $load('t_horse_odds_finder_compute_odds_correction', 'avg_correction_ratio', 'sample_count');

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-REALTIME
        // ══════════════════════════════════════════════════════════════
        //   【旧】算出時刻の無い表には $readAt（＝情報上限、無ければ現在時刻）を
        //     observed_at / available_at として入れていました。
        //     これは<b>真正な取得時刻の記録ではありません。</b>
        //     「無い時刻は作らない」と書きながら、作っていました。
        //     集計期間の終わりが開催日より前でも、その版を上限前に
        //     利用できた証拠にはなりません。
        //   【いま】算出時刻の列が無い表は使いません。理由を付けて外します。
        //     列が入れば（取得・算出側で記録すれば）その日から使えます。
        $nowAt = BaganrikiPrompt::rfc3339(BaganrikiWorker::nowUs());

        $put = static function (array &$add, string $ref, $value, string $field,
                                string $unit, array $applicable, string $at,
                                array $parents = [], ?string $formula = null)
                use ($raceId) {
            $derived = ($parents !== []);
            $add[$ref] = [
                'source_ref'           => $ref,
                'race_id'              => $raceId,
                'value'                => $value,
                'scope'                => ($applicable === []) ? 'RACE' : 'COHORT',
                'horse_nos'            => [],
                'applicable_horse_nos' => $applicable,
                'field'                => $field,
                'unit'                 => $unit,
                'slot_offset'          => null,
                'observed_at'          => $at,
                'available_at'         => $at,
                'computed_at'          => $derived ? $at : null,
                'source_kind'          => $derived ? 'DERIVED' : 'OBSERVED',
                'parents'              => $parents,
                'formula_id'           => $derived ? $formula : null,
                'formula_version'      => $derived ? 'v1' : null,
                'dependency_key'       => $field,
                'dependency_role'      => 'CONTEXT',
            ];
        };

        // @ANCHOR-20261020-PREDICTION-REALTIME  算出時刻が無い表は使いません
        //   ここで2つを<b>区別します</b>。混ぜると、直したあとも
        //   「列がありません」と出続けて、何をすれば載るのか読めません。
        //     列が無い   … 取得・算出側に記録する場所がまだ無い
        //     値がNULL   … 場所はあるが、その集計がまだ1回もまわっていない
        $noTime = []; $noTimeWhy = [];
        $tableNameByTag = [
            'popavg'  => 't_horse_odds_finder_popularity_rank_average',
            'fukuavg' => 't_horse_odds_finder_fuku_popularity_rank_average',
            'corr'    => 't_horse_odds_finder_compute_odds_correction',
        ];
        foreach ([['popavg', $popAvg], ['fukuavg', $fukuAvg], ['corr', $corr]] as $pair) {
            [$tag, $t] = $pair;
            if ($t['rows'] !== [] && $t['computed_at'] === null) {
                $noTime[$tag] = true;
                $noTimeWhy[$tag] =
                    \Schema::hasColumn((string) $tableNameByTag[$tag], 'computed_at')
                        ? 'value' : 'column';
                $t['rows'] = [];                 // 使いません
                if ($tag === 'popavg')  { $popAvg['rows']  = []; }
                if ($tag === 'fukuavg') { $fukuAvg['rows'] = []; }
                if ($tag === 'corr')    { $corr['rows']    = []; }
            }
        }

        // ── ① 集計の窓（レース単位で1組ずつ）───────────────────────
        foreach ([['popavg', $popAvg], ['fukuavg', $fukuAvg], ['corr', $corr]] as $pair) {
            [$tag, $t] = $pair;
            if ($t['window'] === null || $t['rows'] === []) { continue; }
            $at = $t['computed_at'];
            foreach (['start_date' => $t['window']['start'],
                      'end_date'   => $t['window']['end']] as $f => $v) {
                if ($v === '') { continue; }
                $ref = $tag . '_' . $f;
                $put($add, $ref, $v, $tag . '_' . $f, 'date', [], $at);
                $refs[($tag === 'corr') ? 'estimated_odds' : 'OPI'][] = $ref;
            }
        }

        // ── ② 人気順位ごとの集計（その順位の馬にだけ当てはまります）───
        foreach ($byRank as $rk => $list) {
            $at1 = ($popAvg['row_times'][$rk] ?? null);
            if (isset($popAvg['rows'][$rk])) {
                $r = $popAvg['rows'][$rk];
                $v = AiController::decimalOrNull($r->odds_average ?? null);
                if ($v !== null) {
                    $ref = 'popavg_rank' . $rk . '_win_odds';
                    $put($add, $ref, AiController::parseNumericValue(trim((string) $r->odds_average)), 'popavg_win_odds', 'odds', $list, $at1);
                    $refs['OPI'][] = $ref;
                }
                $n = AiController::intOrNull($r->count ?? null);
                if ($n !== null) {
                    $ref = 'popavg_rank' . $rk . '_count';
                    $put($add, $ref, AiController::parseNumericValue(trim((string) $r->count)), 'popavg_count', 'races', $list, $at1);
                    $refs['OPI'][] = $ref;
                }
            }
            $at2 = ($fukuAvg['row_times'][$rk] ?? null);
            if (isset($fukuAvg['rows'][$rk])) {
                $r = $fukuAvg['rows'][$rk];
                $v = AiController::decimalOrNull($r->odds_average ?? null);
                if ($v !== null) {
                    $ref = 'fukuavg_rank' . $rk . '_place_min';
                    $put($add, $ref, AiController::parseNumericValue(trim((string) $r->odds_average)), 'fukuavg_place_min', 'odds', $list, $at2);
                    $refs['OPI'][] = $ref;
                }
                $n = AiController::intOrNull($r->count ?? null);
                if ($n !== null) {
                    $ref = 'fukuavg_rank' . $rk . '_count';
                    $put($add, $ref, AiController::parseNumericValue(trim((string) $r->count)), 'fukuavg_count', 'races', $list, $at2);
                    $refs['OPI'][] = $ref;
                }
            }
            $at3 = ($corr['row_times'][$rk] ?? null);
            if (isset($corr['rows'][$rk])) {
                $r = $corr['rows'][$rk];
                foreach (['avg_correction_ratio' => 'ratio',
                          'std_correction_ratio' => 'ratio'] as $f => $unit) {
                    $v = AiController::decimalOrNull($r->{$f} ?? null);
                    if ($v === null) { continue; }
                    $ref = 'corr_rank' . $rk . '_' . $f;
                    $put($add, $ref, AiController::parseNumericValue(trim((string) $r->{$f})), 'corr_' . $f, $unit, $list, $at3);
                    $refs['estimated_odds'][] = $ref;
                }
                $n = AiController::intOrNull($r->sample_count ?? null);
                if ($n !== null) {
                    $ref = 'corr_rank' . $rk . '_sample_count';
                    $put($add, $ref, AiController::parseNumericValue(trim((string) $r->sample_count)), 'corr_sample_count', 'races', $list, $at3);
                    $refs['estimated_odds'][] = $ref;
                }
            }
        }

        // ── ③ 馬ごとの値（式はいまの商品と同じ）─────────────────────
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-DERIVEDAT
        // ══════════════════════════════════════════════════════════════
        //   【旧】observed_at / available_at / computed_at の3つに
        //     <b>同じ値（集計の算出時刻）</b>を入れていました。
        //     推定確定オッズは「今回の6分前オッズ × 補正係数」なのに、
        //     係数が前夜算出なら「今回のオッズを使った値が前夜に
        //     利用可能だった」という時刻関係になります。
        //   【いま】3つを分けます。
        //     observed_at / available_at … <b>親のうちいちばん遅い時刻</b>
        //       （親より過去へ戻しません）
        //     computed_at               … この値を実際に計算した時刻
        $later = static function (?string $a, ?string $b): ?string {
            if ($a === null) { return $b; }
            if ($b === null) { return $a; }
            $ua = BaganrikiTime::us($a); $ub = BaganrikiTime::us($b);
            if ($ua === null) { return $b; }
            if ($ub === null) { return $a; }
            return ($ub > $ua) ? $b : $a;
        };
        $putHorse = static function (array &$add, int $no, string $field, $value,
                                     string $unit, array $parents, string $formula,
                                     string $availAt, string $computedAt) use ($raceId) {
            $ref = 'h' . $no . '_' . $field;
            $add[$ref] = [
                'source_ref'           => $ref,
                'race_id'              => $raceId,
                'value'                => $value,
                'scope'                => 'HORSE',
                'horse_nos'            => [$no],
                'applicable_horse_nos' => [],
                'field'                => $field,
                'unit'                 => $unit,
                'slot_offset'          => 6,
                'observed_at'          => $availAt,
                'available_at'         => $availAt,
                'computed_at'          => $computedAt,
                'source_kind'          => 'DERIVED',
                'parents'              => $parents,
                'formula_id'           => $formula,
                'formula_version'      => 'v1',
                'dependency_key'       => 'h' . $no . '_' . $field,
                'dependency_role'      => 'CONTEXT',
            ];
            return $ref;
        };

        foreach ($frozenRank as $no => $rk) {
            $no = (int) $no; $rk = (int) $rk;
            // 6分前オッズが使えるようになった時刻（親のひとつ）
            $odds6At = ($freezeAt !== null) ? $freezeAt : $nowAt;
            $win6  = AiController::decimalOrNull($cells[$no][6]['win_odds']  ?? null);
            $fuku6 = AiController::decimalOrNull($cells[$no][6]['place_min'] ?? null);
            $pa = isset($popAvg['rows'][$rk])
                ? AiController::decimalOrNull($popAvg['rows'][$rk]->odds_average ?? null) : null;
            $fa = isset($fukuAvg['rows'][$rk])
                ? AiController::decimalOrNull($fukuAvg['rows'][$rk]->odds_average ?? null) : null;
            $cr = isset($corr['rows'][$rk])
                ? AiController::decimalOrNull($corr['rows'][$rk]->avg_correction_ratio ?? null) : null;

            // OPI = 過去平均単勝 ÷ 6分前単勝
            if ($pa !== null && $win6 !== null && $win6 > 0) {
                $refs['OPI'][] = $putHorse($add, $no, 'opi',
                    round($pa / $win6, 2), 'ratio',
                    ['popavg_rank' . $rk . '_win_odds', 'h' . $no . '_win_odds_6'],
                    'OPI_AVG_WIN_DIV_WIN6',
                    $later(($popAvg['row_times'][$rk] ?? null), $odds6At), $nowAt);
            }
            // 複勝OPI = 過去平均複勝最小 ÷ 6分前複勝最小
            if ($fa !== null && $fuku6 !== null && $fuku6 > 0) {
                $refs['OPI'][] = $putHorse($add, $no, 'fuku_opi',
                    round($fa / $fuku6, 2), 'ratio',
                    ['fukuavg_rank' . $rk . '_place_min', 'h' . $no . '_place_min_6'],
                    'FUKU_OPI_AVG_PLACE_DIV_PLACE6',
                    $later(($fukuAvg['row_times'][$rk] ?? null), $odds6At), $nowAt);
            }
            // 推定確定オッズ = 6分前単勝 × 補正係数
            $est = null;
            if ($cr !== null && $win6 !== null && $win6 > 0) {
                $est = round($win6 * $cr, 2);
                $refs['estimated_odds'][] = $putHorse($add, $no, 'estimated_final_odds',
                    $est, 'odds',
                    ['corr_rank' . $rk . '_avg_correction_ratio', 'h' . $no . '_win_odds_6'],
                    'EST_FINAL_ODDS_WIN6_X_RATIO',
                    $later(($corr['row_times'][$rk] ?? null), $odds6At), $nowAt);
            }
            // 推定確定複勝最小（複勝専用の係数は未実装なので単勝の係数を流用）
            if ($cr !== null && $fuku6 !== null && $fuku6 > 0) {
                $refs['estimated_odds'][] = $putHorse($add, $no, 'estimated_final_place_min',
                    round($fuku6 * $cr, 2), 'odds',
                    ['corr_rank' . $rk . '_avg_correction_ratio', 'h' . $no . '_place_min_6'],
                    'EST_FINAL_PLACE_MIN_PLACE6_X_WIN_RATIO',
                    $later(($corr['row_times'][$rk] ?? null), $odds6At), $nowAt);
            }
            // 予測補正OPI = 過去平均単勝 ÷ 推定確定オッズ
            if ($pa !== null && $est !== null && $est > 0) {
                $refs['OPI'][] = $putHorse($add, $no, 'estimated_opi',
                    round($pa / $est, 2), 'ratio',
                    ['popavg_rank' . $rk . '_win_odds', 'h' . $no . '_estimated_final_odds'],
                    'EST_OPI_AVG_WIN_DIV_EST_FINAL',
                    $later($later(($popAvg['row_times'][$rk] ?? null), ($corr['row_times'][$rk] ?? null)), $odds6At),
                    $nowAt);
            }
        }

        $cov = [];
        foreach (['OPI' => [$popAvg, $fukuAvg], 'estimated_odds' => [$corr]] as $k => $src) {
            $list = array_values(array_unique($refs[$k]));
            sort($list, SORT_STRING);
            if ($list !== []) {
                $cov[$k] = ['status' => 'INCLUDED', 'source_refs' => $list];
                continue;
            }
            $reason = null;
            // @ANCHOR-20261020-PREDICTION-REALTIME  算出時刻が無いことを理由に出します
            //   「列が無い」と「まだ値が入っていない」を分けて書きます。
            $missCol = []; $missVal = [];
            foreach (($k === 'OPI' ? ['popavg', 'fukuavg'] : ['corr']) as $tg) {
                if (empty($noTime[$tg])) { continue; }
                if (($noTimeWhy[$tg] ?? 'column') === 'value') { $missVal[] = $tg; }
                else { $missCol[] = $tg; }
            }
            if ($missCol !== [] || $missVal !== []) {
                $parts = [];
                if ($missCol !== []) {
                    $parts[] = '表に算出時刻（computed_at）の列がありません'
                             . '（該当 ' . implode('・', $missCol) . '）。'
                             . '取得・算出側で算出時刻を記録すれば、その日から使えます。';
                }
                if ($missVal !== []) {
                    $parts[] = '算出時刻（computed_at）の列はありますが、'
                             . 'まだ値が入っていません'
                             . '（該当 ' . implode('・', $missVal) . '）。'
                             // @ANCHOR-20261020-PREDICTION-COMPUTEDAT  10月20日に直しました
                             //   【旧】「1回まわれば入ります」と書いていましたが、
                             //     集計は<b>新しいレース結果が無い日はDBに
                             //     1行も書かずに終わる</b>作りでした。
                             //     平日は必ずそちらを通るので、永久に入りません。
                             //     この書き方は読む人に誤った見込みを与えます。
                             . 'その集計が次に走ったときに入ります'
                             . '（新しいレース結果が無い日も、値は変えずに'
                             . '算出時刻だけ入れ直すようにしました）。';
                }
                $reason = '情報上限より前に利用できた証拠が無いので使いません。'
                        . implode('', $parts);
            }
            if ($reason === null) {
                foreach ($src as $t) {
                    if ($t['bad'] !== null) { $reason = $t['bad']; break; }
                }
            }
            $cov[$k] = ['status' => 'UNAVAILABLE',
                        'reason' => $reason ?? '人気順位別の集計が見つかりませんでした'];
        }
        return ['registry' => $add, 'coverage' => $cov];
    }

    /** @ANCHOR-20261020-PREDICTION-MARKET  .env で止められます（既定は載せる） */
    public static function marketPriorEnabled(): bool
    {
        $v = (string) env('BAGANRIKI_MARKET_PRIOR', '1');
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** @ANCHOR-20261020-PREDICTION-MARKET  十進数として読めるときだけ float を返します */
    public static function decimalOrNull($v): ?float
    {
        if ($v === null) { return null; }
        $s = trim((string) $v);
        if ($s === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $s)) { return null; }
        return (float) $s;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-BANDKEEP  順位の指摘が付いた帯の名前だけを返します
     *   repair_scope.rank_bands には帯の名前（A/B/D）のほかに、
     *   項目名（band_ranks / evidence_band_ranks）が入ることがあります。
     *   帯の名前だけを取り出します。項目ごと壊れている場合は全帯を
     *   「指摘あり」として扱います（順位そのものが読めないためです）。
     */
    public static function invalidBandsOf($scope): array
    {
        $t = (array) (((array) $scope)['rank_bands'] ?? []);
        $bands = []; $wholeBroken = false;
        foreach ($t as $x) {
            $x = (string) $x;
            if ($x === 'A' || $x === 'B' || $x === 'D') { $bands[] = $x; continue; }
            if ($x === 'band_ranks' || $x === 'evidence_band_ranks') {
                $wholeBroken = true;
            }
        }
        if ($wholeBroken) { return ['A', 'B', 'D']; }
        return array_values(array_unique($bands));
    }

    /** @ANCHOR-20261020-PREDICTION-RESP  各馬の事実出典hash（加点の証明とは別に残します） */
    public static function fieldSourcesOf(array $horses): array
    {
        $o = [];
        foreach ($horses as $no => $h) {
            $refs = [];
            foreach ((array) ($h['evidence'] ?? []) as $e) {
                foreach ((array) ($e['source_refs'] ?? []) as $r) { $refs[] = (string) $r; }
            }
            foreach ((array) ($h['family_review'] ?? []) as $fam) {
                foreach ((array) ($fam['source_refs'] ?? []) as $r) { $refs[] = (string) $r; }
            }
            $refs = array_values(array_unique($refs));
            sort($refs, SORT_STRING);
            $o[(string) $no] = ['refs' => $refs,
                                'hash' => hash('sha256', implode("\n", $refs))];
        }
        return $o;
    }

    /** @ANCHOR-20261020-PREDICTION-RESP  順位の出どころ（帯ごとの方式と並び） */
    public static function rankSourcesOf(array $res): array
    {
        return [
            'band_ranks'          => (array) ($res['band_ranks'] ?? []),
            'evidence_band_ranks' => (array) ($res['evidence_band_ranks'] ?? []),
            'public_candidates'   => (array) ($res['public_candidates'] ?? []),
        ];
    }
}

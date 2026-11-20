<?php
namespace App\Console\Commands\AiAnalysisCompensate;

use App\Http\Controllers\Api\AiController;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 凍結・送信・検査・公開などの受入試験（--prediction-○○-test など）
 *
 * keiba:ai-analysis-compensate（SummaryAiAnalysisCompensate）の一部です。
 * 中身は元の SummaryAiAnalysisCompensate.php から、1文字も変えずに移しました。
 */
trait PredictionAcceptanceTests
{
    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-FREEZE  凍結入力の保存を走らせる
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 2章・5章／詳細設計書 4.4・7.2
     *
     *   一度固定した snapshot は上書きしません。
     *   入力を作れないレースは snapshot を作らず、baganriki_race_runs へ
     *   理由コードつきで残します（「何も起きなかった」と区別するため）。
     *   --prediction-freeze-dry なら1行も書きません。
     */
    private function runFreezeInput(bool $dry): void
    {
        self::loadPredictionClasses();
        $dates = array_values(array_filter(array_map('trim',
            explode(',', (string) ($this->option('date') ?: date('Y-m-d'))))));
        $assume = (bool) $this->option('prediction-prompt-assume');
        $offset = (int) ($this->option('prediction-prompt-sales-offset') ?: 0);

        $this->info('════════════════════════════════════════════════════════');
        $this->info($dry ? ' r12.2 凍結入力の保存（試すだけ・1行も書きません）'
                         : ' r12.2 凍結入力の保存');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  対象日   : ' . implode(' / ', $dates));
        if ($assume) $this->warn('  【仮】取得監査の実時刻を予定時刻で埋めます（本番の凍結には使いません）');
        if ($offset > 0) $this->warn('  【仮】投票締切を「発走の' . $offset . '秒前」として扱います（正本ではありません）');
        $this->line('');

        $ctrl = app(\App\Http\Controllers\Api\AiController::class);
        $byCode = []; $okN = 0; $skipN = 0; $rows = [];
        foreach ($dates as $date) {
            foreach (\DB::table('t_horse_odds_finder_races')->where('date', $date)
                        ->orderBy('basho')->orderBy('race')
                        ->get(['date', 'kaisuu', 'basho', 'day', 'race', 'start_time']) as $r) {
                $ov = [];
                if ($assume) $ov['assume_audit'] = true;
                if ($offset > 0) {
                    $st = (string) $r->start_time;
                    if (strlen($st) === 5) $st .= ':00';
                    $us = \App\Http\Controllers\Api\BaganrikiTime::us(
                        $r->date . 'T' . $st . '+09:00');
                    if ($us !== null) {
                        $ov['sales_close_at'] = \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339(
                            $us - $offset * 1000000);
                    }
                }
                $res = $ctrl->freezePredictionInput($r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                                         $ov, ($assume || $offset > 0) ? 'REPLAY' : 'PRODUCTION',
                                         $dry);
                $code = $res['ok'] ? ($res['skipped'] ? 'SKIP(既存)' : 'OK') : (string) $res['code'];
                $byCode[$code] = ($byCode[$code] ?? 0) + 1;
                if ($res['ok'] && !$res['skipped']) {
                    $okN++;
                    $c = $res['counts'];
                    $rows[] = sprintf('  ○ %-22s 出馬%2s 取得%2s 凍結%2s 活動%2s hash=%s',
                        $res['race_id'], $c['entry'] ?? '-', $c['acquired'] ?? '-',
                        $c['frozen'] ?? '-', $c['active'] ?? '-',
                        substr((string) $res['snapshot_hash'], 0, 12));
                } elseif ($res['skipped']) {
                    $skipN++;
                }
            }
        }
        ksort($byCode);
        foreach ($byCode as $k => $n) $this->line(sprintf('    %-30s %4d レース', $k, $n));
        $this->line('');
        foreach (array_slice($rows, 0, 12) as $x) $this->line($x);
        if (count($rows) > 12) $this->line('    …ほか ' . (count($rows) - 12) . ' レース');
        $this->line('');
        $this->line('  ※ 4つの頭数は別物です。出馬＝正本出馬表／取得＝読み込めた一意件数／');
        $this->line('    凍結＝情報上限までの取消を除いた頭数（N）／活動＝凍結後の取消を除いた頭数。');
        if ($dry) $this->warn('  ※ --prediction-freeze-dry なので1行も書いていません。');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info($dry ? ' 試行おわり（書き込み0件）'
                         : ' 保存 ' . $okN . ' 件／既存で触らず ' . $skipN . ' 件');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-ACQFIX  監査行→採用→入力→凍結保存→読戻しの通し試験
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ■ 何をするか
     *   1つのレースについて、<b>取得側と同じ形</b>の監査行を6スロットぶん作り、
     *   原ペイロードは保存済みオッズから組み立て、SHA-256も実際に計算します。
     *   そのうえで本番と同じ経路を通します。
     *     監査行の読込 → 原ペイロードのhash照合 → 採用バッチの選定
     *     → INPUT_JSON の組み立て → 凍結保存 → 読み戻し
     *
     * ■ 書き込みについて
     *   <b>全部ひとつのトランザクションで行い、最後に必ずロールバックします。</b>
     *   DBへ残る書き込みは0件です。既存テーブルには一切触りません。
     *
     * ■ 正直に書いておくこと
     *   ここで使う監査行は<b>試験用に作ったもの</b>です。実開催で取れた
     *   実時刻ではありません。実監査行での確認は10月24・25日に行います。
     *   これは「経路がつながっているか」を確かめる試験です。
     */
    private function runAcquisitionTest(): void
    {
        self::loadPredictionClasses();
        $date = (string) ($this->option('date') ?: '2026-10-18');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 監査行→採用→入力→凍結保存→読戻し の通し試験');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ 監査行は試験用に作ったものです（実開催の実時刻ではありません）');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');

        $r = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                ->orderBy('basho')->orderBy('race')->first();
        if ($r === null) { $this->error('  × 対象レースがありません: ' . $date); return; }
        $raceId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
            $r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
        $st = (string) $r->start_time;
        if (strlen($st) === 5) $st .= ':00';
        $anchorUs = \App\Http\Controllers\Api\BaganrikiTime::us($r->date . 'T' . $st . '+09:00');
        if ($anchorUs === null) { $this->error('  × 発走時刻を読めません'); return; }
        $this->line('  対象レース : ' . $raceId . '  発走 ' . $st);

        $slots = \App\Http\Controllers\Api\BaganrikiTime::INPUT_SLOTS;
        $rows  = [];
        $made  = 0;
        foreach ($slots as $slot) {
            // 保存済みオッズから、取得側が受け取るのと同じ形の原ペイロードを作ります
            $pl = [];
            foreach (\DB::table('t_horse_odds_finder_odds')
                        ->where('date', $r->date)->where('kaisuu', $r->kaisuu)
                        ->where('basho', $r->basho)->where('day', $r->day)
                        ->where('race', (int) $r->race)
                        ->where('minutes_before_start', $slot)
                        ->orderBy('num')->get(['num', 'odds', 'fuku_min', 'fuku_max']) as $o) {
                $pl[] = ['num' => (int) $o->num, 'tan' => (string) $o->odds,
                         'fuku_min' => (string) $o->fuku_min, 'fuku_max' => (string) $o->fuku_max];
            }
            if ($pl === []) { $this->line(sprintf('  － %2d分前 オッズが無いので監査行を作りません', $slot)); continue; }
            $raw = (string) json_encode($pl, JSON_UNESCAPED_UNICODE);
            $sch = \App\Http\Controllers\Api\BaganrikiTime::slotScheduled($anchorUs, $slot);
            $toDb = static function (int $us): string {
                return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                    ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                    ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
            };
            $rows[] = [
                'race_id' => $raceId, 'slot' => $slot,
                'batch_id' => 'ACQTEST-' . $raceId . '-' . $slot . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
                'slot_scheduled_at'    => $toDb($sch),
                'source_updated_at'    => null,
                'request_started_at'   => $toDb($sch + 500000),          // 予定 +0.5秒
                'response_received_at' => $toDb($sch + 1500000),         // 予定 +1.5秒
                'available_at'         => $toDb($sch + 2500000),         // 予定 +2.5秒
                'raw_payload'          => $raw,
                'payload_hash'         => hash('sha256', $raw),
                'validation_result'    => 'ADOPTED',
                'rejection_reason'     => null,
                'committed'            => 1,
                'created_at'           => $toDb($sch + 2500000),
            ];
            $made++;
            $this->line(sprintf('  － %2d分前 %2d頭ぶんの原ペイロード %6d バイト  sha256=%s',
                $slot, count($pl), strlen($raw), substr(hash('sha256', $raw), 0, 12)));
        }
        if ($made === 0) { $this->error('  × 監査行を1件も作れません'); return; }
        $this->line('');

        $ok = true;
        \DB::beginTransaction();
        try {
            \DB::table('baganriki_acquisition_batches')->insert($rows);
            $this->info('── ① 監査行を入れた（' . count($rows) . ' 件・ロールバックします）──');

            $ctrl = app(\App\Http\Controllers\Api\AiController::class);

            $this->info('── ② 監査行を読んで採用バッチを選び、INPUT_JSON を作る ──');
            // @ANCHOR-20261020-PREDICTION-ONEBUILD  ここは「作り直す側」です。
            //   仮の締切を差し込んで組み立てを見る試験なので、
            //   保存物を読む送信経路（assemblePredictionRequest）は通しません。
            $asm = $ctrl->assembleFreshPredictionRequest($r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                                           '1st', ['sales_close_at' =>
                \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($anchorUs - 60 * 1000000)]);
            if (!$asm['ok']) {
                $ok = false;
                $this->error('  × ' . $asm['code'] . ' ' . $asm['reason']);
            } else {
                $in = $asm['input'];
                $this->line('  ○ N=' . $in['N'] . '  mode=' . $in['mode']
                          . '／' . $in['mode_status']
                          . '  snapshot_hash=' . substr((string) $in['snapshot_hash'], 0, 16));
                $this->line('  ○ source_registry ' . count($in['source_registry']) . ' 件');
                $ad = [];
                foreach ($in['collection_audit'] as $k => $v) {
                    $ad[] = $k . '=' . ($v['status'] ?? '?');
                }
                $this->line('  ○ collection_audit ' . implode(' ', $ad));
                $six = $in['collection_audit']['6'] ?? null;
                if (is_array($six) && ($six['status'] ?? '') === 'ADOPTED') {
                    $this->line('  ○ 6分前 batch_id ' . $six['batch_id']);
                    $this->line('           request '  . $six['request_started_at']);
                    $this->line('           response ' . $six['response_received_at']);
                    $this->line('           available ' . $six['available_at']);
                    $this->line('           payload_hash ' . substr((string) $six['payload_hash'], 0, 16));
                    // 採用したバッチの原値が、そのまま登録に入っているか
                    $bt = null;
                    foreach ($rows as $x) { if ($x['batch_id'] === $six['batch_id']) { $bt = $x; break; } }
                    if ($bt === null) {
                        $ok = false; $this->error('  × 採用された batch_id が試験用の行に見つかりません');
                    } else {
                        if (hash('sha256', $bt['raw_payload']) !== $six['payload_hash']) {
                            $ok = false; $this->error('  × payload_hash が原ペイロードと合いません');
                        } else {
                            $this->line('  ○ payload_hash を再計算して一致を確認');
                        }
                        $pl = json_decode($bt['raw_payload'], true);
                        $miss = 0;
                        foreach ((array) $pl as $h) {
                            $n = (int) $h['num'];
                            $t = trim((string) $h['tan']);
                            if (!\App\Http\Controllers\Api\BaganrikiAcquisition::usableDecimal($t)) continue;
                            $ref = 'h' . $n . '_win_odds_6';
                            $got = $in['source_registry'][$ref]['value'] ?? null;
                            if ((string) $got !== (string) \App\Http\Controllers\Api\AiController::parseNumericValue($t)) $miss++;
                        }
                        if ($miss > 0) {
                            $ok = false;
                            $this->error('  × 採用バッチの原値と登録の値が違う馬が ' . $miss . ' 頭');
                        } else {
                            $this->line('  ○ 6分前の登録の値は、採用バッチの原値と全頭一致');
                        }
                    }
                }
            }

            if ($ok) {
                $this->info('── ③ 凍結保存して読み戻す ──');
                $fz = $ctrl->freezePredictionInput($r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                    ['sales_close_at' =>
                        \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($anchorUs - 60 * 1000000)],
                    'REPLAY', false);
                if (!$fz['ok']) {
                    $ok = false;
                    $this->error('  × 凍結保存できません: ' . $fz['code'] . ' ' . $fz['reason']);
                } else {
                    $c = $fz['counts'];
                    $this->line(sprintf('  ○ 保存できました 出馬%s 取得%s 凍結%s 活動%s',
                        $c['entry'] ?? '-', $c['acquired'] ?? '-',
                        $c['frozen'] ?? '-', $c['active'] ?? '-'));
                    // @ANCHOR-20261020-PREDICTION-SEP  REPLAY は別世代に保存されます
                    $gen = (int) ($fz['generation'] ?? 0);
                    $this->line('  ○ 保存先の世代 generation=' . $gen
                              . '（REPLAY は本番の0と分けています）');
                    $snap = \DB::table('baganriki_snapshots')->where('race_id', $raceId)
                               ->where('generation', $gen)->first();
                    if ($snap === null) {
                        $ok = false; $this->error('  × 読み戻せません');
                    } else {
                        $this->line('  ○ 読み戻し snapshot_hash ' . substr((string) $snap->snapshot_hash, 0, 16));
                        // ══════════════════════════════════════════════
                        // @ANCHOR-20261020-PREDICTION-DERIVEDAT  hash が違う理由
                        // ══════════════════════════════════════════════
                        //   上の行と番号が違うのは不具合ではありません。
                        //   上は<b>この試験が組み立てた</b>入力、
                        //   下は<b>凍結が組み立てて保存した</b>入力です。
                        //   派生値の computed_at に実計算時刻を入れる契約
                        //   （時刻A案）なので、組み立て直すと hash も変わります。
                        //   大事なのは「保存したものと読み戻したものが同じ」ことで、
                        //   それは次の行で確かめています。
                        $this->line('     ※ 上の hash と違うのは、組み立てが別だからです'
                                  . '（派生の計算時刻が入るため）。'
                                  . '保存と読み戻しが一致していることが要点です');
                        $this->line('           mode ' . $snap->mode . '／' . $snap->mode_status
                                  . '  frozen_at ' . $snap->frozen_at);
                        $j = json_decode((string) $snap->input_json_1st, true);
                        if (!is_array($j) || ($j['snapshot_hash'] ?? null) !== $snap->snapshot_hash) {
                            $ok = false; $this->error('  × 保存した入力と snapshot_hash が合いません');
                        } else {
                            $this->line('  ○ 保存した INPUT_JSON を読み直せて、hash も一致');
                        }
                        $run = \DB::table('baganriki_race_runs')->where('race_id', $raceId)->first();
                        if ($run !== null) {
                            $this->line('  ○ race_runs status=' . $run->status
                                      . ' run_kind=' . $run->run_kind
                                      . ' generation=' . $run->generation
                                      . ' 出馬' . $run->entry_count . ' 凍結' . $run->frozen_count);
                        }
                        // ── ④ 仮置きを本番として保存しようとしたら止まるか ──
                        $this->info('── ④ 仮置きの入力を PRODUCTION で保存しようとする ──');
                        $ng = $ctrl->freezePredictionInput($r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                            ['sales_close_at' =>
                                \App\Http\Controllers\Api\BaganrikiPrompt::rfc3339($anchorUs - 60 * 1000000)],
                            'PRODUCTION', false);
                        if (($ng['ok'] ?? false) === false && ($ng['code'] ?? '') === 'REPLAY_ONLY') {
                            $this->line('  ○ 止まりました: ' . $ng['reason']);
                        } else {
                            $ok = false;
                            $this->error('  × 仮置きの入力が PRODUCTION として保存できてしまいます');
                        }
                        $p0 = \DB::table('baganriki_snapshots')->where('race_id', $raceId)
                                 ->where('generation', 0)->count();
                        if ($p0 === 0) {
                            $this->line('  ○ 本番の世代（generation=0）には1件も入っていません');
                        } else {
                            $ok = false;
                            $this->error('  × 本番の世代に ' . $p0 . ' 件入ってしまいました');
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('  × 例外: ' . $e->getMessage());
        } finally {
            \DB::rollBack();
        }

        $this->line('');
        $n1 = \DB::table('baganriki_acquisition_batches')->count();
        $n2 = \DB::table('baganriki_snapshots')->count();
        $n3 = \DB::table('baganriki_race_runs')->count();
        $this->line(sprintf('  ロールバック後の件数  監査 %d 件／snapshot %d 件／race_runs %d 件',
            $n1, $n2, $n3));
        if ($n1 !== 0 || $n2 !== 0 || $n3 !== 0) {
            $this->warn('  ※ 0件でない場合は、試験の前から入っていた行です');
        }
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info($ok ? ' 監査行から凍結保存まで通りました（DBへ残る書き込み0件）'
                        : ' 通らなかった箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-QUEUE  キューと永続予約の通し試験
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 10章・11章
     *
     * ■ 何を確かめるか
     *   1. 枠は1レース・1世代・1roleにつき2つだけ（初回0・訂正1）
     *   2. 同じ枠は作り直さない（重複キュー・期限切れ・再起動のどれでも）
     *   3. 予約を確定してから送る。送信許可は1回だけ
     *   4. 初回の原応答と complete を保存してから訂正に入る
     *   5. 同じ token に同じ応答は冪等。別の応答・終端の付け替えは拒否
     *   6. complete=false の本文は残すが選出に使わない
     *   7. 期限・全頭取消・版の変化では予約しない
     *   8. 再起動：SENT は結果不明にして再送しない。RESERVED は枠として残る
     *   9. response_revision は 予約の回数＋終端が変わった回数
     *  10. 読み取りは token も原文も返さない
     *
     * ■ 書き込みについて
     *   <b>全部ひとつのトランザクションで行い、最後に必ずロールバックします。</b>
     *   DBへ残る書き込みは0件です。
     */
    private function runQueueTest(): void
    {
        self::loadPredictionClasses();

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 キューと永続予約の通し試験');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');

        if (!\App\Http\Controllers\Api\BaganrikiWorker::ready()) {
            $this->error('  × 必要な表がありません'
                . '（baganriki_snapshots / _ai_attempts / _live_states / _audit_events）');
            return;
        }

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-10s %-52s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };

        // 試験用の凍結入力を作る小さな道具
        $j = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mkSnap = function (string $raceId, int $salesUs, int $active, int $gen = 900)
                  use ($j): int {
            $id = \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => $raceId, 'generation' => $gen, 'run_id' => 'QT-' . $raceId,
                'release_version' => \App\Http\Controllers\Api\BaganrikiPrompt::PROMPT_VERSION,
                'snapshot_hash'  => hash('sha256', $raceId),
                'frozen_nums'    => $j(range(1, max(1, $active))),
                'target_count'   => 5,
                'quota_1st'      => $j([3, 2, 0]),
                'sales_close_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($salesUs),
                'created_at'     => \App\Http\Controllers\Api\BaganrikiWorker::toDb(\App\Http\Controllers\Api\BaganrikiWorker::nowUs()),
            ]);
            \DB::table('baganriki_live_states')->insert([
                'snapshot_id' => $id, 'cancellations' => $j([]),
                'active_count' => $active, 'live_target_count' => 5,
                'live_quota' => $j([3, 2, 0]), 'live_close' => null,
                'state_revision' => 0, 'response_revision' => 0,
                'publication_revision' => 0, 'latest_publication_id' => null,
                'updated_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb(\App\Http\Controllers\Api\BaganrikiWorker::nowUs()),
            ]);
            return (int) $id;
        };
        $rev = static fn(int $sid) => (int) \DB::table('baganriki_live_states')
                 ->where('snapshot_id', $sid)->value('response_revision');

        $ok = true;
        \DB::beginTransaction();
        try {
            // 現在時刻は「期限のだいぶ前」に置きます
            $now   = \App\Http\Controllers\Api\BaganrikiWorker::nowUs();
            $sales = $now + 3600 * 1000000;          // 投票締切は1時間後
            $dl    = \App\Http\Controllers\Api\BaganrikiTime::effectiveDeadlines($sales, null);

            $this->info('── ① 初回の予約と送信許可 ──');
            $sid = $mkSnap('QT-20261020-A', $sales, 16);
            $r = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sid, '1st', 0, $now);
            $check('Q-01', '初回の枠を予約できる', $r['ok'], true);
            $tok1 = (string) ($r['token'] ?? '');
            $check('Q-02', '予約で response_revision が1増える', $rev($sid), 1);

            $r2 = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sid, '1st', 0, $now);
            $check('Q-03', '同じ枠は作り直さない', $r2['code'], 'SLOT_TAKEN');
            $check('Q-04', '作り直さないので版も増えない', $rev($sid), 1);

            $g = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sid, '1st', 1, $now);
            $check('Q-05', '初回が未受領なら訂正を予約しない', $g['code'], 'FIRST_NOT_RECEIVED');

            $rc = \App\Http\Controllers\Api\BaganrikiWorker::receive($tok1, '{"a":1}', true, $now);
            $check('Q-06', '送信許可の前に受領はできない', $rc['code'], 'NOT_SENT');

            $s = \App\Http\Controllers\Api\BaganrikiWorker::allowSend($tok1, $now);
            $check('Q-07', '送信許可が出る', $s['ok'], true);
            $s2 = \App\Http\Controllers\Api\BaganrikiWorker::allowSend($tok1, $now);
            $check('Q-08', '送信許可は1回だけ', $s2['code'], 'ALREADY_SENT');

            $this->info('── ② 受領の冪等と付け替えの拒否 ──');
            $rc = \App\Http\Controllers\Api\BaganrikiWorker::receive($tok1, '{"a":1}', true, $now + 1000);
            $check('Q-09', '原応答を保存できる', $rc['ok'], true);
            $check('Q-10', '受領で version が1増える', $rev($sid), 2);
            $rc2 = \App\Http\Controllers\Api\BaganrikiWorker::receive($tok1, '{"a":1}', true, $now + 2000);
            $check('Q-11', '同じ応答の再保存は冪等', $rc2['code'], 'IDEMPOTENT');
            $check('Q-12', '冪等なら版は増えない', $rev($sid), 2);
            $rc3 = \App\Http\Controllers\Api\BaganrikiWorker::receive($tok1, '{"a":2}', true, $now + 3000);
            $check('Q-13', '別の応答は保存しない', $rc3['code'], 'RESPONSE_MISMATCH');
            $f = \App\Http\Controllers\Api\BaganrikiWorker::fail($tok1, 'あとから失敗にしてみる', $now + 4000);
            $check('Q-14', '終端の付け替えは拒否', $f['code'], 'TERMINAL');

            $this->info('── ③ 訂正の枠 ──');
            $g = \App\Http\Controllers\Api\BaganrikiWorker::fixAllowed($sid, '1st');
            $check('Q-15', '初回がcomplete=trueなら訂正できる', $g['ok'], true);
            $rf = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sid, '1st', 1, $now + 5000);
            $check('Q-16', '訂正の枠を予約できる', $rf['ok'], true);
            $tokF = (string) ($rf['token'] ?? '');
            $check('Q-17', '訂正の予約で版が1増える', $rev($sid), 3);
            $r3 = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sid, '1st', 2, $now + 6000);
            $check('Q-18', '3つめの枠は作らない', $r3['code'], 'INVALID_ATTEMPT');
            $g2 = \App\Http\Controllers\Api\BaganrikiWorker::fixAllowed($sid, '1st');
            $check('Q-19', '訂正の枠はもう作り直さない', $g2['code'], 'SLOT_TAKEN');

            \App\Http\Controllers\Api\BaganrikiWorker::allowSend($tokF, $now + 7000);
            $rbad = \App\Http\Controllers\Api\BaganrikiWorker::receive($tokF, '{"fix":1}', true, $now - 10000);
            $check('Q-20', '訂正の受領が初回より前なら拒否', $rbad['code'], 'RECEIVE_ORDER');
            $rgood = \App\Http\Controllers\Api\BaganrikiWorker::receive($tokF, '{"fix":1}', true, $now + 8000);
            $check('Q-21', '訂正の受領（初回以降の時刻）は通る', $rgood['ok'], true);
            $u = \App\Http\Controllers\Api\BaganrikiWorker::usable($sid, '1st');
            $check('Q-22', '選出には訂正を使う', $u['attempt_no'] ?? null, 1);
            $check('Q-23', '版は 予約2＋終端2 = 4', $rev($sid), 4);

            $this->info('── ④ complete=false は選出に使わない ──');
            $sidB = $mkSnap('QT-20261020-B', $sales, 14);
            $rb = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidB, '2nd', 0, $now);
            $tokB = (string) ($rb['token'] ?? '');
            \App\Http\Controllers\Api\BaganrikiWorker::allowSend($tokB, $now);
            $rcb = \App\Http\Controllers\Api\BaganrikiWorker::receive($tokB, '{"partial":true}', false, $now + 1000);
            $check('Q-24', 'complete=false でも本文は保存する', $rcb['ok'], true);
            $ub = \App\Http\Controllers\Api\BaganrikiWorker::usable($sidB, '2nd');
            $check('Q-25', 'complete=false は選出に使わない', $ub['code'], 'NONE');
            $gb = \App\Http\Controllers\Api\BaganrikiWorker::fixAllowed($sidB, '2nd');
            $check('Q-26', 'complete=false では訂正を予約しない', $gb['code'], 'FIRST_NOT_COMPLETE');

            $this->info('── ⑤ 期限・全頭取消・版の変化 ──');
            $sidC = $mkSnap('QT-20261020-C', $sales, 12);
            $late = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidC, '1st', 0, (int) $dl['reply'] + 1);
            $check('Q-27', '応答期限を1マイクロ秒でも超えたら予約しない',
                   $late['code'], 'DEADLINE_PASSED');
            $just = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidC, '1st', 0, (int) $dl['reply']);
            $check('Q-28', '期限ちょうどは予約できる', $just['ok'], true);

            $sidD = $mkSnap('QT-20261020-D', $sales, 0);
            $allc = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidD, '1st', 0, $now);
            $check('Q-29', '全頭取消では予約しない', $allc['code'], 'ALL_CANCELLED');

            $sidE = $mkSnap('QT-20261020-E', $sales, 10);
            \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidE, '1st', 0, $now);              // ここで版が1になる
            $stale = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidE, '2nd', 0, $now, ['response_revision' => 0]);
            $check('Q-30', '版が変わっていたら予約しない', $stale['code'], 'STATE_CHANGED');
            $fresh = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidE, '2nd', 0, $now, ['response_revision' => 1]);
            $check('Q-31', '版が合っていれば予約できる', $fresh['ok'], true);

            $this->info('── ⑥ 再起動（結果不明にして再送しない）──');
            $sidF = $mkSnap('QT-20261020-F', $sales, 16);
            $t1 = (string) \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidF, '1st', 0, $now)['token'];
            \App\Http\Controllers\Api\BaganrikiWorker::allowSend($t1, $now);                         // SENT のまま落ちた
            $t2 = (string) \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidF, '2nd', 0, $now)['token'];  // RESERVED のまま
            $before = $rev($sidF);
            $rs = \App\Http\Controllers\Api\BaganrikiWorker::resume($sidF, $now + 1000);
            $check('Q-32', '送信済みは結果不明にする', $rs['unknown'], ['1st#0']);
            $check('Q-33', '未送信の枠はそのまま残す', $rs['reserved_kept'], ['2nd#0']);
            $check('Q-34', '結果不明は終端なので版が1増える', $rev($sidF), $before + 1);
            $again = \App\Http\Controllers\Api\BaganrikiWorker::reserve($sidF, '1st', 0, $now + 2000);
            $check('Q-35', '再起動でも枠は作り直さない', $again['code'], 'SLOT_TAKEN');
            $gf = \App\Http\Controllers\Api\BaganrikiWorker::fixAllowed($sidF, '1st');
            $check('Q-36', '結果不明の初回では訂正を予約しない', $gf['code'], 'FIRST_FAILED');
            $sr = \App\Http\Controllers\Api\BaganrikiWorker::allowSend($t2, $now + 3000);
            $check('Q-37', '未送信だった枠は送信許可が1回残る', $sr['ok'], true);
            $rs2 = \App\Http\Controllers\Api\BaganrikiWorker::resume($sidF, $now + 4000);
            $check('Q-38', '2回目の再起動で終端は動かさない', $rs2['unknown'], ['2nd#0']);

            $this->info('── ⑦ 読み取りに token と原文を出さない ──');
            $sl = \App\Http\Controllers\Api\BaganrikiWorker::slots($sid, '1st');
            $keys = ($sl === []) ? [] : array_keys($sl[0]);
            $check('Q-39', 'slots に token は無い', in_array('token', $keys, true), false);
            $check('Q-40', 'slots に原文は無い', in_array('raw_response', $keys, true), false);
            $check('Q-41', 'slots は2枠を返す', count($sl), 2);

            $this->info('── ⑧ 監査が残っているか ──');
            $ev = \DB::table('baganriki_audit_events')
                    ->where('race_id', 'QT-20261020-A')->pluck('kind')->all();
            $check('Q-42', 'Aレースの監査は 予約・送信・受領 で6件', count($ev), 6);
            $check('Q-43', '予約の監査が2件', count(array_filter($ev,
                   static fn($k) => $k === 'QUEUE_RESERVED')), 2);
            $evF = \DB::table('baganriki_audit_events')
                     ->where('race_id', 'QT-20261020-F')
                     ->where('kind', 'QUEUE_UNKNOWN_RESULT')->count();
            $check('Q-44', '結果不明の監査が2件', $evF, 2);

            // ───────────────────────────────────────────────────────────
            // ⑨ 送信口（reserveAiSlot / Receive / Fail）のつなぎ
            //    実AIは呼びません。凍結入力は試験用に1件だけ入れます。
            // ───────────────────────────────────────────────────────────
            if (function_exists('app')) {
                $this->info('── ⑨ 送信口のつなぎ（実AIは呼びません）──');
                $ctrl  = app(\App\Http\Controllers\Api\AiController::class);
                $keep  = [getenv('BAGANRIKI_PREDICTION_QUEUE'),
                          $_ENV['BAGANRIKI_PREDICTION_QUEUE'] ?? null,
                          $_SERVER['BAGANRIKI_PREDICTION_QUEUE'] ?? null];
                // @ANCHOR-20261023-NARU-ENTRY  枠を取る口も対象レースを見るようになったので、対象の旗も退避します
                $keepT = [getenv('BAGANRIKI_PREDICTION_TARGETS'),
                          $_ENV['BAGANRIKI_PREDICTION_TARGETS'] ?? null,
                          $_SERVER['BAGANRIKI_PREDICTION_TARGETS'] ?? null];
                try {
                    // 旗が下りているときは素通り（1バイトも変わらない）
                    putenv('BAGANRIKI_PREDICTION_QUEUE=0');
                    $_ENV['BAGANRIKI_PREDICTION_QUEUE'] = '0';
                    // @ANCHOR-20261023-NARU-STARTTIME  場コードを 04 → 99（実在しない場）にしました。期限の判定が実在のレース
                    //   （10/18 新潟1R）の発走時刻を読むようになり、未来の締切を置いた試験の凍結入力と食い違うためです。
                    $_SERVER['BAGANRIKI_PREDICTION_QUEUE'] = '0';
                    $w = $ctrl->reserveAiSlot('2026-10-18', '4', '99', '2', 1,
                                                 '1st', ['snapshot_hash' => 'x']);
                    $check('W-01', '旗が0なら素通りする（skip）', $w['skip'], true);

                    // 旗を上げて、試験用の凍結入力（generation=0）を1件だけ置きます。
                    //   すでに固定済みなので freezePredictionInput は呼ばれません。
                    putenv('BAGANRIKI_PREDICTION_QUEUE=1');
                    $_ENV['BAGANRIKI_PREDICTION_QUEUE'] = '1';
                    $_SERVER['BAGANRIKI_PREDICTION_QUEUE'] = '1';
                    $rid  = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
                                '2026-10-18', '4', '99', '2', 1);
                    $hash = hash('sha256', $rid);
                    // @ANCHOR-20261023-NARU-ENTRY  この試験レースを対象に入れます
                    putenv('BAGANRIKI_PREDICTION_TARGETS=' . $rid);
                    $_ENV['BAGANRIKI_PREDICTION_TARGETS'] = $rid;
                    $_SERVER['BAGANRIKI_PREDICTION_TARGETS'] = $rid;
                    $sidW = \DB::table('baganriki_snapshots')->insertGetId([
                        'race_id' => $rid, 'generation' => 0, 'run_id' => 'QT-W',
                        'snapshot_hash' => $hash, 'frozen_nums' => $j(range(1, 12)),
                        'target_count' => 5, 'quota_1st' => $j([3, 2, 0]),
                        'sales_close_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($sales),
                        'created_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
                    ]);
                    \DB::table('baganriki_live_states')->insert([
                        'snapshot_id' => $sidW, 'cancellations' => $j([]),
                        'active_count' => 12, 'live_target_count' => 5,
                        'live_quota' => $j([3, 2, 0]), 'live_close' => null,
                        'state_revision' => 0, 'response_revision' => 0,
                        'publication_revision' => 0, 'latest_publication_id' => null,
                        'updated_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
                    ]);

                    // 固定した入力と送信文のhashが違えば送りません
                    $wNg = $ctrl->reserveAiSlot('2026-10-18', '4', '99', '2', 1,
                               '1st', ['snapshot_hash' => 'ちがうhash']);
                    $check('W-02', 'hashが違えば送らない', $wNg['code'], 'SNAPSHOT_MISMATCH');

                    // hashが合えば予約して送信許可まで進みます
                    $wOk = $ctrl->reserveAiSlot('2026-10-18', '4', '99', '2', 1,
                               '1st', ['snapshot_hash' => $hash]);
                    $check('W-03', 'hashが合えば枠を取って送信許可まで進む', $wOk['ok'], true);
                    $check('W-04', '予約した枠は初回（0）', (int) \DB::table('baganriki_ai_attempts')
                        ->where('snapshot_id', $sidW)->value('attempt_no'), 0);

                    // 2回目は送りません（重複送信の防止）
                    $wAgain = $ctrl->reserveAiSlot('2026-10-18', '4', '99', '2', 1,
                                  '1st', ['snapshot_hash' => $hash]);
                    $check('W-05', '2回目は送らない', $wAgain['code'], 'SLOT_TAKEN');

                    $cw = $ctrl->receiveAiSlotResponse($wOk, '{"w":1}', true);
                    $check('W-06', '送信口から原応答を保存できる', $cw['ok'], true);
                    $cw2 = $ctrl->receiveAiSlotResponse($wOk, '{"w":1}', true);
                    $check('W-07', '送信口から2回保存しても冪等', $cw2['code'], 'IDEMPOTENT');
                    $fw = $ctrl->failAiSlot($wOk, '試験', true);
                    $check('W-08', '終端になった枠は付け替えない', $fw['code'], 'TERMINAL');
                    $cwS = $ctrl->receiveAiSlotResponse(['skip' => true, 'ok' => false],
                               '{"w":2}', true);
                    $check('W-09', '素通りの枠には何もしない', $cwS['skip'], true);

                    // ── ⑩ shadow（記録はするが送信は止めない）─────────
                    $this->info('── ⑩ shadow（記録はするが送信を止めない）──');
                    $setq = static function (string $v) {
                        putenv('BAGANRIKI_PREDICTION_QUEUE=' . $v);
                        $_ENV['BAGANRIKI_PREDICTION_QUEUE'] = $v;
                        $_SERVER['BAGANRIKI_PREDICTION_QUEUE'] = $v;
                    };
                    $setq('0');
                    $check('S-01', '0 は何もしない',
                        [\App\Http\Controllers\Api\BaganrikiWorker::mode(),
                         \App\Http\Controllers\Api\BaganrikiWorker::enabled(),
                         \App\Http\Controllers\Api\BaganrikiWorker::blocking()],
                        ['off', false, false]);
                    $setq('shadow');
                    $check('S-02', 'shadow は動くが止めない',
                        [\App\Http\Controllers\Api\BaganrikiWorker::mode(),
                         \App\Http\Controllers\Api\BaganrikiWorker::enabled(),
                         \App\Http\Controllers\Api\BaganrikiWorker::blocking()],
                        ['shadow', true, false]);
                    $setq('1');
                    $check('S-03', '1 は契約どおり止める',
                        [\App\Http\Controllers\Api\BaganrikiWorker::mode(),
                         \App\Http\Controllers\Api\BaganrikiWorker::enabled(),
                         \App\Http\Controllers\Api\BaganrikiWorker::blocking()],
                        ['on', true, true]);

                    // shadow で枠が取れなくても shadow=true（呼出側は送ります）
                    $setq('shadow');
                    $sh = $ctrl->reserveAiSlot('2026-10-18', '4', '99', '2', 1,
                              '1st', ['snapshot_hash' => 'ちがうhash']);
                    $check('S-04', 'shadow は失敗しても止めない印を返す',
                        [$sh['ok'], $sh['shadow'], $sh['code']],
                        [false, true, 'SNAPSHOT_MISMATCH']);
                    $setq('1');
                    $on = $ctrl->reserveAiSlot('2026-10-18', '4', '99', '2', 1,
                              '1st', ['snapshot_hash' => 'ちがうhash']);
                    $check('S-05', '1 のときは止める印を返す',
                        [$on['ok'], $on['shadow']], [false, false]);

                    // ── 待ち時間を応答期限から決める ───────────────
                    $this->info('── ⑪ AIを待つ時間（応答期限から決めます）──');
                    $T = \App\Http\Controllers\Api\BaganrikiTime::class;
                    $check('S-06', '予約が無ければ今までの秒数',
                        $ctrl->aiTimeoutFromDeadline(90, null), 90);
                    $check('S-07', '素通りでも今までの秒数',
                        $ctrl->aiTimeoutFromDeadline(90, ['skip' => true, 'ok' => false]), 90);
                    $far = ['skip' => false, 'ok' => true,
                            'reply_deadline' => $T::toJst($now + 600 * 1000000)];
                    $check('S-08', '期限が遠ければ170秒で止める',
                        $ctrl->aiTimeoutFromDeadline(90, $far), 170);
                    // 期限は「いま」から数え直します。試験の途中で実時間が
                    //   進むので、$now を使い回すと1秒ずれて落ちます。
                    //   秒の端で割れるので、1秒の幅を許します。
                    $mid = ['skip' => false, 'ok' => true,
                            'reply_deadline' => $T::toJst(
                                \App\Http\Controllers\Api\BaganrikiWorker::nowUs()
                                + 100 * 1000000)];
                    $t09 = $ctrl->aiTimeoutFromDeadline(240, $mid);
                    $check('S-09', '期限が近ければ短くする（5秒残す）',
                        ($t09 === 95 || $t09 === 94), true);
                    $near = ['skip' => false, 'ok' => true,
                             'reply_deadline' => $T::toJst($now + 10 * 1000000)];
                    $check('S-10', '下限は20秒',
                        $ctrl->aiTimeoutFromDeadline(90, $near), 20);
                    $past = ['skip' => false, 'ok' => true,
                             'reply_deadline' => $T::toJst($now - 60 * 1000000)];
                    $check('S-11', '期限を過ぎていても20秒',
                        $ctrl->aiTimeoutFromDeadline(90, $past), 20);

                    // ── ⑫ 本文の旗の3段 ──────────────────────────
                    $this->info('── ⑫ 本文の旗（0／shadow／1）──');
                    $kp = [getenv('BAGANRIKI_PREDICTION_PROMPT'),
                           $_ENV['BAGANRIKI_PREDICTION_PROMPT'] ?? null,
                           $_SERVER['BAGANRIKI_PREDICTION_PROMPT'] ?? null];
                    $setp = static function (string $v) {
                        putenv('BAGANRIKI_PREDICTION_PROMPT=' . $v);
                        $_ENV['BAGANRIKI_PREDICTION_PROMPT'] = $v;
                        $_SERVER['BAGANRIKI_PREDICTION_PROMPT'] = $v;
                    };
                    $P = \App\Http\Controllers\Api\BaganrikiPrompt::class;
                    try {
                        $setp('0');
                        $check('S-12', '0 は旧本文のまま',
                            [$P::mode(), $P::enabled(), $P::sending()],
                            ['off', false, false]);
                        $setp('shadow');
                        $check('S-13', 'shadow は入力を作るが送らない',
                            [$P::mode(), $P::enabled(), $P::sending()],
                            ['shadow', true, false]);
                        $setp('1');
                        $check('S-14', '1 は r12.2 の本文で送る',
                            [$P::mode(), $P::enabled(), $P::sending()],
                            ['on', true, true]);
                    } finally {
                        if ($kp[0] === false) { putenv('BAGANRIKI_PREDICTION_PROMPT'); }
                        else { putenv('BAGANRIKI_PREDICTION_PROMPT=' . $kp[0]); }
                        if ($kp[1] === null) { unset($_ENV['BAGANRIKI_PREDICTION_PROMPT']); }
                        else { $_ENV['BAGANRIKI_PREDICTION_PROMPT'] = $kp[1]; }
                        if ($kp[2] === null) { unset($_SERVER['BAGANRIKI_PREDICTION_PROMPT']); }
                        else { $_SERVER['BAGANRIKI_PREDICTION_PROMPT'] = $kp[2]; }
                    }
                } finally {
                    // 旗を元へ戻します（この後の処理に残さないため）
                    if ($keepT[0] === false) { putenv('BAGANRIKI_PREDICTION_TARGETS'); }
                    else { putenv('BAGANRIKI_PREDICTION_TARGETS=' . $keepT[0]); }
                    if ($keepT[1] === null) { unset($_ENV['BAGANRIKI_PREDICTION_TARGETS']); }
                    else { $_ENV['BAGANRIKI_PREDICTION_TARGETS'] = $keepT[1]; }
                    if ($keepT[2] === null) { unset($_SERVER['BAGANRIKI_PREDICTION_TARGETS']); }
                    else { $_SERVER['BAGANRIKI_PREDICTION_TARGETS'] = $keepT[2]; }
                    if ($keep[0] === false) { putenv('BAGANRIKI_PREDICTION_QUEUE'); }
                    else { putenv('BAGANRIKI_PREDICTION_QUEUE=' . $keep[0]); }
                    if ($keep[1] === null) { unset($_ENV['BAGANRIKI_PREDICTION_QUEUE']); }
                    else { $_ENV['BAGANRIKI_PREDICTION_QUEUE'] = $keep[1]; }
                    if ($keep[2] === null) { unset($_SERVER['BAGANRIKI_PREDICTION_QUEUE']); }
                    else { $_SERVER['BAGANRIKI_PREDICTION_QUEUE'] = $keep[2]; }
                }
            }

            // ───────────────────────────────────────────────────────────
            // ⑬ @ANCHOR-20261020-PREDICTION-WIRE  実送信の後への接続（）
            //    実AIは呼びません。呼ぶ手前で断る経路と、
            //    依頼本文の組み立て、公開の待ち合わせ、そして
            //    「本番の関数から本当に呼ばれているか」を確かめます。
            // ───────────────────────────────────────────────────────────
            if (function_exists('app')) {
                $this->info('── ⑬ 実送信の後への接続（006の6-4。実AIは呼びません）──');
                $ctrlX = app(\App\Http\Controllers\Api\AiController::class);
                $A = \App\Http\Controllers\Api\AiController::class;
                $W = \App\Http\Controllers\Api\BaganrikiWorker::class;

                // 旗を一時的に動かすための道具（終わったら必ず戻します）
                $keepX = [];
                // @ANCHOR-20261023-PREDICTION-QTTARGET  対象レースの旗も退避して戻します
                foreach (['BAGANRIKI_PREDICTION_PROMPT', 'BAGANRIKI_PREDICTION_QUEUE',
                          'BAGANRIKI_PREDICTION_TARGETS'] as $kk) {
                    $keepX[$kk] = [getenv($kk), $_ENV[$kk] ?? null, $_SERVER[$kk] ?? null];
                }
                $setX = static function (string $k, string $v) {
                    putenv($k . '=' . $v);
                    $_ENV[$k] = $v; $_SERVER[$k] = $v;
                };
                try {
                    // ── 設定の食い違い（006：旗を1にすれば完成経路にしない）──
                    $setX('BAGANRIKI_PREDICTION_PROMPT', '0');
                    $setX('BAGANRIKI_PREDICTION_QUEUE', '0');
                    $x1 = $A::checkPredictionFlagConfig();
                    $check('X-01', '旧本文のままなら何も言わない',
                        [$x1['ok'], $x1['code']], [true, 'OK']);

                    $setX('BAGANRIKI_PREDICTION_PROMPT', '1');
                    $setX('BAGANRIKI_PREDICTION_QUEUE', '0');
                    $x2 = $A::checkPredictionFlagConfig();
                    $check('X-02', '本文だけ1で枠が0なら送らせない',
                        [$x2['ok'], $x2['code']], [false, 'QUEUE_REQUIRED']);

                    $setX('BAGANRIKI_PREDICTION_QUEUE', 'shadow');
                    $x3 = $A::checkPredictionFlagConfig();
                    $check('X-03', '枠がshadowなら進めるが印を付ける',
                        [$x3['ok'], $x3['code']], [true, 'QUEUE_SHADOW']);

                    $setX('BAGANRIKI_PREDICTION_QUEUE', '1');
                    $x4 = $A::checkPredictionFlagConfig();
                    $check('X-04', '両方1なら契約どおり',
                        [$x4['ok'], $x4['code']], [true, 'OK']);

                    // ── 旗が下りている枠では1バイトも動かない ──
                    $x5 = $ctrlX->afterAiResponseReceived('2026-10-18', '4', '99', '2', 9,
                              '1st', ['skip' => true, 'ok' => false]);
                    $check('X-05', '素通りの枠では接続を動かさない', $x5['ran'], false);

                    // ── 訂正依頼の本文 ──
                    // @ANCHOR-20261023-NARU-STARTTIME  場コード 04 → 99（実在しない場）。期限の判定が実在のレースの
                    //   発走時刻を読むようになり、未来の締切を置いた試験の凍結入力と食い違うためです。
                    $ridX = $A::buildPredictionRaceId('2026-10-18', '4', '99', '2', 9);
                    $hashX = hash('sha256', $ridX . '-X');
                    $srcX = ['race_id' => $ridX, 'ai_role' => '1st',
                             'snapshot_hash' => $hashX,
                             'runners' => [
                                 ['horse_no' => 1, 'band' => 'A'],
                                 ['horse_no' => 2, 'band' => 'B'],
                                 ['horse_no' => 3, 'band' => 'D'],
                             ]];
                    $sidX = \DB::table('baganriki_snapshots')->insertGetId([
                        'race_id' => $ridX, 'generation' => 0, 'run_id' => 'QT-X',
                        'snapshot_hash' => $hashX, 'frozen_nums' => $j([1, 2, 3]),
                        'input_json_1st' => $j($srcX), 'input_json_2nd' => $j($srcX),
                        'target_count' => 3, 'quota_1st' => $j([2, 1, 0]),
                        'sales_close_at' => $W::toDb($sales),
                        'created_at' => $W::toDb($now),
                    ]);
                    \DB::table('baganriki_live_states')->insert([
                        'snapshot_id' => $sidX, 'cancellations' => $j([]),
                        'active_count' => 3, 'live_target_count' => 3,
                        'live_quota' => $j([2, 1, 0]), 'live_close' => null,
                        'state_revision' => 0, 'response_revision' => 0,
                        'publication_revision' => 0, 'latest_publication_id' => null,
                        'updated_at' => $W::toDb($now),
                    ]);

                    $inspX = ['repair_scope' => ['horses' => [2],
                                  'rank_bands' => ['B'], 'presentation_horses' => [],
                                  'summary_horses' => [], 'public_candidates' => false,
                                  'top_fields' => []],
                              'diag' => ['findings' => [
                                  ['scope' => 'horses', 'target' => 2,
                                   'code' => 'SCORES_RANGE', 'reason' => 'H1が範囲外'],
                              ], 'missing' => [3], 'duplicated' => [],
                                 'unknown' => [], 'isolated' => [2]]];

                    $fa0 = $ctrlX->assembleCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-06', '初回の原応答が無ければ依頼を作らない',
                        $fa0['code'], 'FIRST_NOT_RECEIVED');

                    // 初回の枠を「受領済み・complete=false」で置きます
                    $rawX = '{"race_id":"' . $ridX . '","ai_role":"1st"}';
                    \DB::table('baganriki_ai_attempts')->insert([
                        'snapshot_id' => $sidX, 'role' => '1st', 'attempt_no' => 0,
                        'token' => 'QT-X-1st-0', 'status' => $W::RECEIVED,
                        'reserved_at' => $W::toDb($now), 'received_at' => $W::toDb($now),
                        'complete' => 0, 'raw_response' => $rawX,
                        'response_hash' => hash('sha256', $rawX),
                    ]);
                    $faX = $ctrlX->assembleCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-07', '依頼本文を作れる', $faX['ok'], true);
                    $bodyX = json_decode((string) $faX['user'], true);
                    $check('X-08', '依頼は4つの中身だけを持つ',
                        array_keys((array) $bodyX),
                        ['first_response', 'fix_request', 'input']);
                    $check('X-09', '同じsnapshotのhashを載せる',
                        (string) ($bodyX['fix_request']['snapshot_hash'] ?? ''), $hashX);
                    $check('X-10', '当該AIの初回応答を原文のまま載せる',
                        (string) ($bodyX['first_response'] ?? ''), $rawX);
                    $check('X-11', 'repair_scope を載せる',
                        (array) ($bodyX['fix_request']['repair_scope']['horses'] ?? []),
                        [2]);
                    $check('X-12', '具体的なエラー一覧を載せる',
                        (string) ($bodyX['fix_request']['errors'][0]['code'] ?? ''),
                        'SCORES_RANGE');
                    $check('X-13', '欠落馬も伝える',
                        (array) ($bodyX['fix_request']['missing_horses'] ?? []), [3]);
                    $check('X-14', '訂正は1回だけと書いてある',
                        [(int) ($bodyX['fix_request']['attempt_no'] ?? -1),
                         (int) ($bodyX['fix_request']['max_attempts'] ?? -1)], [1, 1]);
                    $check('X-15', '相手AIの応答は載せない',
                        strpos((string) $faX['user'], '"second_response"'), false);
                    $check('X-16', '依頼本文が自分の厳密読込を通る',
                        \App\Http\Controllers\Api\BaganrikiJson::strict(
                            (string) $faX['user'])['ok'], true);

                    // ── 送る手前で断る経路（実AIは呼びません）──
                    $sf1 = $ctrlX->sendCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-17', '初回がcomplete=falseなら訂正を予約しない',
                        [$sf1['sent'], $sf1['code']], [false, 'FIRST_NOT_COMPLETE']);

                    \DB::table('baganriki_ai_attempts')
                      ->where('snapshot_id', $sidX)->where('role', '1st')
                      ->where('attempt_no', 0)->update(['complete' => 1]);

                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261023-PREDICTION-QTTARGET  枠の段が上がれば対象レースで限定
                    // ══════════════════════════════════════════════════
                    //   10/23 から、QUEUE が shadow/1 なら TARGETS で必ず限定します
                    //   （入れ忘れで全レースへ送らないため）。空なら1レースも送りません。
                    $setX('BAGANRIKI_PREDICTION_TARGETS', '');
                    $sfT0 = $ctrlX->sendCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-17b', '枠の段が上がり対象が空なら訂正も送らない',
                        [$sfT0['sent'], $sfT0['code']], [false, 'NO_TARGETS']);
                    $setX('BAGANRIKI_PREDICTION_TARGETS', 'QT-OTHER-RACE');
                    $sfT1 = $ctrlX->sendCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-17c', '対象外のレースへは訂正を送らない',
                        [$sfT1['sent'], $sfT1['code']], [false, 'NOT_TARGET']);
                    // 以降は、この試験レースを対象に入れて従来どおりの経路を見ます
                    $setX('BAGANRIKI_PREDICTION_TARGETS', $ridX);

                    // 締切を目前にすると、時間が無いので送りません
                    \DB::table('baganriki_snapshots')->where('id', $sidX)
                      ->update(['sales_close_at' => $W::toDb($now + 10 * 1000000)]);
                    $sf2 = $ctrlX->sendCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-18', '応答期限まで時間が無ければ訂正を送らない',
                        [$sf2['sent'], $sf2['code']], [false, 'NO_TIME_FOR_FIX']);

                    // 時間を戻し、訂正の枠が先にあれば送りません（最大1回）
                    \DB::table('baganriki_snapshots')->where('id', $sidX)
                      ->update(['sales_close_at' => $W::toDb($sales)]);
                    \DB::table('baganriki_ai_attempts')->insert([
                        'snapshot_id' => $sidX, 'role' => '1st', 'attempt_no' => 1,
                        'token' => 'QT-X-1st-1', 'status' => $W::RECEIVED,
                        'reserved_at' => $W::toDb($now), 'received_at' => $W::toDb($now),
                        'complete' => 1, 'raw_response' => $rawX,
                        'response_hash' => hash('sha256', $rawX),
                    ]);
                    $sf3 = $ctrlX->sendCorrectionRequest($sidX, '1st', $inspX);
                    $check('X-19', '訂正の枠が既にあれば送らない（最大1回）',
                        [$sf3['sent'], $sf3['code']], [false, 'SLOT_TAKEN']);

                    // ── 公開の待ち合わせ ──
                    $pw1 = $ctrlX->publishWhenReady($sidX, ['now_us' => $now]);
                    $check('X-20', '2ndが終端でなければ公開しない',
                        $pw1['code'], 'WAITING');
                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-SETTLE  待ちの中身が増えました
                    // ══════════════════════════════════════════════════
                    //   1st は初回も訂正も RECEIVED ですが、採否確定の印が
                    //   まだ無いので「採否未確定」として待ちに入ります。
                    //   （010の2-4。RECEIVED は HTTPが返っただけです）
                    // $check('X-21', '待っている相手を名前で返す',
                    $check('X-21', '待っている相手を名前で返す',
                        (array) ($pw1['waiting'] ?? []),
                        ['1st（採否未確定）', '2nd（枠なし）']);

                    \DB::table('baganriki_ai_attempts')->insert([
                        'snapshot_id' => $sidX, 'role' => '2nd', 'attempt_no' => 0,
                        'token' => 'QT-X-2nd-0', 'status' => $W::RESERVED,
                        'reserved_at' => $W::toDb($now), 'complete' => 0,
                    ]);
                    $pw2 = $ctrlX->publishWhenReady($sidX, ['now_us' => $now]);
                    $check('X-22', '2ndが予約中でも公開しない', $pw2['code'], 'WAITING');

                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-SETTLE  受領の終端≠採否の確定
                    // ══════════════════════════════════════════════════
                    $check('X-22b', 'RECEIVEDだけでは採否は未確定',
                        $A::roleSettled($sidX, '1st')['settled'], false);

                    $mk1 = $A::markRoleSettled($sidX, '1st',
                                                   ['from' => 'queue-test'], $now);
                    $check('X-22c', '採否確定の印を付けられる',
                        [$mk1['marked'], $mk1['already']], [true, false]);
                    $check('X-22d', '同じ版に二度は付けない',
                        $A::markRoleSettled($sidX, '1st', [], $now)['already'],
                        true);
                    $check('X-22e', '印が付けば採否確定',
                        $A::roleSettled($sidX, '1st')['settled'], true);

                    $pw2b = $ctrlX->publishWhenReady($sidX, ['now_us' => $now]);
                    $check('X-22f', '1stが確定しても2ndが未了なら公開しない',
                        [$pw2b['code'], (array) ($pw2b['waiting'] ?? [])],
                        ['WAITING', ['2nd（RESERVED）']]);

                    // 印を付けたあとに訂正の中身が変われば、版が変わります
                    \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sidX)
                      ->where('role', '1st')->where('attempt_no', 1)
                      ->update(['response_hash' => hash('sha256', $rawX . '-another')]);
                    $check('X-22g', '応答の版が変われば印は効かない',
                        $A::roleSettled($sidX, '1st')['settled'], false);
                    $pw2c = $ctrlX->publishWhenReady($sidX, ['now_us' => $now]);
                    $check('X-22h', '版が変わったら待ちへ戻る',
                        (array) ($pw2c['waiting'] ?? []),
                        ['1st（採否未確定）', '2nd（RESERVED）']);

                    // もとの版へ戻し、2nd も終端＋確定にして公開まで通します
                    \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sidX)
                      ->where('role', '1st')->where('attempt_no', 1)
                      ->update(['response_hash' => hash('sha256', $rawX)]);
                    $check('X-22i', '版を戻せば印は効く',
                        $A::roleSettled($sidX, '1st')['settled'], true);

                    // 応答期限を過ぎたら、来ないものを待ちません
                    $lateX = (int) $W::dbUs($W::toDb($sales)) + 100 * 1000000;
                    $pw3 = $ctrlX->publishWhenReady($sidX, ['now_us' => $lateX]);
                    $check('X-23', '応答期限を過ぎたら待たない',
                        ($pw3['code'] === 'WAITING'), false);

                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-ATDEADLINE  期限ぴったりの1回
                    // ══════════════════════════════════════════════════
                    //   `>=` にしたので、期限と同じ時刻でも待ちません。
                    $snapD = \DB::table('baganriki_snapshots')
                               ->where('id', $sidX)->first();
                    $liveD = \DB::table('baganriki_live_states')
                               ->where('snapshot_id', $sidX)->first();
                    $dlD   = $W::deadlines($snapD, $liveD);
                    $pwD   = $ctrlX->publishWhenReady(
                                 $sidX, ['now_us' => (int) $dlD['reply']]);
                    $check('X-23b', '応答期限ぴったりでも待たない',
                        ($pwD['code'] === 'WAITING'), false);

                    // ═══════════════════════════════════════════════════
                    // 呼出経路そのものの確認（006：実ファイル・呼出経路）
                    // ═══════════════════════════════════════════════════
                    //   関数の行範囲を取って、その中に呼び出しがあるかを見ます。
                    //   「他ファイルに接続があるなら経路を出せ」へのお答えです。
                    $rc   = new \ReflectionClass($A);
                    $file = (array) file((string) $rc->getFileName());
                    $slice = static function (string $m) use ($rc, $file): string {
                        $r = $rc->getMethod($m);
                        return implode('', array_slice($file, $r->getStartLine() - 1,
                            $r->getEndLine() - $r->getStartLine() + 1));
                    };
                    $bw = $slice('afterAiResponseReceived');
                    $check('X-28', '接続は検証→訂正→解決→公開の順に呼ぶ',
                        [strpos($bw, '->inspectAiResponse(') !== false,
                         strpos($bw, '->sendCorrectionRequest(') !== false,
                         strpos($bw, '->acceptedHorsesOf(') !== false,
                         strpos($bw, '->publishWhenReady(') !== false],
                        [true, true, true, true]);
                    // ── @ANCHOR-20261020-PREDICTION-RESUME ──────────
                    //   キャッシュを返す経路から、固定のやり直しを
                    //   呼んでいるか。TOO_EARLY で空回答をキャッシュ
                    //   しないようにしているか。
                    $br = $slice('resumePredictionFreeze');
                    $check('X-33', 'やり直しは固定済みなら何もしない',
                        strpos($br, "'ALREADY'") !== false, true);
                    $check('X-34', 'やり直しは旗が0なら何もしない',
                        strpos($br, "'FLAG_OFF'") !== false, true);

                    $bc = $slice('callPredictionAi');
                    $check('X-29', '1st は Anthropic・2nd は DeepSeek へ送る',
                        [strpos($bc, '$this->anthropic->sendWithRetry(') !== false,
                         strpos($bc, 'api.deepseek.com') !== false], [true, true]);
                    $check('X-30', '訂正の自動再試行は0（maxAttempts: 1）',
                        strpos($bc, 'maxAttempts: 1') !== false, true);
                } finally {
                    foreach ($keepX as $kk => $vv) {
                        if ($vv[0] === false) { putenv($kk); }
                        else { putenv($kk . '=' . $vv[0]); }
                        if ($vv[1] === null) { unset($_ENV[$kk]); }
                        else { $_ENV[$kk] = $vv[1]; }
                        if ($vv[2] === null) { unset($_SERVER[$kk]); }
                        else { $_SERVER[$kk] = $vv[2]; }
                    }
                }
            }

        } catch (\Throwable $e) {
            $ok = false; $fail++;
            $this->error('  × 例外: ' . $e->getMessage());
            $this->error('     ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
        }

        $n1 = \DB::table('baganriki_snapshots')->count();
        $n2 = \DB::table('baganriki_ai_attempts')->count();
        $n3 = \DB::table('baganriki_live_states')->count();
        $n4 = \DB::table('baganriki_audit_events')->count();
        $this->line('');
        $this->line(sprintf('  ロールバック後  snapshot %d／attempts %d／live %d／audit %d',
            $n1, $n2, $n3, $n4));
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0 && $ok)
            ? ' キューと永続予約は契約どおりに動きました（DBへ残る書き込み0件）'
            : ' 通らなかった箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-RESP  応答検証と局所回復の通し試験
     * ═══════════════════════════════════════════════════════════════════════
     * ■ 何を確かめるか
     *   1. 正常出力14本が、本番の経路（extract→封筒→隔離→診断）を
     *      <b>直すところなし</b>で通ること（誤検出0）
     *   2. 馬番欠落・重複・未知馬・局所の項目不正で、その範囲だけ隔離され、
     *      正常な他馬が残ること
     *   3. 公開リストとフラグの不一致だけでは、評価行を訂正対象に広げないこと
     *   4. 訂正は最大1回。初回の正常部分は、訂正が採用できなくても残ること
     *   5. 対象外を変える訂正・初回の根拠を全部失う訂正を採用しないこと
     *   6. 検証の結果が baganriki_validation_results に残ること
     *
     * ■ 書き込みについて
     *   全部ひとつのトランザクションで行い、最後に必ずロールバックします。
     */
    private function runResponseTest(string $root): void
    {
        self::loadPredictionClasses();
        $vDir = rtrim($root, '/') . '/validation';
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 応答検証と局所回復の通し試験');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  資料     : ' . $vDir);
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');
        if (!is_dir($vDir . '/normal')) {
            $this->error('  × ' . $vDir . '/normal がありません');
            return;
        }

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-10s %-50s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $j = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;

        // 凍結入力と枠を1組だけ作る道具（generation=900 の試験用）
        $seq = 0;
        $mk = function (array $in, array $out1, ?array $out2 = null,
                        ?int $firstUs = null, ?int $fixUs = null, string $role = '1st')
              use ($j, $BEGIN, $END, &$seq): array {
            $seq++;
            $now = \App\Http\Controllers\Api\BaganrikiWorker::nowUs();
            $nums = [];
            foreach ((array) $in['runners'] as $r) { $nums[] = (int) $r['horse_no']; }
            $sid = \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => 'RT-' . $seq, 'generation' => 900, 'run_id' => 'RT',
                'snapshot_hash' => (string) ($in['snapshot_hash'] ?? ''),
                'input_json_1st' => $j($in), 'input_json_2nd' => $j($in),
                'frozen_nums' => $j($nums),
                // @ANCHOR-20261023-NARU-011  検査は「実効応答期限内に受領したか」も見るようになったので
                //   （011再精査版）、試験の凍結入力にも投票締切（1時間後）を入れます。本番の凍結入力には必ずあります。
                'sales_close_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now + 3600 * 1000000),
                'created_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
            ]);
            $mkA = function (int $no, array $doc, int $at)
                   use ($sid, $j, $BEGIN, $END, &$seq, $role) {
                \DB::table('baganriki_ai_attempts')->insert([
                    'snapshot_id' => $sid, 'role' => $role, 'attempt_no' => $no,
                    'token' => hash('sha256', 'RT' . $sid . '-' . $no . '-' . $seq),
                    'status' => \App\Http\Controllers\Api\BaganrikiWorker::RECEIVED,
                    'reserved_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($at),
                    'received_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($at),
                    'complete' => 1,
                    'raw_response' => $BEGIN . $j($doc) . $END,
                    'response_hash' => hash('sha256', $j($doc)),
                ]);
            };
            $mkA(0, $out1, $firstUs ?? $now);
            if ($out2 !== null) { $mkA(1, $out2, $fixUs ?? ($now + 1000)); }
            return ['sid' => (int) $sid, 'nums' => $nums];
        };

        $ok = true;
        \DB::beginTransaction();
        try {
            $ctrl = app(\App\Http\Controllers\Api\AiController::class);

            // ── ① 正常出力14本を本番の経路へ通す ────────────────────
            $this->info('── ① 正常出力を本番の経路へ通す（誤検出0であること）──');
            $files = glob($vDir . '/normal/*_output.json');
            sort($files);
            $ng = 0; $n = 0; $base = null;
            foreach ($files as $f) {
                $inF = str_replace('_output', '_input', $f);
                if (!is_file($inF)) { continue; }
                $in  = json_decode((string) file_get_contents($inF), true);
                $out = json_decode((string) file_get_contents($f), true);
                if (!is_array($in) || !is_array($out)) { continue; }
                $role = (strpos(basename($f), '_2nd_') !== false) ? '2nd' : '1st';
                $m = $mk($in, $out, null, null, null, $role);
                $r = $ctrl->inspectAiResponse($m['sid'], $role, 0);
                $n++;
                if (!$r['ok'] || $r['needs_fix']
                    || count($r['normal']) !== count($m['nums'])) {
                    $ng++;
                    $this->line(sprintf('  × %-40s %s／%s  %s',
                        basename($f), $r['stage'], (string) $r['code'],
                        mb_strimwidth((string) $r['reason'], 0, 60, '…')));
                } else {
                    $this->line(sprintf('  ○ %-40s 全%d頭が残り、直すところなし',
                        basename($f), count($r['normal'])));
                }
                if ($base === null && count($in['runners']) >= 16) {
                    $base = ['in' => $in, 'out' => $out];
                }
            }
            $check('R-01', '正常出力を通した本数', $n, 14);
            $check('R-02', '正常出力の誤検出', $ng, 0);
            if ($base === null) {
                $this->error('  × 16頭の正常例が見つかりません'); $ok = false;
                throw new \RuntimeException('base なし');
            }
            $N = count($base['in']['runners']);

            // ── ② 局所回復（その範囲だけ隔離し、他馬を残す）──────────
            $this->info('── ② 局所回復（正常な他馬を残す）──');
            // 馬番欠落
            $o = $base['out']; $gone = (int) $o['horses'][2]['horse_no'];
            array_splice($o['horses'], 2, 1);
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $check('R-03', '欠落しても他馬は残る', count($r['normal']), $N - 1);
            $check('R-04', '欠落馬は直す範囲に入る',
                   in_array($gone, (array) $r['repair_scope']['horses'], true), true);

            // 未知馬
            $o = $base['out'];
            $x = $o['horses'][0]; $x['horse_no'] = 99; $o['horses'][] = $x;
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $check('R-05', '未知馬を混ぜても他馬は残る', count($r['normal']), $N);
            $check('R-06', '未知馬は隔離される',
                   in_array(99, (array) $r['diag']['unknown'], true), true);

            // 重複
            $o = $base['out'];
            $o['horses'][] = $o['horses'][0];
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            // @ANCHOR-20261023-NARU-011  重複した馬はどちらの行が正しいか決められないので、その馬は残さず
            //   （両方の行を隔離）、他馬だけを残します（011再精査4版の隔離。自己試験 R122-V02 と同じ）
            $check('R-07', '重複した馬を除き、他馬は残る', count($r['normal']), $N - 1);
            $check('R-08', '重複は記録される', count((array) $r['diag']['duplicated']), 1);

            // 局所の項目不正
            // @ANCHOR-20261020-PREDICTION-SALVAGEで期待値を直しました
            //   【旧】採点集計が合わないだけで「1頭まるごと落ちる」を正としていました。
            //     契約は「不正な得点項目だけを直し、正常根拠は保持する」です。
            //     落とす側の経路は、帯の食い違い（採点以外）で見ます。
            $o = $base['out'];
            $bad = (int) $o['horses'][1]['horse_no'];
            $o['horses'][1]['band'] = ($o['horses'][1]['band'] === 'D') ? 'A' : 'D';
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $check('R-09', '壊れた1頭だけ落ちる', count($r['normal']), $N - 1);
            $check('R-10', '壊れた行は row_valid=false', $r['row_valid'][$bad], false);
            $check('R-11', '他の行は row_valid=true',
                   $r['row_valid'][(int) $base['out']['horses'][0]['horse_no']], true);

            // ── ③ 公開リストの不一致で評価行を広げない ────────────────
            $this->info('── ③ 公開リストの不一致で評価行を広げない ──');
            $o = $base['out'];
            if (!empty($o['public_candidates'])) {
                array_pop($o['public_candidates']);
            }
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $check('R-12', '直す範囲に公開リストが入る',
                   $r['repair_scope']['public_candidates'], true);
            $check('R-13', '評価行は広がらない',
                   (array) $r['repair_scope']['horses'], []);
            $check('R-14', '全頭そのまま残る', count($r['normal']), $N);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-REFCHECK  参照根拠の意味検証
            // ══════════════════════════════════════════════════════════
            $this->info('── ③-2 参照根拠の意味検証（実在・所有馬・SIGNAL）──');

            // 参照IDを作り話に差し替える（実在しないID）
            $o = $base['out'];
            $no0 = (int) $o['horses'][0]['horse_no'];
            $o['horses'][0] = self::rtSwapRefs($o['horses'][0], 'SONZAI_SHINAI_REF');
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-26', '実在しない参照IDを見つける',
                   in_array('EVIDENCE_REF_UNKNOWN', $codes, true), true);
            $check('R-27', 'その馬が直す範囲に入る',
                   in_array($no0, (array) $r['repair_scope']['horses'], true), true);
            $check('R-28', '他の馬はそのまま残る', count($r['normal']), $N - 1);
            $check('R-29', '自分のSIGNALが無くなるので行は落ちる',
                   $r['row_valid'][$no0], false);

            // 他の馬の参照IDを混ぜる（実在はするが所有が違う）
            $o = $base['out'];
            $other = (int) $o['horses'][1]['horse_no'];
            $borrow = null;
            foreach ((array) $base['in']['source_registry'] as $rf => $e) {
                if (($e['scope'] ?? '') === 'HORSE'
                    && in_array($other, array_map('intval', (array) ($e['horse_nos'] ?? [])), true)) {
                    $borrow = (string) $rf; break;
                }
            }
            if ($borrow !== null) {
                // 自分のSIGNALは残したまま、他馬の参照を1件足します
                $o['horses'][0] = self::rtAddRef($o['horses'][0], $borrow);
                $m = $mk($base['in'], $o);
                $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
                $codes = array_column((array) $r['diag']['findings'], 'code');
                $check('R-30', '他の馬の参照IDを見つける',
                       in_array('EVIDENCE_REF_NOT_OWNED', $codes, true), true);
                $check('R-31', '自分のSIGNALが残っていれば行は落とさない',
                       $r['row_valid'][$no0], true);
                $check('R-32', '全頭そのまま残る', count($r['normal']), $N);
            }

            // 正常出力は何も出ないこと（誤検出0）
            $m = $mk($base['in'], $base['out']);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-33', '正常出力では参照の指摘が出ない',
                   count(array_intersect($codes,
                       ['EVIDENCE_REF_UNKNOWN', 'EVIDENCE_REF_NOT_OWNED',
                        'EVIDENCE_NO_OWN_SIGNAL'])), 0);
            $st = (array) ($r['diag']['ref_stats'] ?? []);
            $check('R-34', '馬ごとの参照の内訳を残す', count($st), $N);
            $own = 0;
            foreach ($st as $v) { if ((int) ($v['own_signal'] ?? 0) > 0) { $own++; } }
            $check('R-35', '全頭に自分のSIGNALがある', $own, $N);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-SCOREEV  採点と出典の結びつき
            // ══════════════════════════════════════════════════════════
            $this->info('── ③-3 採点と出典の結びつき ──');

            // 点が付いているのに出典が空
            $o = $base['out'];
            $o['horses'][0]['scores']['H1']       = 5;
            $o['horses'][0]['observed_sum']       = 5;
            $o['horses'][0]['observed_cap']       = 18;
            $o['horses'][0]['score_evidence']['H1']['source_refs'] = [];
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-36', '点が付いているのに出典が空なら見つける',
                   in_array('SCORE_WITHOUT_EVIDENCE', $codes, true), true);
            $check('R-37', 'その行は落ちる', $r['row_valid'][$no0], false);

            // 採点していないのに理由が空
            $o = $base['out'];
            $o['horses'][0]['score_evidence']['H2']['reason'] = '';
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-38', '採点していないのに理由が空なら見つける',
                   in_array('SCORE_NULL_WITHOUT_REASON', $codes, true), true);

            // 採点の出典が実在しない
            $o = $base['out'];
            $o['horses'][0]['score_evidence']['H1']['source_refs'] = ['SONZAI_SHINAI_REF'];
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-39', '採点の出典が実在しなければ見つける',
                   in_array('SCORE_EVIDENCE_REF_UNKNOWN', $codes, true), true);
            // @ANCHOR-20261023-NARU-011  その採点項目だけを外して行は残します。ただし、サーバ側で
            //   直した行は「検証済み」とは数えません（row_valid=false。011再精査5版）
            $check('R-40', '実在しないだけなら行は落とさない（候補の材料に残る）',
                   array_key_exists($no0, (array) $r['normal']), true);
            $check('R-40b', 'サーバ側で直した行は検証済みと数えない',
                   $r['row_valid'][$no0], false);

            // 採点の出典欄そのものが欠けている
            $o = $base['out'];
            unset($o['horses'][0]['score_evidence']['V5']);
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-41', '採点の出典欄の欠けを見つける',
                   in_array('SCORE_EVIDENCE_MISSING', $codes, true), true);

            // 正常出力では何も出ないこと（誤検出0）
            $m = $mk($base['in'], $base['out']);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = array_column((array) $r['diag']['findings'], 'code');
            $check('R-42', '正常出力では採点の指摘が出ない',
                   count(array_intersect($codes,
                       ['SCORE_WITHOUT_EVIDENCE', 'SCORE_NULL_WITHOUT_REASON',
                        'SCORE_EVIDENCE_REF_UNKNOWN', 'SCORE_EVIDENCE_REF_NOT_OWNED',
                        'SCORE_EVIDENCE_MISSING', 'SCORE_EVIDENCE_TYPE',
                        'SCORE_EVIDENCE_UNKNOWN_KEY'])), 0);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-SALVAGE  採点欄だけの誤りで馬を落とさない
            // ══════════════════════════════════════════════════════════
            $this->info('── ③-4 採点欄だけの誤りで正常根拠を落とさない ──');
            $o = $base['out'];
            $tgt = (int) $o['horses'][1]['horse_no'];
            $o['horses'][1]['observed_sum'] = 999;      // 集計だけを壊します
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $codes = [];
            foreach ((array) $r['diag']['findings'] as $fd) {
                if ((int) ($fd['target'] ?? 0) === $tgt) { $codes[] = $fd['code']; }
            }
            $check('R-43', '採点集計の誤りを見つける',
                   in_array('OBSERVED_SUM', array_unique($codes), true), true);
            $check('R-44', 'その馬は summary_horses に入る',
                   in_array($tgt, (array) $r['repair_scope']['summary_horses'], true), true);
            $check('R-45', 'horses には入れない（行ごとは直さない）',
                   in_array($tgt, (array) $r['repair_scope']['horses'], true), false);
            $check('R-46', '行は落とさず残す（全頭そろう）', count($r['normal']), $N);
            $check('R-47', '採点欄を落とした印が残る',
                   in_array($tgt, (array) $r['diag']['salvaged'], true), true);
            // ?? は null も「無い」と見なすので、キーの有無で見ます
            $check('R-48', '採点は空にする',
                   array_key_exists('H1', (array) ($r['normal'][$tgt]['scores'] ?? []))
                   && ($r['normal'][$tgt]['scores']['H1'] === null), true);
            $check('R-49', '採点の状態は partial',
                   ($r['normal'][$tgt]['score_status'] ?? null), 'partial');
            $check('R-50', '根拠はそのまま残す',
                   ($r['normal'][$tgt]['evidence'] ?? null),
                   ($base['out']['horses'][1]['evidence'] ?? null));

            // 根拠側の指摘が混ざっていたら残しません
            $o = $base['out'];
            $o['horses'][1]['observed_sum'] = 999;
            $o['horses'][1]['band'] = 'D';              // 帯は凍結入力が正
            $m = $mk($base['in'], $o);
            $r = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $check('R-51', '採点以外の指摘が混ざれば残さない',
                   count($r['normal']), $N - 1);

            // ── ④ 訂正（最大1回）──────────────────────────────────
            $this->info('── ④ 訂正 ──');
            // 欠落を訂正で埋める（救済）
            $o1 = $base['out']; $gone = (int) $o1['horses'][3]['horse_no'];
            array_splice($o1['horses'], 3, 1);
            $o2 = $base['out'];
            $keep = [];
            foreach ($o2['horses'] as $h) {
                if ((int) $h['horse_no'] === $gone) { $keep[] = $h; }
            }
            $o2['horses'] = $keep;
            $m = $mk($base['in'], $o1, $o2);
            $r = $ctrl->resolveCorrection($m['sid'], '1st');
            $check('R-15', '訂正を反映した', $r['code'], 'FIX_APPLIED');
            $check('R-16', '欠落した馬を救済した', (array) $r['rescued'], [$gone]);
            $check('R-17', '全頭そろう', count($r['merged']), $N);

            // ══════════════════════════════════════════════════════════
            //   としていました。ところが observed_sum は<b>集計欄</b>なので、
            //   こちらの救済が勝手に計算し直して元の値へ戻します。
            //   つまり比べる時点では<b>初回と同じ中身</b>になっていました。
            //   それでも不採用になっていたのは、救済の印（r122_salvage）が
            //   行の中に残っていて、その差で弾いていたからです。
            //   つまり<b>10月21日008でご指摘を受けた不具合そのもの</b>に
            //   助けられて合格していました。
            //   【いま】救済が触らない項目（explanation）を書き換えます。
            //   訂正は契約どおり<b>全頭返し</b>ます。
            $o1 = $base['out']; $gone = (int) $o1['horses'][3]['horse_no'];
            array_splice($o1['horses'], 3, 1);
            $o2 = $base['out'];                       // 全頭返す（契約どおり）
            $chgNo = null;
            foreach ($o2['horses'] as $hi => $h) {
                if ((int) $h['horse_no'] !== $gone) {
                    $chgNo = (int) $h['horse_no'];
                    $o2['horses'][$hi]['explanation'] =
                        '対象外なのに書き換えた説明（この訂正は採用されません）';
                    break;
                }
            }
            $m = $mk($base['in'], $o1, $o2);
            $r = $ctrl->resolveCorrection($m['sid'], '1st');
            $check('R-18', '対象外の馬を本当に変える訂正は採用しない',
                   $r['code'], 'FIX_REJECTED');
            $check('R-18b', 'その理由にその馬番が出る',
                   strpos((string) ($r['reason'] ?? ''), (string) $chgNo) !== false,
                   true);

            $check('R-19', '初回の正常部分は残る', count($r['merged']), $N - 1);

            // ── 全頭を返す正常な訂正（10月20日007のご指摘4）──────────────
            //   欠落した馬を直し、他の馬は初回と同じものを再掲します。
            //   これが配布本文どおりの訂正の形です（常に全頭を返す）。
            $o1 = $base['out']; $gone = (int) $o1['horses'][3]['horse_no'];
            array_splice($o1['horses'], 3, 1);
            $o2 = $base['out'];                       // 全頭そのまま（再掲）
            $m  = $mk($base['in'], $o1, $o2);
            $r  = $ctrl->resolveCorrection($m['sid'], '1st');
            $check('R-52', '全頭を返す訂正を受け入れる', $r['code'], 'FIX_APPLIED');
            $check('R-53', '欠落していた馬を救済する', (array) $r['rescued'], [$gone]);
            $check('R-54', '全頭そろう', count($r['merged']), $N);
            $check('R-55', '当てたのは救済した馬だけ',
                   array_values(array_diff((array) $r['applied'], [$gone])), []);
            // 未変更の馬は初回の中身のままであること
            $keepNo = null; $firstRow = null;
            foreach ($o1['horses'] as $h0) {
                if ((int) $h0['horse_no'] !== $gone) {
                    $keepNo = (int) $h0['horse_no']; $firstRow = $h0; break;
                }
            }
            $keepRow = (array) (($r['merged'][$keepNo]) ?? []);
            $check('R-56', '未変更の馬の帯・資格・Tierが初回のまま',
                   [(string) ($keepRow['band'] ?? ''),
                    (string) ($keepRow['eligibility'] ?? ''),
                    (string) ($keepRow['tier'] ?? '')],
                   [(string) ($firstRow['band'] ?? ''),
                    (string) ($firstRow['eligibility'] ?? ''),
                    (string) ($firstRow['tier'] ?? '')]);
            $check('R-57', '未変更の馬の根拠の数も初回のまま',
                   count((array) ($keepRow['evidence'] ?? [])),
                   count((array) ($firstRow['evidence'] ?? [])));

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-METASPLIT
            // ══════════════════════════════════════════════════════════
            //   「採点の救済」と「全頭を返す正常な訂正」を<b>同じ経路で</b>
            //   通します。別々に試していたので、組み合わせたときに
            //   落ちることに気づけませんでした。
            //   原因は、救済の明細（内部の監査項目）を馬の行に足していて、
            //   それが訂正の比較対象に入っていたことです。
            $this->info('── ④-2 採点を救済した馬の正常な全頭訂正 ──');

            // (a) 合計欄（observed_sum）だけが誤っている初回
            $oA = $base['out'];
            $tA = (int) $oA['horses'][1]['horse_no'];
            $oA['horses'][1]['observed_sum'] = 999;
            $mA = $mk($base['in'], $oA, $base['out']);
            $iA = $ctrl->inspectAiResponse($mA['sid'], '1st', 0);
            $check('R-58', '初回は救済される（行は落ちない）',
                   count($iA['normal']), $N);
            $check('R-59', '救済の明細は馬の行に入れない',
                   array_key_exists('r122_salvage',
                       (array) ($iA['normal'][$tA] ?? [])), false);
            $check('R-60', '救済の明細は別の入れ物に出す',
                   array_key_exists($tA, (array) ($iA['salvage_detail'] ?? [])), true);
            $aA = $ctrl->acceptedHorsesOf($mA['sid'], '1st');
            $check('R-61', '正常な全頭訂正を採用する',
                   (string) ($aA['code'] ?? ''), 'FIX_APPLIED');
            $check('R-62', '全頭そろう', count((array) $aA['merged']), $N);
            $check('R-63', '当てたのは合計欄が誤っていた馬だけ',
                   array_map('intval', (array) $aA['applied']), [$tA]);
            $kA = (array) ($aA['merged'][(int) $base['out']['horses'][0]['horse_no']] ?? []);
            $fA = (array) $base['out']['horses'][0];
            $check('R-64', '他の馬の帯は初回のまま',
                   ($kA['band'] ?? null), ($fA['band'] ?? null));
            $check('R-65', '他の馬の根拠の数も初回のまま',
                   count((array) ($kA['evidence'] ?? [])),
                   count((array) ($fA['evidence'] ?? [])));
            $check('R-66', '当てた馬の採点は訂正の値に戻る',
                   ($aA['merged'][$tA]['observed_sum'] ?? null),
                   ($base['out']['horses'][1]['observed_sum'] ?? null));

            // (b) 得点1項目だけが不正な初回（満点超え）
            $oB = $base['out'];
            $tB = (int) $oB['horses'][2]['horse_no'];
            $oB['horses'][2]['scores']['H1'] = 999;     // 満点18を超えます
            $mB = $mk($base['in'], $oB, $base['out']);
            $iB = $ctrl->inspectAiResponse($mB['sid'], '1st', 0);
            $check('R-67', '得点1項目の不正でも行は落ちない',
                   count($iB['normal']), $N);
            // ?? は null も「無い」と見なすので、キーの有無で見ます
            //   （この資料の正常出力は、採点がもともと全部 null です）
            $scB = (array) ($iB['normal'][$tB]['scores'] ?? []);
            $check('R-68', 'おかしい項目の欄は残したまま空にする',
                   array_key_exists('H1', $scB) && $scB['H1'] === null, true);
            //   右側でも同じ罠を踏んでいました。初回の H2 が null のとき
            //   `?? 'y'` が 'y' を返すので、null === 'y' で落ちます。
            //   両側ともキーの有無で見ます。
            $scBase = (array) ($base['out']['horses'][2]['scores'] ?? []);
            $check('R-69', '他の項目は初回のまま',
                   array_key_exists('H2', $scB) && array_key_exists('H2', $scBase)
                   && $scB['H2'] === $scBase['H2'], true);
            $aB = $ctrl->acceptedHorsesOf($mB['sid'], '1st');
            $check('R-70', '得点1項目の不正でも全頭訂正を採用する',
                   (string) ($aB['code'] ?? ''), 'FIX_APPLIED');
            $check('R-71', '全頭そろう', count((array) $aB['merged']), $N);
            $check('R-72', '当てたのはその馬だけ',
                   array_map('intval', (array) $aB['applied']), [$tB]);
            $check('R-73', '当てた馬の得点は訂正の値に戻る',
                   ($aB['merged'][$tB]['scores']['H1'] ?? null),
                   ($base['out']['horses'][2]['scores']['H1'] ?? null));

            // (c) 内部の監査項目を訂正に入れてきたら断ります
            $oC = $base['out'];
            $oC['horses'][1]['observed_sum'] = 999;
            $fC = $base['out'];
            $fC['horses'][1]['r122_salvage'] = ['nulled_scores' => []];
            $mC = $mk($base['in'], $oC, $fC);
            $aC = $ctrl->acceptedHorsesOf($mC['sid'], '1st');
            $check('R-74', '内部の監査項目を返す訂正は採用しない',
                   (string) ($aC['code'] ?? ''), 'FIX_REJECTED');
            $check('R-75', 'それでも初回の正常部分は残る',
                   count((array) $aC['merged']), $N);

            // 訂正の受領が初回より前
            $now = \App\Http\Controllers\Api\BaganrikiWorker::nowUs();
            $o1 = $base['out']; $gone = (int) $o1['horses'][3]['horse_no'];
            array_splice($o1['horses'], 3, 1);
            $o2 = $base['out'];
            $keep = [];
            foreach ($o2['horses'] as $h) {
                if ((int) $h['horse_no'] === $gone) { $keep[] = $h; }
            }
            $o2['horses'] = $keep;
            $m = $mk($base['in'], $o1, $o2, $now, $now - 5000000);
            $r = $ctrl->resolveCorrection($m['sid'], '1st');
            $check('R-20', '訂正が初回より前なら採用しない', $r['code'], 'FIX_REJECTED');

            // 直すところが無ければ訂正しない
            $m = $mk($base['in'], $base['out'], $base['out']);
            $r = $ctrl->resolveCorrection($m['sid'], '1st');
            $check('R-21', '直すところが無ければ訂正しない', $r['code'], 'NO_FIX_NEEDED');

            // ── ⑤ 検証の結果が残る ────────────────────────────────
            $this->info('── ⑤ 検証の結果が残る ──');
            $vr = \DB::table('baganriki_validation_results')
                    ->where('attempt_id', \DB::table('baganriki_ai_attempts')
                        ->where('snapshot_id', $m['sid'])->where('attempt_no', 0)
                        ->value('id'))->first();
            $check('R-22', '検証の結果が1行残る', ($vr !== null), true);
            if ($vr !== null) {
                $check('R-23', '診断の版が入る', (string) $vr->validator_version,
                       \App\Http\Controllers\Api\BaganrikiDiagnose::VERSION);
                $fs = json_decode((string) $vr->field_sources, true);
                $check('R-24', '事実出典hashを全頭ぶん残す',
                       is_array($fs) ? count($fs) : 0, $N);
                $rs = json_decode((string) $vr->rank_sources, true);
                $check('R-25', '順位の出どころを残す',
                       is_array($rs) && isset($rs['band_ranks']), true);
            }

        } catch (\Throwable $e) {
            $ok = false; $fail++;
            $this->error('  × 例外: ' . $e->getMessage());
            $this->error('     ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
        }

        $this->line('');
        $this->line(sprintf('  ロールバック後  snapshot %d／attempts %d／検証 %d',
            \DB::table('baganriki_snapshots')->count(),
            \DB::table('baganriki_ai_attempts')->count(),
            \DB::table('baganriki_validation_results')->count()));
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0 && $ok)
            ? ' 応答検証と局所回復は契約どおりに動きました（DBへ残る書き込み0件）'
            : ' 通らなかった箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    
    }

    /**
     * @ANCHOR-20261020-PREDICTION-REFCHECK  試験用。参照IDを全部1つの値へ差し替えます
     */
    private static function rtSwapRefs(array $h, string $to): array
    {
        $walk = static function ($n, $to) use (&$walk) {
            if (!is_array($n)) { return $n; }
            $out = [];
            foreach ($n as $k => $v) {
                if ($k === 'source_ref' && is_string($v))  { $out[$k] = $to; continue; }
                if ($k === 'source_refs' && is_array($v))  { $out[$k] = [$to]; continue; }
                $out[$k] = $walk($v, $to);
            }
            return $out;
        };
        return $walk($h, $to);
    }

    /** @ANCHOR-20261020-PREDICTION-REFCHECK  試験用。参照IDを1件だけ足します */
    private static function rtAddRef(array $h, string $ref): array
    {
        if (isset($h['evidence'][0]['source_refs'])
            && is_array($h['evidence'][0]['source_refs'])) {
            $h['evidence'][0]['source_refs'][] = $ref;
        }
        return $h;
    }

    /**
     * @ANCHOR-20261023-NARU-BUDGET2  2つのプロセスで同時に予約する試験（なる 10/23 4通目 3-2）
     *   --prediction-budget-probe=<余裕ドル>   1回だけ予約を試み、結果を1行で出します。
     *     上限は「その日の額（この試験の予約を除く）＋余裕」。最悪額は約0.28ドル。
     *     余裕0.4ドルで2つ同時に走らせると、通るのは1つだけのはずです。
     *   --prediction-budget-probe-cleanup     この試験の予約（race_id=BUDGET-PROBE）を消します。
     *   外部へは何も送りません。
     */
    private function runBudgetProbe(?string $margin, bool $cleanup): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $db = $A::budgetDb();
        if ($cleanup) {
            $n = $db->table('baganriki_audit_events')->where('race_id', 'BUDGET-PROBE')->delete();
            $this->line('  後片付け : この試験の予約を ' . $n . ' 行消しました');
            // Job試験（B-01〜B-13）の行が途中の例外で残った場合も、ここで消します（race_id が BT- で始まる行と、その予約番号の精算）
            $holds = $db->table('baganriki_audit_events')->where('race_id', 'like', 'BT-%')
                        ->where('kind', 'R122_BUDGET_HOLD')->pluck('related_id')->all();
            $ids = $db->table('baganriki_audit_events')->where('race_id', 'like', 'BT-%')->pluck('id')->all();
            if ($holds !== []) {
                $ids = array_merge($ids, $db->table('baganriki_audit_events')
                    ->whereIn('kind', ['R122_BUDGET_SETTLE', 'R122_BUDGET_RELEASE'])
                    ->whereIn('related_id', $holds)->pluck('id')->all());
            }
            $m = 0;
            foreach (array_values(array_unique(array_map('intval', $ids))) as $idB) {
                $m += $db->table('baganriki_audit_events')->where('id', $idB)->delete();
            }
            $this->line('  後片付け : Job試験の予算の行を ' . $m . ' 行消しました');
            // @ANCHOR-20261023-NARU-BUDGET3  予約の無い精算（旧版が試験の中から書いてしまった行。related_id が「role#番号」）を消します。
            //   新しい予想は送信を止めているので、本物の送信の精算はまだ1行もありません。
            // ── 20261025：一度きりの旧データ清掃は済んだので、この分岐はコメントにしました（なる 10/25 確認結果 3章）──
            //   選ぶ条件（kind=SETTLE かつ related_id に # を含む）が試験の行に限られていなかったためです。
            //   10/25 に清掃は終わっており、BUDGET3 以降は予約の無い精算そのものを書きません。
            // $ids2 = $db->table('baganriki_audit_events')->where('kind', 'R122_BUDGET_SETTLE')
            //            ->where('related_id', 'like', '%#%')->pluck('id')->all();
            // $k = 0;
            // foreach (array_map('intval', $ids2) as $idB) { $k += $db->table('baganriki_audit_events')->where('id', $idB)->delete(); }
            // $this->line('  後片付け : 予約の無い精算の行を ' . $k . ' 行消しました（その日の額 ' . sprintf('%.6f', \App\Http\Controllers\Api\AiController::spentTodayUsd(\App\Http\Controllers\Api\BaganrikiWorker::nowUs())) . ' ドル）');
            $this->line('  いまの予算の行 : ' . json_encode($db->table('baganriki_audit_events')
                ->whereIn('kind', ['R122_BUDGET_HOLD', 'R122_BUDGET_SETTLE', 'R122_BUDGET_RELEASE'])
                ->selectRaw('kind, count(*) c')->groupBy('kind')->get(), JSON_UNESCAPED_UNICODE)
                . '／その日の額 ' . sprintf('%.6f', $A::spentTodayUsd($W::nowUs())) . ' ドル');
            return;
        }
        $now = $W::nowUs();
        $probe = 0.0;
        foreach ($db->table('baganriki_audit_events')->where('race_id', 'BUDGET-PROBE')
                    ->where('kind', 'R122_BUDGET_HOLD')->get(['diagnostics']) as $r) {
            $d = json_decode((string) $r->diagnostics, true);
            $probe += (float) ($d['amount_usd'] ?? 0);
        }
        $base = $A::spentTodayUsd($now) - $probe;
        $budget = round($base + (float) $margin, 6);
        putenv('BAGANRIKI_PREDICTION_BUDGET_USD_DAY=' . $budget);
        $_ENV['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'] = (string) $budget; $_SERVER['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'] = (string) $budget;
        $r = $A::budgetReserve('1st', 'claude-haiku-5-5', 'BUDGET-PROBE', 186000, 65536);
        $this->line(sprintf('  pid=%d 結果=%s 上限=%.6f 予約前の額=%s 最悪額=%.6f %s',
            getmypid(), (string) $r['code'], $budget, $r['spent'] === null ? '—' : sprintf('%.6f', $r['spent']),
            (float) $r['worst'], (string) ($r['reason'] ?? '')));
    }

    /**
     * @ANCHOR-20261025-NARU-PUBCOMMIT  公開の実COMMITのあとに、待っていた同じUPDATEが進むこと（なる 10/25 再確認 3章）
     *   外側のトランザクションは使いません。試験用の行（場95・12R、generation 900）は確定させて作り、最後に id で消します。
     *   B は別プロセス（php artisan tinker）で、正本の発走時刻を1回だけ UPDATE します（待ち時間の上限30秒）。
     *   A（このプロセス）は公開本体を呼び、正本を FOR UPDATE で読んで判定した直後（試験口）に B を起動し、
     *   別の接続 C から B が LOCK WAIT になったのを確かめてから、公開を最後まで進めて COMMIT します。
     */
    private function runPublishCommitTest(string $root): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $T = \App\Http\Controllers\Api\BaganrikiTime::class;
        $vDir = rtrim($root, '/') . '/validation/normal';
        $rd = static fn(string $f) => json_decode((string) file_get_contents($f), true);
        $j = static fn($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $in1 = $rd($vDir . '/MIXED_N16_1st_input.json'); $in2 = $rd($vDir . '/MIXED_N16_2nd_input.json');
        $out1 = $rd($vDir . '/MIXED_N16_1st_output.json'); $out2 = $rd($vDir . '/MIXED_N16_2nd_output.json');
        if (!is_array($in1) || !is_array($out1) || !is_array($in2) || !is_array($out2)) { $this->error('  × ' . $vDir . ' の資料がありません'); return; }
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 公開の実COMMITと、待っていた発走時刻UPDATEの再開（外側のトランザクションなし）');
        $this->info('════════════════════════════════════════════════════════');
        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $ok = ($got === $want); if ($ok) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-6s %-62s %s', $ok ? '○' : '×', $id, $what,
                $ok ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $def = (string) config('database.default');
        config(['database.connections.baganriki_commit_c' => config('database.connections.' . $def)]);
        $C = \DB::connection('baganriki_commit_c');
        $src = null;
        foreach (\DB::table('t_horse_odds_finder_races')->where('basho', '<', '90')->orderByDesc('date')->limit(20)->get() as $rr) {
            if (preg_match('/^\d{1,2}:\d{2}/', (string) ($rr->start_time ?? ''))) { $src = $rr; break; }
        }
        if ($src === null) { $this->error('  × 写し元のレースがありません'); return; }
        $this->line('  A の外側のトランザクション : ' . (\DB::transactionLevel() === 0 ? 'なし（transactionLevel=0）' : 'あり'));
        $now = $W::nowUs();
        $sales = $now + 3600 * 1000000;
        $farS = intdiv($sales, 1000000) + 120;                  // 凍結の締切より遅い＝前倒しではない
        $newS = $farS + 300;                                   // B が書く値（遅くする方向。期限は延ばさないので公開判定には効かない）
        $rDate = date('Y-m-d', $farS);
        $key = ['date' => $rDate, 'kaisuu' => $src->kaisuu, 'basho' => '95', 'day' => $src->day, 'race' => 12];
        $rid = $A::buildPredictionRaceId($rDate, $src->kaisuu, '95', $src->day, 12);
        $sid = null; $proc = null;
        try {
            \DB::table('t_horse_odds_finder_races')->where($key)->delete();
            $row = (array) $src; unset($row['id']);
            \DB::table('t_horse_odds_finder_races')->insert(array_merge($row, $key, ['start_time' => date('H:i:s', $farS)]));
            $nums = [];
            foreach ((array) $in1['runners'] as $r) { $nums[] = (int) $r['horse_no']; }
            $sid = (int) \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => $rid, 'generation' => 900, 'run_id' => 'PCOMMIT',
                'snapshot_hash' => (string) $in1['snapshot_hash'],
                'input_json_1st' => $j($in1), 'input_json_2nd' => $j($in2), 'frozen_nums' => $j($nums),
                'allocation_mode' => (string) $in1['allocation_mode'], 'mode' => (string) $in1['mode'],
                'mode_status' => (string) $in1['mode_status'], 'target_count' => (int) $in1['target_count'],
                'quota_1st' => $j($in1['quota']), 'sales_close_at' => $W::toDb($sales), 'created_at' => $W::toDb($now),
            ]);
            \DB::table('baganriki_live_states')->insert([
                'snapshot_id' => $sid, 'cancellations' => '[]', 'active_count' => count($nums),
                'live_target_count' => (int) $in1['target_count'], 'live_quota' => $j($in1['quota']), 'live_close' => null,
                'state_revision' => 0, 'response_revision' => 0, 'publication_revision' => 0,
                'latest_publication_id' => null, 'updated_at' => $W::toDb($now),
            ]);
            foreach ([['1st', $out1], ['2nd', $out2]] as [$role, $doc]) {
                \DB::table('baganriki_ai_attempts')->insert([
                    'snapshot_id' => $sid, 'role' => $role, 'attempt_no' => 0,
                    'token' => hash('sha256', 'PCOMMIT' . $sid . $role), 'status' => $W::RECEIVED,
                    'reserved_at' => $W::toDb($now), 'received_at' => $W::toDb($now), 'complete' => 1,
                    'raw_response' => $BEGIN . $j($doc) . $END, 'response_hash' => hash('sha256', $j($doc)),
                ]);
            }
            $this->line('  試験の行 : 正本 ' . $rid . '（発走 ' . date('H:i:s', $farS) . '）／snapshot_id ' . $sid . '（確定済み）');

            // B：別プロセスで1回だけ UPDATE（待ち時間の上限30秒）。結果を JSON で標準出力へ
            $whereB = var_export($key, true);
            // @ANCHOR-20261025-NARU-PUBCOMMIT2  B は UPDATE の前に自分の接続ID（CONNECTION_ID）をファイルへ書き、A に渡します（なる 10/25 夜2回目 3章）
            $cidFile = sys_get_temp_dir() . '/baganriki_pcommit_' . getmypid() . '_' . $sid . '.txt';
            @unlink($cidFile);
            $codeB = '\DB::statement("SET SESSION innodb_lock_wait_timeout = 30");'
                   . 'file_put_contents(' . var_export($cidFile, true) . ', (string) \DB::selectOne("SELECT CONNECTION_ID() c")->c);'
                   . '$t0 = date_create("now", new \DateTimeZone("Asia/Tokyo"))->format("Y-m-d\\TH:i:s.uP");'
                   . 'try { $n = \DB::table("t_horse_odds_finder_races")->where(' . str_replace(["\n", '  '], ['', ''], $whereB) . ')->update(["start_time" => "' . date('H:i:s', $newS) . '"]); $ok = true; $err = null; }'
                   . 'catch (\Throwable $e) { $n = 0; $ok = false; $err = $e->getMessage(); }'
                   . '$t1 = date_create("now", new \DateTimeZone("Asia/Tokyo"))->format("Y-m-d\\TH:i:s.uP");'
                   . 'echo "BRESULT" . json_encode(["ok" => $ok, "rows" => $n, "start" => $t0, "end" => $t1, "error" => $err, "pid" => getmypid()], JSON_UNESCAPED_UNICODE) . PHP_EOL;';
            $cmdB = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(base_path('artisan')) . ' tinker --execute=' . escapeshellarg($codeB);
            $log = [];
            $waitSeen = false; $waitHow = ''; $tJudge = ''; $tWait = ''; $bCid = null; $waitRow = null;
            $hook = function (int $s) use (&$proc, &$pipes, $cmdB, $C, &$log, &$waitSeen, &$waitHow, &$tJudge, &$tWait, &$bCid, &$waitRow, $cidFile, $T, $W) {
                $tJudge = $T::toJst($W::nowUs());
                $log[] = 'A：正本を FOR UPDATE で読み、公開してよいと判定（保存のトランザクションの中・未COMMIT） ' . $tJudge;
                $proc = proc_open($cmdB, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $log[] = 'B：別プロセスを起動 ' . $T::toJst($W::nowUs());
                for ($i = 0; $i < 200 && $bCid === null; $i++) {
                    if (is_file($cidFile) && trim((string) file_get_contents($cidFile)) !== '') { $bCid = (int) trim((string) file_get_contents($cidFile)); break; }
                    usleep(100000);
                }
                $log[] = 'B：接続ID ' . ($bCid === null ? '受け取れず' : $bCid) . ' を受け取り（UPDATE の前に B が書いたもの） ' . $T::toJst($W::nowUs());
                if ($bCid !== null) {
                    for ($i = 0; $i < 200; $i++) {
                        $r = $C->selectOne("SELECT trx_mysql_thread_id, trx_state, trx_query FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$bCid]);
                        if ($r !== null) { $waitSeen = true; $waitRow = (array) $r; $waitHow = '接続ID ' . $bCid . ' が LOCK WAIT（' . mb_substr((string) ($r->trx_query ?? ''), 0, 120) . '）'; break; }
                        usleep(100000);
                    }
                }
                $tWait = $T::toJst($W::nowUs());
                $log[] = 'C：B の待ちを ' . ($waitSeen ? '確認（' . $waitHow . '）' : '確認できず') . ' ' . $tWait;
                usleep(500000);   // 待っている状態をもう少し保ってから、A を進めます
            };
            $res = app($A)->publishPrediction($sid, ['test_after_race_lock' => $hook]);
            $tAret = $T::toJst($W::nowUs());
            $log[] = 'A：公開本体の返却（code=' . (string) ($res['code'] ?? '') . '・transactionLevel=' . \DB::transactionLevel() . '） ' . $tAret;
            $bOut = '';
            if (is_resource($proc)) {
                $bOut = (string) stream_get_contents($pipes[1]); $bErr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
            }
            $b = null;
            if (preg_match('/BRESULT(\{.*\})/u', $bOut, $m)) { $b = json_decode($m[1], true); }
            if (is_array($b)) {
                $log[] = 'B：UPDATE 開始 ' . $b['start'] . ' → 完了 ' . $b['end'] . '（ok=' . var_export($b['ok'], true) . '・行数 ' . $b['rows'] . '）';
            }
            foreach ($log as $l) { $this->line('      ' . $l); }
            $check('K-01', 'A に外側のトランザクションが無い（公開本体の COMMIT が実際の確定になる）', \DB::transactionLevel(), 0);
            $check('K-02', 'A が正本をロックしたあと、B 自身の接続（CONNECTION_ID）が LOCK WAIT になった（接続 C で確認）',
                   [$bCid !== null, $waitSeen, is_array($waitRow) ? (int) $waitRow['trx_mysql_thread_id'] : null], [true, true, $bCid]);
            $check('K-03', 'A は公開した', (string) ($res['code'] ?? ''), 'PUBLISHED');
            $check('K-04', 'B の同じ UPDATE は、再送なしで成功した（1行）', is_array($b) ? [(bool) $b['ok'], (int) $b['rows']] : null, [true, 1]);
            $check('K-05', 'B の UPDATE は A の判定より後に始まり、B の待ちを確認した時刻より後に完了した',
                   is_array($b) ? [($b['start'] >= $tJudge), ($b['end'] >= $tWait)] : null, [true, true]);
            $check('K-06', '別の接続 C から：publication 1件・intent 1件が見える（確定している）',
                   [(int) $C->table('baganriki_publications')->where('snapshot_id', $sid)->count(),
                    (int) $C->table('baganriki_publication_intents')->where('snapshot_id', $sid)->count()], [1, 1]);
            $check('K-07', '別の接続 C から：正本の発走時刻は B が書いた値になっている',
                   (string) $C->table('t_horse_odds_finder_races')->where($key)->value('start_time'), date('H:i:s', $newS));
            $check('K-08', '別の接続 C から：B の接続の LOCK WAIT は残っていない',
                   $bCid === null ? null : (int) $C->selectOne("SELECT COUNT(*) c FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$bCid])->c, 0);
            if (!is_array($b)) { $this->line('  B の出力 : ' . mb_substr($bOut . ($bErr ?? ''), 0, 800)); }
        } catch (\Throwable $e) {
            $fail++;
            $this->error('  × 例外: ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            if (\DB::transactionLevel() > 0) { \DB::rollBack(); }
            if (is_resource($proc)) { proc_close($proc); }
            if (isset($cidFile)) { @unlink($cidFile); }
            if ($sid !== null) {
                // snapshot_id を持つ baganriki_ 表から、この試験の行だけを消します
                $tbls = \DB::select("SELECT table_name t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'snapshot_id' AND table_name LIKE 'baganriki\\_%'");
                foreach ($tbls as $t) {
                    $tn = (string) ($t->t ?? $t->TABLE_NAME ?? '');
                    if ($tn === '' || $tn === 'baganriki_snapshots') { continue; }
                    \DB::table($tn)->where('snapshot_id', $sid)->delete();
                }
                \DB::table('baganriki_snapshots')->where('id', $sid)->delete();
            }
            \DB::table('t_horse_odds_finder_races')->where($key)->delete();
        }
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件／合格 ' . $pass . '／不合格 ' . $fail);
        $this->line('  後片付け : 試験の正本・凍結入力と、その snapshot_id の行を消しました（実在のレースには触っていません）');
    }

    private function runRaceLockTest(): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 発走時刻の更新と最終確認の直列化（2つの接続・AIは呼ばない）');
        $this->info('════════════════════════════════════════════════════════');
        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $ok = ($got === $want); if ($ok) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-6s %-60s %s', $ok ? '○' : '×', $id, $what,
                $ok ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $def = (string) config('database.default');
        config(['database.connections.baganriki_race_b' => config('database.connections.' . $def)]);
        $B = \DB::connection('baganriki_race_b');
        $src = null;
        foreach (\DB::table('t_horse_odds_finder_races')->where('basho', '<', '90')->orderByDesc('date')->limit(20)->get() as $rr) {
            if (preg_match('/^\d{1,2}:\d{2}/', (string) ($rr->start_time ?? ''))) { $src = $rr; break; }
        }
        if ($src === null) { $this->error('  × 写し元のレースがありません'); return; }
        $postS = intdiv($W::nowUs(), 1000000) + 600;
        $date = date('Y-m-d', $postS);
        $key = ['date' => $date, 'kaisuu' => $src->kaisuu, 'basho' => '95', 'day' => $src->day, 'race' => 11];
        $row = (array) $src; unset($row['id']); $row = array_merge($row, $key, ['start_time' => date('H:i:s', $postS)]);
        $rid = $A::buildPredictionRaceId($date, $src->kaisuu, '95', $src->day, 11);
        $sid = null;
        try {
            $B->table('t_horse_odds_finder_races')->where($key)->delete();
            $B->table('t_horse_odds_finder_races')->insert($row);
            $sales = ($postS - 60) * 1000000;
            $sid = (int) $B->table('baganriki_snapshots')->insertGetId([
                'race_id' => $rid, 'generation' => 0, 'run_id' => 'RLOCK', 'release_version' => 'r12.2',
                'common_json' => '{}', 'input_json_1st' => '{}', 'input_json_2nd' => '{}',
                'snapshot_hash' => hash('sha256', 'rlock' . $postS), 'frozen_nums' => '[1,2]', 'frozen_rank' => '[1,2]',
                'mode' => 'MIXED', 'mode_status' => 'FIXED', 'sales_close_at' => $W::toDb($sales),
                'anchor_at' => $W::toDb($postS * 1000000),
                'cutoff_at' => $W::toDb(($postS - 360) * 1000000),
                'input_freeze_at' => $W::toDb(($postS - (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC)) * 1000000),
                'frozen_at' => $W::toDb($W::nowUs()),
                'created_at' => $W::toDb($W::nowUs()),
            ]);
            $B->table('baganriki_live_states')->insert([
                'snapshot_id' => $sid, 'cancellations' => '[]', 'active_count' => 2, 'live_target_count' => 2,
                'live_quota' => '[1,1,0]', 'live_close' => null, 'state_revision' => 0, 'response_revision' => 0,
                'publication_revision' => 0, 'latest_publication_id' => null, 'updated_at' => $W::toDb($W::nowUs()),
            ]);
            $B->statement('SET SESSION innodb_lock_wait_timeout = 2');

            // ── ① A が先に読む → B の UPDATE は A の確定まで待たされる ──
            \DB::beginTransaction();
            $snapA = \DB::table('baganriki_snapshots')->where('id', $sid)->first();
            $liveA = \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->lockForUpdate()->first();
            $d1 = $W::deadlines($snapA, $liveA, true);
            $check('R-01', 'A：凍結時の締切で期限を出す（まだ前倒しされていない）', [(bool) $d1['ok'], (bool) $d1['start_time_used']], [true, false]);
            $early = intdiv($W::nowUs(), 1000000) + 90;   // 公開期限はもう過ぎる時刻
            $blocked = false;
            try {
                $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => date('H:i:s', $early)]);
            } catch (\Throwable $e) {
                $blocked = (strpos($e->getMessage(), 'Lock wait timeout') !== false);
            }
            $check('R-02', 'B：A が最終確認を終えるまで、発走時刻を書き換えられない（ロック待ちで止まる）', $blocked, true);
            \DB::commit();
            $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => date('H:i:s', $early)]);
            $check('R-03', 'B：A が確定したあとは書き換えられる', (string) $B->table('t_horse_odds_finder_races')->where($key)->value('start_time'), date('H:i:s', $early));

            // ── ② B が先に書き換えて確定 → A の最終確認は新しい時刻で判定する ──
            \DB::beginTransaction();
            $snapA = \DB::table('baganriki_snapshots')->where('id', $sid)->first();
            $liveA = \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->lockForUpdate()->first();
            $d2 = $W::deadlines($snapA, $liveA, true);
            \DB::commit();
            $check('R-04', 'A：B の書き換えのあとは、新しい発走時刻で期限を出す（実状態はまだ書かれていない）',
                   [(bool) $d2['ok'], (bool) $d2['start_time_used'], (int) $d2['live_close']], [true, true, ($early - 60) * 1000000]);
            $rv = $W::reserve($sid, '1st', $W::FIRST, $W::nowUs(), []);
            $check('R-05', '新しい期限を過ぎているので、送信の予約をしない', (string) $rv['code'], 'DEADLINE_PASSED');

            // ── ③ 正本を読めない（時刻の形が壊れている）→ 期限を出さない ──
            $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => '??:??']);
            $snapA = \DB::table('baganriki_snapshots')->where('id', $sid)->first();
            $d3 = $W::deadlines($snapA, \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first());
            $check('R-06', '正本の発走時刻が読めないときは「変更なし」にせず、期限を出さない', [(bool) $d3['ok'], (string) $d3['code']], [false, 'START_TIME_UNREADABLE']);
            $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => 'XXX']);
            $d4 = $W::deadlines($snapA, \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first());
            // ── @ANCHOR-20261025-NARU-STARTED  XXX は「発走済み」。古い締切が未来に残っていても止める ──
            //   正本を「いまから10分後」に戻してから XXX にします（前倒しの記録が実状態にも無い＝古い締切だけが未来に残る場面）。
            $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => date('H:i:s', $postS)]);
            $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => 'XXX']);
            $d4 = $W::deadlines($snapA, \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first());
            $check('R-07', '発走済みの印（XXX）なら、古い締切（10分後）が未来でも期限を出さない（DB障害とは別の code）',
                   [(bool) $d4['ok'], (string) $d4['code'], $sales > $W::nowUs()], [false, 'RACE_STARTED', true]);
            $rv7 = $W::reserve($sid, '1st', $W::FIRST, $W::nowUs(), []);
            $check('R-09', '発走済みなら送信の予約をしない', (string) $rv7['code'], 'RACE_STARTED');
            $pb7 = app($A)->publishPrediction($sid);
            $check('R-10', '発走済みなら公開しない', [(bool) ($pb7['ok'] ?? false), (string) ($pb7['code'] ?? '')], [false, 'RACE_STARTED']);
            // ── 正本の行が無い：実在の場コードなら止める／試験用の場コード（90〜99）だけ読み飛ばす ──
            $snapReal = clone $snapA; $snapReal->race_id = $A::buildPredictionRaceId($date, $src->kaisuu, '05', $src->day, 13);
            $dNoRow = $W::deadlines($snapReal, null);
            $check('R-11', '実在の場コードで正本に行が無ければ止める（13R は存在しない）', [(bool) $dNoRow['ok'], (string) $dNoRow['code']], [false, 'RACE_ROW_MISSING']);
            $snapTest = clone $snapA; $snapTest->race_id = $A::buildPredictionRaceId($date, $src->kaisuu, '96', $src->day, 13);
            $check('R-12', '試験用の場コード（96）で行が無いときだけ読み飛ばす', (bool) $W::deadlines($snapTest, null)['ok'], true);
            // ── @ANCHOR-20261025-NARU-NOWC  期限を読んだあとの DB 読取の遅れも、HTTP の直前で引く（なる 10/23 5通目 6章）──
            //   応答期限を「いまから8秒後」にした凍結入力で、callPredictionAi を2回呼びます（2nd・外へは出さない）。
            //     遅れなし … 残り約7秒なので送信へ進む（偽の送信口が1回呼ばれる）
            //     遅れ4秒  … 期限を読んだ直後に4秒止める。残り約3秒なので送らない（偽の送信口は0回）・予約は0ドルで精算
            //   送信口は Http::fake で置き換え、呼ばれた回数を数えます（実AIへの通信は0回）。
            $httpCalls = 0;
            \Illuminate\Support\Facades\Http::fake(function ($req) use (&$httpCalls) {
                $httpCalls++;
                return \Illuminate\Support\Facades\Http::response(['error' => 'fake'], 500);
            });
            $B->table('t_horse_odds_finder_races')->where($key)->update(['start_time' => date('H:i:s', $postS)]);
            $envKeep = ['BAGANRIKI_PREDICTION_FAKE_AI' => (string) env('BAGANRIKI_PREDICTION_FAKE_AI', ''),
                        'BAGANRIKI_PREDICTION_BUDGET_USD_DAY' => (string) env('BAGANRIKI_PREDICTION_BUDGET_USD_DAY', '')];
            $setEnv = static function (string $k, string $v) {
                putenv($k . ($v === '' ? '' : '=' . $v));
                if ($v === '') { unset($_ENV[$k], $_SERVER[$k]); } else { $_ENV[$k] = $v; $_SERVER[$k] = $v; }
            };
            try {
                $setEnv('BAGANRIKI_PREDICTION_FAKE_AI', '0');
                $setEnv('BAGANRIKI_PREDICTION_BUDGET_USD_DAY', (string) round($A::spentTodayUsd($W::nowUs()) + 5, 6));
                $userN = (string) json_encode(['race_id' => $rid]);
                $setSales = function () use ($B, $sid, $W) {
                    $B->table('baganriki_snapshots')->where('id', $sid)
                      ->update(['sales_close_at' => $W::toDb($W::nowUs() + 98 * 1000000)]);   // 応答期限＝締切−90秒＝8秒後
                };
                $setSales();
                $c1 = app($A)->callPredictionAi('2nd', 'sys', $userN, 60, $sid);
                $check('R-13', '遅れなし：残りが5秒以上なので送信へ進む（偽の送信口1回）', [(string) $c1['code'], $httpCalls], ['API_ERROR', 1]);
                $setSales();
                $A::$testAfterDeadlineRead = static function () { sleep(4); };
                $c2 = app($A)->callPredictionAi('2nd', 'sys', $userN, 60, $sid);
                $A::$testAfterDeadlineRead = null;
                $check('R-14', '期限を読んだあとに4秒遅れたら送らない（偽の送信口は増えない）', [(string) $c2['code'], $httpCalls], ['DEADLINE_AFTER_WAIT', 1]);
                $rel = (int) $A::budgetDb()->table('baganriki_audit_events')->where('kind', 'R122_BUDGET_RELEASE')
                         ->where('related_id', (string) ($c2['budget_hold'] ?? '-'))->count();
                $check('R-15', '送らなかった予約は0ドルで精算されている', [is_string($c2['budget_hold'] ?? null), $rel], [true, 1]);
            } finally {
                $A::$testAfterDeadlineRead = null;
                foreach ($envKeep as $k => $v) { $setEnv($k, $v); }
                $bdb = $A::budgetDb();
                foreach ($bdb->table('baganriki_audit_events')->where('race_id', $rid)->pluck('id')->all() as $idB) {
                    $bdb->table('baganriki_audit_events')->where('id', (int) $idB)->delete();
                }
            }
            $check('R-08', 'ここまでAIへの送信は0回', (int) \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->count(), 0);
        } finally {
            if (\DB::transactionLevel() > 0) { \DB::rollBack(); }
            if ($sid !== null) {
                $B->table('baganriki_ai_attempts')->where('snapshot_id', $sid)->delete();
                $B->table('baganriki_live_states')->where('snapshot_id', $sid)->delete();
                $B->table('baganriki_audit_events')->where('snapshot_id', $sid)->delete();
                $B->table('baganriki_snapshots')->where('id', $sid)->delete();
            }
            $B->table('t_horse_odds_finder_races')->where($key)->delete();
        }
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件／合格 ' . $pass . '／不合格 ' . $fail);
        $this->line('  後片付け : 試験用のレース・凍結入力を消しました（実在のレースには触っていません）');
    }

    /**
     * @ANCHOR-20261023-NARU-FIXCMP  訂正方針 V1（contract）と V2（missing）を、診断別の固定ケースで比べます（なる 10/23 2通目 5章）
     *   配布の正常出力（MIXED_N16・1st）に、1か所だけ誤りを入れた5ケースを作ります。
     *     合計のみ／全頭そろった FLAG_VS_LIST／帯の不一致／不正参照＋正常SIGNAL／馬の欠落
     *   各ケースを、方針ごとに「訂正を依頼するか」と、訂正なしで初回から公開したときの
     *   保持された正常馬・帯別の候補・順位の出どころ（縮退）・不足理由で比べます。
     *   V1 で訂正を依頼した場合の結果は、AIの答えしだいなのでここでは作りません（AIは呼びません）。
     *   DBは全部ロールバックします。結果は送付資料ルートの下へ fixpolicy_compare_<日時>.json／.txt で残します。
     */
    private function runFixPolicyCompare(string $root): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $vDir = rtrim($root, '/') . '/validation/normal';
        $rd = static fn(string $f) => json_decode((string) file_get_contents($f), true);
        $j = static fn($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $in1 = $rd($vDir . '/MIXED_N16_1st_input.json');
        $out1 = $rd($vDir . '/MIXED_N16_1st_output.json');
        if (!is_array($in1) || !is_array($out1)) { $this->error('  × ' . $vDir . ' の MIXED_N16 がありません'); return; }
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 訂正方針 V1／V2 の診断別比較（AIは呼ばない・DBはロールバック）');
        $this->info('════════════════════════════════════════════════════════');

        $idx = static function (array $o, int $no): ?int {
            foreach ((array) $o['horses'] as $i => $h) { if ((int) $h['horse_no'] === $no) { return $i; } }
            return null;
        };
        $cases = [];
        $o = $out1; $o['horses'][$idx($o, 2)]['observed_sum'] = 3;
        $cases['合計のみ（2番の observed_sum を3にずらす）'] = $o;
        $o = $out1; $o['horses'][$idx($o, 2)]['public_candidate'] = false;
        $cases['全頭そろった FLAG_VS_LIST（2番の印だけ外す）'] = $o;
        $o = $out1; $o['horses'][$idx($o, 8)]['band'] = 'D';
        $cases['帯の不一致（B帯の8番を D と書く）'] = $o;
        $o = $out1; $k = $idx($o, 2);
        $o['horses'][$k]['counterevidence'] = [['source_refs' => ['zz_unknown_ref'], 'reason' => '存在しない参照']];
        $cases['不正参照＋正常SIGNAL（2番の反証に存在しない参照）'] = $o;
        $o = $out1; array_splice($o['horses'], $idx($o, 9), 1);
        $cases['馬の欠落（9番の行を消す）'] = $o;

        $rep = ['processed_at' => date('c'), 'source' => 'MIXED_N16_1st（配布の正常出力）', 'cases' => []];
        $orig = (string) env('BAGANRIKI_PREDICTION_FIX_POLICY', '');
        \DB::beginTransaction();
        try {
            $ctrl = app($A);
            $seq = 0;
            foreach ($cases as $label => $doc) {
                $row = ['case' => $label];
                foreach (['contract', 'missing'] as $pol) {
                    putenv('BAGANRIKI_PREDICTION_FIX_POLICY=' . $pol);
                    $_ENV['BAGANRIKI_PREDICTION_FIX_POLICY'] = $pol; $_SERVER['BAGANRIKI_PREDICTION_FIX_POLICY'] = $pol;
                    $seq++;
                    $now = $W::nowUs();
                    $nums = array_map(static fn($r) => (int) $r['horse_no'], (array) $in1['runners']);
                    $sid = (int) \DB::table('baganriki_snapshots')->insertGetId([
                        'race_id' => (string) $in1['race_id'], 'generation' => 800 + $seq, 'run_id' => 'FIXCMP',
                        'snapshot_hash' => (string) $in1['snapshot_hash'],
                        'input_json_1st' => $j($in1), 'input_json_2nd' => '{}',
                        'frozen_nums' => $j($nums), 'allocation_mode' => (string) $in1['allocation_mode'],
                        'mode' => (string) $in1['mode'], 'mode_status' => (string) $in1['mode_status'],
                        'target_count' => (int) $in1['target_count'], 'quota_1st' => $j($in1['quota']),
                        'sales_close_at' => $W::toDb($now + 3600 * 1000000), 'created_at' => $W::toDb($now),
                    ]);
                    $W::ensureLive($sid, $now);
                    $raw = $BEGIN . $j($doc) . $END;
                    \DB::table('baganriki_ai_attempts')->insert([
                        'snapshot_id' => $sid, 'role' => '1st', 'attempt_no' => 0,
                        'token' => hash('sha256', 'FC' . $sid), 'status' => $W::RECEIVED,
                        'reserved_at' => $W::toDb($now), 'received_at' => $W::toDb($now),
                        'complete' => 1, 'raw_response' => $raw, 'response_hash' => hash('sha256', $raw),
                    ]);
                    $insp = $ctrl->inspectAiResponse($sid, '1st', 0);
                    $allow = $A::correctionPolicyAllows($insp);
                    $pub = $ctrl->publishPrediction($sid);
                    $codes = [];
                    foreach ((array) ($insp['diag']['findings'] ?? []) as $f) {
                        $codes[] = (string) ($f['target'] ?? '-') . ':' . (string) $f['code'];
                    }
                    $byBand = [];
                    foreach ((array) ($pub['candidates'] ?? []) as $c) { $byBand[(string) ($c['band'] ?? '?')][] = (int) $c['horse_no']; }
                    $row[$pol] = [
                        'policy_id' => $A::correctionPolicyId(),
                        'findings' => array_values(array_unique($codes)),
                        'needs_fix' => (bool) ($insp['needs_fix'] ?? false),
                        'fix_requested' => (bool) (($insp['needs_fix'] ?? false) && $allow['allow']),
                        'fix_reason' => $allow['reason'],
                        'kept_normal' => count((array) ($insp['normal'] ?? [])),
                        'salvaged' => (array) ($insp['salvaged'] ?? []),
                        'publish_code' => $pub['code'] ?? null,
                        'candidates_by_band' => $byBand,
                        'quota' => $pub['quota'] ?? null,
                        'rank_source' => $pub['rank_source']['1st'] ?? ($pub['rank_source'] ?? null),
                        'shortfalls' => $pub['shortfalls'] ?? null,
                    ];
                }
                $rep['cases'][] = $row;
            }
        } finally {
            \DB::rollBack();
            putenv('BAGANRIKI_PREDICTION_FIX_POLICY' . ($orig === '' ? '' : '=' . $orig));
            if ($orig === '') { unset($_ENV['BAGANRIKI_PREDICTION_FIX_POLICY'], $_SERVER['BAGANRIKI_PREDICTION_FIX_POLICY']); }
            else { $_ENV['BAGANRIKI_PREDICTION_FIX_POLICY'] = $orig; $_SERVER['BAGANRIKI_PREDICTION_FIX_POLICY'] = $orig; }
        }
        $lines = ['訂正方針 V1（contract）／V2（missing）の診断別比較（' . $rep['processed_at'] . '）', ''];
        foreach ($rep['cases'] as $c) {
            $lines[] = '■ ' . $c['case'];
            foreach (['contract' => 'V1', 'missing' => 'V2'] as $pol => $nm) {
                $x = $c[$pol];
                $lines[] = sprintf('  %s 訂正依頼=%s 正常馬=%d 救済=%s 公開=%s 候補=%s rank=%s',
                    $nm, $x['fix_requested'] ? 'する' : 'しない', $x['kept_normal'], $j($x['salvaged']),
                    (string) $x['publish_code'], $j($x['candidates_by_band']), $j($x['rank_source']));
                $lines[] = '     診断=' . implode(' ', $x['findings']) . '／不足=' . $j($x['shortfalls']);
            }
            $lines[] = '';
        }
        $stamp = date('Ymd_His');
        $base = rtrim($root, '/') . '/fixpolicy_compare_' . $stamp;
        file_put_contents($base . '.json', json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        file_put_contents($base . '.txt', implode("\n", $lines) . "\n");
        foreach ($lines as $l) { $this->line('  ' . $l); }
        $this->line('  書き出し : ' . $base . '.json／.txt');
    }

    /**
     * @ANCHOR-20261023-NARU-REPROC  保存した入力と原応答を、最新版で処理し直してファイルに残します（なる 10/23 2通目 4章）
     *   AIは呼びません。DBは全部ロールバックします（処理の途中だけ、試験用の行を入れます）。
     *   入力：ディレクトリ（*_input.json・*_attempt0.txt・*_attempt1.txt）
     *   出力：同じディレクトリに reprocess_<role>_<日時>.json と .txt
     *   馬ごとに：帯／初回の行の有無／初回の診断コード／自分の SIGNAL の数／救済／訂正の診断コード／
     *   訂正の採否（適用・救済・飛ばし）／最終の行の有無／候補か。
     *   レース全体：訂正の採否理由・repair_scope・rank_source・rank_notes・shortfalls・候補・処理した版のハッシュ。
     */
    private function runReprocessSaved(string $dir): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $dir = rtrim($dir, '/');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 保存した原応答を最新版で処理し直す（AIは呼ばない・DBはロールバック）');
        $this->info('════════════════════════════════════════════════════════');
        $fIn = glob($dir . '/*_input.json'); $f0 = glob($dir . '/*_attempt0.txt'); $f1 = glob($dir . '/*_attempt1.txt');
        if (!$fIn || !$f0) { $this->error('  × ' . $dir . ' に *_input.json と *_attempt0.txt がありません'); return; }
        $inRaw = (string) file_get_contents($fIn[0]);
        $in = json_decode($inRaw, true);
        if (!is_array($in)) { $this->error('  × 入力JSONを読めません'); return; }
        $role = (($in['ai_role'] ?? '1st') === '2nd') ? '2nd' : '1st';
        $raw0 = (string) file_get_contents($f0[0]);
        $raw1 = $f1 ? (string) file_get_contents($f1[0]) : null;
        $band = []; $frozen = [];
        foreach ((array) ($in['runners'] ?? []) as $r) { $band[(int) $r['horse_no']] = (string) ($r['band'] ?? ''); $frozen[] = (int) $r['horse_no']; }
        $rcA = new \ReflectionClass($A);
        $rep = ['processed_at' => date('c'), 'role' => $role,
                'code_sha256' => hash_file('sha256', (string) $rcA->getFileName()),
                'input_file' => basename($fIn[0]), 'input_sha256' => hash('sha256', $inRaw),
                'attempt0_sha256' => hash('sha256', $raw0), 'attempt1_sha256' => ($raw1 === null) ? null : hash('sha256', $raw1),
                'fix_policy' => $A::correctionPolicyId(),
                'fix_partial' => (string) env('BAGANRIKI_PREDICTION_FIX_PARTIAL', '0'),
                'runners_by_band' => [], 'horses' => []];
        foreach ($band as $no => $b) { $rep['runners_by_band'][$b][] = $no; }

        \DB::beginTransaction();
        try {
            $ctrl = app($A);
            $now = $W::nowUs();
            $col = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
            $other = ($role === '2nd') ? 'input_json_1st' : 'input_json_2nd';
            $sid = (int) \DB::table('baganriki_snapshots')->insertGetId([
                // 応答の race_id・snapshot_hash と照合されるので、入力と同じ race_id を使います。
                // 世代は 900（本番の 0 と重ならない番号）。ロールバックするので残りません。
                'race_id' => (string) ($in['race_id'] ?? ''),
                'generation' => 900, 'run_id' => 'REPROC',
                'snapshot_hash' => (string) ($in['snapshot_hash'] ?? ''),
                $col => $inRaw, $other => '{}',
                'frozen_nums' => json_encode($frozen),
                'allocation_mode' => $in['allocation_mode'] ?? null,
                'mode' => (string) ($in['mode'] ?? ''), 'mode_status' => (string) ($in['mode_status'] ?? ''),
                'target_count' => (int) ($in['target_count'] ?? 0),
                'quota_1st' => json_encode($in['quota'] ?? null),
                // 期限で落ちないよう、処理の時点から1時間後を締切にします（判定の中身には関係しません）
                'sales_close_at' => $W::toDb($now + 3600 * 1000000),
                'created_at' => $W::toDb($now),
            ]);
            $W::ensureLive($sid, $now);
            $ins = function (int $no, string $raw) use ($sid, $role, $now, $W) {
                \DB::table('baganriki_ai_attempts')->insert([
                    'snapshot_id' => $sid, 'role' => $role, 'attempt_no' => $no,
                    'token' => hash('sha256', 'RP' . $sid . $role . $no), 'status' => $W::RECEIVED,
                    'reserved_at' => $W::toDb($now + $no), 'received_at' => $W::toDb($now + $no),
                    'complete' => 1, 'raw_response' => $raw, 'response_hash' => hash('sha256', $raw),
                ]);
            };
            $ins(0, $raw0);
            $i0 = $ctrl->inspectAiResponse($sid, $role, 0);
            $i1 = null;
            if ($raw1 !== null) { $ins(1, $raw1); $i1 = $ctrl->inspectAiResponse($sid, $role, 1); }
            $acc = $ctrl->acceptedHorsesOf($sid, $role);
            $pub = $ctrl->publishPrediction($sid);
            $codesOf = static function (?array $insp, int $no): array {
                $c = [];
                foreach ((array) ($insp['diag']['findings'] ?? []) as $f) {
                    if ((int) ($f['target'] ?? -1) === $no) { $c[] = (string) $f['code']; }
                }
                return array_values(array_unique($c));
            };
            $rows0 = array_map('intval', array_keys((array) ($i0['normal_kept'] ?? [])));
            $miss0 = array_values(array_unique(array_map('intval', array_merge((array) ($i0['diag']['missing'] ?? []), (array) ($i0['diag']['iso_missing'] ?? [])))));
            sort($miss0);
            $cand = [];
            foreach ((array) ($pub['candidates'] ?? []) as $c) { $cand[(int) $c['horse_no']] = $c; }
            $merged = array_map('intval', array_keys((array) ($acc['merged'] ?? [])));
            foreach ($band as $no => $b) {
                $rep['horses'][] = [
                    'horse_no' => $no, 'band' => $b,
                    'initial_row' => in_array($no, $miss0, true) ? 'MISSING' : 'PRESENT',
                    'initial_codes' => $codesOf($i0, $no),
                    'own_signal' => (int) ($i0['diag']['ref_stats'][$no]['own_signal'] ?? 0),
                    'salvaged' => in_array($no, array_map('intval', (array) ($i0['salvaged'] ?? [])), true),
                    'kept_after_initial' => in_array($no, $rows0, true),
                    'fix_codes' => ($i1 === null) ? null : $codesOf($i1, $no),
                    'fix_band' => ($i1 === null) ? null : (string) (($i1['normal_kept'][$no]['band'] ?? null) ?? ''),
                    'fix_applied' => in_array($no, array_map('intval', (array) ($acc['applied'] ?? [])), true),
                    'fix_rescued' => in_array($no, array_map('intval', (array) ($acc['rescued'] ?? [])), true),
                    'fix_skipped' => in_array($no, array_map('intval', (array) ($acc['skipped'] ?? [])), true),
                    'in_final_rows' => in_array($no, $merged, true),
                    'candidate' => isset($cand[$no]),
                    'candidate_detail' => $cand[$no] ?? null,
                ];
            }
            $rep['initial'] = ['ok' => $i0['ok'] ?? null, 'code' => $i0['code'] ?? null, 'complete' => $i0['complete'] ?? null,
                               'repair_scope' => $i0['repair_scope'] ?? null, 'missing' => $miss0,
                               'salvaged' => $i0['salvaged'] ?? [], 'reason' => $i0['reason'] ?? null];
            $rep['fix'] = ($i1 === null) ? null : ['ok' => $i1['ok'] ?? null, 'code' => $i1['code'] ?? null,
                               'repair_scope' => $i1['repair_scope'] ?? null, 'reason' => $i1['reason'] ?? null];
            $rep['resolve'] = ['ok' => $acc['ok'] ?? null, 'code' => $acc['code'] ?? null, 'reason' => $acc['reason'] ?? null,
                               'applied' => $acc['applied'] ?? [], 'rescued' => $acc['rescued'] ?? [], 'skipped' => $acc['skipped'] ?? []];
            $rep['publish'] = ['code' => $pub['code'] ?? null, 'reason' => $pub['reason'] ?? null,
                               'quota' => $pub['quota'] ?? null,
                               'candidates' => array_map(static fn($c) => ['horse_no' => $c['horse_no'] ?? null, 'band' => $c['band'] ?? null] + $c, (array) ($pub['candidates'] ?? [])),
                               'shortfalls' => $pub['shortfalls'] ?? null,
                               'rank_source' => $pub['rank_source'] ?? null, 'rank_fallback' => $pub['rank_fallback'] ?? null,
                               'rank_notes' => $pub['rank_notes'] ?? null];
        } finally {
            \DB::rollBack();
        }
        $stamp = date('Ymd_His');
        $jf = $dir . '/reprocess_' . $role . '_' . $stamp . '.json';
        file_put_contents($jf, json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
        $lines = [];
        $lines[] = '保存した原応答の再処理（' . $rep['processed_at'] . '）';
        $lines[] = 'AiController sha256 ' . $rep['code_sha256'] . '／訂正方針 ' . $rep['fix_policy'] . '／FIX_PARTIAL=' . $rep['fix_partial'];
        $lines[] = '帯：' . json_encode($rep['runners_by_band'], JSON_UNESCAPED_UNICODE);
        $lines[] = '初回：' . json_encode($rep['initial'], JSON_UNESCAPED_UNICODE);
        $lines[] = '訂正：' . json_encode($rep['fix'], JSON_UNESCAPED_UNICODE);
        $lines[] = '採否：' . json_encode($rep['resolve'], JSON_UNESCAPED_UNICODE);
        $lines[] = '';
        $lines[] = '馬番 帯 初回 初回の診断 自分のSIGNAL 救済 訂正の帯 訂正の診断 訂正の扱い 最終行 候補';
        foreach ($rep['horses'] as $h) {
            $fx = $h['fix_applied'] ? '適用' : ($h['fix_rescued'] ? '救済' : ($h['fix_skipped'] ? '飛ばし' : '—'));
            $lines[] = sprintf('%2d %s %s %s %d %s %s %s %s %s %s', $h['horse_no'], $h['band'], $h['initial_row'],
                implode(',', $h['initial_codes']) ?: '—', $h['own_signal'], $h['salvaged'] ? '○' : '—',
                (string) ($h['fix_band'] ?? '—') ?: '—', ($h['fix_codes'] === null) ? '—' : (implode(',', $h['fix_codes']) ?: '—'),
                $fx, $h['in_final_rows'] ? '○' : '—', $h['candidate'] ? '○' : '—');
        }
        $lines[] = '';
        $lines[] = '公開：' . (string) $rep['publish']['code'] . '／枠 ' . json_encode($rep['publish']['quota']);
        $lines[] = '候補：' . implode(' ', array_map(static fn($c) => (string) $c['horse_no'] . (string) ($c['band'] ?? ''), $rep['publish']['candidates']));
        $lines[] = 'rank_source：' . json_encode($rep['publish']['rank_source'], JSON_UNESCAPED_UNICODE);
        $lines[] = 'rank_fallback：' . json_encode($rep['publish']['rank_fallback'], JSON_UNESCAPED_UNICODE);
        foreach ((array) $rep['publish']['rank_notes'] as $n) { $lines[] = 'rank_note：' . $n; }
        $lines[] = 'shortfalls：' . json_encode($rep['publish']['shortfalls'], JSON_UNESCAPED_UNICODE);
        $tf = $dir . '/reprocess_' . $role . '_' . $stamp . '.txt';
        file_put_contents($tf, implode("\n", $lines) . "\n");
        foreach ($lines as $l) { $this->line('  ' . $l); }
        $this->line('');
        $this->line('  書き出し : ' . $jf);
        $this->line('             ' . $tf);
    }

    /**
     * @ANCHOR-20261023-COMPACT  出典一覧を詰めた形の確認（課金ゼロ）
     *   保存した入力JSON（凍結した本文）を読み、詰めた形へ変え、元へ1バイトも違わずに
     *   戻せることを確かめ、元と詰めた形のトークン数を count_tokens（無料）で数えます。
     *   本文の生成は1回も呼びません。DBにも書きません。送信の動きも変えません。
     */
    private function runCompactCheck(string $file): void
    {
        self::loadPredictionClasses();
        $P = \App\Http\Controllers\Api\BaganrikiPrompt::class;
        $J = \App\Http\Controllers\Api\BaganrikiJson::class;
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 出典一覧を詰めた形の確認（課金ゼロ・count_tokens だけ）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  入力     : ' . $file);
        if (!is_file($file)) { $this->error('  × ファイルがありません'); return; }
        $raw = (string) file_get_contents($file);
        $r = $J::strict($raw);
        if (!$r['ok'] || !is_array($r['value']['source_registry'] ?? null)) {
            $this->error('  × 入力JSONとして読めません'); return;
        }
        $in = $r['value'];
        $reg = $in['source_registry'];
        $enc = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $c = $P::compactRegistry($reg);
        $this->line('  詰められたか : ' . ($c['ok'] ? 'はい（元へ1バイトも違わずに戻せることを確認）' : 'いいえ：' . $c['reason']));
        if (!$c['ok']) { return; }
        $idxSame = $P::canonical($P::indicesFor($reg)) === $P::canonical($in['input_indices'] ?? null);
        $this->line('  input_indices は出典一覧から作り直せるか : ' . ($idxSame ? 'はい' : 'いいえ'));
        $w1 = $in; unset($w1['source_registry']); $w1['source_table'] = $c['table'];
        $cw = $P::compactWire($in);
        $this->line('  本文全体を詰めて戻せるか : ' . ($cw['ok'] ? 'はい（canonical が元と一致）' : 'いいえ：' . $cw['reason']));
        $bodies = ['元の本文' => $raw, '出典一覧だけ詰めた形' => $enc($w1)];
        if ($cw['ok']) {
            $bodies['本文全体を詰めた形'] = $enc($cw['wire']);
            // 実際に送る形（BAGANRIKI_PREDICTION_COMPACT=1 のときに callPredictionAi が送るもの。説明文を指示文の後ろに足す）
            $sendW = $cw['wire']; $sendW['wire_encoding'] = $P::SOURCE_TABLE_VERSION;
            $bodies['送る形（説明文つき）'] = (string) $P::canonical($sendW);
        }
        $sysB = $P::systemBody(($in['ai_role'] ?? '1st') === '2nd' ? '2nd' : '1st');
        $sysText = $sysB['ok'] ? (string) $sysB['body'] : '';
        $this->line('  指示文 : ' . ($sysB['ok'] ? number_format(strlen($sysText)) . ' バイト（一緒に数えます）' : '読めません（本文だけ数えます）'));

        $key = (string) (env('ANTHROPIC_API_KEY') ?: config('services.anthropic.api_key'));
        $model = trim((string) env('BAGANRIKI_PREDICTION_1ST_MODEL', '')) ?: 'claude-haiku-5-5';
        $this->line('  数えるモデル : ' . $model);
        $this->line('');
        foreach ($bodies as $label => $body) {
            $tok = '—';
            // （指示文 $sysText を一緒に数えます）
            if ($key !== '') {
                try {
                    $res = \Illuminate\Support\Facades\Http::timeout(120)->withHeaders([
                        'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])->post('https://api.anthropic.com/v1/messages/count_tokens', array_filter([
                        'model' => $model,
                        'system' => ($sysText === '') ? null
                                    : ($label === '送る形（説明文つき）'
                                        ? $sysText . "\n\n" . \App\Http\Controllers\Api\AiController::COMPACT_PROMPT_ADDENDUM
                                        : $sysText),
                        'messages' => [['role' => 'user', 'content' => $body]],
                    ], static fn($x) => $x !== null));
                    $tok = $res->failed() ? ('失敗 status=' . $res->status())
                                          : number_format((int) $res->json('input_tokens'));
                } catch (\Throwable $e) { $tok = '失敗 ' . $e->getMessage(); }
            }
            $this->line(sprintf('  %-22s %10s バイト  %12s トークン', $label, number_format(strlen($body)), $tok));
        }
        $this->line('');
        $this->line('  ※ トークン数は指示文＋本文です。Haiku 5.5 は入力10万トークン以下なら単価が5分の1になります');

        // @ANCHOR-20261023-NARU-ADDENDUM2  列が rows に残る場合・null・表外の parents・依存元の形（なる 10/23 4通目 5章）
        $this->line('');
        $this->info('── 列が rows に残る場合の照合 ──');
        $pass = 0; $fail = 0;
        $ck = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $ok = ($got === $want); if ($ok) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-6s %-56s %s', $ok ? '○' : '×', $id, $what, $ok ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $base = ['race_id' => 'R', 'value' => '1.5', 'horse_nos' => [1], 'applicable_horse_nos' => [], 'field' => 'f1',
                 'unit' => 'odds', 'slot_offset' => 6, 'observed_at' => '2026-10-24T12:00:00+09:00', 'available_at' => '2026-10-24T12:00:01+09:00',
                 'computed_at' => null, 'source_kind' => 'OBSERVED', 'parents' => [], 'formula_id' => null, 'formula_version' => null,
                 'dependency_role' => 'SIGNAL', 'scope' => 'HORSE'];
        $reg = [];
        $reg['h1_f1_6'] = ['source_ref' => 'h1_f1_6', 'dependency_key' => 'h1_f1'] + $base;
        $reg['h2_f1_6'] = ['source_ref' => 'h2_f1_6', 'dependency_key' => 'h2_f1', 'horse_nos' => [2]] + $base;
        // 同じ field で scope・dependency_role・unit が違う行、unit が null の行
        $reg['p12_f1_6'] = ['source_ref' => 'p12_f1_6', 'dependency_key' => 'pair_x', 'horse_nos' => [1, 2],
                            'scope' => 'PAIR', 'dependency_role' => 'CONTEXT', 'unit' => null,
                            'parents' => ['h1_f1_6', 't_horse_odds_finder_odds']] + $base;
        $cs = $P::compactRegistry($reg);
        $ck('T-01', '詰められて、元へ戻せる', (bool) $cs['ok'], true);
        $cols = (array) ($cs['table']['columns'] ?? []);
        $ck('T-02', 'field 内で値が違う scope・dependency_role・unit は rows の列に残る',
            [in_array('scope', $cols, true), in_array('dependency_role', $cols, true), in_array('unit', $cols, true)], [true, true, true]);
        $fd = (array) (((array) ($cs['table']['field_defaults'] ?? []))['f1'] ?? []);
        $ck('T-03', 'rows に残した列は field_defaults に入れない', [array_key_exists('scope', $fd), array_key_exists('unit', $fd), array_key_exists('source_kind', $fd)], [false, false, true]);
        $ex = $P::expandRegistry(json_decode((string) json_encode($cs['table'] ?? []), true));
        $rowP = (array) ($ex['registry']['p12_f1_6'] ?? []);
        $ck('T-04', 'unit が null の行は null のまま戻る（defaults で置き換えない）',
            [array_key_exists('unit', $rowP), array_key_exists('unit', $rowP) ? $rowP['unit'] : 'x'], [true, null]);
        $ck('T-05', '表外の parents（表の名前）は文字列のまま、行の名前は行番号から戻る',
            ($ex['registry']['p12_f1_6']['parents'] ?? null), ['h1_f1_6', 't_horse_odds_finder_odds']);
        $ck('T-06', 'source_ref の先頭でない依存元は文字列のまま戻る', ($ex['registry']['p12_f1_6']['dependency_key'] ?? null), 'pair_x');
        $ck('T-07', '全体が元と1バイトも違わない', $P::canonical($ex['registry'] ?? null), $P::canonical($reg));
        $ad = \App\Http\Controllers\Api\AiController::COMPACT_PROMPT_ADDENDUM;
        $ck('T-08', '説明文：columns にあれば行の値・無いときだけ field_defaults・null は行の値・番号は0始まり',
            [mb_strpos($ad, 'columns にその項目があれば、その行の値を使う') !== false,
             mb_strpos($ad, 'null も行の値として扱い') !== false,
             mb_strpos($ad, 'time_dict の番号（0始まり）') !== false,
             mb_strpos($ad, '行番号（0始まり）') !== false], [true, true, true, true]);
        $ck('T-09', '説明文の版の名前', \App\Http\Controllers\Api\AiController::COMPACT_ADDENDUM_VERSION, 'SOURCE_TABLE_V1_ADDENDUM_V2');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件／合格 ' . $pass . '／不合格 ' . $fail);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-NARU-AUDIT10  011再精査10版の追加139項目を、統合後の本物の実装で通します
     * ═══════════════════════════════════════════════════════════════════════
     *   なる 10/23 5章A：「011のaudit10にある139項目の回帰確認と、実取得payload形式での
     *   結合確認が必要です。011側の過去の成功記録を、統合後の成功記録に読み替えないでください。」
     *
     *   011 の tests/audit10.php の139項目を、項目の並び・入力・期待をそのまま写しました。
     *   違うのは呼び先だけです。
     *     ・011 は試験用の代替DB（PHP の配列）で動かしています。ここは本物の MySQL と Laravel です。
     *     ・011 の Decimal／ExactJson は採っていない（統合の方針）ので、長い数値は
     *       JSON の数値トークンを原文に直接書いて作ります。期待する十進表記は手で書きました。
     *     ・r122GatherContext → gatherPredictionContext、BaganrikiPrompt 等は同じ名前です。
     *   写すのは実在のレース1件の行（レース1行・出馬表の1番と2番）です。場コードは96。
     *   原ペイロードは、オッズ取得（ImportKeibaOdds）が保存するのと同じ形です。
     *   全部ロールバックします。AIは呼びません。
     */
    private function runRawOddsAuditTest(string $src): void
    {
        self::loadPredictionClasses();
        $A  = \App\Http\Controllers\Api\AiController::class;
        $P  = \App\Http\Controllers\Api\BaganrikiPrompt::class;
        $T  = \App\Http\Controllers\Api\BaganrikiTime::class;
        $W  = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $AQ = \App\Http\Controllers\Api\BaganrikiAcquisition::class;
        $J  = \App\Http\Controllers\Api\BaganrikiJson::class;

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 011再精査10版の追加139項目（原オッズの読込）を統合後の実装で');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ AIは呼びません。全部ロールバックします');

        // 写し元：指定がなければ、出馬表に1番と2番がある直近の実在レース
        $race = null;
        if ($src !== '' && $src !== '1') {
            $sp = explode('-', $src);
            if (count($sp) === 5) {
                $d = substr($sp[0], 0, 4) . '-' . substr($sp[0], 4, 2) . '-' . substr($sp[0], 6, 2);
                $race = \DB::table('t_horse_odds_finder_races')->where('date', $d)
                          ->where('kaisuu', $sp[1])->where('basho', $sp[2])
                          ->where('day', $sp[3])->where('race', (int) $sp[4])->first();
            }
        } else {
            foreach (\DB::table('t_horse_odds_finder_races')->where('basho', '<', '90')
                        ->orderByDesc('date')->orderBy('race')->limit(50)->get() as $r) {
                $n = \DB::table('t_horse_odds_finder_horses')->where('date', $r->date)
                       ->where('kaisuu', $r->kaisuu)->where('basho', $r->basho)
                       ->where('day', $r->day)->where('race', (int) $r->race)
                       ->whereIn('num', [1, 2])->count();
                if ($n === 2) { $race = $r; break; }
            }
        }
        if ($race === null) { $this->error('  × 写し元のレースがありません'); return; }

        $pass = 0; $fail = 0; $fails = [];
        $check = function (string $name, callable $t) use (&$pass, &$fail, &$fails) {
            try { $t(); $pass++; }
            catch (\Throwable $e) {
                $fail++; $fails[] = $name . '：' . $e->getMessage();
                $this->line('  × ' . $name . '：' . mb_strimwidth($e->getMessage(), 0, 160, '…'));
            }
        };
        $must = static function (bool $ok, string $m): void { if (!$ok) { throw new \RuntimeException($m); } };

        \DB::beginTransaction();
        try {
            $date = substr((string) $race->date, 0, 10);
            $kaisuu = (string) $race->kaisuu; $basho = '96'; $day = (string) $race->day; $rno = 1;
            $ra = (array) $race; unset($ra['id']);
            $ra['basho'] = $basho; $ra['race'] = $rno; $ra['start_time'] = '12:00:00';
            if (array_key_exists('num_horses', $ra)) { $ra['num_horses'] = 2; }
            \DB::table('t_horse_odds_finder_races')->where('date', $date)->where('kaisuu', $kaisuu)
              ->where('basho', $basho)->where('day', $day)->where('race', $rno)->delete();
            \DB::table('t_horse_odds_finder_races')->insert($ra);
            \DB::table('t_horse_odds_finder_horses')->where('date', $date)->where('kaisuu', $kaisuu)
              ->where('basho', $basho)->where('day', $day)->where('race', $rno)->delete();
            foreach (\DB::table('t_horse_odds_finder_horses')->where('date', $race->date)
                        ->where('kaisuu', $race->kaisuu)->where('basho', $race->basho)
                        ->where('day', $race->day)->where('race', (int) $race->race)
                        ->whereIn('num', [1, 2])->get() as $h) {
                $ha = (array) $h; unset($ha['id']);
                $ha['date'] = $race->date; $ha['basho'] = $basho; $ha['race'] = $rno;
                \DB::table('t_horse_odds_finder_horses')->insert($ha);
            }
            \DB::table('t_horse_odds_finder_odds')->where('date', $date)->where('kaisuu', $kaisuu)
              ->where('basho', $basho)->where('day', $day)->where('race', $rno)->delete();
            $raceId = $A::buildPredictionRaceId($date, $kaisuu, $basho, $day, $rno);
            $anchor = $T::us($date . 'T12:00:00+09:00');
            $this->line('  写し元   : ' . $A::buildPredictionRaceId(substr((string) $race->date, 0, 10),
                            $race->kaisuu, $race->basho, $race->day, (int) $race->race)
                      . ' → ' . $raceId . '（出馬表 1番・2番）');
            $this->line('');

            $toDb = static function (int $us): string {
                return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                    ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                    ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
            };
            $enc = static fn($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rows10 = static fn() => [['num' => 1, 'tan' => '2.1', 'fuku_min' => '1.1', 'fuku_max' => '1.2'],
                                      ['num' => 2, 'tan' => '3.2', 'fuku_min' => '1.2', 'fuku_max' => '1.4']];
            $orig1 = ['tan' => '2.1', 'fuku_min' => '1.1', 'fuku_max' => '1.2'];
            // 1番の1項目だけ、JSON の生のトークンに差し替えた原文を作ります
            $tok = static function (array $rows, string $field, string $token) use ($enc, $orig1): string {
                $raw = $enc($rows);
                $needle = '"' . $field . '":"' . $orig1[$field] . '"';
                $pos = strpos($raw, $needle);
                if ($pos === false) { throw new \RuntimeException('試験の原文を作れません'); }
                return substr_replace($raw, '"' . $field . '":' . $token, $pos, strlen($needle));
            };
            $batch = static function (string $raw, string $id = 'a-first', int $slot = 6)
                     use ($T, $anchor, $raceId, $toDb): array {
                $at = $T::slotScheduled($anchor, $slot);
                return ['race_id' => $raceId, 'slot' => $slot, 'batch_id' => $raceId . '-' . $id . '-' . $slot,
                        'slot_scheduled_at' => $toDb($at), 'source_updated_at' => $toDb($at),
                        'request_started_at' => $toDb($at), 'response_received_at' => $toDb($at + 1000000),
                        'available_at' => $toDb($at + 2000000), 'raw_payload' => $raw,
                        'payload_hash' => hash('sha256', $raw), 'validation_result' => 'ADOPTED',
                        'rejection_reason' => null, 'committed' => 1, 'created_at' => $toDb($at + 2000000)];
            };
            $gather = function (array $batches) use ($A, $raceId, $date, $kaisuu, $basho, $day, $rno): array {
                \DB::table('baganriki_acquisition_batches')->where('race_id', $raceId)->delete();
                if ($batches !== []) { \DB::table('baganriki_acquisition_batches')->insert($batches); }
                return $A::gatherPredictionContext($date, $kaisuu, $basho, $day, $rno);
            };
            $bid = static fn(array $out, int $slot) => (string) ($out['ctx']['audit'][(string) $slot]['batch_id']
                                                    ?? ($out['ctx']['audit'][$slot]['batch_id'] ?? ''));
            $id = static fn(string $s, int $slot) => $raceId . '-' . $s . '-' . $slot;
            $backup = function (string $raw, int $slot = 6) use ($gather, $batch, $enc, $rows10, $bid, $must, $id): array {
                $bs = [$batch($raw, 'a-first', $slot), $batch($enc($rows10()), 'z-backup', $slot)];
                if ($slot !== 6) { $bs[] = $batch($enc($rows10()), 'six', 6); }
                $out = $gather($bs);
                $must((bool) $out['ok'], '不正なバッチで入力全体が止まった（' . (string) ($out['code'] ?? '') . '）');
                $must($bid($out, $slot) === $id('z-backup', $slot), '不正なバッチを採った、または正常な控えを失った（' . $bid($out, $slot) . '）');
                $must((array) $out['ctx']['cancelled'] === [], '不採用バッチの取消が漏れた');
                return $out;
            };
            $value = static function (array $ctx, string $field, int $slot = 6) use ($P): string {
                $e = $ctx['registry']['h1_' . $field . '_' . $slot] ?? null;
                return $e === null ? '(なし)' : $P::numToDecimalString($e['value'] ?? null);
            };
            $regOf = static function ($reg, string $ref) {
                if (is_array($reg) && isset($reg[$ref])) { return $reg[$ref]; }
                foreach ((array) $reg as $e) { if (is_array($e) && ($e['source_ref'] ?? null) === $ref) { return $e; } }
                return null;
            };

            // ① 長い数値が出典一覧と送信本文で丸まらない（21）
            foreach ([
                'above two'              => ['2.0000000000000000000000000001', '2.0000000000000000000000000001'],
                'below two'              => ['1.9999999999999999999999999999', '1.9999999999999999999999999999'],
                'large integer'          => ['9223372036854775809', '9223372036854775809'],
                'small exponent'         => ['1e-25', '0.0000000000000000000000001'],
                'fraction exponent'      => ['1.234567890123456789012345678e1', '12.34567890123456789012345678'],
                '128 integer digits'     => [str_repeat('9', 128), str_repeat('9', 128)],
                '128 significant digits' => ['1.' . str_repeat('0', 126) . '1', '1.' . str_repeat('0', 126) . '1'],
            ] as $label => [$token, $expected]) {
                foreach (['tan' => 'win_odds', 'fuku_min' => 'place_min', 'fuku_max' => 'place_max'] as $source => $field) {
                    $check('raw numeric value survives registry and wire: ' . $label . ' ' . $source,
                    function () use ($tok, $rows10, $source, $field, $token, $expected, $gather, $batch, $value, $must, $P, $J, $regOf) {
                        $out = $gather([$batch($tok($rows10(), $source, $token))]);
                        $must((bool) $out['ok'], '正確な数値のバッチを不採用にした（' . (string) ($out['code'] ?? '') . '）');
                        $ctx = $out['ctx'];
                        $must($value($ctx, $field) === $expected, '出典一覧の前で丸まった・失われた（' . $value($ctx, $field) . '）');
                        $built = $P::buildInput($ctx, '1st'); $must((bool) $built['ok'], '入力を組み立てられない');
                        $wire = $P::encode($built['input']); $must((bool) $wire['ok'], '送信本文にできない');
                        $rt = $J::strict((string) $wire['json']); $must((bool) $rt['ok'], '送信本文を読み戻せない');
                        $e = $regOf($rt['value']['source_registry'] ?? [], 'h1_' . $field . '_6');
                        $must($e !== null && $P::numToDecimalString($e['value'] ?? null) === $expected, '送信本文で値が変わった');
                        if ($source !== 'fuku_max') {
                            $hs = $P::horsesForSignals($ctx['registry'], [1 => 1, 2 => 2]);
                            $key = ($source === 'tan') ? 'tan_series' : 'fuku_min_series';
                            $must(($hs[0][$key][6] ?? null) === $expected, '信号の入力で丸まった');
                        }
                    });
                }
            }
            // ② 人気順位が正確（2）
            foreach (['fraction' => ['2.0000000000000000000000000001', '2.0000000000000000000000000000'],
                      'large integers' => ['9223372036854775810', '9223372036854775809']] as $label => [$hi, $lo]) {
                $check('raw numeric popularity is exact: ' . $label, function () use ($hi, $lo, $enc, $rows10, $gather, $batch, $must, $P) {
                    $raw = $enc($rows10());
                    $raw = preg_replace('/"tan":"2\.1"/', '"tan":' . $hi, $raw, 1);
                    $raw = preg_replace('/"tan":"3\.2"/', '"tan":' . $lo, $raw, 1);
                    $out = $gather([$batch($raw)]); $must((bool) $out['ok'], '読めない');
                    $b = $P::buildInput($out['ctx'], '1st'); $must((bool) $b['ok'], '組み立てられない');
                    $ranks = array_column($b['input']['runners'], 'win_rank', 'horse_no');
                    $must(($ranks[2] ?? null) === 1 && ($ranks[1] ?? null) === 2, '丸めで別のオッズが同着扱いになった');
                });
            }
            // ③ 同じ値の別表記は馬番で並べる（1）
            $check('equal values with different numeric spellings keep horse-number tie break',
            function () use ($enc, $rows10, $gather, $batch, $must, $P) {
                $raw = $enc($rows10());
                $raw = preg_replace('/"tan":"2\.1"/', '"tan":2.00', $raw, 1);
                $raw = preg_replace('/"tan":"3\.2"/', '"tan":20e-1', $raw, 1);
                $out = $gather([$batch($raw)]); $b = $P::buildInput($out['ctx'] ?? [], '1st');
                $must((bool) $out['ok'] && (bool) $b['ok']
                      && array_column($b['input']['runners'], 'win_rank', 'horse_no') === [1 => 1, 2 => 2], '同じ値が同着にならない');
            });
            // ④ 重複キーはそのバッチだけ不採用（12）
            foreach ([
                'cancel overwritten'     => '[{"num":1,"tan":"取消","tan":"2.1"},{"num":2,"tan":"3.2"}]',
                'escaped key alias'      => '[{"num":1,"tan":"取消","t\\u0061n":"2.1"},{"num":2,"tan":"3.2"}]',
                'identical duplicate'    => '[{"num":1,"tan":"2.1","tan":"2.1"},{"num":2,"tan":"3.2"}]',
                'horse overwritten'      => '[{"num":99,"num":1,"tan":"2.1"},{"num":2,"tan":"3.2"}]',
                'root key overwritten'   => '{"row":{"num":1,"tan":"取消"},"row":{"num":1,"tan":"2.1"},"second":{"num":2,"tan":"3.2"}}',
                'nested key overwritten' => '[{"num":1,"tan":"2.1","meta":{"x":0,"x":1}},{"num":2,"tan":"3.2"}]',
            ] as $label => $raw) {
                foreach ([6, 21] as $slot) {
                    $check('duplicate JSON keys reject only their batch: ' . $label . ' slot ' . $slot,
                           function () use ($backup, $raw, $slot) { $backup($raw, $slot); });
                }
            }
            // ⑤ 不正な馬番は読み替えない（26）
            foreach (['"1x"', '1.9', '1.0', '1e0', 'true', 'false', '[]', '{}', 'null', '0', '-1',
                      '"9223372036854775808"', '9223372036854775808'] as $token) {
                foreach ([6, 21] as $slot) {
                    $check('invalid raw horse number is never coerced: ' . $token . ' slot ' . $slot,
                    function () use ($backup, $token, $slot) {
                        $backup('[{"num":' . $token . ',"tan":"2.1"},{"num":2,"tan":"3.2"}]', $slot);
                    });
                }
            }
            // ⑥ 余分な不正行を黙って消さない（8）
            foreach (['null', 'true', '123', '"row"', '[]', '{}', '{"tan":"4.5"}', '{"num":0,"tan":"4.5"}'] as $row) {
                $check('malformed extra row cannot silently disappear: ' . $row, function () use ($backup, $enc, $rows10, $row) {
                    $backup(substr($enc($rows10()), 0, -1) . ',' . $row . ']');
                });
            }
            // ⑦ 正しい馬番の書き方は受け付ける（4）
            foreach (['1', '"1"', '"01"', '" 1 "'] as $token) {
                $check('positive raw horse identity remains supported: ' . $token, function () use ($token, $gather, $batch, $value, $must) {
                    $out = $gather([$batch('[{"num":' . $token . ',"tan":"2.1"},{"num":2,"tan":"3.2"}]')]);
                    $must((bool) $out['ok'] && $value($out['ctx'], 'win_odds') === '2.1', '正しい馬番を拒否した');
                });
            }
            // ⑧ これまでの行の入れ物の形は受け付ける（3）
            foreach (['list', 'named object', 'numeric object'] as $shape) {
                $check('legacy row collection shape remains supported: ' . $shape,
                function () use ($shape, $rows10, $enc, $gather, $batch, $bid, $must, $id) {
                    $rows = $rows10();
                    $raw = ($shape === 'list') ? $enc($rows)
                         : (($shape === 'named object') ? $enc(['one' => $rows[0], 'two' => $rows[1]])
                                                        : $enc((object) [0 => $rows[0], 1 => $rows[1]]));
                    $out = $gather([$batch($raw)]);
                    $must((bool) $out['ok'] && $bid($out, 6) === $id('a-first', 6), '正しい入れ物を拒否した');
                });
            }
            // ⑨ 不正な単勝は6分前の全頭確認を満たさない（11）
            foreach (['true', 'false', '[]', '{}', '[2.1]', '{"value":2.1}', 'null', '0', '-2.1', '"NaN"', '"Infinity"'] as $token) {
                $check('invalid raw win odds do not satisfy six-minute completeness: ' . $token,
                function () use ($backup, $token) { $backup('[{"num":1,"tan":' . $token . '},{"num":2,"tan":"3.2"}]'); });
            }
            // ⑩ 途中のスロットの部分欠損は保持（9）
            foreach (['tan' => 'win_odds', 'fuku_min' => 'place_min', 'fuku_max' => 'place_max'] as $source => $field) {
                foreach (['true', '[]', '{}'] as $token) {
                    $check('partial earlier slot preserves missing odds: ' . $source . ' ' . $token,
                    function () use ($source, $field, $token, $tok, $rows10, $enc, $gather, $batch, $bid, $must, $P, $id) {
                        $out = $gather([$batch($tok($rows10(), $source, $token), 'partial', 21),
                                        $batch($enc($rows10()), 'six', 6)]);
                        $must((bool) $out['ok'] && $bid($out, 21) === $id('partial', 21), '途中のバッチを不要に不採用にした');
                        $must(!isset($out['ctx']['registry']['h1_' . $field . '_21']), '不正な項目が数値の出典になった');
                        $must(isset($out['ctx']['registry']['h2_win_odds_21']), '正常な他の馬を失った');
                        $must((bool) $P::buildInput($out['ctx'], '1st')['ok'], '6分前の正常なバッチで入力が作れない');
                    });
                }
            }
            // ⑪ 6分前の複勝が欠けても単勝は消さない（2）
            foreach (['fuku_min' => 'place_min', 'fuku_max' => 'place_max'] as $source => $field) {
                $check('missing six-minute place odds do not erase valid wins: ' . $source,
                function () use ($source, $field, $tok, $rows10, $gather, $batch, $bid, $must, $P, $id) {
                    $out = $gather([$batch($tok($rows10(), $source, '{"value":"1.2"}'))]);
                    $must((bool) $out['ok'] && $bid($out, 6) === $id('a-first', 6), '任意の複勝のために単勝のそろったバッチを拒否した');
                    $must(!isset($out['ctx']['registry']['h1_' . $field . '_6']), 'object が複勝の数値になった');
                    $must((bool) $P::buildInput($out['ctx'], '1st')['ok'], '正常な単勝の入力を失った');
                });
            }
            // ⑫ 正しい取消は採用したバッチの中だけで効く（2）
            foreach (['取消', '除外'] as $marker) {
                $check('valid cancellation remains local to adopted batch: ' . $marker,
                function () use ($marker, $rows10, $enc, $gather, $batch, $must) {
                    $rows = $rows10(); $rows[1]['tan'] = ' ' . $marker . ' '; $rows[1]['fuku_min'] = null; $rows[1]['fuku_max'] = null;
                    $out = $gather([$batch($enc($rows))]);
                    $must((bool) $out['ok'] && (array) $out['ctx']['cancelled'] === [2], '正しい取消を失った');
                    $must(!isset($out['ctx']['registry']['h2_win_odds_6']), '取消の馬にオッズが付いた');
                });
            }
            // ⑬ 6分前の候補がすべて不正なら、前のデータがあっても入力を止める（1）
            $check('all malformed six-minute candidates stop input even with earlier data',
            function () use ($enc, $rows10, $gather, $batch, $must, $P) {
                $bad = '[{"num":1,"tan":"2.1","tan":"9.9"},{"num":2,"tan":"3.2"}]';
                $out = $gather([$batch($bad), $batch($enc($rows10()), 'earlier', 21)]);
                if ($out['ok']) { $must(!(bool) $P::buildInput($out['ctx'], '1st')['ok'], '不正な凍結バッチで送れる入力ができた'); }
            });
            // ⑭ 厳密な読み取りで、取得の関門が弱まっていない（3）
            foreach (['hash mismatch', 'uncommitted', 'late'] as $kind) {
                $check('raw parser does not weaken acquisition gates: ' . $kind,
                function () use ($kind, $rows10, $enc, $gather, $batch, $bid, $must, $id, $date) {
                    $rows = $rows10(); $rows[1]['tan'] = '取消'; $bad = $batch($enc($rows));
                    if ($kind === 'hash mismatch') { $bad['payload_hash'] = str_repeat('0', 64); }
                    if ($kind === 'uncommitted')   { $bad['committed'] = 0; }
                    if ($kind === 'late')          { $bad['available_at'] = $date . ' 12:00:00.000000'; }
                    $out = $gather([$bad, $batch($enc($rows10()), 'z-backup')]);
                    $must((bool) $out['ok'] && $bid($out, 6) === $id('z-backup', 6) && (array) $out['ctx']['cancelled'] === [],
                          '関門か取消の隔離が後退した');
                });
            }
            // ⑮ これまでの集合検査が効いている（4）
            foreach (['unknown horse' => ['num' => 3, 'tan' => '4.5'], 'duplicate horse' => ['num' => 1, 'tan' => '4.5'],
                      'duplicate padded horse' => ['num' => '01', 'tan' => '4.5'], 'cancel conflict' => ['num' => 1, 'tan' => '取消']] as $label => $extra) {
                $check('existing raw set integrity remains enforced: ' . $label, function () use ($extra, $rows10, $enc, $backup) {
                    $rows = $rows10(); $rows[] = $extra; $backup($enc($rows));
                });
            }
            // ⑯ 空・壊れた根は6分前の入力にならない（10）
            foreach (['[]', '{}', 'null', 'true', '42', '"text"', '[', '[NaN]', '[Infinity]', '[] trailing'] as $raw) {
                $check('empty or malformed root cannot qualify six-minute input: ' . $raw, function () use ($backup, $raw) { $backup($raw); });
            }
            // ⑰ 4 MiB の境界（3）
            foreach ([-1, 0, 1] as $delta) {
                $check('raw payload byte boundary: ' . $delta, function () use ($delta, $rows10, $enc, $gather, $batch, $bid, $must, $id) {
                    $rows = $rows10(); $rows[0]['note'] = ''; $base = $enc($rows);
                    $rows[0]['note'] = str_repeat('x', 4194304 - strlen($base) + $delta); $raw = $enc($rows);
                    $must(strlen($raw) === 4194304 + $delta, '試験の原文の大きさが違う');
                    $out = $gather([$batch($raw), $batch($enc($rows10()), 'z-backup')]);
                    $must((bool) $out['ok'] && $bid($out, 6) === $id($delta <= 0 ? 'a-first' : 'z-backup', 6), '大きさの境界が違う');
                });
            }
            // ⑱ 深さの境界（2）
            foreach ([62, 63] as $nested) {
                $check('raw payload depth boundary: ' . ($nested + 2), function () use ($nested, $rows10, $enc, $gather, $batch, $bid, $must, $id) {
                    $raw = '[{"num":1,"tan":"2.1","meta":' . str_repeat('[', $nested) . '0' . str_repeat(']', $nested) . '},{"num":2,"tan":"3.2"}]';
                    $out = $gather([$batch($raw), $batch($enc($rows10()), 'z-backup')]);
                    $must((bool) $out['ok'] && $bid($out, 6) === $id($nested === 62 ? 'a-first' : 'z-backup', 6), '深さの境界が違う');
                });
            }
            // ⑲ 不正なトークンはバッチを外す（4）
            foreach (['129 digits' => str_repeat('9', 129), 'positive exponent' => '1e309',
                      'negative exponent' => '1e-309', 'invalid UTF-8' => "\"\xff\""] as $label => $token) {
                $check('invalid raw token excludes batch: ' . $label,
                function () use ($backup, $token, $label, $batch, $gather, $enc, $rows10, $bid, $must, $id, $raceId) {
                    $raw = '[{"num":1,"tan":"2.1","meta":' . $token . '},{"num":2,"tan":"3.2"}]';
                    if ($label !== 'invalid UTF-8') { $backup($raw); return; }
                    // 不正なUTF-8は、MySQL（utf8mb4）がその行を保存しません。オッズ取得の記録（ImportKeibaOdds）でも
                    // 同じく保存できないので、そのバッチは存在しないまま、正常な控えが採用されることを確かめます。
                    $stored = true;
                    try {
                        \DB::transaction(function () use ($batch, $raw) {
                            \DB::table('baganriki_acquisition_batches')->insert($batch($raw));
                        });
                    } catch (\Throwable $e) { $stored = false; }
                    $must(!$stored, '不正なUTF-8の原文が保存できてしまった');
                    $out = $gather([$batch($enc($rows10()), 'z-backup')]);
                    $must((bool) $out['ok'] && $bid($out, 6) === $id('z-backup', 6), '正常な控えを採用しない');
                });
            }
            // ⑳ 数値の判定は扱えない PHP の値を安全に拒む（7）
            foreach (['null' => null, 'true' => true, 'array' => [], 'object' => new \stdClass(), 'nonfinite' => INF,
                      'nan' => NAN, 'stringable' => new class { public function __toString(): string { return '2.1'; } }] as $label => $v) {
                $check('numeric predicate safely rejects unsupported PHP value: ' . $label,
                       function () use ($AQ, $v, $must) { $must(!$AQ::usableDecimal($v), '扱えない値を受け付けた'); });
            }
            // ㉑ 数値の判定は扱える値を受け付ける（4）
            //   011 の Decimal オブジェクトは採っていないので、同じ値の十進文字列で確かめます。
            foreach (['integer' => 2, 'float' => 2.5, 'string' => ' 2.10 ', 'decimal' => '2.00000000000000000001'] as $label => $v) {
                $check('numeric predicate keeps supported PHP values: ' . $label,
                       function () use ($AQ, $v, $must) { $must($AQ::usableDecimal($v), '扱える値を拒否した'); });
            }
        } finally {
            \DB::rollBack();
        }

        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('  後片付け : ロールバックしました（写したレースと監査行は残っていません）');
    }

    private function runPublishTest(string $root): void
    {
        self::loadPredictionClasses();
        $vDir = rtrim($root, '/') . '/validation/normal';
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 統合・公開・intent・revision の通し試験');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  資料     : ' . $vDir);
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');
        $need = ['MIXED_N16_1st_input.json', 'MIXED_N16_1st_output.json',
                 'MIXED_N16_2nd_input.json', 'MIXED_N16_2nd_output.json'];
        foreach ($need as $f) {
            if (!is_file($vDir . '/' . $f)) {
                $this->error('  × ' . $vDir . '/' . $f . ' がありません');
                return;
            }
        }

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-9s %-50s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $rd = static fn(string $f) => json_decode(
            (string) file_get_contents($f), true);
        $j = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;

        $in1  = $rd($vDir . '/MIXED_N16_1st_input.json');
        $out1 = $rd($vDir . '/MIXED_N16_1st_output.json');
        $in2  = $rd($vDir . '/MIXED_N16_2nd_input.json');
        $out2 = $rd($vDir . '/MIXED_N16_2nd_output.json');

        $seq = 0;
        $mk = function (array $o1, array $o2, array $opt = [])
              use ($in1, $in2, $j, $BEGIN, $END, &$seq): int {
            $seq++;
            $now  = \App\Http\Controllers\Api\BaganrikiWorker::nowUs();
            $sales = $now + 3600 * 1000000;
            $nums = [];
            foreach ((array) $in1['runners'] as $r) { $nums[] = (int) $r['horse_no']; }
            $cancel = array_map('intval', (array) ($opt['cancelled'] ?? []));
            $sid = \DB::table('baganriki_snapshots')->insertGetId([
                // 20261025：レース正本と競合させる試験（④-3）のために race_id を渡せるようにしました
                'race_id' => (string) ($opt['race_id'] ?? ('PT-' . $seq)), 'generation' => 900, 'run_id' => 'PT',
                'snapshot_hash'   => (string) $in1['snapshot_hash'],
                'input_json_1st'  => $j($in1), 'input_json_2nd' => $j($in2),
                'frozen_nums'     => $j($nums),
                'allocation_mode' => (string) $in1['allocation_mode'],
                'mode'            => (string) $in1['mode'],
                'mode_status'     => (string) $in1['mode_status'],
                'target_count'    => (int) $in1['target_count'],
                'quota_1st'       => $j($in1['quota']),
                'sales_close_at'  => \App\Http\Controllers\Api\BaganrikiWorker::toDb($sales),
                'created_at'      => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
            ]);
            \DB::table('baganriki_live_states')->insert([
                'snapshot_id' => $sid, 'cancellations' => $j($cancel),
                'active_count' => count($nums) - count($cancel),
                'live_target_count' => (int) $in1['target_count'],
                'live_quota' => $j($in1['quota']), 'live_close' => null,
                'state_revision' => 0, 'response_revision' => 0,
                'publication_revision' => 0, 'latest_publication_id' => null,
                'updated_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
            ]);
            foreach ([['1st', $o1], ['2nd', $o2]] as $k => $pair) {
                [$role, $doc] = $pair;
                if ($doc === []) { continue; }
                $raw = $BEGIN . $j($doc) . $END;
                \DB::table('baganriki_ai_attempts')->insert([
                    'snapshot_id' => $sid, 'role' => $role, 'attempt_no' => 0,
                    'token' => hash('sha256', 'PT' . $sid . $role),
                    'status' => \App\Http\Controllers\Api\BaganrikiWorker::RECEIVED,
                    'reserved_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
                    'received_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now),
                    'complete' => (($opt['incomplete'] ?? null) === $role) ? 0 : 1,
                    'raw_response' => $raw,
                    'response_hash' => hash('sha256', $j($doc)),
                ]);
                // @ANCHOR-20261021-PREDICTION-BANDSCOPE  訂正の枠も作れるようにします
                $fx = $opt['fix_' . $role] ?? null;
                if (is_array($fx) && $fx !== []) {
                    $rawF = $BEGIN . $j($fx) . $END;
                    \DB::table('baganriki_ai_attempts')->insert([
                        'snapshot_id' => $sid, 'role' => $role, 'attempt_no' => 1,
                        'token' => hash('sha256', 'PTF' . $sid . $role),
                        'status' => \App\Http\Controllers\Api\BaganrikiWorker::RECEIVED,
                        'reserved_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now + 1000),
                        'received_at' => \App\Http\Controllers\Api\BaganrikiWorker::toDb($now + 1000),
                        'complete' => 1,
                        'raw_response' => $rawF,
                        'response_hash' => hash('sha256', $j($fx)),
                    ]);
                }
            }
            return (int) $sid;
        };

        $ok = true;
        $pubRaceKeys = [];   // 20261025：④-3 で作るレース正本の行（後片付け用）
        \DB::beginTransaction();
        try {
            $ctrl = app(\App\Http\Controllers\Api\AiController::class);

            // ── ① ふつうに公開する ──────────────────────────────────
            $this->info('── ① 統合して公開する ──');
            $sid = $mk($out1, $out2);
            $p = $ctrl->publishPrediction($sid);
            $check('P-01', '公開できる', $p['code'], 'PUBLISHED');
            $q = (array) $p['quota'];
            $check('P-02', '枠ぶんの候補が出る', count($p['candidates']),
                   (int) array_sum($q));
            $nos = array_column($p['candidates'], 'horse_no');
            $check('P-03', '馬番が重複しない', count(array_unique($nos)), count($nos));
            $this->line('      枠 ' . $j($q) . '  候補 ' . implode('-', $nos));
            $check('P-04', 'publication_revision が1増える',
                   (int) \DB::table('baganriki_live_states')->where('snapshot_id', $sid)
                       ->value('publication_revision'), 1);
            $check('P-05', 'latest_publication_id が入る',
                   (string) \DB::table('baganriki_live_states')->where('snapshot_id', $sid)
                       ->value('latest_publication_id'), (string) $p['publication_id']);

            // ── ② 加点（両AIの原公開一致を証明できたときだけ）──────────
            $this->info('── ② 加点は証明できたときだけ ──');
            $both = array_values(array_intersect(
                array_map('intval', (array) $out1['public_candidates']),
                array_map('intval', (array) $out2['public_candidates'])));
            $bonused = [];
            foreach ($p['candidates'] as $c) {
                if ($c['bonus'] === 5.0) { $bonused[] = (int) $c['horse_no']; }
            }
            sort($both); sort($bonused);
            $check('P-06', '両AIの原公開一致した馬に +5',
                   $bonused, array_values(array_intersect($both, $nos)));
            $check('P-07', '証明がそろっている', $p['proof'], ['1st', '2nd']);
            $pub = \DB::table('baganriki_publications')
                     ->where('publication_id', $p['publication_id'])->first();
            $pf = json_decode((string) ($pub->public_proof_hash ?? '[]'), true);
            $check('P-08', '加点を証明する応答hashを別に残す',
                   is_array($pf) ? count($pf) : 0, count($bonused));

            // ── ③ 同じ intent の再実行 ──────────────────────────────
            $this->info('── ③ 同じ intent の再実行 ──');
            $p2 = $ctrl->publishPrediction($sid);
            $check('P-09', '同じ結果を返す', $p2['code'], 'ALREADY');
            $check('P-10', '同じ publication_id', (string) $p2['publication_id'],
                   (string) $p['publication_id']);
            $check('P-11', '公開は1件のまま',
                   (int) \DB::table('baganriki_publications')
                       ->where('snapshot_id', $sid)->count(), 1);

            // ── ④ 読取のあとに版が変わったら保存しない ─────────────────
            $this->info('── ④ 版が変わったら保存しない（STALE）──');
            $sid3 = $mk($out1, $out2);
            // ほかの処理がライブ状態を進めた場面を作ります
            \DB::table('baganriki_live_states')->where('snapshot_id', $sid3)
              ->update(['state_revision' => 1]);
            // 進む前に読んだ版のまま保存を試みます
            $stale = $ctrl->publishPrediction($sid3,
                ['read_revisions' => ['state' => 0, 'response' => 0,
                                      'publication' => 0]]);
            $check('P-12', '古い版の計算は保存しない', $stale['code'], 'STALE');
            $check('P-13', '公開は作られない',
                   (int) \DB::table('baganriki_publications')
                       ->where('snapshot_id', $sid3)->count(), 0);
            // 版を合わせれば保存できます
            $fresh = $ctrl->publishPrediction($sid3,
                ['read_revisions' => ['state' => 1, 'response' => 0,
                                      'publication' => 0]]);
            $check('P-14', '版を合わせれば公開できる', $fresh['code'], 'PUBLISHED');

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-NARU-RACE  公開 commit 直前の取消・締切・版の競合（なる 10/23 5章D）
            // ══════════════════════════════════════════════════════════
            //   計算が終わって保存のトランザクションに入る直前に、本物の applyLiveChange で
            //   取消・締切の前倒しを入れます（版を手で書き換えるのではありません）。
            //   期待結果は先に固定しています。
            $this->info('── ④-2 公開 commit 直前の競合（本物の取消・締切の変更を割り込ませる）──');
            $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
            $xNo = (int) $nos[0];            // ①で候補に入った馬
            $yNo = (int) $nos[1];
            $sidC = $mk($out1, $out2);
            $cutX = function (int $s) use ($ctrl, $xNo) {
                $ctrl->applyLiveChange($s, ['cancellations' => [$xNo]]);
            };
            $c1 = $ctrl->publishPrediction($sidC, ['test_before_commit' => $cutX]);
            $check('C-01', 'commit 直前に取消が入ったら、古い計算は保存しない', $c1['code'], 'STALE');
            $check('C-02', '公開は作られない',
                   (int) \DB::table('baganriki_publications')->where('snapshot_id', $sidC)->count(), 0);
            $c2 = $ctrl->publishPrediction($sidC);
            $nosC2 = array_map('intval', array_column((array) $c2['candidates'], 'horse_no'));
            $check('C-03', '新しい版で計算し直すと公開でき、取消の馬は候補に入らない',
                   [$c2['code'], in_array($xNo, $nosC2, true)], ['PUBLISHED', false]);
            $pubC2 = \DB::table('baganriki_publications')->where('snapshot_id', $sidC)->first();

            // 公開の後に取消が入った場合：出した公開は書き換えず、期限内なら新しい公開を足します
            $ctrl->applyLiveChange($sidC, ['cancellations' => [$yNo]]);
            $pubC2b = \DB::table('baganriki_publications')
                        ->where('publication_id', (string) $c2['publication_id'])->first();
            $check('C-04', '公開の後の取消で、出した公開は書き換えない',
                   $j((array) $pubC2b), $j((array) $pubC2));
            $c3 = $ctrl->publishPrediction($sidC);
            $nosC3 = array_map('intval', array_column((array) $c3['candidates'], 'horse_no'));
            $check('C-05', '期限内なら新しい版で公開を足し、取消の馬は外れる',
                   [$c3['code'], in_array($yNo, $nosC3, true),
                    (string) $c3['publication_id'] !== (string) $c2['publication_id']],
                   ['PUBLISHED', false, true]);
            $check('C-06', '最新の公開IDが新しいものへ進む',
                   (string) \DB::table('baganriki_live_states')->where('snapshot_id', $sidC)
                       ->value('latest_publication_id'), (string) $c3['publication_id']);

            // commit 直前に締切が前倒しされ、公開期限を過ぎた場合
            $sidD = $mk($out1, $out2);
            $closeSoon = function (int $s) use ($ctrl, $W) {
                // 実効の公開期限 = 締切 − 60秒 なので、10秒後の締切なら期限はもう過ぎています
                $ctrl->applyLiveChange($s, ['live_close_us' => $W::nowUs() + 10 * 1000000]);
            };
            $d1 = $ctrl->publishPrediction($sidD, ['test_before_commit' => $closeSoon]);
            $check('C-07', 'commit 直前に締切が前倒しされたら保存しない', $d1['code'], 'STALE');
            $d2 = $ctrl->publishPrediction($sidD);
            $check('C-08', '計算し直しても、前倒し後の公開期限を過ぎていれば公開しない',
                   $d2['code'], 'DEADLINE_PASSED');
            $check('C-09', '公開は作られない',
                   (int) \DB::table('baganriki_publications')->where('snapshot_id', $sidD)->count(), 0);

            // 同じ取消をもう一度入れても版は進まないので、計算は捨てない
            $sidE = $mk($out1, $out2, ['cancelled' => [$xNo]]);
            \DB::table('baganriki_live_states')->where('snapshot_id', $sidE)
              ->update(['cancellations' => $j([$xNo])]);
            $e1 = $ctrl->publishPrediction($sidE, ['test_before_commit' => $cutX]);
            $check('C-10', '変わりのない取消の再入力では版が進まず、そのまま公開できる',
                   $e1['code'], 'PUBLISHED');

            // commit 直前に応答の版が進んだ場合（訂正の受領・採否の確定が割り込んだ場面）
            $sidF = $mk($out1, $out2);
            $bumpResp = function (int $s) {
                \DB::table('baganriki_live_states')->where('snapshot_id', $s)
                  ->increment('response_revision');
            };
            $f1 = $ctrl->publishPrediction($sidF, ['test_before_commit' => $bumpResp]);
            $check('C-11', 'commit 直前に応答の版が進んだら保存しない', $f1['code'], 'STALE');
            $check('C-12', '監査に取消・締切の変更が残る（C系で変わった回数ぶん）',
                   (int) \DB::table('baganriki_audit_events')
                       ->whereIn('snapshot_id', [$sidC, $sidD])
                       ->where('kind', 'R122_LIVE_APPLIED')->count(), 3);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261025-NARU-PUBRACE  公開の実経路と、別の接続による発走時刻の更新の競合（なる 10/23 5通目 8章）
            // ══════════════════════════════════════════════════════════
            //   レース正本の行（試験用の場95）は、別の接続 B で作って確定させます（A のトランザクションの外）。
            //   期待結果は先に固定しています。
            //     G：更新が先 … 保存のトランザクションに入る直前に B が発走時刻を早めて確定
            //                   → A の最終確認（FOR UPDATE で最新を読む）は新しい期限で拒否（DEADLINE_PASSED）
            //     H：公開判定が先 … A が正本をロックして公開してよいと判定したあとで B が書き換えようとする
            //                   → B は A の確定まで待たされる（2秒でロック待ち切れ）。A は公開する
            $this->info('── ④-3 公開の実経路と発走時刻の更新の競合（2つの接続）──');
            $Wk = \App\Http\Controllers\Api\BaganrikiWorker::class;
            $Ak = \App\Http\Controllers\Api\AiController::class;
            $defC = (string) config('database.default');
            config(['database.connections.baganriki_pub_b' => config('database.connections.' . $defC)]);
            $PB = \DB::connection('baganriki_pub_b');
            $PB->statement('SET SESSION innodb_lock_wait_timeout = 2');
            $srcR = null;
            foreach ($PB->table('t_horse_odds_finder_races')->where('basho', '<', '90')->orderByDesc('date')->limit(20)->get() as $rr) {
                if (preg_match('/^\d{1,2}:\d{2}/', (string) ($rr->start_time ?? ''))) { $srcR = $rr; break; }
            }
            if ($srcR === null) {
                $check('G-00', '写し元のレースがある', false, true);
            } else {
                $farS = intdiv($Wk::nowUs(), 1000000) + 3600 + 120;      // 凍結の締切（1時間後）より遅い＝前倒しではない
                $pubDate = date('Y-m-d', $farS);
                foreach ([10, 11] as $rno) {
                    $kG = ['date' => $pubDate, 'kaisuu' => $srcR->kaisuu, 'basho' => '95', 'day' => $srcR->day, 'race' => $rno];
                    $rowG = (array) $srcR; unset($rowG['id']);
                    $rowG = array_merge($rowG, $kG, ['start_time' => date('H:i:s', $farS)]);
                    $PB->table('t_horse_odds_finder_races')->where($kG)->delete();
                    $PB->table('t_horse_odds_finder_races')->insert($rowG);
                    $pubRaceKeys[] = $kG;
                }
                $ridG = $Ak::buildPredictionRaceId($pubDate, $srcR->kaisuu, '95', $srcR->day, 10);
                $ridH = $Ak::buildPredictionRaceId($pubDate, $srcR->kaisuu, '95', $srcR->day, 11);

                // G：更新が先
                $sidG = $mk($out1, $out2, ['race_id' => $ridG]);
                $gUpdAt = null;
                $earlyG = function (int $s) use ($PB, $pubRaceKeys, $Wk, &$gUpdAt) {
                    // 発走を90秒後へ（投票締切30秒後・公開期限はもう過ぎた）。B は自動コミットなので、ここで確定します
                    $PB->table('t_horse_odds_finder_races')->where($pubRaceKeys[0])
                       ->update(['start_time' => date('H:i:s', intdiv($Wk::nowUs(), 1000000) + 90)]);
                    $gUpdAt = \App\Http\Controllers\Api\BaganrikiTime::toJst($Wk::nowUs());
                };
                $g1 = $ctrl->publishPrediction($sidG, ['test_before_commit' => $earlyG]);
                $check('G-01', '更新が先：保存の直前に確定した新しい発走時刻で、最終確認が拒否する',
                       (string) $g1['code'], 'DEADLINE_PASSED');
                $check('G-02', '公開も intent も保存されない',
                       [(int) \DB::table('baganriki_publications')->where('snapshot_id', $sidG)->count(),
                        (int) \DB::table('baganriki_publication_intents')->where('snapshot_id', $sidG)->count()], [0, 0]);
                $this->line('      G の時刻順 : B 更新の確定 ' . ($gUpdAt ?? '—') . ' → A 公開の返却 ' . \App\Http\Controllers\Api\BaganrikiTime::toJst($Wk::nowUs())
                            . '（code=' . (string) $g1['code'] . '）');

                // H：公開判定が先
                $sidH = $mk($out1, $out2, ['race_id' => $ridH]);
                // 対照：公開の前は、B はこの行をすぐ書き換えられる（同じ値で書く。ほかのロックに当たっていないことの確認）
                $ctrlFree = true;
                try {
                    $PB->table('t_horse_odds_finder_races')->where($pubRaceKeys[1])->update(['start_time' => date('H:i:s', $farS)]);
                } catch (\Throwable $e) { $ctrlFree = false; }
                $check('G-03a', '対照：公開の前は、B は H の行をすぐ書き換えられる（ほかのロックに当たっていない）', $ctrlFree, true);
                $hBlocked = null; $hTryAt = null; $hEndAt = null;
                $tryH = function (int $s) use ($PB, $pubRaceKeys, $Wk, &$hBlocked, &$hTryAt, &$hEndAt) {
                    $hTryAt = \App\Http\Controllers\Api\BaganrikiTime::toJst($Wk::nowUs());
                    try {
                        $PB->table('t_horse_odds_finder_races')->where($pubRaceKeys[1])
                           ->update(['start_time' => date('H:i:s', intdiv($Wk::nowUs(), 1000000) + 90)]);
                        $hBlocked = false;
                    } catch (\Throwable $e) {
                        $hBlocked = (strpos($e->getMessage(), 'Lock wait timeout') !== false);
                    }
                    $hEndAt = \App\Http\Controllers\Api\BaganrikiTime::toJst($Wk::nowUs());
                };
                $h1 = $ctrl->publishPrediction($sidH, ['test_after_race_lock' => $tryH]);
                $check('G-03', '公開判定が先：判定のあとの書き換えは、A の確定まで待たされる（ロック待ちで止まる）', $hBlocked, true);
                $check('G-04', 'A は判定どおり公開する（公開と intent が1件ずつ保存される）',
                       [(string) $h1['code'], (int) \DB::table('baganriki_publications')->where('snapshot_id', $sidH)->count(),
                        (int) \DB::table('baganriki_publication_intents')->where('snapshot_id', $sidH)->count()], ['PUBLISHED', 1, 1]);
                $this->line('      H の時刻順 : A が正本をロックして判定 → B 更新の開始 ' . ($hTryAt ?? '—') . ' → B ロック待ち切れ ' . ($hEndAt ?? '—')
                            . ' → A 公開の返却 ' . \App\Http\Controllers\Api\BaganrikiTime::toJst($Wk::nowUs()) . '（code=' . (string) $h1['code'] . '）');
                $check('G-05', '待たされた書き換えは確定していない（正本は元の発走時刻のまま）',
                       (string) $PB->table('t_horse_odds_finder_races')->where($pubRaceKeys[1])->value('start_time'), date('H:i:s', $farS));
            }

            // ── ⑤ 片方が complete=false ─────────────────────────────
            $this->info('── ⑤ 片方が complete=false ──');
            $sid4 = $mk($out1, $out2, ['incomplete' => '2nd']);
            $p4 = $ctrl->publishPrediction($sid4);
            $check('P-15', '片方だけでも公開できる', $p4['code'], 'PUBLISHED');
            $check('P-16', '証明は1roleだけ', $p4['proof'], ['1st']);
            $b4 = 0;
            foreach ($p4['candidates'] as $c) { if ($c['bonus'] === 5.0) { $b4++; } }
            $check('P-17', '片方だけなら加点しない', $b4, 0);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-COMPLETE  未完了は選出に使わない
            // ══════════════════════════════════════════════════════════
            $this->info('── ⑤-2 未完了は候補にも順位点にも入れない ──');
            //   2nd だけで上位に来る馬を 2nd の帯の先頭へ置き、
            //   その 2nd を complete=false にします。
            //   結果が「1st だけのとき」と同じになることを見ます。
            $sidA = $mk($out1, [], []);                       // 1stだけ
            $pA   = $ctrl->publishPrediction($sidA);
            $out2b = $out2;
            foreach (array_keys((array) $out2b['band_ranks']) as $bk) {
                $arr = array_map('intval', (array) $out2b['band_ranks'][$bk]);
                if (count($arr) >= 2) {
                    $arr = array_merge([array_pop($arr)], $arr);   // 末尾を先頭へ
                    $out2b['band_ranks'][$bk] = $arr;
                }
            }
            $sidB = $mk($out1, $out2b, ['incomplete' => '2nd']);
            $pB   = $ctrl->publishPrediction($sidB);
            $check('P-25', '未完了側は公開を止めない', $pB['code'], 'PUBLISHED');
            $check('P-26', '候補が1st単独のときと同じ',
                   array_column((array) $pB['candidates'], 'horse_no'),
                   array_column((array) $pA['candidates'], 'horse_no'));
            $scA = array_column((array) $pA['candidates'], 'score', 'horse_no');
            $scB = array_column((array) $pB['candidates'], 'score', 'horse_no');
            ksort($scA); ksort($scB);
            $check('P-27', '順位点も1st単独のときと同じ', $scB, $scA);
            $check('P-28', '使った応答は1stだけ', $pB['proof'], ['1st']);
            $sidC = $mk($out1, $out2, ['incomplete' => 'both']);
            // どちらも未完了にします（mk は role 名一致で見るので2回に分けます）
            \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sidC)
               ->update(['complete' => 0]);
            $pC = $ctrl->publishPrediction($sidC);
            $check('P-29', '両方とも未完了なら候補を作らない',
                   $pC['code'], 'NO_RESPONSE');

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-ELIG  根拠のない馬で枠を埋めない
            // ══════════════════════════════════════════════════════════
            $this->info('── ⑤-3 全頭NO_EVIDENCEなら候補0 ──');
            $noEv = function (array $doc) {
                foreach (array_keys((array) $doc['horses']) as $i) {
                    $doc['horses'][$i]['eligibility']     = 'NO_EVIDENCE';
                    $doc['horses'][$i]['public_candidate'] = false;
                    $doc['horses'][$i]['rank_basis']      = 'NOT_RANKED';
                    $doc['horses'][$i]['family_review']   = [];
                }
                $doc['public_candidates'] = [];
                return $doc;
            };
            $sidD = $mk($noEv($out1), $noEv($out2));
            $pD = $ctrl->publishPrediction($sidD);
            $check('P-30', '全頭NO_EVIDENCEでは候補を作らない',
                   in_array((string) $pD['code'], ['NO_RESPONSE', 'NO_CANDIDATE'], true), true);
            $check('P-31', '候補は0頭', count((array) ($pD['candidates'] ?? [])), 0);

            // ══════════════════════════════════════════════════════════
            //   直すところがある応答の帯順位は、統合に使いません。
            //   「+5を付けない」だけでは順位の正しさは担保できないためです。
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-BANDKEEP
            // ══════════════════════════════════════════════════════════
            //   【前回の試験が間違っていました】P-34〜P-38 は
            //   「1頭の採点集計が壊れたら全帯の順位を捨てる」動きを
            //   <b>合格にしていました。</b>設計書10.2の局所回復と
            //   一致しません。期待値のほうを直します。
            //   いまは帯ごとに見て、指摘の無い帯はそのまま使い、
            //   使えない帯だけを 旧順位 → 予備根拠順位 → 明示縮退 の
            //   順に落とします。
            $this->info('── ⑤-5 採点の不備で正常な帯の順位を捨てない ──');

            // ① 1頭の observed_sum だけ 999（採点集計だけの誤り）
            $badSum = function (array $doc) {
                $nums = array_keys((array) $doc['horses']);
                $t = (int) $doc['horses'][$nums[0]]['horse_no'];
                $doc['horses'][$nums[0]]['observed_sum'] = 999;
                return [$doc, $t];
            };
            [$out1G, $tgtG] = $badSum($out1);
            $sidG  = $mk($out1G, $out2);
            $accG  = $ctrl->acceptedHorsesOf($sidG, '1st');
            $usedG = $ctrl->markResponseUsed($sidG, '1st', $accG);
            $check('P-34', '採点集計だけの誤りでも帯の順位は残る',
                   ($usedG['band_ranks'] !== []), true);
            $check('P-35', '指摘の無い帯は応答の順位をそのまま使う',
                   ($usedG['rank_source']['A'] ?? null), 'RESPONSE');
            $check('P-36', '縮退した帯は無い', $usedG['fallback'], []);
            $check('P-37', '直すところがあるので加点の証明は出さない',
                   $usedG['proof'], null);
            // 壊した馬の採点は消さず、合計だけ計算し直します
            $hG = (array) (($accG['merged'] ?? [])[$tgtG] ?? []);
            $check('P-38', '壊した馬も残る', ($hG !== []), true);
            //   この資料の正常出力は、もともと採点が全部 null（score_status
            //   は partial）です。だから「null の数」では見られません。
            //   <b>応答にあった採点と1項目も変わっていないこと</b>を見ます。
            $scoreBefore = null;
            foreach ((array) $out1G['horses'] as $hh) {
                if ((int) $hh['horse_no'] === $tgtG) { $scoreBefore = (array) $hh['scores']; break; }
            }
            $check('P-39', '採点を1項目も書き換えていない',
                   (array) ($hG['scores'] ?? []), (array) $scoreBefore);
            $check('P-40', 'observed_sum は計算し直した値',
                   ((int) ($hG['observed_sum'] ?? -1) !== 999), true);
            // 公開まで通して、正常な馬が候補へ届くこと
            $pG = $ctrl->publishPrediction($sidG);
            $check('P-41', '公開できる', $pG['code'], 'PUBLISHED');
            $pGnums = array_column((array) $pG['candidates'], 'horse_no');
            $check('P-42', '候補の数が正常なときと同じ',
                   count($pGnums), count((array) $pA['candidates']));
            $check('P-43', '候補の馬番も正常なときと同じ',
                   $pGnums, array_column((array) $pA['candidates'], 'horse_no'));

            // ② 1st だけ（2nd は未完了）でも、1stのB／D帯の馬が消えない
            $sidI = $mk($out1G, $out2, ['incomplete' => '2nd']);
            $pI   = $ctrl->publishPrediction($sidI);
            $check('P-44', '2ndが未完了でも1st単独で公開できる',
                   $pI['code'], 'PUBLISHED');
            $check('P-45', '1stのB／D帯の馬が採点ミスだけで消えない',
                   (count((array) $pI['candidates']) > 0), true);

            // ③ 順位集合が不正（凍結集合に無い馬番を帯順位へ入れる）
            //    その帯だけ縮退し、他の帯は残します
            $ghost = function (array $doc) {
                $doc['band_ranks']['A'] = array_merge(
                    [999], (array) ($doc['band_ranks']['A'] ?? []));
                return $doc;
            };
            $sidH  = $mk($ghost($out1), $out2);
            $usedH = $ctrl->markResponseUsed($sidH, '1st',
                         $ctrl->acceptedHorsesOf($sidH, '1st'));
            //   evidence_band_ranks（予備根拠順位）はそのままなので、
            //   設計書10.2の順で<b>先にそちらへ切り替わります。</b>
            //   明示縮退はその次です。
            $check('P-46', '集合の壊れた帯は予備根拠順位へ切り替わる',
                   ($usedH['rank_source']['A'] ?? null), 'EVIDENCE_RANK');
            $check('P-47', '予備根拠順位で足りるので明示縮退はしない',
                   $usedH['fallback'], []);
            $check('P-48', '他の帯は応答の順位のまま',
                   ($usedH['rank_source']['B'] ?? null), 'RESPONSE');
            $check('P-49', '縮退があるので加点の証明は出さない',
                   $usedH['proof'], null);
            $check('P-50', '幽霊馬は順位に入らない',
                   in_array(999, (array) ($usedH['band_ranks']['A'] ?? []), true),
                   false);
            $pH = $ctrl->publishPrediction($sidH);
            $check('P-51', '幽霊馬は候補に入らない',
                   in_array(999, array_column((array) $pH['candidates'],
                                              'horse_no'), true), false);
            $check('P-52', '切り替えても公開は止まらない', $pH['code'], 'PUBLISHED');

            // ④ 予備根拠順位も壊れていたら、明示縮退まで落ちます
            $ghost2 = function (array $doc) {
                $doc['band_ranks']['A'] = array_merge(
                    [999], (array) ($doc['band_ranks']['A'] ?? []));
                $doc['evidence_band_ranks']['A'] = array_merge(
                    [998], (array) ($doc['evidence_band_ranks']['A'] ?? []));
                return $doc;
            };
            $sidJ  = $mk($ghost2($out1), $out2);
            $usedJ = $ctrl->markResponseUsed($sidJ, '1st',
                         $ctrl->acceptedHorsesOf($sidJ, '1st'));
            $check('P-53', '予備根拠順位も壊れていれば明示縮退になる',
                   ($usedJ['rank_source']['A'] ?? null), 'EXPLICIT_FALLBACK');
            $check('P-54', '縮退した帯を記録する', $usedJ['fallback'], ['A']);
            $check('P-55', '縮退してもA帯の馬は全部そろう',
                   count((array) ($usedJ['band_ranks']['A'] ?? [])),
                   count((array) ($out1['band_ranks']['A'] ?? [])));
            $check('P-56', '他の帯は応答の順位のまま',
                   ($usedJ['rank_source']['B'] ?? null), 'RESPONSE');
            $pJ = $ctrl->publishPrediction($sidJ);
            $check('P-57', '明示縮退でも公開は止まらない', $pJ['code'], 'PUBLISHED');

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-BANDSCOPE
            // ══════════════════════════════════════════════════════════
            //   訂正は馬の行しか照合していないので、トップレベルの
            //   band_ranks を黙って変えても通ります。FIX_APPLIED なら
            //   その変えた並びが使われていました。
            //   訂正の対象になっていない帯は、初回の並びを保持します。
            $this->info('── ⑤-6 訂正の対象外の帯は初回の順位を保持する ──');
            $bo1 = $out1;
            // A帯だけを壊します（幽霊馬を入れて集合を不正にします）
            $bo1['band_ranks']['A'] = array_merge(
                array_slice((array) $out1['band_ranks']['A'], 0, -1), [999]);
            // 訂正は A帯を正しく直すが、<b>B帯の並びも勝手に入れ替える</b>
            $bf1 = $out1;
            $bf1['band_ranks']['B'] = array_reverse((array) $out1['band_ranks']['B']);
            $sidB = $mk($bo1, $out2, ['fix_1st' => $bf1]);
            $accB = $ctrl->acceptedHorsesOf($sidB, '1st');
            $check('P-58', '訂正は採用される',
                   (string) ($accB['code'] ?? ''), 'FIX_APPLIED');
            $usedB = $ctrl->markResponseUsed($sidB, '1st', $accB);
            $check('P-59', 'A帯は訂正の順位を使う（対象なので）',
                   (string) ($usedB['rank_source']['A'] ?? ''), 'RESPONSE');
            $check('P-60', 'A帯の並びは訂正のもの',
                   array_map('intval', (array) ($usedB['band_ranks']['A'] ?? [])),
                   array_map('intval', (array) $bf1['band_ranks']['A']));
            $check('P-61', 'B帯は初回の順位を保持する（対象外なので）',
                   (string) ($usedB['rank_source']['B'] ?? ''), 'PREVIOUS_KEPT');
            $check('P-62', 'B帯の並びは初回のまま（訂正の並びを採らない）',
                   array_map('intval', (array) ($usedB['band_ranks']['B'] ?? [])),
                   array_map('intval', (array) $out1['band_ranks']['B']));
            $check('P-63', 'B帯の並びは訂正のものと違う',
                   array_map('intval', (array) ($usedB['band_ranks']['B'] ?? []))
                   !== array_map('intval', (array) $bf1['band_ranks']['B']), true);
            $noteB = implode('／', (array) ($usedB['rank_notes'] ?? []));
            $check('P-64', '訂正が違う順位を返していたことを記録する',
                   strpos($noteB, '訂正は違う順位を返していました') !== false, true);
            $check('P-65', 'D帯も対象外なので初回を保持する',
                   (string) ($usedB['rank_source']['D'] ?? ''), 'PREVIOUS_KEPT');

            // 訂正が B帯を変えていなければ、記録も「変えていた」とは書きません
            $bf2 = $out1;                      // 帯順位はそのまま
            $sidC = $mk($bo1, $out2, ['fix_1st' => $bf2]);
            $accC = $ctrl->acceptedHorsesOf($sidC, '1st');
            $usedC = $ctrl->markResponseUsed($sidC, '1st', $accC);
            $check('P-66', '変えていなければ初回と同じ並び',
                   array_map('intval', (array) ($usedC['band_ranks']['B'] ?? [])),
                   array_map('intval', (array) $out1['band_ranks']['B']));
            $noteC = implode('／', (array) ($usedC['rank_notes'] ?? []));
            $check('P-67', '「違う順位を返していた」とは書かない',
                   strpos($noteC, '訂正は違う順位を返していました') !== false, false);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-FROZENK  取消で順位点を変えない
            // ══════════════════════════════════════════════════════════
            $this->info('── ⑤-4 取消しても凍結Kと順位点は変わらない ──');
            $sidE = $mk($out1, $out2);
            $pE   = $ctrl->publishPrediction($sidE);
            $baseScore = array_column((array) $pE['candidates'], 'score', 'horse_no');
            // A帯の先頭（band_ranks の A の1頭目）を取り消します
            $headA = (int) (((array) $out1['band_ranks']['A'])[0] ?? 0);
            $sidF  = $mk($out1, $out2, ['cancelled' => [$headA]]);
            $pF    = $ctrl->publishPrediction($sidF);
            $afterScore = array_column((array) $pF['candidates'], 'score', 'horse_no');
            $same = true;
            foreach ($afterScore as $hn => $sv) {
                if (!array_key_exists($hn, $baseScore)) { continue; }
                if ($baseScore[$hn] !== $sv) { $same = false; break; }
            }
            $check('P-32', '残った馬の順位点が取消前と同じ', $same, true);
            $check('P-33', '取消馬は候補に入らない',
                   in_array($headA, array_column((array) $pF['candidates'], 'horse_no'), true),
                   false);

            // ── ⑥ 期限・全頭取消・手渡し ───────────────────────────
            $this->info('── ⑥ 止まるところ ──');
            $sid5 = $mk($out1, $out2);
            $late = \App\Http\Controllers\Api\BaganrikiWorker::nowUs()
                  + 3600 * 1000000;
            $p5 = $ctrl->publishPrediction($sid5, ['now_us' => $late]);
            $check('P-18', '公開期限を過ぎたら止まる', $p5['code'], 'DEADLINE_PASSED');

            $allNums = [];
            foreach ((array) $in1['runners'] as $r) { $allNums[] = (int) $r['horse_no']; }
            $sid6 = $mk($out1, $out2, ['cancelled' => $allNums]);
            $p6 = $ctrl->publishPrediction($sid6);
            $check('P-19', '全頭取消で止まる', $p6['code'], 'ALL_CANCELLED');

            $sid7 = $mk($out1, $out2);
            $p7 = $ctrl->publishPrediction($sid7, ['handed' => [['horse_no' => 1]]]);
            $check('P-20', 'ジャーナルがあるのに手渡しは受け付けない',
                   $p7['code'], 'HANDED_REFUSED');

            // ── ⑦ 監査に3つの時刻が残る ────────────────────────────
            $this->info('── ⑦ 公開の監査 ──');
            $ev = \DB::table('baganriki_audit_events')
                    ->where('snapshot_id', $sid)->where('kind', 'PUBLISHED')->first();
            $check('P-21', '公開の監査が残る', ($ev !== null), true);
            if ($ev !== null) {
                $d = json_decode((string) $ev->diagnostics, true);
                $has = is_array($d) && isset($d['verify_started_at'],
                        $d['verify_finished_at'], $d['committed_at']);
                $check('P-22', '3つの時刻が残る', $has, true);
                if ($has) {
                    $a = \App\Http\Controllers\Api\BaganrikiTime::us($d['verify_started_at']);
                    $b = \App\Http\Controllers\Api\BaganrikiTime::us($d['verify_finished_at']);
                    $c = \App\Http\Controllers\Api\BaganrikiTime::us($d['committed_at']);
                    $check('P-23', '検証開始<=完了<=commit直前',
                           ($a <= $b && $b <= $c), true);
                }
                $check('P-24', '版の増え方が残る',
                       [(int) $ev->old_revision, (int) $ev->new_revision], [0, 1]);
            }

        } catch (\Throwable $e) {
            $ok = false; $fail++;
            $this->error('  × 例外: ' . $e->getMessage());
            $this->error('     ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
            // 20261025：④-3 で別の接続に作ったレース正本の行を消します（A のロールバックでロックが外れてから）
            foreach ($pubRaceKeys as $kG) {
                try { \DB::connection('baganriki_pub_b')->table('t_horse_odds_finder_races')->where($kG)->delete(); }
                catch (\Throwable $e) { $this->error('  × 試験用のレース正本を消せません: ' . $e->getMessage()); }
            }
        }

        $this->line('');
        $this->line(sprintf('  ロールバック後  snapshot %d／公開 %d／intent %d',
            \DB::table('baganriki_snapshots')->count(),
            \DB::table('baganriki_publications')->count(),
            \DB::table('baganriki_publication_intents')->count()));
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0 && $ok)
            ? ' 統合・公開・intent・revision は契約どおりに動きました（DBへ残る書き込み0件）'
            : ' 通らなかった箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-API  読取APIの通し試験
     * ═══════════════════════════════════════════════════════════════════════
     * ■ 何を確かめるか
     *   1. 本番の世代（generation=0）だけを読み、REPLAY（900）を出さないこと
     *   2. 3つの版（state／response／publication）を返すこと
     *   3. ワーカーの状態を返し、<b>予約token と原応答を返さない</b>こと
     *   4. 正式公開の枠は動かさず、いまの取消は actual_quota と
     *      available_candidate_count にだけ映すこと
     *   5. 表示状態（PENDING／PUBLISHED／UNAVAILABLE／CANCELLED）
     *   6. 暫定では星を出さないこと、購入推奨が常に false であること
     *
     * ■ 書き込みについて
     *   全部ひとつのトランザクションで行い、最後に必ずロールバックします。
     */
    private function runReadApiTest(string $root): void
    {
        self::loadPredictionClasses();
        $vDir = rtrim($root, '/') . '/validation/normal';
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 読取APIの通し試験');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');
        foreach (['MIXED_N16_1st_input.json', 'MIXED_N16_1st_output.json',
                  'MIXED_N16_2nd_input.json', 'MIXED_N16_2nd_output.json'] as $f) {
            if (!is_file($vDir . '/' . $f)) {
                $this->error('  × ' . $vDir . '/' . $f . ' がありません');
                return;
            }
        }
        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-9s %-50s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $rd = static fn(string $f) => json_decode((string) file_get_contents($f), true);
        $j  = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $in1  = $rd($vDir . '/MIXED_N16_1st_input.json');
        $in2  = $rd($vDir . '/MIXED_N16_2nd_input.json');
        $out1 = $rd($vDir . '/MIXED_N16_1st_output.json');
        $out2 = $rd($vDir . '/MIXED_N16_2nd_output.json');
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;
        $WK    = \App\Http\Controllers\Api\BaganrikiWorker::class;

        // $r = \DB::table('t_horse_odds_finder_races')->where('date', '2026-10-18')
        //         ->orderBy('basho')->orderBy('race')->first();
        // 20261025：10/18 の行は週次の洗い替えで消えたので、--date があればその日、無ければ実在の場の最新日を写し元にします
        $rSrcDate = (string) ($this->option('date') ?: \DB::table('t_horse_odds_finder_races')->where('basho', '<', '90')->max('date'));
        $r = \DB::table('t_horse_odds_finder_races')->where('date', $rSrcDate)->where('basho', '<', '90')
                ->orderBy('basho')->orderBy('race')->first();
        if ($r === null) { $this->error('  × ' . $rSrcDate . ' のレースがありません'); return; }
        // @ANCHOR-20261023-NARU-STARTTIME  【旧】10/18 の実在レースの race_id のまま、締切だけ1時間後にしていました。
        //   期限の判定がレース正本の発走時刻を読むようになったので、そのままでは「締切はとうに過ぎた」と
        //   判定されます。実在レースの行を、場コード94・発走（締切の60秒後）で写して使います（ロールバックします）。
        // $raceId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
        $salesSec0 = time() + 3600;
        $rDate = date('Y-m-d', $salesSec0 + 60);
        $raceId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
            $rDate, $r->kaisuu, '94', $r->day, $r->race);
        $this->line('  対象レース : ' . $raceId . '（写し元 ' . $r->date . ' ' . $r->basho . ' ' . $r->race . 'R）');
        $this->line('');

        $ok = true;
        \DB::beginTransaction();
        try {
            $ctrl = app(\App\Http\Controllers\Api\AiController::class);
            $rc = (array) $r; unset($rc['id']);
            $rc['date'] = $rDate; $rc['basho'] = '94'; $rc['start_time'] = date('H:i:s', $salesSec0 + 60);
            \DB::table('t_horse_odds_finder_races')->where('date', $rDate)->where('kaisuu', $r->kaisuu)
              ->where('basho', '94')->where('day', $r->day)->where('race', $r->race)->delete();
            \DB::table('t_horse_odds_finder_races')->insert($rc);
            $now   = $WK::nowUs();
            $sales = $salesSec0 * 1000000;
            $nums  = [];
            foreach ((array) $in1['runners'] as $x) { $nums[] = (int) $x['horse_no']; }

            $mkSnap = function (int $gen) use ($raceId, $in1, $in2, $nums, $sales,
                                               $now, $j, $WK): int {
                return (int) \DB::table('baganriki_snapshots')->insertGetId([
                    'race_id' => $raceId, 'generation' => $gen,
                    'run_id' => 'AT-' . $gen,
                    'snapshot_hash'   => (string) $in1['snapshot_hash'],
                    'input_json_1st'  => $j($in1), 'input_json_2nd' => $j($in2),
                    'frozen_nums'     => $j($nums),
                    'allocation_mode' => (string) $in1['allocation_mode'],
                    'mode'            => ($gen === 0) ? 'MIXED' : 'REPLAYMODE',
                    'mode_status'     => (string) $in1['mode_status'],
                    'mode_policy_id'  => (string) $in1['mode_policy_id'],
                    'display_rating'  => 3,
                    'target_count'    => (int) $in1['target_count'],
                    'quota_1st'       => $j($in1['quota']),
                    'coverage'        => $j(['past_runs' => ['status' => 'INCLUDED']]),
                    'collection_audit' => $j(['6' => ['status' => 'ADOPTED']]),
                    'input_freeze_at' => $WK::toDb($now - 100),
                    'sales_close_at'  => $WK::toDb($sales),
                    'created_at'      => $WK::toDb($now),
                ]);
            };
            // REPLAY を先に入れて、公開APIが拾わないことを見ます
            $sid900 = $mkSnap(900);
            $sid0   = $mkSnap(0);
            \DB::table('baganriki_live_states')->insert([
                'snapshot_id' => $sid0, 'cancellations' => $j([]),
                'active_count' => count($nums),
                'live_target_count' => (int) $in1['target_count'],
                'live_quota' => $j($in1['quota']), 'live_close' => null,
                'state_revision' => 0, 'response_revision' => 0,
                'publication_revision' => 0, 'latest_publication_id' => null,
                'updated_at' => $WK::toDb($now),
            ]);
            foreach ([['1st', $out1], ['2nd', $out2]] as $pair) {
                [$role, $doc] = $pair;
                \DB::table('baganriki_ai_attempts')->insert([
                    'snapshot_id' => $sid0, 'role' => $role, 'attempt_no' => 0,
                    'token' => hash('sha256', 'AT' . $sid0 . $role),
                    'status' => $WK::RECEIVED,
                    'reserved_at' => $WK::toDb($now), 'received_at' => $WK::toDb($now),
                    'complete' => 1, 'raw_response' => $BEGIN . $j($doc) . $END,
                    'response_hash' => hash('sha256', $j($doc)),
                ]);
            }

            // ── ① 未公開（期限内）──────────────────────────────────
            $this->info('── ① 未公開（期限内）──');
            $d = $ctrl->getBaganrikiPublishedPrediction($raceId)->getData(true);
            $check('A-01', '期限内・未公開は PENDING', $d['state'], 'PENDING');
            $check('A-02', 'REPLAY の世代を読まない', $d['mode'], 'MIXED');
            $check('A-03', '確定なら星を出す', $d['display_rating'], 3);
            $check('A-04', '購入推奨は常に false', $d['buy_recommended'], false);
            $check('A-05', '学習段階は COLLECT_ONLY', $d['learning_stage'], 'COLLECT_ONLY');
            $check('A-06', 'model_version は null', $d['model_version'], null);
            $check('A-07', '3つの版を返す',
                   [$d['live_state']['state_revision'],
                    $d['live_state']['response_revision'],
                    $d['live_state']['publication_revision']], [0, 0, 0]);
            $ws = (array) ($d['worker_state'] ?? []);
            $check('A-08', 'ワーカーの状態を2枠ぶん返す', count($ws), 2);
            $keys = ($ws === []) ? [] : array_keys($ws[0]);
            $check('A-09', '予約token を返さない',
                   in_array('token', $keys, true), false);
            $check('A-10', '原応答を返さない',
                   in_array('raw_response', $keys, true), false);
            $check('A-11', '未公開では公開欄が null', $d['publication'], null);

            // ── ② 公開したあと ────────────────────────────────────
            $this->info('── ② 公開したあと ──');
            $p = $ctrl->publishPrediction($sid0);
            $check('A-12', '公開できる', $p['code'], 'PUBLISHED');
            $d = $ctrl->getBaganrikiPublishedPrediction($raceId)->getData(true);
            $check('A-13', '公開済みは PUBLISHED', $d['state'], 'PUBLISHED');
            $check('A-14', '公開IDが一致',
                   (string) $d['publication']['publication_id'],
                   (string) $p['publication_id']);
            $pq = (array) $d['publication']['publication_quota'];
            $check('A-15', '公開した枠がそのまま入る', $pq, (array) $p['quota']);
            $check('A-16', 'いまの枠も同じ（取消なし）',
                   (array) $d['live_state']['actual_quota'], $pq);
            $check('A-17', '公開候補の数', $d['live_state']['available_candidate_count'],
                   count($p['candidates']));
            $check('A-18', 'publication_revision が1になる',
                   $d['live_state']['publication_revision'], 1);

            // ── ③ 取消を入れる（正式公開は動かさない）──────────────
            $this->info('── ③ 取消を入れる ──');
            $gone = (int) $p['candidates'][0]['horse_no'];
            $goneBand = (string) $p['candidates'][0]['band'];
            \DB::table('baganriki_live_states')->where('snapshot_id', $sid0)->update([
                'cancellations' => $j([$gone]),
                'active_count'  => count($nums) - 1,
                'state_revision' => 1,
            ]);
            $d = $ctrl->getBaganrikiPublishedPrediction($raceId)->getData(true);
            $check('A-19', '正式公開の枠は動かさない',
                   (array) $d['publication']['publication_quota'], $pq);
            $want = $pq; $want[$goneBand] = (int) $want[$goneBand] - 1;
            $check('A-20', 'いまの枠は取消を映す',
                   (array) $d['live_state']['actual_quota'], $want);
            $check('A-21', '公開候補の数も1つ減る',
                   $d['live_state']['available_candidate_count'],
                   count($p['candidates']) - 1);
            $check('A-22', '取消の馬番を返す',
                   (array) $d['live_state']['cancellations'], [$gone]);
            $check('A-23', '状態は PUBLISHED のまま', $d['state'], 'PUBLISHED');

            // ── ④ 全頭取消 ────────────────────────────────────────
            $this->info('── ④ 全頭取消 ──');
            \DB::table('baganriki_live_states')->where('snapshot_id', $sid0)->update([
                'cancellations' => $j($nums), 'active_count' => 0,
            ]);
            $d = $ctrl->getBaganrikiPublishedPrediction($raceId)->getData(true);
            $check('A-24', '全頭取消は CANCELLED', $d['state'], 'CANCELLED');

            // ── ⑤ 未公開で期限超過 ────────────────────────────────
            $this->info('── ⑤ 未公開で期限超過 ──');
            \DB::table('baganriki_publications')->where('snapshot_id', $sid0)->delete();
            \DB::table('baganriki_live_states')->where('snapshot_id', $sid0)->update([
                'cancellations' => $j([]), 'active_count' => count($nums),
            ]);
            \DB::table('baganriki_snapshots')->where('id', $sid0)->update([
                'sales_close_at' => $WK::toDb($now - 7200 * 1000000),
            ]);
            $d = $ctrl->getBaganrikiPublishedPrediction($raceId)->getData(true);
            $check('A-25', '未公開で期限超過は UNAVAILABLE', $d['state'], 'UNAVAILABLE');
            $check('A-26', '実効公開期限を返す',
                   is_string($d['live_state']['effective_publish_deadline']), true);

            // ── ⑥ 暫定のときの表示 ────────────────────────────────
            $this->info('── ⑥ 暫定のときの表示 ──');
            \DB::table('baganriki_snapshots')->where('id', $sid0)->update([
                'mode_status' => 'PROVISIONAL', 'mode' => 'UNKNOWN',
                'sales_close_at' => $WK::toDb($sales),
            ]);
            $d = $ctrl->getBaganrikiPublishedPrediction($raceId)->getData(true);
            $check('A-27', '暫定では星を出さない', $d['display_rating'], null);
            $check('A-28', '暫定の表示文を出す',
                   (string) ($d['provisional']['label'] ?? ''), '混戦として暫定選出');
            $check('A-29', '暫定の理由を出す',
                   (string) ($d['provisional']['note'] ?? ''),
                   \App\Http\Controllers\Api\BaganrikiPublicView::PROVISIONAL_NOTE);

            // ══════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-READGATE  非公開の読取経路（章⑤）
            // ══════════════════════════════════════════════════════
            $this->info('── ⑥ 非公開の読取経路（010の7章⑤）──');
            $A2 = \App\Http\Controllers\Api\AiController::class;

            $g1 = $A2::checkPredictionReadAccess(['force_web' => true, 'key' => '']);
            $check('A-30', '鍵が未設定なら、web からは中身を見せない',
                   [$g1['ok'], (string) $g1['code']], [false, 'NOT_PUBLIC']);
            $check('A-31', '既定の段は key（閉じる側）',
                   (string) $g1['mode'], 'key');
            $g2 = $A2::checkPredictionReadAccess();
            $check('A-32', 'コマンドからは素通りする（試験が落ちないため）',
                   [$g2['ok'], (string) $g2['code']], [true, 'CONSOLE']);

            // 鍵を設定したときの動き
            putenv('BAGANRIKI_PREDICTION_READ_KEY=qt-secret-20261021');
            $g3 = $A2::checkPredictionReadAccess(['force_web' => true, 'key' => 'chigau']);
            $check('A-33', '鍵が違えば見せない',
                   [$g3['ok'], (string) $g3['code']], [false, 'NOT_PUBLIC']);
            $g4 = $A2::checkPredictionReadAccess(['force_web' => true, 'key' => '']);
            $check('A-34', '鍵を渡さなければ見せない', $g4['ok'], false);
            $g5 = $A2::checkPredictionReadAccess(['force_web' => true,
                                     'key' => 'qt-secret-20261021']);
            $check('A-35', '鍵が合えば見せる',
                   [$g5['ok'], (string) $g5['code']], [true, 'KEY']);

            // 段を open にしたとき
            putenv('BAGANRIKI_PREDICTION_READ=open');
            $g6 = $A2::checkPredictionReadAccess(['force_web' => true, 'key' => '']);
            $check('A-36', 'open なら鍵なしでも見せる',
                   [$g6['ok'], (string) $g6['code']], [true, 'OPEN']);

            // 段を off にしたとき（鍵があっても見せない）
            putenv('BAGANRIKI_PREDICTION_READ=off');
            $g7 = $A2::checkPredictionReadAccess(['force_web' => true,
                                     'key' => 'qt-secret-20261021']);
            $check('A-37', 'off なら鍵が合っていても見せない',
                   [$g7['ok'], (string) $g7['code']], [false, 'NOT_PUBLIC']);

            // 知らない値は、閉じる側へ倒す
            putenv('BAGANRIKI_PREDICTION_READ=hoge');
            $g8 = $A2::checkPredictionReadAccess(['force_web' => true, 'key' => '']);
            $check('A-38', '知らない設定値は閉じる側へ倒す',
                   [(string) $g8['mode'], $g8['ok']], ['key', false]);

            // 試験で触った環境変数を元へ戻します
            putenv('BAGANRIKI_PREDICTION_READ');
            putenv('BAGANRIKI_PREDICTION_READ_KEY');
            $g9 = $A2::checkPredictionReadAccess(['force_web' => true, 'key' => '']);
            $check('A-39', '戻したあとも既定は閉じる',
                   [(string) $g9['mode'], $g9['ok']], ['key', false]);

            // ══════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-READGATE200  画面が壊れない返し方
            // ══════════════════════════════════════════════════════
            //   画面側（Flutter）の client.dart は 2xx 以外を全部例外に
            //   します。404 で返すと、画面に
            //     「読めませんでした Exception: http 404 [url=https://…]」
            //   と生のエラー文字列とURLが出ます。
            //   なので 200 で、いつもの形のまま断ります。
            //   画面は diagnostics をそのまま並べるので、
            //   「この記録はまだ公開していません」と読める形で出ます。
            $resp = $ctrl->getBaganrikiPublishedPrediction($raceId);
            $check('A-40', 'コマンドからは中身が読める（素通り）',
                   (int) $resp->getStatusCode(), 200);
            $dd = $resp->getData(true);
            $check('A-41', '返す形はいつもどおり（race_id と state がある）',
                   [array_key_exists('race_id', (array) $dd),
                    array_key_exists('state', (array) $dd)], [true, true]);
        } catch (\Throwable $e) {
            $ok = false; $fail++;
            $this->error('  × 例外: ' . $e->getMessage());
            $this->error('     ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            putenv('BAGANRIKI_PREDICTION_READ');
            putenv('BAGANRIKI_PREDICTION_READ_KEY');
            \DB::rollBack();
        }

        $this->line('');
        $this->line(sprintf('  ロールバック後  snapshot %d／公開 %d／attempts %d',
            \DB::table('baganriki_snapshots')->count(),
            \DB::table('baganriki_publications')->count(),
            \DB::table('baganriki_ai_attempts')->count()));
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0 && $ok)
            ? ' 読取APIは契約どおりに返しました（DBへ残る書き込み0件）'
            : ' 通らなかった箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PAST  本番の経路で作った入力をSchemaに当てる
     * ═══════════════════════════════════════════════════════════════════════
     * ■ 何をするか
     *   実レース1本について、<b>本番と同じ gatherPredictionContext</b> で
     *   INPUT_JSON を組み立て、
     *     ・input_schema.json
     *     ・source_registry_schema.json
     *   の両方に当てます。coverage 7区分の状態も並べます。
     *
     *   取得監査の実時刻は過去レースには無いので、仮置き（assume_audit）で
     *   組み立てます。<b>これは形とSchemaを確かめる試験です。</b>
     *   仮置きで作った入力は PRODUCTION として保存できません（別の試験で確認済み）。
     *
     * 読むだけです。DBへは1行も書きません。
     */
    private function runProductionInputCheck(string $root): void
    {
        self::loadPredictionClasses();
        $sDir = rtrim($root, '/') . '/schemas';
        $date = (string) ($this->option('date') ?: '2026-10-18');

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 本番の経路で作った入力を Schema に当てる');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  Schema   : ' . $sDir);
        $this->warn('  ※ 取得監査は仮置きです（形とSchemaを見る試験です）');
        $this->line('');

        $inSchemaPath  = $sDir . '/input_schema.json';
        $regSchemaPath = $sDir . '/source_registry_schema.json';
        foreach ([$inSchemaPath, $regSchemaPath] as $f) {
            if (!is_file($f)) { $this->error('  × ' . $f . ' がありません'); return; }
        }
        $inSchema  = json_decode((string) file_get_contents($inSchemaPath), true);
        $regSchema = json_decode((string) file_get_contents($regSchemaPath), true);

        $races = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                   ->orderBy('basho')->orderBy('race')->limit(3)->get();
        if ($races->isEmpty()) { $this->error('  × ' . $date . ' のレースがありません'); return; }

        $pass = 0; $fail = 0;
        foreach ($races as $r) {
            $raceId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
                $r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
            $st = (string) $r->start_time;
            if (strlen($st) === 5) { $st .= ':00'; }
            $anchorUs = \App\Http\Controllers\Api\BaganrikiTime::us($r->date . 'T' . $st . '+09:00');
            if ($anchorUs === null) { continue; }

            $g = \App\Http\Controllers\Api\AiController::gatherPredictionContext(
                $r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                ['assume_audit' => true]);
            if (!($g['ok'] ?? false)) {
                $this->line(sprintf('  － %-20s 入力を作れません: %s %s',
                    $raceId, (string) ($g['code'] ?? ''), (string) ($g['reason'] ?? '')));
                continue;
            }
            $asm = \App\Http\Controllers\Api\BaganrikiPrompt::assemble($g['ctx'], '1st');
            if (!($asm['ok'] ?? false)) {
                $this->line(sprintf('  － %-20s 組み立てられません: %s %s',
                    $raceId, (string) ($asm['code'] ?? ''), (string) ($asm['reason'] ?? '')));
                continue;
            }
            $in = $asm['input'];

            // 過去走を外した場合の大きさも出します（応答時間の見当をつけるため）
            $keep = [getenv('BAGANRIKI_PAST_RUNS_MAX'),
                     $_ENV['BAGANRIKI_PAST_RUNS_MAX'] ?? null,
                     $_SERVER['BAGANRIKI_PAST_RUNS_MAX'] ?? null];
            $noPastBytes = null;
            try {
                putenv('BAGANRIKI_PAST_RUNS_MAX=0');
                $_ENV['BAGANRIKI_PAST_RUNS_MAX'] = '0';
                $_SERVER['BAGANRIKI_PAST_RUNS_MAX'] = '0';
                $g0 = \App\Http\Controllers\Api\AiController::gatherPredictionContext(
                    $r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                    ['assume_audit' => true]);
                if ($g0['ok'] ?? false) {
                    $a0 = \App\Http\Controllers\Api\BaganrikiPrompt::assemble($g0['ctx'], '1st');
                    if ($a0['ok'] ?? false) { $noPastBytes = strlen((string) $a0['user']); }
                }
            } finally {
                if ($keep[0] === false) { putenv('BAGANRIKI_PAST_RUNS_MAX'); }
                else { putenv('BAGANRIKI_PAST_RUNS_MAX=' . $keep[0]); }
                if ($keep[1] === null) { unset($_ENV['BAGANRIKI_PAST_RUNS_MAX']); }
                else { $_ENV['BAGANRIKI_PAST_RUNS_MAX'] = $keep[1]; }
                if ($keep[2] === null) { unset($_SERVER['BAGANRIKI_PAST_RUNS_MAX']); }
                else { $_SERVER['BAGANRIKI_PAST_RUNS_MAX'] = $keep[2]; }
            }

            $this->info('── ' . $raceId . ' ──');
            $bytes = strlen((string) $asm['user']);
            $this->line(sprintf('    N=%d  source_registry %d 件  送信文 %s バイト',
                (int) $in['N'], count((array) $in['source_registry']),
                number_format($bytes)));
            if ($noPastBytes !== null) {
                $this->line(sprintf('    過去走を外すと %s バイト（過去走の分 %s バイト・%+.1f%%）',
                    number_format($noPastBytes),
                    number_format($bytes - $noPastBytes),
                    ($noPastBytes > 0) ? (($bytes - $noPastBytes) / $noPastBytes * 100) : 0));
            }
            $this->line('    直近何走まで : '
                . \App\Http\Controllers\Api\AiController::pastRunsMax() . ' 走'
                . '／OPI・推定確定オッズ : '
                . (\App\Http\Controllers\Api\AiController::marketPriorEnabled()
                    ? '載せる' : '載せない'));

            // ── ① 入力Schema ──────────────────────────────────────────
            $e1 = self::jsCheck($in, $inSchema, '');
            if ($e1 === []) {
                $pass++; $this->line('    ○ input_schema に適合');
            } else {
                $fail++;
                $this->error('    × input_schema 違反 ' . count($e1) . ' 件');
                foreach (array_slice($e1, 0, 5) as $m) { $this->error('        ' . $m); }
            }

            // ── ② source_registry Schema ─────────────────────────────
            $e2 = self::jsCheck((array) $in['source_registry'], $regSchema, '');
            if ($e2 === []) {
                $pass++; $this->line('    ○ source_registry_schema に適合');
            } else {
                $fail++;
                $this->error('    × source_registry_schema 違反 ' . count($e2) . ' 件');
                foreach (array_slice($e2, 0, 8) as $m) { $this->error('        ' . $m); }
            }

            // ── ③ coverage 7区分 ─────────────────────────────────────
            $cov = (array) $in['coverage'];
            $inc = 0;
            foreach (\App\Http\Controllers\Api\BaganrikiPrompt::COVERAGE_KEYS as $k) {
                $c = (array) ($cov[$k] ?? []);
                $s = (string) ($c['status'] ?? '?');
                if ($s === 'INCLUDED') {
                    $inc++;
                    $this->line(sprintf('    ○ %-22s INCLUDED  出典 %d 件',
                        $k, count((array) ($c['source_refs'] ?? []))));
                } else {
                    $this->line(sprintf('    － %-22s %s  %s', $k, $s,
                        mb_strimwidth((string) ($c['reason'] ?? ''), 0, 54, '…')));
                }
            }
            $this->line('    coverage INCLUDED ' . $inc . ' / 7 区分');

            // ── ④ 過去走が実際に入っているか ───────────────────────────
            $pastRefs = 0; $pastHorses = [];
            foreach ((array) $in['source_registry'] as $ref => $e) {
                if (strpos((string) $ref, '_past_') === false) { continue; }
                $pastRefs++;
                foreach ((array) ($e['horse_nos'] ?? []) as $n) { $pastHorses[(int) $n] = true; }
            }
            // OPI と推定確定オッズが入っているか
            $mkRefs = 0;
            foreach ((array) $in['source_registry'] as $ref => $e) {
                $r2 = (string) $ref;
                if (strpos($r2, 'popavg_') === 0 || strpos($r2, 'fukuavg_') === 0
                    || strpos($r2, 'corr_') === 0
                    || strpos($r2, '_opi') !== false
                    || strpos($r2, '_estimated_final_') !== false) {
                    $mkRefs++;
                }
            }
            if ($mkRefs > 0) {
                $pass++;
                $this->line('    ○ OPI・推定確定オッズ ' . $mkRefs . ' 件');
            } else {
                $this->line('    － OPI・推定確定オッズは載っていません（理由は上の coverage）');
            }

            if ($pastRefs > 0) {
                $pass++;
                $this->line('    ○ 過去走 ' . $pastRefs . ' 件／'
                          . count($pastHorses) . ' 頭ぶん');
            } else {
                $this->line('    － 過去走は載っていません（理由は上の coverage）');
            }
            foreach ((array) ($g['notes'] ?? []) as $n) {
                if (is_string($n) && strpos($n, '過去走') !== false) {
                    $this->line('      ' . $n);
                }
            }
            $this->line('');
        }

        $this->line('  合格 : ' . $pass . ' 件');
        $this->line('  不合格 : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0)
            ? ' 本番の経路で作った入力は Schema に適合しました（書き込み0件）'
            : ' Schema に合わない箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-MARKETTEST  OPI と推定確定オッズの通し試験
     * ═══════════════════════════════════════════════════════════════════════
     * ■ なぜこの試験が要るか
     *   OPI と推定確定オッズは「集計期間の終わりが対象レースの開催日より前」
     *   のときだけ載せます。DBにある集計は end_date が 2026-10-18 なので、
     *   10/18 のレースでは<b>必ず</b>載りません（当日を含む集計で当日を
     *   評価しないためです）。つまり 10/18 のデータだけでは
     *   <b>載る側（INCLUDED）を一度も通せません。</b>
     *
     *   そこで、実レースの実オッズ・実人気をそのまま使ったまま、
     *   <b>開催日だけを仮に先へ動かして</b>呼び、載る側も通します。
     *   仮に動かすのは開催日と情報上限だけで、オッズ・集計表・式には
     *   一切手を入れません。載った値は SQL から取り直して突き合わせます。
     *
     * ■ 読むだけです。DBへは1行も書きません。
     */
    private function runMarketPriorTest(string $root): void
    {
        self::loadPredictionClasses();
        $C  = \App\Http\Controllers\Api\AiController::class;
        $T  = \App\Http\Controllers\Api\BaganrikiTime::class;
        $S  = \App\Http\Controllers\Api\BaganrikiSnapshot::class;
        $A  = \App\Http\Controllers\Api\BaganrikiAcquisition::class;
        $P  = \App\Http\Controllers\Api\BaganrikiPrompt::class;

        $date = (string) ($this->option('date') ?: '2026-10-18');
        $sDir = rtrim($root, '/') . '/schemas';
        $regSchemaPath = $sDir . '/source_registry_schema.json';

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 OPI と推定確定オッズの通し試験');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  実レース : ' . $date . ' の先頭1レース');
        $this->warn('  ※ 読むだけです。DBへは1行も書きません');
        $this->line('');

        $pass = 0; $fail = 0;
        $ok = function (string $name, bool $cond, string $note = '') use (&$pass, &$fail) {
            if ($cond) { $pass++; $this->line(sprintf('  ○ %-12s %s', $name, $note)); }
            else       { $fail++; $this->error(sprintf('  × %-12s %s', $name, $note)); }
        };

        // ── ① 集計表はいまどうなっているか ────────────────────────────
        $this->info('── ① 集計表の状態（この集計はいつの開催日から使えるか）──');
        // @ANCHOR-20261020-PREDICTION-COVWIN  集計を使う5区分をまとめて並べます。
        //   「集計期間の列を持っているか」「算出時刻の列を持っているか」で
        //   判定できることが変わるので、そこも一緒に出します。
        $tables = [
            'popavg'  => ['t_horse_odds_finder_popularity_rank_average', 'OPI'],
            'fukuavg' => ['t_horse_odds_finder_fuku_popularity_rank_average', 'OPI'],
            'corr'    => ['t_horse_odds_finder_compute_odds_correction', 'estimated_odds'],
            'gaprec'  => ['t_horse_odds_finder_odds_gap_recovery', 'recovery_statistics'],
            'similar' => ['t_horse_odds_finder_similar_race_stats', 'similar_statistics'],
        ];
        $maxEnd = '';
        foreach ($tables as $tag => $pair) {
            [$tbl, $cov] = $pair;
            if (!\Schema::hasTable($tbl)) {
                $this->error('    × ' . $tbl . ' がありません'); $fail++; continue;
            }
            $rows = \DB::table($tbl)->get();
            // @ANCHOR-20261020-PREDICTION-REALTIME  列が無いのと値が無いのを区別します
            $hasCol = (\Schema::hasColumn($tbl, 'computed_at')
                       || \Schema::hasColumn($tbl, 'updated_at'));
            $ed = ''; $sd = ''; $cAt = null; $n = 0;
            foreach ($rows as $r) {
                $n++;
                $e = substr(str_replace('/', '-', (string) ($r->end_date ?? '')), 0, 10);
                $s = substr(str_replace('/', '-', (string) ($r->start_date ?? '')), 0, 10);
                if ($e !== '' && strcmp($e, $ed) > 0) { $ed = $e; }
                if ($s !== '' && ($sd === '' || strcmp($s, $sd) < 0)) { $sd = $s; }
                // 過去類似成績の表は updated_at が算出時刻にあたります
                $c = $r->computed_at ?? ($r->updated_at ?? null);
                if ($c !== null && ($cAt === null || strcmp((string) $c, (string) $cAt) > 0)) {
                    $cAt = (string) $c;
                }
            }
            // OPI と推定確定オッズの判定にだけ使う「集計期間の終わり」
            if (in_array($tag, ['popavg', 'fukuavg', 'corr'], true)
                && $ed !== '' && strcmp($ed, $maxEnd) > 0) { $maxEnd = $ed; }
            // 使える最も早い開催日 = end_date の翌日
            $nextDay = ($ed === '')
                     ? '集計期間の列が無いので期間では判定できません'
                     : date('Y-m-d', strtotime($ed . ' +1 day'));
            $cShow = ($cAt !== null) ? $cAt
                   : ($hasCol ? '列はあるが値なし（次に集計が走れば入ります）'
                              : '列なし');
            $this->line(sprintf('    %-8s %2d 件  期間 %s 〜 %s  算出 %s',
                $tag, $n, ($sd === '' ? '列なし' : $sd), ($ed === '' ? '列なし' : $ed),
                $cShow));
            $this->line(sprintf('             └ 使える最も早い開催日 : %s（%s）',
                $nextDay, $cov));
        }
        $this->line('');

        // ── ② 実レースの実オッズから凍結人気を作る ─────────────────────
        $this->info('── ② 実レースの実オッズから凍結人気を作る ──');
        $r = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                ->orderBy('basho')->orderBy('race')->first();
        if ($r === null) {
            $this->error('  × ' . $date . ' のレースがありません');
            return;
        }
        $raceId = $C::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
        $st = (string) $r->start_time;
        if (strlen($st) === 5) { $st .= ':00'; }
        $anchorUs = $T::us($r->date . 'T' . $st . '+09:00');
        if ($anchorUs === null) { $this->error('  × 発走時刻を組み立てられません'); return; }

        $slots = $T::INPUT_SLOTS;
        $cells = [];
        foreach (\DB::table('t_horse_odds_finder_odds')
                    ->where('date', $r->date)->where('kaisuu', $r->kaisuu)
                    ->where('basho', $r->basho)->where('day', $r->day)
                    ->where('race', (int) $r->race)
                    ->whereIn('minutes_before_start', $slots)
                    ->get(['num', 'minutes_before_start', 'odds', 'fuku_min', 'fuku_max'])
                 as $o) {
            $n = (int) $o->num;
            $t = (int) $o->minutes_before_start;
            $raw = trim((string) $o->odds);
            if ($raw === '取消' || $raw === '除外') { continue; }
            if ($A::usableDecimal($o->odds))     { $cells[$n][$t]['win_odds']  = trim((string) $o->odds); }
            if ($A::usableDecimal($o->fuku_min)) { $cells[$n][$t]['place_min'] = trim((string) $o->fuku_min); }
            if ($A::usableDecimal($o->fuku_max)) { $cells[$n][$t]['place_max'] = trim((string) $o->fuku_max); }
        }
        $win6 = [];
        foreach ($cells as $n => $byT) {
            if (isset($byT[6]['win_odds'])) { $win6[(int) $n] = $byT[6]['win_odds']; }
        }
        ksort($win6);
        $fr = $S::frozenRank($win6);
        $ok('M-01', ($fr['ok'] ?? false) === true,
            '凍結人気が出る（' . count($fr['rank'] ?? []) . ' 頭）');
        if (($fr['ok'] ?? false) !== true) {
            $this->error('      ' . (string) ($fr['reason'] ?? ''));
            return;
        }
        $rank = $fr['rank'];
        $top = null;
        foreach ($rank as $n => $rk) { if ((int) $rk === 1) { $top = (int) $n; break; } }
        $this->line(sprintf('    %s  N=%d  1番人気=馬番%s（6分前単勝 %s／6分前複勝最小 %s）',
            $raceId, count($rank), (string) $top,
            (string) ($cells[$top][6]['win_odds'] ?? '無し'),
            (string) ($cells[$top][6]['place_min'] ?? '無し')));
        $realFreeze = $P::rfc3339($T::inputFreeze($anchorUs));
        $this->line('    情報上限（実レース）: ' . $realFreeze);
        $this->line('');

        // ── ③ 開催日そのまま（当日を含む集計なので載らないこと）─────────
        $this->info('── ③ 開催日そのまま → 載らないこと（当日を含む集計だから）──');
        $m0 = $C::buildMarketPriorRegistry($raceId, (string) $r->date,
                   $rank, $cells, $realFreeze);
        $c0 = $m0['coverage'];
        $ok('M-02', ($c0['OPI']['status'] ?? '') === 'UNAVAILABLE',
            'OPI は UNAVAILABLE');
        $ok('M-03', ($c0['estimated_odds']['status'] ?? '') === 'UNAVAILABLE',
            'estimated_odds は UNAVAILABLE');
        $ok('M-04', $m0['registry'] === [], 'registry は 0 件');
        $ok('M-05',
            strpos((string) ($c0['OPI']['reason'] ?? ''), (string) substr((string) $r->date, 0, 10)) !== false,
            '理由に開催日が入る');
        foreach (['OPI', 'estimated_odds'] as $k) {
            $this->line(sprintf('      %-15s %s', $k,
                mb_strimwidth((string) ($c0[$k]['reason'] ?? ''), 0, 100, '…')));
        }
        $this->line('');

        // ── ④ 開催日を仮に先へ動かして載る側を通す ─────────────────────
        //   動かすのは開催日と情報上限だけです。
        //   オッズ・集計表・式には手を入れていません。
        $asOf = (string) ($this->option('as-of') ?: '');
        if ($asOf === '') {
            $asOf = ($maxEnd === '')
                  ? date('Y-m-d', strtotime($date . ' +7 day'))
                  : date('Y-m-d', strtotime($maxEnd . ' +7 day'));
        }
        $this->info('── ④ 開催日を仮に ' . $asOf . ' として呼ぶ → 載ること ──');
        $this->warn('    ※ 仮の開催日です。動かすのは開催日と情報上限だけで、');
        $this->warn('       オッズ・集計表・式はそのままです（本番送信には使いません）');
        $asFreeze = $asOf . substr($realFreeze, 10);   // 同じ時刻のまま日付だけ動かす
        $m1 = $C::buildMarketPriorRegistry($raceId, $asOf, $rank, $cells, $asFreeze);
        $c1 = $m1['coverage'];
        $reg = $m1['registry'];
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-REALTIME  算出時刻がある表かどうかで期待値が変わります
        // ══════════════════════════════════════════════════════════════
        //   過去平均の2表に computed_at が無い（または NULL）あいだは、
        //   「情報上限より前に利用できた証拠が無い」ので OPI は載せません。
        //   列が入って集計が次に走れば、その日から載ります。
        //   @ANCHOR-20261020-PREDICTION-COMPUTEDAT  「夜の集計が1回まわれば」と
        //     書いていましたが、新しいレース結果が無い日は集計が
        //     DBに1行も書かずに終わる作りでした。そちらを直しました。
        $hasPop = \Schema::hasColumn('t_horse_odds_finder_popularity_rank_average',
                                      'computed_at')
               && \Schema::hasColumn('t_horse_odds_finder_fuku_popularity_rank_average',
                                      'computed_at')
               && \DB::table('t_horse_odds_finder_popularity_rank_average')
                     ->whereNotNull('computed_at')->exists()
               && \DB::table('t_horse_odds_finder_fuku_popularity_rank_average')
                     ->whereNotNull('computed_at')->exists();
        $this->line('    過去平均の表に算出時刻があるか : '
                  . ($hasPop ? 'ある（OPIを載せます）'
                             : 'まだ無い（OPIは載せません）'));
        if ($hasPop) {
            $ok('M-06', ($c1['OPI']['status'] ?? '') === 'INCLUDED',
                'OPI は INCLUDED（出典 '
                . count((array) ($c1['OPI']['source_refs'] ?? [])) . ' 件）');
        } else {
            $ok('M-06', ($c1['OPI']['status'] ?? '') === 'UNAVAILABLE'
                && strpos((string) ($c1['OPI']['reason'] ?? ''), 'computed_at') !== false,
                'OPI は算出時刻が無いので UNAVAILABLE（理由も読める）');
        }
        $ok('M-07', ($c1['estimated_odds']['status'] ?? '') === 'INCLUDED',
            'estimated_odds は INCLUDED（出典 '
            . count((array) ($c1['estimated_odds']['source_refs'] ?? [])) . ' 件）');
        $ok('M-08', count($reg) > 0, 'registry は ' . count($reg) . ' 件');
        // @ANCHOR-20261021-PREDICTION-OPILINE  OPIが載ったのに「載らないぶん」と
        //   書き続けていました（算出時刻が入る前の文面のままでした）。
        //   読む人に逆の意味で伝わるので、載らなかったときだけ出します。
        if (($c1['OPI']['status'] ?? '') !== 'INCLUDED') {
            $this->line('    （OPIが載らないぶん、件数は少なくなります）');
            $this->error('      ' . (string) ($c1['OPI']['reason'] ?? ''));
        }
        if (($c1['estimated_odds']['status'] ?? '') !== 'INCLUDED') {
            $this->error('      ' . (string) ($c1['estimated_odds']['reason'] ?? ''));
        }
        // 内訳
        $byScope = []; $byKind = [];
        foreach ($reg as $e) {
            $byScope[(string) ($e['scope'] ?? '?')] = ($byScope[(string) ($e['scope'] ?? '?')] ?? 0) + 1;
            $byKind[(string) ($e['source_kind'] ?? '?')] = ($byKind[(string) ($e['source_kind'] ?? '?')] ?? 0) + 1;
        }
        ksort($byScope); ksort($byKind);
        $pp = [];
        foreach ($byScope as $k => $v) { $pp[] = $k . ' ' . $v; }
        $this->line('    scope       : ' . implode('／', $pp));
        $pp = [];
        foreach ($byKind as $k => $v) { $pp[] = $k . ' ' . $v; }
        $this->line('    source_kind : ' . implode('／', $pp));
        $this->line('');

        // ── ⑤ source_registry Schema に当てる ──────────────────────────
        $this->info('── ⑤ 載せた項目を source_registry Schema に当てる ──');
        if (!is_file($regSchemaPath)) {
            $this->error('  × ' . $regSchemaPath . ' がありません');
            $fail++;
        } else {
            $regSchema = json_decode((string) file_get_contents($regSchemaPath), true);
            $errs = self::jsCheck($reg, $regSchema, '');
            $ok('M-09', $errs === [], 'Schema 違反 ' . count($errs) . ' 件');
            foreach (array_slice($errs, 0, 8) as $m) { $this->error('        ' . $m); }
        }
        $this->line('');

        // ── ⑥ 載った値を SQL から取り直して突き合わせる ─────────────────
        //   同じ式を、同じ表から、PHP の外で取り直した値で組み直します。
        $this->info('── ⑥ 1番人気の5つの値を SQL から取り直して突き合わせる ──');
        $pa = \DB::table('t_horse_odds_finder_popularity_rank_average')
                 ->where('popularity_rank', 1)->value('odds_average');
        $fa = \DB::table('t_horse_odds_finder_fuku_popularity_rank_average')
                 ->where('popularity_rank', 1)->value('odds_average');
        $cr = \DB::table('t_horse_odds_finder_compute_odds_correction')
                 ->where('popularity_rank', 1)->value('avg_correction_ratio');
        $w6 = (float) ($cells[$top][6]['win_odds'] ?? 0);
        $p6 = (float) ($cells[$top][6]['place_min'] ?? 0);
        $this->line(sprintf('    材料 : 過去平均単勝 %s／過去平均複勝最小 %s／補正係数 %s',
            (string) $pa, (string) $fa, (string) $cr));
        $this->line(sprintf('           6分前単勝 %s／6分前複勝最小 %s',
            (string) $w6, (string) $p6));
        $estHand = ($w6 > 0 && $cr !== null) ? round($w6 * (float) $cr, 2) : null;
        $want = [
            'opi'                       => ($w6 > 0 && $pa !== null) ? round((float) $pa / $w6, 2) : null,
            'fuku_opi'                  => ($p6 > 0 && $fa !== null) ? round((float) $fa / $p6, 2) : null,
            'estimated_final_odds'      => $estHand,
            'estimated_final_place_min' => ($p6 > 0 && $cr !== null) ? round($p6 * (float) $cr, 2) : null,
            'estimated_opi'             => ($estHand !== null && $estHand > 0 && $pa !== null)
                                         ? round((float) $pa / $estHand, 2) : null,
        ];
        $i = 10;
        // 算出時刻がまだ無い表に頼る項目は、載らないのが正しい動きです
        $needPop = ['opi' => true, 'fuku_opi' => true, 'estimated_opi' => true,
                    'estimated_final_odds' => false, 'estimated_final_place_min' => false];
        foreach ($want as $field => $v) {
            $ref = 'h' . $top . '_' . $field;
            $got = $reg[$ref]['value'] ?? null;
            $name = 'M-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $i++;
            if (!$hasPop && ($needPop[$field] ?? false)) {
                // $ok は (名前, 真偽, 説明) です。引数を取り違えていました。
                $ok($name, ($got === null),
                    sprintf('%-26s 算出時刻が無いので載せない（正しい動き）', $field));
                continue;
            }
            if ($v === null) {
                $this->line(sprintf('  － %-12s %-26s 材料が足りないので出しません', $name, $field));
                continue;
            }
            $ok($name, ($got !== null && abs((float) $got - (float) $v) < 0.000001),
                sprintf('%-26s 載った %s ／ SQLから %s', $field,
                    ($got === null ? '無し' : (string) $got), (string) $v));
        }
        $this->line('');

        // ── ⑦ 親（parents）が同じ登録の中に居るか ──────────────────────
        $this->info('── ⑦ 親（parents）がちゃんと指せているか ──');
        $miss = [];
        foreach ($reg as $ref => $e) {
            foreach ((array) ($e['parents'] ?? []) as $p2) {
                $p2 = (string) $p2;
                if (isset($reg[$p2])) { continue; }
                // 6分前のオッズは本番ではオッズ側の登録に居ます（この試験には居ません）
                if (preg_match('/^h\d+_(win_odds|place_min)_6$/', $p2)) { continue; }
                $miss[] = $ref . ' → ' . $p2;
            }
        }
        $ok('M-15', $miss === [],
            'この試験の中で行き先の無い親 ' . count($miss) . ' 件'
            . '（6分前オッズは本番のオッズ側に居るので除きます）');
        foreach (array_slice($miss, 0, 8) as $m) { $this->error('        ' . $m); }
        $this->line('');

        // ── ⑧ 旗で止められるか ─────────────────────────────────────────
        $this->info('── ⑧ .env の BAGANRIKI_MARKET_PRIOR=0 で止まるか ──');
        $keep = [getenv('BAGANRIKI_MARKET_PRIOR'),
                 $_ENV['BAGANRIKI_MARKET_PRIOR'] ?? null,
                 $_SERVER['BAGANRIKI_MARKET_PRIOR'] ?? null];
        try {
            putenv('BAGANRIKI_MARKET_PRIOR=0');
            $_ENV['BAGANRIKI_MARKET_PRIOR'] = '0';
            $_SERVER['BAGANRIKI_MARKET_PRIOR'] = '0';
            $m2 = $C::buildMarketPriorRegistry($raceId, $asOf, $rank, $cells, $asFreeze);
            $ok('M-16', $m2['registry'] === [], '0 なら registry は 0 件');
            $ok('M-17', ($m2['coverage']['OPI']['status'] ?? '') === 'UNAVAILABLE',
                '0 なら UNAVAILABLE');
            $ok('M-18',
                strpos((string) ($m2['coverage']['OPI']['reason'] ?? ''), 'BAGANRIKI_MARKET_PRIOR') !== false,
                '止めた理由が読める');
        } finally {
            if ($keep[0] === false) { putenv('BAGANRIKI_MARKET_PRIOR'); }
            else { putenv('BAGANRIKI_MARKET_PRIOR=' . $keep[0]); }
            if ($keep[1] === null) { unset($_ENV['BAGANRIKI_MARKET_PRIOR']); }
            else { $_ENV['BAGANRIKI_MARKET_PRIOR'] = $keep[1]; }
            if ($keep[2] === null) { unset($_SERVER['BAGANRIKI_MARKET_PRIOR']); }
            else { $_SERVER['BAGANRIKI_MARKET_PRIOR'] = $keep[2]; }
        }
        $this->line('');

        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0)
            ? ' OPI と推定確定オッズは契約どおりに載りました（書き込み0件）'
            : ' 合わない箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-NATIVE  合成試験と取消の例を「本体」へ直接通す
     * ═══════════════════════════════════════════════════════════════════════
     * 【10月20日005のご指摘6-1・11-4】
     *   これまで合成729件＋境界4件は、Command側の検証用関数
     *   （self::decideModeFromSignals／self::acceptAcquisitionBatch）へ通していました。
     *   <b>本番が使う本体の関数へ通した試験ではありませんでした。</b>
     *   取消の配分も、11→10頭の不足移動しか試験がなく、
     *   目標数 C が変わる例（16→15・14→13・9→8・全頭取消）は
     *   コードを直したのに試験がありませんでした。
     *
     *   この試験は、本番が呼ぶ本体の関数だけを使います。
     *     BaganrikiSignals::v04Classify        mode・S・配分モード
     *     BaganrikiAllocation::v04TargetCount  目標数
     *     BaganrikiTime::acceptBatch       取得境界
     *     BaganrikiRanking::afterCancellation  取消後の引き直し
     *
     * 読むだけです。DBへは1行も書きません。
     */
    private function runNativeTest(string $dir): void
    {
        self::loadPredictionClasses();
        // AiController の v04Classify は BaganrikiSignals への薄い入口（private）です。
        // 本番が実際に計算しているのは BaganrikiSignals なので、そちらを呼びます。
        $S = \App\Http\Controllers\Api\BaganrikiSignals::class;
        $T = \App\Http\Controllers\Api\BaganrikiTime::class;
        $R = \App\Http\Controllers\Api\BaganrikiRanking::class;

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 合成試験と取消の例を「本体」へ直接通す');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  対象     : ' . $dir);
        $this->warn('  ※ Command側の検証用関数は使いません。本体だけを呼びます');
        $this->line('');

        $pass = 0; $fail = 0;
        $ok = function (string $name, $got, $want, string $note = '')
              use (&$pass, &$fail) {
            if ($got === $want) {
                $pass++;
                $this->line(sprintf('  ○ %-12s %s', $name, $note));
            } else {
                $fail++;
                $this->error(sprintf('  × %-12s %s  期待=%s 実際=%s', $name, $note,
                    var_export($want, true), var_export($got, true)));
            }
        };

        // ── ① 合成729件を本体 v04Classify へ直接通す ────────────────────
        $this->info('── ① 合成729件を本体 BaganrikiSignals::v04Classify へ通す ──');
        $p1 = rtrim($dir, '/') . '/validation/mode_cases.json';
        if (!is_file($p1)) { $p1 = rtrim($dir, '/') . '/mode_cases.json'; }
        if (!is_file($p1)) {
            $this->error('  × mode_cases.json がありません: ' . $p1);
            $fail++;
        } else {
            $d = json_decode((string) file_get_contents($p1), true);
            $cases = (array) ($d['cases'] ?? []);
            // 先方の signals は true/false/null。本体は TRUE/FALSE/UNKNOWN の文字列です。
            $tri = static fn($v) => ($v === null) ? 'UNKNOWN' : ($v ? 'TRUE' : 'FALSE');
            $keys = ['mode', 'mode_status', 'allocation_mode', 'mode_policy_id',
                     'selection_enabled', 'S', 'S_min', 'S_max', 'display_rating'];
            $ng = 0; $shown = 0; $checked = 0;
            foreach ($cases as $c) {
                $sig = (array) ($c['signals'] ?? []);
                $flags = [];
                foreach (['X1', 'X2', 'X3', 'X4', 'X5', 'X6', 'Z'] as $k) {
                    $flags[$k] = $tri($sig[$k] ?? null);
                }
                $r = $S::v04Classify($flags);
                foreach ($keys as $k) {
                    if (!array_key_exists($k, (array) ($c['expected'] ?? []))) { continue; }
                    $checked++;
                    if ($c['expected'][$k] !== $r[$k]) {
                        $ng++;
                        if ($shown < 5) {
                            $shown++;
                            $this->error(sprintf('      %s %s 期待=%s 実際=%s 信号=%s',
                                (string) ($c['id'] ?? '?'), $k,
                                var_export($c['expected'][$k], true),
                                var_export($r[$k], true),
                                json_encode($sig)));
                        }
                        break;
                    }
                }
            }
            $this->line(sprintf('    %d 件（判定 %d 項目）', count($cases), $checked));
            $ok('N-01', $ng, 0, '本体 v04Classify との不一致');
            $ok('N-02', count($cases), 729, '通した件数');
        }
        $this->line('');

        // ── ② 境界4件を本体 acceptBatch へ直接通す ──────────────────────
        $this->info('── ② 取得境界4件を本体 BaganrikiTime::acceptBatch へ通す ──');
        $p2 = rtrim($dir, '/') . '/validation/acquisition_boundary_cases.json';
        if (!is_file($p2)) { $p2 = rtrim($dir, '/') . '/acquisition_boundary_cases.json'; }
        if (!is_file($p2)) {
            $this->error('  × acquisition_boundary_cases.json がありません: ' . $p2);
            $fail++;
        } else {
            $d = json_decode((string) file_get_contents($p2), true);
            $cases = (array) ($d['cases'] ?? []);
            $ng = 0;
            foreach ($cases as $c) {
                // 予定時刻は与えられていないので、情報上限から発走時刻を戻します。
                // 戻した発走時刻を渡すので、@ANCHOR-R122-ANCHORMATCH の
                // 「正本との一致検査」も一緒に通ります。
                $frz = $T::us((string) ($c['input_freeze_at'] ?? ''));
                if ($frz === null) {
                    $ng++;
                    $this->error('      ' . (string) ($c['id'] ?? '?') . ' 情報上限が読めません');
                    continue;
                }
                $anchor = $frz - $T::INPUT_FREEZE_OFFSET_SEC * 1000000;
                $r = $T::acceptBatch((int) $c['slot'], [
                    'request_started_at'   => (string) $c['request_started_at'],
                    'response_received_at' => (string) $c['response_received_at'],
                    'available_at'         => (string) $c['available_at'],
                    'input_freeze_at'      => (string) $c['input_freeze_at'],
                ], $anchor);
                $got  = ($r['ok'] ?? false) ? 'PASS' : 'FAIL';
                $want = (string) ($c['expected'] ?? '');
                if ($got !== $want) {
                    $ng++;
                    $this->error(sprintf('      %s 期待=%s 実際=%s %s',
                        (string) ($c['id'] ?? '?'), $want, $got,
                        (string) ($r['reason'] ?? '')));
                } else {
                    $this->line(sprintf('    ○ %-18s %s', (string) ($c['id'] ?? '?'), $got));
                }
            }
            $ok('N-03', $ng, 0, '本体 acceptBatch との不一致');
            $ok('N-04', count($cases), 4, '通した件数');
        }
        $this->line('');

        // ── ③ 正本の発走時刻との一致検査（新しい検査） ──────────────────
        $this->info('── ③ 申告された予定・情報上限が正本と合わないとき ──');
        $anchor = $T::us('2026-10-18T10:05:00+09:00');
        $frzOk  = $T::toJst($T::inputFreeze($anchor));
        $schOk  = $T::toJst($T::slotScheduled($anchor, 6));
        $mk = static function (array $over) use ($schOk, $frzOk) {
            return $over + ['slot_scheduled_at'    => $schOk,
                            'request_started_at'   => $schOk,
                            'response_received_at' => $schOk,
                            'available_at'         => $schOk,
                            'input_freeze_at'      => $frzOk];
        };
        $r = $T::acceptBatch(6, $mk([]), $anchor);
        $ok('N-05', $r['ok'], true, '正本と合っていれば採用');
        $r = $T::acceptBatch(6, $mk(['slot_scheduled_at' => $T::toJst($T::slotScheduled($anchor, 6) + 1000000)]), $anchor);
        $ok('N-06', $r['code'], 'SCHEDULE_MISMATCH', '予定が1秒ずれたら不採用');
        $r = $T::acceptBatch(6, $mk(['input_freeze_at' => $T::toJst($T::inputFreeze($anchor) + 1000000)]), $anchor);
        $ok('N-07', $r['code'], 'FREEZE_MISMATCH', '情報上限が1秒甘いと不採用');
        $this->line('');

        // ── ④ 日付・時刻の繰上がり ──────────────────────────────────────
        $this->info('── ④ 存在しない日付・時刻を受け取らない ──');
        $ok('N-08', $T::us('2026-02-30T10:05:00+09:00'), null, '2月30日は受け取らない');
        $ok('N-09', $T::us('2026-13-01T10:05:00+09:00'), null, '13月は受け取らない');
        $ok('N-10', $T::us('2026-10-18T25:00:00+09:00'), null, '25時は受け取らない');
        $ok('N-11', $T::us('2026-10-18T10:61:00+09:00'), null, '61分は受け取らない');
        $ok('N-12', $T::us('2025-02-29T10:05:00+09:00'), null, '平年の2月29日は受け取らない');
        $ok('N-13', ($T::us('2024-02-29T10:05:00+09:00') !== null), true, 'うるう年の2月29日は通る');
        $ok('N-14', ($T::us('2026-10-18T10:05:00.123456+09:00') !== null), true,
            'マイクロ秒つきは通る');
        $this->line('');

        // ── ⑤ 取消で目標数が変わる例を本体へ通す ────────────────────────
        $this->info('── ⑤ 取消で目標数 C が変わる例（16→15・14→13・9→8・全頭）──');
        //   凍結帯は維持したまま、最新の頭数から C と基本配分を引き直します。
        //   帯は「上位3＝A／次3＝B／残り＝D」ではなく、各例の形で作ります。
        $band = static function (int $n, int $a, int $b) {
            $m = [];
            for ($i = 1; $i <= $n; $i++) {
                $m[$i] = ($i <= $a) ? 'A' : (($i <= $a + $b) ? 'B' : 'D');
            }
            return $m;
        };
        //  N  取消後  配分モード  期待する基本配分
        $cases = [
            ['16→15', 16, [16], 'MIXED',    ['A' => 3, 'B' => 2, 'D' => 1], 6],
            ['14→13', 14, [14], 'MIXED',    ['A' => 3, 'B' => 2, 'D' => 0], 5],
            ['9→8',    9, [9],  'MIXED',    ['A' => 2, 'B' => 2, 'D' => 0], 4],
        ];
        $i = 15;
        foreach ($cases as $cs) {
            [$label, $n, $cancel, $mode, $wantBase, $wantC] = $cs;
            // A6・B6・残りD の形（帯は凍結したまま動かしません）
            $fb = $band($n, 6, 6);
            $old = $R::afterCancellation($fb, [], ['A' => 3, 'B' => 3, 'D' => 1], $mode);
            $ac  = $R::afterCancellation($fb, $cancel, ['A' => 3, 'B' => 3, 'D' => 1], $mode);
            $nm = 'N-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT); $i++;
            $ok($nm, $ac['target_count'], $wantC,
                $label . ' の目標数（取消前 ' . var_export($old['target_count'], true) . '）');
            $nm = 'N-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT); $i++;
            $ok($nm, $ac['base_quota'], $wantBase,
                $label . ' の基本配分 '
                . json_encode($ac['base_quota'] ?? null));
            $nm = 'N-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT); $i++;
            $ok($nm, $ac['active_count'], $n - count($cancel), $label . ' の生存頭数');
        }
        // 全頭取消
        $fb = $band(16, 6, 6);
        $all = range(1, 16);
        $ac  = $R::afterCancellation($fb, $all, ['A' => 3, 'B' => 3, 'D' => 1], 'MIXED');
        $ok('N-24', $ac['all_cancelled'], true, '全頭取消は印を立てる');
        $ok('N-25', $ac['target_count'], 0, '全頭取消の目標数は0');
        $ok('N-26', $ac['live_quota'], null, '全頭取消では枠を返さない');
        $ok('N-27', $ac['active_count'], 0, '全頭取消の生存頭数は0');
        // 凍結帯を詰め直していないこと
        $ac2 = $R::afterCancellation($band(16, 6, 6), [1], ['A' => 3, 'B' => 3, 'D' => 1], 'MIXED');
        $ok('N-28', $ac2['band_alive']['A'], 5, '取消でAの生存が1減る（詰め直さない）');
        $ok('N-29', $ac2['band_alive']['B'], 6, 'Bは動かない（人気を繰り上げない）');
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-PERCAND
        // ══════════════════════════════════════════════════════════════
        //   なるが挙げた例をそのまま本体 pick へ通します。
        //     出馬表 1・2・3番
        //     A（先に利用可能）1・2番の単勝あり。3番は単勝も取消も<b>無い</b>
        //     B（後に利用可能）1・2番の単勝あり。3番の<b>正式取消</b>あり
        //     両方とも commit済み・時刻適合・hash正常
        //   合算した活動集合（1・2番）を使うと A が通ってしまいます。
        //   候補ごとに<b>自分の取消だけ</b>を引けば、A は3番の単勝が無いので
        //   落ち、B が採用されます。
        $this->info('── ⑥ 取消の採用単位（008のご指摘4）──');
        $mkB = static function (string $id, string $availAt, array $vals,
                                array $cancelled, bool $committed = true): array {
            return ['batch_id' => $id, 'slot' => 6,
                    'slot_scheduled_at'    => '2026-10-18T09:34:00+09:00',
                    'request_started_at'   => $availAt,
                    'response_received_at' => $availAt,
                    'available_at'         => $availAt,
                    'input_freeze_at'      => '2026-10-18T09:34:30+09:00',
                    'committed' => $committed, 'payload_ng' => null,
                    'values' => $vals, 'cancelled' => $cancelled];
        };
        $entry = [1, 2, 3];
        $vAB   = [1 => ['win' => '2.1'], 2 => ['win' => '4.5']];
        $vAll  = [1 => ['win' => '2.1'], 2 => ['win' => '4.5'], 3 => ['win' => '9.9']];
        $anchorUs6 = \App\Http\Controllers\Api\BaganrikiTime::us('2026-10-18T09:40:00+09:00');
        $A = $mkB('A', '2026-10-18T09:34:10+09:00', $vAB,  []);
        $B = $mkB('B', '2026-10-18T09:34:20+09:00', $vAB,  [3]);
        $pk = \App\Http\Controllers\Api\BaganrikiAcquisition::pick(6, [$A, $B], $entry, $anchorUs6);
        $ok('N-30', (string) ($pk['status'] ?? ''), 'ADOPTED',
            '採用できるバッチがある');
        $ok('N-31', (string) ($pk['batch']['batch_id'] ?? ''), 'B',
            '3番の取消を持つBを採用する（Aを選ばない）');
        $rejA = null;
        foreach ((array) $pk['rejected'] as $rj) {
            if ((string) ($rj['batch_id'] ?? '') === 'A') { $rejA = $rj; }
        }
        $ok('N-32', (string) ($rejA['code'] ?? ''), 'FREEZE_ODDS_MISSING',
            'Aは3番の単勝が無いので落ちる（別候補の取消を借りない）');
        $ok('N-33', strpos((string) ($rejA['reason'] ?? ''), '3') !== false, true,
            'その理由に馬番3が出る');

        // 未commit の取消は、ほかの候補を助けません
        $C = $mkB('C', '2026-10-18T09:34:10+09:00', $vAB, []);
        $D = $mkB('D', '2026-10-18T09:34:20+09:00', $vAB, [3], false);  // 未commit
        $pk2 = \App\Http\Controllers\Api\BaganrikiAcquisition::pick(6, [$C, $D], $entry, $anchorUs6);
        $ok('N-34', (string) ($pk2['status'] ?? ''), 'UNAVAILABLE',
            '未commitの取消では、どの候補も通らない');
        $codes2 = [];
        foreach ((array) $pk2['rejected'] as $rj) {
            $codes2[(string) $rj['batch_id']] = (string) $rj['code'];
        }
        $ok('N-35', $codes2['C'] ?? '', 'FREEZE_ODDS_MISSING',
            'Cは3番の単勝が無いので落ちる');
        $ok('N-36', $codes2['D'] ?? '', 'NOT_COMMITTED',
            'Dは未commitで落ちる');

        // 時刻不適合の取消も、ほかの候補を助けません
        $E = $mkB('E', '2026-10-18T09:34:10+09:00', $vAB, []);
        $F = $mkB('F', '2026-10-18T09:40:30+09:00', $vAB, [3]);  // 情報上限より後
        $pk3 = \App\Http\Controllers\Api\BaganrikiAcquisition::pick(6, [$E, $F], $entry, $anchorUs6);
        $codes3 = [];
        foreach ((array) $pk3['rejected'] as $rj) {
            $codes3[(string) $rj['batch_id']] = (string) $rj['code'];
        }
        $ok('N-37', (string) ($pk3['status'] ?? ''), 'UNAVAILABLE',
            '時刻不適合の取消でも、どの候補も通らない');
        $ok('N-38', $codes3['E'] ?? '', 'FREEZE_ODDS_MISSING',
            'Eは3番の単勝が無いので落ちる');
        $ok('N-39', ($codes3['F'] ?? '') !== '' && ($codes3['F'] ?? '') !== 'FREEZE_ODDS_MISSING',
            true, 'Fは時刻の理由で落ちる（' . ($codes3['F'] ?? '') . '）');

        // 自分で取消を持つ候補は、その馬を全頭検査から外せます（正しい動き）
        $G = $mkB('G', '2026-10-18T09:34:10+09:00', $vAB, [3]);
        $pk4 = \App\Http\Controllers\Api\BaganrikiAcquisition::pick(6, [$G], $entry, $anchorUs6);
        $ok('N-40', (string) ($pk4['status'] ?? ''), 'ADOPTED',
            '自分の取消なら、その馬は全頭検査から外れる');

        // 取消が無く全頭そろっていれば、これまでどおり先に来た方を採ります
        $H = $mkB('H', '2026-10-18T09:34:10+09:00', $vAll, []);
        $I = $mkB('I', '2026-10-18T09:34:20+09:00', $vAll, []);
        $pk5 = \App\Http\Controllers\Api\BaganrikiAcquisition::pick(6, [$H, $I], $entry, $anchorUs6);
        $ok('N-41', (string) ($pk5['batch']['batch_id'] ?? ''), 'H',
            '全頭そろっていれば先に利用できた方を採る（従来どおり）');
        $this->line('');

        $this->line('');

        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0)
            ? ' 本体へ直接通して契約どおりでした（書き込み0件）'
            : ' 合わない箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-ONEBUILD  固定入力をそのまま送る通し試験
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：10月20日007のご指摘2（最優先）
     *
     * ■ 何を確かめるか
     *   「保存した入力をそのまま使い、両roleが送信予約まで通ること」です。
     *   なるさんから出た必要な試験を、そのまま並べました。
     *     ・派生値を含む入力を保存し、時間を進めても、両roleの送信本文が
     *       保存本文と一致する
     *     ・snapshotが無い初回から、凍結→予約→送信直前まで通る
     *     ・snapshotがある2ndも、再計算せず同じ固定入力で予約できる
     *     ・実AIを使わなくても、送信口の手前まで検証できる
     *
     *   あわせて、<b>直す前の壊れ方そのもの</b>も試験にしました。
     *   作り直す側（assembleFreshPredictionRequest）を2回呼ぶと共通hashが変わります。
     *   変わることが悪いのではなく、<b>それを送信経路で使っていたこと</b>が
     *   問題でした。送信経路は保存物しか読まない、という対比を出します。
     *
     * ■ 書き込みについて
     *   <b>全部ひとつのトランザクションで行い、最後に必ずロールバックします。</b>
     *   DBへ残る書き込みは0件です。実AIは呼びません。
     */
    private function runOneBuildTest(): void
    {
        self::loadPredictionClasses();
        $date = (string) ($this->option('date') ?: '2026-10-18');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 固定入力をそのまま送る（007のご指摘2）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ 監査行は試験用に作ったものです（実開催の実時刻ではありません）');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->warn('  ※ 実AIは呼びません');
        $this->line('');

        $A = \App\Http\Controllers\Api\AiController::class;
        $T = \App\Http\Controllers\Api\BaganrikiTime::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;

        $r = \DB::table('t_horse_odds_finder_races')->where('date', $date)
                ->orderBy('basho')->orderBy('race')->first();
        if ($r === null) { $this->error('  × 対象レースがありません: ' . $date); return; }
        $raceId = $A::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
        $st = (string) $r->start_time;
        if (strlen($st) === 5) { $st .= ':00'; }
        $anchorUs = $T::us($r->date . 'T' . $st . '+09:00');
        if ($anchorUs === null) { $this->error('  × 発走時刻を読めません'); return; }
        $this->line('  対象レース : ' . $raceId . '  発走 ' . $st);
        $this->line('');

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-8s %-54s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true)
                           . ' 期待=' . var_export($want, true))));
        };

        // 取得監査の行を作ります（--prediction-acq-test と同じ作り方）
        $toDb = static function (int $us): string {
            return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
        };
        $rows = [];
        foreach ($T::INPUT_SLOTS as $slot) {
            $pl = [];
            foreach (\DB::table('t_horse_odds_finder_odds')
                        ->where('date', $r->date)->where('kaisuu', $r->kaisuu)
                        ->where('basho', $r->basho)->where('day', $r->day)
                        ->where('race', (int) $r->race)
                        ->where('minutes_before_start', $slot)
                        ->orderBy('num')->get(['num','odds','fuku_min','fuku_max']) as $o) {
                $pl[] = ['num' => (int) $o->num, 'tan' => (string) $o->odds,
                         'fuku_min' => (string) $o->fuku_min,
                         'fuku_max' => (string) $o->fuku_max];
            }
            if ($pl === []) { continue; }
            $raw = (string) json_encode($pl, JSON_UNESCAPED_UNICODE);
            $sch = $T::slotScheduled($anchorUs, $slot);
            $rows[] = [
                'race_id' => $raceId, 'slot' => $slot,
                'batch_id' => 'ONEBUILD-' . $raceId . '-' . $slot . '-'
                            . substr(bin2hex(random_bytes(3)), 0, 6),
                'slot_scheduled_at'    => $toDb($sch),
                'source_updated_at'    => null,
                'request_started_at'   => $toDb($sch + 500000),
                'response_received_at' => $toDb($sch + 1500000),
                'available_at'         => $toDb($sch + 2500000),
                'raw_payload'          => $raw,
                'payload_hash'         => hash('sha256', $raw),
                'validation_result'    => 'ADOPTED',
                'rejection_reason'     => null,
                'committed'            => 1,
                'created_at'           => $toDb($sch + 2500000),
            ];
        }
        if ($rows === []) { $this->error('  × 監査行を1件も作れません'); return; }

        // 旗を一時的に動かします（終わったら必ず戻します）
        $keep = [];
        foreach (['BAGANRIKI_PREDICTION_PROMPT', 'BAGANRIKI_PREDICTION_QUEUE'] as $k) {
            $keep[$k] = [getenv($k), $_ENV[$k] ?? null, $_SERVER[$k] ?? null];
        }
        $setEnv = static function (string $k, string $v) {
            putenv($k . '=' . $v); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        };

        $ok = true;
        \DB::beginTransaction();
        try {
            $setEnv('BAGANRIKI_PREDICTION_PROMPT', '1');
            $setEnv('BAGANRIKI_PREDICTION_QUEUE', '1');
            \DB::table('baganriki_acquisition_batches')->insert($rows);
            $this->info('── ① 監査行を入れました（' . count($rows) . ' 件）──');

            $ctrl = app($A);

            // ══════════════════════════════════════════════════════════
            // ② 1回目。固定入力が無いので、ここで1回だけ作ります
            // ══════════════════════════════════════════════════════════
            $this->info('── ② 1回目の組み立て（固定入力を作ります）──');
            $a1 = $ctrl->assemblePredictionRequest($r->date, $r->kaisuu, $r->basho,
                                          $r->day, $r->race, '1st');
            if (!($a1['ok'] ?? false)) {
                $ok = false;
                $this->error('  × 1回目が作れません: ' . ($a1['code'] ?? '')
                           . ' ' . ($a1['reason'] ?? ''));
            } else {
                $this->line('  snapshot_hash : ' . $a1['snapshot_hash']);
                $this->line('  user bytes    : ' . $a1['user_bytes']);
                foreach ((array) ($a1['notes'] ?? []) as $nt) {
                    $this->line('  note          : ' . $nt);
                }
            }
            $check('Y-01', '1回目で固定入力ができる', $a1['ok'] ?? false, true);
            $check('Y-02', '保存物から読んだ印が立つ', $a1['from_saved'] ?? null, true);

            $snapRow = \DB::table('baganriki_snapshots')
                         ->where('race_id', $raceId)->where('generation', 0)->first();
            $check('Y-03', '固定入力が1件だけできている',
                (int) \DB::table('baganriki_snapshots')->where('race_id', $raceId)
                         ->where('generation', 0)->count(), 1);
            $check('Y-04', '保存された本文と送信本文のバイト列が同じ',
                (string) ($snapRow->input_json_1st ?? ''), (string) ($a1['user'] ?? ''));
            $check('Y-05', '保存された共通hashと送信本文の共通hashが同じ',
                (string) ($snapRow->snapshot_hash ?? ''),
                (string) ($a1['snapshot_hash'] ?? ''));

            // ══════════════════════════════════════════════════════════
            // ③ 時間を進めてから2回目。作り直さないので1バイトも変わりません
            // ══════════════════════════════════════════════════════════
            $this->info('── ③ 時間を進めてから2回目（作り直しません）──');
            usleep(1100000);                      // 1.1秒。派生の計算時刻が変わる間隔です
            $a1b = $ctrl->assemblePredictionRequest($r->date, $r->kaisuu, $r->basho,
                                           $r->day, $r->race, '1st');
            $check('Y-06', '2回目も作れる', $a1b['ok'] ?? false, true);
            $check('Y-07', '2回目の本文が1回目と1バイトも違わない',
                (string) ($a1b['user'] ?? 'x'), (string) ($a1['user'] ?? 'y'));
            $check('Y-08', '2回目の共通hashが1回目と同じ',
                (string) ($a1b['snapshot_hash'] ?? 'x'),
                (string) ($a1['snapshot_hash'] ?? 'y'));
            $check('Y-09', '2回目は固定入力を作り直していない',
                (int) \DB::table('baganriki_snapshots')->where('race_id', $raceId)
                         ->where('generation', 0)->count(), 1);

            // ══════════════════════════════════════════════════════════
            // ④ 2nd も同じ固定入力から。再計算しません
            // ══════════════════════════════════════════════════════════
            $this->info('── ④ 2nd も同じ固定入力から ──');
            $a2 = $ctrl->assemblePredictionRequest($r->date, $r->kaisuu, $r->basho,
                                          $r->day, $r->race, '2nd');
            $check('Y-10', '2nd も作れる', $a2['ok'] ?? false, true);
            $check('Y-11', '2nd も保存物から読んでいる', $a2['from_saved'] ?? null, true);
            $check('Y-12', '2nd の共通hashが 1st と同じ',
                (string) ($a2['snapshot_hash'] ?? 'x'),
                (string) ($a1['snapshot_hash'] ?? 'y'));
            $check('Y-13', '2nd の本文が保存された 2nd の本文と同じ',
                (string) ($snapRow->input_json_2nd ?? ''), (string) ($a2['user'] ?? ''));
            $check('Y-14', '2nd の ai_role が 2nd になっている',
                (string) ((array) ($a2['input'] ?? []))['ai_role'] ?? '', '2nd');

            // ══════════════════════════════════════════════════════════
            // ⑤ 送信予約が hash 不一致で止まらないこと
            // ══════════════════════════════════════════════════════════
            $this->info('── ⑤ 送信予約（SNAPSHOT_MISMATCH で止まらないこと）──');
            $q1 = $ctrl->reserveAiSlot($r->date, $r->kaisuu, $r->basho,
                                          $r->day, $r->race, '1st', $a1);
            $this->line('  1st の予約 : code=' . (string) ($q1['code'] ?? '')
                      . '  ' . (string) ($q1['reason'] ?? ''));
            $check('Y-15', '1st の予約が hash 不一致で止まらない',
                ((string) ($q1['code'] ?? '') === 'SNAPSHOT_MISMATCH'), false);
            $q2 = $ctrl->reserveAiSlot($r->date, $r->kaisuu, $r->basho,
                                          $r->day, $r->race, '2nd', $a2);
            $this->line('  2nd の予約 : code=' . (string) ($q2['code'] ?? '')
                      . '  ' . (string) ($q2['reason'] ?? ''));
            $check('Y-16', '2nd の予約も hash 不一致で止まらない',
                ((string) ($q2['code'] ?? '') === 'SNAPSHOT_MISMATCH'), false);
            $this->warn('  ※ このレースは発走がとうに過ぎているので、');
            $this->warn('     予約そのものは期限の理由で断られます。それが正しい動きです。');
            $this->warn('     ここで見たいのは「hash が合わないせいで断られていないこと」です。');

            // ══════════════════════════════════════════════════════════
            // ⑥ 直す前の壊れ方（作り直す側は、呼ぶたびに hash が変わる）
            // ══════════════════════════════════════════════════════════
            $this->info('── ⑥ 作り直す側は呼ぶたびに変わります（だから送信に使いません）──');
            $f1 = $ctrl->assembleFreshPredictionRequest($r->date, $r->kaisuu, $r->basho,
                                           $r->day, $r->race, '1st');
            usleep(1100000);
            $f2 = $ctrl->assembleFreshPredictionRequest($r->date, $r->kaisuu, $r->basho,
                                           $r->day, $r->race, '1st');
            $this->line('  作り直し1回目 : ' . substr((string) ($f1['snapshot_hash'] ?? ''), 0, 24));
            $this->line('  作り直し2回目 : ' . substr((string) ($f2['snapshot_hash'] ?? ''), 0, 24));
            $check('Y-17', '作り直す側は2回で共通hashが変わる（これが前回の原因）',
                ((string) ($f1['snapshot_hash'] ?? 'a')
                 === (string) ($f2['snapshot_hash'] ?? 'b')), false);
            $check('Y-18', '作り直す側には保存物の印が立たない',
                $f1['from_saved'] ?? null, false);
            $a1c = $ctrl->assemblePredictionRequest($r->date, $r->kaisuu, $r->basho,
                                           $r->day, $r->race, '1st');
            $check('Y-19', '作り直しても、送信経路の本文は動かない',
                (string) ($a1c['snapshot_hash'] ?? 'x'),
                (string) ($a1['snapshot_hash'] ?? 'y'));
            $check('Y-20', '固定入力はいまも1件のまま',
                (int) \DB::table('baganriki_snapshots')->where('race_id', $raceId)
                         ->where('generation', 0)->count(), 1);

        } catch (\Throwable $e) {
            $ok = false; $fail++;
            $this->error('  × 例外: ' . $e->getMessage());
            $this->error('     ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
            foreach ($keep as $k => $v) {
                if ($v[0] === false) { putenv($k); } else { putenv($k . '=' . $v[0]); }
                if ($v[1] === null) { unset($_ENV[$k]); } else { $_ENV[$k] = $v[1]; }
                if ($v[2] === null) { unset($_SERVER[$k]); } else { $_SERVER[$k] = $v[2]; }
            }
        }

        $this->line('');
        $this->line(sprintf('  ロールバック後  監査 %d 件／snapshot %d 件／attempts %d 件',
            (int) \DB::table('baganriki_acquisition_batches')->count(),
            (int) \DB::table('baganriki_snapshots')->count(),
            (int) \DB::table('baganriki_ai_attempts')->count()));
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(($fail === 0 && $ok)
            ? ' 固定入力をそのまま送れました（DBへ残る書き込み0件）'
            : ' 通らなかった箇所があります。上の × をご確認ください');
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-WIRETEST  送信口を試験用に置き換えて接続を通す
     * ═══════════════════════════════════════════════════════════════════════
     * 10月20日007の「次の提出で確認したい最小セット」E：
     *   「上限前呼出し→上限後凍結、片側失敗、応答期限到達、
     *     プロセス停止後の復旧を通す実起動経路。」
     *   「まず送信口を試験用に置き換えて接続を通し、
     *     その後で実AIの時間と出力品質を測ってください。」
     *
     * ■ どうやって実AIを呼ばずに実経路を通すか
     *   送信口（Httpクライアント）そのものを偽の応答に差し替えます。
     *   Laravel の Http::fake() は facade の下に入るので、
     *   <b>本番のコードを1行も変えずに</b>、
     *     AnthropicService::sendWithRetry（1st）
     *     Http::post('https://api.deepseek.com/...')（2nd）
     *   の両方が偽応答を受け取ります。
     *   通るのは本番の関数です（afterAiResponseReceived →
     *   inspectAiResponse → sendCorrectionRequest → callPredictionAi →
     *   acceptedHorsesOf → publishWhenReady）。
     *   実AIへの通信は0回です（偽の送信口が何回呼ばれたかも数えます）。
     *
     * ■ 書き込みについて
     *   全部ひとつのトランザクションで行い、最後に必ずロールバックします。
     */
    private function runWireTest(string $root): void
    {
        // @ANCHOR-20261023-COST-FIXPOLICY  この試験は契約どおりの訂正の経路を見るので、
        //   訂正の方針を contract にして通します（本番の既定は missing）
        putenv('BAGANRIKI_PREDICTION_FIX_POLICY=contract');
        $_ENV['BAGANRIKI_PREDICTION_FIX_POLICY'] = 'contract';
        $_SERVER['BAGANRIKI_PREDICTION_FIX_POLICY'] = 'contract';
        self::loadPredictionClasses();
        $vDir = rtrim($root, '/') . '/validation/normal';
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 送信口を試験用に置き換えて接続を通す（007のE）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  資料     : ' . $vDir);
        $this->warn('  ※ 実AIは1回も呼びません（送信口を偽応答に差し替えます）');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');
        $need = ['MIXED_N16_1st_input.json', 'MIXED_N16_1st_output.json',
                 'MIXED_N16_2nd_input.json', 'MIXED_N16_2nd_output.json'];
        foreach ($need as $f) {
            if (!is_file($vDir . '/' . $f)) {
                $this->error('  × ' . $vDir . '/' . $f . ' がありません');
                return;
            }
        }

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-8s %-52s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true)
                             . ' 期待=' . var_export($want, true))));
        };
        $rd = static fn(string $f) => json_decode((string) file_get_contents($f), true);
        $j  = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $W     = \App\Http\Controllers\Api\BaganrikiWorker::class;
        // @ANCHOR-20261021-PREDICTION-SETTLE  採否確定の印を見るために使います
        $A     = \App\Http\Controllers\Api\AiController::class;
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;
        $HTTP  = \Illuminate\Support\Facades\Http::class;

        $in1  = $rd($vDir . '/MIXED_N16_1st_input.json');
        $out1 = $rd($vDir . '/MIXED_N16_1st_output.json');
        $in2  = $rd($vDir . '/MIXED_N16_2nd_input.json');
        $out2 = $rd($vDir . '/MIXED_N16_2nd_output.json');

        // ── 偽の送信口 ───────────────────────────────────────────────
        // ══════════════════════════════════════════════════════════════
        //   【ここは1回だけ登録します】Http::fake() は呼ぶたびに
        //   <b>積み重なります</b>（置き換えではありません）。
        //   Laravel は登録した順に全部呼んで、最初に応答を返したものを
        //   使うので、2回目以降に登録した偽応答は<b>使われません。</b>
        //   10月20日の最初の試作でこれを踏みました（1回目の応答が
        //   ずっと返り続け、片側失敗も通信不能も再現できていませんでした）。
        //   なので登録は1回だけにして、<b>振る舞いは変数で切り替えます。</b>
        $calls = [];
        $mode  = 'ok';
        $doc   = [];
        $docFix = [];       // @ANCHOR-20261021-PREDICTION-FIXPROVE  訂正で返す本文
        $bodyOf = function (array $d) use ($j, $BEGIN, $END): string {
            return $BEGIN . $j($d) . $END;
        };
        $seq2 = 0;          // @ANCHOR-20261021-PREDICTION-FIXPROVE  何回目の送信か
        $HTTP::fake(function ($request)
                    use (&$calls, &$mode, &$doc, &$docFix, &$seq2, $bodyOf, $HTTP) {
            $url = (string) $request->url();
            $calls[] = $url;
            $seq2++;
            // @ANCHOR-20261021-PREDICTION-FIXPROVE  2回目以降（＝訂正）は別の本文を返します
            //   初回と訂正に同じ応答を返すと、訂正で直ったことを確かめられません。
            if ($docFix !== [] && $seq2 >= 2) { $doc = $docFix; }
            if ($mode === 'down') {
                throw new \Illuminate\Http\Client\ConnectionException(
                    '試験用：送信口につながりません');
            }
            if ($mode === 'http500') {
                return $HTTP::response('{"error":"試験用"}', 500);
            }
            $body = $bodyOf($doc);
            if (strpos($url, 'api.deepseek.com') !== false) {
                return $HTTP::response(['choices' => [['message' =>
                    ['content' => $body],
                    'finish_reason' => ($mode === 'truncated') ? 'length' : 'stop']]], 200);
            }
            return $HTTP::response([
                'content' => [['type' => 'text', 'text' => $body]],
                'stop_reason' => ($mode === 'truncated') ? 'max_tokens' : 'end_turn',
            ], 200);
        });
        // $mode: 'ok' 正しい応答／'http500' 明確な失敗／'down' 通信できない
        //        'truncated' 途中で切れた（complete=false）
        $port = function (string $m, array $d = [], array $f = [])
                use (&$calls, &$mode, &$doc, &$docFix, &$seq2): void {
            $mode = $m; $doc = $d; $docFix = $f; $calls = []; $seq2 = 0;
        };
        $sentTo = function () use (&$calls): string {
            if ($calls === []) { return '（送信なし）'; }
            $last = (string) $calls[count($calls) - 1];
            if (strpos($last, 'api.deepseek.com') !== false) { return 'DeepSeek'; }
            if (strpos($last, 'api.anthropic.com') !== false) { return 'Anthropic'; }
            return $last;
        };

        // ── 凍結入力と初回の枠を「本番の関数で」作る道具 ─────────────
        //   枠は reserve → allowSend → receive の本番3段で作ります。
        //   手で RECEIVED を入れないので、予約の見張りも通ります。
        $seq = 0;
        $mk = function (array $doc1, array $doc2, array $opt = [])
              use ($in1, $in2, $j, $BEGIN, $END, &$seq, $W): array {
            $seq++;
            $now   = $W::nowUs();
            $sales = $now + ((int) ($opt['sales_in_sec'] ?? 3600)) * 1000000;
            $nums  = [];
            foreach ((array) $in1['runners'] as $r) { $nums[] = (int) $r['horse_no']; }
            $sid = \DB::table('baganriki_snapshots')->insertGetId([
                // @ANCHOR-20261021-PREDICTION-SENDFIRST  race_id は本番と同じ形にします
                //   sendInitialAiRequest は受領のあと、race_id を5つに分けて
                //   afterAiResponseReceived（検証→訂正→解決→公開）を呼びます。
                //   'WT-1' のような形だと分けられず、そこで止まっていました。
                //   試験の側を本番と同じ形（日付-回-場-日-R）にします。
                //   場コード99は実在しないので、本番のレースとは混ざりません。
                'race_id' => '20261018-9-99-9-' . $seq,
                // @ANCHOR-20261021-PREDICTION-SENDFIRST  初回送信は本番の世代（0）を見ます
                'generation' => (($opt['gen0'] ?? false) === true) ? 0 : 900,
                'run_id' => 'WT',
                'snapshot_hash'   => (string) $in1['snapshot_hash'],
                'input_json_1st'  => $j($in1), 'input_json_2nd' => $j($in2),
                'frozen_nums'     => $j($nums),
                'allocation_mode' => (string) $in1['allocation_mode'],
                'mode'            => (string) $in1['mode'],
                'mode_status'     => (string) $in1['mode_status'],
                'target_count'    => (int) $in1['target_count'],
                'quota_1st'       => $j($in1['quota']),
                'sales_close_at'  => $W::toDb($sales),
                'created_at'      => $W::toDb($now),
            ]);
            \DB::table('baganriki_live_states')->insert([
                'snapshot_id' => $sid, 'cancellations' => $j([]),
                'active_count' => count($nums),
                'live_target_count' => (int) $in1['target_count'],
                'live_quota' => $j($in1['quota']), 'live_close' => null,
                'state_revision' => 0, 'response_revision' => 0,
                'publication_revision' => 0, 'latest_publication_id' => null,
                'updated_at' => $W::toDb($now),
            ]);
            $tok = [];
            // @ANCHOR-20261021-PREDICTION-SENDFIRST  枠を1つも作らない指定
            if (($opt['no_attempt'] ?? false) === true) {
                return ['sid' => (int) $sid, 'nums' => $nums, 'token' => []];
            }
            foreach ([['1st', $doc1], ['2nd', $doc2]] as $pair) {
                [$role, $doc] = $pair;
                if ($doc === []) { continue; }       // 枠を作らない role
                $r = $W::reserve((int) $sid, $role, $W::FIRST, $W::nowUs());
                if (!$r['ok']) { continue; }
                $tok[$role] = (string) $r['token'];
                $W::allowSend((string) $r['token'], $W::nowUs());
                if (($opt['leave_sent'] ?? null) === $role) { continue; }  // 送信済みのまま
                $W::receive((string) $r['token'], $BEGIN . $j($doc) . $END,
                            !(($opt['incomplete'] ?? null) === $role), $W::nowUs());
            }
            return ['sid' => (int) $sid, 'nums' => $nums, 'token' => $tok];
        };

        // 1頭落ちた初回（訂正が必要になります）
        $broken = $out1;
        $goneNo = (int) $broken['horses'][2]['horse_no'];
        array_splice($broken['horses'], 2, 1);

        $ok = true;
        \DB::beginTransaction();
        try {
            $ctrl = app(\App\Http\Controllers\Api\AiController::class);
            $D = ['2026-10-18', '4', '04', '2', 1];   // race_id を作るためだけの引数

            // ── ① 初回→検証→訂正→解決→公開を、本番の関数で通す ──────
            $this->info('── ① 初回→検証→訂正→解決→公開（偽の送信口で実経路）──');
            $port('ok', $out1);                       // 訂正では正しい全頭を返す
            $m = $mk($broken, $out2);
            $q = ['skip' => false, 'ok' => true, 'snapshot_id' => $m['sid']];
            $r = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '1st', $q);
            $check('E-01', '接続が動いた', $r['ran'], true);
            $check('E-02', '初回を検証した（直すところがある）',
                   (bool) ($r['inspect']['needs_fix'] ?? null), true);
            $check('E-03', '欠落しても他の15頭は残した',
                   (int) ($r['inspect']['normal'] ?? -1), 15);
            $check('E-04', '訂正を実際に送った', (bool) ($r['fix']['sent'] ?? null), true);
            $check('E-05', '偽の応答を受け取れた', (bool) ($r['fix']['ok'] ?? null), true);
            $check('E-06', '送信口が呼ばれた回数は1回', count($calls), 1);
            $this->line('      送信先 : ' . $sentTo());
            $check('E-07', '1st の送信先は Anthropic', $sentTo(), 'Anthropic');
            $check('E-08', '訂正の枠が RECEIVED になった',
                   (string) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $m['sid'])->where('role', '1st')
                       ->where('attempt_no', 1)->value('status'), $W::RECEIVED);
            $acc = $ctrl->acceptedHorsesOf($m['sid'], '1st');
            $this->line('      解決 : ' . (string) ($acc['code'] ?? '')
                      . '／' . mb_strimwidth((string) ($acc['reason'] ?? ''), 0, 60, '…'));
            $check('E-09', '訂正を当てて全16頭そろう',
                   count((array) ($acc['merged'] ?? [])), 16);
            $check('E-10', '欠落していた馬を救済した',
                   in_array($goneNo, array_map('intval',
                       (array) ($acc['rescued'] ?? [])), true), true);
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-SETTLE  公開は両roleの採否確定の後です
            // ══════════════════════════════════════════════════════════
            //   【試験の前提が変わりました】
            //   これまでは 1st だけ接続を通し、2nd は枠を RECEIVED に
            //   しただけで「両roleが終端」と数えられていました。
            //   010の2-4 のとおり、RECEIVED は「HTTPが返った」だけです。
            //   本番は両roleが afterAiResponseReceived を通るので、試験もそう
            //   します。そのうえで<b>1stだけでは公開しない</b>ことを
            //   先に確かめます（これが010の2-4 の競合そのものです）。
            $check('E-11-0', '1st の採否が確定した',
                   (bool) ($r['settled']['marked'] ?? false), true);
            // $check('E-11', '両roleが終端なので公開した',
            $check('E-11-1', '1st だけでは公開しない（2nd は受領済みだが採否未確定）',
                   (string) ($r['publish']['code'] ?? ''), 'WAITING');
            $check('E-11-2', '待っている相手は 2nd の採否',
                   (string) ($r['publish']['reason'] ?? ''),
                   'まだ終端でないroleがあります: 2nd（採否未確定）');
            $r1b = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '2nd', $q);
            $check('E-11-3', '2nd の採否も確定した',
                   (bool) ($r1b['settled']['marked'] ?? false), true);
            $check('E-11', '両roleの採否が確定したので公開した',
                   (string) ($r1b['publish']['code'] ?? ''), 'PUBLISHED');
            $this->line('      公開候補 : ' . (int) ($r1b['publish']['candidates'] ?? 0) . ' 頭');

            // ── ①-2 2nd（DeepSeek）側の送信口も同じように通す ───────────
            $this->info('── ①-2 2nd 側の送信口（DeepSeek）──');
            $broken2 = $out2;
            $gone2   = (int) $broken2['horses'][1]['horse_no'];
            array_splice($broken2['horses'], 1, 1);
            $port('ok', $out2);
            $mb = $mk($out1, $broken2);
            $qb = ['skip' => false, 'ok' => true, 'snapshot_id' => $mb['sid']];
            $rb = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '2nd', $qb);
            $check('E-47', '2nd も訂正を実際に送った',
                   (bool) ($rb['fix']['sent'] ?? null), true);
            $check('E-48', '2nd の送信先は DeepSeek', $sentTo(), 'DeepSeek');
            $check('E-49', '2nd も偽の応答を受け取れた',
                   (bool) ($rb['fix']['ok'] ?? null), true);
            $accb = $ctrl->acceptedHorsesOf($mb['sid'], '2nd');
            $this->line('      解決 : ' . (string) ($accb['code'] ?? '')
                      . '／' . mb_strimwidth((string) ($accb['reason'] ?? ''), 0, 60, '…'));
            $check('E-50', '2nd も訂正を当てて全16頭そろう',
                   count((array) ($accb['merged'] ?? [])), 16);
            $check('E-51', '2nd の欠落馬を救済した',
                   in_array($gone2, array_map('intval',
                       (array) ($accb['rescued'] ?? [])), true), true);
            // @ANCHOR-20261021-PREDICTION-SETTLE  こちらも両roleの採否確定が必要です
            $check('E-52-1', '2nd だけでは公開しない',
                   (string) ($rb['publish']['code'] ?? ''), 'WAITING');
            $rb2 = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '1st', $qb);
            $check('E-52-2', '1st の採否も確定した',
                   (bool) ($rb2['settled']['marked'] ?? false), true);
            // $check('E-52', '2nd 側も公開まで進む',
            $check('E-52', '2nd 側から始めても、両roleの確定後に公開まで進む',
                   (string) ($rb2['publish']['code'] ?? ''), 'PUBLISHED');

            // ── ② 訂正は最大1回（2回目は送らない）─────────────────────
            $this->info('── ② 訂正は最大1回 ──');
            $before = count($calls);
            $insp = $ctrl->inspectAiResponse($m['sid'], '1st', 0);
            $fx2  = $ctrl->sendCorrectionRequest($m['sid'], '1st', $insp);
            $check('E-12', '2回目の訂正は送らない', (bool) $fx2['sent'], false);
            //   訂正の枠がすでにある、という理由です（fixAllowed）。
            $check('E-13', 'その理由が読める', (string) $fx2['code'], 'SLOT_TAKEN');
            $check('E-14', '送信口は増えない', count($calls), $before);

            // ── ③ 片側が失敗しても、もう片方で公開できる ────────────────
            $this->info('── ③ 片側失敗（HTTP 500）──');
            $port('http500');
            $m2 = $mk($broken, $out2);
            $q2 = ['skip' => false, 'ok' => true, 'snapshot_id' => $m2['sid']];
            $r2 = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '1st', $q2);
            $check('E-15', '訂正は送った', (bool) ($r2['fix']['sent'] ?? null), true);
            $check('E-16', '明確な失敗として扱う',
                   (string) ($r2['fix']['code'] ?? ''), 'API_ERROR');
            $check('E-17', '枠は FAILED（結果不明にしない）',
                   (string) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $m2['sid'])->where('role', '1st')
                       ->where('attempt_no', 1)->value('status'), $W::FAILED);
            $acc2 = $ctrl->acceptedHorsesOf($m2['sid'], '1st');
            $check('E-18', '初回の正常部分は残る（15頭）',
                   count((array) ($acc2['merged'] ?? [])), 15);
            // @ANCHOR-20261021-PREDICTION-SETTLE  訂正が失敗しても、採否は確定します
            //   初回が RECEIVED で訂正が FAILED なら、初回の正常部分を
            //   採って確定です。2nd は受領済みなので、こちらも接続を通します。
            $check('E-19-0', '訂正が失敗しても 1st の採否は確定する',
                   (bool) ($r2['settled']['marked'] ?? false), true);
            $check('E-19-1', '1st だけでは公開しない',
                   (string) ($r2['publish']['code'] ?? ''), 'WAITING');
            $r2b = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '2nd', $q2);
            // $check('E-19', '失敗しても公開まで進む',
            $check('E-19', '訂正が失敗しても、両roleの確定後に公開まで進む',
                   (string) ($r2b['publish']['code'] ?? ''), 'PUBLISHED');

            // ── ④ 通信できないときは「結果不明」（再送しない）───────────
            $this->info('── ④ 通信できない（結果不明）──');
            $port('down');
            $m3 = $mk($broken, $out2);
            $insp3 = $ctrl->inspectAiResponse($m3['sid'], '1st', 0);
            $fx3   = $ctrl->sendCorrectionRequest($m3['sid'], '1st', $insp3);
            $check('E-20', '送信はした', (bool) $fx3['sent'], true);
            $check('E-21', '通信できないので CONNECTION',
                   (string) $fx3['code'], 'CONNECTION');
            $check('E-22', '枠は結果不明（UNKNOWN）',
                   (string) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $m3['sid'])->where('role', '1st')
                       ->where('attempt_no', 1)->value('status'), $W::UNKNOWN);
            $check('E-23', '自動で再送していない', count($calls), 1);

            // ── ⑤ 途中で切れた応答は選出に使わない ──────────────────────
            $this->info('── ⑤ 途中で切れた応答（complete=false）──');
            $port('truncated', $out1);
            $m4 = $mk($broken, $out2);
            $insp4 = $ctrl->inspectAiResponse($m4['sid'], '1st', 0);
            $fx4   = $ctrl->sendCorrectionRequest($m4['sid'], '1st', $insp4);
            $check('E-24', '切れていても原応答は保存する', (bool) $fx4['ok'], true);
            $check('E-25', 'complete=false で保存している',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $m4['sid'])->where('role', '1st')
                       ->where('attempt_no', 1)->value('complete'), 0);
            $u4 = $W::usable($m4['sid'], '1st');
            $check('E-26', '選出に使うのは初回のほう',
                   (int) ($u4['attempt_no'] ?? -1), 0);

            // ── ⑥ 応答期限まで時間が無ければ訂正を送らない ──────────────
            $this->info('── ⑥ 応答期限到達 ──');
            $port('ok', $out1);
            $m5 = $mk($broken, $out2, ['sales_in_sec' => 100]);  // 応答期限まで約10秒
            $n5 = (int) \DB::table('baganriki_ai_attempts')
                      ->where('snapshot_id', $m5['sid'])->count();
            $insp5 = $ctrl->inspectAiResponse($m5['sid'], '1st', 0);
            $fx5   = $ctrl->sendCorrectionRequest($m5['sid'], '1st', $insp5);
            $check('E-27', '残り時間が無いので送らない', (bool) $fx5['sent'], false);
            $check('E-28', 'その理由が読める', (string) $fx5['code'], 'NO_TIME_FOR_FIX');
            $check('E-29', '空振りの枠を作らない',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $m5['sid'])->count(), $n5);
            $check('E-30', '送信口は呼ばれない', count($calls), 0);

            // ── ⑦ 公開期限を過ぎたら公開しない ──────────────────────────
            $this->info('── ⑦ 公開期限到達 ──');
            $port('ok', $out1);
            $m6 = $mk($out1, $out2, ['sales_in_sec' => 30]);  // 公開期限はもう過ぎている
            $p6 = $ctrl->publishWhenReady($m6['sid']);
            $check('E-31', '公開期限を過ぎたら公開しない', (bool) ($p6['ok'] ?? false), false);
            $check('E-32', 'その理由が読める',
                   (string) ($p6['code'] ?? ''), 'DEADLINE_PASSED');

            // ── ⑧ プロセス停止後の復旧（再送しない）──────────────────────
            $this->info('── ⑧ プロセス停止後の復旧 ──');
            $port('ok', $out1);
            $m7 = $mk($broken, $out2, ['leave_sent' => '1st']);  // 1stは送信済みのまま
            $check('E-33', '落ちる前は送信済み（SENT）',
                   (string) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $m7['sid'])->where('role', '1st')
                       ->where('attempt_no', 0)->value('status'), $W::SENT);
            $rs = $W::resume($m7['sid'], $W::nowUs());
            $check('E-34', '後始末をした', (bool) $rs['ok'], true);
            $check('E-35', '送信済みは結果不明にする',
                   (array) ($rs['unknown'] ?? []), ['1st#0']);
            $check('E-36', '初回の送信はやり直さない', count($calls), 0);
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-NOTHINGTOADOPT  採るものが無い role
            // ══════════════════════════════════════════════════════════
            //   1st は結果不明（UNKNOWN_RESULT）で終わりました。
            //   この役には診断も訂正も採否も、やることが残っていません。
            //   印を待っても永久に付かない（印を付けるのは受領に成功した
            //   ときだけ）ので、「採るものが無い」ことを確定として
            //   数えます。RECEIVED のときは数えません（010の2-4）。
            $sv1st = $A::roleSettled($m7['sid'], '1st');
            $check('E-37-0', '結果不明の役は「採るものが無い」で確定',
                   [$sv1st['settled'], (string) ($sv1st['by'] ?? '')],
                   [true, 'NOTHING_TO_ADOPT']);
            $p7w = $ctrl->publishWhenReady($m7['sid']);
            $check('E-37-1', 'それでも 2nd の採否が未確定なら公開しない',
                   (string) ($p7w['code'] ?? ''), 'WAITING');
            $q7 = ['skip' => false, 'ok' => true, 'snapshot_id' => $m7['sid']];
            $r7b = $ctrl->afterAiResponseReceived($D[0], $D[1], $D[2], $D[3], $D[4], '2nd', $q7);
            $check('E-37-2', '2nd の採否が確定した',
                   (bool) ($r7b['settled']['marked'] ?? false), true);
            $p7 = $ctrl->publishWhenReady($m7['sid']);
            // $check('E-37', '結果不明も終端なので公開まで進む',
            $check('E-37', '結果不明の役があっても公開まで進む',
                   in_array((string) ($p7['code'] ?? ''),
                            ['PUBLISHED', 'ALREADY'], true), true);
            $check('E-38', '片方だけなので加点しない',
                   count(array_filter((array) ($p7['candidates'] ?? []),
                       static fn($c) => ((float) ($c['bonus'] ?? 0)) > 0.0)), 0);

            // ══════════════════════════════════════════════════════════
            //   その経路がこれです。固定入力から、予約→送信許可→送信→
            //   受領→検証→訂正→解決→公開まで一続きで通します。
            //   初回の送信口も偽の応答に差し替えています（実AIは呼びません）。
            $this->info('── ⑧-2 固定入力から初回を送る（キャッシュ後のやり直し）──');
            //
            //   そのとおりです。しかも初回（$broken）と訂正（$out1）は
            //   band_ranks が同じ中身でした。保持できていなくても
            //   気づけない試験でした。
            //   いまは、<b>訂正の側で、訂正の対象ではない帯の並びを
            //   わざと逆順にします。</b>それでも初回の並びが残ることを、
            //   帯ごとに1つずつ突き合わせて確かめます。
            //
            //   どの帯が訂正の対象かは、①で作った同じ初回応答から
            //   実際の repair_scope を取って決めます（決め打ちしません）。
            $piF  = $ctrl->inspectAiResponse($m['sid'], '1st', $W::FIRST);
            $badF = (array) \App\Http\Controllers\Api\AiController
                        ::invalidBandsOf($piF['repair_scope'] ?? null);
            $flipBand = null;
            foreach (['A', 'B', 'D'] as $b) {
                if (in_array($b, $badF, true)) { continue; }   // 訂正の対象の帯
                if (count((array) ($out1['band_ranks'][$b] ?? [])) >= 2) {
                    $flipBand = $b; break;
                }
            }
            $check('E-58e-0', '訂正の対象外で2頭以上いる帯がある',
                   $flipBand !== null, true);
            $out1s = $out1;
            if ($flipBand !== null) {
                $out1s['band_ranks'][$flipBand] = array_reverse(
                    array_values((array) $out1['band_ranks'][$flipBand]));
            }
            $port('ok', $broken, $out1s);
            $mf = $mk([], [], ['no_attempt' => true, 'gen0' => true]);  // 枠は作りません
            $check('E-53', '初回の枠が1つも無い状態から始める',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $mf['sid'])->count(), 0);
            $f1 = $ctrl->sendInitialAiRequest($mf['sid'], '1st', ['test_ignore_flags' => true]);
            $check('E-54', '1st の初回を実際に送った', (bool) $f1['sent'], true);
            $check('E-55', '1st の初回を受け取れた', (bool) $f1['ok'], true);
            $check('E-56', '1st の送信先は Anthropic', $sentTo(), 'Anthropic');
            $check('E-57', '1st の初回の枠が RECEIVED になった',
                   (string) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $mf['sid'])->where('role', '1st')
                       ->where('attempt_no', 0)->value('status'), $W::RECEIVED);
            $check('E-58', '検証→訂正まで進んだ（訂正の枠ができている）',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $mf['sid'])->where('role', '1st')
                       ->where('attempt_no', 1)->count(), 1);
            $port('ok', $out2);
            $f2 = $ctrl->sendInitialAiRequest($mf['sid'], '2nd', ['test_ignore_flags' => true]);
            $check('E-59', '2nd の初回も送った', (bool) $f2['sent'], true);
            $check('E-60', '2nd の送信先は DeepSeek', $sentTo(), 'DeepSeek');
            $check('E-61', '2nd の初回の枠も RECEIVED',
                   (string) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $mf['sid'])->where('role', '2nd')
                       ->where('attempt_no', 0)->value('status'), $W::RECEIVED);
            //   初回を受け取った時点で afterAiResponseReceived が中で公開まで
            //   進めるので、ここで呼び直すと「同じ intent は作り直さない」
            //   という契約どおり ALREADY が返ります。どちらも公開済みです。
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-FIXPROVE
            // ══════════════════════════════════════════════════════════
            $accF = $ctrl->acceptedHorsesOf($mf['sid'], '1st');
            $check('E-58b', '訂正を当てている',
                   (string) ($accF['code'] ?? ''), 'FIX_APPLIED');
            $check('E-58c', '欠落していた馬が戻って全16頭そろう',
                   count((array) ($accF['merged'] ?? [])), 16);
            $check('E-58d', '救済したのは欠落していた馬',
                   in_array($goneNo, array_map('intval',
                       (array) ($accF['rescued'] ?? [])), true), true);
            $usedF = $ctrl->markResponseUsed($mf['sid'], '1st', $accF);
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-BANDPROVE  帯ごとに1つずつ突き合わせます
            // ══════════════════════════════════════════════════════════
            // $check('E-58e', '他の帯の順位は保たれている',
            $firstRanks = [];
            foreach ((array) ($broken['band_ranks'] ?? []) as $b => $v) {
                $firstRanks[(string) $b] = array_map('intval', array_values((array) $v));
            }
            $keptAll = true; $keptDetail = [];
            foreach (['A', 'B', 'D'] as $b) {
                if (in_array($b, $badF, true)) { continue; }      // 訂正の対象
                if (!isset($firstRanks[$b]) || $firstRanks[$b] === []) { continue; }
                $got = array_map('intval',
                           array_values((array) ($usedF['band_ranks'][$b] ?? [])));
                $same = ($got === $firstRanks[$b]);
                $keptDetail[$b] = $same ? '一致' : '変わっています';
                if (!$same) { $keptAll = false; }
            }
            $check('E-58e', '訂正の対象外の帯は初回の並びと1つずつ一致する',
                   $keptAll, true);
            $check('E-58e-1', '突き合わせた帯が1つ以上ある',
                   count($keptDetail) >= 1, true);
            $check('E-58e-2', 'わざと逆順にした帯でも初回の並びが残る',
                   array_map('intval',
                       array_values((array) ($usedF['band_ranks'][$flipBand] ?? []))),
                   (array) ($firstRanks[$flipBand] ?? []));
            $check('E-58e-3', 'その帯の順位の出どころは「初回の保持」',
                   (string) ($usedF['rank_source'][$flipBand] ?? ''),
                   'PREVIOUS_KEPT');
            $check('E-58e-4', '訂正が違う並びを返していたことを記録に残す',
                   count(array_filter((array) ($usedF['rank_notes'] ?? []),
                       static fn($t) => strpos((string) $t,
                           '訂正は違う順位を返していました') !== false)) >= 1, true);
            $this->info('     対象外の帯の突き合わせ : '
                . json_encode($keptDetail, JSON_UNESCAPED_UNICODE)
                . ' ／ わざと逆順にした帯 : ' . (string) $flipBand
                . ' ／ 訂正の対象の帯 : '
                . (($badF === []) ? 'なし' : implode('・', $badF)));

            $pf = $ctrl->publishWhenReady($mf['sid']);
            $check('E-62', '両roleがそろって公開まで進んでいる',
                   in_array((string) ($pf['code'] ?? ''),
                            ['PUBLISHED', 'ALREADY'], true), true);
            $check('E-62b', '公開が1件できている',
                   ((string) \DB::table('baganriki_live_states')
                       ->where('snapshot_id', $mf['sid'])
                       ->value('latest_publication_id')) !== '', true);
            $check('E-62c', '公開の版が1になっている',
                   (int) \DB::table('baganriki_live_states')
                       ->where('snapshot_id', $mf['sid'])
                       ->value('publication_revision'), 1);
            $f3 = $ctrl->sendInitialAiRequest($mf['sid'], '1st', ['test_ignore_flags' => true]);
            $check('E-63', '枠があるなら二度と送らない', (bool) $f3['sent'], false);
            $check('E-64', 'その理由が読める', (string) $f3['code'], 'ALREADY');
            //   本番の既定（旗が下りている）では、そもそも送りません。
            $check('E-65', '旗が下りていれば送らない（本番の既定）',
                   (string) $this->withEnv(['BAGANRIKI_PREDICTION_PROMPT' => '0', 'BAGANRIKI_PREDICTION_QUEUE' => '0'],
                       fn() => $ctrl->sendInitialAiRequest($mf['sid'], '2nd'))['code'], 'FLAG_OFF');

            // 本番の呼出元が旗の検査を外していないこと（ソースで見ます）
            $srcF = (string) file_get_contents(
                (new \ReflectionClass(\App\Http\Controllers\Api\AiController::class))
                    ->getFileName());
            // @ANCHOR-20261023-NARU-RESUME  受領済みの再開・経路の所有でも同じ旗を見るので、
            //   「出現回数」ではなく「本番の呼出元が true を渡していないこと」を見ます。
            // $check('E-66', '本番の呼出元は旗の検査を外していない',
            $check('E-66', '本番の呼出元は旗の検査を外していない',
                   substr_count($srcF, "'test_ignore_flags' => true"), 0);
            $check('E-67', '巡回から初回送信を呼んでいる',
                   strpos($srcF, '$this->sendInitialAiRequest($sid, $role)') !== false, true);

            // ── ⑨ やり直しの巡回は初回の送信をしない ─────────────────────
            $this->info('── ⑨ やり直しの巡回（--prediction-sweep と同じ関数）──');
            //   巡回は「訂正の判定」と「公開」だけをやり直します。
            //   初回の送信はしません（二重に予想を呼ばないため）。
            //   枠の段が off のときは拾う対象を見ないので、
            //   <b>関数の中身そのもの</b>を見て確かめます。
            $srcW = (string) file_get_contents(
                (new \ReflectionClass(\App\Http\Controllers\Api\AiController::class))
                    ->getFileName());
            $rmW  = new \ReflectionMethod(
                \App\Http\Controllers\Api\AiController::class, 'sweepPendingPredictions');
            $linesW = explode("\n", $srcW);
            $bodyW  = implode("\n", array_slice($linesW, $rmW->getStartLine() - 1,
                $rmW->getEndLine() - $rmW->getStartLine() + 1));
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-SWEEPDESC
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-NARU-RESUME  コメント行を除いた本体で数えます（旧の行はコメントで残しているため）
            $codeW = implode("\n", array_filter(explode("\n", $bodyW),
                static fn($l) => !preg_match('/^\s*(\/\/|\*|\/\*)/', $l)));
            // $check('E-39', '巡回は訂正を1回だけ呼ぶ',
            $check('E-39', '巡回は訂正を直接は呼ばず、受領済みの再開（診断→訂正→採否確定）を1回呼ぶ',
                   [substr_count($codeW, '$this->sendCorrectionRequest('),
                    substr_count($codeW, '$this->resumeReceivedRole(')], [0, 1]);
            $check('E-39b', '巡回は初回の送信も呼ぶ（枠が無いか、予約済み・未送信のとき）',
                   substr_count($codeW, '$this->sendInitialAiRequest('), 1);
            // $check('E-39c', '初回を呼ぶのは枠が1つも無いときに限る',
            $check('E-39c', '初回を呼ぶのは枠が無いか、予約済み・未送信のときに限る',
                   strpos($codeW, '|| (string) $any->status === BaganrikiWorker::RESERVED) {') !== false, true);
            $check('E-40', '巡回は本文の組み立てを呼ばない',
                   strpos($bodyW, 'assemblePredictionRequest') === false, true);
            $check('E-41', '巡回は公開を呼ぶ',
                   substr_count($bodyW, '$this->publishWhenReady('), 1);
            $port('ok', $out1);        // ここから数え直します（⑧-2の送信を持ち越さない）
            $sw = $this->withEnv(['BAGANRIKI_PREDICTION_PROMPT' => '0', 'BAGANRIKI_PREDICTION_QUEUE' => '0'],
                fn() => $ctrl->sweepPendingPredictions());
            $check('E-42', '旗が下りていれば何も拾わない',
                   (int) ($sw['scanned'] ?? -1), 0);
            $check('E-43', '巡回しても送信口は呼ばれない', count($calls), 0);

            // ── ⑩ 上限前は固定しない／上限後は断らない ────────────────────
            $this->info('── ⑩ 上限前呼出し→上限後凍結 ──');
            $future = date('Y-m-d', time() + 86400 * 400);   // ずっと先の日付
            \DB::table('t_horse_odds_finder_races')->insert([
                'date' => $future, 'kaisuu' => '9', 'basho' => '99',
                'day' => '9', 'race' => 9, 'start_time' => '23:50:00',
            ]);
            $rf1 = $ctrl->resumePredictionFreeze($future, '9', '99', '9', 9);
            $check('E-44', '情報上限より前は固定しない',
                   (string) ($rf1['code'] ?? ''), 'TOO_EARLY');
            //   @ANCHOR-20261021-PREDICTION-SENDFIRST  この試験の凍結入力も
            //   race_id に 99 を含むので、like では当たってしまいます。
            //   仮レースの race_id だけを名指しで見ます。
            $futureId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
                $future, '9', '99', '9', 9);
            $check('E-45', 'そのとき凍結入力を作らない',
                   (int) \DB::table('baganriki_snapshots')
                       ->where('race_id', $futureId)->count(), 0);
            \DB::table('t_horse_odds_finder_races')
              ->where('date', $future)->where('basho', '99')
              ->update(['date' => date('Y-m-d', time() - 86400)]);
            $past = date('Y-m-d', time() - 86400);
            $rf2 = $ctrl->resumePredictionFreeze($past, '9', '99', '9', 9);
            $check('E-46', '上限を過ぎれば TOO_EARLY では止めない',
                   (string) ($rf2['code'] ?? '') !== 'TOO_EARLY', true);
            $this->line('      上限後のやり直しの結果 : '
                      . (string) ($rf2['code'] ?? '')
                      . '（このレースには実オッズが無いので、'
                      . 'ここで止まるのが正しい動きです）');

        } catch (\Throwable $e) {
            $ok = false;
            $this->error('  × 例外 ' . $e->getMessage()
                       . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
        }

        $this->line('');
        $this->line('  ロールバック後  snapshot '
            . (int) \DB::table('baganriki_snapshots')->where('run_id', 'WT')->count()
            . '／attempts '
            . (int) \DB::table('baganriki_ai_attempts')
                    ->whereIn('snapshot_id', function ($q) {
                        $q->select('id')->from('baganriki_snapshots')
                          ->where('run_id', 'WT');
                    })->count()
            . '／仮レース '
            . (int) \DB::table('t_horse_odds_finder_races')
                    ->where('basho', '99')->count());
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        if ($fail === 0 && $ok) {
            $this->info('════════════════════════════════════════════════════════');
            $this->info(' 送信口を差し替えた実経路が契約どおり動きました'
                      . '（実AI 0回・DBへ残る書き込み0件）');
            $this->info('════════════════════════════════════════════════════════');
        } else {
            $this->error('════════════════════════════════════════════════════════');
            $this->error(' 不合格があります');
            $this->error('════════════════════════════════════════════════════════');
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-TOKENS  トークン数を課金ゼロで実測します
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ■ 課金は発生しません
     *   Anthropic の `POST /v1/messages/count_tokens` は<b>料金がかかりません</b>。
     *   出力を生成しないためです（1分あたりのリクエスト数の制限だけがあります）。
     *   本文の生成（/v1/messages）は<b>1回も呼びません</b>。
     *
     * ■ 何を数えるか
     *   ① 代表レースの初回の入力（system ＋ user）を role ごとに
     *   ② 正常出力14本（出力トークンの目安）
     *   ③ 訂正の入力（固定入力 ＋ 初回応答 ＋ 依頼文の3つの合計）
     *
     * ■ DeepSeek 側
     *   DeepSeek には無料のトークン計数APIがありません。
     *   ここでは本文を<b>ファイルへ書き出す</b>だけにして、
     *   公開トークナイザで別途数えます（これも課金ゼロです）。
     *
     * 読むだけです。DBへは1行も書きません。
     */
    private function runTokenCount(string $root): void
    {
        self::loadPredictionClasses();
        $date = (string) ($this->option('date') ?: '2026-10-18');
        $outDir = rtrim((string) ($this->option('prediction-token-out') ?: ''), '/');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 トークン数の実測（課金ゼロ）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ 本文の生成（/v1/messages）は1回も呼びません');
        $this->warn('  ※ 使うのは count_tokens だけです（料金がかかりません）');
        $this->line('');

        $key = (string) (env('ANTHROPIC_API_KEY')
                      ?: config('services.anthropic.api_key'));
        if ($key === '') {
            $this->error('  × ANTHROPIC_API_KEY が読めません（.env をご確認ください）');
            return;
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TWOMODELS  両モデルで数えます
        // ══════════════════════════════════════════════════════════════
        //   ・Haiku 5.5 の $0.10／$0.50 は<b>入力10万トークンまで</b>。
        //     10万を超えると、そのリクエスト全体が $0.50／$2.50 になります。
        //   ・Haiku 5.5 はトークナイザが違うので、同じ本文でも数が変わります。
        //     旧モデルの数値は使えません。モデルを指定して数え直します。
        //   count_tokens はモデルを指定して呼べます。料金はかかりません。
        $MODELS = [
            'claude-haiku-4-5' => [
                'name' => 'Haiku 4.5（いま使っているもの）',
                'ctx'  => 200000,
                // [長文のしきい値, 短文入力, 短文出力, 長文入力, 長文出力]
                'price' => [null, 1.00, 5.00, 1.00, 5.00],
            ],
            'claude-haiku-5-5' => [
                'name' => 'Haiku 5.5（新しいもの）',
                'ctx'  => 1000000,
                'price' => [100000, 0.10, 0.50, 0.50, 2.50],
            ],
        ];
        $only = trim((string) ($this->option('prediction-token-model') ?: ''));
        if ($only !== '' && isset($MODELS[$only])) {
            $MODELS = [$only => $MODELS[$only]];
        }
        $this->line('  単価の公式確認日 : 2026年10月21日');
        foreach ($MODELS as $mid => $mi) {
            $pr = $mi['price'];
            $this->line(sprintf('    %-18s 上限 %s トークン／入力 $%.2f・出力 $%.2f%s',
                $mid, number_format($mi['ctx']), $pr[1], $pr[2],
                ($pr[0] === null) ? ''
                  : sprintf('（入力%sトークン超は $%.2f・$%.2f）',
                            number_format($pr[0]), $pr[3], $pr[4])));
        }
        $this->line('');
        $model = array_key_first($MODELS);

        // ── 数える道具（count_tokens は無料）──────────────────────────
        $count = function (string $system, string $user, ?string $m = null)
                 use ($key, &$model) {
            try {
                $res = \Illuminate\Support\Facades\Http::timeout(60)
                    ->withHeaders([
                        'x-api-key'         => $key,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ])->post('https://api.anthropic.com/v1/messages/count_tokens', [
                        'model'    => ($m ?? $model),
                        'system'   => $system,
                        'messages' => [['role' => 'user', 'content' => $user]],
                    ]);
                if ($res->failed()) {
                    return ['ok' => false,
                            'reason' => 'status=' . $res->status() . ' '
                                      . mb_strimwidth((string) $res->body(), 0, 120, '…')];
                }
                return ['ok' => true, 'tokens' => (int) $res->json('input_tokens')];
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => $e->getMessage()];
            }
        };

        // ── 代表レースを選びます（頭数の多い順に3本）──────────────────
        $races = \DB::table('t_horse_odds_finder_races')
                   ->where('date', $date)->whereNotNull('num_horses')
                   ->orderByDesc('num_horses')->orderBy('basho')->orderBy('race')
                   ->get();
        $picked = []; $seenN = [];
        foreach ($races as $r) {
            $n = (int) $r->num_horses;
            if (isset($seenN[$n])) { continue; }
            $seenN[$n] = true; $picked[] = $r;
            if (count($picked) >= 3) { break; }
        }
        if ($picked === []) {
            $this->error('  × ' . $date . ' のレースがありません'); return;
        }

        $rows = [];
        $this->info('── ① 初回の入力（system ＋ user）をモデルごとに数えます ──');
        foreach ($picked as $r) {
            $raceId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId($r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
            $g = \App\Http\Controllers\Api\AiController::gatherPredictionContext($r->date, $r->kaisuu, $r->basho,
                                         $r->day, $r->race, ['assume_audit' => true]);
            if (!($g['ok'] ?? false)) {
                $this->line(sprintf('  － %-22s 入力を作れません（%s）',
                    $raceId, (string) ($g['code'] ?? '')));
                continue;
            }
            foreach (['1st', '2nd'] as $role) {
                $asm = \App\Http\Controllers\Api\BaganrikiPrompt::assemble($g['ctx'], $role);
                if (!($asm['ok'] ?? false)) { continue; }
                $sys = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody($role);
                $sysBody = ($sys['ok'] ?? false) ? (string) $sys['body'] : '';
                $user = (string) $asm['user'];
                $n = (int) ($asm['input']['N'] ?? 0);
                if ($outDir !== '') {
                    @mkdir($outDir, 0775, true);
                    file_put_contents($outDir . '/input_' . $raceId . '_' . $role . '.json',
                                      $user);
                    file_put_contents($outDir . '/system_' . $role . '.txt', $sysBody);
                }
                $tk = [];
                foreach ($MODELS as $mid => $mi) {
                    $c = $count($sysBody, $user, $mid);
                    if (!$c['ok']) {
                        $this->error(sprintf('  × %-22s %-4s %-18s 数えられません：%s',
                            $raceId, $role, $mid, (string) $c['reason']));
                        continue;
                    }
                    $tk[$mid] = (int) $c['tokens'];
                }
                if ($tk === []) { continue; }
                $rows[] = ['race' => $raceId, 'n' => $n, 'role' => $role,
                           'bytes' => strlen($user) + strlen($sysBody),
                           'tok' => $tk];
                $this->line(sprintf('  %-22s N=%-3d %-4s  %9s バイト',
                    $raceId, $n, $role, number_format(strlen($user) + strlen($sysBody))));
                foreach ($tk as $mid => $v) {
                    $ctx = (int) $MODELS[$mid]['ctx'];
                    $this->line(sprintf('      %-18s %9s トークン  %s（上限 %s まで %+d）',
                        $mid, number_format($v),
                        ($v <= $ctx ? '○ 収まる' : '× 超える'),
                        number_format($ctx), $ctx - $v));
                }
                if (count($tk) === 2) {
                    $a = $tk['claude-haiku-4-5'] ?? 0;
                    $b = $tk['claude-haiku-5-5'] ?? 0;
                    if ($a > 0) {
                        $this->line(sprintf('      → 5.5 は 4.5 の %+.1f%%',
                            ($b - $a) / $a * 100));
                    }
                }
            }
            // ── 本文の内訳（なる様の6章：16頭と17頭で量が急増する理由）──
            $in1 = (array) (\App\Http\Controllers\Api\BaganrikiPrompt::assemble($g['ctx'], '1st')['input'] ?? []);
            $parts = [];
            foreach ($in1 as $k => $v) {
                $parts[$k] = strlen((string) json_encode($v,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            arsort($parts);
            $pastN = 0;
            foreach ((array) ($in1['source_registry'] ?? []) as $e) {
                if (strpos((string) ($e['ref'] ?? ''), '_past_') !== false) { $pastN++; }
            }
            $this->line(sprintf('      内訳：出走 %d 頭／source_registry %d 件'
                              . '（うち過去走の出典 %d 件）',
                $n, count((array) ($in1['source_registry'] ?? [])), $pastN));
            $top = array_slice($parts, 0, 5, true);
            $ps = [];
            foreach ($top as $k => $v) { $ps[] = $k . ' ' . number_format($v); }
            $this->line('      大きい順：' . implode('／', $ps));
        }
        $this->line('');

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-CTXLIMIT  入力がモデルの上限に収まるか
        // ══════════════════════════════════════════════════════════════
        //   claude-haiku-4-5 のコンテキストウィンドウは 200,000 トークンです。
        //   実測で 18頭が 257,696 トークンだったので、<b>そのままでは
        //   送れません。</b>過去走を何走まで減らせば収まるかを測ります。
        //   ここも count_tokens だけなので料金はかかりません。
        $this->info('── ①-3 過去走を減らすと、どこまで下がるか ──');
        $this->line('  いちばん大きいレースについて、直近何走まで載せるかを変えて数えます。');
        $this->line('  訂正は「初回の入力 ＋ 初回応答 ＋ 依頼文」なので、その分を足して見ます。');
        // 初回応答の目安は②で数えますが、先にここで要るので仮に数えます
        $fixAdd = 0;
        $vDir0 = rtrim($root, '/') . '/validation/normal';
        $f0 = $vDir0 . '/MIXED_N16_1st_output.json';
        if (is_file($f0)) {
            $c0 = $count('', \App\Http\Controllers\Api\BaganrikiResponse::BEGIN
                 . trim((string) file_get_contents($f0))
                 . \App\Http\Controllers\Api\BaganrikiResponse::END);
            if ($c0['ok'] ?? false) { $fixAdd = (int) $c0['tokens'] + 165; }
        }
        $this->line('  足す分 : ' . number_format($fixAdd) . ' トークン'
                  . '（初回応答の目安 ＋ 依頼文165）');
        $big = null;
        foreach ($picked as $r) {
            if ($big === null || (int) $r->num_horses > (int) $big->num_horses) { $big = $r; }
        }
        if ($big !== null) {
            $keep = [getenv('BAGANRIKI_PAST_RUNS_MAX'),
                     $_ENV['BAGANRIKI_PAST_RUNS_MAX'] ?? null,
                     $_SERVER['BAGANRIKI_PAST_RUNS_MAX'] ?? null];
            $bigId = \App\Http\Controllers\Api\AiController::buildPredictionRaceId(
                $big->date, $big->kaisuu, $big->basho, $big->day, $big->race);
            $this->line('  対象 ' . $bigId . '（N=' . (int) $big->num_horses . '）');
            try {
                foreach ([5, 4, 3, 2, 1, 0] as $pm) {
                    putenv('BAGANRIKI_PAST_RUNS_MAX=' . $pm);
                    $_ENV['BAGANRIKI_PAST_RUNS_MAX'] = (string) $pm;
                    $_SERVER['BAGANRIKI_PAST_RUNS_MAX'] = (string) $pm;
                    $gp = \App\Http\Controllers\Api\AiController::gatherPredictionContext(
                        $big->date, $big->kaisuu, $big->basho, $big->day, $big->race,
                        ['assume_audit' => true]);
                    if (!($gp['ok'] ?? false)) { continue; }
                    $ap = \App\Http\Controllers\Api\BaganrikiPrompt::assemble($gp['ctx'], '1st');
                    if (!($ap['ok'] ?? false)) { continue; }
                    $sp = \App\Http\Controllers\Api\BaganrikiPrompt::systemBody('1st');
                    $spBody = ($sp['ok'] ?? false) ? (string) $sp['body'] : '';
                    $this->line(sprintf('    直近%d走  %9s バイト', $pm,
                        number_format(strlen((string) $ap['user']) + strlen($spBody))));
                    foreach ($MODELS as $mid => $mi) {
                        $cp = $count($spBody, (string) $ap['user'], $mid);
                        if (!($cp['ok'] ?? false)) {
                            $this->error(sprintf('      %-18s 数えられません：%s',
                                $mid, (string) $cp['reason']));
                            continue;
                        }
                        $tk = (int) $cp['tokens'];
                        // 訂正は「初回の入力＋初回応答＋依頼文」なので、その分を足して見ます
                        $fx = $tk + $fixAdd;
                        $ctx = (int) $mi['ctx'];
                        $this->line(sprintf('      %-18s 初回 %9s %s ／ 訂正 %9s %s',
                            $mid, number_format($tk),
                            ($tk <= $ctx ? '○' : '×'),
                            number_format($fx),
                            ($fx <= $ctx ? '○' : '×')));
                    }
                }
            } finally {
                if ($keep[0] === false) { putenv('BAGANRIKI_PAST_RUNS_MAX'); }
                else { putenv('BAGANRIKI_PAST_RUNS_MAX=' . $keep[0]); }
                if ($keep[1] === null) { unset($_ENV['BAGANRIKI_PAST_RUNS_MAX']); }
                else { $_ENV['BAGANRIKI_PAST_RUNS_MAX'] = $keep[1]; }
                if ($keep[2] === null) { unset($_SERVER['BAGANRIKI_PAST_RUNS_MAX']); }
                else { $_SERVER['BAGANRIKI_PAST_RUNS_MAX'] = $keep[2]; }
            }
            $this->line('  ※ いまの設定は BAGANRIKI_PAST_RUNS_MAX='
                      . \App\Http\Controllers\Api\AiController::pastRunsMax() . ' 走です（戻しました）');
        }
        $this->line('');

        // ── ② 出力トークンの目安（正常出力14本）────────────────────────
        $this->info('── ② 出力トークンの目安（配布された正常出力14本）──');
        $vDir = rtrim($root, '/') . '/validation/normal';
        $outTok = [];
        foreach (glob($vDir . '/*_output.json') as $f) {
            $body = (string) file_get_contents($f);
            $c = $count('', \App\Http\Controllers\Api\BaganrikiResponse::BEGIN . trim($body) . \App\Http\Controllers\Api\BaganrikiResponse::END);
            if (!$c['ok']) { continue; }
            $outTok[basename($f)] = (int) $c['tokens'];
        }
        if ($outTok !== []) {
            $vals = array_values($outTok);
            sort($vals);
            $this->line(sprintf('  本数 %d ／ 最小 %s ／ 中央 %s ／ 最大 %s トークン',
                count($vals), number_format($vals[0]),
                number_format($vals[intdiv(count($vals), 2)]),
                number_format($vals[count($vals) - 1])));
            foreach ($outTok as $k => $v) {
                $this->line(sprintf('    %-42s %7s', $k, number_format($v)));
            }
        } else {
            $this->warn('  正常出力を数えられませんでした（' . $vDir . '）');
        }
        $this->line('');

        // ── ③④ 12レースの見積もり（初回のみ／訂正込み）────────────────
        $this->info('── ③ 12レースの見積もり（モデル別・初回のみ／訂正込み）──');
        $this->line('  訂正の入力 ＝ 初回の入力 ＋ 初回応答 ＋ 依頼文165トークン');
        $this->line('  キャッシュは使っていません（cache_control を付けていません）');
        $this->line('  出力は「配布された正常出力14本で観測した中央値」です。');
        $this->warn('  ※ これから生成される出力の保証上限ではありません');
        $this->line('');
        $outMid = [];
        foreach ($MODELS as $mid => $mi) {
            $vals = [];
            foreach (glob($vDir . '/*_output.json') as $f) {
                $c = $count('', \App\Http\Controllers\Api\BaganrikiResponse::BEGIN
                     . trim((string) file_get_contents($f))
                     . \App\Http\Controllers\Api\BaganrikiResponse::END, $mid);
                if ($c['ok'] ?? false) { $vals[] = (int) $c['tokens']; }
            }
            sort($vals);
            $outMid[$mid] = $vals === [] ? 0 : $vals[intdiv(count($vals), 2)];
        }
        $priceOf = static function (array $mi, int $inTok): array {
            $p = $mi['price'];
            if ($p[0] !== null && $inTok > (int) $p[0]) {
                return [(float) $p[3], (float) $p[4], '長文側'];
            }
            return [(float) $p[1], (float) $p[2], '通常'];
        };
        foreach ($MODELS as $mid => $mi) {
            $ins = [];
            foreach ($rows as $w) {
                if ($w['role'] !== '1st') { continue; }
                if (isset($w['tok'][$mid])) { $ins[] = $w['tok'][$mid]; }
            }
            if ($ins === []) { continue; }
            $om  = (int) $outMid[$mid];
            $ctx = (int) $mi['ctx'];
            $this->info('  ' . $mid . '（' . $mi['name'] . '）');
            $this->line(sprintf('    出力の中央値 %s トークン／上限 %s トークン',
                number_format($om), number_format($ctx)));
            foreach ([['平均', (int) round(array_sum($ins) / count($ins))],
                      ['最大（18頭）', max($ins)]] as $pair) {
                [$lbl, $in1] = $pair;
                $inFix = $in1 + $om + 165;
                [$pi1, $po1, $t1] = $priceOf($mi, $in1);
                [$pi2, $po2, $t2] = $priceOf($mi, $inFix);
                $usdFirst = $in1 * 12 / 1e6 * $pi1 + $om * 12 / 1e6 * $po1;
                $usdFix   = $inFix * 12 / 1e6 * $pi2 + $om * 12 / 1e6 * $po2;
                $this->line(sprintf('    %-14s 初回 入力%s(%s) → $%.2f'
                                  . '／訂正 入力%s(%s) → $%.2f／合計 $%.2f',
                    $lbl, number_format($in1), $t1, $usdFirst,
                    number_format($inFix), $t2, $usdFix, $usdFirst + $usdFix));
                $this->line(sprintf('    %-14s 上限 : 初回 %s ／ 訂正 %s',
                    '', ($in1 <= $ctx ? '○' : '× 超える'),
                    ($inFix <= $ctx ? '○' : '× 超える')));
            }
            $this->line('');
        }
        $this->warn('  ※ これは 1st（Anthropic）だけの金額です。'
                  . '2nd（DeepSeek）は別に出します');
        $this->warn('  ※ 既存運用の呼び出し・10月25日・別日の事前試験は別枠です');
        $this->line('');
        if ($outDir !== '') {
            $this->info('  本文を書き出しました: ' . $outDir);
            $this->line('    （DeepSeek 側は、この本文を公開トークナイザで数えます）');
        }
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 実測しました（本文の生成は0回・DBへの書き込み0件）');
        $this->info('════════════════════════════════════════════════════════');
    }

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261023-NARU-WORKERTEST  実 worker の受入試験（なる 10/23 8-3・8-4）
    // ══════════════════════════════════════════════════════════════════════
    //   本物の worker（別プロセス）に Job を処理させて、次を通します。外部AIは呼びません
    //   （試験用の場コード 90〜99 のレースだけに効く偽AI。BAGANRIKI_PREDICTION_FAKE_AI=1）。
    //     S1 対象外のレースへは1回も送らない（場 89：TEST の対象外）
    //     S2 キャッシュの無い通常アクセスと同じ起動口（dispatchPredictionJobs）から公開まで
    //     S3 誰も開かないレースを、巡回が見つけて凍結し、公開まで
    //     S4 既存の凍結＋2nd が返らない → 応答期限の集計で 1st だけで公開
    //     S5 受領後に止まった両 role → 送り直さずに診断・採否確定から再開して公開
    //     S6 遅い 1st（初回145秒・訂正40秒）でも Job の上限内で終わり、2nd と期限処理が止まらない
    //     S7 近接2レース（同じ発走時刻）が両方とも公開まで
    //     S8 1st が145秒かかる → こちらの待ち時間の上限（応答期限の5秒前）で切って結果不明にし、
    //        Job は上限に当たらず、2nd と期限処理は止まらない
    //   worker は別プロセスなので、この試験は<b>ロールバックできません</b>。
    //   作った行は、最後に試験用のレース（当日・場 89／91〜96）の分だけ消します。
    //   実在のレース（場 01〜10）の行には一切触りません。
    private function runWorkerAcceptanceTest(): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $T = \App\Http\Controllers\Api\BaganrikiTime::class;
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 実 worker の受入試験（外部AIは呼びません・課金0）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));

        // ── ① 前提 ─────────────────────────────────────────────────
        $pre = [
            'BAGANRIKI_PREDICTION_PROMPT が shadow か 1' => \App\Http\Controllers\Api\BaganrikiPrompt::enabled(),
            'BAGANRIKI_PREDICTION_QUEUE=1'               => $W::mode() === 'on',
            'BAGANRIKI_PREDICTION_JOBS=1'                => $W::jobs(),
            'BAGANRIKI_PREDICTION_FAKE_AI=1'             => $A::fakeAiEnabled(),
            'BAGANRIKI_PREDICTION_TARGETS に TEST がある' => in_array('TEST', $A::predictionTargets(), true),
            'jobs 表がある'                         => \App\Jobs\PredictionJob::ready(),
        ];
        $okPre = true;
        foreach ($pre as $k => $v) {
            $this->line(sprintf('  %s %s', $v ? '○' : '×', $k));
            if (!$v) { $okPre = false; }
        }
        $ps = (string) @shell_exec('pgrep -fa "queue:work r122" 2>/dev/null');
        $workers = array_values(array_filter(explode("\n", trim($ps)), static fn($l) => $l !== ''));
        $this->line('  動いている worker : ' . count($workers) . ' 本');
        foreach ($workers as $wl) { $this->line('      ' . mb_strimwidth($wl, 0, 140, '…')); }
        if (count($workers) === 0) {
            $this->line('  × worker が1本も動いていません'); $okPre = false;
        }
        if (!$okPre) {
            $this->error('  前提がそろっていないので中止しました（何も書いていません）');
            return;
        }
        $src = trim((string) ($this->option('prediction-live-race') ?: '20261018-4-04-2-10'));
        $sp = explode('-', $src);
        $srcDate = substr($sp[0], 0, 4) . '-' . substr($sp[0], 4, 2) . '-' . substr($sp[0], 6, 2);
        $race = \DB::table('t_horse_odds_finder_races')
                  ->where('date', $srcDate)->where('kaisuu', $sp[1])
                  ->where('basho', $sp[2])->where('day', $sp[3])
                  ->where('race', (int) $sp[4])->first();
        if ($race === null) {
            $this->error('  × 写し元のレースがありません: ' . $src); return;
        }

        // ── ② 試験用のレースを写す ─────────────────────────────────
        $nowUs    = $W::nowUs();
        $anchorUs = ($nowUs - ($nowUs % 1000000)) + 345 * 1000000;
        $anchorDt = (new \DateTimeImmutable('@' . intdiv($anchorUs, 1000000)))
                        ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $newDate  = $anchorDt->format('Y-m-d');
        $newStart = $anchorDt->format('H:i:s');
        // 場・レース番号・やり方
        $plan = [
            'S1' => ['89', 1, 'sweep'],
            'S2' => ['91', 1, 'dispatch'],
            'S3' => ['92', 1, 'sweep'],
            'S4' => ['93', 2, 'existing'],
            'S5' => ['94', 1, 'received'],
            'S6' => ['95', 3, 'sweep'],
            // @ANCHOR-20261023-NARU-WORKERTEST2  145秒の 1st は上限で切れる場合として分けました
            // @ANCHOR-20261023-NARU-WORKERTEST4  S8 は下で S8a／S8b として、配布の正常例（MIXED_N16）から作ります
            'S7a' => ['96', 1, 'sweep'],
            'S7b' => ['96', 4, 'sweep'],
        ];
        $toDb = static function (int $us): string {
            return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
        };
        $ids = [];
        foreach ($plan as $key => [$basho, $rno, $how]) {
            foreach (['t_horse_odds_finder_races', 't_horse_odds_finder_horses',
                      't_horse_odds_finder_odds'] as $tbl) {
                foreach (\DB::table($tbl)->where('date', $race->date)
                            ->where('kaisuu', $race->kaisuu)->where('basho', $race->basho)
                            ->where('day', $race->day)->where('race', (int) $race->race)
                            ->get() as $row) {
                    $a = (array) $row; unset($a['id']);
                    $a['date'] = $newDate; $a['basho'] = $basho; $a['race'] = $rno;
                    if ($tbl === 't_horse_odds_finder_races') { $a['start_time'] = $newStart; }
                    \DB::table($tbl)->insert($a);
                }
            }
            $rid = $A::buildPredictionRaceId($newDate, $race->kaisuu, $basho, $race->day, $rno);
            $ids[$key] = $rid;
            $rowsA = [];
            foreach ($T::INPUT_SLOTS as $slot) {
                $pl = [];
                foreach (\DB::table('t_horse_odds_finder_odds')->where('date', $newDate)
                            ->where('kaisuu', $race->kaisuu)->where('basho', $basho)
                            ->where('day', $race->day)->where('race', $rno)
                            ->where('minutes_before_start', $slot)->orderBy('num')
                            ->get(['num', 'odds', 'fuku_min', 'fuku_max']) as $o) {
                    $pl[] = ['num' => (int) $o->num, 'tan' => (string) $o->odds,
                             'fuku_min' => (string) $o->fuku_min, 'fuku_max' => (string) $o->fuku_max];
                }
                if ($pl === []) { continue; }
                $raw = (string) json_encode($pl, JSON_UNESCAPED_UNICODE);
                $sch = $T::slotScheduled($anchorUs, $slot);
                $rowsA[] = [
                    'race_id' => $rid, 'slot' => $slot,
                    'batch_id' => 'WT-' . $rid . '-' . $slot,
                    'slot_scheduled_at' => $toDb($sch), 'source_updated_at' => null,
                    'request_started_at' => $toDb($sch + 500000),
                    'response_received_at' => $toDb($sch + 1500000),
                    'available_at' => $toDb($sch + 2500000),
                    'raw_payload' => $raw, 'payload_hash' => hash('sha256', $raw),
                    'validation_result' => 'ADOPTED', 'rejection_reason' => null,
                    'committed' => 1, 'created_at' => $toDb($sch + 2500000),
                ];
            }
            if ($rowsA !== []) { \DB::table('baganriki_acquisition_batches')->insert($rowsA); }
            $this->line(sprintf('  %-4s %-26s %s', $key, $rid, $how));
        }
        $this->line('  発走     : ' . $newStart . '（情報上限 T−330・応答期限 T−150・公開期限 T−120）');
        $freezeUs = $T::inputFreeze($anchorUs);
        $scUs     = $T::salesCloseFromPost($anchorUs) ?? ($anchorUs - 60 * 1000000);

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-NARU-WORKERTEST4  S8a／S8b（なる 10/23 2通目 5章C）
        // ══════════════════════════════════════════════════════════════
        //   1st が145秒かかって使えない場合を、期待結果を先に決めて2通り通します。
        //     S8a … 2nd は配布の正常例（MIXED_N16 の 2nd 出力）。入力も同じ例の 1st／2nd 入力。
        //            → 2nd だけで期限内に公開する
        //     S8b … 2nd の答えに、同じ例の「1st の出力」を返す（ai_role・role_quota が 2nd と合わない）
        //            → 使えない応答なので公開しない。採否確定の記録に理由（検査のコード）が残る
        //   1st の答えを 2nd に流用するのではなく、配布された 2nd の正常例を使います。
        //   変えるのは race_id だけ（試験用の場コード 98／99）で、snapshot_hash・配分・role は例のままです。
        $matDir = trim((string) ($this->option('prediction-worker-mat') ?: (getenv('HOME') . '/r122_v23/mat')));
        $vdir = $matDir . '/validation/normal';
        $in1m = json_decode((string) @file_get_contents($vdir . '/MIXED_N16_1st_input.json'), true);
        $in2m = json_decode((string) @file_get_contents($vdir . '/MIXED_N16_2nd_input.json'), true);
        $o1m  = json_decode((string) @file_get_contents($vdir . '/MIXED_N16_1st_output.json'), true);
        $o2m  = json_decode((string) @file_get_contents($vdir . '/MIXED_N16_2nd_output.json'), true);
        $fakeDir = storage_path('logs/r122_fake');
        $fakeFiles = [];
        if (is_array($in1m) && is_array($in2m) && is_array($o1m) && is_array($o2m)
            && (is_dir($fakeDir) || @mkdir($fakeDir, 0777, true))) {
            $jj = static fn($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                                          | JSON_PRESERVE_ZERO_FRACTION);
            $B = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
            $E = \App\Http\Controllers\Api\BaganrikiResponse::END;
            foreach (['S8a' => ['98', $o2m], 'S8b' => ['99', $o1m]] as $key => [$bsh, $ans2]) {
                $rid = $A::buildPredictionRaceId($newDate, $race->kaisuu, $bsh, $race->day, 5);
                $ids[$key] = $rid;
                $i1 = $in1m; $i1['race_id'] = $rid;
                $i2 = $in2m; $i2['race_id'] = $rid;
                $nums = array_map(static fn($r) => (int) $r['horse_no'], (array) $i1['runners']);
                $sidM = \DB::table('baganriki_snapshots')->insertGetId([
                    'race_id' => $rid, 'generation' => 0, 'run_id' => 'WT-MAT-' . $key,
                    'snapshot_hash' => (string) $i1['snapshot_hash'],
                    'input_json_1st' => $jj($i1), 'input_json_2nd' => $jj($i2),
                    'frozen_nums' => $jj($nums),
                    'allocation_mode' => (string) ($i1['allocation_mode'] ?? ''),
                    'mode' => (string) ($i1['mode'] ?? ''),
                    'mode_status' => (string) ($i1['mode_status'] ?? ''),
                    'target_count' => (int) ($i1['target_count'] ?? 0),
                    'quota_1st' => $jj($i1['quota'] ?? []),
                    'sales_close_at' => $toDb($scUs),
                    'created_at' => $toDb($W::nowUs()),
                ]);
                \DB::table('baganriki_live_states')->insert([
                    'snapshot_id' => $sidM, 'cancellations' => '[]',
                    'active_count' => count($nums), 'live_target_count' => (int) ($i1['target_count'] ?? 0),
                    'live_quota' => $jj($i1['quota'] ?? []), 'live_close' => null,
                    'state_revision' => 0, 'response_revision' => 0,
                    'publication_revision' => 0, 'latest_publication_id' => null,
                    'updated_at' => $toDb($W::nowUs()),
                ]);
                $a2 = $ans2; $a2['race_id'] = $rid;
                $ff = $fakeDir . '/' . $rid . '_2nd_0.txt';
                @file_put_contents($ff, $B . $jj($a2) . $E);
                @chmod($ff, 0644);
                $fakeFiles[] = $ff;
                $this->line(sprintf('  %-4s %-26s %s', $key, $rid,
                    $key === 'S8a' ? '配布の正常例・2nd は正常な2nd出力（1st は待ち時間を超える）'
                                   : '配布の正常例・2nd は1stの出力を返す（使えない応答。1st は待ち時間を超える）'));
            }
        } else {
            $this->warn('  ※ 配布の正常例（' . $vdir . '）が読めないので S8a／S8b は行いません');
        }
        $replyUs  = $T::replyDeadline($scUs);
        $pubDlUs  = $T::publishDeadline($scUs);
        $ctrl = app($A);

        // ── ③ 情報上限まで待ってから起動 ─────────────────────────────
        $waitUs = $freezeUs - $W::nowUs() + 500000;
        if ($waitUs > 0) {
            $this->line('  情報上限まで ' . round($waitUs / 1000000) . ' 秒待ちます');
            usleep($waitUs);
        }
        foreach ($plan as $key => [$basho, $rno, $how]) {
            if ($how === 'dispatch') {
                $dp = $ctrl->dispatchPredictionJobs($newDate, $race->kaisuu, $basho, $race->day, $rno);
                $this->line('  ' . $key . ' 通常アクセスの起動口 : ' . (string) $dp['code']
                          . ' ' . json_encode($dp['dispatched']));
            }
            if ($how === 'existing' || $how === 'received') {
                $rf = $ctrl->resumePredictionFreeze($newDate, $race->kaisuu, $basho, $race->day, $rno);
                $this->line('  ' . $key . ' 先に凍結 : ' . (string) ($rf['code'] ?? ''));
            }
            if ($how === 'received') {
                $sn = \DB::table('baganriki_snapshots')->where('race_id', $ids[$key])
                        ->where('generation', 0)->first();
                if ($sn !== null) {
                    $A::fixProcessingPath((int) $sn->id, 'JOB');
                    foreach (['1st', '2nd'] as $rl) {
                        $col = ($rl === '2nd') ? 'input_json_2nd' : 'input_json_1st';
                        $fk = $A::fakeAiResponse($rl, (string) $sn->{$col}, 30);
                        $raw = (string) ($fk['raw'] ?? '{}');
                        \DB::table('baganriki_ai_attempts')->insert([
                            'snapshot_id' => (int) $sn->id, 'role' => $rl, 'attempt_no' => 0,
                            'token' => 'WT-' . (int) $sn->id . '-' . $rl,
                            'status' => $W::RECEIVED,
                            'reserved_at' => $toDb($W::nowUs()), 'received_at' => $toDb($W::nowUs()),
                            'complete' => 1, 'raw_response' => $raw,
                            'response_hash' => hash('sha256', $raw),
                        ]);
                    }
                    $this->line('  ' . $key . ' 受領済み・採否未確定の枠を置きました（worker が止まった想定）');
                }
            }
            if ($how === 'existing') {
                $dp = $ctrl->dispatchPredictionJobs($newDate, $race->kaisuu, $basho, $race->day, $rno);
                $this->line('  ' . $key . ' 既存の凍結から積む : ' . (string) $dp['code']
                          . ' ' . json_encode($dp['dispatched']));
            }
        }

        // @ANCHOR-20261023-NARU-WORKERTEST4  S8a／S8b を既存の凍結から積みます
        foreach (['S8a' => '98', 'S8b' => '99'] as $key => $bsh) {
            if (!isset($ids[$key])) { continue; }
            $dp = $ctrl->dispatchPredictionJobs($newDate, $race->kaisuu, $bsh, $race->day, 5);
            $this->line('  ' . $key . ' 既存の凍結から積む : ' . (string) $dp['code']
                      . ' ' . json_encode($dp['dispatched']));
        }
        // ── ④ 公開期限の15秒後まで、5秒おきに巡回（cron と同じ関数）─────
        $endUs = $pubDlUs + 15 * 1000000;
        $this->line('  公開期限の15秒後まで巡回します（約 '
                  . round(($endUs - $W::nowUs()) / 1000000) . ' 秒）');
        $round = 0;
        while ($W::nowUs() < $endUs) {
            $round++;
            $ctrl->sweepPendingPredictions();
            if ($round % 6 === 0) {
                $pubN = 0;
                foreach ($ids as $rid) {
                    $sn = \DB::table('baganriki_snapshots')->where('race_id', $rid)
                            ->where('generation', 0)->first(['id']);
                    if ($sn !== null && \DB::table('baganriki_publications')
                            ->where('snapshot_id', (int) $sn->id)->exists()) { $pubN++; }
                }
                $this->line(sprintf('    %s  公開済み %d／%d', date('H:i:s'), $pubN, count($ids) - 1));
            }
            usleep(5000000);
        }

        // ── ⑤ 判定 ─────────────────────────────────────────────────
        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $ok = ($got === $want); $ok ? $pass++ : $fail++;
            $this->line(sprintf('  %s %-6s %-50s %s', $ok ? '○' : '×', $id, $what,
                $ok ? '' : ('得=' . var_export($got, true) . ' 期待=' . var_export($want, true))));
        };
        $snapOf = static fn(string $rid) => \DB::table('baganriki_snapshots')
                    ->where('race_id', $rid)->where('generation', 0)->first();
        $usage = static fn(int $sid, ?int $no = null) => \DB::table('baganriki_audit_events')
                    ->where('snapshot_id', $sid)->where('kind', 'R122_USAGE')->get()
                    ->filter(static function ($e) use ($no) {
                        if ($no === null) { return true; }
                        $d = json_decode((string) $e->diagnostics, true);
                        return (int) ($d['attempt_no'] ?? -1) === $no;
                    })->count();
        $pubOf = static fn(int $sid) => \DB::table('baganriki_publications')
                    ->where('snapshot_id', $sid)->orderBy('id')->first();
        $this->line('');
        $this->info('── 判定 ──');
        // S1
        $s1 = $snapOf($ids['S1']);
        $check('W-S1a', '対象外のレースは凍結もしない', $s1 === null, true);
        $check('W-S1b', '対象外のレースへの送信は0回',
               \DB::table('baganriki_audit_events')->where('race_id', $ids['S1'])
                 ->where('kind', 'R122_USAGE')->count(), 0);
        foreach (['S2', 'S3', 'S6', 'S7a', 'S7b', 'S4', 'S5', 'S8a', 'S8b'] as $key) {
            if (!isset($ids[$key])) { continue; }
            $sn = $snapOf($ids[$key]);
            if ($sn === null) { $check('W-' . $key, '凍結できている', false, true); continue; }
            $sid = (int) $sn->id;
            $pb = $pubOf($sid);
            $pubUs = $pb !== null ? $W::dbUs($pb->published_at) : null;
            $check('W-' . $key . 'a', '経路は JOB', $A::processingPathOf($sid), 'JOB');
            // @ANCHOR-20261023-NARU-WORKERTEST3  S8 は 2nd だけが材料で、公開できるかは
            //   保存済み応答の中身しだいなので、公開の有無は判定に入れず表示だけにします
            if ($key !== 'S8b') {
                $check('W-' . $key . 'b', '公開期限までに公開された',
                       $pubUs !== null && $pubUs <= $pubDlUs, true);
            }
            $st1 = (string) \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                     ->where('role', '1st')->where('attempt_no', 0)->value('status');
            $st2 = (string) \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                     ->where('role', '2nd')->where('attempt_no', 0)->value('status');
            $set1 = $A::roleSettled($sid, '1st')['settled'];
            $set2 = $A::roleSettled($sid, '2nd')['settled'];
            if ($key === 'S4') {
                $check('W-S4c', '1st は受領・採否確定', [$st1, $set1], [$W::RECEIVED, true]);
                $check('W-S4d', '2nd は返っていない（受領していない）', $st2 !== $W::RECEIVED, true);
                // @ANCHOR-20261023-NARU-WORKERTEST2  2nd は応答期限の5秒前に、こちらの上限で切ります。
                //   切った時点で両 role が終端になるので、公開は応答期限より前に出ることがあります。
                //   見るべきは「2nd を待ち続けて止まらないこと」なので、2nd が終端（結果不明か失敗）に
                //   なっていることを見ます。
                // $check('W-S4e', '公開は応答期限より後（期限の集計で出た）',
                $check('W-S4e', '2nd は待ち続けずに終端になった（結果不明か失敗）',
                       in_array($st2, [$W::UNKNOWN, $W::FAILED], true), true);
                continue;
            }
            if ($key === 'S8a' || $key === 'S8b') {
                $check('W-' . $key . 'c', '待ち時間を超える 1st は上限で切れて結果不明（SENT のまま残らない）',
                       $st1, $W::UNKNOWN);
                $check('W-' . $key . 'd', '2nd は受領・採否確定（1st の遅れに引きずられない）',
                       [$st2, $set2], [$W::RECEIVED, true]);
                if ($key === 'S8b') {
                    $check('W-S8be', '使えない 2nd の応答なので公開しない', $pb === null, true);
                    $ev = \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)
                            ->where('kind', 'R122_ROLE_SETTLED')->where('related_id', '2nd')
                            ->orderBy('id')->first();
                    $dg = $ev !== null ? (array) json_decode((string) $ev->diagnostics, true) : [];
                    $this->line('       S8b の採否確定の記録 : ' . json_encode($dg, JSON_UNESCAPED_UNICODE));
                    $check('W-S8bf', '使えない理由（検査のコード）が記録に残る',
                           ($dg['inspect_code'] ?? '') !== '' && ($dg['resolve_ok'] ?? true) === false, true);
                }
                continue;
            }
            $check('W-' . $key . 'c', '両 role とも受領・採否確定',
                   [$st1, $st2, $set1, $set2], [$W::RECEIVED, $W::RECEIVED, true, true]);
            if ($key === 'S5') {
                $check('W-S5d', '初回はAIへ送り直していない（使用量の記録0）', $usage($sid, 0), 0);
            }
            if ($key === 'S6') {
                $a1 = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                        ->where('role', '1st')->where('attempt_no', 0)->first();
                $r0 = ($a1 !== null) ? $W::dbUs($a1->reserved_at ?? null) : null;
                $r1 = ($a1 !== null) ? $W::dbUs($a1->received_at ?? null) : null;
                $sec = ($r0 !== null && $r1 !== null) ? ($r1 - $r0) / 1000000 : null;
                $this->line('       S6 の 1st 初回にかかった秒数 : ' . ($sec === null ? '—' : round($sec, 1)));
                $fx = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                        ->where('role', '1st')->where('attempt_no', 1)->first();
                $this->line('       S6 の 1st 訂正 : ' . ($fx === null ? '送っていない（時間が足りない判断）'
                          : (string) $fx->status));
                $check('W-S6d', '1st の訂正が SENT のまま残っていない（Job の上限で切れていない）',
                       $fx === null || (string) $fx->status !== $W::SENT, true);
            }
        }
        $fj = \DB::table('failed_jobs')->where('failed_at', '>=', $toDb($nowUs))->count();
        $check('W-FJ', 'この試験の間に失敗した Job は0本', $fj, 0);
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件／合格 ' . $pass . '／不合格 ' . $fail);

        // ── ⑥ 後片付け（試験用のレースの行だけ）────────────────────────
        if ((bool) $this->option('prediction-worker-keep')) {
            $this->warn('  --prediction-worker-keep なので、試験の行を残しました');
        } else {
            $n = 0;
            foreach ($ids as $rid) {
                if (!$A::isTestRaceId($rid) && explode('-', $rid)[2] !== '89') { continue; }
                $sids = \DB::table('baganriki_snapshots')->where('race_id', $rid)->pluck('id')->all();
                if ($sids !== []) {
                    $aids = \DB::table('baganriki_ai_attempts')->whereIn('snapshot_id', $sids)->pluck('id')->all();
                    if ($aids !== []) {
                        $n += \DB::table('baganriki_validation_results')->whereIn('attempt_id', $aids)->delete();
                    }
                    foreach (['baganriki_publications', 'baganriki_publication_intents',
                              'baganriki_ai_attempts', 'baganriki_live_states'] as $tb) {
                        $n += \DB::table($tb)->whereIn('snapshot_id', $sids)->delete();
                    }
                    foreach ($sids as $sid) {
                        $n += \DB::table('jobs')->where('payload', 'like',
                                '%"snapshotId";i:' . (int) $sid . ';%')->delete();
                    }
                }
                foreach (['baganriki_audit_events', 'baganriki_race_runs',
                          'baganriki_snapshots', 'baganriki_acquisition_batches'] as $tb) {
                    $n += \DB::table($tb)->where('race_id', $rid)->delete();
                }
            }
            foreach (['t_horse_odds_finder_races', 't_horse_odds_finder_horses',
                      't_horse_odds_finder_odds'] as $tbl) {
                $n += \DB::table($tbl)->where('date', $newDate)
                        ->whereIn('basho', ['89', '91', '92', '93', '94', '95', '96', '97', '98', '99'])->delete();
            }
            // @ANCHOR-20261023-NARU-WORKERTEST4  試験が置いた応答のファイルも消します
            foreach ($fakeFiles as $ff) { @unlink($ff); }
            $this->line('  後片付け : 試験用のレースの行を ' . $n . ' 行消しました（実在のレースには触っていません）');
        }
        $this->line('');
        $this->info($fail === 0
            ? ' 実 worker で、対象外0送信・通常アクセス・定期起動・期限起動・受領後の再開・遅い1st・近接2レースが通りました'
            : ' 通らなかった箇所があります。上の × をご確認ください');
    }

    private function runJobTest(): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 Job構成の通し試験（010の2章）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ worker は立てません。jobs 表へ積まれた行を読みます');
        $this->warn('  ※ 実AIは1回も呼びません（Jobを1本も実行しません）');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-10s %-54s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true)
                           . ' 期待=' . var_export($want, true))));
        };

        // ── ① 置かれているか ───────────────────────────────────────
        $this->info('── ① 置かれているか ──');
        $classes = ['\App\Jobs\PredictionJob', '\App\Jobs\PredictionFreezeRaceJob',
                    '\App\Jobs\PredictionRunRoleJob', '\App\Jobs\PredictionAggregateRaceJob',
                    '\App\Jobs\PredictionUpdateRaceLiveJob'];
        $missing = [];
        foreach ($classes as $c) {
            if (!class_exists($c)) { $missing[] = $c; }
        }
        $check('J-01', 'Job 5本（土台1＋4本）が置かれている', $missing, []);
        if ($missing !== []) {
            $this->error('  app/Jobs が置かれていないので、ここで止めます');
            return;
        }

        $check('J-02', 'jobs 表がある', \Schema::hasTable('jobs'), true);
        $check('J-03', 'failed_jobs 表がある', \Schema::hasTable('failed_jobs'), true);
        $conn = config('queue.connections.' . \App\Jobs\PredictionJob::CONN);
        $check('J-04', 'r122 の接続が config/queue.php にある',
               is_array($conn), true);
        if (!is_array($conn) || !\Schema::hasTable('jobs')) {
            $this->error('  待ち行列の受け皿がそろっていないので、ここで止めます');
            $this->line('');
            $this->line('  判定件数 : ' . ($pass + $fail) . ' 件／不合格 ' . $fail . ' 件');
            return;
        }
        $check('J-05', 'r122 の接続は database', (string) ($conn['driver'] ?? ''),
               'database');

        // ── ② 秒数の整合（010の2-1 末尾）────────────────────────────
        $this->info('── ② 秒数の整合（Jobの上限・retry_after）──');
        $retry = (int) ($conn['retry_after'] ?? 0);
        $runJob = new \App\Jobs\PredictionRunRoleJob(1, '1st');
        $aggJob = new \App\Jobs\PredictionAggregateRaceJob(1, 'RECEIVED');
        $frzJob = new \App\Jobs\PredictionFreezeRaceJob('2026-10-24', '5', '05', '1', 1);
        // AnthropicService::TIMEOUT は private const なので、
        // リフレクションで<b>実際の値</b>を読みます（書き写しません）。
        $httpTimeout = null;
        try {
            $rcA = new \ReflectionClass(\App\Services\AnthropicService::class);
            $cs  = $rcA->getConstants();
            if (array_key_exists('TIMEOUT', $cs)) { $httpTimeout = (int) $cs['TIMEOUT']; }
        } catch (\Throwable $e) {
            $httpTimeout = null;
        }
        $this->line('      RunRoleJob の上限   : ' . (int) $runJob->timeout . ' 秒');
        $this->line('      retry_after         : ' . $retry . ' 秒');
        $this->line('      AnthropicService    : '
                  . ($httpTimeout === null ? '（読めません）'
                                           : $httpTimeout . ' 秒'));
        $check('J-06', 'RunRoleJob の上限は 180秒の窓の内側',
               ((int) $runJob->timeout) < 180, true);
        $check('J-07', 'RunRoleJob の上限は HTTP の上限より長い',
               ($httpTimeout !== null)
                   && (((int) $runJob->timeout) > $httpTimeout), true);
        $check('J-08', 'retry_after は Jobの上限より十分長い（+100秒以上）',
               $retry >= ((int) $runJob->timeout) + 100, true);
        $check('J-09', 'RunRoleJob はやり直さない（外部APIへ二度送らない）',
               (int) $runJob->tries, 1);
        $check('J-10', '集計Jobはやり直してよい', ((int) $aggJob->tries) > 1, true);
        $check('J-11', '集計JobはAIを待たないので上限が短い',
               ((int) $aggJob->timeout) <= 60, true);
        $check('J-12', 'AIの待ち行列と期限・統合の待ち行列は別',
               [$runJob->queue, $aggJob->queue],
               [\App\Jobs\PredictionJob::Q_AI_1ST, \App\Jobs\PredictionJob::Q_AGG]);
        $check('J-13', '2nd は1st と別の待ち行列',
               (new \App\Jobs\PredictionRunRoleJob(1, '2nd'))->queue,
               \App\Jobs\PredictionJob::Q_AI_2ND);
        $check('J-14', '凍結も別の待ち行列', $frzJob->queue,
               \App\Jobs\PredictionJob::Q_FREEZE);
        $check('J-15', '未commitを読ませない設定（after_commit）',
               (bool) ($conn['after_commit'] ?? false), true);

        // ── ③ 旗（段）───────────────────────────────────────────────
        $this->info('── ③ 旗（段）──');
        $this->line('      本文の段 : ' . \App\Http\Controllers\Api\BaganrikiPrompt::mode()
                  . '／枠の段 : ' . $W::mode()
                  . '／Jobの段 : ' . ($W::jobs() ? 'on' : 'off'));
        $check('J-16', '枠の段が on でなければ Job の段も上がらない',
               ($W::mode() === 'on') || !$W::jobs(), true);

        // ── ④ 対象レースの限定（010の2-5）──────────────────────────
        $this->info('── ④ 対象レースの限定 ──');
        $tgs = $A::predictionTargets();
        $this->line('      対象レース : ' . count($tgs) . ' 件'
                  . ($tgs === [] ? '（BAGANRIKI_PREDICTION_TARGETS が空です）'
                                 : '：' . implode('・', array_slice($tgs, 0, 3))
                                   . (count($tgs) > 3 ? ' …' : '')));
        $check('J-17', '限定を効かせるかは段で決まる',
               is_bool($W::targetsEnforced()), true);
        if ($W::targetsEnforced()) {
            $check('J-18', '対象に入っていない race_id は断る',
                   $A::checkPredictionTarget('20261024-9-05-9-9')['ok'], false);   // 試験用の場（90〜99）は対象に入るので、実在の場コードで確かめる
            if ($tgs !== []) {
                $check('J-19', '対象に入っている race_id は通す',
                       $A::checkPredictionTarget((string) $tgs[0])['ok'], true);
            } else {
                $check('J-19', '対象が空なら1レースも送らない',
                       $A::checkPredictionTarget('20261024-5-05-1-1')['code'],
                       'NO_TARGETS');
            }
        } else {
            $check('J-18', '限定を効かせていないので素通りする',
                   $A::checkPredictionTarget('20261024-9-99-9-9')['code'], 'NOT_ENFORCED');
            $check('J-19', '限定を効かせていないので素通りする（対象側も）',
                   $A::checkPredictionTarget('20261024-5-05-1-1')['ok'], true);
        }

        // ── ⑤ ここから先はDBを使います（必ずロールバックします）──────
        $ok = true;
        \DB::beginTransaction();
        try {
            $ctrl = app($A);
            $now  = $W::nowUs();
            $raceId = '20261024-9-99-9-7';   // 場コード99は実在しません

            // 仮の凍結入力（generation=0）を作ります
            $post  = $now + 600 * 1000000;            // 発走は10分後
            $sales = $post - 60 * 1000000;            // 投票締切は発走の60秒前
            $sid = (int) \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => $raceId, 'generation' => 0,
                'run_id' => 'JT-' . $now, 'release_version' => 'r12.2',
                'common_json' => '{}', 'input_json_1st' => '{}',
                'input_json_2nd' => '{}',
                'snapshot_hash' => hash('sha256', 'jobtest' . $now),
                'frozen_nums' => '[1,2,3,4]', 'frozen_rank' => '[1,2,3,4]',
                'mode' => 'MIXED', 'mode_status' => 'FIXED',
                'anchor_at' => $W::toDb($post),
                'cutoff_at' => $W::toDb($post - 360 * 1000000),
                'input_freeze_at' => $W::toDb($post - (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC) * 1000000),
                'frozen_at' => $W::toDb($now),
                'sales_close_at' => $W::toDb($sales),
                'created_at' => $W::toDb($now),
            ]);
            $W::ensureLive($sid, $now);

            // ── 経路の固定（010の2-5）──────────────────────────────
            $this->info('── ⑤ 経路の固定 ──');
            $check('J-20', '固定する前は、どちらでもない',
                   $A::processingPathOf($sid), null);
            $f1 = $A::fixProcessingPath($sid, 'JOB');
            $check('J-21', 'Job に固定できる',
                   [$f1['fixed'], $f1['path']], [true, 'JOB']);
            $f2 = $A::fixProcessingPath($sid, 'SYNC');
            $check('J-22', '後から旧経路へ変えられない（先に決めたほうが残る）',
                   [$f2['already'], $f2['path']], [true, 'JOB']);
            $check('J-23', '読み出しても Job のまま', $A::processingPathOf($sid), 'JOB');

            // ── 積まれること ───────────────────────────────────────
            $this->info('── ⑥ 積まれること（worker は動かしません）──');

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-AFTERCOMMIT  未commitを読ませない
            // ══════════════════════════════════════════════════════════
            //   新方式 の接続は after_commit = true なので、
            //   トランザクションの中で dispatch しても、<b>commit まで
            //   jobs 表には入りません。</b>まずそれを確かめます。
            //   （この試験は最後にロールバックするので、ここで積んだ
            //     ぶんはそのまま消えます）
            $q0 = (int) \DB::table('jobs')->count();
            \App\Jobs\PredictionRunRoleJob::dispatch($sid, '2nd');
            $check('J-24a', 'commit前は jobs 表に入らない（after_commit）',
                   ((int) \DB::table('jobs')->count()) - $q0, 0);

            //   以降は beforeCommit() で、その場で積ませて中身を見ます。
            //   <b>試験のためだけ</b>です。本番の経路では付けません
            //   （付けると未commitのsnapshotを読まれるおそれがあります）。
            \App\Jobs\PredictionRunRoleJob::dispatch($sid, '1st')->beforeCommit();
            $q1 = (int) \DB::table('jobs')->count();
            $check('J-24', 'beforeCommit なら その場で1行増える', $q1 - $q0, 1);

            $row = \DB::table('jobs')->orderByDesc('id')->first();
            $check('J-25', '積まれた待ち行列は 1st 用',
                   (string) ($row->queue ?? ''), \App\Jobs\PredictionJob::Q_AI_1ST);
            $pl = json_decode((string) ($row->payload ?? '{}'), true);
            $check('J-26', '積まれたのは RunRoleJob',
                   (string) ($pl['displayName'] ?? ''),
                   'App\Jobs\PredictionRunRoleJob');
            // 引数（snapshot_id と role）が payload に入っていること
            $cmd = (string) ($pl['data']['command'] ?? '');
            $check('J-27', '積んだ引数に snapshot_id が入っている',
                   strpos($cmd, (string) $sid) !== false, true);
            $check('J-28', '積んだ引数に role が入っている',
                   strpos($cmd, '1st') !== false, true);
            $check('J-29', '積んだだけでは1本も実行されていない',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $sid)->count(), 0);

            \App\Jobs\PredictionAggregateRaceJob::dispatch($sid, 'DEADLINE')
                ->delay(now()->addSeconds(30))->beforeCommit();
            $row2 = \DB::table('jobs')->orderByDesc('id')->first();
            $check('J-30', '遅らせて積んだ集計は、まだ取り出せない時刻',
                   ((int) ($row2->available_at ?? 0)) > time() + 20, true);
            $check('J-31', '集計は期限・統合の待ち行列',
                   (string) ($row2->queue ?? ''), \App\Jobs\PredictionJob::Q_AGG);

            // ── 入口から積む（010の2-3）────────────────────────────
            $this->info('── ⑦ 入口から積む ──');
            //   入口は本番の経路なので beforeCommit は付きません。
            //   そのため jobs 表の行数では数えられません（commit前です）。
            //   <b>何を積んだか</b>を戻り値で見ます。
            $dp = $ctrl->dispatchPredictionJobs('2026-10-24', '9', '99', '9', 7);
            if (!$W::jobs()) {
                $check('J-32', 'Jobの段が下りていれば積まない',
                       [(string) $dp['code'], (array) $dp['dispatched']],
                       ['JOBS_OFF', []]);
                $check('J-33', 'Jobの段が下りていれば何も走らせない',
                       (bool) $dp['ran'], false);
            } else {
                $check('J-32', '不足している role と集計を積む',
                       (string) $dp['code'], 'DISPATCHED');
                // @ANCHOR-20261023-NARU-DEADLINE  先に期限での集計（deadline）を確保するようになりました
                // $check('J-33', '積んだのは role2本＋集計1本', (array) $dp['dispatched'],
                $check('J-33', '積んだのは role2本＋集計1本（期限の予約は先頭に付くことがある）',
                       array_values(array_diff((array) $dp['dispatched'], ['deadline'])),
                       ['role:1st', 'role:2nd', 'aggregate']);
            }

            // ── 旧経路のレースには積まない ──────────────────────────
            $sid2 = (int) \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => '20261024-9-99-9-8', 'generation' => 0,
                'run_id' => 'JT2-' . $now, 'release_version' => 'r12.2',
                'common_json' => '{}', 'input_json_1st' => '{}',
                'input_json_2nd' => '{}',
                'snapshot_hash' => hash('sha256', 'jobtest2' . $now),
                'frozen_nums' => '[1,2]', 'frozen_rank' => '[1,2]',
                'mode' => 'MIXED', 'mode_status' => 'FIXED',
                'anchor_at' => $W::toDb($post),
                'cutoff_at' => $W::toDb($post - 360 * 1000000),
                'input_freeze_at' => $W::toDb($post - (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC) * 1000000),
                'frozen_at' => $W::toDb($now),
                'sales_close_at' => $W::toDb($sales),
                'created_at' => $W::toDb($now),
            ]);
            $W::ensureLive($sid2, $now);
            $A::fixProcessingPath($sid2, 'SYNC');
            $dp2 = $ctrl->dispatchPredictionJobs('2026-10-24', '9', '99', '9', 8);
            if ($W::jobs()) {
                $check('J-34', '旧経路で始まったレースには積まない',
                       [(string) $dp2['code'], (array) $dp2['dispatched']],
                       ['PATH_IS_SYNC', []]);
            } else {
                $check('J-34', 'Jobの段が下りていれば、やはり積まない',
                       (array) $dp2['dispatched'], []);
            }

            // ── ⑧ 実状態の変更（010の2-2 UpdateRaceLiveJob）──────────
            $this->info('── ⑧ 実状態の変更（取消は足すだけ・締切は前倒しだけ）──');
            $before = \DB::table('baganriki_live_states')
                        ->where('snapshot_id', $sid)->first();
            $rev0 = (int) $before->state_revision;

            $a1 = $ctrl->applyLiveChange($sid, ['cancellations' => [3]]);
            $check('J-35', '取消を入れられる',
                   [(string) $a1['code'], (array) $a1['cancellations']],
                   ['APPLIED', [3]]);
            $check('J-36', '版が1つ増える', (int) $a1['state_revision'], $rev0 + 1);

            $a2 = $ctrl->applyLiveChange($sid, ['cancellations' => [1]]);
            $check('J-37', '取消は足すだけ（前のものが残る）',
                   (array) $a2['cancellations'], [1, 3]);

            $a3 = $ctrl->applyLiveChange($sid, ['cancellations' => [3]]);
            $check('J-38', '同じ取消をもう一度入れても版は増えない',
                   [(string) $a3['code'], (int) $a3['state_revision']],
                   ['NO_CHANGE', $rev0 + 2]);

            $early = $sales - 120 * 1000000;     // 投票締切の2分前へ前倒し
            $a4 = $ctrl->applyLiveChange($sid, ['live_close_us' => $early]);
            $check('J-39', '締切を前倒しできる',
                   (int) $a4['live_close_us'], $early);

            $late = $sales - 10 * 1000000;       // さっきより後ろ（延長）
            $a5 = $ctrl->applyLiveChange($sid, ['live_close_us' => $late]);
            $check('J-40', '延長は受け付けない',
                   [(string) $a5['code'], (int) $a5['live_close_us']],
                   ['NO_CHANGE', $early]);

            $after = $sales + 60 * 1000000;      // 正本より後ろ
            $a6 = $ctrl->applyLiveChange($sid, ['live_close_us' => $after]);
            $check('J-41', '正本の投票締切より後ろも受け付けない',
                   (string) $a6['code'], 'NO_CHANGE');

            // 凍結済み本文を触っていないこと
            $snapNow = \DB::table('baganriki_snapshots')->where('id', $sid)->first();
            $check('J-42', '凍結済み本文は1バイトも変わっていない',
                   [(string) $snapNow->input_json_1st,
                    (string) $snapNow->input_json_2nd,
                    (string) $snapNow->sales_close_at],
                   ['{}', '{}', (string) $W::toDb($sales)]);
            $check('J-43', '実状態の変更でAIへは1回も送っていない',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $sid)->count(), 0);

            // 前倒しで期限が動いたこと
            $liveNow = \DB::table('baganriki_live_states')
                         ->where('snapshot_id', $sid)->first();
            $dlNow = $W::deadlines($snapNow, $liveNow);
            $dlOrig = $W::deadlines($snapNow, $before);
            $check('J-44', '前倒しのぶん応答期限も早くなる',
                   ((int) $dlNow['reply']) < ((int) $dlOrig['reply']), true);
            $this->line('      応答期限 : ' . \App\Http\Controllers\Api\BaganrikiTime
                            ::toJst((int) $dlOrig['reply'])
                      . ' → ' . \App\Http\Controllers\Api\BaganrikiTime
                            ::toJst((int) $dlNow['reply']));

            // ── ⑨ 監査に残っていること ─────────────────────────────
            $this->info('── ⑨ 監査に残っていること ──');
            $check('J-45', '経路の固定が監査に残る',
                   (int) \DB::table('baganriki_audit_events')
                       ->where('snapshot_id', $sid)
                       ->where('kind', $A::PATH_FIXED_AUDIT_KIND)->count(), 1);
            $check('J-46', '実状態の変更が監査に残る（変わった回数ぶん）',
                   (int) \DB::table('baganriki_audit_events')
                       ->where('snapshot_id', $sid)
                       ->where('kind', 'R122_LIVE_APPLIED')->count(), 3);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-NARU-LIVE  取得から実状態へ（なる 10/23 4-B）
            // ══════════════════════════════════════════════════════════
            $this->info('── ⑨-2 取得から実状態へ（取消・発走時刻の前倒し）──');
            $check('J-46b', '取消を入れると、いまの頭数も数え直す（4頭−取消2頭）',
                   (int) \DB::table('baganriki_live_states')
                       ->where('snapshot_id', $sid)->value('active_count'), 2);

            //   発走日の仮レース（場コード99・10R、凍結 [1,2]）を作り、旧経路（SYNC）に固定して試します。
            //   旧経路のレースは Job を積まず、その場で入れます。
            $postSec = intdiv($post, 1000000);
            $sid3 = (int) \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => $A::buildPredictionRaceId(date('Y-m-d', $postSec), '9', '99', '9', 10),
                'generation' => 0,
                'run_id' => 'JT3-' . $now, 'release_version' => 'r12.2',
                'common_json' => '{}', 'input_json_1st' => '{}',
                'input_json_2nd' => '{}',
                'snapshot_hash' => hash('sha256', 'jobtest3' . $now),
                'frozen_nums' => '[1,2]', 'frozen_rank' => '[1,2]',
                'mode' => 'MIXED', 'mode_status' => 'FIXED',
                'anchor_at' => $W::toDb($post),
                'cutoff_at' => $W::toDb($post - 360 * 1000000),
                'input_freeze_at' => $W::toDb($post - (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC) * 1000000),
                'frozen_at' => $W::toDb($now),
                'sales_close_at' => $W::toDb($sales),
                'created_at' => $W::toDb($now),
            ]);
            $W::ensureLive($sid3, $now);
            $A::fixProcessingPath($sid3, 'SYNC');
            $mkRace = static function (int $startSec) use ($postSec) {
                return (object) [
                    'date' => date('Y-m-d', $postSec), 'kaisuu' => '9', 'basho' => '99',
                    'day' => '9', 'race' => 10, 'start_time' => date('H:i:s', $startSec),
                ];
            };
            // 秒を切り上げて、凍結のときの締切と同じか後ろになる発走時刻（＝前倒しではない）
            $sameStart = $postSec + 1;
            $payCancel = '[{"num":"1","tan":"3.5","fuku_min":"1.2","fuku_max":"1.8"},'
                       . '{"num":"2","tan":"取消","fuku_min":"取消","fuku_max":"取消"}]';
            $L1 = $A::syncLiveFromAcquisition($mkRace($sameStart), $payCancel);
            $check('L-01', '原ペイロードの「取消」を実状態へ足す',
                   [(bool) $L1['ran'], (string) $L1['code'], (array) $L1['cancellations']],
                   [true, 'APPLIED', [2]]);
            $lv3 = \DB::table('baganriki_live_states')->where('snapshot_id', $sid3)->first();
            $check('L-02', '実状態の取消といまの頭数',
                   [json_decode((string) $lv3->cancellations, true), (int) $lv3->active_count],
                   [[2], 1]);
            $L2 = $A::syncLiveFromAcquisition($mkRace($sameStart), $payCancel);
            $check('L-03', '同じ取得をもう一度読んでも何もしない',
                   [(bool) $L2['ran'], (string) $L2['code']], [false, 'NO_CHANGE']);
            $L3 = $A::syncLiveFromAcquisition($mkRace($sameStart),
                   '[{"num":"1","tan":"取消"},{"num":"1","tan":"2.0"}]');
            $check('L-04', '馬番が重複する原ペイロードは使わない',
                   (string) $L3['code'], 'NO_CHANGE');
            $L4 = $A::syncLiveFromAcquisition($mkRace($sameStart),
                   '[{"num":"9","tan":"取消"}]');
            $check('L-05', '凍結集合にいない馬の取消は入れない',
                   (string) $L4['code'], 'NO_CHANGE');
            $L5 = $A::syncLiveFromAcquisition($mkRace($sameStart), '{"num":');
            $check('L-06', '厳密に読めない原ペイロードは使わない',
                   (string) $L5['code'], 'NO_CHANGE');
            $earlyStart = $postSec - 300;      // 発走が5分早まった
            $L6 = $A::syncLiveFromAcquisition($mkRace($earlyStart), '[]');
            $check('L-07', '発走が早まったら締切を前倒しする（発走1分前）',
                   [(string) $L6['code'], (int) $L6['live_close_us']],
                   ['APPLIED', ($earlyStart - 60) * 1000000]);
            $L7 = $A::syncLiveFromAcquisition($mkRace($postSec + 600), '[]');
            $check('L-08', '発走が遅くなっても延長しない',
                   (string) $L7['code'], 'NO_CHANGE');
            $noSnap = $mkRace($sameStart); $noSnap->race = 11;
            $check('L-09', '凍結していないレースでは何もしない',
                   (string) $A::syncLiveFromAcquisition($noSnap, $payCancel)['code'], 'NO_SNAPSHOT');
            $check('L-10', '取得から実状態へ入れてもAIへは1回も送っていない',
                   (int) \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $sid3)->count(), 0);
            $snap3 = \DB::table('baganriki_snapshots')->where('id', $sid3)->first();
            $check('L-11', '凍結済み本文と正本の締切は変わっていない',
                   [(string) $snap3->input_json_1st, (string) $snap3->sales_close_at],
                   ['{}', (string) $W::toDb($sales)]);

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-NARU-STARTTIME  発走時刻の前倒しは、オッズ取得の成否と関係なく効く（なる 10/23 2通目 3章）
            // ══════════════════════════════════════════════════════════
            //   実在のレース1件の行を、場コード95・12R・発走10分後として写します。
            //   オッズ取得（ImportKeibaOdds）は一度も呼びません（取得対象外・取得失敗の場面と同じ）。
            //   そのうえでレース正本の発走時刻だけを早めて、送信の予約・公開・期限が新しい時刻で
            //   判定されることを確かめます。実状態へ書く前（更新Jobが動く前）でも効くことを見ます。
            $this->info('── ⑨-3 発走時刻の前倒し（オッズ取得なしで効くか）──');
            $srcRace = null;
            foreach (\DB::table('t_horse_odds_finder_races')->where('basho', '<', '90')
                        ->orderByDesc('date')->limit(20)->get() as $rr) {
                if (preg_match('/^\d{1,2}:\d{2}/', (string) ($rr->start_time ?? ''))) { $srcRace = $rr; break; }
            }
            if ($srcRace === null) {
                $check('S-00', '写し元のレースがある', false, true);
            } else {
                $pS   = intdiv($post, 1000000);              // 発走（秒）
                $sDate = date('Y-m-d', $pS);
                $raRow = (array) $srcRace; unset($raRow['id']);
                $raRow['date'] = $sDate; $raRow['basho'] = '95'; $raRow['race'] = 12;
                $raRow['start_time'] = date('H:i:s', $pS);
                \DB::table('t_horse_odds_finder_races')->where('date', $sDate)
                  ->where('kaisuu', $raRow['kaisuu'])->where('basho', '95')
                  ->where('day', $raRow['day'])->where('race', 12)->delete();
                \DB::table('t_horse_odds_finder_races')->insert($raRow);
                $rid5 = $A::buildPredictionRaceId($sDate, $raRow['kaisuu'], '95', $raRow['day'], 12);
                $sales5 = ($pS - 60) * 1000000;
                $sid5 = (int) \DB::table('baganriki_snapshots')->insertGetId([
                    'race_id' => $rid5, 'generation' => 0,
                    'run_id' => 'JT5-' . $now, 'release_version' => 'r12.2',
                    'common_json' => '{}', 'input_json_1st' => '{}', 'input_json_2nd' => '{}',
                    'snapshot_hash' => hash('sha256', 'jobtest5' . $now),
                    'frozen_nums' => '[1,2]', 'frozen_rank' => '[1,2]',
                    'mode' => 'MIXED', 'mode_status' => 'FIXED',
                    'anchor_at' => $W::toDb($pS * 1000000),
                    'cutoff_at' => $W::toDb(($pS - 360) * 1000000),
                    'input_freeze_at' => $W::toDb(($pS - (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC)) * 1000000),
                    'frozen_at' => $W::toDb($now), 'sales_close_at' => $W::toDb($sales5),
                    'created_at' => $W::toDb($now),
                ]);
                $W::ensureLive($sid5, $now);
                $A::fixProcessingPath($sid5, 'JOB');
                $snap5 = fn() => \DB::table('baganriki_snapshots')->where('id', $sid5)->first();
                $live5 = fn() => \DB::table('baganriki_live_states')->where('snapshot_id', $sid5)->first();

                $d0 = $W::deadlines($snap5(), $live5());
                $check('S-01', '発走時刻が凍結時のままなら、凍結時の締切で期限を出す',
                       [(bool) $d0['start_time_used'], (int) $d0['publish']], [false, $sales5 - 60 * 1000000]);

                // 正本の発走時刻だけを「いまから90秒後」へ早めます（＝投票締切30秒後・公開期限はもう過ぎた）
                $newStartS = intdiv($W::nowUs(), 1000000) + 90;
                \DB::table('t_horse_odds_finder_races')->where('date', $sDate)
                  ->where('kaisuu', $raRow['kaisuu'])->where('basho', '95')
                  ->where('day', $raRow['day'])->where('race', 12)
                  ->update(['start_time' => date('H:i:s', $newStartS)]);
                $d1 = $W::deadlines($snap5(), $live5());
                $check('S-02', '実状態へ書く前でも、正本の新しい発走時刻で期限を出す（取得なし）',
                       [(bool) $d1['start_time_used'], (int) $d1['live_close'], $live5()->live_close],
                       [true, ($newStartS - 60) * 1000000, null]);
                $rv5 = $W::reserve($sid5, '1st', $W::FIRST, $W::nowUs(), []);
                $check('S-03', '新しい期限を過ぎていれば、送信の予約をしない', (string) $rv5['code'], 'DEADLINE_PASSED');
                $pb5 = $ctrl->publishPrediction($sid5);
                $check('S-04', '新しい期限を過ぎていれば、公開しない', (string) $pb5['code'], 'DEADLINE_PASSED');

                // 発走時刻の更新処理（keiba:repairStartTime）と同じ入口から実状態へ入れます
                $sr5 = $A::syncLiveStartTimeForRace($sDate, '95', 12);
                $codes5 = implode(',', (array) $sr5['codes']);
                $check('S-05', 'repairStartTime の入口から実状態へ入る（Jobの段なら更新Jobを積む）',
                       [(int) $sr5['checked'], (int) $sr5['ran'],
                        (strpos($codes5, 'APPLIED') !== false || strpos($codes5, 'JOB_DISPATCHED') !== false)],
                       [1, 1, true]);
                if (strpos($codes5, 'APPLIED') !== false) {
                    $check('S-06', '実状態の締切が新しい時刻になる',
                           $W::dbUs($live5()->live_close), ($newStartS - 60) * 1000000);
                    $sr5b = $A::syncLiveStartTime($sid5, 'sweep');
                    $check('S-07', '巡回の入口でもう一度見ても、変わりがなければ何もしない',
                           [(bool) $sr5b['ran'], (string) $sr5b['code']], [false, 'NO_CHANGE']);
                }
                // 発走が遅くなった（延長）場合は使いません
                \DB::table('t_horse_odds_finder_races')->where('date', $sDate)
                  ->where('kaisuu', $raRow['kaisuu'])->where('basho', '95')
                  ->where('day', $raRow['day'])->where('race', 12)
                  ->update(['start_time' => date('H:i:s', $pS + 600)]);
                $check('S-08', '発走が遅くなっても期限は延ばさない（正本の値は使わない）',
                       $W::closeFromRaceStartTime($snap5()), null);
                \DB::table('t_horse_odds_finder_races')->where('date', $sDate)
                  ->where('kaisuu', $raRow['kaisuu'])->where('basho', '95')
                  ->where('day', $raRow['day'])->where('race', 12)
                  ->update(['start_time' => 'XXX']);
                // $check('S-09', '発走済みの印（XXX）でも止まらず、正本の値は使わない',
                //        [$W::closeFromRaceStartTime($snap5()), (bool) $W::deadlines($snap5(), $live5())['ok']],
                $d9 = $W::deadlines($snap5(), $live5());
                $check('S-09', '発走済みの印（XXX）は正本の時刻として使わず、期限も出さない（RACE_STARTED）',
                       [$W::closeFromRaceStartTime($snap5()), (bool) $d9['ok'], (string) $d9['code']],
                       [null, false, 'RACE_STARTED']);
                $check('S-10', 'ここまでAIへは1回も送っていない',
                       (int) \DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid5)->count(), 0);
            }

            // ── 旧 B-01〜B-08（なる 10/23 4通目のご指摘で下の版に置き換え。消さずに残します）──
            // // ══════════════════════════════════════════════════════════
            // // @ANCHOR-20261023-NARU-BUDGET  送信前の予算制御（なる 10/23 2通目 6章）
            // // ══════════════════════════════════════════════════════════
            // $this->info('── ⑨-4 送信前の予算制御 ──');
            // $origB = (string) env('BAGANRIKI_PREDICTION_BUDGET_USD_DAY', '');
            // $setB = static function (string $v) {
            // putenv('BAGANRIKI_PREDICTION_BUDGET_USD_DAY' . ($v === '' ? '' : '=' . $v));
            // if ($v === '') { unset($_ENV['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'], $_SERVER['BAGANRIKI_PREDICTION_BUDGET_USD_DAY']); }
            // else { $_ENV['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'] = $v; $_SERVER['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'] = $v; }
            // };
            // try {
            // $setB('');
            // $b0 = $A::budgetReserve('1st', 'claude-haiku-5-5', 'BT', 186000, 65536);
            // $check('B-01', '上限を書かなければ制御しない（予約も残さない）', [(bool) $b0['ok'], $b0['hold']], [true, null]);
            // $base = $A::spentTodayUsd($W::nowUs());
            // $setB((string) round($base + 0.5, 6));
            // $b1 = $A::budgetReserve('1st', 'claude-haiku-5-5', 'BT', 900000, 65536);
            // $check('B-02', '最悪額（約0.73ドル）が残りの予算（0.5ドル）を超えるなら送らない', (bool) $b1['ok'], false);
            // $b2 = $A::budgetReserve('1st', 'claude-haiku-5-5', 'BT', 186000, 65536);
            // $check('B-03', '最悪額（約0.28ドル）が収まるなら送り、最悪額を予約する',
            // [(bool) $b2['ok'], is_string($b2['hold'])], [true, true]);
            // $b3 = $A::budgetReserve('1st', 'claude-haiku-5-5', 'BT', 186000, 65536);
            // $check('B-04', '予約中の額も数えるので、2回目は送らない（同時に送っても上限を超えない）', (bool) $b3['ok'], false);
            // // 1回目の実際の usage が届いた（実際の額 0.02ドル）→ 予約は実際の額に置き換わる
            // $W::event('BT', null, 'R122_USAGE', null, null,
            // ['role' => '1st', 'cost_usd' => 0.02, 'budget_hold' => $b2['hold']], '1st#0', $W::nowUs());
            // $check('B-05', 'usage が届けば予約の額ではなく実際の額で数える',
            // round($A::spentTodayUsd($W::nowUs()) - $base, 6), 0.02);
            // $b4 = $A::budgetReserve('1st', 'claude-haiku-5-5', 'BT', 186000, 65536);
            // $check('B-06', '実際の額に置き換わったので、次の1回は送れる', (bool) $b4['ok'], true);
            // // 結果不明（usage なし）は予約した最悪額のまま数える
            // $W::event('BT', null, 'R122_USAGE', null, null,
            // ['role' => '1st', 'cost_usd' => null, 'budget_hold' => $b4['hold']], '1st#1', $W::nowUs());
            // $check('B-07', '結果不明（usage なし）は最悪額のまま数える',
            // round($A::spentTodayUsd($W::nowUs()) - $base, 6), round(0.02 + (float) $b4['worst'], 6));
            // $check('B-08', '実際の額の計算（10/23 実測の1st初回 usage）',
            // round((float) $A::costFromUsage('1st', 'claude-haiku-5-5',
            // ['input_tokens' => 448876, 'output_tokens' => 23896]), 6), 0.284178);
            // } finally {
            // $setB($origB);
            // }

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-NARU-BUDGET2  送信前の予算制御（なる 10/23 4通目 3章で直した版）
            // ══════════════════════════════════════════════════════════
            //   予約・精算は予算専用の接続で確定させて書くので、この試験のロールバックでは消えません。
            //   この試験で書いた行は、最後に race_id で消します（実在のレースには触りません）。
            $this->info('── ⑨-4 送信前の予算制御（不完全な usage・別接続での確定）──');
            $origB = (string) env('BAGANRIKI_PREDICTION_BUDGET_USD_DAY', '');
            $setB = static function (string $v) {
                putenv('BAGANRIKI_PREDICTION_BUDGET_USD_DAY' . ($v === '' ? '' : '=' . $v));
                if ($v === '') { unset($_ENV['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'], $_SERVER['BAGANRIKI_PREDICTION_BUDGET_USD_DAY']); }
                else { $_ENV['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'] = $v; $_SERVER['BAGANRIKI_PREDICTION_BUDGET_USD_DAY'] = $v; }
            };
            $btRid = 'BT-' . substr((string) $W::nowUs(), -8);
            $btHolds = [];   // 20261025：B-16 で作る予約番号（後片付け用）
            try {
                $setB('');
                $b0 = $A::budgetReserve('1st', 'claude-haiku-5-5', $btRid, 186000, 65536);
                $check('B-01', '上限を書かなければ制御しない（予約も残さない）', [(bool) $b0['ok'], $b0['hold']], [true, null]);
                // usage の検証（不完全なら費用不明＝null。0ドルにしない）
                $cf = fn($u) => $A::costFromUsage('1st', 'claude-haiku-5-5', $u);
                $check('B-02', '空・入力欠け・出力欠け・負・整数でない usage は費用不明（null）',
                       [$cf([]), $cf(['output_tokens' => 23896]), $cf(['input_tokens' => 448876]),
                        $cf(['input_tokens' => -1, 'output_tokens' => 1]), $cf(['input_tokens' => '448876', 'output_tokens' => 1]), $cf(null)],
                       [null, null, null, null, null, null]);
                $check('B-03', '2nd：キャッシュ内訳が合わない usage も費用不明、料金表に無いモデル名も費用不明',
                       [$A::costFromUsage('2nd', 'deepseek-flash', ['prompt_tokens' => 100, 'prompt_cache_hit_tokens' => 10,
                            'prompt_cache_miss_tokens' => 80, 'completion_tokens' => 5]),
                        $A::costFromUsage('1st', 'claude-unknown-model', ['input_tokens' => 1, 'output_tokens' => 1])],
                       [null, null]);
                $check('B-04', '正しい usage は計算する（10/23 実測の1st初回）',
                       round((float) $cf(['input_tokens' => 448876, 'output_tokens' => 23896]), 6), 0.284178);
                $u2 = ['prompt_tokens' => 335687, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 335687, 'completion_tokens' => 25966];
                $check('B-05', '2nd は保守的見積り（ピーク単価）と請求見込み（土曜はオフピーク）を分ける',
                       [round((float) $A::costFromUsage('2nd', 'deepseek-flash', $u2, 'conservative'), 6),
                        round((float) $A::costFromUsage('2nd', 'deepseek-flash', $u2, 'estimate', strtotime('2026-10-24 03:00:00 UTC') * 1000000), 6)],
                       [0.131865, 0.065933]);

                $base = $A::spentTodayUsd($W::nowUs());
                $setB((string) round($base + 0.5, 6));
                $check('B-06', '料金表に無いモデルは、予算を設定しているとき送らない',
                       (string) $A::budgetReserve('1st', 'claude-unknown-model', $btRid, 1000, 100)['code'], 'MODEL_NOT_PRICED');
                $b1 = $A::budgetReserve('1st', 'claude-haiku-5-5', $btRid, 900000, 65536);
                $check('B-07', '最悪額（約0.73ドル）が残り（0.5ドル）を超えるなら送らない', (string) $b1['code'], 'BUDGET_EXCEEDED');
                $b2 = $A::budgetReserve('1st', 'claude-haiku-5-5', $btRid, 186000, 65536);
                $check('B-08', '収まるなら最悪額（約0.28ドル）を予約する', [(string) $b2['code'], is_string($b2['hold'])], ['RESERVED', true]);
                $seen = (int) $A::budgetDb()->table('baganriki_audit_events')->where('kind', 'R122_BUDGET_HOLD')
                          ->where('related_id', (string) $b2['hold'])->count();
                $check('B-09', '予約は呼び出し側のトランザクション（この試験は中にいる）と別に確定している（別接続から見える）', $seen, 1);
                // 空の usage が届いた（費用不明）→ 予約額のまま
                $sidB = $sid2;   // 旧経路に固定した試験用の凍結入力（使用量の件数は数えていません）
                $A::recordAiUsage($sidB, '1st', 0, ['code' => 'OK', 'model' => 'claude-haiku-5-5', 'requested_model' => 'claude-haiku-5-5',
                                                   'usage' => [], 'budget_hold' => $b2['hold'], 'budget_worst_usd' => $b2['worst']]);
                $check('B-10', '空の usage（{}）は0ドル扱いにせず、予約額のまま数える（recordAiUsage→精算→集計の経路）',
                       round($A::spentTodayUsd($W::nowUs()) - $base, 6), round((float) $b2['worst'], 6));
                $check('B-11', 'その状態で次の予約は通らない', (string) $A::budgetReserve('1st', 'claude-haiku-5-5', $btRid, 186000, 65536)['code'], 'BUDGET_EXCEEDED');
                // 正しい usage の精算で置き換わる
                $b3h = $b2['hold'];
                $A::budgetSettle($btRid, '1st', 0, ['requested_model' => 'claude-haiku-5-5', 'budget_hold' => $b3h, 'budget_worst_usd' => $b2['worst']],
                                 ['input_tokens' => 50000, 'output_tokens' => 10000]);
                $check('B-12', '正しい usage の精算が来れば、その保守的見積り額に置き換わる',
                       round($A::spentTodayUsd($W::nowUs()) - $base, 6), 0.01);
                // 送らなかった予約は0ドルで精算
                $b4 = $A::budgetReserve('1st', 'claude-haiku-5-5', $btRid, 186000, 65536);
                $A::budgetRelease($b4['hold'], $btRid, '試験');
                $check('B-13', '送らなかった予約（RELEASE）は0ドル', round($A::spentTodayUsd($W::nowUs()) - $base, 6), 0.01);

                // ── @ANCHOR-20261025-NARU-USAGENULL  キャッシュ項目が「無い」と「あるが null」を分ける（なる 10/23 5通目 4章）──
                $uIn = ['input_tokens' => 50000, 'output_tokens' => 10000];
                $check('B-14', '1st：キャッシュ2項目が null なら費用不明、無ければ0として計算する',
                       [$cf($uIn + ['cache_creation_input_tokens' => null]), $cf($uIn + ['cache_read_input_tokens' => null]),
                        round((float) $cf($uIn), 6), round((float) $cf($uIn + ['cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]), 6)],
                       [null, null, 0.01, 0.01]);
                $c2f = fn($u) => $A::costFromUsage('2nd', 'deepseek-flash', $u);
                $check('B-15', '2nd：hit／miss が null なら費用不明、無ければ prompt_tokens から補う',
                       [$c2f(['prompt_tokens' => 1000, 'completion_tokens' => 10, 'prompt_cache_hit_tokens' => null]),
                        $c2f(['prompt_tokens' => 1000, 'completion_tokens' => 10, 'prompt_cache_miss_tokens' => null]),
                        $c2f(['prompt_tokens' => 1000, 'completion_tokens' => 10, 'prompt_cache_hit_tokens' => null, 'prompt_cache_miss_tokens' => 1000]),
                        round((float) $c2f(['prompt_tokens' => 1000, 'completion_tokens' => 10]), 6)],
                       [null, null, null, 0.000312]);
                // recordAiUsage → 精算 → 集計の経路（null のキャッシュ項目は予約額のまま残る）
                $setB((string) round($A::spentTodayUsd($W::nowUs()) + 1.0, 6));
                $before14 = $A::spentTodayUsd($W::nowUs());
                foreach (['cache_creation_input_tokens', 'cache_read_input_tokens'] as $ix => $nk) {
                    $bN = $A::budgetReserve('1st', 'claude-haiku-5-5', $btRid, 186000, 65536);
                    $btHolds[] = $bN['hold'];
                    $A::recordAiUsage($sidB, '1st', 10 + $ix, ['code' => 'OK', 'model' => 'claude-haiku-5-5', 'requested_model' => 'claude-haiku-5-5',
                                                                'usage' => $uIn + [$nk => null], 'budget_hold' => $bN['hold'], 'budget_worst_usd' => $bN['worst'],
                                                                'sent_at_us' => $W::nowUs()]);
                    $evN = json_decode((string) \DB::table('baganriki_audit_events')->where('snapshot_id', $sidB)->where('kind', 'R122_USAGE')
                              ->where('related_id', '1st#' . (10 + $ix))->orderByDesc('id')->value('diagnostics'), true);
                    $check('B-16' . ($ix === 0 ? 'a' : 'b'), $nk . '=null：記録は費用不明・usage_valid=false、集計は予約額（約0.28ドル）のまま',
                           [array_key_exists('cost_usd_conservative', (array) $evN) ? $evN['cost_usd_conservative'] : 'x',
                            array_key_exists('usage_valid', (array) $evN) ? $evN['usage_valid'] : 'x',
                            round($A::spentTodayUsd($W::nowUs()) - $before14, 6)],
                           [null, false, round((float) $bN['worst'] * ($ix + 1), 6)]);
                }

                // ── @ANCHOR-20261025-NARU-SENTAT  請求見込みは送信時刻で計算する（なる 10/23 5通目 7章）──
                //   10/23（金）12:59:50 JST 送信＝UTC 03:59:50（ピーク）。13:00:10 JST＝UTC 04:00:10（オフピーク）。
                //   記録はいま（日曜）行うので、もし記録時刻で計算していればどちらもオフピークになります。
                $peakUs = strtotime('2026-10-23 12:59:50 Asia/Tokyo') * 1000000;
                $offUs  = strtotime('2026-10-23 13:00:10 Asia/Tokyo') * 1000000;
                $est = function (array $extra, int $no) use ($A, $sidB, $u2) {
                    $A::recordAiUsage($sidB, '2nd', $no, ['code' => 'OK', 'model' => 'deepseek-flash', 'requested_model' => 'deepseek-flash',
                                                          'usage' => $u2, 'budget_hold' => null] + $extra);
                    $d = json_decode((string) \DB::table('baganriki_audit_events')->where('snapshot_id', $sidB)->where('kind', 'R122_USAGE')
                           ->where('related_id', '2nd#' . $no)->orderByDesc('id')->value('diagnostics'), true);
                    $d = (array) $d;   // null を「無い」と取り違えないよう array_key_exists で読みます
                    return [array_key_exists('cost_usd_estimate', $d) ? $d['cost_usd_estimate'] : 'x',
                            array_key_exists('cost_usd_conservative', $d) ? $d['cost_usd_conservative'] : 'x'];
                };
                $check('B-17', '送信 12:59:50（ピーク）→ 見込みはピーク単価（記録は13時以降でも）', $est(['sent_at_us' => $peakUs], 21), [0.131865, 0.131865]);
                $check('B-18', '送信 13:00:10（オフピーク）→ 見込みはオフピーク単価、保守的見積りはピークのまま', $est(['sent_at_us' => $offUs], 22), [0.065933, 0.131865]);
                $check('B-19', '整数が無くても sent_at（日本時間の文字列）から読む', $est(['sent_at' => '2026-10-23T12:59:50.000000+09:00'], 23), [0.131865, 0.131865]);
                $check('B-20', '送信時刻が無い・読めないときは、見込みは不明（null）。保守的見積りは出す',
                       [$est([], 24), $est(['sent_at' => 'こわれた時刻'], 25)], [[null, 0.131865], [null, 0.131865]]);
            } finally {
                $setB($origB);
                $bdb = $A::budgetDb();
                $ids = $bdb->table('baganriki_audit_events')->where('race_id', $btRid)->pluck('id')->all();
                // B-10 の精算は凍結入力の race_id で書かれるので、予約番号でも探します
                if (isset($b2['hold']) && is_string($b2['hold'])) {
                    $ids = array_merge($ids, $bdb->table('baganriki_audit_events')
                        ->whereIn('kind', ['R122_BUDGET_SETTLE', 'R122_BUDGET_HOLD', 'R122_BUDGET_RELEASE'])
                        ->where('related_id', $b2['hold'])->pluck('id')->all());
                }
                // 20261025：B-16 の予約番号の精算も消します
                foreach ($btHolds as $hB) {
                    if (!is_string($hB)) { continue; }
                    $ids = array_merge($ids, $bdb->table('baganriki_audit_events')
                        ->whereIn('kind', ['R122_BUDGET_SETTLE', 'R122_BUDGET_HOLD', 'R122_BUDGET_RELEASE'])
                        ->where('related_id', $hB)->pluck('id')->all());
                }
                foreach (array_values(array_unique(array_map('intval', $ids))) as $idB) {
                    $bdb->table('baganriki_audit_events')->where('id', $idB)->delete();
                }
            }

            // ── ⑩ 対象レースを「全入口」で見ていること（010の2-5）────
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-TARGET12  入口を名指しで確かめます
            // ══════════════════════════════════════════════════════════
            //   関数の行範囲を取って、その中に判定の呼び出しがあるかを
            //   見ます。判定を書き写していないこと（同じ関数を呼んで
            //   いること）も、これで分かります。
            $this->info('── ⑩ 対象レースを全入口で見ていること ──');
            $rcT  = new \ReflectionClass($A);
            $fileT = (array) file((string) $rcT->getFileName());
            $sliceT = static function (string $m) use ($rcT, $fileT): string {
                if (!$rcT->hasMethod($m)) { return ''; }
                $r = $rcT->getMethod($m);
                return implode('', array_slice($fileT, $r->getStartLine() - 1,
                    $r->getEndLine() - $r->getStartLine() + 1));
            };
            foreach ([
                'J-47' => ['sendInitialAiRequest',      '初回の送信許可の直前'],
                'J-48' => ['sendCorrectionRequest',        '訂正の送信許可の直前'],
                'J-49' => ['sweepPendingPredictions',   '巡回'],
                'J-51' => ['dispatchPredictionJobs', 'Jobを積む入口'],
            ] as $idT => $pairT) {
                [$mT, $labelT] = $pairT;
                $bodyT = $sliceT($mT);
                $check($idT, $labelT . 'で対象レースを見ている',
                    (strpos($bodyT, 'checkPredictionTarget(') !== false), true);
            }
            $bodyRJ = (string) @file_get_contents(
                base_path('app/Jobs/PredictionRunRoleJob.php'));
            $check('J-52', '待ち行列から出たときも見ている',
                (strpos($bodyRJ, 'checkPredictionTarget(') !== false), true);

            // ── ⑪ 実モデルと使用量が残ること（010の7章⑤）──────────────
            $this->info('── ⑪ 実モデルと使用量（010の7章⑤）──');
            $A::recordAiUsage($sid, '1st', $W::FIRST, [
                'ok' => true, 'code' => 'OK', 'complete' => true,
                'raw' => str_repeat('x', 1234),
                'model' => 'claude-haiku-4-5',
                'stop'  => 'end_turn',
                'usage' => ['input_tokens' => 160207, 'output_tokens' => 12200],
            ]);
            $A::recordAiUsage($sid, '2nd', $W::FIRST, [
                'ok' => true, 'code' => 'OK', 'complete' => true,
                'raw' => str_repeat('y', 555),
                'model' => 'deepseek-chat',
                'stop'  => 'stop',
                'usage' => ['prompt_tokens' => 90000, 'completion_tokens' => 4000],
            ]);
            $uz = $A::aiUsageOf($sid);
            $check('J-53', '使用量が2件残る', count((array) $uz['rows']), 2);
            $check('J-54', '応答が名乗ったモデル名で集計される',
                array_keys((array) $uz['by_model']),
                ['claude-haiku-4-5', 'deepseek-chat']);
            $check('J-55', 'Anthropic の数え方（input/output）を読める',
                [(int) $uz['by_model']['claude-haiku-4-5']['in'],
                 (int) $uz['by_model']['claude-haiku-4-5']['out']],
                [160207, 12200]);
            $check('J-56', 'DeepSeek の数え方（prompt/completion）も読める',
                [(int) $uz['by_model']['deepseek-chat']['in'],
                 (int) $uz['by_model']['deepseek-chat']['out']],
                [90000, 4000]);
            $check('J-57', 'finish_reason（stop_reason）も残る',
                [(string) $uz['rows'][0]['stop'], (string) $uz['rows'][1]['stop']],
                ['end_turn', 'stop']);
            $this->line('      内訳 : '
                . json_encode($uz['by_model'], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('  × 例外 : ' . $e->getMessage());
            $this->error('    ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
        }

        $n1 = (int) \DB::table('baganriki_snapshots')
                ->where('race_id', 'like', '20261024-9-99-9-%')->count();
        $n2 = (int) \DB::table('jobs')->count();
        $this->line('');
        $this->line(sprintf('  ロールバック後  仮snapshot %d 件／jobs 表 %d 行',
            $n1, $n2));
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        if ($fail === 0 && $ok) {
            $this->info(' Job構成は契約どおりに積まれました（DBへ残る書き込み0件）');
        } else {
            $this->error(' 通らなかった箇所があります。上の × をご確認ください');
        }
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-TYPETEST  型保持の通し試験（章）
     * ═══════════════════════════════════════════════════════════════════════
     * 10月21日010（なる）の第3章ぜんぶに対する試験です。
     *
     *
     * ■ 2段に分けています
     *   ①（T群）  読み・書き・比べの単体。6例そのものをここで通します。
     *   ②（P群）  <b>本番の関数で</b>診断→訂正比較→保存→読戻しまで通します。
     *             inspectAiResponse ／ acceptedHorsesOf ／
     *             saveValidationResult ／ normalizedPartOf を実際に呼びます。
     *
     * ■ 「地図なし」との対比を出します
     *   型の地図を渡さないと何を見逃すかを、同じ材料で並べて出します。
     *   PHPの === では
     *     ・2e0 と 2.0        どちらも float(2.0) なので<b>同じ</b>に見えます
     *     ・{} と []          どちらも空配列なので<b>同じ</b>に見えます
     *     ・1.999…（数値）と "1.999…"（文字列）
     *                         どちらもPHP文字列なので<b>同じ</b>に見えます
     *   地図を渡すと、どれも別物として見ます。
     *
     * ■ 実AIは1回も呼びません。全部ロールバックします。
     */
    private function runTypePreserveTest(string $root): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $R = \App\Http\Controllers\Api\BaganrikiResponse::class;
        $J = \App\Http\Controllers\Api\BaganrikiJson::class;
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;

        $vDir = rtrim($root, '/') . '/validation/normal';
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 型保持の通し試験（010の3章）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  資料     : ' . $vDir);
        $this->warn('  ※ 実AIは1回も呼びません');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');

        foreach (['MIXED_N16_1st_input.json', 'MIXED_N16_1st_output.json',
                  'MIXED_N16_2nd_input.json', 'MIXED_N16_2nd_output.json'] as $fN) {
            if (!is_file($vDir . '/' . $fN)) {
                $this->error('  資料がありません: ' . $vDir . '/' . $fN);
                return;
            }
        }

        $pass = 0; $fail = 0;
        $check = function (string $id, string $what, $got, $want) use (&$pass, &$fail) {
            $okk = ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-10s %-54s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true)
                           . ' 期待=' . var_export($want, true))));
        };

        // ═══════════════════════════════════════════════════════════════
        // ① 読み・書き・比べの単体（3-5 の6例）
        // ═══════════════════════════════════════════════════════════════
        $this->info('── ① 3-5 の6例（読み・書き・比べ）──');

        // 例1 数値 1.99999999999999999999 と、同じ内容の文字列
        $aTxt = '{"x":1.99999999999999999999}';
        $bTxt = '{"x":"1.99999999999999999999"}';
        $da = $J::decode($aTxt); $db = $J::decode($bTxt);
        $check('T-01', 'どちらも走査を通る', [$da['ok'], $db['ok']], [true, true]);
        $check('T-02', '数値と数値文字列を別物と見る',
            $J::same($da['value'], $da['map'], $db['value'], $db['map'])['same'], false);
        $check('T-03', '数値の側は引用符なしで書き戻る',
            $J::encode($da['value'], $da['map']), '{"x":1.99999999999999999999}');
        $check('T-04', '文字列の側は文字列のまま',
            $J::encode($db['value'], $db['map']), '{"x":"1.99999999999999999999"}');
        $check('T-05', '往復しても型も値も残る',
            $J::sameJson($aTxt, $J::encode($da['value'], $da['map']))['same'], true);
        $check('T-06', '【参考】PHPの === では見分けられない',
            ($da['value'] === $db['value']), true);

        // 例2 整数1・小数1.0・指数1e0 と、整数必須の判定
        $i1 = $J::decode('{"v":1}'); $d1 = $J::decode('{"v":1.0}');
        $e1 = $J::decode('{"v":1e0}');
        $check('T-07', '1 と 1.0 は別物',
            $J::same($i1['value'], $i1['map'], $d1['value'], $d1['map'])['same'], false);
        $check('T-08', '1 と 1e0 も別物',
            $J::same($i1['value'], $i1['map'], $e1['value'], $e1['map'])['same'], false);
        $check('T-09', '1.0 と 1e0 も別物（表記が違う）',
            $J::same($d1['value'], $d1['map'], $e1['value'], $e1['map'])['same'], false);
        $check('T-10', '表記を無視すれば同じ値と見られる',
            $J::same($i1['value'], $i1['map'], $d1['value'], $d1['map'], false)['same'],
            true);
        $check('T-11', '1.0 を書き戻すと 1.0（1 に丸めない）',
            $J::encode($d1['value'], $d1['map']), '{"v":1.0}');
        $check('T-12', '1e0 を書き戻すと 1e0',
            $J::encode($e1['value'], $e1['map']), '{"v":1e0}');
        $check('T-13', '整数は PHP の int のまま（is_int が通る）',
            is_int($i1['value']['v']), true);
        $check('T-14', '1.0 は PHP の float（整数必須の検査が落とせる）',
            is_float($d1['value']['v']), true);
        $check('T-15', '1e0 も PHP の float', is_float($e1['value']['v']), true);
        $check('T-16', '【参考】1.0 と 1e0 は === では同じ',
            ($d1['value'] === $e1['value']), true);

        // 例3 []・{}・数値キーを持つオブジェクト
        $ar = $J::decode('{"a":[]}'); $ob = $J::decode('{"a":{}}');
        $check('T-17', '[] と {} を別物と見る',
            $J::same($ar['value'], $ar['map'], $ob['value'], $ob['map'])['same'], false);
        $check('T-18', '[] は [] のまま書き戻る',
            $J::encode($ar['value'], $ar['map']), '{"a":[]}');
        $check('T-19', '{} は {} のまま書き戻る',
            $J::encode($ob['value'], $ob['map']), '{"a":{}}');
        $check('T-20', '【参考】PHPでは空配列どちらも同じ',
            ($ar['value'] === $ob['value']), true);
        $nk = $J::decode('{"a":{"0":"x","1":"y"}}');
        $ls = $J::decode('{"a":["x","y"]}');
        $check('T-21', '数値キーのオブジェクトと配列を別物と見る',
            $J::same($nk['value'], $nk['map'], $ls['value'], $ls['map'])['same'], false);
        $check('T-22', '数値キーのオブジェクトは {} のまま書き戻る',
            $J::encode($nk['value'], $nk['map']), '{"a":{"0":"x","1":"y"}}');
        $check('T-23', '配列は [] のまま', $J::encode($ls['value'], $ls['map']),
            '{"a":["x","y"]}');
        $check('T-24', '【参考】PHPでは数値キーのオブジェクトと配列が同じ',
            ($nk['value'] === $ls['value']), true);

        // 例4 馬行の並べ替えと、値を変更した後の保存
        $srcT = '{"horses":[{"horse_no":3,"s":1.0},{"horse_no":1,"s":2e0},'
              . '{"horse_no":2,"s":3}]}';
        $dT = $J::decode($srcT);
        $vT = $dT['value'];
        $ordT = array_column($vT['horses'], 'horse_no');
        asort($ordT);
        $newH = []; $newM = $J::mapWithKind($J::emptyMap(), [], 'obj');
        $newM = $J::mapWithKind($newM, ['horses'], 'arr');
        $ixT = 0;
        foreach (array_keys($ordT) as $fromT) {
            $newH[] = $vT['horses'][$fromT];
            $newM = $J::mapMerge($newM,
                $J::mapMove($dT['map'], ['horses', (int) $fromT], ['horses', $ixT]));
            $ixT++;
        }
        $sortedT = ['horses' => $newH];
        $encT = $J::encode($sortedT, $newM);
        $check('T-25', '並べ替えても表記が付いてくる', $encT,
            '{"horses":[{"horse_no":1,"s":2e0},{"horse_no":2,"s":3},'
            . '{"horse_no":3,"s":1.0}]}');
        $check('T-26', '並べ替えた本文も走査を通る', $J::decode($encT)['ok'], true);
        $sortedT['horses'][1]['s'] = 9.5;
        $check('T-27', '値を変えたら、その値が数値として入る',
            $J::encode($sortedT, $newM),
            '{"horses":[{"horse_no":1,"s":2e0},{"horse_no":2,"s":9.5},'
            . '{"horse_no":3,"s":1.0}]}');
        $check('T-28', '変えていない位置の表記は残る',
            (strpos($J::encode($sortedT, $newM), '"s":2e0') !== false)
            && (strpos($J::encode($sortedT, $newM), '"s":1.0') !== false), true);

        // 例5 5,001個目の長い数値
        $bigT = '1.' . str_repeat('9', 40);
        $rowsT = [];
        for ($iT = 0; $iT < 5002; $iT++) { $rowsT[] = '{"n":' . $bigT . '}'; }
        $manyT = '{"rows":[' . implode(',', $rowsT) . ']}';
        $dmT = $J::decode($manyT);
        $check('T-29', '5,002件でも走査を通る', $dmT['ok'], true);
        $check('T-30', '型の地図は監査の5,000件で切れない（010の3-1）',
            (int) $dmT['stats']['number_paths'], 5002);
        $check('T-31', '監査に残す一覧は5,000件のまま',
            (int) $dmT['stats']['numbers'], 5000);
        $encM = $J::encode($dmT['value'], $dmT['map']);
        $check('T-32', '5,001個目も数値として書き戻る（文字列へ化けない）',
            substr_count($encM, '"n":"'), 0);
        $dm2T = $J::decode($encM);
        $check('T-33', '往復しても全件が同じ',
            $J::same($dmT['value'], $dmT['map'], $dm2T['value'], $dm2T['map'])['same'],
            true);
        $lastT = $dmT['value']['rows'][5001]['n'];
        $check('T-34', '5,001個目も丸まっていない',
            is_string($lastT) ? $lastT : 'not-string', $bigT);

        // 例6 同じ値の別インスタンスを使った正常な訂正
        $r1T = $J::decode('{"v":1.50}'); $r2T = $J::decode('{"v":1.50}');
        $check('T-35', '別に読んだ同じ本文は同じと見る（別インスタンス問題なし）',
            $J::same($r1T['value'], $r1T['map'], $r2T['value'], $r2T['map'])['same'],
            true);
        $check('T-36', '1.50 と 1 は別物（整数と小数の区別は残る）',
            $J::same($r1T['value'], $r1T['map'],
                     $J::decode('{"v":1}')['value'],
                     $J::decode('{"v":1}')['map'])['same'], false);
        $check('T-37', '1.50 は 1.50 のまま保存される（丸めない）',
            $J::encode($r1T['value'], $r1T['map']), '{"v":1.50}');

        // 比べ方の細かいところ
        $k1T = $J::decode('{"a":1,"b":2}'); $k2T = $J::decode('{"b":2,"a":1}');
        $check('T-38', 'オブジェクトのキー順は無視する',
            $J::same($k1T['value'], $k1T['map'], $k2T['value'], $k2T['map'])['same'],
            true);
        $l1T = $J::decode('{"a":[1,2]}'); $l2T = $J::decode('{"a":[2,1]}');
        $check('T-39', '配列の順序は保持する（違えば別物）',
            $J::same($l1T['value'], $l1T['map'], $l2T['value'], $l2T['map'])['same'],
            false);
        $check('T-40', '食い違った経路を返す',
            (string) $J::same($l1T['value'], $l1T['map'],
                              $l2T['value'], $l2T['map'])['where'], '["a",0]');
        $n1T = $J::decode('{"a":null}'); $s1T = $J::decode('{"a":"null"}');
        $check('T-41', 'null と "null" を別物と見る',
            $J::same($n1T['value'], $n1T['map'], $s1T['value'], $s1T['map'])['same'],
            false);
        $b1T = $J::decode('{"a":true}'); $t1T = $J::decode('{"a":1}');
        $check('T-42', 'true と 1 を別物と見る',
            $J::same($b1T['value'], $b1T['map'], $t1T['value'], $t1T['map'])['same'],
            false);

        // ═══════════════════════════════════════════════════════════════
        // ② 本番の関数で 診断→訂正比較→保存→読戻し まで通します
        // ═══════════════════════════════════════════════════════════════
        $this->info('');
        $this->info('── ② 本番の関数で 診断→訂正比較→保存→読戻し ──');

        $rd = static fn(string $fN) => (string) file_get_contents($vDir . '/' . $fN);
        $in1  = json_decode($rd('MIXED_N16_1st_input.json'), true);
        $in2  = json_decode($rd('MIXED_N16_2nd_input.json'), true);
        $out2 = json_decode($rd('MIXED_N16_2nd_output.json'), true);
        $baseTxt = $rd('MIXED_N16_1st_output.json');
        $jj = static fn($v) => (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        $this->line('      資料の value の書き方 : '
            . (string) (preg_match('/"value":[^,}]*/', $baseTxt, $mTmp) ? $mTmp[0] : '（無し）'));

        $ok = true;
        \DB::beginTransaction();
        try {
            $ctrl = app($A);
            $seqT = 0;
            // 凍結入力と枠を本番の3段（reserve→allowSend→receive）で作ります
            $mk = function (string $raw1, ?string $raw2 = null)
                  use ($in1, $in2, $jj, $BEGIN, $END, &$seqT, $W): array {
                $seqT++;
                $now   = $W::nowUs();
                $sales = $now + 3600 * 1000000;
                $nums  = [];
                foreach ((array) $in1['runners'] as $rr) { $nums[] = (int) $rr['horse_no']; }
                $sid = \DB::table('baganriki_snapshots')->insertGetId([
                    'race_id' => '20261018-9-98-9-' . $seqT,   // 場98は実在しません
                    'generation' => 900,
                    'run_id' => 'TT',
                    'snapshot_hash'   => (string) $in1['snapshot_hash'],
                    'input_json_1st'  => $jj($in1), 'input_json_2nd' => $jj($in2),
                    'frozen_nums'     => $jj($nums),
                    'allocation_mode' => (string) $in1['allocation_mode'],
                    'mode'            => (string) $in1['mode'],
                    'mode_status'     => (string) $in1['mode_status'],
                    'target_count'    => (int) $in1['target_count'],
                    'quota_1st'       => $jj($in1['quota']),
                    'sales_close_at'  => $W::toDb($sales),
                    'created_at'      => $W::toDb($now),
                ]);
                \DB::table('baganriki_live_states')->insert([
                    'snapshot_id' => $sid, 'cancellations' => $jj([]),
                    'active_count' => count($nums),
                    'live_target_count' => (int) $in1['target_count'],
                    'live_quota' => $jj($in1['quota']), 'live_close' => null,
                    'state_revision' => 0, 'response_revision' => 0,
                    'publication_revision' => 0, 'latest_publication_id' => null,
                    'updated_at' => $W::toDb($now),
                ]);
                $put = function (string $role, int $no, string $body)
                       use ($sid, $BEGIN, $END, $W): void {
                    $r = $W::reserve((int) $sid, $role, $no, $W::nowUs());
                    if (!$r['ok']) { return; }
                    $W::allowSend((string) $r['token'], $W::nowUs());
                    $W::receive((string) $r['token'], $BEGIN . $body . $END,
                                true, $W::nowUs());
                };
                $put('1st', $W::FIRST, $raw1);
                if ($raw2 !== null) { $put('1st', $W::FIX, $raw2); }
                return ['sid' => (int) $sid, 'nums' => $nums];
            };

            // ── 初回：1頭落とし、1頭目の value を指数（2e0）にします ──
            //   指数は「値は同じで表記だけ違う」ので、PHPの === では
            //   小数（2.0）と見分けが付きません。
            $fa = json_decode($baseTxt, true);
            $goneNo = (int) $fa['horses'][2]['horse_no'];
            array_splice($fa['horses'], 2, 1);
            $firstTxt = preg_replace('/"value":2\.0/', '"value":2e0', $jj($fa), 1);
            $check('P-01', '初回の本文に指数の表記が入っている',
                strpos((string) $firstTxt, '"value":2e0') !== false, true);

            // ── 訂正：全頭そろえ、1頭目の value は小数（2.0）のまま ──
            $fixTxt = $jj(json_decode($baseTxt, true));
            $m1 = $mk((string) $firstTxt, (string) $fixTxt);

            $i1R = $ctrl->inspectAiResponse($m1['sid'], '1st', $W::FIRST);
            $check('P-02', '初回を診断できた', (bool) $i1R['ok'], true);
            $check('P-03', '直すところがある', (bool) $i1R['needs_fix'], true);
            $check('P-04', '型の地図が返っている',
                is_array($i1R['normal_map'] ?? null), true);
            $kP = $J::pathKey(['1', 'evidence', 0, 'facts', 0, 'value']);
            $check('P-05', '1頭目の value は指数の表記で控えられている',
                (string) ((($i1R['normal_map']['num'][$kP] ?? [])['form']) ?? ''), 'exp');

            $i2R = $ctrl->inspectAiResponse($m1['sid'], '1st', $W::FIX);
            $check('P-06', '訂正を診断できた', (bool) $i2R['ok'], true);
            $check('P-07', '訂正側の value は小数の表記',
                (string) ((($i2R['normal_map']['num'][$kP] ?? [])['form']) ?? ''), 'dec');

            // 地図なし＝これまでの比べ方では見逃します
            $accOld = $R::acceptCorrection($i1R['normal'], $i2R['normal'], null, null,
                $m1['nums'], $i1R['repair_scope'], $i2R['row_valid']);
            $check('P-08', '【地図なし】表記だけの差を見逃して採用してしまう',
                (bool) $accOld['ok'], true);
            // 地図あり＝型まで見ます
            $accNew = $R::acceptCorrection($i1R['normal'], $i2R['normal'], null, null,
                $m1['nums'], $i1R['repair_scope'], $i2R['row_valid'],
                (array) $i1R['normal_map'], (array) $i2R['normal_map']);
            $check('P-09', '【地図あり】範囲外を変えているので採用しない',
                (bool) $accNew['ok'], false);
            $check('P-10', '理由に「対象外」が出る',
                strpos((string) ($accNew['reason'] ?? ''), '対象外') !== false, true);

            // 本番の採否（acceptedHorsesOf）も同じ判断になること
            $accR = $ctrl->acceptedHorsesOf($m1['sid'], '1st');
            $check('P-11', '本番の採否でも訂正を採用しない',
                (string) ($accR['code'] ?? ''), 'FIX_REJECTED');
            $check('P-12', '初回の正常部分は残る（15頭）',
                count((array) ($accR['merged'] ?? [])), 15);

            // ── ② {} と [] の違い ────────────────────────────────────
            $this->info('   ── {} と [] の違い（PHPでは同じ空配列）──');
            $fc = json_decode($baseTxt, true);
            array_splice($fc['horses'], 2, 1);
            $t3 = preg_replace('/"counterevidence":\[\]/', '"counterevidence":{}',
                               $jj($fc), 1);
            $check('P-13', '初回の本文に {} が入っている',
                strpos((string) $t3, '"counterevidence":{}') !== false, true);
            $m2 = $mk((string) $t3, (string) $jj(json_decode($baseTxt, true)));
            $j1R = $ctrl->inspectAiResponse($m2['sid'], '1st', $W::FIRST);
            $j2R = $ctrl->inspectAiResponse($m2['sid'], '1st', $W::FIX);
            $kK = $J::pathKey(['1', 'counterevidence']);
            $check('P-14', '1頭目の counterevidence は obj と控えられている',
                (string) (($j1R['normal_map']['kind'][$kK]) ?? ''), 'obj');
            $check('P-15', '訂正側は arr',
                (string) (($j2R['normal_map']['kind'][$kK]) ?? ''), 'arr');
            $a2Old = $R::acceptCorrection($j1R['normal'], $j2R['normal'], null, null,
                $m2['nums'], $j1R['repair_scope'], $j2R['row_valid']);
            $check('P-16', '【地図なし】{} と [] の差を見逃して採用してしまう',
                (bool) $a2Old['ok'], true);
            $a2New = $R::acceptCorrection($j1R['normal'], $j2R['normal'], null, null,
                $m2['nums'], $j1R['repair_scope'], $j2R['row_valid'],
                (array) $j1R['normal_map'], (array) $j2R['normal_map']);
            $check('P-17', '【地図あり】採用しない', (bool) $a2New['ok'], false);

            // ── ③ 正しい全頭訂正は、型を見ても通ること ───────────────
            $this->info('   ── 正しい全頭訂正は通ること ──');
            $fixSame = preg_replace('/"value":2\.0/', '"value":2e0',
                                    $jj(json_decode($baseTxt, true)), 1);
            $m3 = $mk((string) $firstTxt, (string) $fixSame);
            $accG = $ctrl->acceptedHorsesOf($m3['sid'], '1st');
            $check('P-18', '正しい全頭訂正は採用する',
                (string) ($accG['code'] ?? ''), 'FIX_APPLIED');
            $check('P-19', '欠けていた馬が救済される',
                in_array($goneNo, array_map('intval',
                    (array) ($accG['rescued'] ?? [])), true), true);
            $check('P-20', '全16頭そろう',
                count((array) ($accG['merged'] ?? [])), 16);
            $check('P-21', '当てた行の地図も返ってくる',
                is_array($accG['merged_map'] ?? null), true);

            // ── ④ 保存 → 読戻し ─────────────────────────────────────
            $this->info('   ── 保存 → 読戻し（専用シリアライザと専用パーサ）──');
            $aid = (int) \DB::table('baganriki_ai_attempts')
                     ->where('snapshot_id', $m3['sid'])->where('role', '1st')
                     ->where('attempt_no', $W::FIRST)->value('id');
            $rdN = $ctrl->normalizedPartOf($aid);
            $check('P-22', '保存した正常部分を専用パーサで読み戻せる',
                (bool) $rdN['ok'], true);
            $check('P-23', '保存本文に指数の表記が残っている',
                strpos((string) ($rdN['raw'] ?? ''), '"value":2e0') !== false, true);
            $i3R = $ctrl->inspectAiResponse($m3['sid'], '1st', $W::FIRST);
            $rt = $ctrl->checkNormalizedRoundTrip($aid, (array) $i3R['normal'],
                                             (array) $i3R['normal_map']);
            $check('P-24', '読み戻した値と型が、保存前と一致する',
                [(bool) $rt['ok'], (bool) $rt['same']], [true, true]);
            $check('P-25', '【参考】通常の json_encode では表記が落ちる',
                strpos((string) json_encode($i3R['normal']), '"value":2e0') !== false,
                false);

            // 40桁の数値が、引用符なしで保存されること
            $bigTxt = preg_replace('/"value":2\.0/',
                '"value":1.' . str_repeat('9', 40), $jj(json_decode($baseTxt, true)), 1);
            $m4 = $mk((string) $bigTxt);
            $i4R = $ctrl->inspectAiResponse($m4['sid'], '1st', $W::FIRST);
            $check('P-26', '40桁の数値でも診断できた', (bool) $i4R['ok'], true);
            $aid4 = (int) \DB::table('baganriki_ai_attempts')
                      ->where('snapshot_id', $m4['sid'])->where('role', '1st')
                      ->where('attempt_no', $W::FIRST)->value('id');
            $rd4 = $ctrl->normalizedPartOf($aid4);
            $check('P-27', '40桁の数値は引用符なしで保存されている',
                strpos((string) ($rd4['raw'] ?? ''),
                       '"value":1.' . str_repeat('9', 40)) !== false, true);
            $check('P-28', '【参考】通常の json_encode では引用符が付く',
                strpos((string) json_encode($i4R['normal']),
                       '"value":"1.' . str_repeat('9', 40) . '"') !== false, true);
            $rt4 = $ctrl->checkNormalizedRoundTrip($aid4, (array) $i4R['normal'],
                                              (array) $i4R['normal_map']);
            $check('P-29', '40桁でも読み戻して一致する',
                [(bool) $rt4['ok'], (bool) $rt4['same']], [true, true]);
            $check('P-30', '読み戻した値は丸まっていない',
                (string) (($rd4['value'][1]['evidence'][0]['facts'][0]['value']) ?? ''),
                '1.' . str_repeat('9', 40));
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('  × 例外 : ' . $e->getMessage());
            $this->error('    ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
        }

        $left = (int) \DB::table('baganriki_snapshots')
                  ->where('race_id', 'like', '20261018-9-98-9-%')->count();
        $this->line('');
        $this->line('  ロールバック後  仮snapshot ' . $left . ' 件');
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        if ($fail === 0 && $ok) {
            $this->info(' 型保持は診断→訂正比較→保存→読戻しまで通りました'
                      . '（DBへ残る書き込み0件）');
        } else {
            $this->error(' 通らなかった箇所があります。上の × をご確認ください');
        }
        $this->info('════════════════════════════════════════════════════════');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-PREDICTION-LIVEONCE  実AIの18頭実測（AC05・P-07）【課金あり】
     * ═══════════════════════════════════════════════════════════════════════
     * 変更計画書9章／AC05「最大18頭＋必要時訂正が設定した受領／公開期限内に
     * 終了し、finish_reason と出力長を確認できる」の実測です。
     *
     * ■ やること
     *   ① 保存済みのレース（既定 10/18新潟10R・18頭）の出馬表とオッズを、
     *      値を変えずに「今日・いまから330秒後に発走・場98（2ndは97）」へ写す
     *      （--prediction-e2e-test と同じ写し方。期限は1つも決め打ちしない）
     *   ② 監査行を作り、本番の関数（resumePredictionFreeze）で実凍結する
     *   ③ 本番の関数（sendInitialAiRequest）で、指定 role の実AIへ初回を1回だけ送る。
     *      中で検証し、訂正が要れば1回だけ送る（afterAiResponseReceived）
     *   ④ 応答期限が過ぎるのを待ってから、公開まで進むかを見る
     *   ⑤ すべてロールバックする（DBへ残る書き込み0件）
     *
     * ■ 発走を330秒後にする理由
     *   凍結できるのは「入力凍結（発走330秒前）」を過ぎてからです。
     *   330秒後にすると、いまがちょうど入力凍結で、応答期限（発走150秒前）
     *   まで180秒あります。これが変更計画書5章の「入力上限から応答上限まで
     *   180秒」の予算そのものです。
     *
     * ■ 1st と 2nd は本番では同時に動きます
     *   同時に測るときは、role を変えて2つ並べて起動してください
     *   （場コードを 98／97 に分けているので、互いの写しは混ざりません）。
     *     php artisan keiba:ai-analysis-compensate --prediction-live-once=1st --prediction-live-yes &
     *     php artisan keiba:ai-analysis-compensate --prediction-live-once=2nd --prediction-live-yes &
     *     wait
     *
     * ■ 旗について
     *   本番の旗は下りたままで構いません。旗の検査は試験用の
     *   test_ignore_flags で外します（sendInitialAiRequest の既存の口）。
     */
    private function runLiveAiOnce(string $role): void
    {
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $T = \App\Http\Controllers\Api\BaganrikiTime::class;
        $role = strtolower(trim($role));
        if ($role !== '1st' && $role !== '2nd') {
            $this->error('  × --prediction-live-once には 1st か 2nd を渡してください: ' . $role);
            return;
        }
        $src = trim((string) ($this->option('prediction-live-race') ?: '20261018-4-04-2-10'));
        $sp = explode('-', $src);
        if (count($sp) !== 5 || !preg_match('/^\d{8}$/', $sp[0])) {
            $this->error('  × --prediction-live-race の形が違います（例 20261018-4-04-2-10）: ' . $src);
            return;
        }
        $srcDate = substr($sp[0], 0, 4) . '-' . substr($sp[0], 4, 2) . '-' . substr($sp[0], 6, 2);

        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 実AIの実測（AC05）  role=' . $role);
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->line('  写し元   : ' . $src);
        $this->warn('  ※ 実AIを呼びます（初回1回＋訂正が要れば1回）。課金があります');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        if (!(bool) $this->option('prediction-live-yes')) {
            if (!$this->input->isInteractive()) {
                $this->error('  確認できないので中止しました（--prediction-live-yes を付けてください）');
                return;
            }
            if (!$this->confirm('実AIを呼びます。よろしいですか？', false)) {
                $this->info('  中止しました。AI呼び出しは発生していません。');
                return;
            }
        }

        $race = \DB::table('t_horse_odds_finder_races')
                  ->where('date', $srcDate)->where('kaisuu', $sp[1])
                  ->where('basho', $sp[2])->where('day', $sp[3])
                  ->where('race', (int) $sp[4])->first();
        if ($race === null) {
            $this->error('  × 写し元のレースがありません（週次の洗い替えで消えた可能性）: ' . $src);
            return;
        }

        \DB::beginTransaction();
        try {
            $ctrl = app($A);
            // ── ① 写す（--prediction-e2e-test と同じ）────────────────────────
            $nowUs    = $W::nowUs();
            $anchorUs = ($nowUs - ($nowUs % 1000000)) + (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC) * 1000000;
            $anchorDt = (new \DateTimeImmutable('@' . intdiv($anchorUs, 1000000)))
                            ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
            $newDate  = $anchorDt->format('Y-m-d');
            $newBasho = ($role === '1st') ? '98' : '97';
            $newStart = $anchorDt->format('H:i:s');
            $copyErr = [];
            $copy = function (string $table, array $set)
                    use ($race, $newDate, $newBasho, &$copyErr) {
                $n = 0;
                foreach (\DB::table($table)
                            ->where('date', $race->date)->where('kaisuu', $race->kaisuu)
                            ->where('basho', $race->basho)->where('day', $race->day)
                            ->where('race', (int) $race->race)->get() as $row) {
                    $a = (array) $row;
                    unset($a['id']);
                    $a['date']  = $newDate;
                    $a['basho'] = $newBasho;
                    foreach ($set as $k => $v) { $a[$k] = $v; }
                    try {
                        \DB::table($table)->insert($a);
                        $n++;
                    } catch (\Throwable $e) {
                        if ($copyErr === []) { $copyErr[] = $table . ': ' . $e->getMessage(); }
                        break;
                    }
                }
                return $n;
            };
            $nRace  = $copy('t_horse_odds_finder_races',  ['start_time' => $newStart]);
            $nHorse = $copy('t_horse_odds_finder_horses', []);
            $nOdds  = $copy('t_horse_odds_finder_odds',   []);
            $this->line('  写した行 : レース ' . $nRace . '／出馬表 ' . $nHorse . '／オッズ ' . $nOdds);
            if ($copyErr !== [] || $nRace !== 1 || $nHorse === 0 || $nOdds === 0) {
                throw new \RuntimeException('写しで失敗しました ' . implode('／', $copyErr));
            }
            $raceId = $A::buildPredictionRaceId($newDate, $race->kaisuu, $newBasho, $race->day, (int) $race->race);
            $this->line('  写し先   : ' . $raceId . '（発走 ' . $newStart . '）');

            // ── ② 監査行を作り、実凍結する ─────────────────────────────
            $toDb = static function (int $us): string {
                return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                    ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                    ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
            };
            $rowsA = [];
            foreach ($T::INPUT_SLOTS as $slot) {
                $pl = [];
                foreach (\DB::table('t_horse_odds_finder_odds')
                            ->where('date', $newDate)->where('kaisuu', $race->kaisuu)
                            ->where('basho', $newBasho)->where('day', $race->day)
                            ->where('race', (int) $race->race)
                            ->where('minutes_before_start', $slot)
                            ->orderBy('num')
                            ->get(['num', 'odds', 'fuku_min', 'fuku_max']) as $o) {
                    $pl[] = ['num' => (int) $o->num, 'tan' => (string) $o->odds,
                             'fuku_min' => (string) $o->fuku_min,
                             'fuku_max' => (string) $o->fuku_max];
                }
                if ($pl === []) { continue; }
                $raw = (string) json_encode($pl, JSON_UNESCAPED_UNICODE);
                $sch = $T::slotScheduled($anchorUs, $slot);
                $rowsA[] = [
                    'race_id' => $raceId, 'slot' => $slot,
                    'batch_id' => 'LIVE-' . $raceId . '-' . $slot . '-'
                                . substr(bin2hex(random_bytes(3)), 0, 6),
                    'slot_scheduled_at'    => $toDb($sch),
                    'source_updated_at'    => null,
                    'request_started_at'   => $toDb($sch + 500000),
                    'response_received_at' => $toDb($sch + 1500000),
                    'available_at'         => $toDb($sch + 2500000),
                    'raw_payload'          => $raw,
                    'payload_hash'         => hash('sha256', $raw),
                    'validation_result'    => 'ADOPTED',
                    'rejection_reason'     => null,
                    'committed'            => 1,
                    'created_at'           => $toDb($sch + 2500000),
                ];
            }
            \DB::table('baganriki_acquisition_batches')->insert($rowsA);
            $rf = $ctrl->resumePredictionFreeze($newDate, $race->kaisuu, $newBasho,
                                          $race->day, (int) $race->race);
            $this->line('  凍結     : ' . (string) ($rf['code'] ?? '') . '／'
                      . mb_strimwidth((string) ($rf['reason'] ?? ''), 0, 80, '…'));
            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();
            if ($snap === null) { throw new \RuntimeException('凍結できませんでした'); }
            $sid = (int) $snap->id;
            $col = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
            $in  = (array) json_decode((string) $snap->{$col}, true);
            $this->line('  N        : ' . (string) ($in['N'] ?? '?')
                      . '／mode ' . (string) ($in['mode'] ?? '') . '・' . (string) ($in['mode_status'] ?? '')
                      . '／配分 ' . (string) ($in['allocation_mode'] ?? 'null'));
            $this->line('  role_quota : ' . json_encode($in['role_quota'] ?? null)
                      . '／本文 ' . number_format(strlen((string) $snap->{$col})) . ' バイト');
            $live = \DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first();
            $dl = $W::deadlines($snap, $live);
            $this->line('  応答期限 : ' . $T::toJst((int) $dl['reply'])
                      . '（残り ' . round(((int) $dl['reply'] - $W::nowUs()) / 1000000) . ' 秒）');

            // ── ③ 本番の関数で初回を1回だけ送る（訂正が要れば中で1回）────
            $t0 = microtime(true);
            $fs = $ctrl->sendInitialAiRequest($sid, $role, ['test_ignore_flags' => true]);
            $sec = round(microtime(true) - $t0, 1);
            $this->line('');
            $this->info('── 結果 ──');
            $this->line('  送信     : ' . ($fs['sent'] ? 'した' : 'していない')
                      . '／' . (string) $fs['code'] . '／' . (string) $fs['reason']);
            $this->line('  かかった時間（初回＋検証＋必要なら訂正）: ' . $sec . ' 秒');
            foreach (\DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                        ->where('role', $role)->orderBy('attempt_no')->get() as $at) {
                $rcv = $W::dbUs($at->received_at ?? null);
                $res = $W::dbUs($at->reserved_at ?? null);
                $this->line(sprintf('  枠 %s  %-14s complete=%s  受領まで %s 秒  応答期限との差 %s 秒  %s',
                    ((int) $at->attempt_no === 0 ? '初回' : '訂正'),
                    (string) $at->status,
                    ((int) ($at->complete ?? 0) === 1 ? 'true' : 'false'),
                    ($rcv !== null && $res !== null) ? (string) round(($rcv - $res) / 1000000, 1) : '—',
                    ($rcv !== null) ? (string) round(((int) $dl['reply'] - $rcv) / 1000000, 1) : '—',
                    mb_strimwidth((string) ($at->failure_reason ?? ''), 0, 60, '…')));
                $this->line('       応答の長さ ' . number_format(strlen((string) ($at->raw_response ?? ''))) . ' バイト');
                // @ANCHOR-20261023-PREDICTION-LIVETAIL  応答の終わり方を見ます（JSONの閉じ忘れ・マーカー抜けの確認）
                $rawAt = (string) ($at->raw_response ?? '');
                if ($rawAt !== '') {
                    $this->line('       応答の最後の500文字 ↓');
                    $this->line(mb_substr($rawAt, -500));
                    $this->line('       ↑ ここまで');
                }
            }
            $this->line('  使用量   : ' . json_encode($A::aiUsageOf($sid), JSON_UNESCAPED_UNICODE));
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-PREDICTION-LIVEDIAG  検証で何を指摘したか・訂正を採ったか
            // ══════════════════════════════════════════════════════════
            //   検証は同じ入力なら同じ結果なので、ここで呼び直して中身を見せます（AIは呼びません）。
            //   原応答と凍結入力は storage/logs/r122_live/ へ書き出します（あとで手元で調べるため）。
            $dumpDir = storage_path('logs/r122_live');
            $stamp = date('Ymd_His') . '_' . $role;
            $canDump = (is_dir($dumpDir) || @mkdir($dumpDir, 0775, true)) && is_writable($dumpDir);
            if ($canDump) {
                @file_put_contents($dumpDir . '/' . $stamp . '_input.json', (string) $snap->{$col});
            }
            foreach (\DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                        ->where('role', $role)->orderBy('attempt_no')->get() as $at) {
                $no = (int) $at->attempt_no;
                if ($canDump && (string) ($at->raw_response ?? '') !== '') {
                    @file_put_contents($dumpDir . '/' . $stamp . '_attempt' . $no . '.txt',
                                       (string) $at->raw_response);
                }
                if ((string) $at->status !== $W::RECEIVED) { continue; }
                $ins = $ctrl->inspectAiResponse($sid, $role, $no);
                $this->line('  検証（' . ($no === 0 ? '初回' : '訂正') . '）: '
                          . (string) $ins['stage'] . '／' . (string) $ins['code']
                          . '／使える頭数 ' . count((array) $ins['normal'])
                          . '／訂正が要る ' . ($ins['needs_fix'] ? 'はい' : 'いいえ'));
                $this->line('       ' . mb_strimwidth((string) $ins['reason'], 0, 200, '…'));
                $fs = (array) (($ins['diag'] ?? [])['findings'] ?? []);
                $byCode = [];
                foreach ($fs as $f) {
                    $k = (string) ($f['scope'] ?? '') . ':' . (string) ($f['code'] ?? '');
                    $byCode[$k] = ($byCode[$k] ?? 0) + 1;
                }
                arsort($byCode);
                $this->line('       指摘の内訳 : ' . json_encode($byCode, JSON_UNESCAPED_UNICODE));
                foreach (array_slice($fs, 0, 8) as $f) {
                    $this->line('         - ' . mb_strimwidth(json_encode($f, JSON_UNESCAPED_UNICODE), 0, 240, '…'));
                }
            }
            $acc = $ctrl->acceptedHorsesOf($sid, $role);
            $this->line('  採否     : ' . (string) ($acc['code'] ?? '') . '／'
                      . mb_strimwidth((string) ($acc['reason'] ?? ''), 0, 200, '…'));
            $this->line('       使える馬 ' . json_encode(array_map('intval', array_keys((array) ($acc['merged'] ?? []))))
                      . '／適用 ' . json_encode($acc['applied'] ?? [])
                      . '／飛ばし ' . json_encode($acc['skipped'] ?? []));
            $this->line('  書き出し : ' . ($canDump ? $dumpDir . '/' . $stamp . '_*' : '書けませんでした（権限）'));

            // ── ④ 応答期限が過ぎるのを待ってから、公開まで進むか ─────────
            $waitUs = (int) $dl['reply'] - $W::nowUs() + 1000000;
            if ($waitUs > 0) {
                $this->line('  応答期限まで ' . round($waitUs / 1000000) . ' 秒待ってから公開を見ます'
                          . '（片側だけの写しなので、もう片側は来ません）');
                usleep($waitUs);
            }
            $pb = $ctrl->publishWhenReady($sid);
            $this->line('  公開     : ' . (string) ($pb['code'] ?? '') . '／'
                      . mb_strimwidth((string) ($pb['reason'] ?? ''), 0, 80, '…'));
            $this->line('  公開候補 : ' . json_encode(array_map(
                static fn($c) => ($c['horse_no'] ?? '?') . ($c['band'] ?? ''),
                (array) ($pb['candidates'] ?? [])), JSON_UNESCAPED_UNICODE));
            $this->line('  不足     : ' . json_encode($pb['shortfalls'] ?? [], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->error('  × 例外 : ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
            $this->line('');
            $this->line('  ロールバックしました（DBへ残る書き込みは0件）');
        }
    }

    private function runEndToEndTest(string $root): void
    {
        // @ANCHOR-20261023-COST-FIXPOLICY  この試験は契約どおりの訂正の経路を見るので、
        //   訂正の方針を contract にして通します（本番の既定は missing）
        putenv('BAGANRIKI_PREDICTION_FIX_POLICY=contract');
        $_ENV['BAGANRIKI_PREDICTION_FIX_POLICY'] = 'contract';
        $_SERVER['BAGANRIKI_PREDICTION_FIX_POLICY'] = 'contract';
        self::loadPredictionClasses();
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $T = \App\Http\Controllers\Api\BaganrikiTime::class;
        $BEGIN = \App\Http\Controllers\Api\BaganrikiResponse::BEGIN;
        $END   = \App\Http\Controllers\Api\BaganrikiResponse::END;
        $HTTP  = \Illuminate\Support\Facades\Http::class;

        $date = (string) ($this->option('date') ?: '2026-10-18');
        $this->info('════════════════════════════════════════════════════════');
        $this->info(' 新方式 実凍結から一続きの通し試験（010の5章末尾・009の③）');
        $this->info('════════════════════════════════════════════════════════');
        $this->line('  実行時刻 : ' . date('c'));
        $this->warn('  ※ 実在のレースの出馬表とオッズを、値を変えずに写します');
        $this->warn('  ※ 日付・場コード・発走時刻だけを「今日・いまから5分後・場98」'
                  . 'へ寄せます（期限の幅は1つも決め打ちしません）');
        $this->warn('  ※ 実AIは1回も呼びません（送信口を偽応答に差し替えます）');
        $this->warn('  ※ 全部ロールバックします。DBへ残る書き込みは0件です');
        $this->line('');

        // ── 偽の送信口（1回だけ登録します。積み重なるためです）──────────
        $calls = []; $doc1 = []; $doc2 = []; $fixDoc = []; $nth = [];
        $HTTP::fake(function ($request) use (&$calls, &$doc1, &$doc2, &$fixDoc,
                                             &$nth, $BEGIN, $END) {
            $url = (string) $request->url();
            $calls[] = $url;
            $isDs = (strpos($url, 'api.deepseek.com') !== false);
            $role = $isDs ? '2nd' : '1st';
            $nth[$role] = (int) ($nth[$role] ?? 0) + 1;
            // 1回目は初回、2回目は訂正の本文を返します
            $body = ($nth[$role] === 1)
                  ? ($isDs ? $doc2 : $doc1)
                  : ($isDs ? $doc2 : $fixDoc);
            $txt = $BEGIN . (string) json_encode($body,
                       JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                       | JSON_PRESERVE_ZERO_FRACTION) . $END;
            if ($isDs) {
                return \Illuminate\Support\Facades\Http::response([
                    'model' => 'deepseek-chat',
                    'choices' => [['message' => ['content' => $txt],
                                   'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1234, 'completion_tokens' => 567],
                ], 200);
            }
            return \Illuminate\Support\Facades\Http::response([
                'model' => 'claude-haiku-4-5',
                'content' => [['type' => 'text', 'text' => $txt]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 2345, 'output_tokens' => 678],
            ], 200);
        });

        $pass = 0; $fail = 0;
        //   第5引数に true を渡すと「$want と<b>違う</b>こと」を見ます
        $check = function (string $id, string $what, $got, $want,
                           bool $not = false) use (&$pass, &$fail) {
            $okk = $not ? ($got !== $want) : ($got === $want);
            if ($okk) { $pass++; } else { $fail++; }
            $this->line(sprintf('  %s %-10s %-52s %s',
                $okk ? '○' : '×', $id, $what,
                $okk ? '' : ('得=' . var_export($got, true)
                           . ($not ? ' 期待=これ以外' : ' 期待=')
                           . ($not ? '' : var_export($want, true)))));
        };

        // ── ① 実在のレースを選びます ────────────────────────────────
        $race = null;
        foreach (\DB::table('t_horse_odds_finder_races')->where('date', $date)
                    ->orderBy('basho')->orderBy('race')->get() as $r) {
            $n6 = (int) \DB::table('t_horse_odds_finder_odds')
                    ->where('date', $r->date)->where('kaisuu', $r->kaisuu)
                    ->where('basho', $r->basho)->where('day', $r->day)
                    ->where('race', (int) $r->race)
                    ->where('minutes_before_start', 6)->count();
            $nh = (int) \DB::table('t_horse_odds_finder_horses')
                    ->where('date', $r->date)->where('kaisuu', $r->kaisuu)
                    ->where('basho', $r->basho)->where('day', $r->day)
                    ->where('race', (int) $r->race)->count();
            if ($n6 >= 4 && $nh >= 4) { $race = $r; break; }
        }
        if ($race === null) {
            $this->error('  × 出馬表とオッズのそろったレースがありません: ' . $date);
            return;
        }
        $raceId = $A::buildPredictionRaceId($race->date, $race->kaisuu, $race->basho,
                                 $race->day, $race->race);
        $this->line('  対象レース : ' . $raceId
                  . '（実在の出馬表・オッズを使います）');

        $ok = true;
        \DB::beginTransaction();
        try {
            $ctrl = app($A);

            // ── ② 出馬表とオッズを「今日・いまから5分後・場98」へ写します ──
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-E2EDATE  発走時刻だけでは足りません
            // ══════════════════════════════════════════════════════════
            //   情報上限・入力凍結・応答期限・公開期限は、すべて
            //   「日付＋発走時刻」から出します。発走時刻だけを動かしても、
            //   日付が3日前のままなら期限も3日前になります。
            //   そうすると監査行が自己申告した予定時刻と、正本から出した
            //   予定時刻が合わず、バッチが1つも採用されません
            //   （最初に書いたときはここで SOURCE_REGISTRY_EMPTY に
            //     なりました）。
            //   実データを<b>値を変えずに</b>今日へ写します。
            //   場コードは98（実在しません）にして、本番の行と混ざらない
            //   ようにします。ロールバックするので写しも残りません。
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-E2EWINDOW  寄せ先は「いまから5分後」
            // ══════════════════════════════════════════════════════════
            //   10分後にすると、情報上限（発走の360秒前＝いまから240秒後）が
            //   <b>まだ来ていない</b>ので、凍結が TOO_EARLY で正しく止まります。
            //   凍結できて、かつ初回を送れる窓は次の条件です。
            //     情報上限が過ぎている   発走 − 360 ≦ いま
            //       → 発走 ≦ いま + 360秒
            //     応答期限まで余裕がある 発走 − 150 ≧ いま + 45秒
            //       → 発走 ≧ いま + 195秒
            //   あいだを採って <b>いまから300秒後</b>にします。
            //     情報上限   いま − 60秒（過ぎています）
            //     入力凍結   いま − 30秒（過ぎています）
            //     応答期限   いま + 150秒（間に合います）
            //     公開期限   いま + 180秒
            //     投票締切   いま + 240秒
            //   期限の幅は1つも決め打ちしていません。
            //   いちばん遅い6分前の取得も、入力凍結より前に収まります
            //   （予定 いま−60秒、利用可能 いま−57.5秒 ≦ 入力凍結 いま−30秒）。
            $nowUs   = $W::nowUs();
            $anchorUs = ($nowUs - ($nowUs % 1000000)) + 300 * 1000000;  // 秒に丸めます
            $anchorDt = (new \DateTimeImmutable('@' . intdiv($anchorUs, 1000000)))
                            ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
            $newDate  = $anchorDt->format('Y-m-d');
            $newBasho = '98';
            $newStart = $anchorDt->format('H:i:s');

            // 日付が変わるとき（発走が翌日0時台になるとき）も、
            // 日付と時刻は同じ瞬間から作るのでずれません。
            $copyErr = [];
            $copy = function (string $table, array $set)
                    use ($race, $newDate, $newBasho, &$copyErr) {
                $n = 0;
                foreach (\DB::table($table)
                            ->where('date', $race->date)->where('kaisuu', $race->kaisuu)
                            ->where('basho', $race->basho)->where('day', $race->day)
                            ->where('race', (int) $race->race)->get() as $row) {
                    $a = (array) $row;
                    // 自動連番の列は外します（名前が id でない表もあるので、
                    //   1行目で失敗したらその列名を外してもう一度だけ試します）
                    unset($a['id']);
                    $a['date']  = $newDate;
                    $a['basho'] = $newBasho;
                    foreach ($set as $k => $v) { $a[$k] = $v; }
                    try {
                        \DB::table($table)->insert($a);
                        $n++;
                    } catch (\Throwable $e) {
                        if ($copyErr === []) {
                            $copyErr[] = $table . ': ' . $e->getMessage();
                        }
                        break;
                    }
                }
                return $n;
            };
            $nRace  = $copy('t_horse_odds_finder_races',  ['start_time' => $newStart]);
            $nHorse = $copy('t_horse_odds_finder_horses', []);
            $nOdds  = $copy('t_horse_odds_finder_odds',   []);
            $this->line('  写した行 : レース ' . $nRace . ' 件／出馬表 ' . $nHorse
                      . ' 件／オッズ ' . $nOdds . ' 件（値は変えていません）');
            if ($copyErr !== []) {
                $this->error('  × 写しで失敗しました : ' . implode('／', $copyErr));
            }
            $check('G-00a', 'レースの行を写せた', $nRace, 1);
            $check('G-00b', '出馬表を写せた', $nHorse > 0, true);
            $check('G-00c', 'オッズを写せた', $nOdds > 0, true);
            $this->line('  写し先 : ' . $newDate . ' 場' . $newBasho
                      . ' 発走 ' . $newStart . '（いまから5分後）');

            // 以降は写し先のレースを対象にします
            $raceId = $A::buildPredictionRaceId($newDate, $race->kaisuu, $newBasho,
                                     $race->day, (int) $race->race);
            $this->line('  写し先の race_id : ' . $raceId);

            // ── ③ 監査行を、寄せた発走時刻から出した予定時刻で作ります ──
            $toDb = static function (int $us): string {
                return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                    ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                    ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
            };
            $rowsA = [];
            foreach ($T::INPUT_SLOTS as $slot) {
                $pl = [];
                foreach (\DB::table('t_horse_odds_finder_odds')
                            ->where('date', $newDate)->where('kaisuu', $race->kaisuu)
                            ->where('basho', $newBasho)->where('day', $race->day)
                            ->where('race', (int) $race->race)
                            ->where('minutes_before_start', $slot)
                            ->orderBy('num')
                            ->get(['num', 'odds', 'fuku_min', 'fuku_max']) as $o) {
                    $pl[] = ['num' => (int) $o->num, 'tan' => (string) $o->odds,
                             'fuku_min' => (string) $o->fuku_min,
                             'fuku_max' => (string) $o->fuku_max];
                }
                if ($pl === []) { continue; }
                $raw = (string) json_encode($pl, JSON_UNESCAPED_UNICODE);
                $sch = $T::slotScheduled($anchorUs, $slot);
                $rowsA[] = [
                    'race_id' => $raceId, 'slot' => $slot,
                    'batch_id' => 'E2E-' . $raceId . '-' . $slot . '-'
                                . substr(bin2hex(random_bytes(3)), 0, 6),
                    'slot_scheduled_at'    => $toDb($sch),
                    'source_updated_at'    => null,
                    'request_started_at'   => $toDb($sch + 500000),
                    'response_received_at' => $toDb($sch + 1500000),
                    'available_at'         => $toDb($sch + 2500000),
                    'raw_payload'          => $raw,
                    'payload_hash'         => hash('sha256', $raw),
                    'validation_result'    => 'ADOPTED',
                    'rejection_reason'     => null,
                    'committed'            => 1,
                    'created_at'           => $toDb($sch + 2500000),
                ];
            }
            $check('G-01', '監査行を6時点ぶん作れた', count($rowsA), 6);
            \DB::table('baganriki_acquisition_batches')->insert($rowsA);

            // ── ④ キャッシュ後の入口が呼ぶ関数で、実凍結します ──────────
            //   <b>締切は渡しません。</b>発走時刻から導出させます。
            $check('G-02', '凍結の前は snapshot が無い',
                (int) \DB::table('baganriki_snapshots')
                    ->where('race_id', $raceId)->where('generation', 0)->count(), 0);
            $rf = $ctrl->resumePredictionFreeze($newDate, $race->kaisuu, $newBasho,
                                          $race->day, (int) $race->race);
            $this->line('      凍結 : ' . (string) ($rf['code'] ?? '')
                      . '／' . mb_strimwidth((string) ($rf['reason'] ?? ''), 0, 70, '…'));
            $check('G-03', '出馬表と監査行から実際に凍結できた',
                (string) ($rf['code'] ?? ''), 'OK');
            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();
            $check('G-04', '本番の世代（0）に1件できた', $snap !== null, true);
            if ($snap === null) { throw new \RuntimeException('凍結できていません'); }
            $sid = (int) $snap->id;

            // ── ⑤ 期限の幅が契約どおりか（決め打ちしていないこと）────────
            $this->info('── 期限の幅（正本の発走時刻から導出されたもの）──');
            $aU = $W::dbUs($snap->anchor_at ?? null);
            $cU = $W::dbUs($snap->cutoff_at ?? null);
            $fU = $W::dbUs($snap->input_freeze_at ?? null);
            $sU = $W::dbUs($snap->sales_close_at ?? null);
            $check('G-05', '発走時刻が、寄せた時刻として保存されている',
                (int) $aU, $anchorUs);
            $check('G-06', '投票締切は発走の60秒前（導出。渡していません）',
                (int) ($aU - $sU) / 1000000, 60);
            $check('G-07', '情報上限は発走の360秒前',
                (int) ($aU - $cU) / 1000000, 360);
            $check('G-08', '入力凍結は発走の330秒前',
                (int) ($aU - $fU) / 1000000, (-\App\Http\Controllers\Api\BaganrikiTime::INPUT_FREEZE_OFFSET_SEC));
            $live = \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $sid)->first();
            $dl = $W::deadlines($snap, $live);
            $check('G-09', '応答期限は発走の150秒前',
                (int) ($aU - (int) $dl['reply']) / 1000000, 150);
            $check('G-10', '公開期限は発走の120秒前',
                (int) ($aU - (int) $dl['publish']) / 1000000, 120);
            $this->line('      発走 ' . \App\Http\Controllers\Api\BaganrikiTime::toJst($aU));
            $this->line('      情報上限 −360s ／ 入力凍結 −330s ／ 応答期限 −150s');
            $this->line('      公開期限 −120s ／ 投票締切 −60s');
            $this->line('      ※ 3,600秒の決め打ちはありません');

            // ── ⑥ 偽の応答を、この凍結入力に合わせて作ります ─────────────
            //   封筒（prompt_version・race_id・snapshot_hash など）は
            //   保存された本文からそのまま写します。
            $in1 = json_decode((string) $snap->input_json_1st, true);
            $in2 = json_decode((string) $snap->input_json_2nd, true);
            $check('G-11', '1st の本文が保存されている', is_array($in1), true);
            $mkDoc = function (array $in, string $role) use ($root) {
                // 送付資料の正常出力を雛形にし、封筒と馬をこの凍結に合わせます
                $f = rtrim($root, '/') . '/validation/normal/MIXED_N16_'
                   . $role . '_output.json';
                $tpl = is_file($f)
                     ? (array) json_decode((string) file_get_contents($f), true)
                     : [];
                $doc = $tpl;
                foreach (['prompt_version', 'schema_version', 'race_id', 'ai_role',
                          'snapshot_hash', 'mode', 'mode_status', 'allocation_mode',
                          'mode_policy_id', 'role_cap', 'role_quota',
                          'target_count'] as $k) {
                    if (array_key_exists($k, $in)) { $doc[$k] = $in[$k]; }
                }
                $doc['quota'] = $in['quota'] ?? ($doc['quota'] ?? null);
                // 馬は、この凍結の runner にそろえます（雛形の1頭目を使い回します）
                $proto = (array) (($tpl['horses'][0]) ?? []);
                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261021-PREDICTION-E2EEVID  根拠の参照は馬ごとに直します
                // ══════════════════════════════════════════════════════
                //   雛形の1頭目をそのまま使い回すと、全頭が
                //   <b>1番の根拠（h1_…）を指します。</b>診断は
                //   「自分のSIGNALが1系統も無い馬」を外すので、
                //   2番以降が全部落ちます。
                //   この凍結入力に実際に在る参照（h{馬番}_win_odds_6）へ
                //   書き替えます。文字列として差し替えるので、入れ子の
                //   どこに在っても追いつきます。
                $reg = (array) ($in['source_registry'] ?? []);
                $rows = []; $bands = ['A' => [], 'B' => [], 'D' => []];
                foreach ((array) ($in['runners'] ?? []) as $rr) {
                    $no = (int) ($rr['horse_no'] ?? 0);
                    $bd = (string) ($rr['band'] ?? 'A');
                    if ($no <= 0) { continue; }
                    // この馬について、凍結入力に在る参照をひとつ選びます
                    $myRef = null;
                    foreach ([6, 9, 12, 15, 18, 21] as $sl) {
                        $cand = 'h' . $no . '_win_odds_' . $sl;
                        if (isset($reg[$cand])) { $myRef = $cand; break; }
                    }
                    $txt = (string) json_encode($proto,
                               JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                               | JSON_PRESERVE_ZERO_FRACTION);
                    if ($myRef !== null) {
                        // 雛形が指している参照を、この馬の参照へ置き替えます
                        $txt = str_replace('h1_past_performance_None', $myRef, $txt);
                        $txt = str_replace('"h1_', '"h' . $no . '_', $txt);
                    }
                    $h = (array) json_decode($txt, true);
                    $h['horse_no'] = $no;
                    $h['band'] = $bd;
                    $h['public_candidate'] = false;
                    $h['nonselect_reason'] = 'NOT_SELECTED';
                    $rows[] = $h;
                    if (isset($bands[$bd])) { $bands[$bd][] = $no; }
                }
                $doc['horses'] = $rows;
                foreach ($bands as $b => $v) { sort($v); $bands[$b] = $v; }
                $doc['band_ranks'] = $bands;
                $doc['evidence_band_ranks'] = $bands;
                $doc['public_candidates'] = [];
                $doc['shortfalls'] = [];
                $doc['validation_issues'] = [];
                $doc['selection_enabled'] = false;
                return $doc;
            };
            $doc1 = $mkDoc((array) $in1, '1st');
            $doc2 = $mkDoc((array) $in2, '2nd');
            $fixDoc = $doc1;

            // ── ⑦ 初回→検証→訂正→採否→公開まで、本番の関数で通します ───
            $this->info('── 初回送信から公開まで（本番の関数）──');
            $check('G-12', '送信の前は枠が1つも無い',
                (int) \DB::table('baganriki_ai_attempts')
                    ->where('snapshot_id', $sid)->count(), 0);
            $f1 = $ctrl->sendInitialAiRequest($sid, '1st', ['test_ignore_flags' => true]);
            $this->line('      1st : sent=' . var_export($f1['sent'], true)
                      . ' ok=' . var_export($f1['ok'], true)
                      . ' code=' . (string) $f1['code']
                      . '／' . mb_strimwidth((string) $f1['reason'], 0, 60, '…'));
            $check('G-13', '1st の初回を実際に送った', (bool) $f1['sent'], true);
            $check('G-14', '1st の初回を受け取れた', (bool) $f1['ok'], true);
            $check('G-15', '1st の枠が RECEIVED になった',
                (string) \DB::table('baganriki_ai_attempts')
                    ->where('snapshot_id', $sid)->where('role', '1st')
                    ->where('attempt_no', $W::FIRST)->value('status'), $W::RECEIVED);
            $f2 = $ctrl->sendInitialAiRequest($sid, '2nd', ['test_ignore_flags' => true]);
            $this->line('      2nd : sent=' . var_export($f2['sent'], true)
                      . ' ok=' . var_export($f2['ok'], true)
                      . ' code=' . (string) $f2['code']);
            $check('G-16', '2nd の初回も送った', (bool) $f2['sent'], true);
            $check('G-17', '2nd の枠も RECEIVED',
                (string) \DB::table('baganriki_ai_attempts')
                    ->where('snapshot_id', $sid)->where('role', '2nd')
                    ->where('attempt_no', $W::FIRST)->value('status'), $W::RECEIVED);

            // ── ⑧ 実モデルと使用量が残っていること（010の7章⑤）───────────
            $uz = $A::aiUsageOf($sid);
            // 初回に直すところがあったので、両roleで訂正も飛びます。
            //   role ごとに 初回1件＋訂正1件 ＝ 合計4件になります。
            //   （最初に書いたときは2件と見込んでいました。訂正のぶんを
            //     数えていませんでした）
            $this->line('      使用量 : '
                . json_encode($uz['by_model'], JSON_UNESCAPED_UNICODE));
            $byRole = [];
            foreach ((array) $uz['rows'] as $ur) {
                $byRole[(string) ($ur['role'] ?? '')][] = (int) ($ur['attempt_no'] ?? -1);
            }
            foreach ($byRole as $kR => $vR) { sort($vR); $byRole[$kR] = $vR; }
            ksort($byRole);
            $check('G-18', '使用量は role ごとに初回と訂正の2件ずつ残る',
                $byRole, ['1st' => [0, 1], '2nd' => [0, 1]]);
            $check('G-18b', '合計4件', count((array) $uz['rows']), 4);
            $check('G-19', '応答が名乗ったモデル名で残っている',
                array_keys((array) $uz['by_model']),
                ['claude-haiku-4-5', 'deepseek-chat']);

            // ── ⑨ 採否と公開 ───────────────────────────────────────────
            foreach (['1st', '2nd'] as $role) {
                $sv = $A::roleSettled($sid, $role);
                $check('G-2' . ($role === '1st' ? '0' : '1'),
                    $role . ' の採否が確定している', (bool) $sv['settled'], true);
            }
            $pb = $ctrl->publishWhenReady($sid);
            $this->line('      公開 : ' . (string) ($pb['code'] ?? '')
                      . '／' . mb_strimwidth((string) ($pb['reason'] ?? ''), 0, 70, '…'));
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-E2ESCOPE  この試験が示す範囲
            // ══════════════════════════════════════════════════════════
            //   この試験の目的は、010の5章末尾でご指摘いただいた
            //     出馬表・監査行からの実凍結／キャッシュ入口／
            //     実際の期限幅／初回送信から採否確定まで
            //   を<b>一続きに通すこと</b>です。
            //
            //   <b>公開の中身（候補の選定・加点・帯順位）は、この試験では
            //   確かめません。</b>偽の応答は、実在のレースの凍結入力に
            //   合わせて組み立てたものなので、送付資料の正常出力のように
            //   採点・根拠・5系統まで整合しているわけではありません。
            //   候補の選定は、資料の正常出力を使う試験
            //   （公開 67件・実経路 E群・応答検証 76件）で確認しています。
            //
            //   ここで確かめるのは
            //     ・公開判定が<b>起動して、待ちではない</b>こと
            //       （両roleの採否が確定しているので待ちにはなりません）
            //     ・採用できた馬の数が出ること（数は判定しません）
            //   です。
            $check('G-22', '公開判定が起動し、待ちにはならない',
                (string) ($pb['code'] ?? ''), 'WAITING', true);
            $okCodes = ['PUBLISHED', 'ALREADY', 'NO_RESPONSE',
                        'ALL_CANCELLED', 'PAST_PUBLISH_DEADLINE'];
            $check('G-23', '公開判定が定義どおりの結果を返した',
                in_array((string) ($pb['code'] ?? ''), $okCodes, true), true);
            $n1v = count((array) ($ctrl->acceptedHorsesOf($sid, '1st')['merged'] ?? []));
            $n2v = count((array) ($ctrl->acceptedHorsesOf($sid, '2nd')['merged'] ?? []));
            $this->line('      この偽応答で採用できた馬 : 1st ' . $n1v
                      . ' 頭／2nd ' . $n2v . ' 頭');
            $this->line('      ※ 候補の選定は、資料の正常出力を使う'
                      . '「公開 67件」「実経路 E群」で確認しています');
            if ((string) ($pb['code'] ?? '') === 'PUBLISHED'
                || (string) ($pb['code'] ?? '') === 'ALREADY') {
                $check('G-23b', '公開が1件できている',
                    ((string) \DB::table('baganriki_live_states')
                        ->where('snapshot_id', $sid)
                        ->value('latest_publication_id')) !== '', true);
            }
            $check('G-24', '実AIへは1回も送っていない（偽の送信口だけ）',
                count(array_filter($calls, static fn($u) =>
                    strpos((string) $u, 'api.anthropic.com') === false
                    && strpos((string) $u, 'api.deepseek.com') === false)), 0);
            $this->line('      送信口が呼ばれた回数 : ' . count($calls) . ' 回');

            // ── @ANCHOR-20261102-PREDICTION-PUBLIC  お客様の画面へ出す形（今の取得口）まで通す ──
            //   BAGANRIKI_PREDICTION_PUBLIC=1 などを、このコマンドの前に付けて起動したときだけ確かめます
            //   （.env は変えません。写したレースは場98なので BAGANRIKI_PREDICTION_TARGETS=TEST を付けます。
            //     例は作業手順のマークダウンを見てください）。
            if (AiController::predictionPublicEnabled()) {
                $pa = $ctrl->predictionAnswerFor($newDate, $race->kaisuu, $newBasho, $race->day, $race->race);
                $check('G-25', '新しい予想の対象として扱われる（旧方式に回らない）', $pa !== null, true);
                $this->line('      画面に返す状態 : ' . (string) ($pa['state'] ?? 'null'));
                $callsBefore = count($calls);
                $req1 = new Request();
                $req1->query->add(['date' => $newDate, 'kaisuu' => $race->kaisuu, 'basho' => $newBasho,
                                   'day' => $race->day, 'race' => (string) $race->race]);
                $r1 = json_decode((string) $ctrl->getHorseOddsFinderAiAnalysis($req1)->getContent(), true);
                $r2 = json_decode((string) $ctrl->getHorseOddsFinderSecondAiOpinion($req1)->getContent(), true);
                $check('G-26', '1st の取得口が新しい予想を返す',
                    (string) ($r1['data']['prediction_method'] ?? ''), 'new');
                $check('G-27', '2nd の取得口が新しい予想を返す',
                    (string) ($r2['data']['prediction_method'] ?? ''), 'new');
                $legacy = \DB::table('t_horse_odds_finder_ai_analysis')->where('date', $newDate)
                            ->where('kaisuu', $race->kaisuu)->where('basho_code', $newBasho)
                            ->where('day', $race->day)->where('race', $race->race)->count();
                $this->line('      旧方式の1stの行（写したレース。0行のはず） : ' . $legacy . ' 行');
                $check('G-28', '取得口を呼んでも、AIの送信口は1回も呼ばれていない（旧方式も新方式も）',
                    count($calls) - $callsBefore, 0);
                $check('G-29', '写したレースに旧方式の1stの行ができていない', $legacy, 0);
                foreach ((array) ($r2['data']['merged_horses'] ?? []) as $mh) {
                    $this->line(sprintf('      %2d番 %s %s番人気 %s倍 おすすめ度%s', (int) $mh['num'],
                        (string) $mh['name'], (string) ($mh['popularity'] ?? '-'),
                        (string) ($mh['odds_6'] ?? '-'), (string) $mh['score']));
                    $this->line('         ' . (string) $mh['reason']);
                }
                if (($r2['data']['status'] ?? '') === 'undetermined') {
                    $this->line('      出せなかった理由 : ' . (string) ($r2['data']['status_reason'] ?? ''));
                }
            } else {
                $this->line('      （画面へ出す形の確認は省きました：BAGANRIKI_PREDICTION_PUBLIC などが下りています）');
            }
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('  × 例外 : ' . $e->getMessage());
            $this->error('    ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            \DB::rollBack();
        }

        // ロールバックできていることの確認
        $left1 = (int) \DB::table('baganriki_snapshots')
                   ->where('race_id', $raceId)->where('generation', 0)->count();
        $left2 = (int) \DB::table('baganriki_acquisition_batches')
                   ->where('batch_id', 'like', 'E2E-%')->count();
        $st = (string) \DB::table('t_horse_odds_finder_races')
                ->where('date', $race->date)->where('kaisuu', $race->kaisuu)
                ->where('basho', $race->basho)->where('day', $race->day)
                ->where('race', (int) $race->race)->value('start_time');
        $left3 = (int) \DB::table('t_horse_odds_finder_races')
                   ->where('basho', '98')->count();
        $left4 = (int) \DB::table('t_horse_odds_finder_odds')
                   ->where('basho', '98')->count();
        $this->line('');
        $this->line(sprintf('  ロールバック後  snapshot %d 件／監査行(E2E-) %d 件',
            $left1, $left2));
        $this->line(sprintf('  写した行も消えています  場98のレース %d 件／オッズ %d 件',
            $left3, $left4));
        $this->line('  元のレースの発走時刻は触っていません : ' . $st);
        $this->line('');
        $this->line('  判定件数 : ' . ($pass + $fail) . ' 件');
        $this->line('  合格     : ' . $pass . ' 件');
        $this->line('  不合格   : ' . $fail . ' 件');
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        if ($fail === 0 && $ok) {
            $this->info(' 出馬表・監査行からの実凍結から公開まで、一続きに通りました'
                      . '（DBへ残る書き込み0件）');
        } else {
            $this->error(' 通らなかった箇所があります。上の × をご確認ください');
        }
        $this->info('════════════════════════════════════════════════════════');
    }
}

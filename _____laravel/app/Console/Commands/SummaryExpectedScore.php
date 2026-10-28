<?php

namespace App\Console\Commands;

use App\Services\ExpectedScoreService;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryExpectedScore
 *
 * 【概要】20261028 追加（よっしー資料「改良期待数値 v0.1.4」、B案：非公開の計算・保存）
 *   各出走馬の改良期待スコア E3（3着内）・E5（5着内）を計算し、
 *   t_horse_odds_finder_expected_score に保存する。画面・API・AI予想・r12.2・人気帯枠には使わない。
 *   comparison_only=1、validated_for_live=0 で保存する。
 *
 * 【計算】数式は App\Services\ExpectedScoreService（資料の参考実装 ExpectedValueV01 v0.1.4 を、名前だけこのプロジェクトに合わせて配置）。
 *   このコマンドは入力の読み出し・照合・近傍の選択（資料2章・4-6）だけを行う。
 *   条件は試算SQL（改良期待数値_試算SQL_20261028.sql）と同じ。
 *
 * 【入力】
 *   6分前・21分前単勝  : t_horse_odds_finder_summary.odds_tan_before_6 / _21
 *   6分前の取得日時    : t_horse_odds_finder_summary.odds_get_datetime_before_6（20261031 以降）
 *                        summary に無いときは t_horse_odds_finder_odds_get_timing（timing=6）を読む（20261028 追加）
 *   21分前の取得日時   : t_horse_odds_finder_odds_get_timing（timing=21。土曜05:50に消えるので、その週のレースのみ）（20261028 追加）
 *   発走予定時刻       : t_horse_odds_finder_races.start_time（その週のレースのみ）
 *   凍結した出馬表     : t_horse_odds_finder_horses（その週のレースのみ）
 *   着順               : t_horse_odds_finder_race_result_history.finishing_position（過去レースの Y のみ）
 *   芝ダ・距離・クラス : t_horse_odds_finder_race_result_payout（course/dist/grade/race_name/race_condition）
 *   確定オッズ・確定人気は使わない。対象レースの着順・払戻も使わない。
 *   20261028: horses・races・odds_get_timing は週ごとに消えるため、出馬表の馬番・summary の馬番・取消の馬番・
 *             発走予定・6分前と21分前の取得日時とその参照元を、保存表の roster_json に写して残す。
 *
 * 【対象レースの照合（どれかに当たれば未算出：UNAVAILABLE_INPUT／OUT_OF_SCOPE）】
 *   ・障害・芝ダ以外（OUT_OF_SCOPE）
 *   ・6分前の単勝が数値でも「取消」でもない馬がいる（6分前オッズ欠け。残りの頭数で計算しない）
 *   ・21分前オッズ欠け、距離なし、クラス不明
 *   ・凍結した出馬表（horses）と summary の馬番が一致しない（全馬照合。summary の行の欠落を検知）
 *   ・6分前の取得日時が無い、または r12.2 の期限（発走予定の330秒前）を超えている
 *   ・21分前の取得日時が無い、または r12.2 の期限（発走予定の1140秒前＝21分前＋猶予120秒）を超えている（20261028 追加）
 *     期限ちょうどは採用（r12.2 の BaganrikiTime::acceptBatch と同じく「超えたら」不採用）
 *     （--allow-missing-freeze のときだけ、記録が無い過去日も計算し、理由欄に「時刻記録なし」と残す。
 *       記録があって期限を超えているもの・出馬表と一致しないものは、この指定でも計算しない）
 *
 * 【過去レース（基準集団・近傍）】試算SQLと同じ。
 *   対象日より前・730日以内・同N・同surface・平地。6分前完全・全頭の着順確定。
 *   着順−1・未確定の馬がいるレースは除外（状態不明）。近傍はさらに21分前完全・クラス判明・距離あり。
 *   ※過去レースの6分前取得日時は 20261031 より前は記録が無いため、過去レース側の期限照合はしていない。
 *
 * 【使い方】
 *   php artisan keiba:summaryExpectedScore                       # 今日のレース
 *   php artisan keiba:summaryExpectedScore --date=2026-10-31
 *   php artisan keiba:summaryExpectedScore --from=2026-10-01 --to=2026-10-25 --allow-missing-freeze --dry-run --export=/tmp/expected_score.csv
 *     （--dry-run：保存しない、--export：馬ごとの結果をCSVに書く。試算SQLとの照合用）
 *   php artisan keiba:summaryExpectedScore --self-check
 *     （20261028 追加：対象レース照合の判定を、作った入力で試す。DBは読まない・保存しない）
 *   php artisan keiba:summaryExpectedScore --date=2026-10-25 --dry-run --trace=2026-10-25_05_09
 *     （20261028 追加：指定レースの条件文・class_group・各時刻・出馬表照合・判定を表示する）
 */
class SummaryExpectedScore extends Command
{
    protected $signature = 'keiba:summaryExpectedScore
        {--date= : 対象日（省略時は今日）}
        {--from= : 対象期間の開始日}
        {--to= : 対象期間の終了日}
        {--allow-missing-freeze : 6分前の取得日時・出馬表が無い過去日も計算する（理由欄に記録）}
        {--dry-run : DBに保存しない}
        {--export= : 馬ごとの結果を書き出すCSVのパス}
        {--self-check : 対象レース照合の判定を、作った入力で試す（DBに保存しない）}
        {--trace= : 指定した race_id（例 2026-10-25_05_09）の入力と判定を表示する}';

    protected $description = '改良期待数値 v0.1（E3/E5）を計算して保存する（非公開・比較用）';

    private const IMPL_VERSION    = 'baganriki-expected-impl-v0.1.4';
    private const FORMULA_VERSION = 'baganriki-expected-v0.1-draft-20261025';
    private const LAMBDA          = 20.0;
    private const LOOKBACK_DAYS   = 730;
    private const MAX_D2          = 9.0;
    private const MAX_NEIGHBOURS  = 200;
    private const FREEZE_SECONDS  = 330;   // r12.2：input_freeze_at = 発走予定 − 330秒（6分前 ＋ 猶予30秒）
    private const DEADLINE21_SECONDS = 1140;   // 20261028: r12.2：21分前の採用期限 = 発走予定 − 1140秒（21分前 ＋ 猶予120秒）

    /** 20261028: 保存先に21分前の取得日時の列があるか（ALTER 前でも動くようにする） */
    private bool $hasGot21Column = false;

    /** 20261028: 保存先に出馬表の記録の列があるか（ALTER 前でも動くようにする） */
    private bool $hasRosterColumn = false;

    public function handle(): void
    {
        // 20261028: 対象レース照合の判定だけを試す
        if ($this->option('self-check')) {
            $this->selfCheck();
            return;
        }
        $now = microtime(true);
        [$from, $to] = $this->period();
        if ($from === null) {
            return;
        }
        $allowMissingFreeze = (bool) $this->option('allow-missing-freeze');
        $dryRun             = (bool) $this->option('dry-run');
        $exportPath         = $this->option('export');

        $this->info('========== keiba:summaryExpectedScore 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("対象期間 : {$from} 〜 {$to}" . ($dryRun ? '（保存しない）' : '') . ($allowMissingFreeze ? '（時刻記録なしも計算）' : ''));

        DB::disableQueryLog();
        $this->hasGot21Column = \Illuminate\Support\Facades\Schema::hasColumn('t_horse_odds_finder_expected_score', 'odds_get_datetime_before_21');   // 20261028
        $this->hasRosterColumn = \Illuminate\Support\Facades\Schema::hasColumn('t_horse_odds_finder_expected_score', 'roster_json');   // 20261028
        $trace = (string) ($this->option('trace') ?? '');   // 20261028
        $races = $this->loadRaces(date('Y-m-d', strtotime($from . ' -' . self::LOOKBACK_DAYS . ' days')), $to);
        $this->info('  読み込んだレース数 : ' . count($races));

        $export = null;
        if ($exportPath) {
            $export = fopen($exportPath, 'w');
            fputcsv($export, ['race_id', 'num', 'popularity_rank_6min', 'N', 'class_group', 'support_status', 'unavailable_reason',
                'M', 'B3', 'B5', 'neighbour_count', 'W', 'n_eff', 'p3_raw', 'p5_raw', 'E3', 'E5']);
        }

        $count    = ['races' => 0, 'horses' => 0, 'saved' => 0];
        $byStatus = [];
        $status   = '正常終了';
        try {
            foreach ($races as $rid => $c) {
                if ($c['date'] < $from || $c['date'] > $to) {
                    continue;
                }
                $count['races']++;
                $raceCheck = $this->checkTargetRace($c, $allowMissingFreeze);
                $pool      = ($raceCheck['status'] === null) ? $this->pool($races, $c) : [];
                // 20261028: 指定レースの入力と判定を表示する（条件文 → class_group → 各時刻 → 出馬表照合 → 判定）
                if ($trace !== '' && $trace === $rid) {
                    $this->info("  [TRACE] {$rid}");
                    $this->info('    払戻表の条件文 race_condition : ' . ($c['race_condition'] ?? '(NULL)'));
                    $this->info("    class_group                  : {$c['class_group']}（surface {$c['surface']} ／ 距離 {$c['distance']}）");
                    $this->info('    発走予定                     : ' . ($raceCheck['nominal_start_at'] ?? '(なし)'));
                    $this->info('    6分前の取得日時              : ' . ($raceCheck['freeze_at'] ?? '(なし)') . "（期限 発走予定−" . self::FREEZE_SECONDS . '秒）');
                    $this->info('    21分前の取得日時             : ' . ($raceCheck['got21_at'] ?? '(なし)') . "（期限 発走予定−" . self::DEADLINE21_SECONDS . '秒）');
                    $this->info("    出馬表との全馬照合           : {$raceCheck['roster']}（summary " . count($c['rows']) . '頭）');
                    $this->info('    保存する入力の記録 roster_json : ' . ($raceCheck['roster_json'] ?? '(なし)'));   // 20261028
                    $this->info('    判定                         : ' . ($raceCheck['status'] ?? '計算する')
                        . ($raceCheck['reason'] !== '' ? "（{$raceCheck['reason']}）" : '')
                        . ' ／ 基準集団 ' . count($pool) . 'レース');
                }

                foreach ($c['runners'] as $idx => $h) {
                    $rank = $idx + 1;
                    $row  = $this->baseRow($c, $h, $rank, $raceCheck);
                    if ($raceCheck['status'] === null) {
                        $row = array_merge($row, $this->calculateHorse($c, $rank, $pool));
                    }
                    $byStatus[$row['support_status']] = ($byStatus[$row['support_status']] ?? 0) + 1;
                    $count['horses']++;
                    if ($export) {
                        fputcsv($export, [$rid, $h['num'], $rank, $c['N'], $c['class_group'], $row['support_status'], $row['unavailable_reason'],
                            $row['m'], $row['b3'], $row['b5'], $row['neighbour_count'], $row['w_sum'], $row['n_eff'],
                            $row['p3_raw'], $row['p5_raw'], $row['trial_score_3'], $row['trial_score_5']]);
                    }
                    if (!$dryRun) {
                        DB::table('t_horse_odds_finder_expected_score')->updateOrInsert(
                            ['date' => $c['date'], 'basho' => $c['basho'], 'race' => $c['race'], 'num' => $h['num'],
                             'implementation_version' => self::IMPL_VERSION],
                            $row + ['updated_at' => date('Y-m-d H:i:s')]
                        );
                        $count['saved']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            $status = '異常終了: ' . $e->getMessage();
            $this->error($status);
        } finally {
            if ($export) {
                fclose($export);
            }
            $elapsed = round(microtime(true) - $now, 1);
            ksort($byStatus);
            $this->info("  対象レース {$count['races']} ／ 馬 {$count['horses']} ／ 保存 {$count['saved']}");
            foreach ($byStatus as $k => $v) {
                $this->info("    {$k} : {$v}");
            }
            $this->info("終了理由 : {$status} ／ {$elapsed} 秒");
            $this->info('========== keiba:summaryExpectedScore 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            if (!$dryRun && $count['races'] > 0) {
                (new WebPushService())->sendPushNotifierDeveloperNews('develop',
                    "SummaryExpectedScore::handle\n{$status}、{$from}〜{$to}、レース:{$count['races']}、保存:{$count['saved']}");
            }
        }
    }

    /** 対象期間を決める（--date または --from/--to。省略時は今日） */
    private function period(): array
    {
        $date = $this->option('date');
        $from = $this->option('from') ?: ($date ?: date('Y-m-d'));
        $to   = $this->option('to') ?: ($date ?: $from);
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) {
                $this->error('日付は YYYY-MM-DD で指定してください。');
                return [null, null];
            }
        }
        return [$from, $to];
    }

    /** 6分前・21分前のオッズ文字列を数値にする（整数または小数1桁、1.0以上だけ） */
    private static function odds(?string $v): ?float
    {
        if ($v === null || !preg_match('/^[0-9]+(\.[0-9])?$/', $v)) {
            return null;
        }
        $f = (float) $v;
        return $f >= 1.0 ? $f : null;
    }

    /** 払戻表の情報から class_group を固定の対応で決める（試算SQLと同じ。race_condition があればそれを優先） */
    public static function classGroup(?string $course, ?string $grade, ?string $raceName, ?string $cond): string
    {
        $raceName = (string) $raceName;
        $cond     = (string) $cond;
        $grade    = (string) $grade;
        if (str_contains($raceName, '障害') || str_contains($cond, '障害') || str_starts_with($grade, 'J-') || $course === '芝→ダート') {
            return 'JUMP';
        }
        if (in_array($grade, ['G1', 'G2', 'G3', 'L'], true)) {
            return $grade;
        }
        foreach ([$cond, $raceName] as $t) {
            if ($t === '') {
                continue;
            }
            if (str_contains($t, '新馬') || str_contains($t, 'メイクデビュー')) {
                return 'NEW';
            }
            if (str_contains($t, '未勝利')) {
                return 'MAIDEN';
            }
            if (preg_match('/[1１]勝クラス/u', $t)) {
                return 'C1';
            }
            if (preg_match('/[2２]勝クラス/u', $t)) {
                return 'C2';
            }
            if (preg_match('/[3３]勝クラス/u', $t)) {
                return 'C3';
            }
            if (str_contains($t, 'オープン')) {
                return 'OP';
            }
        }
        return 'UNK';
    }

    /** 指定期間のレースを読み込み、レース単位にまとめる */
    private function loadRaces(string $from, string $to): array
    {
        $rows = DB::table('t_horse_odds_finder_summary as s')
            ->leftJoin('t_horse_odds_finder_race_result_history as h', function ($j) {
                $j->on('h.date', '=', 's.date')->on('h.basho_code', '=', 's.basho')
                  ->on('h.race', '=', 's.race')->on('h.num', '=', 's.num');
            })
            ->whereBetween('s.date', [$from, $to])
            ->orderBy('s.date')->orderBy('s.basho')->orderBy('s.race')->orderBy('s.num')
            ->get(['s.date', 's.kaisuu', 's.basho', 's.day', 's.race', 's.num', 's.horse_name',
                   's.odds_tan_before_6 as raw6', 's.odds_tan_before_21 as raw21',
                   's.odds_get_datetime_before_6 as got6', 'h.finishing_position as pos']);

        $payout = [];
        DB::table('t_horse_odds_finder_race_result_payout')->whereBetween('date', [$from, $to])
            ->get(['date', 'basho_code', 'race', 'race_name', 'grade', 'course', 'dist', 'race_condition'])
            ->each(function ($p) use (&$payout) {
                $payout["{$p->date}_{$p->basho_code}_{$p->race}"] = $p;
            });

        $races = [];
        foreach ($rows as $r) {
            $rid = sprintf('%s_%s_%02d', $r->date, $r->basho, $r->race);
            if (!isset($races[$rid])) {
                $p = $payout["{$r->date}_{$r->basho}_{$r->race}"] ?? null;
                $surface = 'OTHER';
                if ($p !== null && $p->course === '芝') {
                    $surface = 'TURF';
                } elseif ($p !== null && $p->course === 'ダート') {
                    $surface = 'DIRT';
                }
                $races[$rid] = [
                    'race_id' => $rid, 'date' => $r->date, 'kaisuu' => $r->kaisuu, 'basho' => $r->basho,
                    'day' => $r->day, 'race' => (int) $r->race,
                    'rows' => [], 'tmp' => [], 'miss6' => 0, 'miss21' => 0, 'pend' => 0, 'm1' => 0, 'got6' => null,
                    'payout'         => $p !== null,
                    'surface'        => $surface,
                    'distance'       => $p !== null ? (int) $p->dist : 0,
                    'class_group'    => $p === null ? 'NOPAY' : self::classGroup($p->course, $p->grade, $p->race_name, $p->race_condition),
                    'race_condition' => $p->race_condition ?? null,
                ];
            }
            $o6  = self::odds($r->raw6);
            $o21 = self::odds($r->raw21);
            // $races[$rid]['rows'][] = ['num' => (int) $r->num];   // 20261028: 取消の印を足したため、コメントアウト
            $races[$rid]['rows'][] = ['num' => (int) $r->num, 'cancelled' => (($r->raw6 ?? '') === '取消')];   // 20261028
            if ($o6 === null) {
                if (($r->raw6 ?? '') !== '取消') {
                    $races[$rid]['miss6']++;   // 「取消」以外の欠損は、レース全体を計算しない
                }
            } else {
                $pos = $r->pos === null ? null : (int) $r->pos;
                $races[$rid]['tmp'][] = ['num' => (int) $r->num, 'name' => $r->horse_name, 'o6' => $o6, 'o21' => $o21, 'pos' => $pos];
                if ($o21 === null) {
                    $races[$rid]['miss21']++;
                }
                if ($pos === null || $pos === 0) {
                    $races[$rid]['pend']++;
                }
                if ($pos !== null && $pos < 0) {
                    $races[$rid]['m1']++;
                }
            }
            if ($r->got6 !== null && ($races[$rid]['got6'] === null || $r->got6 > $races[$rid]['got6'])) {
                $races[$rid]['got6'] = $r->got6;
            }
        }

        foreach ($races as $rid => $race) {
            $run = $race['tmp'];
            // 人気順位：6分前の単勝の昇順、同値は馬番の昇順
            usort($run, fn($a, $b) => [$a['o6'], $a['num']] <=> [$b['o6'], $b['num']]);
            $flat = in_array($race['surface'], ['TURF', 'DIRT'], true)
                && !in_array($race['class_group'], ['JUMP', 'NOPAY'], true);
            $n = count($run);
            $races[$rid]['runners']       = $run;
            $races[$rid]['N']             = $n;
            $races[$rid]['odds6_by_rank'] = array_map(fn($h) => $h['o6'], $run);
            $races[$rid]['base_ok'] = $flat && $race['miss6'] === 0 && $race['pend'] === 0 && $race['m1'] === 0 && $n >= 2;
            $races[$rid]['nb_ok']   = $races[$rid]['base_ok'] && $race['class_group'] !== 'UNK'
                && $race['miss21'] === 0 && $race['distance'] > 0;
            unset($races[$rid]['tmp']);
        }
        return $races;
    }

    // 20261028: 旧版（21分前の期限照合を足し、判定部分を judgeTargetRace に分けたため、コメントアウト）
    // /** 対象レースの照合（未算出の理由。問題が無ければ status=null） */
    // private function checkTargetRace(array $c, bool $allowMissingFreeze): array
    // {
    //     $out = ['status' => null, 'reason' => '', 'freeze_at' => $c['got6'], 'nominal_start_at' => null, 'roster' => null];
    // 
    //     $start = DB::table('t_horse_odds_finder_races')
    //         ->where('date', $c['date'])->where('basho', $c['basho'])->where('race', $c['race'])->value('start_time');
    //     if ($start !== null && preg_match('/^\d{2}:\d{2}:\d{2}$/', (string) $start)) {
    //         $out['nominal_start_at'] = "{$c['date']} {$start}";
    //     }
    // 
    //     // 凍結した出馬表との全馬照合（summary の行そのものの欠落を検知する）
    //     $rosterNums = DB::table('t_horse_odds_finder_horses')
    //         ->where('date', $c['date'])->where('basho', $c['basho'])->where('race', $c['race'])
    //         ->pluck('num')->map(fn($n) => (int) $n)->sort()->values()->all();
    //     $summaryNums = collect($c['rows'])->pluck('num')->sort()->values()->all();
    //     if (empty($rosterNums)) {
    //         $out['roster'] = 'NOT_AVAILABLE';
    //     } else {
    //         $out['roster'] = ($rosterNums === $summaryNums) ? 'MATCH' : 'MISMATCH';
    //     }
    // 
    //     $set = function (string $status, string $reason) use (&$out) {
    //         if ($out['status'] === null) {
    //             $out['status'] = $status;
    //             $out['reason'] = $reason;
    //         }
    //     };
    //     if (!$c['payout']) {
    //         $set('UNAVAILABLE_INPUT', '払戻表なし（芝ダ・距離・クラス不明）');
    //     }
    //     if ($c['class_group'] === 'JUMP' || $c['surface'] === 'OTHER') {
    //         $set('OUT_OF_SCOPE', '対象外（障害など）');
    //     }
    //     if ($c['miss6'] > 0) {
    //         $set('UNAVAILABLE_INPUT', '6分前オッズ欠け');
    //     }
    //     if ($c['miss21'] > 0) {
    //         $set('UNAVAILABLE_INPUT', '21分前オッズ欠け');
    //     }
    //     if ($c['class_group'] === 'UNK') {
    //         $set('UNAVAILABLE_INPUT', 'クラス不明');
    //     }
    //     if ($c['distance'] <= 0) {
    //         $set('UNAVAILABLE_INPUT', '距離なし');
    //     }
    //     if ($c['N'] < 2) {
    //         $set('UNAVAILABLE_INPUT', '出走2頭未満');
    //     }
    //     if ($out['roster'] === 'MISMATCH') {
    //         $set('UNAVAILABLE_INPUT', '出馬表と馬番が一致しない');
    //     }
    // 
    //     // r12.2 と同じ期限：6分前の取得が「発走予定 − 330秒」を超えていない
    //     if ($out['freeze_at'] === null || $out['nominal_start_at'] === null) {
    //         if (!$allowMissingFreeze) {
    //             $set('UNAVAILABLE_INPUT', '6分前の取得日時または発走予定時刻の記録なし');
    //         } elseif ($out['status'] === null) {
    //             $out['reason'] = '時刻記録なし（--allow-missing-freeze で計算）';
    //         }
    //     } elseif (strtotime($out['freeze_at']) > strtotime($out['nominal_start_at']) - self::FREEZE_SECONDS) {
    //         $set('UNAVAILABLE_INPUT', 'ACQUISITION_DEADLINE_EXCEEDED（6分前の取得が期限超過）');
    //     }
    //     if ($out['roster'] === 'NOT_AVAILABLE' && !$allowMissingFreeze) {
    //         $set('UNAVAILABLE_INPUT', '凍結した出馬表なし（全馬照合できない）');
    //     }
    //     return $out;
    // }

    /**
     * 対象レースの照合（未算出の理由。問題が無ければ status=null）
     * 20261028: DBの読み出しだけをここで行い、判定は judgeTargetRace に分けた（--self-check で同じ判定を試すため）。
     *   21分前の取得日時を t_horse_odds_finder_odds_get_timing から読む。
     *   6分前の取得日時は summary を優先し、無ければ同じ表（timing=6）から読む（summary はこの表を写したもの）。
     */
    private function checkTargetRace(array $c, bool $allowMissingFreeze): array
    {
        $start = DB::table('t_horse_odds_finder_races')
            ->where('date', $c['date'])->where('basho', $c['basho'])->where('race', $c['race'])->value('start_time');

        // 凍結した出馬表との全馬照合（summary の行そのものの欠落を検知する）
        $rosterNums = DB::table('t_horse_odds_finder_horses')
            ->where('date', $c['date'])->where('basho', $c['basho'])->where('race', $c['race'])
            ->pluck('num')->map(fn($n) => (int) $n)->sort()->values()->all();

        $timing = [];
        DB::table('t_horse_odds_finder_odds_get_timing')
            ->where('date', $c['date'])->where('basho', $c['basho'])->where('race', $c['race'])
            ->whereIn('timing', [6, 21])
            ->groupBy('timing')
            ->get(['timing', DB::raw('MAX(get_datetime) AS get_datetime')])
            ->each(function ($r) use (&$timing) {
                $timing[(int) $r->timing] = $r->get_datetime;
            });
        $got6  = $c['got6'] ?? ($timing[6] ?? null);
        $got21 = $timing[21] ?? null;

        $out = self::judgeTargetRace($c, $start === null ? null : (string) $start, $rosterNums, $got6, $got21, $allowMissingFreeze);

        // 20261028: 元の表（horses・races・odds_get_timing）は週ごとに消えるため、後から照合できるように入力の記録を残す
        $out['roster_json'] = json_encode([
            'horses'    => $rosterNums,                                                         // 凍結した出馬表の馬番
            'summary'   => array_map(fn($r) => $r['num'], $c['rows']),                          // summary の馬番
            'cancelled' => array_values(array_map(fn($r) => $r['num'], array_filter($c['rows'], fn($r) => !empty($r['cancelled'])))),   // 6分前が「取消」の馬番
            'start_time'          => $start === null ? null : (string) $start,                  // races.start_time
            'got6_source'         => $c['got6'] !== null ? 'summary' : (isset($timing[6]) ? 'odds_get_timing' : null),
            'odds_get_timing_6'   => $timing[6] ?? null,
            'odds_get_timing_21'  => $timing[21] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        return $out;
    }

    /**
     * 20261028: 対象レースの照合の判定（DBを読まない）。最初に当たった理由を残す。
     *   期限は r12.2（BaganrikiTime::acceptBatch）と同じく「超えたら」不採用。期限ちょうどは採用。
     *     6分前  : 取得日時 > 発走予定 − 330秒  → ACQUISITION_DEADLINE_EXCEEDED
     *     21分前 : 取得日時 > 発走予定 − 1140秒 → ACQUISITION_DEADLINE_EXCEEDED
     *   --allow-missing-freeze は「記録が無い」ときだけ計算を許す。期限超過・出馬表の不一致は許さない。
     */
    public static function judgeTargetRace(array $c, ?string $start, array $rosterNums, ?string $got6, ?string $got21,
                                           bool $allowMissingFreeze): array
    {
        $out = ['status' => null, 'reason' => '', 'freeze_at' => $got6, 'got21_at' => $got21,
                'nominal_start_at' => null, 'roster' => null];

        if ($start !== null && preg_match('/^\d{2}:\d{2}:\d{2}$/', $start)) {
            $out['nominal_start_at'] = "{$c['date']} {$start}";
        }

        $summaryNums = array_map(fn($r) => (int) $r['num'], $c['rows']);
        sort($summaryNums);
        $rosterNums = array_map('intval', $rosterNums);
        sort($rosterNums);
        if (empty($rosterNums)) {
            $out['roster'] = 'NOT_AVAILABLE';
        } else {
            $out['roster'] = ($rosterNums === $summaryNums) ? 'MATCH' : 'MISMATCH';
        }

        $set = function (string $status, string $reason) use (&$out) {
            if ($out['status'] === null) {
                $out['status'] = $status;
                $out['reason'] = $reason;
            }
        };
        if (!$c['payout']) {
            $set('UNAVAILABLE_INPUT', '払戻表なし（芝ダ・距離・クラス不明）');
        }
        if ($c['class_group'] === 'JUMP' || $c['surface'] === 'OTHER') {
            $set('OUT_OF_SCOPE', '対象外（障害など）');
        }
        if ($c['miss6'] > 0) {
            $set('UNAVAILABLE_INPUT', '6分前オッズ欠け');
        }
        if ($c['miss21'] > 0) {
            $set('UNAVAILABLE_INPUT', '21分前オッズ欠け');
        }
        if ($c['class_group'] === 'UNK') {
            $set('UNAVAILABLE_INPUT', 'クラス不明');
        }
        if ($c['distance'] <= 0) {
            $set('UNAVAILABLE_INPUT', '距離なし');
        }
        if ($c['N'] < 2) {
            $set('UNAVAILABLE_INPUT', '出走2頭未満');
        }
        if ($out['roster'] === 'MISMATCH') {
            $set('UNAVAILABLE_INPUT', '出馬表と馬番が一致しない');
        }

        // r12.2 と同じ期限（記録があるものは、--allow-missing-freeze でも必ず照合する）
        $startTs = $out['nominal_start_at'] === null ? null : strtotime($out['nominal_start_at']);
        $got6Ts  = $got6 === null ? null : strtotime($got6);
        $got21Ts = $got21 === null ? null : strtotime($got21);
        if ($startTs !== null && $got6Ts !== null && $got6Ts > $startTs - self::FREEZE_SECONDS) {
            $set('UNAVAILABLE_INPUT', 'ACQUISITION_DEADLINE_EXCEEDED（6分前の取得が期限 発走予定−' . self::FREEZE_SECONDS . '秒 を超過）');
        }
        if ($startTs !== null && $got21Ts !== null && $got21Ts > $startTs - self::DEADLINE21_SECONDS) {
            $set('UNAVAILABLE_INPUT', 'ACQUISITION_DEADLINE_EXCEEDED（21分前の取得が期限 発走予定−' . self::DEADLINE21_SECONDS . '秒 を超過）');
        }

        $missing = [];
        if ($startTs === null) {
            $missing[] = '発走予定時刻';
        }
        if ($got6Ts === null) {
            $missing[] = '6分前の取得日時';
        }
        if ($got21Ts === null) {
            $missing[] = '21分前の取得日時';
        }
        if ($missing !== []) {
            if (!$allowMissingFreeze) {
                $set('UNAVAILABLE_INPUT', implode('・', $missing) . 'の記録なし');
            } elseif ($out['status'] === null) {
                $out['reason'] = '時刻記録なし：' . implode('・', $missing) . '（--allow-missing-freeze で計算）';
            }
        }
        if ($out['roster'] === 'NOT_AVAILABLE' && !$allowMissingFreeze) {
            $set('UNAVAILABLE_INPUT', '凍結した出馬表なし（全馬照合できない）');
        }
        return $out;
    }

    /**
     * 20261028: --self-check。対象レース照合の判定を、作った入力で試す（DBは読まない・保存しない）。
     *   発走予定 15:00:00 のレースを基準にし、1か所ずつ変えて、期待どおりの判定になるかを見る。
     */
    private function selfCheck(): void
    {
        $this->info('========== keiba:summaryExpectedScore --self-check 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $base = ['date' => '2026-10-31', 'payout' => true, 'class_group' => 'C1', 'surface' => 'TURF', 'distance' => 1600,
                 'miss6' => 0, 'miss21' => 0, 'N' => 3, 'rows' => [['num' => 1], ['num' => 2], ['num' => 3]]];
        $start = '15:00:00';
        $ok6   = '2026-10-31 14:54:20';   // 発走予定−340秒
        $ok21  = '2026-10-31 14:39:10';   // 発走予定−1250秒
        $roster = [1, 2, 3];

        // [名前, $c の上書き, 発走予定, 出馬表, 6分前, 21分前, allow, 期待 status, 理由に含む文字]
        $cases = [
            ['正常な入力',                         [], $start, $roster, $ok6, $ok21, false, null, ''],
            ['6分前：期限ちょうど（−330秒）',       [], $start, $roster, '2026-10-31 14:54:30', $ok21, false, null, ''],
            ['6分前：期限を1秒超過',                [], $start, $roster, '2026-10-31 14:54:31', $ok21, false, 'UNAVAILABLE_INPUT', '6分前の取得が期限'],
            ['21分前：期限ちょうど（−1140秒）',     [], $start, $roster, $ok6, '2026-10-31 14:41:00', false, null, ''],
            ['21分前：期限を1秒超過',               [], $start, $roster, $ok6, '2026-10-31 14:41:01', false, 'UNAVAILABLE_INPUT', '21分前の取得が期限'],
            ['6分前の取得日時なし',                 [], $start, $roster, null, $ok21, false, 'UNAVAILABLE_INPUT', '6分前の取得日時の記録なし'],
            ['21分前の取得日時なし',                [], $start, $roster, $ok6, null, false, 'UNAVAILABLE_INPUT', '21分前の取得日時の記録なし'],
            ['発走予定時刻なし',                    [], null, $roster, $ok6, $ok21, false, 'UNAVAILABLE_INPUT', '発走予定時刻の記録なし'],
            ['出馬表に summary に無い馬がいる',     [], $start, [1, 2, 3, 4], $ok6, $ok21, false, 'UNAVAILABLE_INPUT', '出馬表と馬番が一致しない'],
            ['summary の行が1頭欠落',               ['rows' => [['num' => 1], ['num' => 3]], 'N' => 2], $start, $roster, $ok6, $ok21, false, 'UNAVAILABLE_INPUT', '出馬表と馬番が一致しない'],
            ['凍結した出馬表なし',                  [], $start, [], $ok6, $ok21, false, 'UNAVAILABLE_INPUT', '凍結した出馬表なし'],
            ['6分前オッズ欠け',                     ['miss6' => 1], $start, $roster, $ok6, $ok21, false, 'UNAVAILABLE_INPUT', '6分前オッズ欠け'],
            ['21分前オッズ欠け',                    ['miss21' => 1], $start, $roster, $ok6, $ok21, false, 'UNAVAILABLE_INPUT', '21分前オッズ欠け'],
            ['クラス不明',                          ['class_group' => 'UNK'], $start, $roster, $ok6, $ok21, false, 'UNAVAILABLE_INPUT', 'クラス不明'],
            ['allow指定：時刻記録なしは計算する',   [], null, [], null, null, true, null, '時刻記録なし'],
            ['allow指定でも6分前の期限超過は計算しない',  [], $start, $roster, '2026-10-31 14:54:31', $ok21, true, 'UNAVAILABLE_INPUT', '6分前の取得が期限'],
            ['allow指定でも21分前の期限超過は計算しない', [], $start, $roster, $ok6, '2026-10-31 14:41:01', true, 'UNAVAILABLE_INPUT', '21分前の取得が期限'],
            ['allow指定でも出馬表の不一致は計算しない',   [], $start, [1, 2, 3, 4], $ok6, $ok21, true, 'UNAVAILABLE_INPUT', '出馬表と馬番が一致しない'],
        ];

        $pass = 0;
        $fail = 0;
        foreach ($cases as [$name, $over, $st, $ros, $g6, $g21, $allow, $wantStatus, $wantReason]) {
            $r  = self::judgeTargetRace(array_merge($base, $over), $st, $ros, $g6, $g21, $allow);
            $ok = ($r['status'] === $wantStatus) && ($wantReason === '' ? $r['reason'] === '' : str_contains($r['reason'], $wantReason));
            $ok ? $pass++ : $fail++;
            $this->info(($ok ? 'PASS ' : 'FAIL ') . $name . ' → ' . ($r['status'] ?? '計算する')
                . ($r['reason'] !== '' ? "（{$r['reason']}）" : '') . " ／ 出馬表 {$r['roster']}");
        }
        $this->info("{$pass} passed; {$fail} failed.");
        $this->info('========== keiba:summaryExpectedScore --self-check 終了 ' . date('Y-m-d H:i:s') . ' ==========');
    }


    /** 基準集団 P（対象日より前・730日以内・同N・同surface・全頭の着順確定） */
    private function pool(array $races, array $c): array
    {
        $min = date('Y-m-d', strtotime($c['date'] . ' -' . self::LOOKBACK_DAYS . ' days'));
        $out = [];
        foreach ($races as $j) {
            if ($j['base_ok'] && $j['N'] === $c['N'] && $j['surface'] === $c['surface']
                && $j['date'] < $c['date'] && $j['date'] >= $min) {
                $out[] = $j;
            }
        }
        return $out;
    }

    /** ExpectedScoreService::distanceSquared に渡す1レース分の特徴 */
    private function feature(array $race, int $rank): array
    {
        return [
            'odds6_by_rank'   => $race['odds6_by_rank'],
            'odds21_at_rank'  => $race['runners'][$rank - 1]['o21'],   // 6分前順位 rank の同じ馬の21分前
            'distance_metres' => $race['distance'],
            'class_group'     => $race['class_group'],
            'surface'         => $race['surface'],
            'race_type'       => 'FLAT',
        ];
    }

    /** 1頭分の計算（数式は ExpectedScoreService＝資料の ExpectedValueV01 をそのまま呼ぶ） */
    private function calculateHorse(array $c, int $rank, array $pool): array
    {
        $m       = count($pool);
        $b3      = 0;
        $b5      = 0;
        $cand    = [];
        $current = $this->feature($c, $rank);
        foreach ($pool as $j) {
            $pos = $j['runners'][$rank - 1]['pos'];
            $y3  = ($pos >= 1 && $pos <= 3) ? 1 : 0;
            $y5  = ($pos >= 1 && $pos <= 5) ? 1 : 0;
            $b3 += $y3;
            $b5 += $y5;
            if (!$j['nb_ok']) {
                continue;   // 基準集団には入るが、近傍候補にはしない
            }
            $d2 = ExpectedScoreService::distanceSquared($current, $this->feature($j, $rank), $rank);
            if ($d2 > self::MAX_D2) {
                continue;
            }
            $age    = (float) ((strtotime($c['date']) - strtotime($j['date'])) / 86400);
            $cand[] = ['race_id' => $j['race_id'], 'date' => $j['date'], 'd2' => $d2,
                       'w' => ExpectedScoreService::weight($d2, $age), 'y3' => $y3, 'y5' => $y5];
        }
        // w 降順、D² 昇順、開催日 降順、race_id 昇順（資料4-6）。結果 Y は並べ替えに使わない
        usort($cand, fn($a, $b) => [$b['w'], $a['d2'], $b['date'], $a['race_id']]
                                  <=> [$a['w'], $b['d2'], $a['date'], $b['race_id']]);
        $cand    = array_slice($cand, 0, self::MAX_NEIGHBOURS);
        $samples = array_map(fn($s) => ['race_id' => $s['race_id'], 'w' => $s['w'], 'y3' => $s['y3'], 'y5' => $s['y5']], $cand);

        $r = ExpectedScoreService::calculate($m, $b3, $b5, $samples, self::LAMBDA, null);
        return [
            'support_status'     => $r['support_status'],
            'm'                  => $m,
            'b3'                 => $b3,
            'b5'                 => $b5,
            'p0_3'               => $r['baseline']['p0_3'],
            'p0_5'               => $r['baseline']['p0_5'],
            'neighbour_count'    => $r['neighbour_count'],
            'w_sum'              => $r['W'],
            'a3'                 => $r['A3'],
            'a5'                 => $r['A5'],
            'n_eff'              => $r['n_eff'],
            'local_weight_share' => $r['local_weight_share'],
            'p3_raw'             => $r['p3_raw'],
            'p5_raw'             => $r['p5_raw'],
            'trial_score_3'      => $r['trial_score_3'],
            'trial_score_5'      => $r['trial_score_5'],
            'score_basis'        => $r['score_basis'],
            // 参照した過去レース（資料6章：race_id・D²・w）
            'neighbours_json'    => json_encode(array_map(fn($s) => [$s['race_id'], round($s['d2'], 6), round($s['w'], 8)], $cand),
                                                JSON_UNESCAPED_UNICODE),
        ];
    }

    /** 保存する1行の共通部分（未算出の行もこの形で残す。未算出を0点にしない） */
    private function baseRow(array $c, array $h, int $rank, array $chk): array
    {
        return [
            'date' => $c['date'], 'kaisuu' => $c['kaisuu'], 'basho' => $c['basho'], 'day' => $c['day'], 'race' => $c['race'],
            'num' => $h['num'], 'horse_name' => $h['name'], 'popularity_rank_6min' => $rank, 'n_runners' => $c['N'],
            'surface' => $c['surface'], 'distance' => $c['distance'], 'class_group' => $c['class_group'],
            'race_condition' => $c['race_condition'],
            'odds_6min' => $h['o6'], 'odds_21min' => $h['o21'],
            'freeze_at' => $chk['freeze_at'], 'nominal_start_at' => $chk['nominal_start_at'], 'roster_check' => $chk['roster'],
            'support_status' => $chk['status'] ?? 'UNAVAILABLE_BASELINE', 'unavailable_reason' => $chk['reason'],
            'm' => null, 'b3' => null, 'b5' => null, 'p0_3' => null, 'p0_5' => null,
            'neighbour_count' => null, 'w_sum' => null, 'a3' => null, 'a5' => null, 'n_eff' => null, 'local_weight_share' => null,
            'lambda' => self::LAMBDA, 'p3_raw' => null, 'p5_raw' => null, 'trial_score_3' => null, 'trial_score_5' => null,
            'score_basis' => 'UNAVAILABLE', 'neighbours_json' => null,
            'formula_version' => self::FORMULA_VERSION, 'implementation_version' => self::IMPL_VERSION,
            'comparison_only' => 1, 'validated_for_live' => 0,
        ]
        // 20261028: 21分前の取得日時（列を足した後だけ保存する）
        + ($this->hasGot21Column ? ['odds_get_datetime_before_21' => $chk['got21_at'] ?? null] : [])
        // 20261028: 出馬表の馬番・取消・時刻の参照元（列を足した後だけ保存する）
        + ($this->hasRosterColumn ? ['roster_json' => $chk['roster_json'] ?? null] : []);
    }
}

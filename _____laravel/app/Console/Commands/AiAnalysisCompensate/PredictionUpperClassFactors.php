<?php
namespace App\Console\Commands\AiAnalysisCompensate;

use Illuminate\Support\Facades\DB;

/**
 * プレミア第4稿（なる 11/26「事前集計と2勝クラス以上の追加ファクター」）の比較用資料（--prediction-upper-factors-day= の中身）
 *
 * keiba:ai-analysis-compensate（SummaryAiAnalysisCompensate）の一部です。
 *
 * @ANCHOR-20261126-UPPER-CLASS-FACTORS
 *   ■ 何をするか
 *     指定した日の全レースについて、第4稿の「追加資料」を作り、ファイルに書き出すだけです。
 *       ① 得意条件への復帰（H1）……同じ馬の過去最大10走から、競馬場・芝ダ・距離が今回と同じ走と、その3着以内
 *       ② 休み明け何走目（H1）……休養の区切りの定義がまだ無いので、仕様どおり「欠測」にします
 *       ③ 6分前単勝人気の順位別の3着以内率（H4）……全体表（背景値）と、同クラス区分・同芝ダ・同頭数・同順位の細分表
 *       ④ 複勝の支持加速（H2）……同じ馬・同じ指標（複勝下限・上限）の12・9・6分前の採用値で、厳密な整数比較
 *   ■ 本番には触りません
 *     ・DBは読むだけです（書き込み0件）。AIへは送りません（費用0円）。公開・凍結入力も変えません。
 *     ・なる第4稿「この版は本番差し替え前の比較用」「検証結果を本番に配信しない」に従います。
 *     ・書き出し先：storage/app/baganriki/upper_factors/日付.json（比較のときにこのファイルを使います）
 *   ■ データの事情（仕様に従って、足りない分は「欠測」として件数を残します）
 *     ・クラス：払戻の表（race_result_payout.race_condition）は2026年10月から。それより前は、レース名・グレードで判定し、
 *       特別戦の名前だけで分からないものは「判別不能」にします（推測しません）。
 *     ・6分前単勝人気：summary の記録は2026年6月6日から。仕様の「過去10年」には届かないので、実際の期間と件数を書きます。
 *       最終人気では埋めません。
 *     ・人気順位：凍結入力（baganriki_snapshots 世代0）があるレースはその順位を使い、振り直しません。
 *       凍結入力が無いレースは6分前単勝オッズの順で決め、同じオッズの馬がいて順位が決まらないものは欠測にします。
 *     ・④の複勝オッズ（t_horse_odds_finder_odds）は毎週土曜に消えるので、その週のうち（金曜まで）に実行します。
 *     ・今回のレースのクラス・芝ダ・距離は払戻の表から読むので、レースが終わった後に実行します（比較用の再現のため）。
 *
 *   使い方：php artisan keiba:ai-analysis-compensate --prediction-upper-factors-day=2026-11-28
 */
trait PredictionUpperClassFactors
{
    /** 第4稿の本文版（なる指定の識別名） */
    private static string $upperFactorsTextRevision = 'premium-e5-r5-prep-upper-20261126-rev4';
    /** この書き出しの定義の版（定義を変えたら上げる） */
    private static string $upperFactorsDefinitionVersion = 'upper-factors-def-20261126-01';

    private function runPredictionUpperFactorsDay(string $day): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $this->error('日付は 2026-11-28 の形で渡してください'); return; }
        $t0 = microtime(true);
        $races = DB::select(
            "SELECT DISTINCT s.date, s.kaisuu, s.basho, s.basho_name, s.day, s.race, s.race_name
               FROM t_horse_odds_finder_summary s WHERE s.date = ? ORDER BY s.basho, s.race", [$day]);
        if (empty($races)) { $this->warn("{$day} のレースが summary にありません"); return; }

        // ③の表は、その日の0時（日本時間）より前のレースだけで作ります（当日分・後の訂正を混ぜない）
        $tables = $this->upperPopularityTables($day);

        $out = [
            'text_revision'      => self::$upperFactorsTextRevision,
            'definition_version' => self::$upperFactorsDefinitionVersion,
            'target_date'        => $day,
            'built_at_jst'       => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'),
            'note'               => '比較用（本番に配信しない）。DBは読むだけ・AIへは送らない。',
            'popularity_table_coverage' => $tables['coverage'],
            'races'              => [],
        ];
        $cnt = ['races' => 0, 'upper' => 0, 'lower' => 0, 'unknown' => 0, 'jump' => 0,
                'return' => 0, 'accel' => 0, 'cohort_with_denominator' => 0];

        foreach ($races as $rc) {
            $cnt['races']++;
            $pay = DB::selectOne(
                "SELECT race_name, grade, race_condition, course, dist FROM t_horse_odds_finder_race_result_payout
                  WHERE date = ? AND basho_code = ? AND race = ? LIMIT 1", [$day, $rc->basho, (int) $rc->race]);
            $cls = self::upperClassOf($pay->race_condition ?? null, $pay->race_name ?? $rc->race_name, $pay->grade ?? null);
            $surface = self::upperSurface($pay->course ?? null);
            $dist = isset($pay->dist) ? (int) $pay->dist : null;
            $place = (string) $rc->basho_name;
            $raceKey = "{$day}-{$rc->kaisuu}-{$rc->basho}-{$rc->day}-{$rc->race}";
            $row = [
                'race_key' => $raceKey, 'place' => $place, 'race' => (int) $rc->race,
                'race_name' => $rc->race_name, 'race_condition' => $pay->race_condition ?? null,
                'class' => $cls, 'surface' => $surface, 'dist' => $dist,
                'applies' => in_array($cls, ['C2', 'C3', 'OP_L', 'GRADED'], true),
                'horses' => [],
            ];
            if ($cls === 'JUMP') { $cnt['jump']++; }
            elseif ($cls === null) { $cnt['unknown']++; }
            elseif (!$row['applies']) { $cnt['lower']++; }
            else { $cnt['upper']++; }
            if (!$row['applies']) {
                $row['reason'] = ($cls === null) ? 'クラス判別不能のため追加資料なし（仕様1）' : '対象外のクラスのため追加資料なし（従来どおり）';
                $out['races'][] = $row;
                continue;
            }

            // 出走馬と6分前人気（凍結入力の順位を優先）
            $frozen = self::upperFrozenRanks(self::upperRaceId($day, $rc));
            $runners = DB::select(
                "SELECT horse_name, num, odds_tan_before_6 FROM t_horse_odds_finder_summary
                  WHERE date = ? AND kaisuu = ? AND basho = ? AND day = ? AND race = ? ORDER BY num",
                [$day, $rc->kaisuu, $rc->basho, $rc->day, $rc->race]);
            $rank = $frozen['ranks'] ?? self::upperRankByOdds($runners);
            $fieldSize = $frozen['N'] ?? count(array_filter($rank, static fn($v) => $v !== null));
            $row['popularity_rank_source'] = $frozen !== null ? 'FROZEN_SNAPSHOT' : 'SUMMARY_TAN_6_NO_TIES';
            $row['field_size_at_freeze'] = $fieldSize;
            $odds = self::upperFukuSeries($day, $rc);

            foreach ($runners as $h) {
                $no = (int) $h->num;
                $pr = $rank[$no] ?? null;
                $hr = ['horse_no' => $no, 'name' => $h->horse_name, 'win_rank_6' => $pr];
                // ①②
                $hr['condition_return'] = self::upperConditionReturn((string) $h->horse_name, $day, $place, $surface, $dist);
                if (($hr['condition_return']['status'] ?? '') === 'RETURN') { $cnt['return']++; }
                $hr['runs_since_rest'] = ['status' => 'MISSING', 'reason' => '休養区切りの定義・版が未定（仕様3②：確かめられなければ欠測）'];
                // ③（有効な人気が無い馬は作らない）
                if ($pr === null) {
                    $hr['popularity_stats'] = ['status' => 'NO_VALID_RANK'];
                } else {
                    $ctxKey = (string) $pr;
                    $cohortKey = "{$cls}|{$surface}|{$fieldSize}|{$pr}";
                    $hr['popularity_stats'] = [
                        'context'  => ['role' => 'CONTEXT', 'key' => $ctxKey] + ($tables['overall'][$ctxKey] ?? ['num' => 0, 'den' => 0, 'pct' => null]),
                        'cohort'   => ['role' => 'COHORT', 'key' => $cohortKey, 'applies_to' => [$no]] + ($tables['cohort'][$cohortKey] ?? ['num' => 0, 'den' => 0, 'pct' => null]),
                    ];
                    if (($hr['popularity_stats']['cohort']['den'] ?? 0) > 0) { $cnt['cohort_with_denominator']++; }
                }
                // ④
                $hr['place_support'] = [
                    'fuku_min' => self::upperAccel($odds[$no]['fuku_min'] ?? []),
                    'fuku_max' => self::upperAccel($odds[$no]['fuku_max'] ?? []),
                ];
                foreach (['fuku_min', 'fuku_max'] as $m) { if ($hr['place_support'][$m]['state'] === 'ACCELERATING') { $cnt['accel']++; } }
                $row['horses'][] = $hr;
            }
            $out['races'][] = $row;
        }

        $dir = storage_path('app/baganriki/upper_factors');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = "{$dir}/{$day}.json";
        file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("=== 比較用の追加資料（第4稿） {$day} ===");
        $this->line("レース {$cnt['races']} ／ 2勝以上 {$cnt['upper']} ／ それより下 {$cnt['lower']} ／ 障害 {$cnt['jump']} ／ クラス判別不能 {$cnt['unknown']}");
        $this->line("①条件復帰の馬 {$cnt['return']} 頭 ／ ③細分表に母数がある馬 {$cnt['cohort_with_denominator']} 頭 ／ ④支持加速 {$cnt['accel']} 件（下限・上限の合計）");
        $cv = $tables['coverage'];
        $this->line("③の集計期間 {$cv['from']}〜{$cv['to']}（6分前人気の記録は2026-06-06から）：2勝以上のレース {$cv['races_used']} ／ 延べ {$cv['entries_used']} 頭 ／ 欠測 {$cv['entries_missing']} 頭 ／ クラス判別不能で外したレース {$cv['races_class_unknown']}");
        $this->line('書き出し：' . $file);
        $this->line('かかった時間：' . round(microtime(true) - $t0, 1) . ' 秒（DBは読むだけ・AIへは送っていません）');
    }

    /** 凍結入力の race_id（例 20261123-5-05-6-12） */
    private static function upperRaceId(string $day, object $rc): string
    {
        return str_replace('-', '', $day) . "-{$rc->kaisuu}-{$rc->basho}-{$rc->day}-{$rc->race}";
    }

    /**
     * クラス区分：C2＝2勝、C3＝3勝、OP_L＝オープン（リステッド含む）、GRADED＝重賞、LOWER＝新馬・未勝利・1勝、JUMP＝障害、null＝判別不能
     */
    private static function upperClassOf(?string $condition, ?string $name, ?string $grade): ?string
    {
        $g = (string) $grade;
        $t = (string) $condition . ' ' . (string) $name;
        if (str_contains($t, '障害') || str_starts_with($g, 'J')) { return 'JUMP'; }
        if (preg_match('/^(G[123]|GⅠ|GⅡ|GⅢ|Jpn)/u', $g)) { return 'GRADED'; }
        if ($g === 'L' || $g === 'リステッド') { return 'OP_L'; }
        if (preg_match('/新馬|未勝利|1勝クラス|メイクデビュー/u', $t)) { return 'LOWER'; }
        if (str_contains($t, '2勝クラス')) { return 'C2'; }
        if (str_contains($t, '3勝クラス')) { return 'C3'; }
        if (str_contains($t, 'オープン')) { return 'OP_L'; }
        return null;
    }

    private static function upperSurface(?string $course): ?string
    {
        $c = (string) $course;
        if ($c === '') { return null; }
        if (str_contains($c, '障')) { return 'JUMP'; }
        if (str_contains($c, 'ダ')) { return 'DIRT'; }
        if (str_contains($c, '芝')) { return 'TURF'; }
        return null;
    }

    /** 凍結入力の6分前単勝順位（振り直さない）。無ければ null */
    private static function upperFrozenRanks(string $raceId): ?array
    {
        $s = DB::selectOne(
            "SELECT JSON_EXTRACT(common_json, '$.runners') r, JSON_EXTRACT(common_json, '$.N') n
               FROM baganriki_snapshots WHERE race_id = ? AND generation = 0 ORDER BY id DESC LIMIT 1", [$raceId]);
        if ($s === null || $s->r === null) { return null; }
        $ranks = [];
        foreach ((array) json_decode((string) $s->r, true) as $r) {
            if (isset($r['horse_no'])) { $ranks[(int) $r['horse_no']] = isset($r['win_rank']) ? (int) $r['win_rank'] : null; }
        }
        return ['ranks' => $ranks, 'N' => $s->n !== null ? (int) $s->n : null];
    }

    /** 凍結入力が無いレース：6分前単勝の順。同じオッズの馬がいたら、その馬たちは欠測（推定しない） */
    private static function upperRankByOdds(array $runners): array
    {
        $v = [];
        foreach ($runners as $h) {
            $o = is_numeric($h->odds_tan_before_6 ?? null) ? (float) $h->odds_tan_before_6 : null;
            if ($o !== null && $o > 0) { $v[(int) $h->num] = $o; }
        }
        asort($v);
        $count = array_count_values(array_map('strval', $v));
        $ranks = []; $i = 0;
        foreach ($runners as $h) { $ranks[(int) $h->num] = null; }
        foreach ($v as $no => $o) { $i++; $ranks[$no] = ($count[(string) $o] > 1) ? null : $i; }
        return $ranks;
    }

    /**
     * ① 得意条件への復帰。過去最大10回の実出走（取消・除外を数えない）から、競馬場・芝ダ・距離が今回と同じ走を拾う。
     *   好走＝確定1〜3着。直前の実出走と今回の3項がすべて分かり、1項以上違い、同条件の過去好走があるときだけ RETURN。
     */
    private static function upperConditionReturn(string $name, string $day, string $place, ?string $surface, ?int $dist): array
    {
        $rows = DB::select(
            "SELECT date, basho, race, race_name, grade, dist, finishing_position, `condition`
               FROM t_horse_odds_finder_shutsuba_history
              WHERE name = ? AND date < ? AND finishing_position IS NOT NULL AND finishing_position > 0
              ORDER BY date DESC LIMIT 10", [$name, $day]);
        if (empty($rows)) { return ['status' => 'NO_PAST_RUNS']; }
        $runs = [];
        foreach ($rows as $r) {
            $d = (string) $r->dist;
            $sf = str_contains($d, '障') ? 'JUMP' : (str_contains($d, 'ダ') ? 'DIRT' : (str_contains($d, '芝') ? 'TURF' : null));
            $dm = preg_match('/(\d{3,4})/', $d, $m) ? (int) $m[1] : null;
            $date = substr((string) $r->date, 0, 10);
            $pay = DB::selectOne("SELECT race_condition FROM t_horse_odds_finder_race_result_payout WHERE date = ? AND basho = ? AND race = ? LIMIT 1",
                                 [$date, $r->basho, (int) $r->race]);
            $runs[] = [
                'date' => $date, 'place' => (string) $r->basho, 'surface' => $sf, 'dist' => $dm,
                'finish' => (int) $r->finishing_position, 'going' => $r->condition,
                'class' => self::upperClassOf($pay->race_condition ?? null, $r->race_name, $r->grade),
                'race_name' => $r->race_name,
            ];
        }
        $known = $surface !== null && $dist !== null && $place !== '';
        $same = array_values(array_filter($runs, static fn($x) =>
            $known && $x['place'] === $place && $x['surface'] === $surface && $x['dist'] === $dist));
        $good = array_values(array_filter($same, static fn($x) => $x['finish'] >= 1 && $x['finish'] <= 3));
        $prev = $runs[0];
        $prevKnown = $prev['place'] !== '' && $prev['surface'] !== null && $prev['dist'] !== null;
        $res = [
            'past_runs_used' => count($runs),
            'same_condition_runs' => count($same),
            'same_condition_top3' => count($good),
            // 仕様3①「下級クラスでの着順を上級クラスでの実績に読み替えない」ため、好走のクラスの内訳も残します
            'same_condition_top3_upper_class' => count(array_filter($good, static fn($x) => in_array($x['class'], ['C2', 'C3', 'OP_L', 'GRADED'], true))),
            'same_condition_top3_lower_class' => count(array_filter($good, static fn($x) => $x['class'] === 'LOWER')),
            'same_condition_top3_class_unknown' => count(array_filter($good, static fn($x) => $x['class'] === null)),
            'same_condition_detail' => $same,
            'previous_run' => $prev,
        ];
        if (!$known || !$prevKnown) {
            $res['status'] = 'PREVIOUS_OR_CURRENT_UNKNOWN';   // 確認できた過去実績だけを使う
        } elseif ($prev['place'] === $place && $prev['surface'] === $surface && $prev['dist'] === $dist) {
            $res['status'] = 'SAME_AS_PREVIOUS';              // 同条件での過去実績として扱う
        } elseif (count($good) > 0) {
            $res['status'] = 'RETURN';
        } else {
            $res['status'] = 'NO_RETURN';
        }
        return $res;
    }

    /** ④ 12・9・6分前の複勝採用値（その週の odds 表から。毎週土曜に消える） */
    private static function upperFukuSeries(string $day, object $rc): array
    {
        $rows = DB::select(
            "SELECT num, minutes_before_start m, fuku_min, fuku_max FROM t_horse_odds_finder_odds
              WHERE date = ? AND kaisuu = ? AND basho = ? AND day = ? AND race = ? AND minutes_before_start IN (12, 9, 6)",
            [$day, $rc->kaisuu, $rc->basho, $rc->day, $rc->race]);
        $o = [];
        foreach ($rows as $r) {
            $o[(int) $r->num]['fuku_min'][(int) $r->m] = (string) $r->fuku_min;
            $o[(int) $r->num]['fuku_max'][(int) $r->m] = (string) $r->fuku_max;
        }
        return $o;
    }

    /**
     * ④ 支持加速：12・9・6分の正の元値を同じ十進桁で整数化して A、B、C。
     *   A>B>C>0 かつ A×(B−C) > B×(A−B) のときだけ ACCELERATING（等号は加速なし）。
     *   12分が欠けても9・6分が有効なら、その区間の変化だけを記録する（加速・減速は推定しない）。
     */
    private static function upperAccel(array $s): array
    {
        $ok = static fn($v) => is_string($v) && preg_match('/^\d+(\.\d+)?$/', $v) && (float) $v > 0;
        $raw = ['12' => $s[12] ?? null, '9' => $s[9] ?? null, '6' => $s[6] ?? null];
        $have12 = $ok($raw['12']); $have9 = $ok($raw['9']); $have6 = $ok($raw['6']);
        if (!$have9 || !$have6) { return ['state' => 'PENDING_MISSING', 'raw' => $raw]; }
        $vals = $have12 ? [$raw['12'], $raw['9'], $raw['6']] : [$raw['9'], $raw['6']];
        $dec = 0;
        foreach ($vals as $v) { $p = strpos($v, '.'); $dec = max($dec, $p === false ? 0 : strlen($v) - $p - 1); }
        $toInt = static function (string $v) use ($dec): int {
            [$i, $f] = array_pad(explode('.', $v, 2), 2, '');
            return (int) ($i . str_pad($f, $dec, '0'));
        };
        if (!$have12) {
            $b = $toInt($raw['9']); $c = $toInt($raw['6']);
            return ['state' => $c < $b ? 'SHORTENED_9_6' : ($c === $b ? 'FLAT_9_6' : 'LENGTHENED_9_6'), 'raw' => $raw, 'note' => '12分前が無いので区間の変化だけ'];
        }
        $a = $toInt($raw['12']); $b = $toInt($raw['9']); $c = $toInt($raw['6']);
        if ($a > $b && $b > $c && $c > 0) {
            $l = $a * ($b - $c); $r = $b * ($a - $b);
            return ['state' => $l > $r ? 'ACCELERATING' : ($l === $r ? 'SAME_RATE_NO_ACCEL' : 'DECELERATING'), 'raw' => $raw];
        }
        return ['state' => 'NOT_MONOTONE_SHORTENING', 'raw' => $raw];
    }

    /**
     * ③ 6分前単勝順位別の3着以内率。対象日の前日までの、JRA平地2勝クラス以上の確定レース。
     *   分母＝その順位で実際に出走した延べ頭数（取消・除外は除く。中止は分母だけ）、分子＝確定1〜3着。
     *   overall＝順位だけ（CONTEXT・背景値）、cohort＝クラス区分|芝ダ|凍結時の頭数|順位（COHORT）。
     */
    private function upperPopularityTables(string $day): array
    {
        $from = (new \DateTimeImmutable($day))->modify('-10 years')->format('Y-m-d');
        $races = DB::select(
            "SELECT DISTINCT s.date, s.kaisuu, s.basho, s.day, s.race, s.race_name
               FROM t_horse_odds_finder_summary s WHERE s.date >= ? AND s.date < ?", [$from, $day]);
        $overall = []; $cohort = [];
        $cov = ['from' => null, 'to' => null, 'races_used' => 0, 'entries_used' => 0, 'entries_missing' => 0,
                'races_class_unknown' => 0, 'races_lower_or_jump' => 0,
                'note' => '6分前単勝人気の記録は2026-06-06から。10年分ではない。最終人気では埋めていない。'];
        foreach ($races as $rc) {
            $pay = DB::selectOne("SELECT race_name, grade, race_condition, course FROM t_horse_odds_finder_race_result_payout
                                   WHERE date = ? AND basho_code = ? AND race = ? LIMIT 1", [$rc->date, $rc->basho, (int) $rc->race]);
            $cls = self::upperClassOf($pay->race_condition ?? null, $pay->race_name ?? $rc->race_name, $pay->grade ?? null);
            if ($cls === null) { $cov['races_class_unknown']++; continue; }
            if (!in_array($cls, ['C2', 'C3', 'OP_L', 'GRADED'], true)) { $cov['races_lower_or_jump']++; continue; }
            $surface = self::upperSurface($pay->course ?? null);
            $res = DB::select("SELECT num, finishing_position, finishing_status FROM t_horse_odds_finder_race_result_history
                                WHERE date = ? AND basho_code = ? AND race = ?", [$rc->date, $rc->basho, (int) $rc->race]);
            if (empty($res)) { continue; }   // 結果が未確定・欠測のレースは数えない（凡走扱いしない）
            $runners = DB::select("SELECT horse_name, num, odds_tan_before_6 FROM t_horse_odds_finder_summary
                                    WHERE date = ? AND kaisuu = ? AND basho = ? AND day = ? AND race = ?",
                                  [$rc->date, $rc->kaisuu, $rc->basho, $rc->day, $rc->race]);
            $frozen = self::upperFrozenRanks(self::upperRaceId((string) $rc->date, $rc));
            $rank = $frozen['ranks'] ?? self::upperRankByOdds($runners);
            $n = $frozen['N'] ?? count(array_filter($rank, static fn($v) => $v !== null));
            $used = false;
            foreach ($res as $r) {
                $st = (string) $r->finishing_status;
                if ($st === '取消' || $st === '除外') { continue; }
                $pr = $rank[(int) $r->num] ?? null;
                if ($pr === null) { $cov['entries_missing']++; continue; }
                $top3 = ((int) $r->finishing_position >= 1 && (int) $r->finishing_position <= 3) ? 1 : 0;
                $ko = (string) $pr;
                $kc = "{$cls}|{$surface}|{$n}|{$pr}";
                $overall[$ko] ??= ['num' => 0, 'den' => 0];
                $overall[$ko]['den']++; $overall[$ko]['num'] += $top3;
                $cohort[$kc] ??= ['num' => 0, 'den' => 0];
                $cohort[$kc]['den']++; $cohort[$kc]['num'] += $top3;
                $cov['entries_used']++; $used = true;
            }
            if ($used) {
                $cov['races_used']++;
                $d = (string) $rc->date;
                if ($cov['from'] === null || $d < $cov['from']) { $cov['from'] = $d; }
                if ($cov['to'] === null || $d > $cov['to']) { $cov['to'] = $d; }
            }
        }
        $rate = static function (array $t): array {
            foreach ($t as $k => $v) {
                // 0≦分子≦分母 と率の整合を確かめる
                if ($v['num'] < 0 || $v['num'] > $v['den']) { $t[$k]['pct'] = null; $t[$k]['invalid'] = true; continue; }
                $t[$k]['pct'] = $v['den'] > 0 ? round(100 * $v['num'] / $v['den'], 1) : null;
            }
            ksort($t);
            return $t;
        };
        return ['overall' => $rate($overall), 'cohort' => $rate($cohort), 'coverage' => $cov];
    }
}

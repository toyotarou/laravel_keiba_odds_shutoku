<?php
namespace App\Http\Controllers\Api;

/**
 * @ANCHOR-20261124-SERVER-RACE-METRICS  3指標（波乱度・下位進入度・大穴進入度）を、サーバが凍結入力だけから計算します
 *
 * よっしー 11/23「3指標が中途半端」→ なる 11/24 の決定（問1 A・問2 A・問3 A・問3-2 B・問4 B）。
 *   ・AI の答えは使いません。同じ凍結入力なら、何度計算しても同じ値になります（DB も時刻も見ません）。
 *   ・波乱度      ＝ レースの型の点数 S = 2*X1 + X2 + X3 + X4 + X5 + X6 - 2*Z を 1〜5 の段階にします。
 *                   信号に不明があるときは、取り得る点数の下限・上限を残し、段階がまたがれば低い方を「暫定」にします。
 *   ・下位進入度  ＝ 凍結単勝 7〜10番人気の馬の「5着内傾向（E5）」の最大値を 1〜5 の段階にします。
 *   ・大穴進入度  ＝ 凍結単勝 11番人気以下の馬で同じ。3以上は、対象帯の同じ馬の複勝（下限か上限のどちらか一方）が
 *                   12分前＞9分前＞6分前 と連続して短縮していることが条件です（無ければ 2 まで）。
 *   ・E5 を出せないレースは、対象帯の複勝オッズの動きから「参考値」を出します（算出元を分けて残します）。
 *   ・対象帯に馬がいなければ「対象馬なし」、6分前とそれより前の複勝が無ければ「時系列不足」で、値は null です。
 *   ・最初の2週末は表示だけに使います（選出の入れ替えには使いません）。
 */
final class BaganrikiServerRaceMetrics
{
    public const POLICY = 'SERVER_RACE_METRICS_V1';
    public const KEYS = ['wave_level', 'lower_entry', 'big_gap_entry'];
    public const LABELS = ['wave_level' => '波乱度', 'lower_entry' => '下位進入度', 'big_gap_entry' => '大穴進入度'];

    /** 算出元（記録用の名前 → 画面に出す言葉） */
    public const BASIS_MODE_SCORE = 'MODE_SCORE';
    public const BASIS_EXPECTED_TOP5 = 'EXPECTED_TOP5';
    public const BASIS_PLACE_ODDS_REFERENCE = 'PLACE_ODDS_REFERENCE';
    public const BASIS_LABELS = [
        self::BASIS_MODE_SCORE => 'レースの型',
        self::BASIS_EXPECTED_TOP5 => 'E5',
        self::BASIS_PLACE_ODDS_REFERENCE => '参考・複勝オッズ',
    ];

    /** 波乱度：点数 → 段階（この点数以下ならその段階） */
    public const WAVE_SCORE_MAX_BY_LEVEL = [1 => 0, 2 => 1, 3 => 3, 4 => 5];   // 6以上は 5

    /**
     * 下位・大穴進入度：対象帯の5着内傾向（E5）の最大値 → 段階（この値未満ならその段階）
     * @ANCHOR-20261124-ENTRY-LEVEL-BY-BAND なる 11/24 問7 A：人気帯ごとに区切りを分ける。
     * 11/21〜23 の71レースから決めた暫定値。最初の2週末（11/28〜30、12/5〜6）は固定し、その後に見直す。
     */
    // public const EXPECTED_TOP5_BELOW_BY_LEVEL = [1 => 20.0, 2 => 30.0, 3 => 40.0, 4 => 50.0];   // 共通の区切り（11/24 試算の初版）
    public const EXPECTED_TOP5_BELOW_BY_LEVEL = [
        'lower_entry'   => [1 => 35.0, 2 => 40.0, 3 => 44.0, 4 => 50.0],   // 7〜10番人気。50以上は 5
        'big_gap_entry' => [1 => 18.0, 2 => 20.0, 3 => 23.0, 4 => 30.0],   // 11番人気以下。30以上は 5
    ];

    /** 複勝オッズの参考値：連続短縮（12＞9＞6分前）の下がり幅が、この割合以上なら「大きい」 */
    public const PLACE_ODDS_LARGE_DROP_RATIO = 0.10;

    private const SIGNAL_WEIGHTS = ['X1' => 2, 'X2' => 1, 'X3' => 1, 'X4' => 1, 'X5' => 1, 'X6' => 1, 'Z' => -2];

    /**
     * この日（レースの日）以降のレースから、画面の3指標をサーバ計算の値にします。
     * @ANCHOR-20261124-METRICS-DISPLAY-FROM  なる 11/24 問8 B：
     *   「11/27 までのレースの画面には、発走前に利用者が見た指標（当時 AI が答えて検査を通した保存値）を残す。
     *     11/28 以降の新しい予想からサーバ計算の値を表示する」。
     *   過去のレースを凍結入力から計算し直した値は、画面には出さず、比較・検証にだけ使います。
     */
    public const DISPLAY_FROM_RACE_DATE = '2026-11-28';

    /**
     * この凍結入力・このレース日で、画面にサーバ計算の3指標を出すか。
     * @ANCHOR-20261124-SERVER-METRICS-GATE  AI が3指標を返す本文版かどうか（BaganrikiRaceMetrics::enabled）とは切り離します。
     *   1st の本文から3指標を外した版（第7版）になっても、サーバ計算の表示は続けるためです。
     */
    public static function enabled(array $in, ?string $raceDate = null): bool
    {
        if (!is_array($in['runners'] ?? null) || $in['runners'] === []) { return false; }
        if (!is_string($raceDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raceDate) !== 1) { return false; }
        return strcmp($raceDate, self::DISPLAY_FROM_RACE_DATE) >= 0;
    }

    /** 凍結入力（1st 用でも 2nd 用でも同じ結果）から、3指標を計算します */
    public static function compute(array $frozenInput): array
    {
        $bands = self::bandHorses($frozenInput);
        $placeOdds = self::placeOddsSeries($frozenInput);
        $expectedTop5 = self::expectedTop5ByHorse($frozenInput);
        return [
            'policy_id' => self::POLICY,
            'snapshot_hash' => $frozenInput['snapshot_hash'] ?? null,
            'metrics' => [
                'wave_level' => self::waveLevel($frozenInput['signals'] ?? null),
                'lower_entry' => self::entryLevel('lower_entry', $bands['lower'], $expectedTop5, $placeOdds),
                'big_gap_entry' => self::entryLevel('big_gap_entry', $bands['big_gap'], $expectedTop5, $placeOdds),
            ],
        ];
    }

    /** 画面に出す短い言葉（例「3（E5）」「3（参考・複勝オッズ）」「2（暫定）」「未算出（時系列不足）」） */
    public static function displayText(array $metric): string
    {
        if (($metric['value'] ?? null) === null) { return '未算出（' . (string) ($metric['reason_short'] ?? '資料不足') . '）'; }
        $basis = (string) ($metric['basis'] ?? '');
        if ($basis === self::BASIS_MODE_SCORE) { return $metric['value'] . (($metric['provisional'] ?? false) ? '（暫定）' : ''); }
        return $metric['value'] . '（' . (self::BASIS_LABELS[$basis] ?? $basis) . '）';
    }

    /**
     * @ANCHOR-20261124-SERVER-METRICS-DISPLAY  画面に返す形（compute の戻り値から作ります）
     *   values：今までと同じ形（1〜5 の数字。出せなければ null。対象の人気帯に馬がいなければ文字の「対象外」）
     *   notes ：数字の下に添える言葉（「暫定」「E5」「参考・複勝オッズ」「時系列不足」など。添えるものが無ければ null）と、算出の理由
     */
    public const DISPLAY_NAMES = ['wave_level' => '波乱度', 'lower_entry' => '下位進入度', 'big_gap_entry' => '大穴進入度'];

    public static function displayValues(array $computed): array
    {
        $values = []; $notes = [];
        foreach (self::DISPLAY_NAMES as $key => $name) {
            $m = (array) ($computed['metrics'][$key] ?? []);
            $value = is_int($m['value'] ?? null) ? $m['value'] : null;
            $short = $m['reason_short'] ?? null;
            if ($value === null) {
                $values[$name] = ($short === '対象馬なし') ? '対象外' : null;
                $note = ($short === '対象馬なし') ? null : $short;
            } else {
                $values[$name] = $value;
                $basis = (string) ($m['basis'] ?? '');
                $note = ($basis === self::BASIS_MODE_SCORE) ? ((($m['provisional'] ?? false) === true) ? '暫定' : null) : (self::BASIS_LABELS[$basis] ?? null);
            }
            $notes[$name] = ['note' => $note, 'text' => self::displayText($m), 'reason' => $m['reason'] ?? null];
        }
        return ['policy_id' => $computed['policy_id'] ?? self::POLICY, 'values' => $values, 'notes' => $notes];
    }

    // ───────────────────────── 波乱度 ─────────────────────────

    public static function waveLevelOfScore(int $score): int
    {
        foreach (self::WAVE_SCORE_MAX_BY_LEVEL as $level => $max) { if ($score <= $max) { return $level; } }
        return 5;
    }

    private static function waveLevel(mixed $signals): array
    {
        if (!is_array($signals)) {
            return ['value' => null, 'basis' => null, 'provisional' => false, 'reason_short' => '型の信号なし',
                    'reason' => 'レースの型の信号が凍結入力に無いため、未算出', 'detail' => null];
        }
        $low = 0; $high = 0; $unknown = [];
        foreach (self::SIGNAL_WEIGHTS as $name => $weight) {
            $v = $signals[$name] ?? null;
            if ($v === true) { $low += $weight; $high += $weight; }
            elseif ($v === null) { $unknown[] = $name; if ($weight > 0) { $high += $weight; } else { $low += $weight; } }
        }
        $levelLow = self::waveLevelOfScore($low); $levelHigh = self::waveLevelOfScore($high);
        $provisional = ($levelLow !== $levelHigh);
        $reason = ($unknown === [])
            ? "レースの型の点数 {$low}"
            : "レースの型の点数 {$low}〜{$high}（未確定の信号：" . implode('・', $unknown) . '）'
              . ($provisional ? "。段階が {$levelLow}〜{$levelHigh} にまたがるため、低い方を暫定で表示" : '。どちらでも同じ段階');
        return ['value' => $levelLow, 'basis' => self::BASIS_MODE_SCORE, 'provisional' => $provisional, 'reason_short' => null,
                'reason' => $reason,
                'detail' => ['score_low' => $low, 'score_high' => $high, 'level_low' => $levelLow, 'level_high' => $levelHigh, 'unknown_signals' => $unknown]];
    }

    // ───────────────────── 下位進入度・大穴進入度 ─────────────────────

    public static function expectedTop5LevelOf(float $value, string $key): int
    {
        foreach (self::EXPECTED_TOP5_BELOW_BY_LEVEL[$key] as $level => $below) { if ($value < $below) { return $level; } }
        return 5;
    }

    private static function entryLevel(string $key, array $horses, array $expectedTop5, array $placeOdds): array
    {
        $bandName = ($key === 'lower_entry') ? '7〜10番人気' : '11番人気以下';
        if ($horses === []) {
            return ['value' => null, 'basis' => null, 'provisional' => false, 'reason_short' => '対象馬なし',
                    'reason' => "凍結単勝{$bandName}の対象馬なし", 'detail' => ['horses' => []]];
        }
        $movement = self::placeOddsMovement($horses, $placeOdds);
        $best = null; $bestHorse = null;
        foreach ($horses as $no) {
            $v = $expectedTop5[$no] ?? null;
            if ($v !== null && ($best === null || $v > $best)) { $best = $v; $bestHorse = $no; }
        }
        $detail = ['horses' => $horses, 'expected_top5_max' => $best, 'expected_top5_horse' => $bestHorse, 'place_odds' => $movement];

        if ($best !== null) {
            $level = self::expectedTop5LevelOf($best, $key);
            $reason = "{$bandName}の5着内傾向（E5）の最大 " . self::num($best) . "（馬番{$bestHorse}）";
            if ($key === 'big_gap_entry' && $level >= 3 && $movement['continuous_horses'] === []) {
                $detail['capped_from'] = $level; $level = 2;
                $reason .= '。対象帯に、複勝が12分前＞9分前＞6分前と連続して短縮した馬がいないため 2 まで';
            }
            return ['value' => $level, 'basis' => self::BASIS_EXPECTED_TOP5, 'provisional' => false, 'reason_short' => null,
                    'reason' => $reason, 'detail' => $detail];
        }

        // 5着内傾向を出せないレース：対象帯への複勝支持の強さを、参考値として出します（なる 11/24 問3-2 B）
        if ($movement['comparable_horses'] === []) {
            return ['value' => null, 'basis' => null, 'provisional' => false, 'reason_short' => '時系列不足',
                    'reason' => "{$bandName}の5着内傾向（E5）を出せず、6分前とそれより前の複勝オッズもそろわないため、未算出", 'detail' => $detail];
        }
        $continuous = count($movement['continuous_horses']); $shortened = count($movement['shortened_horses']);
        $large = ($movement['max_continuous_drop_ratio'] !== null && $movement['max_continuous_drop_ratio'] >= self::PLACE_ODDS_LARGE_DROP_RATIO);
        if ($continuous >= 2 && $large) { $level = 5; }
        elseif ($continuous >= 2 || ($continuous === 1 && $large)) { $level = 4; }
        elseif ($continuous === 1) { $level = 3; }
        elseif ($shortened >= 1) { $level = 2; }
        else { $level = 1; }
        $reason = "{$bandName}の5着内傾向（E5）を出せないため、複勝オッズの動きから算出（参考）："
                . "12分前＞9分前＞6分前の連続短縮 {$continuous}頭、6分前に短縮 {$shortened}頭"
                . ($movement['max_continuous_drop_ratio'] !== null ? '、連続短縮の最大の下がり幅 ' . self::num($movement['max_continuous_drop_ratio'] * 100) . '%' : '');
        return ['value' => $level, 'basis' => self::BASIS_PLACE_ODDS_REFERENCE, 'provisional' => false, 'reason_short' => null,
                'reason' => $reason, 'detail' => $detail];
    }

    /** 対象帯の馬ごとに、複勝（下限・上限は別々に見る）の動きを確かめます */
    private static function placeOddsMovement(array $horses, array $placeOdds): array
    {
        $comparable = []; $shortened = []; $continuous = []; $maxDrop = null;
        foreach ($horses as $no) {
            foreach (['place_min', 'place_max'] as $field) {
                $s = $placeOdds[$no][$field] ?? [];
                if (!isset($s[6])) { continue; }
                $earlier = null;
                foreach ([9, 12, 15, 18, 21] as $slot) { if (isset($s[$slot])) { $earlier = $s[$slot]; break; } }
                if ($earlier === null) { continue; }
                $comparable[$no] = true;
                if ($s[6] < $earlier) { $shortened[$no] = true; }
                if (isset($s[12], $s[9]) && $s[12] > $s[9] && $s[9] > $s[6]) {
                    $continuous[$no] = true;
                    $drop = ($s[12] - $s[6]) / $s[12];
                    if ($maxDrop === null || $drop > $maxDrop) { $maxDrop = $drop; }
                }
            }
        }
        return ['comparable_horses' => array_keys($comparable), 'shortened_horses' => array_keys($shortened),
                'continuous_horses' => array_keys($continuous), 'max_continuous_drop_ratio' => $maxDrop === null ? null : round($maxDrop, 4)];
    }

    // ───────────────────────── 凍結入力の読み取り ─────────────────────────

    /** 凍結単勝の人気順で、7〜10番人気と11番人気以下の馬番を集めます */
    private static function bandHorses(array $in): array
    {
        $lower = []; $bigGap = [];
        foreach ((array) ($in['runners'] ?? []) as $r) {
            $no = $r['horse_no'] ?? null; $rank = $r['win_rank'] ?? null;
            if (!is_int($no) || !is_int($rank)) { continue; }
            if ($rank >= 7 && $rank <= 10) { $lower[] = $no; } elseif ($rank >= 11) { $bigGap[] = $no; }
        }
        sort($lower); sort($bigGap);
        return ['lower' => $lower, 'big_gap' => $bigGap];
    }

    /** 馬番 → 5着内傾向（E5）。出せない馬は入れません */
    private static function expectedTop5ByHorse(array $in): array
    {
        $out = [];
        foreach ((array) ($in['expected_v01']['rows'] ?? []) as $row) {
            $no = $row['horse_no'] ?? null; $v = $row['E5'] ?? null;
            $f = self::toFloat($v);
            if (is_int($no) && $f !== null) { $out[$no] = $f; }
        }
        return $out;
    }

    /** 馬番 → 複勝の下限／上限 → 何分前 → 値（採用済みの観測値だけ） */
    private static function placeOddsSeries(array $in): array
    {
        $out = [];
        foreach ((array) ($in['source_registry'] ?? []) as $e) {
            if (!is_array($e) || ($e['scope'] ?? null) !== 'HORSE' || ($e['source_kind'] ?? null) !== 'OBSERVED') { continue; }
            $field = $e['field'] ?? null; $slot = $e['slot_offset'] ?? null; $nos = $e['horse_nos'] ?? null; $v = $e['value'] ?? null;
            if (!in_array($field, ['place_min', 'place_max'], true) || !in_array($slot, [21, 18, 15, 12, 9, 6], true)) { continue; }
            if (!is_array($nos) || count($nos) !== 1 || !is_int($nos[0])) { continue; }
            $f = self::toFloat($v);
            if ($f === null || $f <= 0) { continue; }
            $out[$nos[0]][$field][$slot] = $f;
        }
        return $out;
    }

    /** 凍結入力の数値（整数・小数・数字の文字列・厳密な小数の入れ物）を float にします。数値でなければ null */
    private static function toFloat(mixed $v): ?float
    {
        if ($v instanceof BaganrikiDecimal) { $v = $v->raw(); }
        if (is_int($v) || is_float($v)) { return is_finite((float) $v) ? (float) $v : null; }
        if (is_string($v) && is_numeric($v)) { return (float) $v; }
        return null;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}

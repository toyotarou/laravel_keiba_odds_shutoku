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
 * @ANCHOR-20261020-PREDICTION-SNAPSHOT  凍結集合・頭数・共通JSON・hash
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 2章・3章・5章／詳細設計書 4.4・7.2
 *
 * 頭数の4つを別物として扱います（混ぜると意味が壊れます）。
 *   entry_count    正本出馬表の頭数。欠損なら null
 *   acquired_count 読み込めた対象馬の一意件数。正常出走頭数の代用にしない
 *   frozen_count   情報上限までの正式取消を除いた凍結出走集合の頭数（＝N）
 *   active_count   凍結集合から最新の累積取消を除いた頭数。集合矛盾時は null
 */
final class BaganrikiSnapshot
{
    /**
     * 凍結順位（6分前単勝の昇順、同値は馬番の小さい順）。
     * 非減少であることを確認し、矛盾は入力不正として拒否します。
     * 値は十進数文字列のまま受け取り、1万倍の整数で比べます（丸めを入れません）。
     */
    public static function frozenRank(array $win6): array
    {
        $scaled = [];
        foreach ($win6 as $num => $s) {
            // @ANCHOR-20261020-PREDICTION-DEC  桁数で弾きません（十進数の文字列のまま）
            //   【旧】$v = self::scale($s);  … 小数5桁以上を null にしていました
            $v = self::decOrNull($s);
            if ($v === null) {
                return ['ok' => false, 'code' => 'FREEZE_ODDS_MISSING',
                        'reason' => '6分前の単勝が読めない馬がいる（馬番 ' . $num . '）',
                        'rank' => []];
            }
            $scaled[(int) $num] = $v;
        }
        if (empty($scaled)) {
            return ['ok' => false, 'code' => 'FREEZE_ODDS_MISSING',
                    'reason' => '6分前の単勝が1頭も無い', 'rank' => []];
        }
        $nums = array_keys($scaled);
        usort($nums, static function ($a, $b) use ($scaled) {
            // @ANCHOR-20261020-PREDICTION-DEC  十進数の文字列どうしを桁で比べます
            $c = self::cmpDec($scaled[$a], $scaled[$b]);
            if ($c !== 0) { return $c; }
            return $a <=> $b;
        });
        $rank = []; $k = 1; $prev = null;
        foreach ($nums as $n) {
            if ($prev !== null && self::cmpDec($scaled[$n], $prev) < 0) {
                return ['ok' => false, 'code' => 'FREEZE_ODDS_MISSING',
                        'reason' => '凍結順位が非減少になっていない（馬番 ' . $n . '）', 'rank' => []];
            }
            $prev = $scaled[$n];
            $rank[$n] = $k++;
        }
        return ['ok' => true, 'code' => null, 'reason' => null, 'rank' => $rank];
    }

    /**
     * @ANCHOR-20261020-PREDICTION-DEC  正の十進数として読めるときだけ、その文字列を返します
     *
     *   桁数の上限を決めません。前後の空白と、先頭の余分な0だけ整えます。
     *   <b>値は作りません。</b>読めたかどうかと、比べるための形だけを返します。
     */
    public static function decOrNull($v): ?string
    {
        if ($v === null || is_bool($v)) { return null; }
        $s = trim((string) $v);
        if (preg_match('/^(\\d{1,128})(?:\\.(\\d{1,128}))?$/', $s, $m) !== 1) {
            return null;
        }
        $int  = ltrim($m[1], '0');
        $frac = rtrim($m[2] ?? '', '0');
        if ($int === '' && $frac === '') { return null; }   // 0 は受け取りません
        return ($int === '' ? '0' : $int) . ($frac === '' ? '' : '.' . $frac);
    }

    /**
     * @ANCHOR-20261020-PREDICTION-DEC  十進数の文字列どうしを、丸めずに比べます
     *   整数部は桁数が多いほうが大きい。同じ桁数なら文字として比べられます。
     *   小数部は短いほうを0で埋めてから比べます。float を通しません。
     */
    public static function cmpDec(string $a, string $b): int
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
        $ai = ltrim($ai, '0'); $bi = ltrim($bi, '0');
        if (strlen($ai) !== strlen($bi)) { return (strlen($ai) < strlen($bi)) ? -1 : 1; }
        if ($ai !== $bi) { return ($ai < $bi) ? -1 : 1; }
        $n = max(strlen($af), strlen($bf));
        $af = str_pad($af, $n, '0');
        $bf = str_pad($bf, $n, '0');
        if ($af === $bf) { return 0; }
        return ($af < $bf) ? -1 : 1;
    }

}

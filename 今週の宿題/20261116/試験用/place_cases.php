<?php
// 20261116 B案（サーバが凍結入力の複勝オッズを直接確かめる）の固定ケース。保存しない・AIへ送らない・DBも使わない
spl_autoload_register(function ($c) { $p = strrpos($c, '\\'); $ns = $p === false ? '' : substr($c, 0, $p); $n = $p === false ? $c : substr($c, $p + 1); eval(($ns ? "namespace $ns; " : '') . "class $n {}"); });
require '/home/claude/ms/AiController.php';
use App\Http\Controllers\Api\BaganrikiMetricsSelection as MS;

const RACE = '20261121-5-05-5-11';
const FREEZE = '2026-11-21T15:34:00+09:00';
function e(int $no, string $f, int $slot, $v, array $o = []): array {
    $ref = 'h' . $no . '_' . $f . '_' . $slot . ($o['suffix'] ?? '');
    return [$ref, array_replace(['unit' => 'odds', 'field' => $f, 'scope' => 'HORSE', 'value' => $v, 'race_id' => RACE, 'horse_nos' => [$no],
        'source_ref' => $ref, 'slot_offset' => $slot, 'source_kind' => 'OBSERVED', 'available_at' => '2026-11-21T15:30:00+09:00'], $o['e'] ?? [])];
}
function reg(array $rows): array { $r = []; foreach ($rows as [$k, $v]) { $r[$k] = $v; } return $r; }
function inp(array $rows): array { return ['race_id' => RACE, 'input_freeze_at' => FREEZE, 'selection_enabled' => true, 'source_registry' => reg($rows)]; }
// AIの market 根拠は単勝だけを引用している（複勝は引用していない）
$winOnly = ['eligibility' => 'ELIGIBLE', 'evidence' => [['evidence_id' => 'e6_market', 'family' => 'market', 'source_refs' => ['h6_win_odds_12', 'h6_win_odds_6']]]];
$rh = ['1st' => [6 => $winOnly], '2nd' => [6 => $winOnly]];

$cases = [
    ['PC-01', 'B：AIの複勝引用なし・6分前が9分前より短い → 成立', 'B', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 6, 4.0)], $rh, true],
    ['PC-02', 'B：単勝だけ短縮・複勝は横ばい → 不成立', 'B', [e(6, 'win_odds', 9, 12.0), e(6, 'win_odds', 6, 9.0), e(6, 'place_min', 9, 3.0), e(6, 'place_min', 6, 3.0)], $rh, false],
    ['PC-03', 'B：別の馬の時点と混ぜない（6番の6分前＜7番の9分前）→ 不成立', 'B', [e(6, 'place_min', 6, 4.0), e(7, 'place_min', 9, 5.0)], $rh, false],
    ['PC-04', 'B：別の指標と混ぜない（min6＜max9 でも、同じ指標では上昇）→ 不成立', 'B', [e(6, 'place_min', 9, 3.9), e(6, 'place_min', 6, 4.0), e(6, 'place_max', 9, 5.8), e(6, 'place_max', 6, 6.0)], $rh, false],
    ['PC-05', 'B：6分前が欠けている → 不成立', 'B', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 12, 5.0)], $rh, false],
    ['PC-06', 'B：横ばい（6分前＝9分前）→ 不成立', 'B', [e(6, 'place_min', 9, 4.0), e(6, 'place_min', 6, 4.0)], $rh, false],
    ['PC-07', 'B：直近の反転（12→9は短縮、9→6は上昇）。古い12分前へ飛ばさない → 不成立', 'B', [e(6, 'place_min', 12, 6.0), e(6, 'place_min', 9, 5.0), e(6, 'place_min', 6, 5.2)], $rh, false],
    ['PC-08', 'B：9分前が欠けていれば次に新しい12分前と比べる → 成立', 'B', [e(6, 'place_max', 12, 6.0), e(6, 'place_max', 6, 5.0)], $rh, true],
    ['PC-09', 'B：6分前が凍結の情報上限より後に利用可能 → 使わない → 不成立', 'B', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 6, 4.0, ['e' => ['available_at' => '2026-11-21T15:34:30+09:00']])], $rh, false],
    ['PC-10', 'B：6分前の値が不正 → 不成立', 'B', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 6, 'abc')], $rh, false],
    ['PC-11', 'B：9分前の値が不正なら、9分前は無いものとして12分前と比べる（12＝4.2＞6＝4.0）→ 成立', 'B', [e(6, 'place_min', 12, 4.2), e(6, 'place_min', 9, null), e(6, 'place_min', 6, 4.0)], $rh, true],
    ['PC-12', 'B：別レースの資料 → 不成立', 'B', [e(6, 'place_min', 9, 4.5, ['e' => ['race_id' => '20261121-5-05-5-10']]), e(6, 'place_min', 6, 4.0)], $rh, false],
    ['PC-13', 'B：OBSERVED でない資料（計算値）→ 不成立', 'B', [e(6, 'place_min', 9, 4.5, ['e' => ['source_kind' => 'COMPUTED']]), e(6, 'place_min', 6, 4.0)], $rh, false],
    ['PC-14', 'B：同じ時点が二重にある → その時点は使わない → 不成立', 'B', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 6, 4.0), e(6, 'place_min', 6, 3.0, ['suffix' => 'x'])], $rh, false],
    ['PC-15', 'B：次点の馬がどちらの役割でも ELIGIBLE でない → 不成立', 'B', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 6, 4.0)], ['1st' => [6 => ['eligibility' => 'UNVERIFIED']], '2nd' => []], false],
    ['PC-16', 'D：12＞9＞6 が厳密に成立 → 成立', 'D', [e(6, 'place_max', 12, 8.0), e(6, 'place_max', 9, 7.0), e(6, 'place_max', 6, 6.0)], $rh, true],
    ['PC-17', 'D：9分前＝6分前（横ばい）→ 不成立', 'D', [e(6, 'place_max', 12, 8.0), e(6, 'place_max', 9, 7.0), e(6, 'place_max', 6, 7.0)], $rh, false],
    ['PC-18', 'D：12分前が欠けている → 不成立', 'D', [e(6, 'place_max', 9, 7.0), e(6, 'place_max', 6, 6.0)], $rh, false],
    ['PC-19', 'D：単勝だけ12＞9＞6 → 不成立', 'D', [e(6, 'win_odds', 12, 30.0), e(6, 'win_odds', 9, 25.0), e(6, 'win_odds', 6, 20.0)], $rh, false],
    ['PC-20', 'D：指標を混ぜない（min12＞max9＞min6 でも、それぞれの指標では不成立）→ 不成立', 'D', [e(6, 'place_min', 12, 9.0), e(6, 'place_max', 9, 8.0), e(6, 'place_min', 6, 7.0)], $rh, false],
];
$ok = 0; $ng = 0;
foreach ($cases as [$id, $desc, $dest, $rows, $horses, $expect]) {
    $got = MS::serverPlaceCheck(inp($rows), $horses, 6, $dest);
    $pass = ($got !== null) === $expect;
    $pass ? $ok++ : $ng++;
    printf("%-6s %s %s%s\n", $id, $pass ? 'OK' : '★違う', $desc, $got ? ' 根拠 ' . json_encode(['field' => $got['field'], 'refs' => $got['refs']]) : '');
}

// 通しの確認：公開の処理と同じ apply を通して、AIの複勝引用が無くても実際に入れ替わるか
$metrics = ['response_hash' => 'x', 'status' => ['wave_level' => 'VALID', 'lower_entry' => 'VALID', 'big_gap_entry' => 'VALID'],
    'values' => ['wave_level' => 5, 'lower_entry' => 5, 'big_gap_entry' => 1]];
$sel = ['band_order' => ['A' => [1, 2, 3, 8], 'B' => [4, 6, 9], 'D' => [7, 10]], 'public_candidates' => [1, 2, 3, 4, 7], 'fill' => [], 'shortfalls' => []];
$quota = ['A' => 3, 'B' => 1, 'D' => 1];
$roles = [['ai_role' => '1st', 'expected_reviews' => []], ['ai_role' => '2nd', 'expected_reviews' => []]];
$runs = [
    ['AP-01', '複勝が短縮・AIは単勝だけ引用 → A帯の3番を外し、B帯の次点6番を入れる', [e(6, 'place_min', 9, 4.5), e(6, 'place_min', 6, 4.0)], 'APPLIED', [1, 2, 4, 6, 7]],
    ['AP-02', '単勝だけ短縮・複勝は横ばい → 入れ替えない', [e(6, 'win_odds', 9, 12.0), e(6, 'win_odds', 6, 9.0), e(6, 'place_min', 9, 3.0), e(6, 'place_min', 6, 3.0)], 'KEEP', [1, 2, 3, 4, 7]],
    ['AP-03', '直近の反転 → 入れ替えない（次点を飛ばして9番を探さない）', [e(6, 'place_min', 12, 6.0), e(6, 'place_min', 9, 5.0), e(6, 'place_min', 6, 5.2), e(9, 'place_min', 9, 9.0), e(9, 'place_min', 6, 8.0)], 'KEEP', [1, 2, 3, 4, 7]],
];
foreach ($runs as [$id, $desc, $rows, $status, $after]) {
    $d = MS::apply(inp($rows), $roles, ['1st' => [6 => $winOnly, 9 => $winOnly], '2nd' => [6 => $winOnly, 9 => $winOnly]], $sel, $quota, $metrics);
    $pass = $d['status'] === $status && $d['after'] === $after && ($status !== 'APPLIED' || ($d['server_place_check']['horse_no'] ?? null) === 6);
    $pass ? $ok++ : $ng++;
    printf("%-6s %s %s → %s %s 後[%s]%s\n", $id, $pass ? 'OK' : '★違う', $desc, $d['status'], $d['reason'] ?? '', implode(',', $d['after']),
        $d['server_place_check'] ? ' 記録 ' . json_encode($d['server_place_check'], JSON_UNESCAPED_UNICODE) : '');
}
echo "合計 OK $ok / 違う $ng\n";

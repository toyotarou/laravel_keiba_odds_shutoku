<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * @ANCHOR-20261110-PREDICTION-GAP-CLAIM  断層（gap）の根拠は、その馬が上側にいる「比2.00以上」の固定隣接ペアがあるときだけ認めます（なる11/10 3章）
 *   よっしーの仕様（prompt 本文）：「断層は6分前単勝順で固定した隣接ペア…W=下側単勝÷上側単勝、P=同じペア下側複勝最小÷上側複勝最小…2.00以上の位置…を確認」。
 *   11/10 京都6R の 2nd は、10→17 の単勝比1.035 を「主断層」、17→15 の1.068 を「次断層」として根拠にし、検査を通っていました。
 *   gap の根拠が引用した固定隣接ペアの比（g{上側}_{下側}_{win|place}_ratio_{時点}）のうち、
 *   この馬が上側で、値が2.00以上のものが1つも無ければ、その根拠を認めません（EXPECTED_FALSE_GAP）。
 *   外した根拠に頼っていた点数（H3 など）・順位・候補は、組み直し（salvageEvidence）で残った根拠だけから作り直します。
 */
final class BaganrikiGapClaim
{
    public static function verify(array $reg,callable $value,array $item,int $no):void
    {
        if (($item['family']??null)!=='gap') return;
        foreach ((array)($item['source_refs']??[]) as $ref) {
            $s=$reg[$ref]??null;
            if (!is_array($s) || ($s['scope']??null)!=='PAIR' || !in_array($s['field']??null,['fixed_gap_win_ratio','fixed_gap_place_ratio'],true)) continue;
            if ((($s['horse_nos']??[])[0]??null)!==$no) continue;
            $token=$value($ref);
            if (($token[0]??null)==='number' && BaganrikiOutputSchema::decimals((string)$token[1],'2')>=0) return;
        }
        throw new \InvalidArgumentException('EXPECTED_FALSE_GAP');
    }
}

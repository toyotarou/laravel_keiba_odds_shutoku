<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** Read the outer lines only. Marker text inside a JSON string remains ordinary data. */
final class BaganrikiResponseFrame
{
    /** A saved response keeps the frame grammar used when its request was dispatched. */
    public static function supportsVersion(mixed $version):bool
    {
        return in_array($version,[
            'baganriki-v041-r12-2-w2-e35-r10-20261101',
            'baganriki-v041-r12-2-w2-e35-r11-20261101',
            'baganriki-v041-r12-2-w2-e35-r12-20261104',
            'baganriki-v041-r12-2-w2-e35-r13-20261104',
            'baganriki-v041-r12-2-w2-e35-r14-20261104',
            BaganrikiCandidateSelection::VERSION,
            BaganrikiCandidateSelection::VERSION_E5,   // @ANCHOR-20261125-E5-SCORE8
        ],true);
    }

    private static function fail(string $code,string $reason):array
    {
        return ['ok'=>false,'code'=>'AI_FAILURE','reason'=>$reason,'json'=>null,'w2_error'=>$code];
    }

    /** Same success fields as the submitted extractor, including the untouched strict type map. */
    public static function extract(string $raw):array
    {
        if (strlen($raw)>4194400) return self::fail('EXPECTED_RESPONSE_FRAME','EXPECTED_RESPONSE_SIZE');
        // Allow complete trailing LF/CRLF line endings, never strip other control bytes.
        $end=strlen($raw);
        while ($end>0 && $raw[$end-1]==="\n") {
            --$end;
            if ($end>0 && $raw[$end-1]==="\r") --$end;
        }
        $framed=substr($raw,0,$end);
        // @ANCHOR-20261122-PREDICTION-FRAME-MISSING  AIが始まり／終わりの目印の行を書き忘れたとき（11/21 は1stの24通中9通、11/22 は2通）、
        //   中身が { で始まり } で終わる返事だけを、目印で挟んだものとして読みます（```json … ``` の囲みも外します）。
        //   目印が片方だけある・目印の外に文章がある返事は、今までどおり EXPECTED_OUTER_LINES で使いません。中身の検査は同じです。
        $bareBody=self::bareJsonBody($framed);
        if ($bareBody!==null) $framed=BaganrikiResponse::BEGIN."\n".$bareBody."\n".BaganrikiResponse::END;
        $first=strpos($framed,"\n");$last=strrpos($framed,"\n");
        if ($first===false || $last===false || $last<=$first)
            return self::fail('EXPECTED_RESPONSE_FRAME','EXPECTED_OUTER_LINES');
        $header=substr($framed,0,$first);
        if (str_ends_with($header,"\r")) $header=substr($header,0,-1);
        // @ANCHOR-20261109-PREDICTION-FRAME-SHORT  AIが目印を短く「BEGIN」「END」とだけ書いたときも、同じ目印として読みます（中身の検査は同じ。11/9 非公開試験で発生）
        $footer=substr($framed,$last+1);
        if (str_ends_with($footer,"\r")) $footer=substr($footer,0,-1);
        if (!in_array($header,[BaganrikiResponse::BEGIN,'BEGIN'],true) || !in_array($footer,[BaganrikiResponse::END,'END'],true))
            return self::fail('EXPECTED_RESPONSE_FRAME','EXPECTED_OUTER_LINES');
        $bodyEnd=$last;
        if ($bodyEnd>$first+1 && $framed[$bodyEnd-1]==="\r") --$bodyEnd;
        $body=substr($framed,$first+1,$bodyEnd-$first-1);
        // No generic trim(): NUL / vertical tab / non-JSON bytes must be rejected by strict().
        $parsed=BaganrikiJson::strict($body);
        // @ANCHOR-20261124-PREDICTION-EVIDENCE-BRACE  根拠（evidence）のかたまりの始まりの { が1つ抜けた返事（なる 11/24 A）
        //   例 …"interpretation":"…"},"evidence_id":"e12_wp",… （正しくは },{"evidence_id"）。11/14〜23 に5件（11/23 福島10R の 2nd など）。
        //   ・ふつうに読めなかったときだけ試します。読める返事には何もしません。
        //   ・文字列の外で、} , のすぐ後に "evidence_id" のキーが続く所だけを探し、その , の直後に { を1文字入れます（入れる位置は1つに決まる）。
        //     正しい返事では、"evidence_id" の直前は必ず { なので、この並びは出てきません。
        //   ・入れた後に、ふつうの厳密な読み取りで読めたときだけ使います。読めなければ何もしなかったことにして、今までどおりの直しへ進みます。
        //   ・馬番・点数・順位などの中身は変えません。原文はそのまま保存し、入れた位置は記録に残します。この後の検査と期限は今までどおりです。
        //   ・重複キー（JSON_DUPLICATE_KEY）の直しより先に試します（{ が抜けると2つの根拠が1つにつながり、重複キーに見えるため。11/22 京都6R）。
        //   ・【なる 11/24 13:06 で範囲を狭めた】使うのは「2nd の返事で、抜けが1か所だけ（{ を1文字入れるだけ）」のときに限ります。
        //     2か所以上の抜けや、重複キーの直しとの組み合わせ（11/22 京都6R 型）は、別に決めるまで使いません（今までどおり捨てます）。
        //     見つかった数は evidence_braces_found に残し、週末の集計で「1か所」と「2か所以上」を分けて数えます。
        // //   ・{ を入れた後に、同じ欄を2回書いた所（重複キー）が残るときだけ、今までの重複キーの直し（2回目以降を外す）を続けて使います（11/22 京都6R の 2nd）。   // 旧（13:03〜13:06。なるの合意の範囲外）
        $evidenceBraces=[];$evidenceDupRemoved=[];$evidenceFound=0;
        if (!$parsed['ok']) {
            $at=self::missingEvidenceBraces($body);
            $evidenceFound=count($at);
            if (count($at)===1) {
                $fixed=self::insertBraces($body,$at);
                $p0=BaganrikiJson::strict($fixed);
                // if (!$p0['ok'] && (string)$p0['code']==='JSON_DUPLICATE_KEY') {
                //     $d0=[];$f0=self::dedupeKeys($fixed,$d0);
                //     if ($f0!==null) { $p0=BaganrikiJson::strict($f0); if ($p0['ok']) $evidenceDupRemoved=$d0; }
                // }   // 旧（13:03〜13:06）：重複キーの直しとの組み合わせ。なるの合意の範囲外なので使わない
                if ($p0['ok'] && is_array($p0['value']) && (($p0['value']['ai_role'] ?? null) === '2nd')) { $parsed=$p0; $evidenceBraces=$at; }
            }
        }
        // @ANCHOR-20261109-PREDICTION-DUPKEY  AIが同じ欄を2回書いたとき（JSON_DUPLICATE_KEY）は、2回目以降だけを外して読み直します（先に書いた値を使う。11/9 非公開試験で発生）
        $dupRemoved=[];
        if (!$parsed['ok'] && (string)$parsed['code']==='JSON_DUPLICATE_KEY') {
            $fixed=self::dedupeKeys($body,$dupRemoved);
            if ($fixed!==null) $parsed=BaganrikiJson::strict($fixed);
        }
        // @ANCHOR-20261110-PREDICTION-STRAYQUOTE  AIの、決まった形の書き損じ3種だけを直して読み直します。読めなかったときだけ試し、直しても読めなければ今までどおり使いません。
        //   ① 「},」のあとの余計な " （例 },"{"evidence_id"…）……11/10 非公開試験で2nd に2回
        //   ② race_metrics の中で、指標1つ分の {…} のあとの余計な } （例 …"}},"lower_entry":{…）……11/10 京都1R・京都4R の 1st
        //   ③ score_evidence の閉じ忘れ（"V5":{…} のあとに } が無いまま ,"score_status" が続く）……11/10 東京6R の 2nd（全頭）
        //   どれも、正しい返事には当てはまらない並びだけを直します（11/10 の正しい返事8通で、当てはまる所が0か所なのを確かめました）
        $strayRemoved=0;$braceRemoved=0;$closeAdded=0;
        if (!$parsed['ok'] && (string)$parsed['code']!=='JSON_DUPLICATE_KEY') {
            $fixed=preg_replace('/([\]}]),"\{"/','$1,{"',$body,-1,$strayRemoved);
            if (is_string($fixed)) $fixed=preg_replace('/\}\},"(lower_entry|big_gap_entry)":\{/','},"$1":{',$fixed,-1,$braceRemoved);
            if (is_string($fixed)) $fixed=preg_replace('/("V5":\{"source_refs":\[[^\]]*\],"reason":"(?:[^"\\\\]|\\\\.)*"\}),"score_status"/u','$1},"score_status"',$fixed,-1,$closeAdded);
            if (is_string($fixed) && $strayRemoved+$braceRemoved+$closeAdded>0) {
                $p2=BaganrikiJson::strict($fixed);
                if (!$p2['ok'] && (string)$p2['code']==='JSON_DUPLICATE_KEY') {
                    $fixed2=self::dedupeKeys($fixed,$dupRemoved);
                    if ($fixed2!==null) $p2=BaganrikiJson::strict($fixed2);
                }
                if ($p2['ok']) $parsed=$p2; else { $strayRemoved=0;$braceRemoved=0;$closeAdded=0; }
            }
        }
        // if (!$parsed['ok']) return self::fail('EXPECTED_RESPONSE_JSON',(string)$parsed['code']);   // 旧
        if (!$parsed['ok']) return self::fail('EXPECTED_RESPONSE_JSON',(string)$parsed['code'])+['evidence_braces_found'=>$evidenceFound];   // @ANCHOR-20261124-PREDICTION-EVIDENCE-BRACE  集計用
        if (!is_array($parsed['value']) || ($parsed['map']['kind'][BaganrikiJson::pathKey([])]??null)!=='obj')
            return self::fail('EXPECTED_RESPONSE_JSON','EXPECTED_OBJECT_REQUIRED');
        return ['ok'=>true,'code'=>null,'reason'=>null,'json'=>$parsed['value'],
            'number_paths'=>(array)($parsed['number_paths']??[]),'map'=>(array)$parsed['map'],'duplicate_keys_removed'=>array_merge($evidenceDupRemoved,$dupRemoved),'stray_quotes_removed'=>$strayRemoved,'metric_braces_removed'=>$braceRemoved,'score_evidence_closed'=>$closeAdded,'frame_missing'=>$bareBody!==null,
            'evidence_braces_added'=>count($evidenceBraces),'evidence_brace_positions'=>$evidenceBraces,'evidence_braces_found'=>$evidenceFound];   // @ANCHOR-20261124-PREDICTION-EVIDENCE-BRACE
    }

    /**
     * @ANCHOR-20261122-PREDICTION-FRAME-MISSING  目印の行が無い返事の、JSON の部分を返します（当てはまらなければ null）
     *   ① 前後の空白・改行を外す（NUL などの制御文字は外さず、strict() で弾きます）
     *   ② ```json（または ```）の行で始まり ``` の行で終わるなら、その2行を外す
     *   ③ 残りが { で始まり } で終わるときだけ返す
     */
    private static function bareJsonBody(string $framed):?string
    {
        $t=trim($framed," \t\r\n");
        if (str_starts_with($t,'```')) {
            $nl=strpos($t,"\n");
            if ($nl===false || preg_match('/^```(?:json)?[ \t]*\r?$/i',substr($t,0,$nl))!==1) return null;
            if (!str_ends_with($t,'```')) return null;
            $t=trim(substr($t,$nl+1,-3)," \t\r\n");
        }
        if ($t==='' || $t[0]!=='{' || !str_ends_with($t,'}')) return null;
        return $t;
    }

    /**
     * @ANCHOR-20261124-PREDICTION-EVIDENCE-BRACE  文字列の外にある「} , "evidence_id" :」の並びを探し、{ を入れる位置（, の直後のバイト位置）を返します
     *   } と , と "evidence_id" と : の間の空白は許します。文字列の中（理由の文章など）は見ません。
     */
    public static function missingEvidenceBraces(string $b):array
    {
        $at=[];$n=strlen($b);$in=false;
        for ($i=0;$i<$n;$i++) {
            $c=$b[$i];
            if ($in) { if ($c==='\\') { $i++; continue; } if ($c==='"') $in=false; continue; }
            if ($c==='"') { $in=true; continue; }
            if ($c!=='}') continue;
            $j=$i+1; while ($j<$n && strpos(" \t\r\n",$b[$j])!==false) $j++;
            if ($j>=$n || $b[$j]!==',') continue;
            $comma=$j; $j++; while ($j<$n && strpos(" \t\r\n",$b[$j])!==false) $j++;
            if (substr($b,$j,13)!=='"evidence_id"') continue;
            $k=$j+13; while ($k<$n && strpos(" \t\r\n",$b[$k])!==false) $k++;
            if ($k>=$n || $b[$k]!==':') continue;
            $at[]=$comma+1;
        }
        return $at;
    }

    /**
     * @ANCHOR-20261124-PREDICTION-EVIDENCE-BRACE  根拠の始まりの { が抜けた所に { を入れた本文を返します（当てはまる所が無ければ null）
     *   返事の「投げ出し」判定（AiController::replyQuality）など、検査の前に返事の中身の量だけを数える所でも、同じ直しで読めるようにするためです。
     */
    public static function withEvidenceBraces(string $b):?string
    {
        $at=self::missingEvidenceBraces($b);
        // return ($at===[]) ? null : self::insertBraces($b,$at);   // 旧（13:03〜13:06）
        return (count($at)===1) ? self::insertBraces($b,$at) : null;   // 検査と同じく、抜けが1か所のときだけ（なる 11/24 13:06）
    }

    /** 決めた位置（昇順）に { を1文字ずつ入れた本文を返します */
    private static function insertBraces(string $b,array $at):string
    {
        $out='';$pos=0;
        foreach ($at as $p) { $out.=substr($b,$pos,$p-$pos).'{'; $pos=$p; }
        return $out.substr($b,$pos);
    }

    /** 同じ階層の重複キーを、2回目以降だけ取り除いた本文を返す（先の値を残す）。読めなければ null */
    public static function dedupeKeys(string $b, array &$removed): ?string
    {
        $cut=[];$i=0;$n=strlen($b);
        $ws=static function() use (&$i,$b,$n){ while ($i<$n && strpos(" \t\r\n",$b[$i])!==false) $i++; };
        $str=static function() use (&$i,$b,$n):?string {
            if ($i>=$n || $b[$i]!=='"') return null; $st=$i; $i++;
            while ($i<$n) { $c=$b[$i]; if ($c==='\\') { $i+=2; continue; } if ($c==='"') { $i++; return substr($b,$st,$i-$st); } $i++; }
            return null;
        };
        $val=null;
        $val=static function() use (&$val,&$i,$b,$n,$ws,$str,&$cut,&$removed):bool {
            $ws(); if ($i>=$n) return false; $c=$b[$i];
            if ($c==='"') return $str()!==null;
            if ($c==='{') {
                $i++; $seen=[]; $ws(); if ($i<$n && $b[$i]==='}') { $i++; return true; }
                $first=true;
                for (;;) {
                    $memberStart=$i; // 直前の読点の位置は prevComma
                    $ws(); $k=$str(); if ($k===null) return false;
                    $ws(); if ($i>=$n || $b[$i]!==':') return false; $i++;
                    if (!$val()) return false;
                    $end=$i;
                    if (isset($seen[$k])) { $cut[]=[$prevComma,$end]; $removed[]=json_decode($k); }
                    $seen[$k]=true;
                    $ws(); if ($i>=$n) return false;
                    if ($b[$i]===',') { $prevComma=$i; $i++; continue; }
                    if ($b[$i]==='}') { $i++; return true; }
                    return false;
                }
            }
            if ($c==='[') {
                $i++; $ws(); if ($i<$n && $b[$i]===']') { $i++; return true; }
                for (;;) { if (!$val()) return false; $ws(); if ($i>=$n) return false;
                    if ($b[$i]===',') { $i++; continue; } if ($b[$i]===']') { $i++; return true; } return false; }
            }
            while ($i<$n && strpos(",}] \t\r\n",$b[$i])===false) $i++;
            return true;
        };
        $prevComma=0;
        if (!$val()) return null;
        if (!$cut) return null;
        $out='';$pos=0;
        foreach ($cut as [$a,$z]) { $out.=substr($b,$pos,$a-$pos); $pos=$z; }
        return $out.substr($b,$pos);
    }
}

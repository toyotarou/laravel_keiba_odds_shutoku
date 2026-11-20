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
        if (!$parsed['ok']) return self::fail('EXPECTED_RESPONSE_JSON',(string)$parsed['code']);
        if (!is_array($parsed['value']) || ($parsed['map']['kind'][BaganrikiJson::pathKey([])]??null)!=='obj')
            return self::fail('EXPECTED_RESPONSE_JSON','EXPECTED_OBJECT_REQUIRED');
        return ['ok'=>true,'code'=>null,'reason'=>null,'json'=>$parsed['value'],
            'number_paths'=>(array)($parsed['number_paths']??[]),'map'=>(array)$parsed['map'],'duplicate_keys_removed'=>$dupRemoved,'stray_quotes_removed'=>$strayRemoved,'metric_braces_removed'=>$braceRemoved,'score_evidence_closed'=>$closeAdded];
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

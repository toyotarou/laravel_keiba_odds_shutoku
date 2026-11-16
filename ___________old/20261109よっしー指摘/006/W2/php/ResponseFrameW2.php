<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiResponse;

/** Read the outer lines only. Marker text inside a JSON string remains ordinary data. */
final class ResponseFrameW2
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
            CandidateSelectionW2::VERSION,
        ],true);
    }

    private static function fail(string $code,string $reason):array
    {
        return ['ok'=>false,'code'=>'AI_FAILURE','reason'=>$reason,'json'=>null,'w2_error'=>$code];
    }

    /** Same success fields as the submitted extractor, including the untouched strict type map. */
    public static function extract(string $raw):array
    {
        if (strlen($raw)>4194400) return self::fail('W2_RESPONSE_FRAME','W10_RESPONSE_SIZE');
        // Allow complete trailing LF/CRLF line endings, never strip other control bytes.
        $end=strlen($raw);
        while ($end>0 && $raw[$end-1]==="\n") {
            --$end;
            if ($end>0 && $raw[$end-1]==="\r") --$end;
        }
        $framed=substr($raw,0,$end);
        $first=strpos($framed,"\n");$last=strrpos($framed,"\n");
        if ($first===false || $last===false || $last<=$first)
            return self::fail('W2_RESPONSE_FRAME','W10_OUTER_LINES');
        $header=substr($framed,0,$first);
        if (str_ends_with($header,"\r")) $header=substr($header,0,-1);
        if ($header!==BaganrikiResponse::BEGIN || substr($framed,$last+1)!==BaganrikiResponse::END)
            return self::fail('W2_RESPONSE_FRAME','W10_OUTER_LINES');
        $bodyEnd=$last;
        if ($bodyEnd>$first+1 && $framed[$bodyEnd-1]==="\r") --$bodyEnd;
        $body=substr($framed,$first+1,$bodyEnd-$first-1);
        // No generic trim(): NUL / vertical tab / non-JSON bytes must be rejected by strict().
        $parsed=BaganrikiJson::strict($body);
        if (!$parsed['ok']) return self::fail('W2_RESPONSE_JSON',(string)$parsed['code']);
        if (!is_array($parsed['value']) || ($parsed['map']['kind'][BaganrikiJson::pathKey([])]??null)!=='obj')
            return self::fail('W2_RESPONSE_JSON','W10_OBJECT_REQUIRED');
        return ['ok'=>true,'code'=>null,'reason'=>null,'json'=>$parsed['value'],
            'number_paths'=>(array)($parsed['number_paths']??[]),'map'=>(array)$parsed['map']];
    }
}

<?php
declare(strict_types=1);
namespace Baganriki;
/** Preserve decimal/exponent tokens as text. Ordinary integer metadata stays integer. */
final class ExactJson
{
    public static function decode(string $json): mixed
    {
        // Scan string boundaries without a repeated regex group: a megabyte of
        // escaped controls can exceed PCRE's stack even in otherwise valid JSON.
        $parts=[];$offset=0;$length=strlen($json);
        while ($offset<$length) {
            $quote=strpos($json,'"',$offset);
            $outside=substr($json,$offset,($quote===false ? $length : $quote)-$offset);
            $converted=preg_replace_callback('/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
                static function(array $match): string {
                    return strpbrk($match[0],'.eE')===false ? $match[0] : '"'.$match[0].'"';
                },$outside);
            if ($converted===null) throw new \RuntimeException('Cannot decode exact JSON numbers');
            $parts[]=$converted;
            if ($quote===false) break;
            $end=$quote+1;
            while (true) {
                $end=strpos($json,'"',$end);
                if ($end===false) throw new \JsonException('Unterminated JSON string');
                $slashes=0;
                for ($i=$end-1;$i>$quote && $json[$i]==='\\';--$i) ++$slashes;
                if ($slashes%2===0) break;
                ++$end;
            }
            $parts[]=substr($json,$quote,$end-$quote+1);$offset=$end+1;
        }
        return json_decode(implode('',$parts),true,512,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}

<?php
declare(strict_types=1);
namespace Baganriki;
/** Same supported RFC3339 subset as Python: no silent submicrosecond truncation. */
final class Timestamp
{
    public static function parse(string $text): \DateTimeImmutable
    {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:[Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/', $text)
            || substr($text,0,4)==='0000') {
            throw new \InvalidArgumentException('Timestamp requires RFC3339, timezone and at most 6 fractional digits');
        }
        try {$value=new \DateTimeImmutable(strtoupper($text));}
        catch (\Exception $ex) {throw new \InvalidArgumentException('Invalid timestamp',0,$ex);}
        $errors=\DateTimeImmutable::getLastErrors();
        if ($errors!==false && ($errors['warning_count']>0 || $errors['error_count']>0)) {
            throw new \InvalidArgumentException('Invalid timestamp calendar or clock');
        }
        return $value;
    }
}

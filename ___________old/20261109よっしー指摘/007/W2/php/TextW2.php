<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

/** Shared by structural validation and local recovery. Preserve the original text. */
final class TextW2
{
    // Unicode White_Space plus controls, format characters and visually empty fillers.
    public const PATTERN='[^\x{0000}-\x{0020}\x{007F}-\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}-\x{1160}\x{1680}\x{17B4}-\x{17B5}\x{180B}-\x{180F}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}\x{2800}\x{3000}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF0}-\x{FFF8}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0000}-\x{E0FFF}]';

    public static function visible(mixed $text):bool
    {
        return is_string($text) && preg_match('~'.self::PATTERN.'~u',$text)===1;
    }
}

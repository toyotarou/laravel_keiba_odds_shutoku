<?php
declare(strict_types=1);
namespace App\Support\Baganriki;

/** Exact JSON number. No binary floating-point conversion. */
final class Decimal implements \JsonSerializable
{
    public readonly string $raw;
    public readonly string $decimal;
    public readonly bool $integerSyntax;

    public function __construct(string $raw)
    {
        if (!preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D', $raw, $m)) {
            throw new \InvalidArgumentException('Invalid JSON number');
        }
        $int = $m[2]; $frac = $m[3] ?? ''; $expText = $m[4] ?? '0';
        if (strlen(ltrim($expText, '+-0')) > 6) {
            throw new \InvalidArgumentException('Exponent outside contract');
        }
        $exp = (int) $expText;
        $digits = $int . $frac; $sig = ltrim($digits, '0');
        if (strlen($sig) > 128) throw new \InvalidArgumentException('More than 128 significant digits');
        if ($sig !== '') {
            $adj = $exp + strlen($int) - 1 - (strlen($digits) - strlen($sig));
            if ($adj < -308 || $adj > 308) throw new \InvalidArgumentException('Adjusted exponent outside contract');
        }
        $this->raw = $raw;
        $this->integerSyntax = strpbrk($raw, '.eE') === false;
        if ($sig === '') { $this->decimal = '0'; return; }
        $point = strlen($int) + $exp;
        if ($point <= 0) $value = '0.' . str_repeat('0', -$point) . $digits;
        elseif ($point >= strlen($digits)) $value = $digits . str_repeat('0', $point - strlen($digits));
        else $value = substr($digits, 0, $point) . '.' . substr($digits, $point);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0'); $fraction = rtrim($fraction, '0');
        $this->decimal = $m[1] . ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    public function __toString(): string { return $this->decimal; }

    /** Ordinary json_encode must fail loudly, never silently save a string. */
    public function jsonSerialize(): mixed
    {
        throw new \LogicException('Use ExactJson::encode for Decimal');
    }

    public function canonicalToken(): string
    {
        return $this->decimal . (!$this->integerSyntax && !str_contains($this->decimal, '.') ? '.0' : '');
    }
}

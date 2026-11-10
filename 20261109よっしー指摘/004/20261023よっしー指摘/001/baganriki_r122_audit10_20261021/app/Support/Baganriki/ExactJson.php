<?php
declare(strict_types=1);
namespace App\Support\Baganriki;

/**
 * JSON lists => PHP list. Ordinary named objects => associative array.
 * Empty/numeric-list-shaped objects => stdClass, so {} and {"0":...} survive.
 * Do not cast these stdClass leaves to array before writing them back.
 * Integers in PHP range stay int; every decimal/exponent/large integer is Decimal.
 */
final class ExactJson
{
    private int $at = 0;
    private function __construct(private string $text) {}

    public static function decode(string $text): mixed
    {
        if (strlen($text) > 4194304 || preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('JSON size or UTF-8 error');
        }
        $p = new self($text); $v = $p->value(0); $p->ws();
        if ($p->at !== strlen($text)) throw new \InvalidArgumentException('Trailing JSON');
        return $v;
    }

    private function ws(): void
    {
        $this->at += strspn($this->text, " \t\r\n", $this->at);
    }

    private function value(int $depth): mixed
    {
        $this->ws(); $ch = $this->text[$this->at] ?? '';
        if ($ch === '"') return $this->string();
        if ($ch === '{' || $ch === '[') {
            if ($depth >= 64) throw new \InvalidArgumentException('JSON too deep');
            $object = $ch === '{'; $close = $object ? '}' : ']';
            ++$this->at; $this->ws(); $out = []; $seen = [];
            if (($this->text[$this->at] ?? '') === $close) {
                ++$this->at; return $object ? new \stdClass() : [];
            }
            while (true) {
                if ($object) {
                    $this->ws(); $key = $this->string();
                    $encodedKey = base64_encode($key);
                    if (isset($seen[$encodedKey])) throw new \InvalidArgumentException('Duplicate JSON key');
                    $seen[$encodedKey] = true; $this->ws();
                    if (($this->text[$this->at++] ?? '') !== ':') throw new \InvalidArgumentException('Missing colon');
                    $out[$key] = $this->value($depth + 1);
                } else $out[] = $this->value($depth + 1);
                $this->ws(); $c = $this->text[$this->at++] ?? '';
                if ($c === $close) break;
                if ($c !== ',') throw new \InvalidArgumentException('Unclosed JSON container');
            }
            return $object && array_is_list($out) ? (object) $out : $out;
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $token => $v) {
            if (substr($this->text, $this->at, strlen($token)) === $token) {
                $this->at += strlen($token); return $v;
            }
        }
        if (!preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->text, $m, 0, $this->at)) {
            throw new \InvalidArgumentException('Invalid JSON value');
        }
        $this->at += strlen($m[0]); $d = new Decimal($m[0]);
        // json_decode is used only for integer syntax; a float result is discarded.
        if ($d->integerSyntax) {
            $n = json_decode($m[0], true, 2, JSON_THROW_ON_ERROR);
            if (is_int($n)) return $n;
        }
        return $d;
    }

    private function string(): string
    {
        if (($this->text[$this->at] ?? '') !== '"') throw new \InvalidArgumentException('Expected JSON string');
        $start = $this->at++;
        while ($this->at < strlen($this->text)) {
            $c = $this->text[$this->at++];
            if ($c === '\\') { ++$this->at; continue; }
            if ($c === '"') return json_decode(substr($this->text, $start, $this->at - $start), true, 2, JSON_THROW_ON_ERROR);
        }
        throw new \InvalidArgumentException('Unclosed JSON string');
    }

    public static function encode(mixed $v, bool $canonical = false, int $depth = 0): string
    {
        if ($depth > 64) throw new \InvalidArgumentException('JSON too deep');
        if ($v instanceof Decimal) return $canonical ? $v->canonicalToken() : $v->raw;
        if ($v instanceof \stdClass || is_array($v)) {
            $map = $v instanceof \stdClass || !array_is_list($v);
            $items = (array) $v;
            if ($canonical && $map) ksort($items, SORT_STRING);
            $parts = [];
            foreach ($items as $key => $item) {
                $parts[] = ($map ? self::encode((string) $key) . ':' : '') . self::encode($item, $canonical, $depth + 1);
            }
            return ($map ? '{' : '[') . implode(',', $parts) . ($map ? '}' : ']');
        }
        if (is_object($v) || is_resource($v)) throw new \InvalidArgumentException('Unsupported JSON value');
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::encode($a, true) === self::encode($b, true);
    }

    public static function hasTyped(mixed $v): bool
    {
        if ($v instanceof Decimal || $v instanceof \stdClass) return true;
        if (is_array($v)) foreach ($v as $x) if (self::hasTyped($x)) return true;
        return false;
    }

    /** Every source_refs encountered must be a JSON list of strings. */
    public static function validSourceRefs(mixed $v): bool
    {
        if (!is_array($v) && !$v instanceof \stdClass) return true;
        foreach ((array)$v as $key=>$item) {
            if ($key === 'source_refs') {
                if (!is_array($item) || !array_is_list($item)) return false;
                foreach ($item as $ref) if (!is_string($ref)) return false;
            } elseif (!self::validSourceRefs($item)) return false;
        }
        return true;
    }
}

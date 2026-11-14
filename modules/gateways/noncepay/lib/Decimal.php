<?php

namespace NoncePay\Whmcs;

/**
 * Exact arithmetic on non-negative decimal strings.
 *
 * Money never goes through a float: bcmath when it is loaded, plain string math otherwise.
 */
final class Decimal
{
    /** @var bool|null Null means auto-detect; tests force either path. */
    public static $useBcmath = null;

    /**
     * Normalized decimal string, or null when the input is not a plain non-negative decimal.
     *
     * @param mixed $value
     */
    public static function parse($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (strlen($value) > 80 || !preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        return self::normalize($value);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function sub(string $a, string $b): string
    {
        if (self::cmp($a, $b) <= 0) {
            return '0';
        }
        $scale = max(self::scale($a), self::scale($b));
        if (self::bcmath()) {
            return self::normalize(bcsub($a, $b, $scale));
        }
        return self::fromInt(self::intSub(self::toInt($a, $scale), self::toInt($b, $scale)), $scale);
    }

    public static function mul(string $a, string $b): string
    {
        $scale = self::scale($a) + self::scale($b);
        if (self::bcmath()) {
            return self::normalize(bcmul($a, $b, $scale));
        }
        return self::fromInt(self::intMul(self::toInt($a, self::scale($a)), self::toInt($b, self::scale($b))), $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        $scale = max(self::scale($a), self::scale($b));
        if (self::bcmath()) {
            return bccomp($a, $b, $scale);
        }
        return self::intCmp(self::toInt($a, $scale), self::toInt($b, $scale));
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    /**
     * Whether $received covers $expected less a tolerance in percent (as NoncePay counts a link paid).
     */
    public static function covers(string $received, string $expected, string $tolerancePercent): bool
    {
        $share = self::sub('100', $tolerancePercent);
        return self::cmp(self::mul($received, '100'), self::mul($expected, $share)) >= 0;
    }

    private static function normalize(string $value): string
    {
        $parts = explode('.', $value, 2);
        $int = ltrim($parts[0], '0');
        $frac = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        $int = $int === '' ? '0' : $int;
        return $frac === '' ? $int : $int . '.' . $frac;
    }

    private static function scale(string $value): int
    {
        $pos = strpos($value, '.');
        return $pos === false ? 0 : strlen($value) - $pos - 1;
    }

    private static function bcmath(): bool
    {
        $available = function_exists('bcadd');
        return self::$useBcmath === null ? $available : (self::$useBcmath && $available);
    }

    private static function toInt(string $value, int $scale): string
    {
        $parts = explode('.', $value, 2);
        $frac = isset($parts[1]) ? $parts[1] : '';
        $int = ltrim($parts[0] . str_pad($frac, $scale, '0'), '0');
        return $int === '' ? '0' : $int;
    }

    private static function fromInt(string $int, int $scale): string
    {
        if ($scale === 0) {
            return self::normalize($int);
        }
        $int = str_pad($int, $scale + 1, '0', STR_PAD_LEFT);
        return self::normalize(substr($int, 0, -$scale) . '.' . substr($int, -$scale));
    }

    private static function intCmp(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) < strlen($b) ? -1 : 1;
        }
        $cmp = strcmp($a, $b);
        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    private static function intSub(string $a, string $b): string
    {
        $len = strlen($a);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';
        for ($i = $len - 1; $i >= 0; $i--) {
            $diff = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $diff < 0 ? 1 : 0;
            $result = ($diff + 10 * $borrow) . $result;
        }
        $result = ltrim($result, '0');
        return $result === '' ? '0' : $result;
    }

    private static function intMul(string $a, string $b): string
    {
        $la = strlen($a);
        $lb = strlen($b);
        $digits = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $sum = (int) $a[$i] * (int) $b[$j] + $digits[$i + $j + 1];
                $digits[$i + $j + 1] = $sum % 10;
                $digits[$i + $j] += intdiv($sum, 10);
            }
        }
        $result = ltrim(implode('', $digits), '0');
        return $result === '' ? '0' : $result;
    }
}

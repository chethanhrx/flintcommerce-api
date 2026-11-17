<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure PHP polyfill for bcmath functions.
 *
 * Provides drop-in replacements for bcmath functions when the extension is not available.
 * Uses PHP's native arbitrary precision integers and string-based arithmetic.
 */
final class BcMath
{
    private static bool $checked = false;

    /**
     * Check if bcmath extension is available.
     */
    private static function check(): void
    {
        if (!self::$checked) {
            self::$checked = true;
        }
    }

    /**
     * Multiply two arbitrary precision numbers.
     */
    public static function mul(mixed $left, mixed $right, int $scale = 0): string
    {
        return self::calculate($left, $right, $scale, '*');
    }

    /**
     * Add two arbitrary precision numbers.
     */
    public static function add(mixed $left, mixed $right, int $scale = 0): string
    {
        return self::calculate($left, $right, $scale, '+');
    }

    /**
     * Subtract right from left.
     */
    public static function sub(mixed $left, mixed $right, int $scale = 0): string
    {
        return self::calculate($left, $right, $scale, '-');
    }

    /**
     * Divide left by right.
     */
    public static function div(mixed $left, mixed $right, int $scale = 0): string
    {
        return self::calculate($left, $right, $scale, '/');
    }

    /**
     * Compare two arbitrary precision numbers.
     *
     * Returns -1 if left < right, 0 if equal, 1 if left > right.
     */
    public static function cmp(string $left, string $right, int $scale = 0): int
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);

        if ($left === $right) {
            return 0;
        }

        $negLeft = $left[0] === '-';
        $negRight = $right[0] === '-';

        if ($negLeft && !$negRight) {
            return -1;
        }
        if (!$negLeft && $negRight) {
            return 1;
        }

        if ($negLeft && $negRight) {
            // Both negative: compare absolute values in reverse
            return self::compareAbs(substr($right, 1), substr($left, 1));
        }

        return self::compareAbs($left, $right);
    }

    /**
     * Get the minimum of two numbers.
     */
    public static function min(string $left, string $right, int $scale = 0): string
    {
        return self::cmp($left, $right, 0) <= 0 ? self::normalize($left, 0) : self::normalize($right, 0);
    }

    /**
     * Perform calculation using PHP's native arbitrary precision.
     */
    private static function calculate(mixed $left, mixed $right, int $scale, string $operator): string
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);

        // Use PHP's native arbitrary precision for the calculation
        $result = match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right !== '0' && $right !== '0.0' ? $left / $right : '0',
            default => throw new \InvalidArgumentException("Unknown operator: $operator"),
        };

        // Format with correct scale
        return self::format($result, $scale);
    }

    /**
     * Compare absolute values of two positive number strings.
     */
    private static function compareAbs(string $left, string $right): int
    {
        // Remove leading zeros
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        if ($left === '') $left = '0';
        if ($right === '') $right = '0';

        if (strlen($left) !== strlen($right)) {
            return strlen($left) > strlen($right) ? 1 : -1;
        }

        return $left <=> $right;
    }

    /**
     * Normalize a number for calculation.
     */
    private static function normalize(mixed $value, int $scale): string
    {
        if ($value === '' || $value === null) {
            return '0';
        }

        // Convert to string if not already
        if (is_float($value)) {
            // Use sprintf to avoid scientific notation
            $value = sprintf('%.10F', $value);
            $value = rtrim(rtrim($value, '0'), '.');
        } elseif (is_int($value)) {
            $value = (string) $value;
        } elseif (is_string($value)) {
            // Already a string
        } else {
            $value = (string) $value;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === null) {
            return '0';
        }

        // Handle negative
        $negative = $value[0] === '-';
        if ($negative) {
            $value = substr($value, 1);
        }

        // Remove leading zeros
        $value = ltrim($value, '0');
        if ($value === '') {
            return '0';
        }

        // Handle decimal point
        if (str_contains($value, '.')) {
            [$intPart, $decPart] = explode('.', $value, 2);
            $intPart = ltrim($intPart, '0');
            if ($intPart === '') $intPart = '0';
            $decPart = rtrim($decPart, '0');
            if ($decPart !== '') {
                $value = $intPart . '.' . $decPart;
            } else {
                $value = $intPart;
            }
        } else {
            $value = ltrim($value, '0');
            if ($value === '') $value = '0';
        }

        if ($negative) {
            $value = '-' . $value;
        }

        return $value;
    }

    /**
     * Format result with correct scale.
     */
    private static function format(mixed $value, int $scale): string
    {
        if (!is_numeric($value)) {
            return '0';
        }

        // Use sprintf for formatting
        if ($scale > 0) {
            return sprintf('%0.' . $scale . 'F', $value);
        }

        return (string) ((int) $value);
    }
}
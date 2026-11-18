<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Support;

use BijayX\NepalPay\Exceptions\ValidationException;

/**
 * Money helpers. The public API always uses integer paisa (Rs 1 = 100 paisa).
 */
final class Money
{
    private function __construct() {}

    /**
     * Format paisa as the rupee string eSewa expects: 11000 => "110", 11050 => "110.50".
     */
    public static function paisaToRupeeString(int $paisa): string
    {
        if ($paisa < 0) {
            throw ValidationException::forField('amount', 'The amount must not be negative.');
        }

        $rupees = intdiv($paisa, 100);
        $remainder = $paisa % 100;

        return $remainder === 0
            ? (string) $rupees
            : sprintf('%d.%02d', $rupees, $remainder);
    }

    /**
     * Parse a rupee amount returned by eSewa ("1,000.0", "110.50", 100.0, 1100) into paisa.
     *
     * @throws ValidationException when the value is not a valid non-negative amount
     */
    public static function parseRupees(mixed $value, string $field = 'amount'): int
    {
        if (is_int($value)) {
            $number = (float) $value;
        } elseif (is_float($value)) {
            $number = $value;
        } elseif (is_string($value)) {
            $normalized = str_replace([',', ' '], '', trim($value));

            if (preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
                throw ValidationException::forField($field, 'The value is not a valid rupee amount.');
            }

            $number = (float) $normalized;
        } else {
            throw ValidationException::forField($field, 'The value is not a valid rupee amount.');
        }

        if (! is_finite($number) || $number < 0) {
            throw ValidationException::forField($field, 'The value is not a valid rupee amount.');
        }

        return (int) round($number * 100);
    }

    /**
     * Assert that a value is a positive integer amount in paisa and return it.
     *
     * @throws ValidationException
     */
    public static function assertPaisa(mixed $value, string $field = 'amount'): int
    {
        if (! is_int($value)) {
            throw ValidationException::forField($field, 'The amount must be an integer number of paisa.');
        }

        if ($value <= 0) {
            throw ValidationException::forField($field, 'The amount must be greater than zero.');
        }

        return $value;
    }
}

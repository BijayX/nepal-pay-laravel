<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

/**
 * The gateway confirmed a payment that does not match what your application expected.
 * Treat this as a fraud signal: do not fulfil the order.
 */
final class PaymentMismatchException extends NepalPayException
{
    public const AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';

    public const REFERENCE_MISMATCH = 'REFERENCE_MISMATCH';

    private function __construct(
        string $message,
        string $code,
        string $gateway,
        private readonly int|string $expected,
        private readonly int|string|null $actual,
    ) {
        parent::__construct($message, $code, $gateway, ['expected' => $expected, 'actual' => $actual]);
    }

    public static function amount(string $gateway, int $expected, int $actual): self
    {
        return new self(
            "The {$gateway} payment amount ({$actual} paisa) does not match the expected amount ({$expected} paisa).",
            self::AMOUNT_MISMATCH,
            $gateway,
            $expected,
            $actual,
        );
    }

    public static function reference(string $gateway, string $expected, ?string $actual): self
    {
        return new self(
            "The {$gateway} payment reference does not match the expected reference.",
            self::REFERENCE_MISMATCH,
            $gateway,
            $expected,
            $actual,
        );
    }

    /**
     * Expected value: paisa for AMOUNT_MISMATCH, reference string for REFERENCE_MISMATCH.
     */
    public function expected(): int|string
    {
        return $this->expected;
    }

    public function actual(): int|string|null
    {
        return $this->actual;
    }
}

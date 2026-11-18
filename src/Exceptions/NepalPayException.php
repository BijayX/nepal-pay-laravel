<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception thrown by this package.
 *
 * Catch this type to handle all payment failures in one place. The machine-readable
 * error code returned by errorCode() is identical across the PHP and Node.js packages.
 * Messages and details never contain secret keys.
 */
abstract class NepalPayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly ?string $gateway = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Stable, machine-readable error code (e.g. "AMOUNT_MISMATCH").
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The gateway that raised the error ("khalti", "esewa"), or null when not gateway specific.
     */
    public function gateway(): ?string
    {
        return $this->gateway;
    }

    /**
     * Structured, secret-free details about the failure.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Log context. Laravel's exception handler merges this into the log entry automatically.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'nepal_pay_code' => $this->errorCode,
            'gateway' => $this->gateway,
            'details' => $this->details,
        ];
    }
}

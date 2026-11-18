<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

use Throwable;

/**
 * The gateway could not be reached: DNS/connection failure (NETWORK_ERROR) or timeout (TIMEOUT).
 */
final class NetworkException extends NepalPayException
{
    public const NETWORK_ERROR = 'NETWORK_ERROR';

    public const TIMEOUT = 'TIMEOUT';

    public static function timeout(string $gateway, int $timeoutMs, ?Throwable $previous = null): self
    {
        return new self(
            "The request to {$gateway} timed out after {$timeoutMs} ms.",
            self::TIMEOUT,
            $gateway,
            ['timeout_ms' => $timeoutMs],
            $previous,
        );
    }

    public static function connectionFailed(string $gateway, ?Throwable $previous = null): self
    {
        return new self(
            "Could not connect to {$gateway}.",
            self::NETWORK_ERROR,
            $gateway,
            [],
            $previous,
        );
    }

    public function isTimeout(): bool
    {
        return $this->errorCode() === self::TIMEOUT;
    }
}

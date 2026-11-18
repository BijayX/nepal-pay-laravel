<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

/**
 * The gateway rejected the credentials (HTTP 401/403), usually a wrong secret key
 * or a sandbox key used against production.
 */
final class AuthenticationException extends NepalPayException
{
    public const CODE = 'AUTHENTICATION_FAILED';

    public function __construct(string $message, string $gateway, private readonly ?int $statusCode = null)
    {
        parent::__construct($message, self::CODE, $gateway, ['status_code' => $statusCode]);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}

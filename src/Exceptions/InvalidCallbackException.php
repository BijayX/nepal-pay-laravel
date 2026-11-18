<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

/**
 * The return/callback request is missing required data or could not be decoded.
 */
final class InvalidCallbackException extends NepalPayException
{
    public const CODE = 'INVALID_CALLBACK';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, string $gateway, array $details = [])
    {
        parent::__construct($message, self::CODE, $gateway, $details);
    }
}

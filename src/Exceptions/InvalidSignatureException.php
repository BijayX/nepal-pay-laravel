<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

/**
 * The callback signature did not match, or it was signed for a different merchant.
 * Treat this as a tampering attempt.
 */
final class InvalidSignatureException extends NepalPayException
{
    public const CODE = 'INVALID_SIGNATURE';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, string $gateway, array $details = [])
    {
        parent::__construct($message, self::CODE, $gateway, $details);
    }
}

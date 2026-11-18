<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

/**
 * Missing or invalid configuration, or an unknown gateway name.
 */
final class ConfigurationException extends NepalPayException
{
    public const CODE = 'CONFIGURATION_ERROR';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, ?string $gateway = null, array $details = [])
    {
        parent::__construct($message, self::CODE, $gateway, $details);
    }

    public static function missing(string $gateway, string $key): self
    {
        return new self(
            "Missing configuration value [nepal-pay.gateways.{$gateway}.{$key}].",
            $gateway,
            ['key' => $key],
        );
    }

    public static function invalid(string $gateway, string $key, string $reason): self
    {
        return new self(
            "Invalid configuration value [nepal-pay.gateways.{$gateway}.{$key}]: {$reason}",
            $gateway,
            ['key' => $key],
        );
    }

    public static function unknownGateway(string $name): self
    {
        return new self("Payment gateway [{$name}] is not supported.", null, ['gateway' => $name]);
    }
}

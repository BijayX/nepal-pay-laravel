<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Enums;

use BijayX\NepalPay\Exceptions\ConfigurationException;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Resolve from a configuration value. Never guesses production.
     */
    public static function fromConfig(mixed $value, ?string $gateway = null): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return self::Sandbox;
        }

        $environment = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

        if ($environment === null) {
            throw new ConfigurationException(
                'Invalid nepal-pay environment ['.(is_scalar($value) ? (string) $value : get_debug_type($value)).']; expected "sandbox" or "production".',
                $gateway,
                ['key' => 'environment'],
            );
        }

        return $environment;
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}

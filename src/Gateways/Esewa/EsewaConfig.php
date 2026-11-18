<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Esewa;

use BijayX\NepalPay\Enums\Environment;
use BijayX\NepalPay\Exceptions\ConfigurationException;
use SensitiveParameter;

/**
 * Validated eSewa ePay v2 settings. Construction fails fast on missing or invalid values.
 *
 * In the sandbox the public UAT merchant (EPAYTEST) is used when no product code is
 * configured. Production never falls back to defaults.
 */
final readonly class EsewaConfig
{
    public const SANDBOX_PRODUCT_CODE = 'EPAYTEST';

    public const SANDBOX_SECRET_KEY = '8gBm/:&EnhH.1/q';

    public const SANDBOX_FORM_URL = 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';

    public const PRODUCTION_FORM_URL = 'https://epay.esewa.com.np/api/epay/main/v2/form';

    public const SANDBOX_STATUS_URL = 'https://rc.esewa.com.np/api/epay/transaction/status/';

    public const PRODUCTION_STATUS_URL = 'https://esewa.com.np/api/epay/transaction/status/';

    public string $formUrl;

    public string $statusUrl;

    /**
     * @throws ConfigurationException
     */
    public function __construct(
        public string $productCode,
        #[SensitiveParameter] public string $secretKey,
        public Environment $environment = Environment::Sandbox,
        ?string $formUrl = null,
        ?string $statusUrl = null,
    ) {
        if (trim($productCode) === '') {
            throw ConfigurationException::missing(EsewaGateway::NAME, 'product_code');
        }

        if (trim($secretKey) === '') {
            throw ConfigurationException::missing(EsewaGateway::NAME, 'secret_key');
        }

        $production = $environment->isProduction();

        $this->formUrl = self::url(
            $formUrl,
            $production ? self::PRODUCTION_FORM_URL : self::SANDBOX_FORM_URL,
            'form_url',
        );

        $this->statusUrl = self::url(
            $statusUrl,
            $production ? self::PRODUCTION_STATUS_URL : self::SANDBOX_STATUS_URL,
            'status_url',
        );
    }

    /**
     * @param  array<array-key, mixed>  $config  keys: product_code, secret_key, environment, form_url, status_url
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $environment = Environment::fromConfig($config['environment'] ?? null, EsewaGateway::NAME);

        $productCode = self::optionalString($config, 'product_code');
        $secretKey = self::optionalString($config, 'secret_key');

        if ($environment === Environment::Sandbox) {
            $productCode ??= self::SANDBOX_PRODUCT_CODE;

            // Only pair the public test secret with the public test merchant.
            if ($productCode === self::SANDBOX_PRODUCT_CODE) {
                $secretKey ??= self::SANDBOX_SECRET_KEY;
            }
        }

        if ($productCode === null) {
            throw ConfigurationException::missing(EsewaGateway::NAME, 'product_code');
        }

        if ($secretKey === null) {
            throw ConfigurationException::missing(EsewaGateway::NAME, 'secret_key');
        }

        return new self(
            $productCode,
            $secretKey,
            $environment,
            self::optionalString($config, 'form_url'),
            self::optionalString($config, 'status_url'),
        );
    }

    /**
     * Keep the secret out of dd(), dump() and var_dump() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'productCode' => $this->productCode,
            'secretKey' => '********',
            'environment' => $this->environment,
            'formUrl' => $this->formUrl,
            'statusUrl' => $this->statusUrl,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ConfigurationException::invalid(EsewaGateway::NAME, $key, 'must be a string.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function url(?string $value, string $default, string $key): string
    {
        $url = $value === null || trim($value) === '' ? $default : trim($value);

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw ConfigurationException::invalid(EsewaGateway::NAME, $key, 'must be an absolute http(s) URL.');
        }

        return $url;
    }
}

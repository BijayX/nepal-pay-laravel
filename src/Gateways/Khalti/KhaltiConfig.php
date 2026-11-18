<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Khalti;

use BijayX\NepalPay\Enums\Environment;
use BijayX\NepalPay\Exceptions\ConfigurationException;
use SensitiveParameter;

/**
 * Validated Khalti settings. Construction fails fast on missing or invalid values.
 */
final readonly class KhaltiConfig
{
    public const SANDBOX_BASE_URL = 'https://dev.khalti.com/api/v2/';

    public const PRODUCTION_BASE_URL = 'https://khalti.com/api/v2/';

    public string $baseUrl;

    /**
     * @throws ConfigurationException
     */
    public function __construct(
        #[SensitiveParameter] public string $secretKey,
        public string $websiteUrl,
        public Environment $environment = Environment::Sandbox,
        ?string $baseUrl = null,
    ) {
        if (trim($secretKey) === '') {
            throw ConfigurationException::missing(KhaltiGateway::NAME, 'secret_key');
        }

        if (! self::isAbsoluteUrl($websiteUrl)) {
            throw ConfigurationException::invalid(KhaltiGateway::NAME, 'website_url', 'must be an absolute http(s) URL.');
        }

        $baseUrl = $baseUrl === null || trim($baseUrl) === ''
            ? ($environment->isProduction() ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL)
            : trim($baseUrl);

        if (! self::isAbsoluteUrl($baseUrl)) {
            throw ConfigurationException::invalid(KhaltiGateway::NAME, 'base_url', 'must be an absolute http(s) URL.');
        }

        $this->baseUrl = rtrim($baseUrl, '/').'/';
    }

    /**
     * @param  array<array-key, mixed>  $config  keys: secret_key, website_url, environment, base_url
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $secretKey = $config['secret_key'] ?? null;
        $websiteUrl = $config['website_url'] ?? null;
        $baseUrl = $config['base_url'] ?? null;

        if (! is_string($secretKey) || trim($secretKey) === '') {
            throw ConfigurationException::missing(KhaltiGateway::NAME, 'secret_key');
        }

        if (! is_string($websiteUrl) || trim($websiteUrl) === '') {
            throw ConfigurationException::missing(KhaltiGateway::NAME, 'website_url');
        }

        if ($baseUrl !== null && ! is_string($baseUrl)) {
            throw ConfigurationException::invalid(KhaltiGateway::NAME, 'base_url', 'must be a string.');
        }

        return new self(
            trim($secretKey),
            trim($websiteUrl),
            Environment::fromConfig($config['environment'] ?? null, KhaltiGateway::NAME),
            $baseUrl,
        );
    }

    public function endpoint(string $path): string
    {
        return $this->baseUrl.ltrim($path, '/');
    }

    /**
     * Keep the secret out of dd(), dump() and var_dump() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'secretKey' => '********',
            'websiteUrl' => $this->websiteUrl,
            'environment' => $this->environment,
            'baseUrl' => $this->baseUrl,
        ];
    }

    private static function isAbsoluteUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}

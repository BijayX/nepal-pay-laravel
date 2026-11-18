<?php

declare(strict_types=1);

namespace BijayX\NepalPay;

use BijayX\NepalPay\Contracts\PaymentGateway;
use BijayX\NepalPay\Enums\Environment;
use BijayX\NepalPay\Exceptions\ConfigurationException;
use BijayX\NepalPay\Gateways\Esewa\EsewaConfig;
use BijayX\NepalPay\Gateways\Esewa\EsewaGateway;
use BijayX\NepalPay\Gateways\Khalti\KhaltiConfig;
use BijayX\NepalPay\Gateways\Khalti\KhaltiGateway;
use BijayX\NepalPay\Http\GatewayHttpClient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;

/**
 * Resolves and caches payment gateways.
 *
 * Built-in drivers: "khalti", "esewa". Register your own with extend():
 *
 *     NepalPay::extend('fonepay', fn ($app) => new FonepayGateway(...));
 *
 * Calls on the manager itself are forwarded to the default gateway.
 *
 * @mixin PaymentGateway
 */
class NepalPayManager extends Manager
{
    /**
     * Get a gateway instance. Alias of driver().
     *
     * @throws ConfigurationException
     */
    public function gateway(?string $name = null): PaymentGateway
    {
        return $this->driver($name);
    }

    /**
     * @param  string|null  $driver
     *
     * @throws ConfigurationException
     */
    public function driver($driver = null): PaymentGateway
    {
        $name = is_string($driver) ? strtolower(trim($driver)) : $this->getDefaultDriver();

        $gateway = parent::driver($name);

        if (! $gateway instanceof PaymentGateway) {
            throw new ConfigurationException(
                "Payment gateway [{$name}] must implement ".PaymentGateway::class.'.',
                $name,
            );
        }

        return $gateway;
    }

    public function getDefaultDriver(): string
    {
        $default = $this->config->get('nepal-pay.default');

        if (! is_string($default) || trim($default) === '') {
            throw new ConfigurationException('No default payment gateway configured [nepal-pay.default].');
        }

        return strtolower(trim($default));
    }

    /**
     * The global environment, or a per-gateway override when configured.
     */
    public function environment(?string $gateway = null): Environment
    {
        $value = $gateway === null ? null : $this->config->get("nepal-pay.gateways.{$gateway}.environment");

        return Environment::fromConfig($value ?? $this->config->get('nepal-pay.environment'), $gateway);
    }

    /**
     * Raw configuration array for a gateway, with the resolved environment merged in.
     *
     * @return array<array-key, mixed>
     */
    public function gatewayConfig(string $gateway): array
    {
        $config = $this->config->get("nepal-pay.gateways.{$gateway}", []);

        if (! is_array($config)) {
            throw new ConfigurationException("Configuration [nepal-pay.gateways.{$gateway}] must be an array.", $gateway);
        }

        return ['environment' => $this->environment($gateway)] + $config;
    }

    /**
     * An HTTP client configured with the package's timeout and retry settings.
     * Useful for custom gateways registered through extend().
     */
    public function httpClient(string $gateway): GatewayHttpClient
    {
        return new GatewayHttpClient(
            $this->container->make(HttpFactory::class),
            $gateway,
            timeoutMs: $this->intConfig('http.timeout_ms', 15000, 1),
            retries: $this->intConfig('http.retries', 2, 0),
            retryDelayMs: $this->intConfig('http.retry_delay_ms', 200, 0),
        );
    }

    protected function createKhaltiDriver(): KhaltiGateway
    {
        return new KhaltiGateway(
            KhaltiConfig::fromArray($this->gatewayConfig(KhaltiGateway::NAME)),
            $this->httpClient(KhaltiGateway::NAME),
            fn (): ?Dispatcher => $this->events(),
        );
    }

    protected function createEsewaDriver(): EsewaGateway
    {
        return new EsewaGateway(
            EsewaConfig::fromArray($this->gatewayConfig(EsewaGateway::NAME)),
            $this->httpClient(EsewaGateway::NAME),
            fn (): ?Dispatcher => $this->events(),
        );
    }

    /**
     * @param  string  $driver
     *
     * @throws ConfigurationException
     */
    protected function createDriver($driver): mixed
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $method = 'create'.ucfirst($driver).'Driver';

        if (preg_match('/^[a-z][a-z0-9_]*$/', $driver) === 1 && method_exists($this, $method)) {
            return $this->{$method}();
        }

        throw ConfigurationException::unknownGateway($driver);
    }

    private function events(): ?Dispatcher
    {
        return $this->container->bound('events') ? $this->container->make('events') : null;
    }

    private function intConfig(string $key, int $default, int $min): int
    {
        $value = $this->config->get("nepal-pay.{$key}", $default);

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min) {
            throw new ConfigurationException(
                "Configuration [nepal-pay.{$key}] must be an integer of at least {$min}.",
                null,
                ['key' => $key],
            );
        }

        return $value;
    }
}

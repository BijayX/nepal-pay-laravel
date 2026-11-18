<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Facades;

use BijayX\NepalPay\Contracts\PaymentGateway;
use BijayX\NepalPay\NepalPayManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \BijayX\NepalPay\Contracts\PaymentGateway driver(string|null $driver = null)
 * @method static \BijayX\NepalPay\Contracts\PaymentGateway gateway(string|null $name = null)
 * @method static \BijayX\NepalPay\NepalPayManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static \BijayX\NepalPay\Enums\Environment environment(string|null $gateway = null)
 * @method static \BijayX\NepalPay\Http\GatewayHttpClient httpClient(string $gateway)
 * @method static array<string, mixed> getDrivers()
 * @method static \BijayX\NepalPay\NepalPayManager forgetDrivers()
 * @method static string name()
 * @method static \BijayX\NepalPay\Data\InitiatedPayment initiate(\BijayX\NepalPay\Data\PaymentRequest|array<string, mixed> $request)
 * @method static \BijayX\NepalPay\Data\PaymentResult verify(array<array-key, mixed> $callback, \BijayX\NepalPay\Data\ExpectedPayment|null $expected = null)
 * @method static \BijayX\NepalPay\Data\PaymentResult lookup(string $reference, int|null $amount = null)
 *
 * @see NepalPayManager
 * @see PaymentGateway
 */
final class NepalPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NepalPayManager::class;
    }
}

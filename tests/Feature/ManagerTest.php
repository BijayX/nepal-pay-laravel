<?php

declare(strict_types=1);

use BijayX\NepalPay\Contracts\PaymentGateway;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Data\PaymentRequest;
use BijayX\NepalPay\Data\PaymentResult;
use BijayX\NepalPay\Data\RedirectInstruction;
use BijayX\NepalPay\Enums\Environment;
use BijayX\NepalPay\Enums\PaymentStatus;
use BijayX\NepalPay\Events\PaymentInitiated;
use BijayX\NepalPay\Exceptions\ConfigurationException;
use BijayX\NepalPay\Exceptions\PaymentMismatchException;
use BijayX\NepalPay\Facades\NepalPay;
use BijayX\NepalPay\Gateways\AbstractGateway;
use BijayX\NepalPay\Gateways\Esewa\EsewaConfig;
use BijayX\NepalPay\Gateways\Esewa\EsewaGateway;
use BijayX\NepalPay\Gateways\Khalti\KhaltiGateway;
use BijayX\NepalPay\NepalPayManager;
use BijayX\NepalPay\NepalPayServiceProvider;
use BijayX\NepalPay\Tests\TestCase;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

final class FakeWalletGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'fakewallet';
    }

    protected function createPayment(PaymentRequest $request): InitiatedPayment
    {
        return new InitiatedPayment('fakewallet', 'fw-'.$request->orderId, $request->orderId, $request->amount, RedirectInstruction::get('https://wallet.test/pay'));
    }

    protected function confirm(array $callback, ?ExpectedPayment $expected): PaymentResult
    {
        return $this->lookup((string) $this->callbackString($callback, 'ref'), $expected?->amount);
    }

    public function lookup(string $reference, ?int $amount = null): PaymentResult
    {
        return new PaymentResult('fakewallet', PaymentStatus::Completed, $reference, 'T1', 5000);
    }
}

it('resolves the built-in gateways', function () {
    expect(NepalPay::driver('khalti'))->toBeInstanceOf(KhaltiGateway::class)
        ->and(NepalPay::gateway('esewa'))->toBeInstanceOf(EsewaGateway::class)
        ->and(NepalPay::driver(' ESEWA '))->toBe(NepalPay::driver('esewa'))
        ->and(NepalPay::driver('khalti')->name())->toBe('khalti');
});

it('uses the configured default gateway', function () {
    config(['nepal-pay.default' => 'esewa']);

    expect(NepalPay::getDefaultDriver())->toBe('esewa')
        ->and(NepalPay::driver())->toBeInstanceOf(EsewaGateway::class)
        ->and(NepalPay::name())->toBe('esewa')
        ->and(app(PaymentGateway::class))->toBeInstanceOf(EsewaGateway::class);
});

it('registers the manager as a singleton with an alias', function () {
    expect(app(NepalPayManager::class))->toBe(app('nepal-pay'))
        ->and(NepalPay::getFacadeRoot())->toBe(app(NepalPayManager::class));
});

it('throws ConfigurationException for unknown gateways', function (string $name) {
    NepalPay::driver($name);
})->throws(ConfigurationException::class, 'not supported')->with(['paypal', 'Khalti-Driver', 'create']);

it('fails fast on missing Khalti configuration', function (string $key) {
    config(["nepal-pay.gateways.khalti.{$key}" => null]);

    try {
        NepalPay::driver('khalti');
        test()->fail('No exception thrown.');
    } catch (ConfigurationException $e) {
        expect($e->errorCode())->toBe('CONFIGURATION_ERROR')
            ->and($e->gateway())->toBe('khalti')
            ->and($e->getMessage())->toContain($key);
    }
})->with(['secret_key', 'website_url']);

it('rejects an invalid website url', function () {
    config(['nepal-pay.gateways.khalti.website_url' => 'shop.test']);

    NepalPay::driver('khalti');
})->throws(ConfigurationException::class, 'website_url');

it('rejects an invalid environment', function () {
    config(['nepal-pay.environment' => 'live']);

    NepalPay::driver('khalti');
})->throws(ConfigurationException::class, 'environment');

it('rejects invalid http settings', function () {
    config(['nepal-pay.http.timeout_ms' => 0]);

    NepalPay::driver('khalti');
})->throws(ConfigurationException::class, 'timeout_ms');

it('uses sandbox endpoints by default and production endpoints when configured', function () {
    expect(NepalPay::driver('khalti')->config()->baseUrl)->toBe('https://dev.khalti.com/api/v2/')
        ->and(NepalPay::driver('esewa')->config()->formUrl)->toBe('https://rc-epay.esewa.com.np/api/epay/main/v2/form');

    config(['nepal-pay.environment' => 'production', 'nepal-pay.gateways.esewa.product_code' => 'LIVE01', 'nepal-pay.gateways.esewa.secret_key' => 'live-secret']);
    NepalPay::forgetDrivers();

    $esewa = NepalPay::driver('esewa')->config();

    expect(NepalPay::driver('khalti')->config()->baseUrl)->toBe('https://khalti.com/api/v2/')
        ->and(NepalPay::environment())->toBe(Environment::Production)
        ->and($esewa->formUrl)->toBe('https://epay.esewa.com.np/api/epay/main/v2/form')
        ->and($esewa->statusUrl)->toBe('https://esewa.com.np/api/epay/transaction/status/');
});

it('supports per-gateway environment and base url overrides', function () {
    config([
        'nepal-pay.gateways.khalti.environment' => 'production',
        'nepal-pay.gateways.khalti.base_url' => 'https://proxy.test/khalti',
    ]);

    expect(NepalPay::driver('khalti')->config()->environment)->toBe(Environment::Production)
        ->and(NepalPay::driver('khalti')->config()->endpoint('epayment/lookup/'))->toBe('https://proxy.test/khalti/epayment/lookup/')
        ->and(NepalPay::driver('esewa')->config()->environment)->toBe(Environment::Sandbox);
});

it('defaults eSewa to the UAT merchant only in sandbox', function () {
    config(['nepal-pay.gateways.esewa.product_code' => null, 'nepal-pay.gateways.esewa.secret_key' => null]);

    $config = NepalPay::driver('esewa')->config();

    expect($config->productCode)->toBe('EPAYTEST')
        ->and($config->secretKey)->toBe(EsewaConfig::SANDBOX_SECRET_KEY);

    config(['nepal-pay.gateways.esewa.environment' => 'production']);
    NepalPay::forgetDrivers();

    expect(fn () => NepalPay::driver('esewa'))->toThrow(ConfigurationException::class, 'product_code');
});

it('does not pair the test secret with a real sandbox merchant', function () {
    config(['nepal-pay.gateways.esewa.product_code' => 'MYSHOP', 'nepal-pay.gateways.esewa.secret_key' => null]);

    NepalPay::driver('esewa');
})->throws(ConfigurationException::class, 'secret_key');

it('keeps secrets out of debug output', function () {
    $dump = print_r(NepalPay::driver('khalti')->config(), true).print_r(NepalPay::driver('esewa')->config(), true);

    expect($dump)->not->toContain(TestCase::KHALTI_SECRET)
        ->and($dump)->not->toContain('8gBm/:&EnhH.1/q');
});

it('registers custom gateways with extend()', function () {
    NepalPay::extend('fakewallet', fn ($app) => new FakeWalletGateway($app['events']));

    $gateway = NepalPay::driver('fakewallet');
    $payment = $gateway->initiate(['amount' => 5000, 'order_id' => 'A1', 'order_name' => 'x', 'return_url' => 'https://shop.test']);

    expect($gateway)->toBeInstanceOf(FakeWalletGateway::class)
        ->and($payment->reference)->toBe('fw-A1')
        ->and($gateway->verify(['ref' => 'fw-A1'], new ExpectedPayment(5000, 'fw-A1'))->isPaid())->toBeTrue()
        ->and(fn () => $gateway->verify(['ref' => 'fw-A1'], new ExpectedPayment(9000, 'fw-A1')))
        ->toThrow(PaymentMismatchException::class);
});

it('rejects custom creators that do not return a PaymentGateway', function () {
    NepalPay::extend('broken', fn () => new stdClass);

    NepalPay::driver('broken');
})->throws(ConfigurationException::class, 'must implement');

it('forwards calls to the default gateway', function () {
    Http::fake(['*' => Http::response(gatewayFixture('khalti')['lookup']['completed']['body'])]);

    expect(NepalPay::lookup('bZQLD9wRVWo4CdESSfuSsB')->isPaid())->toBeTrue();
});

it('publishes the config and views', function () {
    $paths = ServiceProvider::pathsToPublish(NepalPayServiceProvider::class, 'nepal-pay-config');
    $views = ServiceProvider::pathsToPublish(NepalPayServiceProvider::class, 'nepal-pay-views');

    expect(array_values($paths))->toBe([app(Application::class)->configPath('nepal-pay.php')])
        ->and(array_values($views))->toBe([app(Application::class)->resourcePath('views/vendor/nepal-pay')])
        ->and(config('nepal-pay.http.timeout_ms'))->toBe(15000);
});

it('dispatches events to a dispatcher faked after the driver was resolved', function (): void {
    $gateway = NepalPay::driver('esewa');

    Event::fake([PaymentInitiated::class]);

    $gateway->initiate([
        'amount' => 10000, 'order_id' => 'ORD-EVT-1', 'order_name' => 'Test',
        'return_url' => 'https://shop.test/return',
    ]);

    Event::assertDispatched(PaymentInitiated::class);
});

<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Tests;

use BijayX\NepalPay\Facades\NepalPay;
use BijayX\NepalPay\NepalPayServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const KHALTI_SECRET = 'test_secret_key_f59e8b7d18b4499ca40f68195a846e9b';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function getPackageProviders($app): array
    {
        return [NepalPayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['NepalPay' => NepalPay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nepal-pay.environment', 'sandbox');
        $app['config']->set('nepal-pay.gateways.khalti.secret_key', self::KHALTI_SECRET);
        $app['config']->set('nepal-pay.gateways.khalti.website_url', 'https://shop.test');
        $app['config']->set('nepal-pay.gateways.esewa.product_code', 'EPAYTEST');
        $app['config']->set('nepal-pay.gateways.esewa.secret_key', '8gBm/:&EnhH.1/q');
    }
}

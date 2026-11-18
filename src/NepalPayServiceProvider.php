<?php

declare(strict_types=1);

namespace BijayX\NepalPay;

use BijayX\NepalPay\Contracts\PaymentGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

final class NepalPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nepal-pay.php', 'nepal-pay');

        $this->app->singleton(NepalPayManager::class, static fn (Container $app): NepalPayManager => new NepalPayManager($app));
        $this->app->alias(NepalPayManager::class, 'nepal-pay');

        // Type-hint PaymentGateway to receive the default gateway.
        $this->app->bind(
            PaymentGateway::class,
            static fn (Container $app): PaymentGateway => $app->make(NepalPayManager::class)->gateway(),
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nepal-pay');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nepal-pay.php' => $this->app->configPath('nepal-pay.php'),
            ], 'nepal-pay-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/nepal-pay'),
            ], 'nepal-pay-views');
        }
    }
}

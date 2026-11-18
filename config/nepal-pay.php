<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | Used by NepalPay::initiate(), NepalPay::verify() and when type-hinting
    | BijayX\NepalPay\Contracts\PaymentGateway. Supported: "khalti", "esewa",
    | and any gateway registered with NepalPay::extend().
    |
    */

    'default' => env('NEPAL_PAY_GATEWAY', 'khalti'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "sandbox" or "production". Defaults to sandbox; production is never
    | assumed. Each gateway may override this with its own "environment" key.
    |
    */

    'environment' => env('NEPAL_PAY_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeout for every gateway request, and retries for idempotent reads
    | (Khalti lookup, eSewa status) on network errors, timeouts and 5xx.
    | Backoff is retry_delay_ms * 2^attempt. Initiate calls are never retried.
    |
    */

    'http' => [
        'timeout_ms' => (int) env('NEPAL_PAY_HTTP_TIMEOUT_MS', 15000),
        'retries' => (int) env('NEPAL_PAY_HTTP_RETRIES', 2),
        'retry_delay_ms' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Secrets come from the environment only. Never commit them or send them
    | to the browser. Base URL overrides are for proxies and tests; leave
    | them null to use the official endpoints for the active environment.
    |
    */

    'gateways' => [

        'khalti' => [
            'secret_key' => env('KHALTI_SECRET_KEY'),
            'website_url' => env('KHALTI_WEBSITE_URL'),
            'environment' => env('KHALTI_ENV'), // null: use nepal-pay.environment
            'base_url' => env('KHALTI_BASE_URL'),
        ],

        'esewa' => [
            // In sandbox, missing values fall back to the public UAT merchant (EPAYTEST).
            'product_code' => env('ESEWA_PRODUCT_CODE'),
            'secret_key' => env('ESEWA_SECRET_KEY'),
            'environment' => env('ESEWA_ENV'), // null: use nepal-pay.environment
            'form_url' => env('ESEWA_FORM_URL'),
            'status_url' => env('ESEWA_STATUS_URL'),
        ],

    ],

];

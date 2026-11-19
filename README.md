# Nepal Pay for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bijayx/nepal-pay-laravel.svg)](https://packagist.org/packages/bijayx/nepal-pay-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/bijayx/nepal-pay-laravel.svg)](https://packagist.org/packages/bijayx/nepal-pay-laravel)
[![CI](https://github.com/BijayX/nepal-pay-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/BijayX/nepal-pay-laravel/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/bijayx/nepal-pay-laravel/php.svg)](https://packagist.org/packages/bijayx/nepal-pay-laravel)
[![License: MIT](https://img.shields.io/packagist/l/bijayx/nepal-pay-laravel.svg)](LICENSE)

Khalti and eSewa payments for Laravel behind one typed API, with server-side verification,
signature checks, amount/reference matching and consistent exceptions.

- One interface for every gateway: `initiate()`, `verify()`, `lookup()`.
- Money is always an integer number of **paisa** (Rs 1 = 100 paisa). No floats.
- `verify()` always confirms with the gateway's server and rejects payments that do not match
  what you stored (`AMOUNT_MISMATCH`, `REFERENCE_MISMATCH`).
- eSewa callback signatures are checked in constant time; Khalti's HTTP 400 lookup responses
  for expired and canceled payments are handled.
- Laravel's HTTP client under the hood, so `Http::fake()` works in your tests.
- Safe retries for read-only status calls only. Payment creation is never retried.

This package follows the same contract as the Node.js package `@bijayx/nepal-pay`: statuses,
error codes and behaviour are identical.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [A complete checkout flow](#a-complete-checkout-flow)
- [API reference](#api-reference)
- [Payment statuses](#payment-statuses)
- [Error handling](#error-handling)
- [Events](#events)
- [Testing your application](#testing-your-application)
- [Sandbox credentials](#sandbox-credentials)
- [Security checklist](#security-checklist)
- [Custom gateways](#custom-gateways)
- [Customising the eSewa redirect page](#customising-the-esewa-redirect-page)

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13

## Installation

```bash
composer require bijayx/nepal-pay-laravel
```

The service provider and the `NepalPay` facade are registered automatically.

Publish the configuration file if you want to change defaults:

```bash
php artisan vendor:publish --tag=nepal-pay-config
```

## Configuration

Add your credentials to `.env`. Only configure the gateways you use.

```dotenv
NEPAL_PAY_GATEWAY=khalti          # default gateway: khalti | esewa
NEPAL_PAY_ENV=sandbox             # sandbox | production (default: sandbox)

KHALTI_SECRET_KEY=your-live-or-test-secret-key
KHALTI_WEBSITE_URL=https://your-shop.com.np

ESEWA_PRODUCT_CODE=your-merchant-code
ESEWA_SECRET_KEY=your-esewa-secret
```

| Key | Default | Notes |
|---|---|---|
| `default` | `khalti` | Gateway used by `NepalPay::initiate()` etc. and by `PaymentGateway` injection. |
| `environment` | `sandbox` | Production is never assumed. Each gateway may override it with `gateways.<name>.environment` (`KHALTI_ENV`, `ESEWA_ENV`). |
| `http.timeout_ms` | `15000` | Timeout for each gateway request. |
| `http.retries` | `2` | Extra attempts for read-only calls (Khalti lookup, eSewa status) on network errors, timeouts and 5xx. |
| `http.retry_delay_ms` | `200` | Backoff base: `delay * 2^attempt`. |
| `gateways.khalti.secret_key` | none | Required. |
| `gateways.khalti.website_url` | none | Required, absolute URL of your site. |
| `gateways.khalti.base_url` | official | Optional override (proxies, tests). |
| `gateways.esewa.product_code` | `EPAYTEST` in sandbox | Required in production. |
| `gateways.esewa.secret_key` | UAT secret in sandbox | Required in production. The UAT secret is only used with `EPAYTEST`. |
| `gateways.esewa.form_url`, `status_url` | official | Optional overrides. |

Configuration is validated when a gateway is first resolved. Missing or invalid values throw
`ConfigurationException` immediately instead of failing during a payment.

Endpoints used:

| | sandbox | production |
|---|---|---|
| Khalti API | `https://dev.khalti.com/api/v2/` | `https://khalti.com/api/v2/` |
| eSewa form | `https://rc-epay.esewa.com.np/api/epay/main/v2/form` | `https://epay.esewa.com.np/api/epay/main/v2/form` |
| eSewa status | `https://rc.esewa.com.np/api/epay/transaction/status/` | `https://esewa.com.np/api/epay/transaction/status/` |

## Quick start

```php
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Facades\NepalPay;

$payment = NepalPay::driver('khalti')->initiate([
    'amount' => 150000,                 // Rs 1,500 in paisa
    'order_id' => 'ORD-1001-a1',        // unique per payment attempt
    'order_name' => 'Charging session',
    'return_url' => route('payments.return', 'khalti'),
]);

// Persist $payment->reference and $payment->amount on your order, then:
return $payment->redirect();            // GatewayRedirectResponse (Responsable)
```

When the payer comes back to your return URL:

```php
$result = NepalPay::driver('esewa')->verify(
    $request->query(),
    new ExpectedPayment(amount: $order->amount, reference: $order->payment_reference),
);

if ($result->isPaid()) {
    // Mark the order paid (idempotently, see below).
}
```

`$payment->redirect()` returns a response that works for both gateways: a 302 redirect for
Khalti, and a small self-submitting HTML form for eSewa (eSewa requires a POST).

You can also type-hint the contract to receive the default gateway:

```php
use BijayX\NepalPay\Contracts\PaymentGateway;

public function __construct(private PaymentGateway $gateway) {}
```

## A complete checkout flow

Migration columns (amounts in paisa):

```php
$table->unsignedBigInteger('amount');                 // what the customer owes, in paisa
$table->string('payment_gateway')->nullable();
$table->string('payment_reference')->nullable()->unique();
$table->unsignedBigInteger('payment_amount')->nullable();
$table->string('payment_transaction_id')->nullable();
$table->timestamp('paid_at')->nullable();
```

Routes. Keep the order in the path: eSewa appends `?data=...` to the return URL and does not
handle an existing query string.

```php
Route::post('/orders/{order}/pay/{gateway}', [PaymentController::class, 'store'])
    ->whereIn('gateway', ['khalti', 'esewa'])
    ->name('payments.store');

Route::get('/payments/{gateway}/return/{order}', [PaymentController::class, 'callback'])
    ->whereIn('gateway', ['khalti', 'esewa'])
    ->name('payments.return');
```

Controller:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Exceptions\InvalidSignatureException;
use BijayX\NepalPay\Exceptions\NepalPayException;
use BijayX\NepalPay\Exceptions\PaymentMismatchException;
use BijayX\NepalPay\Facades\NepalPay;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentController
{
    public function store(Order $order, string $gateway): Responsable|RedirectResponse
    {
        abort_if($order->paid_at !== null, 409, 'This order is already paid.');

        try {
            $payment = NepalPay::driver($gateway)->initiate([
                'amount' => $order->amount,
                // A fresh id for every attempt: both gateways reject reused ids.
                'order_id' => $order->id.'-'.Str::lower(Str::random(8)),
                'order_name' => "Order #{$order->id}",
                'return_url' => route('payments.return', [$gateway, $order]),
                'customer' => ['name' => $order->customer_name, 'phone' => $order->customer_phone],
            ]);
        } catch (NepalPayException $e) {
            report($e);

            return back()->with('error', 'We could not start the payment. Please try again.');
        }

        // Store what verify() needs BEFORE redirecting.
        $order->forceFill([
            'payment_gateway' => $gateway,
            'payment_reference' => $payment->reference,
            'payment_amount' => $payment->amount,
        ])->save();

        return $payment->redirect();
    }

    public function callback(Request $request, string $gateway, Order $order): RedirectResponse
    {
        if ($order->payment_gateway !== $gateway || $order->payment_reference === null) {
            abort(404);
        }

        try {
            $result = NepalPay::driver($gateway)->verify(
                $request->query(),
                new ExpectedPayment(amount: $order->payment_amount, reference: $order->payment_reference),
            );
        } catch (PaymentMismatchException|InvalidSignatureException $e) {
            report($e); // possible tampering: investigate, do not fulfil

            return to_route('orders.show', $order)->with('error', 'The payment could not be verified.');
        } catch (NepalPayException $e) {
            report($e); // network/gateway problem: the payment may still complete

            return to_route('orders.show', $order)->with('error', 'We could not confirm the payment yet.');
        }

        if (! $result->isPaid()) {
            return to_route('orders.show', $order)->with('error', "Payment {$result->status->value}.");
        }

        // Callbacks can arrive more than once: lock the row and mark paid only once.
        DB::transaction(function () use ($order, $result): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->paid_at !== null) {
                return;
            }

            $locked->forceFill([
                'paid_at' => now(),
                'payment_transaction_id' => $result->transactionId,
            ])->save();

            // Dispatch fulfilment here (jobs, emails, ...).
        });

        return to_route('orders.show', $order)->with('status', 'Payment received. Thank you!');
    }
}
```

If the payer never returns (closed the tab, lost connection), reconcile pending orders from a
scheduled job with `lookup()`:

```php
$result = NepalPay::driver($order->payment_gateway)
    ->lookup($order->payment_reference, $order->payment_amount);
```

## API reference

### `initiate(PaymentRequest|array $request): InitiatedPayment`

Accepts an array with snake_case keys or a `PaymentRequest` object. Input is validated before
any network call; invalid input throws `ValidationException` with all field errors.

| Key | Type | Rules |
|---|---|---|
| `amount` | int | Total in paisa, greater than 0. Khalti requires at least 1000 (Rs 10). |
| `order_id` | string | Unique per payment attempt, 1-64 chars, `A-Z a-z 0-9 -`. |
| `order_name` | string | Required, max 255 characters. |
| `return_url` | string | Absolute http(s) URL. |
| `failure_url` | string, optional | eSewa only. Defaults to `return_url`. |
| `customer` | array, optional | `name`, `email`, `phone`. Sent to Khalti; ignored by eSewa. |
| `breakdown` | array, optional | `tax`, `service_charge`, `delivery_charge` in paisa (>= 0). Their sum must be less than `amount`. |
| `metadata` | array, optional | String values; keys match `^[a-z0-9_]+$`. Khalti: `merchant_<key>`. eSewa: `properties[<key>]`, max 3 keys. |

```php
use BijayX\NepalPay\Data\{Breakdown, Customer, PaymentRequest};

$request = new PaymentRequest(
    amount: 113000,
    orderId: 'ORD-1002-b7',
    orderName: 'Charging session',
    returnUrl: route('payments.return', ['esewa', $order]),
    customer: new Customer(name: 'Ram Bahadur', phone: '9800000001'),
    breakdown: new Breakdown(tax: 13000),
);
```

`InitiatedPayment`:

| Property | Description |
|---|---|
| `gateway` | `"khalti"` or `"esewa"` |
| `reference` | Khalti `pidx`, or eSewa `transaction_uuid` (equal to `order_id`). **Store it.** |
| `orderId`, `amount` | As requested (paisa). |
| `redirect` | `RedirectInstruction` with `method` (`GET`/`POST`), `url`, `fields` (eSewa form fields). |
| `expiresAt` | `CarbonImmutable` for Khalti, `null` for eSewa. |
| `raw` | Khalti response body, or eSewa form fields. |
| `redirect()` | Method returning a `GatewayRedirectResponse` to return from a controller. |

Use `$payment->redirect` (the instruction) if you build the redirect yourself, for example
in an SPA or mobile app.

eSewa's `initiate()` makes no HTTP call: it signs the form fields locally.

### `verify(array $callback, ?ExpectedPayment $expected = null): PaymentResult`

Pass the raw query parameters of the return request and what you stored at initiate time.

1. The gateway's server is always asked for the final status (Khalti lookup, eSewa status API).
   Status values in the callback are never trusted.
2. eSewa callbacks: the `data` parameter is decoded and its HMAC signature verified in
   constant time, and its `product_code` must match yours, otherwise `InvalidSignatureException`.
   If `data` is missing (for example on the failure URL), `$expected` with a reference is required.
3. Khalti callbacks: `pidx` is taken from the callback, or from `$expected->reference`.
4. A reference that differs from `$expected->reference` throws `PaymentMismatchException`
   (`REFERENCE_MISMATCH`).
5. A completed payment whose amount differs from `$expected->amount` throws
   `PaymentMismatchException` (`AMOUNT_MISMATCH`). A mismatched payment is never returned as paid.
6. Non-completed statuses (pending, canceled, expired, ...) are returned, not thrown.

`$expected` is optional, but you should always pass it.

### `lookup(string $reference, ?int $amount = null): PaymentResult`

Fetches the current status from the gateway. eSewa requires `$amount` (paisa). Lookups are
read-only and are retried on network errors, timeouts and 5xx responses.

### `PaymentResult`

| Member | Description |
|---|---|
| `gateway` | `"khalti"` or `"esewa"` |
| `status` | `PaymentStatus` enum |
| `isPaid()` | `true` only when `status` is `completed` |
| `reference` | Khalti `pidx` / eSewa `transaction_uuid` |
| `transactionId` | Khalti `transaction_id` / eSewa `ref_id`, or `null` |
| `amount` | Amount confirmed by the gateway, in paisa |
| `fee` | Khalti fee in paisa; `null` for eSewa |
| `raw` | Gateway response body |
| `toArray()` | Array representation with snake_case keys |

### Money helpers

```php
use BijayX\NepalPay\Support\Money;

Money::paisaToRupeeString(11050); // "110.50"
Money::paisaToRupeeString(11000); // "110"
Money::parseRupees('1,000.0');    // 100000
```

## Payment statuses

`BijayX\NepalPay\Enums\PaymentStatus`:

| Value | Khalti | eSewa | Meaning |
|---|---|---|---|
| `completed` | Completed | COMPLETE | Paid. The only status to fulfil on. |
| `pending` | Pending | PENDING | Not finished yet. Check again later. |
| `initiated` | Initiated | | Payment created, payer has not paid. |
| `refunded` | Refunded | FULL_REFUND | Fully refunded. |
| `partially_refunded` | Partially Refunded | PARTIAL_REFUND | Partly refunded. |
| `expired` | Expired | | Payment link expired. |
| `canceled` | User canceled, Canceled | CANCELED | Canceled by the payer. |
| `failed` | Failed | | Payment failed. |
| `ambiguous` | | AMBIGUOUS | eSewa could not determine the state. Check again later. |
| `not_found` | Not found. | NOT_FOUND | No payment for this reference (and amount, for eSewa). |
| `unknown` | anything else | anything else | Unrecognised status. Treat as not paid. |

## Error handling

All exceptions extend `BijayX\NepalPay\Exceptions\NepalPayException`, which provides
`errorCode()`, `gateway()`, `details()` and `context()` (merged into Laravel's log context
automatically). Messages and details never contain secret keys.

| Exception | `errorCode()` | Thrown when | Suggested handling |
|---|---|---|---|
| `ConfigurationException` | `CONFIGURATION_ERROR` | Missing/invalid config, unknown gateway | Fix configuration. |
| `ValidationException` | `VALIDATION_ERROR` | Invalid input, or field errors returned by the gateway. `fields()` returns `array<string, list<string>>`. | Fix the input; show a generic error to the user. |
| `AuthenticationException` | `AUTHENTICATION_FAILED` | Gateway returned 401/403 (wrong key, or sandbox key in production) | Check keys and environment. |
| `GatewayException` | `GATEWAY_ERROR` | Unexpected status, 5xx, malformed body. `statusCode()`, `responseBody()`. | Retry later. |
| `NetworkException` | `NETWORK_ERROR`, `TIMEOUT` | Connection failure or timeout | Retry later; for verify, reconcile with `lookup()`. |
| `InvalidCallbackException` | `INVALID_CALLBACK` | Callback missing data or garbled | Do not fulfil. |
| `InvalidSignatureException` | `INVALID_SIGNATURE` | eSewa signature mismatch or different product code | Treat as tampering. Do not fulfil. |
| `PaymentMismatchException` | `AMOUNT_MISMATCH`, `REFERENCE_MISMATCH` | Gateway-confirmed payment does not match your order. `expected()`, `actual()`. | Treat as fraud. Do not fulfil. |

```php
use BijayX\NepalPay\Exceptions\NepalPayException;
use BijayX\NepalPay\Exceptions\ValidationException;

try {
    $payment = NepalPay::driver('khalti')->initiate($data);
} catch (ValidationException $e) {
    return back()->withErrors($e->fields());
} catch (NepalPayException $e) {
    report($e);           // context() adds nepal_pay_code, gateway and details to the log
    return back()->with('error', 'Payment is unavailable right now.');
}
```

## Events

| Event | Payload | Dispatched |
|---|---|---|
| `BijayX\NepalPay\Events\PaymentInitiated` | `$event->payment` (`InitiatedPayment`) | After `initiate()` succeeds |
| `BijayX\NepalPay\Events\PaymentVerified` | `$event->result` (`PaymentResult`) | After `verify()` confirms a payment, whatever its status. Not dispatched when verification throws. |

Check `$event->result->isPaid()` in listeners.

## Testing your application

No real requests are needed. Fake the gateway endpoints with `Http::fake()`.

Khalti:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'dev.khalti.com/api/v2/epayment/initiate/' => Http::response([
        'pidx' => 'test-pidx',
        'payment_url' => 'https://test-pay.khalti.com/?pidx=test-pidx',
        'expires_at' => now()->addMinutes(30)->toIso8601String(),
        'expires_in' => 1800,
    ]),
    'dev.khalti.com/api/v2/epayment/lookup/' => Http::response([
        'pidx' => 'test-pidx',
        'total_amount' => 150000,
        'status' => 'Completed',
        'transaction_id' => 'TXN-1',
        'fee' => 0,
        'refunded' => false,
    ]),
]);

$this->get(route('payments.return', ['khalti', $order]).'?pidx=test-pidx')
    ->assertRedirect(route('orders.show', $order));

expect($order->fresh()->paid_at)->not->toBeNull();
```

eSewa: sign a callback with the sandbox secret and fake the status API.

```php
use BijayX\NepalPay\Gateways\Esewa\EsewaConfig;
use BijayX\NepalPay\Gateways\Esewa\EsewaSignature;

$payload = [
    'transaction_code' => '000AE01',
    'status' => 'COMPLETE',
    'total_amount' => '1,500.0',
    'transaction_uuid' => $order->payment_reference,
    'product_code' => 'EPAYTEST',
    'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
];
$payload['signature'] = (new EsewaSignature(EsewaConfig::SANDBOX_SECRET_KEY))
    ->sign(EsewaSignature::message($payload, $payload['signed_field_names']));

Http::fake([
    'rc.esewa.com.np/api/epay/transaction/status/*' => Http::response([
        'product_code' => 'EPAYTEST',
        'transaction_uuid' => $order->payment_reference,
        'total_amount' => 1500.0,
        'status' => 'COMPLETE',
        'ref_id' => '000AE01',
    ]),
]);

$this->get(route('payments.return', ['esewa', $order]).'?data='.urlencode(base64_encode(json_encode($payload))));
```

Retries use `Illuminate\Support\Sleep`, so call `Sleep::fake()` to keep retry tests instant.
Use `Http::preventStrayRequests()` to make sure nothing reaches a real gateway.

## Sandbox credentials

Khalti sandbox:

- Merchant signup: https://test-admin.khalti.com/#/join/merchant (OTP `987654`). Use the test
  secret key from the merchant dashboard as `KHALTI_SECRET_KEY`.
- Payer Khalti IDs: `9800000000` to `9800000005`, MPIN `1111`, OTP `987654`.

eSewa UAT:

- Product code `EPAYTEST`, secret key `8gBm/:&EnhH.1/q` (used automatically in sandbox when
  nothing is configured).
- Payer eSewa IDs: `9711111111`, `9711111112`, `9711111113`; password `Nepal@123`;
  MPIN `1122`; token `123456`.

## Security checklist

- **Verify server-side.** Never mark an order paid from the return URL alone. `verify()`
  always confirms with the gateway's server.
- **Persist `reference` and `amount` at initiate**, and pass them as `ExpectedPayment` to
  `verify()`. This blocks replayed callbacks from other orders and amount tampering.
- **Make "mark paid" idempotent.** Callbacks can repeat. Use a row lock (`lockForUpdate()`) in a
  transaction and/or a unique constraint, and skip orders that are already paid.
- **Use a fresh `order_id` per attempt.** Both gateways require uniqueness; reusing ids breaks
  retries after a failed or canceled payment.
- **Keep secrets in the environment** only. Never commit them or send them to the browser.
  The package keeps secrets out of exception messages, logs and `dd()` output.
- **Treat `PaymentMismatchException` and `InvalidSignatureException` as fraud signals.** Log
  them and do not fulfil the order.
- Set `NEPAL_PAY_ENV=production` explicitly in production; the package never assumes it.

## Custom gateways

Register any gateway that implements `BijayX\NepalPay\Contracts\PaymentGateway`. Extending
`AbstractGateway` gives you `ExpectedPayment` checks and events for free.

```php
use BijayX\NepalPay\Data\{ExpectedPayment, InitiatedPayment, PaymentRequest, PaymentResult};
use BijayX\NepalPay\Gateways\AbstractGateway;
use BijayX\NepalPay\Http\GatewayHttpClient;

final class FonepayGateway extends AbstractGateway
{
    public function __construct(private GatewayHttpClient $http, array $config, $events = null)
    {
        parent::__construct($events);
    }

    public function name(): string
    {
        return 'fonepay';
    }

    protected function createPayment(PaymentRequest $request): InitiatedPayment { /* ... */ }

    protected function confirm(array $callback, ?ExpectedPayment $expected): PaymentResult { /* ... */ }

    public function lookup(string $reference, ?int $amount = null): PaymentResult { /* ... */ }
}
```

Register it in a service provider's `boot()` method:

```php
use BijayX\NepalPay\Facades\NepalPay;
use BijayX\NepalPay\NepalPayManager;

NepalPay::extend('fonepay', function ($app) {
    $manager = $app->make(NepalPayManager::class);

    return new FonepayGateway(
        $manager->httpClient('fonepay'),       // shares timeout and retry settings
        $manager->gatewayConfig('fonepay'),    // reads nepal-pay.gateways.fonepay
        $app['events'],
    );
});

NepalPay::driver('fonepay')->initiate([...]);
```

Unknown gateway names throw `ConfigurationException`.

## Customising the eSewa redirect page

The auto-submit page has no external assets and works without JavaScript (a "Continue"
button is shown). To change it:

```bash
php artisan vendor:publish --tag=nepal-pay-views
```

Then edit `resources/views/vendor/nepal-pay/redirect.blade.php`. If your site uses a
Content Security Policy with nonces, pass the nonce:

```php
return $payment->redirect()->withNonce(Vite::cspNonce());
```

## Development

```bash
composer install
composer test       # Pest
composer lint       # Pint
composer analyse    # PHPStan (Larastan, level 8)
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues as described in
[SECURITY.md](SECURITY.md), not in public issues.

## License

MIT. See [LICENSE](LICENSE).

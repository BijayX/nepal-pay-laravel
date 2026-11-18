<?php

declare(strict_types=1);

use BijayX\NepalPay\Data\Breakdown;
use BijayX\NepalPay\Data\Customer;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Data\PaymentRequest;
use BijayX\NepalPay\Data\PaymentResult;
use BijayX\NepalPay\Data\RedirectInstruction;
use BijayX\NepalPay\Enums\Environment;
use BijayX\NepalPay\Enums\PaymentStatus;
use BijayX\NepalPay\Exceptions\ConfigurationException;
use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Http\Responses\GatewayRedirectResponse;

function validRequest(array $overrides = []): array
{
    return array_merge([
        'amount' => 150000,
        'order_id' => 'ORD-1001-a1',
        'order_name' => 'Charging session',
        'return_url' => 'https://shop.test/pay/return',
    ], $overrides);
}

function validationFields(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->fields();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

it('builds a payment request from snake_case input', function () {
    $request = PaymentRequest::fromArray(validRequest([
        'failure_url' => 'https://shop.test/pay/failed',
        'customer' => ['name' => ' Ram ', 'email' => 'ram@example.com', 'phone' => 9800000001],
        'breakdown' => ['tax' => 10000, 'service_charge' => 500, 'delivery_charge' => 0],
        'metadata' => ['station' => 'ktm-01'],
    ]));

    expect($request->amount)->toBe(150000)
        ->and($request->orderId)->toBe('ORD-1001-a1')
        ->and($request->failureUrlOrReturnUrl())->toBe('https://shop.test/pay/failed')
        ->and($request->customer)->toEqual(new Customer('Ram', 'ram@example.com', '9800000001'))
        ->and($request->breakdown)->toEqual(new Breakdown(10000, 500, 0))
        ->and($request->breakdown?->total())->toBe(10500)
        ->and($request->metadata)->toBe(['station' => 'ktm-01']);
});

it('defaults the failure url to the return url', function () {
    expect(PaymentRequest::fromArray(validRequest())->failureUrlOrReturnUrl())->toBe('https://shop.test/pay/return');
});

it('validates payment request fields', function (array $overrides, string $field) {
    expect(validationFields(fn () => PaymentRequest::fromArray(validRequest($overrides))))->toHaveKey($field);
})->with([
    'float amount' => [['amount' => 1500.5], 'amount'],
    'string amount' => [['amount' => '150000'], 'amount'],
    'zero amount' => [['amount' => 0], 'amount'],
    'negative amount' => [['amount' => -5], 'amount'],
    'order id with space' => [['order_id' => 'ORD 1'], 'order_id'],
    'order id with underscore' => [['order_id' => 'ORD_1'], 'order_id'],
    'order id too long' => [['order_id' => str_repeat('a', 65)], 'order_id'],
    'empty order id' => [['order_id' => ''], 'order_id'],
    'blank order name' => [['order_name' => '   '], 'order_name'],
    'long order name' => [['order_name' => str_repeat('a', 256)], 'order_name'],
    'relative return url' => [['return_url' => '/pay/return'], 'return_url'],
    'ftp return url' => [['return_url' => 'ftp://shop.test/x'], 'return_url'],
    'bad failure url' => [['failure_url' => 'nope'], 'failure_url'],
    'breakdown equals amount' => [['breakdown' => ['tax' => 150000]], 'breakdown'],
    'negative breakdown' => [['breakdown' => ['tax' => -1]], 'breakdown.tax'],
    'float breakdown' => [['breakdown' => ['tax' => 1.5]], 'breakdown.tax'],
    'unknown breakdown key' => [['breakdown' => ['vat' => 1]], 'breakdown.vat'],
    'bad metadata key' => [['metadata' => ['Station' => 'x']], 'metadata'],
    'non-string metadata' => [['metadata' => ['station' => 5]], 'metadata.station'],
    'unknown customer key' => [['customer' => ['address' => 'x']], 'customer.address'],
    'unknown key' => [['orderId' => 'x'], 'orderId'],
]);

it('reports every missing required field at once', function () {
    $fields = validationFields(fn () => PaymentRequest::fromArray([]));

    expect(array_keys($fields))->toBe(['amount', 'order_id', 'order_name', 'return_url']);
});

it('validates the constructor too', function () {
    new PaymentRequest(amount: 0, orderId: 'x', orderName: 'y', returnUrl: 'https://shop.test');
})->throws(ValidationException::class);

it('accepts 64 character order ids', function () {
    expect(PaymentRequest::fromArray(validRequest(['order_id' => str_repeat('a', 64)]))->orderId)->toHaveLength(64);
});

it('validates expected payments', function () {
    expect(new ExpectedPayment(1000, 'pidx'))->amount->toBe(1000);

    expect(fn () => new ExpectedPayment(0))->toThrow(ValidationException::class)
        ->and(fn () => new ExpectedPayment(1000, ' '))->toThrow(ValidationException::class);
});

it('exposes payment results', function () {
    $result = new PaymentResult('khalti', PaymentStatus::Completed, 'pidx', 'txn', 110000, 3300, ['a' => 1]);

    expect($result->isPaid())->toBeTrue()
        ->and($result->toArray())->toBe([
            'gateway' => 'khalti',
            'status' => 'completed',
            'is_paid' => true,
            'reference' => 'pidx',
            'transaction_id' => 'txn',
            'amount' => 110000,
            'fee' => 3300,
            'raw' => ['a' => 1],
        ])
        ->and((new PaymentResult('esewa', PaymentStatus::Pending, 'x', null, 1))->isPaid())->toBeFalse();
});

it('exposes initiated payments and redirect instructions', function () {
    $payment = new InitiatedPayment('esewa', 'ORD-1', 'ORD-1', 1000, RedirectInstruction::post('https://pay.test', ['a' => 'b']));

    expect($payment->redirect)->toBeInstanceOf(RedirectInstruction::class)
        ->and($payment->redirect->isPost())->toBeTrue()
        ->and($payment->redirect())->toBeInstanceOf(GatewayRedirectResponse::class)
        ->and($payment->toArray()['redirect'])->toBe(['method' => 'POST', 'url' => 'https://pay.test', 'fields' => ['a' => 'b']])
        ->and(RedirectInstruction::get('https://pay.test')->isPost())->toBeFalse();
});

it('resolves environments without guessing production', function () {
    expect(Environment::fromConfig(null))->toBe(Environment::Sandbox)
        ->and(Environment::fromConfig(''))->toBe(Environment::Sandbox)
        ->and(Environment::fromConfig(' Production '))->toBe(Environment::Production)
        ->and(fn () => Environment::fromConfig('live'))->toThrow(ConfigurationException::class);
});

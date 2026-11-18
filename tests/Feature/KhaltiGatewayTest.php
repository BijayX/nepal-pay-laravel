<?php

declare(strict_types=1);

use BijayX\NepalPay\Contracts\PaymentGateway;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Enums\PaymentStatus;
use BijayX\NepalPay\Events\PaymentInitiated;
use BijayX\NepalPay\Events\PaymentVerified;
use BijayX\NepalPay\Exceptions\AuthenticationException;
use BijayX\NepalPay\Exceptions\GatewayException;
use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use BijayX\NepalPay\Exceptions\NetworkException;
use BijayX\NepalPay\Exceptions\PaymentMismatchException;
use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Facades\NepalPay;
use BijayX\NepalPay\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const KHALTI_INITIATE = 'https://dev.khalti.com/api/v2/epayment/initiate/';
const KHALTI_LOOKUP = 'https://dev.khalti.com/api/v2/epayment/lookup/';

beforeEach(function () {
    $this->fixture = gatewayFixture('khalti');
    $this->pidx = $this->fixture['initiateResponse']['pidx'];
});

function khalti(): PaymentGateway
{
    return NepalPay::driver('khalti');
}

function fakeKhaltiLookup(string $case): void
{
    $lookup = gatewayFixture('khalti')['lookup'][$case];

    Http::fake([KHALTI_LOOKUP => Http::response($lookup['body'], $lookup['httpStatus'])]);
}

describe('initiate', function () {
    it('creates a payment and returns a GET redirect', function () {
        Event::fake([PaymentInitiated::class]);
        Http::fake([KHALTI_INITIATE => Http::response($this->fixture['initiateResponse'])]);

        $payment = khalti()->initiate(snakeInput($this->fixture['initiateRequest']));

        expect($payment)->toBeInstanceOf(InitiatedPayment::class)
            ->and($payment->gateway)->toBe('khalti')
            ->and($payment->reference)->toBe($this->pidx)
            ->and($payment->orderId)->toBe('ORD-1001-a1')
            ->and($payment->amount)->toBe(110000)
            ->and($payment->redirect->method)->toBe('GET')
            ->and($payment->redirect->url)->toBe($this->fixture['initiateResponse']['payment_url'])
            ->and($payment->expiresAt?->toIso8601String())->toBe('2026-11-18T16:26:16+05:45')
            ->and($payment->raw)->toBe($this->fixture['initiateResponse']);

        Http::assertSent(function (Request $request) {
            return $request->url() === KHALTI_INITIATE
                && $request->method() === 'POST'
                && $request->header('Authorization') === ['Key '.TestCase::KHALTI_SECRET]
                && $request->isJson()
                && $request->data() === [
                    'return_url' => 'https://shop.test/pay/return',
                    'website_url' => 'https://shop.test',
                    'amount' => 110000,
                    'purchase_order_id' => 'ORD-1001-a1',
                    'purchase_order_name' => 'Charging session',
                    'customer_info' => ['name' => 'Ram Bahadur', 'email' => 'ram@example.com', 'phone' => '9800000001'],
                    'merchant_station' => 'ktm-01',
                ];
        });

        Event::assertDispatched(PaymentInitiated::class, fn (PaymentInitiated $e) => $e->payment === $payment);
    });

    it('sends an amount breakdown that sums to the amount and omits zero rows', function () {
        Http::fake([KHALTI_INITIATE => Http::response($this->fixture['initiateResponse'])]);

        khalti()->initiate([
            'amount' => 113000,
            'order_id' => 'ORD-2',
            'order_name' => 'Charging session',
            'return_url' => 'https://shop.test/pay/return',
            'breakdown' => ['tax' => 13000, 'delivery_charge' => 0, 'service_charge' => 0],
        ]);

        Http::assertSent(fn (Request $request) => $request['amount_breakdown'] === [
            ['label' => 'Amount', 'amount' => 100000],
            ['label' => 'Tax', 'amount' => 13000],
        ]);
    });

    it('enforces the Khalti minimum locally', function () {
        Http::fake();

        try {
            khalti()->initiate(['amount' => 999, 'order_id' => 'A1', 'order_name' => 'x', 'return_url' => 'https://shop.test']);
            $this->fail('No exception thrown.');
        } catch (ValidationException $e) {
            expect($e->fields())->toHaveKey('amount')->and($e->gateway())->toBe('khalti');
        }

        Http::assertNothingSent();
    });

    it('maps Khalti field errors to ValidationException', function () {
        Http::fake([KHALTI_INITIATE => Http::response($this->fixture['validationError'], 400)]);

        try {
            khalti()->initiate(snakeInput($this->fixture['initiateRequest']));
            $this->fail('No exception thrown.');
        } catch (ValidationException $e) {
            expect($e->errorCode())->toBe('VALIDATION_ERROR')
                ->and($e->gateway())->toBe('khalti')
                ->and($e->fields())->toBe(['amount' => ['Amount should be greater than Rs. 10, that is 1000 paisa.']])
                ->and($e->getMessage())->toContain('amount: Amount should be greater');
        }
    });

    it('maps an invalid key to AuthenticationException without leaking it', function () {
        Http::fake([KHALTI_INITIATE => Http::response($this->fixture['invalidToken'], 401)]);

        try {
            khalti()->initiate(snakeInput($this->fixture['initiateRequest']));
            $this->fail('No exception thrown.');
        } catch (AuthenticationException $e) {
            expect($e->errorCode())->toBe('AUTHENTICATION_FAILED')
                ->and($e->statusCode())->toBe(401)
                ->and($e->getMessage())->toContain('Invalid token.')
                ->and(json_encode([$e->getMessage(), $e->details(), $e->context()]))->not->toContain(TestCase::KHALTI_SECRET);
        }
    });

    it('never retries initiate', function () {
        Http::fake([KHALTI_INITIATE => Http::response('<html>Bad gateway</html>', 502)]);

        try {
            khalti()->initiate(snakeInput($this->fixture['initiateRequest']));
            $this->fail('No exception thrown.');
        } catch (GatewayException $e) {
            expect($e->statusCode())->toBe(502)
                ->and($e->responseBody())->toBe('<html>Bad gateway</html>');
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });

    it('rejects a success response without pidx', function () {
        Http::fake([KHALTI_INITIATE => Http::response(['payment_url' => 'https://x.test'])]);

        khalti()->initiate(snakeInput($this->fixture['initiateRequest']));
    })->throws(GatewayException::class, 'without pidx');

    it('maps a timeout to NetworkException TIMEOUT', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds'));

        try {
            khalti()->initiate(snakeInput($this->fixture['initiateRequest']));
            $this->fail('No exception thrown.');
        } catch (NetworkException $e) {
            expect($e->errorCode())->toBe('TIMEOUT')->and($e->isTimeout())->toBeTrue();
        }

        Http::assertSentCount(0);
    });
});

describe('lookup', function () {
    it('parses lookup responses, including HTTP 400 bodies', function (string $case, PaymentStatus $status, ?string $transactionId, ?int $fee) {
        fakeKhaltiLookup($case);

        $result = khalti()->lookup($this->pidx);

        expect($result->status)->toBe($status)
            ->and($result->isPaid())->toBe($status === PaymentStatus::Completed)
            ->and($result->reference)->toBe($this->pidx)
            ->and($result->transactionId)->toBe($transactionId)
            ->and($result->amount)->toBe(110000)
            ->and($result->fee)->toBe($fee)
            ->and($result->gateway)->toBe('khalti');

        Http::assertSent(fn (Request $r) => $r->url() === KHALTI_LOOKUP && $r->data() === ['pidx' => $this->pidx]);
    })->with([
        'completed' => ['completed', PaymentStatus::Completed, 'GFq9PFS7b2iYvL8Lir9oXe', 3300],
        'pending' => ['pending', PaymentStatus::Pending, null, 0],
        'initiated' => ['initiated', PaymentStatus::Initiated, null, 0],
        'refunded' => ['refunded', PaymentStatus::Refunded, 'GFq9PFS7b2iYvL8Lir9oXe', 0],
        'expired (HTTP 400)' => ['expired', PaymentStatus::Expired, null, 0],
        'user canceled (HTTP 400)' => ['userCanceled', PaymentStatus::Canceled, null, 0],
    ]);

    it('returns not_found for an unknown pidx', function () {
        fakeKhaltiLookup('notFound');

        $result = khalti()->lookup('missing', 5000);

        expect($result->status)->toBe(PaymentStatus::NotFound)
            ->and($result->reference)->toBe('missing')
            ->and($result->amount)->toBe(5000);
    });

    it('retries on 5xx with exponential backoff', function () {
        Http::fake([KHALTI_LOOKUP => Http::sequence()
            ->push('upstream error', 503)
            ->push('upstream error', 502)
            ->push($this->fixture['lookup']['completed']['body']),
        ]);

        expect(khalti()->lookup($this->pidx)->isPaid())->toBeTrue();

        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(400)->milliseconds()]);
    });

    it('retries connection errors and gives up with NetworkException', function () {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 6: Could not resolve host: dev.khalti.com');
        });

        try {
            khalti()->lookup($this->pidx);
            $this->fail('No exception thrown.');
        } catch (NetworkException $e) {
            expect($e->errorCode())->toBe('NETWORK_ERROR')
                ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
        }

        expect($attempts)->toBe(3);
        Sleep::assertSleptTimes(2);
    });

    it('surfaces persistent 5xx as GatewayException', function () {
        Http::fake([KHALTI_LOOKUP => Http::response(['detail' => 'down'], 500)]);

        expect(fn () => khalti()->lookup($this->pidx))->toThrow(GatewayException::class);
        Http::assertSentCount(3);
    });

    it('honours the configured retry count', function () {
        config(['nepal-pay.http.retries' => 0]);
        Http::fake([KHALTI_LOOKUP => Http::response('', 503)]);

        expect(fn () => khalti()->lookup($this->pidx))->toThrow(GatewayException::class);
        Http::assertSentCount(1);
    });

    it('does not retry 4xx errors', function () {
        Http::fake([KHALTI_LOOKUP => Http::response($this->fixture['invalidToken'], 401)]);

        expect(fn () => khalti()->lookup($this->pidx))->toThrow(AuthenticationException::class);
        Http::assertSentCount(1);
    });

    it('requires a pidx', function () {
        khalti()->lookup('  ');
    })->throws(ValidationException::class);
});

describe('verify', function () {
    it('confirms a completed payment with the server', function () {
        Event::fake([PaymentVerified::class]);
        fakeKhaltiLookup('completed');

        $result = khalti()->verify(
            $this->fixture['callbackCompleted'],
            new ExpectedPayment(amount: 110000, reference: $this->pidx),
        );

        expect($result->isPaid())->toBeTrue()
            ->and($result->transactionId)->toBe('GFq9PFS7b2iYvL8Lir9oXe');

        Http::assertSentCount(1);
        Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $e) => $e->result === $result);
    });

    it('trusts the server, not the callback status', function () {
        fakeKhaltiLookup('pending');

        $result = khalti()->verify($this->fixture['callbackCompleted'], new ExpectedPayment(110000, $this->pidx));

        expect($result->status)->toBe(PaymentStatus::Pending)->and($result->isPaid())->toBeFalse();
    });

    it('returns canceled payments instead of throwing', function () {
        Event::fake([PaymentVerified::class]);
        fakeKhaltiLookup('userCanceled');

        $result = khalti()->verify($this->fixture['callbackCanceled'], new ExpectedPayment(110000, $this->pidx));

        expect($result->status)->toBe(PaymentStatus::Canceled);
        Event::assertDispatched(PaymentVerified::class);
    });

    it('rejects a completed payment for a different amount', function () {
        Event::fake([PaymentVerified::class]);
        fakeKhaltiLookup('completed');

        try {
            khalti()->verify($this->fixture['callbackCompleted'], new ExpectedPayment(150000, $this->pidx));
            $this->fail('No exception thrown.');
        } catch (PaymentMismatchException $e) {
            expect($e->errorCode())->toBe('AMOUNT_MISMATCH')
                ->and($e->expected())->toBe(150000)
                ->and($e->actual())->toBe(110000);
        }

        Event::assertNotDispatched(PaymentVerified::class);
    });

    it('rejects a callback for another payment before calling Khalti', function () {
        Http::fake();

        try {
            khalti()->verify($this->fixture['callbackCompleted'], new ExpectedPayment(110000, 'another-pidx'));
            $this->fail('No exception thrown.');
        } catch (PaymentMismatchException $e) {
            expect($e->errorCode())->toBe('REFERENCE_MISMATCH')
                ->and($e->expected())->toBe('another-pidx')
                ->and($e->actual())->toBe($this->pidx);
        }

        Http::assertNothingSent();
    });

    it('falls back to the expected reference when the callback has no pidx', function () {
        fakeKhaltiLookup('expired');

        $result = khalti()->verify([], new ExpectedPayment(110000, $this->pidx));

        expect($result->status)->toBe(PaymentStatus::Expired);
    });

    it('requires a pidx', function (array $callback) {
        Http::fake();

        khalti()->verify($callback);
    })->throws(InvalidCallbackException::class)->with([
        'empty' => [[]],
        'blank' => [['pidx' => '']],
        'array' => [['pidx' => ['a', 'b']]],
    ]);
});

<?php

declare(strict_types=1);

use BijayX\NepalPay\Contracts\PaymentGateway;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Enums\PaymentStatus;
use BijayX\NepalPay\Events\PaymentInitiated;
use BijayX\NepalPay\Events\PaymentVerified;
use BijayX\NepalPay\Exceptions\GatewayException;
use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use BijayX\NepalPay\Exceptions\InvalidSignatureException;
use BijayX\NepalPay\Exceptions\PaymentMismatchException;
use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Facades\NepalPay;
use BijayX\NepalPay\Gateways\Esewa\EsewaSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const ESEWA_FORM = 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';
const ESEWA_STATUS = 'https://rc.esewa.com.np/api/epay/transaction/status/';

beforeEach(function () {
    $this->fixture = gatewayFixture('esewa');
});

function esewa(): PaymentGateway
{
    return NepalPay::driver('esewa');
}

function fakeEsewaStatus(string $case): void
{
    Http::fake([ESEWA_STATUS.'*' => Http::response(gatewayFixture('esewa')['statusResponses'][$case])]);
}

/**
 * Build a validly signed callback "data" value for a decoded payload.
 */
function signedEsewaData(array $payload, string $secret = '8gBm/:&EnhH.1/q'): string
{
    $payload['signature'] = (new EsewaSignature($secret))->sign(
        (string) EsewaSignature::message($payload, $payload['signed_field_names']),
    );

    return base64_encode((string) json_encode($payload));
}

describe('initiate', function () {
    it('builds the signed form exactly as the fixture, without any HTTP call', function () {
        Event::fake([PaymentInitiated::class]);
        Http::fake();

        $payment = esewa()->initiate(snakeInput($this->fixture['initiate']['input']));

        expect($payment->gateway)->toBe('esewa')
            ->and($payment->reference)->toBe('ORD-1001-a1')
            ->and($payment->orderId)->toBe('ORD-1001-a1')
            ->and($payment->amount)->toBe(110000)
            ->and($payment->expiresAt)->toBeNull()
            ->and($payment->redirect->method)->toBe('POST')
            ->and($payment->redirect->url)->toBe(ESEWA_FORM)
            ->and($payment->redirect->fields)->toEqual($this->fixture['initiate']['expectedFields'])
            ->and(array_keys($payment->redirect->fields))->toBe([
                'amount', 'tax_amount', 'total_amount', 'transaction_uuid', 'product_code',
                'product_service_charge', 'product_delivery_charge', 'success_url', 'failure_url',
                'signed_field_names', 'signature',
            ]);

        Http::assertNothingSent();
        Event::assertDispatched(PaymentInitiated::class);
    });

    it('formats fractional rupees and splits the breakdown', function () {
        $payment = esewa()->initiate([
            'amount' => 11050,
            'order_id' => 'ORD-1001-a1',
            'order_name' => 'Charging session',
            'return_url' => 'https://shop.test/pay/return',
            'breakdown' => ['tax' => 1000, 'service_charge' => 25, 'delivery_charge' => 500],
            'metadata' => ['station' => 'ktm-01', 'slot' => '4'],
        ]);

        $fields = $payment->redirect->fields;

        expect($fields['amount'])->toBe('95.25')
            ->and($fields['tax_amount'])->toBe('10')
            ->and($fields['product_service_charge'])->toBe('0.25')
            ->and($fields['product_delivery_charge'])->toBe('5')
            ->and($fields['total_amount'])->toBe('110.50')
            ->and($fields['failure_url'])->toBe('https://shop.test/pay/return')
            ->and($fields['signature'])->toBe($this->fixture['signatureVectors'][2]['signature'])
            ->and($fields['properties[station]'])->toBe('ktm-01')
            ->and($fields['properties[slot]'])->toBe('4');

        foreach ($fields as $value) {
            expect($value)->toBeString();
        }
    });

    it('allows at most three metadata keys', function () {
        esewa()->initiate([
            'amount' => 1000, 'order_id' => 'A1', 'order_name' => 'x', 'return_url' => 'https://shop.test',
            'metadata' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4'],
        ]);
    })->throws(ValidationException::class, 'at most 3');

    it('accepts amounts below the Khalti minimum', function () {
        $payment = esewa()->initiate(['amount' => 100, 'order_id' => 'A1', 'order_name' => 'x', 'return_url' => 'https://shop.test']);

        expect($payment->redirect->fields['total_amount'])->toBe('1');
    });
});

describe('verify', function () {
    it('verifies a signed callback and confirms with the status API', function () {
        Event::fake([PaymentVerified::class]);
        fakeEsewaStatus('COMPLETE');

        $result = esewa()->verify(
            ['data' => $this->fixture['callback']['data']],
            new ExpectedPayment(amount: 110000, reference: 'ORD-1001-a1'),
        );

        expect($result->isPaid())->toBeTrue()
            ->and($result->status)->toBe(PaymentStatus::Completed)
            ->and($result->reference)->toBe('ORD-1001-a1')
            ->and($result->transactionId)->toBe('000AE01')
            ->and($result->amount)->toBe(110000)
            ->and($result->fee)->toBeNull();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), ESEWA_STATUS.'?')
            && $r->data() === ['product_code' => 'EPAYTEST', 'total_amount' => '1100', 'transaction_uuid' => 'ORD-1001-a1']);
        Event::assertDispatched(PaymentVerified::class);
    });

    it('derives the status amount from the signed callback when none is expected', function () {
        fakeEsewaStatus('COMPLETE');

        $result = esewa()->verify(['data' => $this->fixture['callback']['data']]);

        expect($result->isPaid())->toBeTrue();
        Http::assertSent(fn (Request $r) => $r['total_amount'] === '1100');
    });

    it('returns non-completed statuses', function (string $case, PaymentStatus $status) {
        fakeEsewaStatus($case);

        $result = esewa()->verify(['data' => $this->fixture['callback']['data']], new ExpectedPayment(110000, 'ORD-1001-a1'));

        expect($result->status)->toBe($status)->and($result->isPaid())->toBeFalse();
    })->with([
        ['PENDING', PaymentStatus::Pending],
        ['NOT_FOUND', PaymentStatus::NotFound],
        ['CANCELED', PaymentStatus::Canceled],
    ]);

    it('rejects a tampered callback before calling eSewa', function () {
        Http::fake();

        esewa()->verify(['data' => $this->fixture['tamperedCallback']['data']]);

        Http::assertNothingSent();
    })->throws(InvalidSignatureException::class);

    it('rejects a callback signed for another product code', function () {
        config(['nepal-pay.gateways.esewa.product_code' => 'MYSHOP']);
        Http::fake();

        try {
            esewa()->verify(['data' => $this->fixture['callback']['data']]);
            $this->fail('No exception thrown.');
        } catch (InvalidSignatureException $e) {
            expect($e->errorCode())->toBe('INVALID_SIGNATURE')
                ->and($e->getMessage())->toContain('different product code');
        }

        Http::assertNothingSent();
    });

    it('rejects a completed payment with a different amount', function () {
        fakeEsewaStatus('COMPLETE');

        try {
            esewa()->verify(['data' => $this->fixture['callback']['data']], new ExpectedPayment(100000, 'ORD-1001-a1'));
            $this->fail('No exception thrown.');
        } catch (PaymentMismatchException $e) {
            expect($e->errorCode())->toBe('AMOUNT_MISMATCH')
                ->and($e->expected())->toBe(100000)
                ->and($e->actual())->toBe(110000);
        }

        // The status query uses the amount eSewa signed, not the (different) expected one.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'total_amount=1100&'));
    });

    it('rejects a callback for another order', function () {
        Http::fake();

        try {
            esewa()->verify(['data' => $this->fixture['callback']['data']], new ExpectedPayment(110000, 'ORD-9999'));
            $this->fail('No exception thrown.');
        } catch (PaymentMismatchException $e) {
            expect($e->errorCode())->toBe('REFERENCE_MISMATCH')->and($e->actual())->toBe('ORD-1001-a1');
        }

        Http::assertNothingSent();
    });

    it('checks a failure redirect without data using the expected payment', function () {
        fakeEsewaStatus('CANCELED');

        $result = esewa()->verify([], new ExpectedPayment(110000, 'ORD-1001-a1'));

        expect($result->status)->toBe(PaymentStatus::Canceled);
        Http::assertSent(fn (Request $r) => $r['transaction_uuid'] === 'ORD-1001-a1' && $r['total_amount'] === '1100');
    });

    it('requires an expected reference when there is no data', function (?ExpectedPayment $expected) {
        Http::fake();

        esewa()->verify([], $expected);
    })->throws(InvalidCallbackException::class)->with([
        'no expectation' => [null],
        'amount only' => [new ExpectedPayment(110000)],
    ]);

    it('rejects garbled callback data', function () {
        esewa()->verify(['data' => 'definitely-not-base64!!']);
    })->throws(InvalidCallbackException::class);

    it('rejects a signed callback with an invalid amount', function () {
        $decoded = $this->fixture['callback']['decoded'];
        $decoded['total_amount'] = 'abc';

        esewa()->verify(['data' => signedEsewaData($decoded)]);
    })->throws(InvalidCallbackException::class, 'invalid total_amount');
});

describe('lookup', function () {
    it('queries the status API', function () {
        fakeEsewaStatus('PENDING');

        $result = esewa()->lookup('ORD-1001-a1', 110000);

        expect($result->status)->toBe(PaymentStatus::Pending)
            ->and($result->transactionId)->toBeNull()
            ->and($result->amount)->toBe(110000);
    });

    it('parses comma formatted amounts', function () {
        Http::fake([ESEWA_STATUS.'*' => Http::response(
            ['status' => 'COMPLETE', 'total_amount' => '1,100.0', 'transaction_uuid' => 'ORD-1001-a1', 'ref_id' => 'X1'],
        )]);

        expect(esewa()->lookup('ORD-1001-a1', 110000)->amount)->toBe(110000);
    });

    it('requires the amount', function () {
        esewa()->lookup('ORD-1001-a1');
    })->throws(ValidationException::class, 'require the amount');

    it('retries service errors and then throws GatewayException', function () {
        fakeEsewaStatus('serviceUnavailable');

        try {
            esewa()->lookup('ORD-1001-a1', 110000);
            $this->fail('No exception thrown.');
        } catch (GatewayException $e) {
            expect($e->errorCode())->toBe('GATEWAY_ERROR')
                ->and($e->gateway())->toBe('esewa')
                ->and($e->getMessage())->toContain('Service is currently unavailable')
                ->and($e->responseBody())->toBe(['code' => 0, 'error_message' => 'Service is currently unavailable']);
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    });

    it('recovers when a retry succeeds', function () {
        Http::fake([ESEWA_STATUS.'*' => Http::sequence()
            ->push($this->fixture['statusResponses']['serviceUnavailable'])
            ->push($this->fixture['statusResponses']['COMPLETE']),
        ]);

        expect(esewa()->lookup('ORD-1001-a1', 110000)->isPaid())->toBeTrue();
        Http::assertSentCount(2);
    });

    it('rejects an unexpected response', function () {
        Http::fake([ESEWA_STATUS.'*' => Http::response('<html></html>', 404)]);

        esewa()->lookup('ORD-1001-a1', 110000);
    })->throws(GatewayException::class, 'HTTP 404');
});

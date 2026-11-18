<?php

declare(strict_types=1);

use BijayX\NepalPay\Gateways\Esewa\EsewaSignature;

beforeEach(function () {
    $this->fixture = gatewayFixture('esewa');
    $this->signer = new EsewaSignature($this->fixture['secretKey']);
});

it('matches the live-verified signature vectors', function () {
    foreach ($this->fixture['signatureVectors'] as $vector) {
        expect($this->signer->sign($vector['message']))->toBe($vector['signature']);
    }
});

it('builds the message from signed field names in order', function () {
    $message = EsewaSignature::message(
        ['product_code' => 'EPAYTEST', 'total_amount' => '110.50', 'transaction_uuid' => 'ORD-1001-a1'],
        EsewaSignature::SIGNED_FIELD_NAMES,
    );

    expect($message)->toBe('total_amount=110.50,transaction_uuid=ORD-1001-a1,product_code=EPAYTEST')
        ->and($this->signer->sign((string) $message))->toBe($this->fixture['signatureVectors'][2]['signature']);
});

it('returns null when a signed field is missing', function () {
    expect(EsewaSignature::message(['total_amount' => '100'], EsewaSignature::SIGNED_FIELD_NAMES))->toBeNull();
});

it('refuses to sign incomplete fields', function () {
    $this->signer->signFields(['total_amount' => '100']);
})->throws(InvalidArgumentException::class);

it('verifies the callback fixture signature', function () {
    expect($this->signer->verify($this->fixture['callback']['decoded']))->toBeTrue();
});

it('rejects tampered or unsigned payloads', function (Closure $mutate) {
    expect($this->signer->verify($mutate($this->fixture['callback']['decoded'])))->toBeFalse();
})->with([
    'amount changed' => [fn (array $p) => ['total_amount' => '1.0'] + $p],
    'status changed' => [fn (array $p) => ['status' => 'PENDING'] + $p],
    'signature changed' => [fn (array $p) => ['signature' => base64_encode(str_repeat('x', 32))] + $p],
    'signature missing' => [fn (array $p) => array_diff_key($p, ['signature' => true])],
    'field list missing' => [fn (array $p) => array_diff_key($p, ['signed_field_names' => true])],
    'signed field missing' => [fn (array $p) => array_diff_key($p, ['transaction_code' => true])],
]);

it('rejects signatures made with another secret', function () {
    $other = new EsewaSignature('another-secret');

    expect($other->verify($this->fixture['callback']['decoded']))->toBeFalse();
});

it('does not expose the secret in debug output', function () {
    expect(print_r($this->signer, true))->not->toContain($this->fixture['secretKey']);
});

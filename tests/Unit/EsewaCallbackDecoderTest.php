<?php

declare(strict_types=1);

use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use BijayX\NepalPay\Gateways\Esewa\EsewaCallbackDecoder;

it('decodes the callback fixture', function () {
    $fixture = gatewayFixture('esewa')['callback'];

    expect(EsewaCallbackDecoder::decode(['data' => $fixture['data']]))->toBe($fixture['decoded']);
});

it('returns null when there is no data parameter', function (array $callback) {
    expect(EsewaCallbackDecoder::decode($callback))->toBeNull();
})->with([
    'empty' => [[]],
    'empty string' => [['data' => '']],
    'null' => [['data' => null]],
]);

it('tolerates plus signs decoded to spaces and missing padding', function () {
    $payload = ['status' => 'COMPLETE', 'note' => '>>>???'];
    $data = base64_encode((string) json_encode($payload));

    expect(str_contains($data, '+'))->toBeTrue();

    $mangled = rtrim(str_replace('+', ' ', $data), '=');

    expect(EsewaCallbackDecoder::decode(['data' => $mangled]))->toBe($payload);
});

it('stringifies non-string scalar values', function () {
    $data = base64_encode('{"total_amount":1100.0,"count":2,"flag":true}');

    expect(EsewaCallbackDecoder::decode(['data' => $data]))
        ->toBe(['total_amount' => '1100.0', 'count' => '2', 'flag' => 'true']);
});

it('rejects garbled callback data', function (mixed $data) {
    EsewaCallbackDecoder::decode(['data' => $data]);
})->throws(InvalidCallbackException::class)->with([
    'not base64' => ['%%%not-base64%%%'],
    'not json' => [base64_encode('hello world')],
    'json list' => [base64_encode('["a","b"]')],
    'json scalar' => [base64_encode('"text"')],
    'array value' => [['abc']],
]);

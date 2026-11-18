<?php

declare(strict_types=1);

use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Support\Money;

it('formats paisa as eSewa rupee strings', function (int $paisa, string $expected) {
    expect(Money::paisaToRupeeString($paisa))->toBe($expected);
})->with([
    [11000, '110'],
    [11050, '110.50'],
    [11005, '110.05'],
    [100, '1'],
    [1, '0.01'],
    [0, '0'],
    [110000, '1100'],
]);

it('rejects negative paisa when formatting', function () {
    Money::paisaToRupeeString(-1);
})->throws(ValidationException::class);

it('parses eSewa rupee amounts into paisa', function (mixed $input, int $expected) {
    expect(Money::parseRupees($input))->toBe($expected);
})->with([
    ['1,000.0', 100000],
    ['1,100.0', 110000],
    ['110.50', 11050],
    ['110.5', 11050],
    ['100', 10000],
    [' 1,234,567.89 ', 123456789],
    [100.0, 10000],
    [1100, 110000],
    [10.25, 1025],
    ['0', 0],
]);

it('rejects invalid rupee amounts', function (mixed $input) {
    Money::parseRupees($input);
})->throws(ValidationException::class)->with([
    'empty' => [''],
    'letters' => ['abc'],
    'negative' => ['-10'],
    'two dots' => ['1.2.3'],
    'null' => [null],
    'array' => [[100]],
    'negative float' => [-1.5],
    'infinite' => [INF],
    'nan' => [NAN],
]);

it('asserts positive integer paisa', function () {
    expect(Money::assertPaisa(1000))->toBe(1000);
});

it('rejects non-paisa values', function (mixed $input) {
    Money::assertPaisa($input);
})->throws(ValidationException::class)->with([
    'float' => [100.5],
    'integral float' => [100.0],
    'numeric string' => ['1000'],
    'zero' => [0],
    'negative' => [-100],
    'null' => [null],
]);

it('reports the field name on validation errors', function () {
    try {
        Money::assertPaisa(0, 'expected.amount');
    } catch (ValidationException $e) {
        expect($e->fields())->toHaveKey('expected.amount')
            ->and($e->errorCode())->toBe('VALIDATION_ERROR');

        return;
    }

    $this->fail('No exception thrown.');
});

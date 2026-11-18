<?php

declare(strict_types=1);

use BijayX\NepalPay\Enums\PaymentStatus;
use BijayX\NepalPay\Gateways\Esewa\EsewaStatusMapper;
use BijayX\NepalPay\Gateways\Khalti\KhaltiStatusMapper;

it('maps Khalti statuses', function (?string $input, PaymentStatus $expected) {
    expect(KhaltiStatusMapper::map($input))->toBe($expected);
})->with([
    ['Completed', PaymentStatus::Completed],
    ['Pending', PaymentStatus::Pending],
    ['Initiated', PaymentStatus::Initiated],
    ['Refunded', PaymentStatus::Refunded],
    ['Partially Refunded', PaymentStatus::PartiallyRefunded],
    ['Expired', PaymentStatus::Expired],
    ['User canceled', PaymentStatus::Canceled],
    ['Canceled', PaymentStatus::Canceled],
    ['Failed', PaymentStatus::Failed],
    ['Not found.', PaymentStatus::NotFound],
    ['  COMPLETED ', PaymentStatus::Completed],
    ["user   \tCANCELED", PaymentStatus::Canceled],
    ['partially  refunded', PaymentStatus::PartiallyRefunded],
    ['Something new', PaymentStatus::Unknown],
    ['', PaymentStatus::Unknown],
    [null, PaymentStatus::Unknown],
]);

it('maps eSewa statuses', function (?string $input, PaymentStatus $expected) {
    expect(EsewaStatusMapper::map($input))->toBe($expected);
})->with([
    ['COMPLETE', PaymentStatus::Completed],
    ['PENDING', PaymentStatus::Pending],
    ['FULL_REFUND', PaymentStatus::Refunded],
    ['PARTIAL_REFUND', PaymentStatus::PartiallyRefunded],
    ['CANCELED', PaymentStatus::Canceled],
    ['AMBIGUOUS', PaymentStatus::Ambiguous],
    ['NOT_FOUND', PaymentStatus::NotFound],
    [' complete ', PaymentStatus::Completed],
    ['not_found', PaymentStatus::NotFound],
    ['COMPLETED', PaymentStatus::Unknown],
    [null, PaymentStatus::Unknown],
]);

it('uses the contract status values', function () {
    expect(array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()))->toBe([
        'completed', 'pending', 'initiated', 'refunded', 'partially_refunded',
        'expired', 'canceled', 'failed', 'ambiguous', 'not_found', 'unknown',
    ]);
});

it('treats only completed as paid', function () {
    foreach (PaymentStatus::cases() as $status) {
        expect($status->isPaid())->toBe($status === PaymentStatus::Completed);
    }
});

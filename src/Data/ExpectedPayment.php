<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Support\Money;

/**
 * What your application stored at initiate time. Passed to verify() so the package
 * can reject callbacks for a different payment or a different amount.
 */
final readonly class ExpectedPayment
{
    /**
     * @param  int  $amount  Amount in paisa you expect to have been paid.
     * @param  string|null  $reference  The reference returned by initiate() (Khalti pidx / eSewa transaction_uuid).
     *
     * @throws ValidationException
     */
    public function __construct(
        public int $amount,
        public ?string $reference = null,
    ) {
        Money::assertPaisa($amount, 'expected.amount');

        if ($reference !== null && trim($reference) === '') {
            throw ValidationException::forField('expected.reference', 'The expected reference must not be empty.');
        }
    }
}

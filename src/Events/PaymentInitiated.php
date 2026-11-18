<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Events;

use BijayX\NepalPay\Data\InitiatedPayment;

/**
 * Dispatched after a gateway has accepted (Khalti) or prepared (eSewa) a payment.
 */
final readonly class PaymentInitiated
{
    public function __construct(public InitiatedPayment $payment) {}
}

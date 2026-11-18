<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Events;

use BijayX\NepalPay\Data\PaymentResult;

/**
 * Dispatched after verify() confirmed a payment with the gateway, whatever its status.
 * Check $result->isPaid() before acting on it. Not dispatched when verification throws.
 */
final readonly class PaymentVerified
{
    public function __construct(public PaymentResult $result) {}
}

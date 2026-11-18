<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Enums;

/**
 * Gateway-independent payment status. Values are part of the shared contract.
 */
enum PaymentStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Initiated = 'initiated';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
    case NotFound = 'not_found';
    case Unknown = 'unknown';

    /**
     * Only a completed payment may be fulfilled.
     */
    public function isPaid(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether the payment may still change state and is worth checking again later.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Initiated, self::Pending, self::Ambiguous], true);
    }
}

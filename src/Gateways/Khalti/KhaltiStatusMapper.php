<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Khalti;

use BijayX\NepalPay\Enums\PaymentStatus;

/**
 * Maps Khalti lookup statuses to PaymentStatus (case-insensitive, whitespace-tolerant).
 */
final class KhaltiStatusMapper
{
    private const MAP = [
        'completed' => PaymentStatus::Completed,
        'pending' => PaymentStatus::Pending,
        'initiated' => PaymentStatus::Initiated,
        'refunded' => PaymentStatus::Refunded,
        'partially refunded' => PaymentStatus::PartiallyRefunded,
        'expired' => PaymentStatus::Expired,
        'user canceled' => PaymentStatus::Canceled,
        'canceled' => PaymentStatus::Canceled,
        'failed' => PaymentStatus::Failed,
        'not found.' => PaymentStatus::NotFound,
    ];

    private function __construct() {}

    public static function map(?string $status): PaymentStatus
    {
        if ($status === null) {
            return PaymentStatus::Unknown;
        }

        return self::MAP[self::normalize($status)] ?? PaymentStatus::Unknown;
    }

    public static function normalize(string $status): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($status)));
    }
}

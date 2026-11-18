<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Esewa;

use BijayX\NepalPay\Enums\PaymentStatus;

/**
 * Maps eSewa status API values to PaymentStatus (case-insensitive, whitespace-tolerant).
 */
final class EsewaStatusMapper
{
    private const MAP = [
        'COMPLETE' => PaymentStatus::Completed,
        'PENDING' => PaymentStatus::Pending,
        'FULL_REFUND' => PaymentStatus::Refunded,
        'PARTIAL_REFUND' => PaymentStatus::PartiallyRefunded,
        'CANCELED' => PaymentStatus::Canceled,
        'AMBIGUOUS' => PaymentStatus::Ambiguous,
        'NOT_FOUND' => PaymentStatus::NotFound,
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
        return strtoupper((string) preg_replace('/\s+/', ' ', trim($status)));
    }
}

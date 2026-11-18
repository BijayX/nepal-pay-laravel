<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Enums\PaymentStatus;

/**
 * The gateway-confirmed state of a payment.
 */
final readonly class PaymentResult
{
    /**
     * @param  int  $amount  Amount in paisa as reported by the gateway.
     * @param  int|null  $fee  Gateway fee in paisa when the gateway reports it (Khalti), otherwise null.
     * @param  array<array-key, mixed>  $raw  The gateway's response body.
     */
    public function __construct(
        public string $gateway,
        public PaymentStatus $status,
        public string $reference,
        public ?string $transactionId,
        public int $amount,
        public ?int $fee = null,
        public array $raw = [],
    ) {}

    /**
     * True only when the gateway confirmed the payment as completed.
     */
    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /**
     * @return array{gateway: string, status: string, is_paid: bool, reference: string, transaction_id: string|null, amount: int, fee: int|null, raw: array<array-key, mixed>}
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'status' => $this->status->value,
            'is_paid' => $this->isPaid(),
            'reference' => $this->reference,
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'raw' => $this->raw,
        ];
    }
}

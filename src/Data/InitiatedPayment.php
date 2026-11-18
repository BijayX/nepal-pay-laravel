<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Http\Responses\GatewayRedirectResponse;
use Carbon\CarbonImmutable;

/**
 * A payment that has been created at the gateway (Khalti) or prepared for submission (eSewa).
 *
 * Persist $reference and $amount on your order before redirecting; verify() needs them.
 */
final readonly class InitiatedPayment
{
    /**
     * @param  string  $reference  Khalti pidx, or eSewa transaction_uuid (equal to the order id).
     * @param  int  $amount  Total in paisa.
     * @param  array<array-key, mixed>  $raw  Gateway response (Khalti) or form fields (eSewa).
     */
    public function __construct(
        public string $gateway,
        public string $reference,
        public string $orderId,
        public int $amount,
        public RedirectInstruction $redirect,
        public ?CarbonImmutable $expiresAt = null,
        public array $raw = [],
    ) {}

    /**
     * A Responsable you can return from a controller to send the payer to the gateway.
     */
    public function redirect(): GatewayRedirectResponse
    {
        return new GatewayRedirectResponse($this->redirect);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'redirect' => $this->redirect->toArray(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'raw' => $this->raw,
        ];
    }
}

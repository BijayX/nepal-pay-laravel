<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Contracts;

use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Data\PaymentRequest;
use BijayX\NepalPay\Data\PaymentResult;
use BijayX\NepalPay\Exceptions\NepalPayException;

/**
 * The unified API every gateway implements.
 */
interface PaymentGateway
{
    /**
     * Gateway identifier, e.g. "khalti" or "esewa".
     */
    public function name(): string;

    /**
     * Start a payment. Never retried automatically.
     *
     * @param  PaymentRequest|array<string, mixed>  $request
     *
     * @throws NepalPayException
     */
    public function initiate(PaymentRequest|array $request): InitiatedPayment;

    /**
     * Confirm a payment from the return/callback request with the gateway's server.
     *
     * Non-completed statuses are returned (check isPaid()); fraud signals and bad
     * callbacks are thrown.
     *
     * @param  array<array-key, mixed>  $callback  Raw query parameters of the return request.
     *
     * @throws NepalPayException
     */
    public function verify(array $callback, ?ExpectedPayment $expected = null): PaymentResult;

    /**
     * Fetch the current status of a payment from the gateway.
     *
     * @param  string  $reference  Khalti pidx or eSewa transaction_uuid.
     * @param  int|null  $amount  Amount in paisa; required by eSewa.
     *
     * @throws NepalPayException
     */
    public function lookup(string $reference, ?int $amount = null): PaymentResult;
}

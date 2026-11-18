<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Khalti;

use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Data\PaymentRequest;
use BijayX\NepalPay\Data\PaymentResult;
use BijayX\NepalPay\Data\RedirectInstruction;
use BijayX\NepalPay\Enums\PaymentStatus;
use BijayX\NepalPay\Exceptions\GatewayException;
use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Gateways\AbstractGateway;
use BijayX\NepalPay\Http\GatewayHttpClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Khalti KPG-2 web checkout.
 *
 * @see https://docs.khalti.com/khalti-epayment/
 */
final class KhaltiGateway extends AbstractGateway
{
    public const NAME = 'khalti';

    /** Khalti rejects payments below Rs 10. */
    public const MINIMUM_AMOUNT = 1000;

    public function __construct(
        private readonly KhaltiConfig $config,
        private readonly GatewayHttpClient $http,
        Dispatcher|Closure|null $events = null,
    ) {
        parent::__construct($events);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function config(): KhaltiConfig
    {
        return $this->config;
    }

    protected function createPayment(PaymentRequest $request): InitiatedPayment
    {
        if ($request->amount < self::MINIMUM_AMOUNT) {
            throw ValidationException::forField(
                'amount',
                'Khalti requires an amount of at least '.self::MINIMUM_AMOUNT.' paisa (Rs 10).',
                self::NAME,
            );
        }

        $response = $this->http->postJson(
            $this->config->endpoint('epayment/initiate/'),
            $this->initiatePayload($request),
            $this->headers(),
        );

        if (! $response->successful()) {
            throw KhaltiErrorParser::toException($response);
        }

        $body = $this->body($response);
        $pidx = $body['pidx'] ?? null;
        $paymentUrl = $body['payment_url'] ?? null;

        if (! is_string($pidx) || $pidx === '' || ! is_string($paymentUrl) || $paymentUrl === '') {
            throw new GatewayException(
                'Khalti returned an initiate response without pidx or payment_url.',
                self::NAME,
                $response->status(),
                $body,
            );
        }

        return new InitiatedPayment(
            gateway: self::NAME,
            reference: $pidx,
            orderId: $request->orderId,
            amount: $request->amount,
            redirect: RedirectInstruction::get($paymentUrl),
            expiresAt: $this->parseDate($body['expires_at'] ?? null),
            raw: $body,
        );
    }

    protected function confirm(array $callback, ?ExpectedPayment $expected): PaymentResult
    {
        $callbackPidx = $this->callbackString($callback, 'pidx');
        $pidx = $callbackPidx ?? $expected?->reference;

        if ($pidx === null) {
            throw new InvalidCallbackException(
                'The Khalti callback does not contain a pidx and no expected reference was given.',
                self::NAME,
            );
        }

        if ($callbackPidx !== null) {
            $this->assertReference($expected, $callbackPidx);
        }

        return $this->lookup($pidx, $expected?->amount);
    }

    public function lookup(string $reference, ?int $amount = null): PaymentResult
    {
        if (trim($reference) === '') {
            throw ValidationException::forField('reference', 'The Khalti pidx is required.', self::NAME);
        }

        $response = $this->http->postJson(
            $this->config->endpoint('epayment/lookup/'),
            ['pidx' => $reference],
            $this->headers(),
            idempotent: true,
        );

        $body = $response->json();

        // Khalti answers Expired / User canceled lookups with HTTP 400 and a normal body.
        if (is_array($body) && is_string($body['status'] ?? null) && is_string($body['pidx'] ?? null)) {
            return $this->resultFromLookup($body, $response);
        }

        if (is_array($body) && is_string($body['detail'] ?? null)
            && KhaltiStatusMapper::map($body['detail']) === PaymentStatus::NotFound) {
            return new PaymentResult(
                gateway: self::NAME,
                status: PaymentStatus::NotFound,
                reference: $reference,
                transactionId: null,
                amount: $amount ?? 0,
                fee: null,
                raw: $body,
            );
        }

        if ($response->successful()) {
            throw new GatewayException(
                'Khalti returned a malformed lookup response.',
                self::NAME,
                $response->status(),
                is_array($body) ? $body : $response->body(),
            );
        }

        throw KhaltiErrorParser::toException($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function initiatePayload(PaymentRequest $request): array
    {
        $payload = [
            'return_url' => $request->returnUrl,
            'website_url' => $this->config->websiteUrl,
            'amount' => $request->amount,
            'purchase_order_id' => $request->orderId,
            'purchase_order_name' => $request->orderName,
        ];

        if ($request->customer !== null && ! $request->customer->isEmpty()) {
            $payload['customer_info'] = $request->customer->toArray();
        }

        if ($request->breakdown !== null) {
            $breakdown = $request->breakdown;
            $rows = [['label' => 'Amount', 'amount' => $request->amount - $breakdown->total()]];

            foreach ([
                'Tax' => $breakdown->tax,
                'Service charge' => $breakdown->serviceCharge,
                'Delivery charge' => $breakdown->deliveryCharge,
            ] as $label => $value) {
                if ($value > 0) {
                    $rows[] = ['label' => $label, 'amount' => $value];
                }
            }

            $payload['amount_breakdown'] = $rows;
        }

        foreach ($request->metadata as $key => $value) {
            $payload["merchant_{$key}"] = $value;
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function resultFromLookup(array $body, Response $response): PaymentResult
    {
        /** @var string $status */
        $status = $body['status'];
        /** @var string $pidx */
        $pidx = $body['pidx'];

        $amount = $this->integer($body['total_amount'] ?? null);

        if ($amount === null) {
            throw new GatewayException(
                'Khalti returned a lookup response without a valid total_amount.',
                self::NAME,
                $response->status(),
                $body,
            );
        }

        $transactionId = $body['transaction_id'] ?? null;

        return new PaymentResult(
            gateway: self::NAME,
            status: KhaltiStatusMapper::map($status),
            reference: $pidx,
            transactionId: is_string($transactionId) && $transactionId !== '' ? $transactionId : null,
            amount: $amount,
            fee: $this->integer($body['fee'] ?? null),
            raw: $body,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Key '.$this->config->secretKey];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new GatewayException(
                'Khalti returned a response that is not valid JSON.',
                self::NAME,
                $response->status(),
                $response->body(),
            );
        }

        return $body;
    }

    private function integer(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            is_string($value) && preg_match('/^\d+$/', $value) === 1 => (int) $value,
            default => null,
        };
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

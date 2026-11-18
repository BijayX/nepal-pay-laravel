<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Esewa;

use BijayX\NepalPay\Data\Breakdown;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Data\PaymentRequest;
use BijayX\NepalPay\Data\PaymentResult;
use BijayX\NepalPay\Data\RedirectInstruction;
use BijayX\NepalPay\Exceptions\AuthenticationException;
use BijayX\NepalPay\Exceptions\GatewayException;
use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use BijayX\NepalPay\Exceptions\InvalidSignatureException;
use BijayX\NepalPay\Exceptions\ValidationException;
use BijayX\NepalPay\Gateways\AbstractGateway;
use BijayX\NepalPay\Http\GatewayHttpClient;
use BijayX\NepalPay\Support\Money;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Response;

/**
 * eSewa ePay v2.
 *
 * initiate() makes no HTTP call: it returns a signed form that the payer's browser
 * POSTs to eSewa. verify() checks the callback signature and confirms the payment
 * with eSewa's status API.
 *
 * @see https://developer.esewa.com.np/pages/Epay
 */
final class EsewaGateway extends AbstractGateway
{
    public const NAME = 'esewa';

    public const MAX_METADATA_KEYS = 3;

    private readonly EsewaSignature $signature;

    public function __construct(
        private readonly EsewaConfig $config,
        private readonly GatewayHttpClient $http,
        Dispatcher|Closure|null $events = null,
    ) {
        parent::__construct($events);

        $this->signature = new EsewaSignature($config->secretKey);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function config(): EsewaConfig
    {
        return $this->config;
    }

    protected function createPayment(PaymentRequest $request): InitiatedPayment
    {
        if (count($request->metadata) > self::MAX_METADATA_KEYS) {
            throw ValidationException::forField(
                'metadata',
                'eSewa accepts at most '.self::MAX_METADATA_KEYS.' metadata keys.',
                self::NAME,
            );
        }

        $breakdown = $request->breakdown ?? new Breakdown;
        $baseAmount = $request->amount - $breakdown->total();

        if ($baseAmount <= 0) {
            throw ValidationException::forField('breakdown', 'The breakdown total must be less than the amount.', self::NAME);
        }

        $fields = [
            'amount' => Money::paisaToRupeeString($baseAmount),
            'tax_amount' => Money::paisaToRupeeString($breakdown->tax),
            'total_amount' => Money::paisaToRupeeString($request->amount),
            'transaction_uuid' => $request->orderId,
            'product_code' => $this->config->productCode,
            'product_service_charge' => Money::paisaToRupeeString($breakdown->serviceCharge),
            'product_delivery_charge' => Money::paisaToRupeeString($breakdown->deliveryCharge),
            'success_url' => $request->returnUrl,
            'failure_url' => $request->failureUrlOrReturnUrl(),
            'signed_field_names' => EsewaSignature::SIGNED_FIELD_NAMES,
        ];

        $fields['signature'] = $this->signature->signFields($fields);

        foreach ($request->metadata as $key => $value) {
            $fields["properties[{$key}]"] = $value;
        }

        return new InitiatedPayment(
            gateway: self::NAME,
            reference: $request->orderId,
            orderId: $request->orderId,
            amount: $request->amount,
            redirect: RedirectInstruction::post($this->config->formUrl, $fields),
            expiresAt: null,
            raw: $fields,
        );
    }

    protected function confirm(array $callback, ?ExpectedPayment $expected): PaymentResult
    {
        $payload = EsewaCallbackDecoder::decode($callback);

        if ($payload === null) {
            if ($expected?->reference === null) {
                throw new InvalidCallbackException(
                    'The eSewa callback has no data parameter; pass an ExpectedPayment with amount and reference to verify it.',
                    self::NAME,
                );
            }

            return $this->lookup($expected->reference, $expected->amount);
        }

        if (! $this->signature->verify($payload)) {
            throw new InvalidSignatureException('The eSewa callback signature is invalid.', self::NAME);
        }

        if (($payload['product_code'] ?? null) !== $this->config->productCode) {
            throw new InvalidSignatureException(
                'The eSewa callback was issued for a different product code.',
                self::NAME,
                ['product_code' => $payload['product_code'] ?? null],
            );
        }

        $reference = $payload['transaction_uuid'] ?? '';

        if ($reference === '') {
            throw new InvalidCallbackException('The eSewa callback does not contain a transaction_uuid.', self::NAME);
        }

        $this->assertReference($expected, $reference);

        // Query with the amount eSewa signed (what was actually charged). If it differs from
        // the expected amount, assertMatchesExpectation() raises AMOUNT_MISMATCH.
        return $this->lookup($reference, $this->callbackAmount($payload));
    }

    public function lookup(string $reference, ?int $amount = null): PaymentResult
    {
        if (trim($reference) === '') {
            throw ValidationException::forField('reference', 'The eSewa transaction_uuid is required.', self::NAME);
        }

        if ($amount === null) {
            throw ValidationException::forField('amount', 'eSewa status lookups require the amount in paisa.', self::NAME);
        }

        Money::assertPaisa($amount);

        $response = $this->http->get(
            $this->config->statusUrl,
            [
                'product_code' => $this->config->productCode,
                'total_amount' => Money::paisaToRupeeString($amount),
                'transaction_uuid' => $reference,
            ],
            retryWhen: fn (Response $response): bool => $this->isServiceError($response->json()),
        );

        $body = $response->json();

        if (is_array($body) && is_string($body['status'] ?? null)) {
            return $this->resultFromStatus($body, $reference, $amount, $response);
        }

        if ($this->isServiceError($body)) {
            /** @var array<array-key, mixed> $body */
            $message = is_string($body['error_message'] ?? null) ? $body['error_message'] : 'unknown error';

            throw new GatewayException(
                "The eSewa status check failed: {$message}",
                self::NAME,
                $response->status(),
                $body,
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new AuthenticationException('eSewa rejected the status request.', self::NAME, $response->status());
        }

        throw new GatewayException(
            "eSewa returned an unexpected status response (HTTP {$response->status()}).",
            self::NAME,
            $response->status(),
            is_array($body) ? $body : $response->body(),
        );
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function resultFromStatus(array $body, string $reference, int $amount, Response $response): PaymentResult
    {
        /** @var string $status */
        $status = $body['status'];

        $confirmedAmount = $amount;

        if (isset($body['total_amount'])) {
            try {
                $confirmedAmount = Money::parseRupees($body['total_amount'], 'total_amount');
            } catch (ValidationException $e) {
                throw new GatewayException(
                    'eSewa returned an invalid total_amount.',
                    self::NAME,
                    $response->status(),
                    $body,
                    $e,
                );
            }
        }

        $uuid = $body['transaction_uuid'] ?? null;
        $refId = $body['ref_id'] ?? null;

        return new PaymentResult(
            gateway: self::NAME,
            status: EsewaStatusMapper::map($status),
            reference: is_string($uuid) && $uuid !== '' ? $uuid : $reference,
            transactionId: is_string($refId) && $refId !== '' ? $refId : null,
            amount: $confirmedAmount,
            fee: null,
            raw: $body,
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function callbackAmount(array $payload): int
    {
        try {
            return Money::parseRupees($payload['total_amount'] ?? null, 'total_amount');
        } catch (ValidationException) {
            throw new InvalidCallbackException('The eSewa callback contains an invalid total_amount.', self::NAME);
        }
    }

    /**
     * eSewa reports outages as {"code": 0, "error_message": "..."}.
     */
    private function isServiceError(mixed $body): bool
    {
        return is_array($body)
            && array_key_exists('code', $body)
            && array_key_exists('error_message', $body)
            && ! isset($body['status']);
    }
}

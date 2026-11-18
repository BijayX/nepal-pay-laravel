<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Exceptions\ValidationException;

/**
 * Everything needed to start a payment. Validated on construction.
 */
final readonly class PaymentRequest
{
    public const ORDER_ID_PATTERN = '/^[A-Za-z0-9-]{1,64}$/';

    public const METADATA_KEY_PATTERN = '/^[a-z0-9_]+$/';

    private const KEYS = [
        'amount', 'order_id', 'order_name', 'return_url', 'failure_url', 'customer', 'breakdown', 'metadata',
    ];

    /**
     * @param  int  $amount  Total payable in paisa.
     * @param  string  $orderId  Unique per payment attempt: 1-64 chars of A-Z, a-z, 0-9 and "-".
     * @param  array<string, string>  $metadata  Extra merchant data (keys: lowercase letters, digits, "_").
     *
     * @throws ValidationException
     */
    public function __construct(
        public int $amount,
        public string $orderId,
        public string $orderName,
        public string $returnUrl,
        public ?string $failureUrl = null,
        public ?Customer $customer = null,
        public ?Breakdown $breakdown = null,
        public array $metadata = [],
    ) {
        $errors = [];

        if ($amount <= 0) {
            $errors['amount'][] = 'The amount must be greater than zero.';
        }

        if (preg_match(self::ORDER_ID_PATTERN, $orderId) !== 1) {
            $errors['order_id'][] = 'The order id must be 1-64 characters of letters, digits and dashes.';
        }

        if (trim($orderName) === '') {
            $errors['order_name'][] = 'The order name is required.';
        } elseif (mb_strlen($orderName) > 255) {
            $errors['order_name'][] = 'The order name must not exceed 255 characters.';
        }

        if (! self::isHttpUrl($returnUrl)) {
            $errors['return_url'][] = 'The return url must be an absolute http(s) URL.';
        }

        if ($failureUrl !== null && ! self::isHttpUrl($failureUrl)) {
            $errors['failure_url'][] = 'The failure url must be an absolute http(s) URL.';
        }

        if ($breakdown !== null && $amount > 0 && $breakdown->total() >= $amount) {
            $errors['breakdown'][] = 'The breakdown total must be less than the amount.';
        }

        foreach ($metadata as $key => $value) {
            /** @phpstan-ignore function.alreadyNarrowedType (guards untyped callers) */
            if (! is_string($key) || preg_match(self::METADATA_KEY_PATTERN, $key) !== 1) {
                $errors['metadata'][] = "The metadata key [{$key}] must contain only lowercase letters, digits and underscores.";
            }

            /** @phpstan-ignore function.alreadyNarrowedType (guards untyped callers) */
            if (! is_string($value)) {
                $errors["metadata.{$key}"][] = 'The metadata value must be a string.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withFields($errors);
        }
    }

    /**
     * Build from a snake_case array:
     * amount, order_id, order_name, return_url, failure_url?, customer?, breakdown?, metadata?.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                $errors[(string) $key][] = 'Unknown payment field.';
            }
        }

        $amount = $data['amount'] ?? null;
        if (! is_int($amount)) {
            $errors['amount'][] = 'The amount must be an integer number of paisa.';
        }

        foreach (['order_id', 'order_name', 'return_url'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                $errors[$key][] = "The {$key} field is required and must be a string.";
            }
        }

        $failureUrl = $data['failure_url'] ?? null;
        if ($failureUrl !== null && ! is_string($failureUrl)) {
            $errors['failure_url'][] = 'The failure_url must be a string.';
        }

        $customer = $data['customer'] ?? null;
        if ($customer !== null && ! is_array($customer) && ! $customer instanceof Customer) {
            $errors['customer'][] = 'The customer must be an array.';
        }

        $breakdown = $data['breakdown'] ?? null;
        if ($breakdown !== null && ! is_array($breakdown) && ! $breakdown instanceof Breakdown) {
            $errors['breakdown'][] = 'The breakdown must be an array.';
        }

        $metadata = $data['metadata'] ?? [];
        if (! is_array($metadata)) {
            $errors['metadata'][] = 'The metadata must be an array of strings.';
            $metadata = [];
        }

        if ($errors !== []) {
            throw ValidationException::withFields($errors);
        }

        /** @var int $amount */
        /** @var string|null $failureUrl */
        return new self(
            amount: $amount,
            orderId: self::string($data, 'order_id'),
            orderName: self::string($data, 'order_name'),
            returnUrl: self::string($data, 'return_url'),
            failureUrl: $failureUrl,
            customer: is_array($customer) ? Customer::fromArray($customer) : $customer,
            breakdown: is_array($breakdown) ? Breakdown::fromArray($breakdown) : $breakdown,
            metadata: self::metadata($metadata),
        );
    }

    /**
     * The URL eSewa should send failed/canceled payments to.
     */
    public function failureUrlOrReturnUrl(): string
    {
        return $this->failureUrl ?? $this->returnUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'order_id' => $this->orderId,
            'order_name' => $this->orderName,
            'return_url' => $this->returnUrl,
            'failure_url' => $this->failureUrl,
            'customer' => $this->customer?->toArray(),
            'breakdown' => $this->breakdown?->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Metadata is validated by the constructor; this only narrows the type and lets
     * scalar values through so the constructor can report them.
     *
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, string>
     */
    private static function metadata(array $metadata): array
    {
        /** @var array<string, string> $metadata */
        return $metadata;
    }
}

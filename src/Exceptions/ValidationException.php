<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

/**
 * Invalid input, detected locally or reported by the gateway as field errors.
 */
final class ValidationException extends NepalPayException
{
    public const CODE = 'VALIDATION_ERROR';

    /**
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(
        string $message,
        private readonly array $fields = [],
        ?string $gateway = null,
    ) {
        parent::__construct($message, self::CODE, $gateway, ['fields' => $fields]);
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    public static function withFields(array $fields, ?string $gateway = null, ?string $message = null): self
    {
        if ($message === null) {
            $first = array_key_first($fields);
            $message = $first === null
                ? 'The payment data is invalid.'
                : "{$first}: ".($fields[$first][0] ?? 'is invalid.');
        }

        return new self($message, $fields, $gateway);
    }

    public static function forField(string $field, string $message, ?string $gateway = null): self
    {
        return self::withFields([$field => [$message]], $gateway);
    }

    /**
     * Field errors keyed by field name.
     *
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}

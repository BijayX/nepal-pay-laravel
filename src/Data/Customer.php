<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Exceptions\ValidationException;

/**
 * Payer details. Sent to Khalti as customer_info; eSewa ignores them.
 */
final readonly class Customer
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  keys: name, email, phone
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, ['name', 'email', 'phone'], true)) {
                $errors["customer.{$key}"] = ['Unknown customer field.'];
            }
        }

        $values = [];

        foreach (['name', 'email', 'phone'] as $key) {
            $value = $data[$key] ?? null;

            if (is_int($value)) {
                $value = (string) $value;
            }

            if ($value !== null && ! is_string($value)) {
                $errors["customer.{$key}"] = ['The value must be a string.'];

                continue;
            }

            $value = $value === null ? null : trim($value);
            $values[$key] = $value === '' ? null : $value;
        }

        if ($errors !== []) {
            throw ValidationException::withFields($errors);
        }

        return new self($values['name'] ?? null, $values['email'] ?? null, $values['phone'] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->name === null && $this->email === null && $this->phone === null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter(
            ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone],
            static fn (?string $value): bool => $value !== null,
        );
    }
}

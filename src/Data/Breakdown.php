<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Exceptions\ValidationException;

/**
 * Parts of the total that are not the product price. All values in paisa.
 */
final readonly class Breakdown
{
    /**
     * @throws ValidationException
     */
    public function __construct(
        public int $tax = 0,
        public int $serviceCharge = 0,
        public int $deliveryCharge = 0,
    ) {
        $errors = [];

        foreach (['tax' => $tax, 'service_charge' => $serviceCharge, 'delivery_charge' => $deliveryCharge] as $field => $value) {
            if ($value < 0) {
                $errors["breakdown.{$field}"] = ['The value must be zero or more paisa.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withFields($errors);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data  keys: tax, service_charge, delivery_charge
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $values = [];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, ['tax', 'service_charge', 'delivery_charge'], true)) {
                $errors["breakdown.{$key}"] = ['Unknown breakdown field.'];
            }
        }

        foreach (['tax', 'service_charge', 'delivery_charge'] as $key) {
            $value = $data[$key] ?? 0;

            if (! is_int($value)) {
                $errors["breakdown.{$key}"] = ['The value must be an integer number of paisa.'];

                continue;
            }

            $values[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withFields($errors);
        }

        return new self($values['tax'] ?? 0, $values['service_charge'] ?? 0, $values['delivery_charge'] ?? 0);
    }

    public function total(): int
    {
        return $this->tax + $this->serviceCharge + $this->deliveryCharge;
    }

    /**
     * @return array{tax: int, service_charge: int, delivery_charge: int}
     */
    public function toArray(): array
    {
        return [
            'tax' => $this->tax,
            'service_charge' => $this->serviceCharge,
            'delivery_charge' => $this->deliveryCharge,
        ];
    }
}

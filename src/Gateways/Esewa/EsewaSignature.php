<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Esewa;

use SensitiveParameter;

/**
 * eSewa ePay v2 HMAC-SHA256 signatures.
 *
 * The signed message is "name=value" pairs joined by commas, in the order given by
 * signed_field_names, using the raw string values exactly as sent/received:
 *
 *     total_amount=110,transaction_uuid=ab14a8f2b02c3,product_code=EPAYTEST
 *
 * The signature is base64(HMAC-SHA256(secret, message)).
 */
final class EsewaSignature
{
    public const SIGNED_FIELD_NAMES = 'total_amount,transaction_uuid,product_code';

    public function __construct(#[SensitiveParameter] private readonly string $secretKey) {}

    public function sign(string $message): string
    {
        return base64_encode(hash_hmac('sha256', $message, $this->secretKey, true));
    }

    /**
     * Sign the fields named in $signedFieldNames.
     *
     * @param  array<string, string>  $fields
     *
     * @throws \InvalidArgumentException when a named field is missing
     */
    public function signFields(array $fields, string $signedFieldNames = self::SIGNED_FIELD_NAMES): string
    {
        $message = self::message($fields, $signedFieldNames);

        if ($message === null) {
            throw new \InvalidArgumentException('Cannot sign eSewa fields: a signed field is missing.');
        }

        return $this->sign($message);
    }

    /**
     * Verify a decoded callback payload (it must contain signed_field_names and signature).
     *
     * @param  array<string, string>  $payload
     */
    public function verify(array $payload): bool
    {
        $names = $payload['signed_field_names'] ?? null;
        $signature = $payload['signature'] ?? null;

        if ($names === null || $names === '' || $signature === null || $signature === '') {
            return false;
        }

        $message = self::message($payload, $names);

        return $message !== null && hash_equals($this->sign($message), $signature);
    }

    /**
     * Build the message to sign, or null if a named field is missing.
     *
     * @param  array<string, string>  $fields
     */
    public static function message(array $fields, string $signedFieldNames): ?string
    {
        $parts = [];

        foreach (explode(',', $signedFieldNames) as $name) {
            if (! array_key_exists($name, $fields)) {
                return null;
            }

            $parts[] = $name.'='.$fields[$name];
        }

        return implode(',', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['secretKey' => '********'];
    }
}

<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Esewa;

use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use JsonException;

/**
 * Decodes the base64 JSON "data" parameter eSewa appends to the success URL.
 */
final class EsewaCallbackDecoder
{
    private function __construct() {}

    /**
     * @param  array<array-key, mixed>  $callback  Raw query parameters.
     * @return array<string, string>|null The decoded payload with string values, or null when there is no data parameter.
     *
     * @throws InvalidCallbackException
     */
    public static function decode(array $callback): ?array
    {
        $data = $callback['data'] ?? null;

        if ($data === null || $data === '') {
            return null;
        }

        if (! is_string($data)) {
            throw self::invalid('The eSewa callback parameter [data] must be a single string value.');
        }

        $json = base64_decode(self::normalizeBase64($data), true);

        if ($json === false || $json === '') {
            throw self::invalid('The eSewa callback data is not valid base64.');
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw self::invalid('The eSewa callback data is not valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw self::invalid('The eSewa callback data is not a JSON object.');
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (is_string($value)) {
                $payload[(string) $key] = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $payload[(string) $key] = (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            }
        }

        return $payload;
    }

    /**
     * Tolerate "+" decoded to " " by query parsing, URL-safe alphabets and missing padding.
     */
    private static function normalizeBase64(string $data): string
    {
        $data = strtr(trim($data), [' ' => '+', '-' => '+', '_' => '/']);
        $remainder = strlen($data) % 4;

        return $remainder === 0 ? $data : $data.str_repeat('=', 4 - $remainder);
    }

    private static function invalid(string $message): InvalidCallbackException
    {
        return new InvalidCallbackException($message, EsewaGateway::NAME);
    }
}

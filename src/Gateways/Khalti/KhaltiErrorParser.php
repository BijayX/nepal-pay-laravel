<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways\Khalti;

use BijayX\NepalPay\Exceptions\AuthenticationException;
use BijayX\NepalPay\Exceptions\GatewayException;
use BijayX\NepalPay\Exceptions\NepalPayException;
use BijayX\NepalPay\Exceptions\ValidationException;
use Illuminate\Http\Client\Response;

/**
 * Turns Khalti error responses into package exceptions.
 *
 *  - 401/403, or {"detail": "...", "status_code": 401} => AuthenticationException
 *  - {"<field>": ["msg"], "error_key": "validation_error"} => ValidationException with fields
 *  - anything else => GatewayException
 */
final class KhaltiErrorParser
{
    private const META_KEYS = ['error_key', 'status_code', 'detail', 'idx'];

    private function __construct() {}

    public static function toException(Response $response): NepalPayException
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : null;
        $bodyStatus = is_int($body['status_code'] ?? null) ? $body['status_code'] : null;

        if (in_array($status, [401, 403], true) || in_array($bodyStatus, [401, 403], true)) {
            return new AuthenticationException(
                'Khalti rejected the credentials: '.($detail ?? 'authentication failed').' Check KHALTI_SECRET_KEY and the environment.',
                KhaltiGateway::NAME,
                $bodyStatus ?? $status,
            );
        }

        if ($body !== null && $status < 500) {
            $fields = self::fields($body);

            if (($body['error_key'] ?? null) === 'validation_error' || ($status === 400 && $fields !== [])) {
                if ($fields === [] && $detail !== null) {
                    $fields = ['detail' => [$detail]];
                }

                return ValidationException::withFields(
                    $fields,
                    KhaltiGateway::NAME,
                    'Khalti rejected the request: '.self::summary($fields, $detail),
                );
            }
        }

        return new GatewayException(
            $status >= 500
                ? "Khalti is unavailable (HTTP {$status})."
                : "Khalti returned an unexpected response (HTTP {$status}).",
            KhaltiGateway::NAME,
            $status,
            $body ?? $response->body(),
        );
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<string, list<string>>
     */
    public static function fields(array $body): array
    {
        $fields = [];

        foreach ($body as $key => $value) {
            if (! is_string($key) || in_array($key, self::META_KEYS, true)) {
                continue;
            }

            if (is_string($value)) {
                $fields[$key] = [$value];
            } elseif (is_array($value)) {
                $messages = array_values(array_filter($value, 'is_string'));

                if ($messages !== []) {
                    $fields[$key] = $messages;
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    private static function summary(array $fields, ?string $detail): string
    {
        $first = array_key_first($fields);

        if ($first !== null && $first !== 'detail') {
            return "{$first}: ".($fields[$first][0] ?? 'is invalid.');
        }

        return $detail ?? 'validation error.';
    }
}

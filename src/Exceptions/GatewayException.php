<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Exceptions;

use Throwable;

/**
 * The gateway answered with an unexpected status code, a 5xx, or a malformed body.
 */
final class GatewayException extends NepalPayException
{
    public const CODE = 'GATEWAY_ERROR';

    /**
     * @param  array<mixed>|string|null  $responseBody
     */
    public function __construct(
        string $message,
        string $gateway,
        private readonly ?int $statusCode = null,
        private readonly array|string|null $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, self::CODE, $gateway, [
            'status_code' => $statusCode,
            'response_body' => $responseBody,
        ], $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Decoded JSON body when available, otherwise the raw body string.
     *
     * @return array<mixed>|string|null
     */
    public function responseBody(): array|string|null
    {
        return $this->responseBody;
    }
}

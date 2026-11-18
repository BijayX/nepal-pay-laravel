<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Data;

use BijayX\NepalPay\Http\Responses\GatewayRedirectResponse;

/**
 * How to send the payer to the gateway: a GET redirect (Khalti) or an auto-submitted
 * POST form (eSewa).
 */
final readonly class RedirectInstruction
{
    public const GET = 'GET';

    public const POST = 'POST';

    /**
     * @param  'GET'|'POST'  $method
     * @param  array<string, string>  $fields  Form fields for POST redirects.
     */
    private function __construct(
        public string $method,
        public string $url,
        public array $fields = [],
    ) {}

    public static function get(string $url): self
    {
        return new self(self::GET, $url);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function post(string $url, array $fields): self
    {
        return new self(self::POST, $url, $fields);
    }

    public function isPost(): bool
    {
        return $this->method === self::POST;
    }

    public function toResponse(): GatewayRedirectResponse
    {
        return new GatewayRedirectResponse($this);
    }

    /**
     * @return array{method: 'GET'|'POST', url: string, fields: array<string, string>}
     */
    public function toArray(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'fields' => $this->fields];
    }
}

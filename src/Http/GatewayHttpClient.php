<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Http;

use BijayX\NepalPay\Exceptions\NetworkException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * Thin wrapper around Laravel's HTTP client used by the gateways.
 *
 * - Applies the configured timeout.
 * - Retries idempotent reads (lookup/status) on connection errors, timeouts and 5xx
 *   responses with exponential backoff (base * 2^attempt). Writes are never retried.
 * - Converts transport failures into NetworkException.
 * - Returns every HTTP response (including 4xx/5xx) to the caller for gateway-specific parsing.
 *
 * Request headers (which may carry secret keys) are never logged or included in exceptions.
 */
final class GatewayHttpClient
{
    public const USER_AGENT = 'bijayx/nepal-pay-laravel';

    public function __construct(
        private readonly Factory $http,
        private readonly string $gateway,
        private readonly int $timeoutMs = 15000,
        private readonly int $retries = 2,
        private readonly int $retryDelayMs = 200,
    ) {}

    /**
     * Send a JSON POST.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  bool  $idempotent  Only idempotent requests are retried.
     * @param  (Closure(Response): bool)|null  $retryWhen  Extra condition for retrying a response.
     *
     * @throws NetworkException
     */
    public function postJson(
        string $url,
        array $payload,
        array $headers = [],
        bool $idempotent = false,
        ?Closure $retryWhen = null,
    ): Response {
        return $this->send(
            fn (PendingRequest $request): Response => $request->asJson()->post($url, $payload),
            $headers,
            $idempotent,
            $retryWhen,
        );
    }

    /**
     * Send a GET request. GETs are treated as idempotent and retried.
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     * @param  (Closure(Response): bool)|null  $retryWhen
     *
     * @throws NetworkException
     */
    public function get(string $url, array $query = [], array $headers = [], ?Closure $retryWhen = null): Response
    {
        return $this->send(
            fn (PendingRequest $request): Response => $request->get($url, $query),
            $headers,
            true,
            $retryWhen,
        );
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @param  array<string, string>  $headers
     * @param  (Closure(Response): bool)|null  $retryWhen
     */
    private function send(Closure $call, array $headers, bool $idempotent, ?Closure $retryWhen): Response
    {
        $maxAttempts = $idempotent ? max(0, $this->retries) + 1 : 1;

        for ($attempt = 0; ; $attempt++) {
            $isLastAttempt = $attempt + 1 >= $maxAttempts;

            try {
                $response = $call($this->request($headers));
            } catch (ConnectionException $e) {
                if ($isLastAttempt) {
                    throw $this->networkException($e);
                }

                $this->backoff($attempt);

                continue;
            }

            if ($isLastAttempt || ! $this->shouldRetry($response, $retryWhen)) {
                return $response;
            }

            $this->backoff($attempt);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->withUserAgent(self::USER_AGENT)
            ->withHeaders($headers)
            ->withOptions([
                'timeout' => $this->timeoutMs / 1000,
                'connect_timeout' => min($this->timeoutMs, 10000) / 1000,
            ]);
    }

    /**
     * @param  (Closure(Response): bool)|null  $retryWhen
     */
    private function shouldRetry(Response $response, ?Closure $retryWhen): bool
    {
        if ($response->serverError()) {
            return true;
        }

        return $retryWhen !== null && $retryWhen($response);
    }

    private function backoff(int $attempt): void
    {
        Sleep::for($this->retryDelayMs * (2 ** $attempt))->milliseconds();
    }

    private function networkException(ConnectionException $e): NetworkException
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return NetworkException::timeout($this->gateway, $this->timeoutMs, $e);
        }

        return NetworkException::connectionFailed($this->gateway, $e);
    }
}

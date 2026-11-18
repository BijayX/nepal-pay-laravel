<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Gateways;

use BijayX\NepalPay\Contracts\PaymentGateway;
use BijayX\NepalPay\Data\ExpectedPayment;
use BijayX\NepalPay\Data\InitiatedPayment;
use BijayX\NepalPay\Data\PaymentRequest;
use BijayX\NepalPay\Data\PaymentResult;
use BijayX\NepalPay\Events\PaymentInitiated;
use BijayX\NepalPay\Events\PaymentVerified;
use BijayX\NepalPay\Exceptions\InvalidCallbackException;
use BijayX\NepalPay\Exceptions\PaymentMismatchException;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Shared behaviour for gateways: input normalisation, expectation checks and events.
 *
 * Subclasses implement createPayment() and confirm(); initiate() and verify() wrap them
 * so every gateway enforces the same verification rules.
 */
abstract class AbstractGateway implements PaymentGateway
{
    /**
     * @param  Dispatcher|(Closure(): ?Dispatcher)|null  $events  a resolver is preferred: it is called per
     *                                                            event, so Event::fake() works even when the
     *                                                            gateway was resolved before the fake
     */
    public function __construct(private readonly Dispatcher|Closure|null $events = null) {}

    /**
     * Build the gateway-specific payment for an already validated request.
     */
    abstract protected function createPayment(PaymentRequest $request): InitiatedPayment;

    /**
     * Resolve the reference from the callback and confirm the payment with the gateway's server.
     *
     * @param  array<array-key, mixed>  $callback
     */
    abstract protected function confirm(array $callback, ?ExpectedPayment $expected): PaymentResult;

    final public function initiate(PaymentRequest|array $request): InitiatedPayment
    {
        $request = $request instanceof PaymentRequest ? $request : PaymentRequest::fromArray($request);

        $payment = $this->createPayment($request);

        $this->dispatch(new PaymentInitiated($payment));

        return $payment;
    }

    final public function verify(array $callback, ?ExpectedPayment $expected = null): PaymentResult
    {
        $result = $this->confirm($callback, $expected);

        $this->assertMatchesExpectation($result, $expected);

        $this->dispatch(new PaymentVerified($result));

        return $result;
    }

    private function dispatch(object $event): void
    {
        $events = $this->events instanceof Closure ? ($this->events)() : $this->events;

        $events?->dispatch($event);
    }

    /**
     * Reject a reference that differs from the one the application stored.
     *
     * @throws PaymentMismatchException
     */
    protected function assertReference(?ExpectedPayment $expected, ?string $actual): void
    {
        if ($expected?->reference !== null && $actual !== $expected->reference) {
            throw PaymentMismatchException::reference($this->name(), $expected->reference, $actual);
        }
    }

    /**
     * Never return a completed result for another payment or a different amount.
     *
     * @throws PaymentMismatchException
     */
    protected function assertMatchesExpectation(PaymentResult $result, ?ExpectedPayment $expected): void
    {
        if ($expected === null) {
            return;
        }

        $this->assertReference($expected, $result->reference);

        if ($result->isPaid() && $result->amount !== $expected->amount) {
            throw PaymentMismatchException::amount($this->name(), $expected->amount, $result->amount);
        }
    }

    /**
     * Read a single string value from callback query parameters.
     *
     * @param  array<array-key, mixed>  $callback
     *
     * @throws InvalidCallbackException when the parameter is present but not a string
     */
    protected function callbackString(array $callback, string $key): ?string
    {
        $value = $callback[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidCallbackException(
                "The {$this->name()} callback parameter [{$key}] must be a single string value.",
                $this->name(),
                ['parameter' => $key],
            );
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

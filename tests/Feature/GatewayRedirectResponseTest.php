<?php

declare(strict_types=1);

use BijayX\NepalPay\Data\RedirectInstruction;
use BijayX\NepalPay\Facades\NepalPay;
use BijayX\NepalPay\Http\Responses\GatewayRedirectResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

it('redirects GET instructions', function () {
    $response = (new GatewayRedirectResponse(RedirectInstruction::get('https://test-pay.khalti.com/?pidx=abc')))
        ->toResponse(Request::create('/'));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getStatusCode())->toBe(302)
        ->and($response->getTargetUrl())->toBe('https://test-pay.khalti.com/?pidx=abc');
});

it('renders an auto-submitting form for POST instructions', function () {
    $payment = NepalPay::driver('esewa')->initiate(snakeInput(gatewayFixture('esewa')['initiate']['input']));

    $response = $payment->redirect()->toResponse(Request::create('/'));
    $html = (string) $response->getContent();

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($html)->toContain('<form id="nepal-pay-redirect" method="POST" action="https://rc-epay.esewa.com.np/api/epay/main/v2/form">')
        ->and($html)->toContain('<noscript>')
        ->and($html)->toContain('<button type="submit">')
        ->and($html)->toContain('.submit();')
        ->and($html)->not->toContain('<link')
        ->and($html)->not->toContain('src=');

    foreach ($payment->redirect->fields as $name => $value) {
        expect($html)->toContain('name="'.e($name).'" value="'.e($value).'"');
    }
});

it('escapes field values and the action', function () {
    $html = (string) (new GatewayRedirectResponse(RedirectInstruction::post(
        'https://pay.test/?a=1&b="2"',
        ['note' => '"><script>alert(1)</script>'],
    )))->toResponse(Request::create('/'))->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('action="https://pay.test/?a=1&amp;b=&quot;2&quot;"');
});

it('adds a CSP nonce to the inline script when provided', function () {
    $response = fn (?string $nonce) => (string) (new GatewayRedirectResponse(RedirectInstruction::post('https://pay.test', [])))
        ->withNonce($nonce)
        ->toResponse(Request::create('/'))
        ->getContent();

    expect($response('abc123'))->toMatch('/<script\s+nonce="abc123"\s*>/')
        ->and($response(null))->not->toContain('nonce=');
});

it('works as a controller return value', function () {
    Route::get('/pay', fn () => NepalPay::driver('esewa')->initiate([
        'amount' => 1000, 'order_id' => 'ORD-1', 'order_name' => 'x', 'return_url' => 'https://shop.test/return',
    ])->redirect());

    $this->get('/pay')
        ->assertOk()
        ->assertSee('name="transaction_uuid" value="ORD-1"', false);
});

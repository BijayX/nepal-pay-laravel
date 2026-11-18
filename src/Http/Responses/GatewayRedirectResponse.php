<?php

declare(strict_types=1);

namespace BijayX\NepalPay\Http\Responses;

use BijayX\NepalPay\Data\RedirectInstruction;
use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sends the payer to the gateway.
 *
 * GET instructions (Khalti) become a 302 redirect. POST instructions (eSewa) render a
 * small HTML page that auto-submits a form to the gateway, with a button fallback for
 * browsers without JavaScript.
 */
final class GatewayRedirectResponse implements Responsable
{
    /** @var view-string */
    public const VIEW = 'nepal-pay::redirect';

    private ?string $nonce = null;

    public function __construct(private readonly RedirectInstruction $instruction) {}

    /**
     * Add a CSP nonce to the inline auto-submit script (e.g. Vite::cspNonce()).
     */
    public function withNonce(?string $nonce): self
    {
        $this->nonce = $nonce;

        return $this;
    }

    public function instruction(): RedirectInstruction
    {
        return $this->instruction;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse|Response
    {
        if (! $this->instruction->isPost()) {
            return new RedirectResponse($this->instruction->url, 302, ['Cache-Control' => 'no-store, private']);
        }

        /** @var ViewFactory $views */
        $views = Container::getInstance()->make(ViewFactory::class);

        $html = $views->make(self::VIEW, [
            'action' => $this->instruction->url,
            'fields' => $this->instruction->fields,
            'nonce' => $this->nonce,
        ])->render();

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}

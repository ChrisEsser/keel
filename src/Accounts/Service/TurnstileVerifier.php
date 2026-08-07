<?php

declare(strict_types=1);

namespace Framework\Accounts\Service;

// Server-side verification of a Cloudflare Turnstile challenge response. Turnstile is applied
// automatically to every published form and to the products checkout whenever it's configured
// (both keys set) — see SiteController::handleFormSubmit()/handleProductsCheckout(). When it's
// not configured, isConfigured() is false everywhere and the whole feature no-ops.
class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private string $secretKey, private string $siteKey = '') {}

    // True only when both keys are present — the single gate every caller uses to decide whether
    // to render/require a challenge. Lets local/dev and unconfigured deploys submit forms and
    // checkouts with no Turnstile at all.
    public function isConfigured(): bool
    {
        return $this->secretKey !== '' && $this->siteKey !== '';
    }

    // The public site key, safe to hand to the browser (it's rendered into the widget). Empty
    // unless configured.
    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function verify(string $token, string $remoteIp): bool
    {
        if ($this->secretKey === '' || $token === '') return false;

        $body = http_build_query([
            'secret' => $this->secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $opts = [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ];

        $context = stream_context_create(['http' => $opts]);
        $raw = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($raw === false) return false;

        $result = json_decode($raw, true);
        return !empty($result['success']);
    }
}

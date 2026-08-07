<?php

declare(strict_types=1);

namespace Framework\Accounts\Service;

use Framework\Accounts\Model\RateLimitModel;

// One place that decides whether a submission to a PUBLIC platform form (login, signup,
// forgot-password, verification resend) is allowed through. Every limit the platform enforces on
// logged-out visitors is listed in LIMITS below, so the policy is readable in one screen instead
// of scattered as magic numbers across controllers.
//
// Four layers, cheapest first — a request that fails an early one never reaches the later ones:
//
//   1. Honeypot   — a field real users never see. Filled in = bot.
//   2. Timing     — humans don't submit a login form 300ms after it renders.
//   3. Rate limit — sliding per-IP and per-email windows (Framework\Accounts\Model\RateLimitModel).
//   4. Turnstile  — Cloudflare's challenge, verified server-side. Skipped entirely when the
//                   platform has no Turnstile keys, so local dev and unconfigured deploys work.
//
// This mirrors what hosted customer forms already do in SiteController::handleFormSubmit(); the
// platform's own forms simply had none of it.
//
// Controllers own their responses — this returns a verdict, never a Response, because each caller
// renders a different view. The verdict's `silent` flag means "pretend it worked": for honeypot
// and timing hits we deliberately fake success so a bot can't tell a rejection from a real one and
// start tuning. A human who somehow trips these can retry; a bot learns nothing.
class PublicFormGuard
{
    // action => [maxPerIp, ipWindowSeconds, maxPerEmail, emailWindowSeconds]
    //
    // The email windows are the ones that matter for abuse: they cap how many messages a single
    // address can be made to receive, which is what stops this platform being used as a mail
    // cannon and what protects the sending domain's reputation. IP windows are deliberately
    // looser — offices and schools share addresses.
    private const LIMITS = [
        // Failed logins only (successes clear the bucket), on top of the existing per-account
        // lockout in AuthController. This is the layer that catches spraying: one attempt each
        // across thousands of addresses never trips a per-account limit.
        'login' => [20, 900, 10, 900],
        // Password-reset requests. 3 emails/hour to any one address is generous for a real user
        // who mistypes their address once.
        'pwreset' => [10, 3600, 3, 3600],
        // Signups. Each one sends a verification (or "you already have an account") email.
        'signup' => [5, 3600, 3, 3600],
        // "Resend the verification email" — same address every time, so the email cap is the
        // real control.
        'resend' => [10, 3600, 5, 3600],
        // Creating an account from an invitation. The odd one out: it sends no mail, so the email
        // bucket here is not protecting a mailbox — it caps how hard one specific invitation can
        // be hammered, which is what a stolen or guessed token would look like.
        'invite' => [10, 3600, 5, 3600],
    ];

    // Below this, the submission came from a script, not a person filling in fields.
    private const MIN_ELAPSED_MS = 1200;

    public function __construct(private TurnstileVerifier $turnstile) {}

    // True when Turnstile is available, so views know whether to render the widget.
    public function turnstileEnabled(): bool
    {
        return $this->turnstile->isConfigured();
    }

    public function turnstileSiteKey(): string
    {
        return $this->turnstile->siteKey();
    }

    /**
     * @param array<string,mixed> $input the raw request body
     * @param string $email the address this action would send to, if any
     */
    public function check(string $action, array $input, string $ip, string $email = ''): GuardVerdict
    {
        // 1. Honeypot — `_hp` is hidden from real users by CSS and left empty by real browsers.
        if (trim((string) ($input['_hp'] ?? '')) !== '') {
            return GuardVerdict::silent();
        }

        // 2. Timing — `_elapsed_ms` is stamped by the browser when the form is first rendered.
        // A missing value means JS never ran; we do NOT reject for that alone, because the form
        // must still work without JS. Only an implausibly FAST submission is a bot signal.
        $elapsed = (int) ($input['_elapsed_ms'] ?? 0);
        if ($elapsed > 0 && $elapsed < self::MIN_ELAPSED_MS) {
            return GuardVerdict::silent();
        }

        // 3. Rate limits.
        [$maxIp, $ipWindow, $maxEmail, $emailWindow] = self::LIMITS[$action]
            ?? throw new \InvalidArgumentException("Unknown guarded action: $action");

        if (RateLimitModel::tooMany(RateLimitModel::ipBucket($action, $ip), $maxIp, $ipWindow)) {
            return GuardVerdict::blocked('rate');
        }
        if ($email !== '' && RateLimitModel::tooMany(RateLimitModel::emailBucket($action, $email), $maxEmail, $emailWindow)) {
            // Deliberately the same verdict as the IP limit. Telling the caller "this specific
            // address is rate limited" would confirm the address exists.
            return GuardVerdict::blocked('rate');
        }

        // 4. Turnstile, last because it costs a network round trip.
        if ($this->turnstile->isConfigured()) {
            $token = (string) ($input['cf-turnstile-response'] ?? '');
            if (!$this->turnstile->verify($token, $ip)) {
                return GuardVerdict::blocked('turnstile');
            }
        }

        return GuardVerdict::ok();
    }

    // Record an attempt against both buckets. Call AFTER check(), and for login only on failure —
    // see RateLimitModel's note on why a refused request must not extend its own window.
    public function record(string $action, string $ip, string $email = ''): void
    {
        RateLimitModel::hit(RateLimitModel::ipBucket($action, $ip));
        if ($email !== '') {
            RateLimitModel::hit(RateLimitModel::emailBucket($action, $email));
        }
    }

    // Wipe the buckets for a successful actor (a real login), so earlier failures don't count
    // against them.
    public function forget(string $action, string $ip, string $email = ''): void
    {
        RateLimitModel::clear(RateLimitModel::ipBucket($action, $ip));
        if ($email !== '') {
            RateLimitModel::clear(RateLimitModel::emailBucket($action, $email));
        }
    }
}

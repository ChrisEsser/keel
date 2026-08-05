<?php

declare(strict_types=1);

namespace Keel\Accounts\Service;

// The result of a PublicFormGuard::check(). Deliberately not a Response: each caller renders its
// own view, so the guard reports what it found and the controller decides how that looks.
//
// `silent` is the interesting state. A honeypot or timing hit means we're near-certain this is a
// bot, and the right response is to look exactly like success — no error, no hint, nothing to tune
// against. The controller should skip the real work (don't send the email, don't create the row)
// and render whatever the happy path renders.
final class GuardVerdict
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $silent,
        public readonly string $reason,
    ) {}

    public static function ok(): self
    {
        return new self(true, false, '');
    }

    // Caught by honeypot/timing: refuse the work, but show the caller a success page.
    public static function silent(): self
    {
        return new self(false, true, 'bot');
    }

    // 'rate' or 'turnstile' — refuse and say so, because a real user can act on both.
    public static function blocked(string $reason): self
    {
        return new self(false, false, $reason);
    }

    // The message to show a human. Kept here so the wording stays consistent across every public
    // form, and vague enough on the rate-limit path that it never confirms an address exists.
    public function message(): string
    {
        return match ($this->reason) {
            'turnstile' => 'Verification failed, please try again.',
            'rate' => 'Too many attempts. Please wait a few minutes and try again.',
            default => 'Something went wrong, please try again.',
        };
    }
}

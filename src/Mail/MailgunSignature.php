<?php

declare(strict_types=1);

namespace Framework\Mail;

/**
 * Verifies a Mailgun webhook signature.
 *
 * Every Mailgun webhook -- delivery events (JSON) and inbound routes (form fields) alike -- signs
 * `timestamp + token` with the account-wide WEBHOOK SIGNING KEY, which is a different secret from
 * the API key used to send. Getting those two confused fails as "every webhook is forged", with
 * nothing on either side naming the real problem.
 *
 * Kept apart from any one ingestor so that an application handling both event and inbound hooks
 * cannot end up with two readings of the same signature.
 */
final class MailgunSignature
{
    // How stale a signature may be before it is treated as a replay. Mailgun retries a failed
    // delivery for hours, but each retry is re-signed with a fresh timestamp, so a short window
    // costs nothing.
    public const MAX_AGE = 900;

    public static function verify(
        string $timestamp,
        string $token,
        string $provided,
        string $signingKey,
        int $maxAge = self::MAX_AGE,
    ): bool {
        // An unset key must not read as "nothing to check against, let it through". Logged rather
        // than thrown, because the caller is a webhook endpoint whose only other option is to
        // answer 200 to a forgery.
        if ($signingKey === '') {
            error_log('MailgunSignature: no webhook signing key configured; rejecting webhook.');
            return false;
        }
        if ($timestamp === '' || $token === '' || $provided === '') {
            return false;
        }
        if (abs(time() - (int) $timestamp) > $maxAge) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . $token, $signingKey), $provided);
    }
}

<?php

declare(strict_types=1);

namespace Framework\Mail;

// Deliberately separate from MailProviderInterface rather than widening it. That interface has six
// other callers -- password resets, invitations, order confirmations, 2FA -- and none of them want a
// batch method; adding one there would force LogSmsProvider-style no-op implementations across all of
// them for the benefit of a single caller. The worker checks `instanceof` and falls back to a
// per-recipient send() loop, which is exactly what the log provider needs anyway.
interface BatchMailProviderInterface
{
    /**
     * Sends one message to many recipients in a single API call, with per-recipient substitution.
     *
     * @param array $recipients  [['email' => ..., 'variables' => ['first_name' => ..., ...]], ...]
     * @param array $headers     Extra headers, per-recipient values allowed (e.g. List-Unsubscribe).
     * @return array{ok: bool, message_id: ?string, error: ?string}
     */
    public function sendBatch(
        array $recipients,
        string $fromEmail,
        string $fromName,
        string $subject,
        string $textBody,
        string $htmlBody,
        ?string $domain = null,
        array $headers = [],
        array $options = []
    ): array;

    // Providers cap how many recipients one call may carry (Mailgun: 1000).
    public function maxBatchSize(): int;
}

<?php

declare(strict_types=1);

namespace Framework\Mail;

// A thin seam over MailProviderInterface: one place to send a single message, with the provider
// (log in dev, Mailgun in prod) swapped underneath.
//
// This class briefly had two methods. The other send() was plaintext-only, took three arguments,
// and defaulted the From to MAIL_FROM_* -- and because it was the shortest path, every
// transactional email in the app used it, which is precisely why none of them had an HTML part or
// could send as anyone but the platform. They now compose through Framework\Mail\AppMailer, which
// builds a multipart message and resolves its own From, so that method lost its last caller and
// was deleted rather than left as a shortcut back to unstyled, platform-only mail. What remains
// took its name, because sending is all it does.
class Mailer
{
    public function __construct(private MailProviderInterface $provider) {}

    // $text is not optional in practice: MailgunProvider omits the field entirely when it's
    // empty, and an HTML-only message is both a spam signal and unreadable in a text client.
    // $domain selects a customer's verified sending domain; null routes through the platform's.
    public function send(
        string $to,
        string $fromEmail,
        string $fromName,
        string $subject,
        string $html,
        string $text = '',
        ?string $domain = null,
        array $headers = []
    ): bool {
        return $this->provider->send($to, $fromEmail, $fromName, $subject, $text, $html, $domain, $headers);
    }
}

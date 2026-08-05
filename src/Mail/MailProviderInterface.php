<?php

declare(strict_types=1);

namespace Keel\Mail;

interface MailProviderInterface
{
    public function send(
        string $to,
        string $fromEmail,
        string $fromName,
        string $subject,
        string $textBody,
        string $htmlBody = '',
        ?string $domain = null,
        array $headers = []
    ): bool;
}

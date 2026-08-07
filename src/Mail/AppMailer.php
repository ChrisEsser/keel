<?php

declare(strict_types=1);

namespace Framework\Mail;

use Framework\Accounts\Service\EmailBlocks;
use Framework\Brand;
use Framework\View\View;

// Sends an EmailBlocks as the application: wraps its blocks in views/emails/layout.php, pairs the
// HTML with the plaintext the builder accumulated alongside it, and puts the app's own From on it.
//
// Framework\Mail\Mailer::send() already does the sending; what it doesn't do is decide who the mail is
// from, which every system-mail call site would otherwise have to repeat. This class is that
// decision in one place.
//
// Multipart matters more than it looks: MailgunProvider omits the text field when it's empty,
// so an HTML-only transactional email is both a spam signal and unreadable in a text client.
class AppMailer
{
    public function __construct(
        private Mailer $mailer,
        private View $view,
    ) {}

    // A method rather than a constant: the app's name comes from the environment, and a constant
    // expression can't call for it.
    public static function defaultReason(): string
    {
        return "You're receiving this because you have a " . Brand::name() . ' account.';
    }

    /**
     * @param string|null $reason Footer line explaining why this person got the mail. The default
     *                            is true of every system email except notices sent to a configured
     *                            address that need not be an account.
     * @param string|null $fromEmail Override the default From address — for mail that must be
     *                               replied to somewhere specific rather than to the app.
     * @param array<string,string> $headers Extra MIME headers (Reply-To, Message-Id, In-Reply-To, References).
     */
    public function send(
        string $to,
        string $subject,
        EmailBlocks $email,
        ?string $reason = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $headers = [],
    ): bool {
        $html = $this->view->render('emails/layout', [
            'preheader' => $email->resolvedPreheader(),
            'title' => $subject,
            'content' => $email->toHtml(),
            'footerReason' => $reason ?? self::defaultReason(),
        ], null);

        return $this->mailer->send(
            to: $to,
            fromEmail: $fromEmail ?? ($_ENV['MAIL_FROM_EMAIL'] ?? 'noreply@example.com'),
            fromName: $fromName ?? ($_ENV['MAIL_FROM_NAME'] ?? Brand::name()),
            subject: $subject,
            html: $html,
            text: $this->text($email, $reason),
            headers: $headers,
        );
    }

    // The plaintext twin gets the same sign-off and footer reason the HTML carries, so the two
    // parts say the same thing rather than the text part looking truncated.
    private function text(EmailBlocks $email, ?string $reason): string
    {
        return $email->toText()
            . "\n\n-- The " . Brand::name() . " team\n\n"
            . str_repeat('-', 48) . "\n"
            . ($reason ?? self::defaultReason());
    }
}

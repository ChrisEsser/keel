<?php

declare(strict_types=1);

namespace Keel\Mail;

class LogMailProvider implements MailProviderInterface
{
    private string $logDir;

    /**
     * The storage root is passed in rather than derived from __DIR__: this class ships inside a
     * Composer package, so its own location says nothing about where the host application keeps
     * its writable directories.
     */
    public function __construct(string $storagePath)
    {
        $this->logDir = rtrim($storagePath, '/') . '/mail';
    }

    public function send(
        string $to,
        string $fromEmail,
        string $fromName,
        string $subject,
        string $textBody,
        string $htmlBody = '',
        ?string $domain = null,
        array $headers = []
    ): bool {
        $from = $fromName !== '' ? "$fromName <$fromEmail>" : $fromEmail;
        $logFile = $this->logDir . '/' . date('Y-m-d') . '.log';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        // Plain text only — the dev log is for reading what went out, and the full HTML body just
        // buries it. The real multipart email still carries the HTML; only this log drops it.
        $parts = [
            '[' . date('Y-m-d H:i:s') . ']',
            'TO:      ' . $to,
            'FROM:    ' . $from,
            'SUBJECT: ' . $subject,
        ];
        // Surface custom headers (Reply-To/threading) so the dev log shows what a client would thread on.
        foreach ($headers as $name => $value) {
            $parts[] = strtoupper($name) . ': ' . $value;
        }
        $parts[] = '';
        $parts[] = $textBody;

        $parts[] = '';
        $parts[] = str_repeat('-', 60);
        $parts[] = '';

        $isNewFile = !file_exists($logFile);
        $result = @file_put_contents($logFile, implode("\n", $parts), FILE_APPEND);

        if ($isNewFile) {
            // The log is written by whichever process handles the request first — CLI scripts
            // (migrations, cron) and the web server often run as different users, so make the
            // file writable by both rather than letting the first writer lock the rest out.
            @chmod($logFile, 0666);
        }

        if ($result === false) {
            error_log("LogMailProvider: failed to write to $logFile (check file permissions) — email to $to was not logged");
        }

        return $result !== false;
    }
}

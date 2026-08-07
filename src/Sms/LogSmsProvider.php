<?php

declare(strict_types=1);

namespace Framework\Sms;

class LogSmsProvider implements SmsProviderInterface
{
    private string $logDir;

    /** See LogMailProvider: the storage root is the host application's to name, not ours. */
    public function __construct(string $storagePath)
    {
        $this->logDir = rtrim($storagePath, '/') . '/sms';
    }

    public function send(string $to, string $body): bool
    {
        $logFile = $this->logDir . '/' . date('Y-m-d') . '.log';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $parts = [
            '[' . date('Y-m-d H:i:s') . ']',
            'TO:   ' . $to,
            'BODY: ' . $body,
            '',
            str_repeat('-', 60),
            '',
        ];

        file_put_contents($logFile, implode("\n", $parts), FILE_APPEND);
        return true;
    }
}

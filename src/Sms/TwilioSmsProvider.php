<?php

declare(strict_types=1);

namespace Framework\Sms;

class TwilioSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private string $accountSid,
        private string $authToken,
        private string $fromNumber,
    ) {}

    public function send(string $to, string $body): bool
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

        $fields = http_build_query([
            'To'   => $to,
            'From' => $this->fromNumber,
            'Body' => $body,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Authorization: Basic ' . base64_encode("{$this->accountSid}:{$this->authToken}"),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($fields),
                ]),
                'content'       => $fields,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        $statusCode = (int) (explode(' ', $statusLine)[1] ?? 500);
        return $statusCode >= 200 && $statusCode < 300;
    }
}

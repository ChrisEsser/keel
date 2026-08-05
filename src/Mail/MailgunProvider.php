<?php

declare(strict_types=1);

namespace Keel\Mail;

class MailgunProvider implements MailProviderInterface, BatchMailProviderInterface
{
    // Mailgun's documented ceiling for recipients in a single messages call.
    private const MAX_BATCH = 1000;

    // EU-region accounts live on a different host entirely, and calling the US host with EU
    // credentials fails as a 401 rather than anything that names the real problem.
    private string $apiBase;

    public function __construct(
        private string $apiKey,
        private string $domain,
        string $region = 'us',
    ) {
        $this->apiBase = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';
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
        $data = [
            'from'    => $this->formatFrom($fromEmail, $fromName),
            'to'      => $to,
            'subject' => $subject,
            'text'    => $textBody,
        ];
        if ($htmlBody !== '') {
            $data['html'] = $htmlBody;
        }
        // Custom MIME headers (Reply-To, Message-Id, In-Reply-To, References) go through Mailgun's
        // h: prefix, same as sendBatch. Used by the request thread so replies thread in the client.
        foreach ($headers as $name => $value) {
            $data['h:' . $name] = $value;
        }

        return $this->post($domain ?? $this->domain, $data)['ok'];
    }

    // One API call for up to 1000 recipients. The win is that a 60KB HTML body crosses the wire once
    // per batch instead of once per recipient; Mailgun expands %recipient.x% per address on its side.
    //
    // Mailgun addresses each recipient separately (they never see each other) as long as
    // recipient-variables is present -- without it, this would leak the whole list in the To header.
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
    ): array {
        if ($recipients === []) {
            return ['ok' => true, 'message_id' => null, 'error' => null];
        }
        if (count($recipients) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Batch exceeds ' . self::MAX_BATCH . ' recipients.');
        }

        $variables = [];
        foreach ($recipients as $r) {
            $variables[$r['email']] = $r['variables'] ?? [];
        }

        $data = [
            'from'                => $this->formatFrom($fromEmail, $fromName),
            'to'                  => implode(',', array_column($recipients, 'email')),
            'subject'             => $subject,
            'text'                => $textBody,
            'recipient-variables' => json_encode($variables),
        ];
        if ($htmlBody !== '') {
            $data['html'] = $htmlBody;
        }

        foreach ($headers as $name => $value) {
            $data['h:' . $name] = $value;
        }
        foreach ($options as $name => $value) {
            $data[$name] = $value;
        }

        return $this->post($domain ?? $this->domain, $data);
    }

    public function maxBatchSize(): int
    {
        return self::MAX_BATCH;
    }

    private function formatFrom(string $email, string $name): string
    {
        return $name !== '' ? "$name <$email>" : $email;
    }

    // Returns the parsed result rather than a bare bool: the batch path needs the message id (a
    // fallback key for mapping webhook events back to recipients) and the error body (to store on the
    // failed rows). The old version read only the status code and threw the body away.
    private function post(string $sendingDomain, array $data): array
    {
        $fields = http_build_query($data);
        $url = "{$this->apiBase}/{$sendingDomain}/messages";

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Authorization: Basic ' . base64_encode("api:{$this->apiKey}"),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($fields),
                ]),
                'content'       => $fields,
                'ignore_errors' => true,
                // Without this a hung connection stalls the cron worker indefinitely while it holds
                // the send lock, blocking every later tick. Generous, because a 1000-recipient batch
                // is a large request.
                'timeout'       => 30,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return ['ok' => false, 'message_id' => null, 'error' => 'Could not reach Mailgun.'];
        }

        // $http_response_header is magically populated by file_get_contents in the calling function's
        // scope -- it only exists here, so any refactor must keep the read in this function.
        $status = (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 500')[1] ?? 500);
        $body = json_decode($raw, true) ?? [];

        if ($status < 200 || $status >= 300) {
            return [
                'ok'         => false,
                'message_id' => null,
                'error'      => $body['message'] ?? "Mailgun returned HTTP {$status}.",
            ];
        }

        return [
            'ok'         => true,
            // Mailgun returns "<20260715...@domain>"; the angle brackets aren't part of the id that
            // comes back on webhook events.
            'message_id' => isset($body['id']) ? trim((string) $body['id'], '<>') : null,
            'error'      => null,
        ];
    }
}

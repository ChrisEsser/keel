<?php

declare(strict_types=1);

namespace Keel\Mail;

class MailgunDomainManager
{
    private string $apiBase;

    // Events we need to answer "did it land, did they read it". temporary_fail is included because a
    // soft bounce is worth showing but must never suppress the address.
    private const WEBHOOK_EVENTS = [
        'delivered', 'permanent_fail', 'temporary_fail', 'complained', 'opened', 'clicked', 'unsubscribed',
    ];

    // EU-region accounts are served from a different host; the US host rejects EU credentials with a
    // bare 401 that says nothing about the region.
    public function __construct(private string $apiKey, string $region = 'us')
    {
        $this->apiBase = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';
    }

    // $domain is the sending subdomain (mail.acme.com), never the customer's root domain -- the root
    // keeps its existing SPF untouched. spam_action=disabled because we do our own suppression.
    public function register(string $domain): array
    {
        $fields = http_build_query(['name' => $domain, 'spam_action' => 'disabled']);
        $body = $this->request('POST', '/domains', $fields);
        return $body['sending_dns_records'] ?? [];
    }

    public function getDnsRecords(string $domain): array
    {
        $body = $this->request('GET', '/domains/' . urlencode($domain));
        return $body['sending_dns_records'] ?? [];
    }

    // The full domain record, or null if this account doesn't have it. Lets a domain that already
    // exists at Mailgun be adopted rather than re-registered -- which covers a sandbox domain (created
    // by Mailgun itself, so register() would always fail) and anyone who added their domain in the
    // dashboard before connecting it here.
    public function find(string $domain): ?array
    {
        try {
            return $this->request('GET', '/domains/' . urlencode($domain));
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public function verify(string $domain): bool
    {
        $body = $this->request('PUT', '/domains/' . urlencode($domain) . '/verify');
        return ($body['domain']['state'] ?? '') === 'active';
    }

    public function delete(string $domain): void
    {
        $this->request('DELETE', '/domains/' . urlencode($domain));
    }

    // Domains are created programmatically, so webhooks configured by hand in the Mailgun dashboard
    // would silently miss every domain added after the fact -- and a domain with no webhooks reports
    // zero opens, zero bounces, and never suppresses anything, while looking perfectly healthy.
    // Idempotent: an already-registered event returns 400, which is fine to ignore.
    public function ensureWebhooks(string $domain, string $callbackUrl): void
    {
        foreach (self::WEBHOOK_EVENTS as $event) {
            try {
                $this->request(
                    'POST',
                    '/domains/' . urlencode($domain) . '/webhooks',
                    http_build_query(['id' => $event, 'url' => $callbackUrl])
                );
            } catch (\RuntimeException $e) {
                // Already registered, or a transient failure. Never block domain setup on it.
                error_log("MailgunDomainManager: webhook '{$event}' for {$domain}: " . $e->getMessage());
            }
        }
    }

    private function request(string $method, string $path, string $body = ''): array
    {
        $headers = [
            'Authorization: Basic ' . base64_encode("api:{$this->apiKey}"),
        ];

        // ignore_errors keeps file_get_contents from returning false on a 4xx so we can read the error
        // body; the status check below is what actually decides success.
        $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15];

        if ($body !== '') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
            $opts['content'] = $body;
        }

        $opts['header'] = implode("\r\n", $headers);

        $context = stream_context_create(['http' => $opts]);
        $raw = @file_get_contents($this->apiBase . $path, false, $context);

        if ($raw === false) {
            throw new \RuntimeException('Could not reach Mailgun. Please try again.');
        }

        // $http_response_header is populated by file_get_contents in this function's local scope.
        $status = (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 500')[1] ?? 500);
        $decoded = json_decode($raw, true) ?? [];

        if ($status < 200 || $status >= 300) {
            // Mailgun puts the human-readable reason in 'message'. Surfacing it matters: "domain
            // already exists" is a routine response users need to see, and swallowing it here used to
            // save an empty DNS record set and report success.
            throw new \RuntimeException($decoded['message'] ?? "Mailgun returned HTTP {$status}.");
        }

        return $decoded;
    }
}

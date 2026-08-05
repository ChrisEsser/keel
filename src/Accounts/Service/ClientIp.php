<?php

declare(strict_types=1);

namespace Keel\Accounts\Service;

// The visitor's real IP address, resolved through the reverse proxy.
//
// Caddy terminates TLS and proxies plaintext to Apache on 127.0.0.1 (see config/Caddyfile.example),
// so `$_SERVER['REMOTE_ADDR']` is the PROXY's address on every production request, not the
// visitor's. Anything keyed on the raw value -- rate limits, analytics visitor hashes, Turnstile's
// remoteip, API-key allow-lists -- silently collapses to a single address once Caddy is in front.
// This class is the one place that knows how to unwrap that, mirroring how PlatformHost owns the
// X-Forwarded-Proto rule.
//
// Trust model: X-Forwarded-For is caller-supplied and trivially spoofed, so it is honoured ONLY
// when the connection itself comes from a proxy we trust. We walk the chain from the right,
// discarding trusted hops, and take the first address that isn't one of ours -- the last party the
// trusted proxy actually accepted a connection from. A client that pre-seeds the header with junk
// gets that junk left-of-ours and ignored.
//
// TRUSTED_PROXIES (config/.env) is a comma-separated list of IPs/CIDRs; it defaults to loopback,
// which is exactly the Caddy-on-the-same-box topology. Leave it empty to disable header trust
// entirely and always use the direct peer.
class ClientIp
{
    private const DEFAULT_TRUSTED = '127.0.0.1,::1';

    public static function resolve(): string
    {
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($peer === '') return '';

        $trusted = self::trustedProxies();
        if ($trusted === [] || !self::isTrusted($peer, $trusted)) {
            // Direct connection (or a peer we don't trust): the socket address is the truth.
            return $peer;
        }

        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') return $peer;

        $chain = array_values(array_filter(array_map('trim', explode(',', $forwarded))));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = self::stripPort($chain[$i]);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_IP)) continue;
            if (self::isTrusted($candidate, $trusted)) continue;
            return $candidate;
        }

        // Every hop was a trusted proxy, so there is no client address to recover.
        return $peer;
    }

    /** @return string[] */
    private static function trustedProxies(): array
    {
        $raw = trim((string) ($_ENV['TRUSTED_PROXIES'] ?? self::DEFAULT_TRUSTED));
        if ($raw === '') return [];

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** @param string[] $trusted */
    private static function isTrusted(string $ip, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if (self::matches($ip, $entry)) return true;
        }
        return false;
    }

    // Exact match, or IPv4/IPv6 CIDR containment. Kept self-contained rather than shared with
    // ApiKeyAuth::ipMatches(), which is IPv4-only and answers a different question (does the
    // CUSTOMER allow this address) -- merging them would couple an auth check to a plumbing detail.
    private static function matches(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) return $ip === $entry;

        [$subnet, $bits] = explode('/', $entry, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) return false;
        if (strlen($ipBin) !== strlen($subnetBin)) return false; // never compare v4 against v6

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;
        if ($remainder === 0) return true;

        $mask = chr(0xFF << (8 - $remainder) & 0xFF);
        return (($ipBin[$bytes] ?? "\0") & $mask) === (($subnetBin[$bytes] ?? "\0") & $mask);
    }

    // "203.0.113.5:41234" and "[2001:db8::1]:443" both appear in the wild.
    private static function stripPort(string $value): string
    {
        if (str_starts_with($value, '[')) {
            $close = strpos($value, ']');
            return $close === false ? $value : substr($value, 1, $close - 1);
        }

        // Only strip for IPv4-with-port; a bare IPv6 address is full of colons.
        if (substr_count($value, ':') === 1) {
            return explode(':', $value)[0];
        }

        return $value;
    }
}

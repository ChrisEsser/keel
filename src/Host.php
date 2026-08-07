<?php

declare(strict_types=1);

namespace Framework;

// Single owner of the APP_URL <-> APP_DOMAIN relationship. Two host families share one
// DocumentRoot and one front controller:
//
//   app.APP_DOMAIN             -> the application UI + API   (HostKind::App)
//   APP_DOMAIN, www.APP_DOMAIN -> the public marketing site  (HostKind::Marketing)
//
// The app host is DERIVED from APP_URL rather than configured separately: APP_URL has to be
// correct anyway (every emailed link is built from it), and a second env var is just a second
// thing that can disagree with it.
//
// An install that never sets APP_DOMAIN is a single-host app: everything classifies as App and
// the marketing router is simply never reached. That is the default, and it is why a brand-new
// project works before anyone has thought about hostnames.
final class Host
{
    // Loopback is always the app: `php -S localhost:8000`, health checks and CLI-ish requests
    // have no meaningful vhost, and a blank Host header means the same thing.
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1'];

    public static function normalize(string $raw): string
    {
        $host = strtolower(trim($raw));

        // Strip the port -- but not from a bare IPv6 literal, where /:\d+$/ would eat `::1`'s
        // last group and leave ":". Bracketed forms ([::1], [::1]:80) unwrap to the address.
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end !== false ? substr($host, 1, $end - 1) : $host;
        } elseif (substr_count($host, ':') === 1) {
            $host = (string) preg_replace('/:\d+$/', '', $host);
        }

        return rtrim($host, '.'); // a trailing dot is a legal FQDN in a Host header
    }

    public static function appDomain(): string
    {
        return self::normalize((string) ($_ENV['APP_DOMAIN'] ?? ''));
    }

    // Host of APP_URL, e.g. "app.example.com". Empty when APP_URL is blank or unparseable.
    public static function appHost(): string
    {
        $host = parse_url((string) ($_ENV['APP_URL'] ?? ''), PHP_URL_HOST);
        return is_string($host) ? self::normalize($host) : '';
    }

    // Base URL for app links, no trailing slash. Everything emitted off the app -- emails,
    // redirects, webhook URLs -- must be absolute off this, because app routes exist only on
    // the app host.
    public static function appUrl(string $path = ''): string
    {
        return rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . $path;
    }

    // The subdomain label the app occupies under APP_DOMAIN ("app" for app.example.com), or
    // null when APP_URL is not a single-label subdomain of APP_DOMAIN -- i.e. the app is on the
    // apex, or lives on an unrelated domain. An application that hands subdomains out to its
    // own users should reserve this label.
    public static function appLabel(): ?string
    {
        $appHost = self::appHost();
        $appDomain = self::appDomain();

        if ($appDomain === '' || $appHost === '' || !str_ends_with($appHost, '.' . $appDomain)) {
            return null;
        }

        $label = substr($appHost, 0, -strlen($appDomain) - 1);

        // Deeper nesting (a.b.APP_DOMAIN) isn't a label anyone could claim anyway.
        return str_contains($label, '.') ? null : $label;
    }

    // Request scheme, honoring a reverse proxy's X-Forwarded-Proto and falling back to the
    // direct-connection signal.
    public static function requestScheme(): string
    {
        $forwarded = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
        if ($forwarded !== '') {
            return $forwarded === 'https' ? 'https' : 'http';
        }
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    public static function classify(string $rawHost): HostKind
    {
        $host = self::normalize($rawHost);

        if ($host === '' || in_array($host, self::LOOPBACK, true)) {
            return HostKind::App;
        }

        // No APP_DOMAIN means host-based routing is off entirely (single-host install).
        $appDomain = self::appDomain();
        if ($appDomain === '') {
            return HostKind::App;
        }

        $appHost = self::appHost();
        if ($appHost !== '' && $host === $appHost) {
            return HostKind::App;
        }

        if ($host === $appDomain || $host === 'www.' . $appDomain) {
            // The app hasn't moved off the apex (APP_URL still points there, or is blank), so the
            // apex IS the app and there is no marketing host. This is what lets an install that
            // never split the two keep behaving exactly as it always did.
            return ($appHost === '' || $appHost === $appDomain || $appHost === 'www.' . $appDomain)
                ? HostKind::App
                : HostKind::Marketing;
        }

        // An unrecognized host reaching this vhost is treated as marketing rather than 404'd at
        // the bootstrap: it is the surface with nothing behind a session, so a misconfigured DNS
        // record lands on a public page instead of an error.
        return HostKind::Marketing;
    }
}

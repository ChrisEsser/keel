<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * Marks a response as safe for a *shared* cache to reuse for a few seconds.
 *
 * The concurrency ceiling of an application like this is the php-fpm worker pool, and the failure
 * that matters is one page being linked somewhere busy: without a shared cache every one of those
 * hits is a PHP request, and a pool exhausted by one page takes the whole application down with
 * it. This is the header contract that lets something in front absorb that instead.
 *
 * `s-maxage` is the whole idea. Only shared caches obey it -- browsers ignore it and keep
 * revalidating against the ETag -- so the window is the entire staleness budget and nothing else
 * is affected by it. Keep it tiny: an edit published now should reach anonymous visitors within
 * SECONDS, and that is the only thing this number trades away.
 *
 * Safe to ship before anything in front of PHP acts on it. It is inert until a shared cache
 * exists, and it is the prerequisite for putting one there.
 *
 * Two rules keep it honest, and both are about never handing one visitor's page to the next:
 *
 *  - A request carrying ANY cookie is left alone. A surface marked shareable should be setting
 *    none, so a cookie means something changed and the assumption no longer holds.
 *  - A response that already chose its own `Cache-Control` is left alone, because that choice was
 *    made by code that knew more about the page than this does.
 */
final class SharedCache
{
    /** Seconds a shared cache may reuse a page without asking. Spike insurance, not a CDN policy. */
    public const SECONDS = 10;

    /**
     * Add the validator and the shared-cache window to a response that is safe to share, and
     * answer a matching conditional request with a bodyless 304.
     *
     * The natural call site is one choke point in the front controller, per surface -- not
     * decorated onto each of a controller's return paths, where the one that forgets is the one
     * that matters.
     */
    public static function mark(Request $request, Response $response): Response
    {
        if (!self::shareable($request, $response)) {
            return $response;
        }
        return self::withValidator($request, $response, $response->getBody());
    }

    /**
     * The header pair, for a caller that already knows its response is shareable and is holding
     * the body it is about to send.
     */
    public static function withValidator(Request $request, Response $response, string $body): Response
    {
        $etag = '"' . md5($body) . '"';
        $control = self::control(self::shareableRequest($request));

        if (trim((string) $request->getHeader('If-None-Match', '')) === $etag) {
            return (new Response(304))
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', $control);
        }

        return $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $control);
    }

    public static function control(bool $shared): string
    {
        // max-age=0 keeps browsers revalidating, so an edit is never hidden behind a browser's own
        // copy. s-maxage rides alongside it and is seen only by shared caches.
        return $shared
            ? 'public, max-age=0, s-maxage=' . self::SECONDS . ', must-revalidate'
            : 'public, max-age=0, must-revalidate';
    }

    private static function shareable(Request $request, Response $response): bool
    {
        if ($response->getStatus() !== 200 || $response->getStream() !== null) {
            return false;
        }
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }
        // Someone else's explicit choice wins. A `no-store` already on the response is a decision,
        // not an omission.
        foreach ($response->getHeaders() as $key => $_) {
            if (strcasecmp((string) $key, 'Cache-Control') === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * A cookie on the request means this visitor may be getting something personal.
     *
     * Checked two ways on purpose. Request's header bag comes from getallheaders(), which exists
     * under php-fpm but not on the CLI and is not guaranteed by the language; were it ever
     * missing, every request would look cookie-free and this class would start offering personal
     * pages to a shared cache. $_COOKIE is populated by PHP itself on every SAPI, so it is the one
     * that cannot quietly go blind. The wrong answer here is the expensive kind, so it takes both
     * of them saying no.
     */
    private static function shareableRequest(Request $request): bool
    {
        if (!empty($_COOKIE)) {
            return false;
        }
        return trim((string) $request->getHeader('Cookie', '')) === '';
    }
}

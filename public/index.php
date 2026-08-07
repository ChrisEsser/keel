<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Framework\Auth;
use Framework\Database;
use Framework\Env;
use Framework\Host;
use Framework\HostKind;
use Framework\Http\Emitter;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Model\Model;
use Framework\Router\Router;

Env::load(__DIR__ . '/../config/.env');

// Which surface this request is for. Decided first, off nothing but $_ENV and the Host header,
// because it governs how much of the application is worth booting -- see Framework\Host::classify().
// An install that never sets APP_DOMAIN gets HostKind::App for everything, which is the right
// default: one host, one router, no ceremony.
$host = Host::normalize($_SERVER['HTTP_HOST'] ?? '');
$kind = Host::classify($host);

// Only the app host starts a session. Marketing is anonymous by definition, and a session there
// would mean a Set-Cookie on the one surface that most wants to be cacheable.
if ($kind === HostKind::App) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        // Host-only, and it must stay that way. Widening this to '.APP_DOMAIN' hands the session
        // cookie to every sibling subdomain -- which is fine right up until the day one of those
        // subdomains serves something you don't fully control.
        'domain'   => '',
        'secure'   => str_starts_with($_ENV['APP_URL'] ?? '', 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    Auth::start();
}

// Conditional, so an application with no database (a brochure site, a portfolio) never opens a
// socket and never needs MySQL installed to boot.
if (Database::isConfigured()) {
    Model::setConnection(Database::connect());
}

if ($kind === HostKind::App && Database::isConfigured() && !Auth::check()) {
    Auth::attemptRememberLogin();
}

$container = require __DIR__ . '/../config/container.php';

$request = Request::fromGlobals();

// The two routers are deliberately separate instances, so neither surface can reach the other's
// routes -- a marketing page cannot accidentally expose an app endpoint by being registered in
// the wrong closure.
$response = match ($kind) {
    HostKind::App => $container->get(Router::class)->dispatch($request),
    HostKind::Marketing => $host === 'www.' . Host::appDomain()
        // Canonicalize www -> apex, so marketing has exactly one address.
        ? Response::redirect(
            Host::requestScheme() . '://' . Host::appDomain() . ($_SERVER['REQUEST_URI'] ?? '/'),
            301
        )
        : $container->get('router.marketing')->dispatch($request),
};

// The app host is a private application, not content: nothing on it should appear in a search
// index, including the login and signup pages. robots.txt asks crawlers not to fetch; this tells
// them not to index what they fetched anyway -- a well-behaved crawler following an inbound link
// never reads robots.txt for that URL first. Marketing is excluded; it exists to be found.
if ($kind === HostKind::App) {
    $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
}

(new Emitter())->emit($response);

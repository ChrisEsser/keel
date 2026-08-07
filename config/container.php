<?php

declare(strict_types=1);

/**
 * Wiring and routes.
 *
 * Returns a built Container. Two things live here and nothing else: singletons the application
 * needs, and the route tables for each surface.
 *
 * Keel's own routes are registered by Framework\Routes::app() and Framework\Routes::marketing(). Add YOUR
 * application's routes in the marked blocks below — the split keeps a framework upgrade from
 * touching your file, and keeps your routes readable next to each other rather than interleaved
 * with the framework's forty.
 */

use Framework\Accounts\Service\PublicFormGuard;
use Framework\Container\Container;
use Framework\Http\Errors;
use Framework\Mail\Mailer;
use Framework\Router\Router;
use Framework\Routes;
use Framework\Sms\Sms;
use Framework\View\View;

$root = dirname(__DIR__);
$container = new Container();

// ── View ──────────────────────────────────────────────────────────────────────────────────────

$container->singleton(View::class, function () use ($root, $container) {
    // Both paths explicitly: View ships inside vendor/ and cannot guess this project's layout.
    $view = new View($root . '/views', $root . '/public');

    // Shared with every template, because the login screen alone renders it from several places
    // and no controller should have to remember to pass it. See views/partials/form-guard.php.
    $view->share('guard', $container->get(PublicFormGuard::class));

    return $view;
});

// ── Public form defense ───────────────────────────────────────────────────────────────────────
//
// Honeypot, timing check, per-IP and per-email rate limits, and Turnstile — applied to every
// logged-out form. Bound explicitly because the keys come from the environment, not from a type
// the container could resolve. With both Turnstile keys blank, isConfigured() is false and the
// other three defenses still apply.

$container->singleton(\Framework\Accounts\Service\TurnstileVerifier::class, fn() => new \Framework\Accounts\Service\TurnstileVerifier(
    $_ENV['TURNSTILE_SECRET_KEY'] ?? '',
    $_ENV['TURNSTILE_SITE_KEY'] ?? '',
));

$container->singleton(PublicFormGuard::class, fn($c) => new PublicFormGuard(
    $c->get(\Framework\Accounts\Service\TurnstileVerifier::class),
));

// ── Mail ──────────────────────────────────────────────────────────────────────────────────────

$container->singleton(\Framework\Mail\MailProviderInterface::class, function () use ($root) {
    return match ($_ENV['MAIL_PROVIDER'] ?? 'log') {
        'mailgun' => new \Framework\Mail\MailgunProvider(
            $_ENV['MAILGUN_API_KEY'] ?? '',
            $_ENV['MAILGUN_DOMAIN'] ?? '',
            $_ENV['MAILGUN_REGION'] ?? 'us',
        ),
        // The default writes to storage/mail/YYYY-MM-DD.log instead of sending. That is the right
        // default for a framework: a fresh checkout that silently mails real people while someone
        // is testing a password reset is a worse failure than one that mails nobody.
        default => new \Framework\Mail\LogMailProvider($root . '/storage'),
    };
});

$container->singleton(Mailer::class, fn($c) => new Mailer($c->get(\Framework\Mail\MailProviderInterface::class)));

// ── SMS (only needed for two-factor by text) ──────────────────────────────────────────────────

$container->singleton(\Framework\Sms\SmsProviderInterface::class, function () use ($root) {
    return match ($_ENV['SMS_PROVIDER'] ?? 'log') {
        'twilio' => new \Framework\Sms\TwilioSmsProvider(
            $_ENV['TWILIO_ACCOUNT_SID'] ?? '',
            $_ENV['TWILIO_AUTH_TOKEN'] ?? '',
            $_ENV['TWILIO_FROM_NUMBER'] ?? '',
        ),
        default => new \Framework\Sms\LogSmsProvider($root . '/storage'),
    };
});

$container->singleton(Sms::class, fn($c) => new Sms($c->get(\Framework\Sms\SmsProviderInterface::class)));

// ── Errors ────────────────────────────────────────────────────────────────────────────────────

// Each surface 404s in its own shell, so a visitor never gets the wrong site's chrome.
$container->singleton(Errors::class, fn($c) => new Errors($c->get(View::class)));
$container->singleton('errors.marketing', fn($c) => new Errors($c->get(View::class), 'marketing/404', 'layouts/marketing'));

// ── Your application's services ───────────────────────────────────────────────────────────────
//
// $container->singleton(App\Service\Thing::class, fn($c) => new App\Service\Thing(...));

// ── The app router ────────────────────────────────────────────────────────────────────────────

$container->singleton(Router::class, function ($c) {
    $router = new Router($c, $c->get(Errors::class));

    // Sign-in, sign-up, password reset, two-factor, users, organizations, team, billing, activity.
    Routes::app($router);

    // ── Your application's routes ─────────────────────────────────────────────────────────────
    //
    // $router->get('/things', App\Controller\ThingController::class . '@index');

    return $router;
});

// ── The marketing router ──────────────────────────────────────────────────────────────────────
//
// A separate Router instance on purpose: neither surface can reach the other's routes, so a
// marketing page cannot expose an app endpoint by being registered in the wrong closure.

$container->singleton('router.marketing', function ($c) {
    $router = new Router($c, $c->get('errors.marketing'));

    Routes::marketing($router);

    return $router;
});

return $container;

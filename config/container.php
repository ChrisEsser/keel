<?php

declare(strict_types=1);

/**
 * Wiring and routes.
 *
 * Returns a built Container. Two things live here and nothing else: singletons the application
 * needs, and the route tables for each surface.
 *
 * Every route is written out longhand, including the ones that came with the framework. There is
 * no Routes::app() to call: this is your project now, the framework half of src/ is as editable as
 * the rest of it, and a route table you can read top to bottom is worth more than one that hides
 * forty entries behind a method call. Add yours in the marked block.
 *
 * Route ORDER matters. The router matches in registration order and returns on the first hit, so a
 * fixed path must be registered before a wildcard that would also match it — `/users/create`
 * before `/users/{uid}`, or "create" is read as a uid. Every pair that has actually bitten is
 * commented where it appears; keep those comments alive as the list grows.
 */

use Framework\Accounts\Controller\AccountController;
use Framework\Accounts\Controller\ActivityController;
use Framework\Accounts\Controller\AuthController;
use Framework\Accounts\Controller\DashboardController;
use Framework\Accounts\Controller\InvitationController;
use Framework\Accounts\Controller\MembershipController;
use Framework\Accounts\Controller\ModalController;
use Framework\Accounts\Controller\OrgAdminController;
use Framework\Accounts\Controller\OrganizationController;
use Framework\Accounts\Controller\SecurityCheckupController;
use Framework\Accounts\Controller\SignupController;
use Framework\Accounts\Controller\UserController;
use Framework\Accounts\Service\PublicFormGuard;
use Framework\Billing\BillingController;
use Framework\Container\Container;
use Framework\Http\Errors;
use Framework\Mail\Mailer;
use Framework\Marketing\MarketingController;
use Framework\Router\Router;
use Framework\Sms\Sms;
use Framework\View\View;

$root = dirname(__DIR__);
$container = new Container();

// ── View ──────────────────────────────────────────────────────────────────────────────────────

$container->singleton(View::class, function () use ($root, $container) {
    // Both paths explicitly. This file is the one place that knows the project's layout, so it is
    // the one place that should be edited if views/ or public/ ever move.
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

    // ── Sign in ───────────────────────────────────────────────────────────────────────────────
    $router->get('/login', AuthController::class . '@login');
    $router->post('/login', AuthController::class . '@doLogin');
    $router->get('/logout', AuthController::class . '@logout');
    $router->get('/login/pin', AuthController::class . '@showPin');
    $router->post('/login/pin', AuthController::class . '@doVerifyPin');
    $router->get('/login/forget-device', AuthController::class . '@forgetDevice');
    $router->get('/login/2fa', AuthController::class . '@showTwoFactor');
    $router->post('/login/2fa', AuthController::class . '@doVerifyTwoFactor');
    $router->post('/login/2fa/resend', AuthController::class . '@resendTwoFactorCode');

    // Password reset — /login/forgot-password/sent MUST come before /login/reset/{token}.
    $router->get('/login/forgot-password', AuthController::class . '@forgotPassword');
    $router->post('/login/forgot-password', AuthController::class . '@doForgotPassword');
    $router->get('/login/forgot-password/sent', AuthController::class . '@forgotPasswordSent');
    $router->get('/login/reset/{token}', AuthController::class . '@resetPassword');
    $router->post('/login/reset/{token}', AuthController::class . '@doResetPassword');

    // ── Sign up ───────────────────────────────────────────────────────────────────────────────
    // /signup/sent before the verify routes; /verify/{token}/resend before /verify/{token}.
    $router->get('/signup', SignupController::class . '@show');
    $router->post('/signup', SignupController::class . '@submit');
    $router->get('/signup/sent', SignupController::class . '@sent');
    $router->post('/verify/{token}/resend', SignupController::class . '@resend');
    $router->get('/verify/{token}', SignupController::class . '@verify');
    $router->post('/verify/{token}', SignupController::class . '@complete');

    // ── Invitations ───────────────────────────────────────────────────────────────────────────
    $router->get('/invitations/{token}', InvitationController::class . '@show');
    $router->post('/invitations/{token}/accept', InvitationController::class . '@accept');
    // The only way to create an account other than /signup. Scoped to an invitation on purpose:
    // the address comes from the invitation row, so the mailed token is the proof of ownership
    // that stands in for a verification email.
    $router->post('/invitations/{token}/register', InvitationController::class . '@createAccount');
    $router->post('/api/invitations', InvitationController::class . '@store');

    // ── Landing ───────────────────────────────────────────────────────────────────────────────
    // The root of the app host. /dashboard decides where a signed-in user actually belongs and
    // bounces everyone else to /login, so there is one place that logic lives.
    $router->get('/', DashboardController::class . '@index');
    $router->get('/dashboard', DashboardController::class . '@index');
    $router->post('/workspaces', DashboardController::class . '@createWorkspace');
    $router->post('/security-checkup/snooze', SecurityCheckupController::class . '@snooze');

    // Modal partials, fetched on demand. The name is whitelisted in ModalController.
    $router->get('/api/modals/{name}', ModalController::class . '@fragment');

    // ── Impersonation (admin) ─────────────────────────────────────────────────────────────────
    $router->post('/api/admin/impersonate', AuthController::class . '@quickImpersonate');
    $router->post('/api/admin/impersonate/stop', AuthController::class . '@stopImpersonating');
    $router->post('/api/admin/impersonate/{id}', AuthController::class . '@impersonate');

    // ── Users ─────────────────────────────────────────────────────────────────────────────────
    $router->get('/api/users', UserController::class . '@get');
    $router->get('/api/users/{uid}', UserController::class . '@apiShow');
    $router->get('/users', UserController::class . '@list');
    // /users/create before /users/{uid}, or "create" is read as a uid.
    $router->get('/users/create', UserController::class . '@edit');
    $router->get('/users/{uid}', UserController::class . '@show');
    $router->get('/users/{uid}/edit', UserController::class . '@edit');
    $router->post('/api/users', UserController::class . '@store');
    $router->put('/api/users/{uid}', UserController::class . '@update');
    $router->post('/api/users/{uid}/2fa/disable', UserController::class . '@disableTwoFactor');
    $router->delete('/api/users/{uid}', UserController::class . '@destroy');

    // ── Your own account's security ───────────────────────────────────────────────────────────
    // Self-scoped, and separate from /api/users/{uid} for that reason: these act on
    // Auth::actualUser() and take no uid, so there is nothing to address but yourself.
    $router->get('/api/account/security', AccountController::class . '@status');
    $router->post('/api/account/2fa/totp/setup', AccountController::class . '@totpSetup');
    $router->post('/api/account/2fa/totp/confirm', AccountController::class . '@totpConfirm');
    $router->post('/api/account/2fa/sms/send', AccountController::class . '@smsSend');
    $router->post('/api/account/2fa/sms/confirm', AccountController::class . '@smsConfirm');
    $router->post('/api/account/2fa/disable', AccountController::class . '@disableTwoFactor');
    $router->post('/api/account/2fa/backup-codes/regenerate', AccountController::class . '@regenerateBackupCodes');
    $router->post('/api/account/pin/setup', AccountController::class . '@setupPin');
    $router->post('/api/account/pin/disable', AccountController::class . '@disablePin');
    $router->post('/api/account/devices/revoke-all', AccountController::class . '@revokeAllDevices');
    $router->post('/api/account/devices/{id}/revoke', AccountController::class . '@revokeDevice');

    // ── Organizations ─────────────────────────────────────────────────────────────────────────
    $router->get('/api/organizations', OrganizationController::class . '@get');
    $router->get('/api/organizations/{uid}/members', OrganizationController::class . '@getMembers');
    $router->get('/api/organizations/{uid}', OrganizationController::class . '@apiShow');
    $router->get('/organizations', OrganizationController::class . '@list');
    $router->get('/organizations/{uid}/dashboard', OrganizationController::class . '@dashboard');
    // /organizations/create before /organizations/{uid}.
    $router->get('/organizations/create', OrganizationController::class . '@edit');
    // The staff support hub. A member who lands here is redirected to their own dashboard.
    $router->get('/organizations/{uid}', OrgAdminController::class . '@show');
    $router->get('/organizations/{uid}/edit', OrganizationController::class . '@edit');
    $router->post('/api/organizations', OrganizationController::class . '@store');
    $router->put('/api/organizations/{uid}', OrganizationController::class . '@update');
    $router->delete('/api/organizations/{uid}', OrganizationController::class . '@destroy');
    $router->post('/api/organizations/{uid}/transfer-ownership', OrganizationController::class . '@transferOwnership');

    // ── Team ──────────────────────────────────────────────────────────────────────────────────
    $router->post('/api/memberships', MembershipController::class . '@store');
    $router->put('/api/memberships/{uid}', MembershipController::class . '@update');
    $router->delete('/api/memberships/{uid}', MembershipController::class . '@destroy');

    // ── Activity log (admin) ──────────────────────────────────────────────────────────────────
    $router->get('/activity', ActivityController::class . '@index');
    $router->get('/api/activity', ActivityController::class . '@get');

    // ── Billing ───────────────────────────────────────────────────────────────────────────────
    $router->post('/api/billing/setup-subscription', BillingController::class . '@setupForSubscription');
    $router->post('/api/billing/activate-subscription', BillingController::class . '@activateSubscription');
    $router->post('/api/billing/update-subscription', BillingController::class . '@updateSubscription');
    $router->post('/api/billing/cancel-subscription', BillingController::class . '@cancelSubscription');
    $router->post('/api/billing/setup-card', BillingController::class . '@setupCard');
    $router->post('/api/billing/confirm-card', BillingController::class . '@confirmCard');
    $router->get('/api/billing/invoices', BillingController::class . '@invoices');
    $router->post('/api/billing/portal', BillingController::class . '@portal');
    // Where a 3-D Secure redirect lands. A GET, because the browser is coming back from Stripe.
    $router->get('/billing/return', BillingController::class . '@return');
    // No session, no CSRF: Stripe's signature is the authentication. This one must stay reachable
    // logged-out or renewals never reach the application.
    $router->post('/api/billing/webhook', BillingController::class . '@webhook');

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

    $router->get('/', MarketingController::class . '@index');

    return $router;
});

return $container;

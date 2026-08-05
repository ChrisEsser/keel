<?php

declare(strict_types=1);

namespace Keel;

use Keel\Accounts\Controller\ActivityController;
use Keel\Accounts\Controller\AuthController;
use Keel\Accounts\Controller\DashboardController;
use Keel\Accounts\Controller\InvitationController;
use Keel\Accounts\Controller\MembershipController;
use Keel\Accounts\Controller\ModalController;
use Keel\Accounts\Controller\OrgAdminController;
use Keel\Accounts\Controller\OrganizationController;
use Keel\Accounts\Controller\SecurityCheckupController;
use Keel\Accounts\Controller\SignupController;
use Keel\Accounts\Controller\UserController;
use Keel\Billing\BillingController;
use Keel\Marketing\MarketingController;
use Keel\Router\Router;

/**
 * Keel's own routes, in one place so an application's config/container.php can register them with
 * a single call and keep its own routes readable beside each other.
 *
 * Route ORDER matters: this router matches in registration order, so a fixed path must be
 * registered before a wildcard that would also match it. `/login/forgot-password/sent` before
 * `/login/reset/{token}`, `/organizations/create` before `/organizations/{uid}`. Each of those
 * pairs is commented where it appears — they are the ones that have actually bitten.
 */
final class Routes
{
    /** Everything on the app host. */
    public static function app(Router $router): void
    {
        // ── Sign in ───────────────────────────────────────────────────────────────────────────
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

        // ── Sign up ───────────────────────────────────────────────────────────────────────────
        // /signup/sent before the verify routes; /verify/{token}/resend before /verify/{token}.
        $router->get('/signup', SignupController::class . '@show');
        $router->post('/signup', SignupController::class . '@submit');
        $router->get('/signup/sent', SignupController::class . '@sent');
        $router->post('/verify/{token}/resend', SignupController::class . '@resend');
        $router->get('/verify/{token}', SignupController::class . '@verify');
        $router->post('/verify/{token}', SignupController::class . '@complete');

        // ── Invitations ───────────────────────────────────────────────────────────────────────
        $router->get('/invitations/{token}', InvitationController::class . '@show');
        $router->post('/invitations/{token}/accept', InvitationController::class . '@accept');
        // The only way to create an account other than /signup. Scoped to an invitation on
        // purpose: the address comes from the invitation row, so the mailed token is the proof of
        // ownership that stands in for a verification email.
        $router->post('/invitations/{token}/register', InvitationController::class . '@createAccount');
        $router->post('/api/invitations', InvitationController::class . '@store');

        // ── Landing ───────────────────────────────────────────────────────────────────────────
        // The root of the app host. /dashboard decides where a signed-in user actually belongs and
        // bounces everyone else to /login, so there is one place that logic lives.
        $router->get('/', DashboardController::class . '@index');
        $router->get('/dashboard', DashboardController::class . '@index');
        $router->post('/workspaces', DashboardController::class . '@createWorkspace');
        $router->post('/security-checkup/snooze', SecurityCheckupController::class . '@snooze');

        // Modal partials, fetched on demand. The name is whitelisted in ModalController.
        $router->get('/api/modals/{name}', ModalController::class . '@fragment');

        // ── Impersonation (admin) ─────────────────────────────────────────────────────────────
        $router->post('/api/admin/impersonate', AuthController::class . '@quickImpersonate');
        $router->post('/api/admin/impersonate/stop', AuthController::class . '@stopImpersonating');
        $router->post('/api/admin/impersonate/{id}', AuthController::class . '@impersonate');

        // ── Users ─────────────────────────────────────────────────────────────────────────────
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

        // ── Organizations ─────────────────────────────────────────────────────────────────────
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

        // ── Team ──────────────────────────────────────────────────────────────────────────────
        $router->post('/api/memberships', MembershipController::class . '@store');
        $router->put('/api/memberships/{uid}', MembershipController::class . '@update');
        $router->delete('/api/memberships/{uid}', MembershipController::class . '@destroy');

        // ── Activity log (admin) ──────────────────────────────────────────────────────────────
        $router->get('/activity', ActivityController::class . '@index');
        $router->get('/api/activity', ActivityController::class . '@get');

        // ── Billing ───────────────────────────────────────────────────────────────────────────
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
        // No session, no CSRF: Stripe's signature is the authentication. This one must stay
        // reachable logged-out or renewals never reach the application.
        $router->post('/api/billing/webhook', BillingController::class . '@webhook');
    }

    /** Everything on the marketing host (the apex). */
    public static function marketing(Router $router): void
    {
        $router->get('/', MarketingController::class . '@index');
    }
}

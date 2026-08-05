<?php
/**
 * The starter marketing page, served on the apex.
 *
 * Replace this with your own. What it is here to show is the SHAPE: a flat sequence of
 * <section class="marketing-section"> each wrapping a <div class="wrap">, using the layout
 * primitives in public/css/marketing.css and the tokens in base.css. Nothing on this page touches
 * Auth, the database, or a model — that is the property worth keeping when you rewrite it, because
 * it is what lets the marketing surface stay up and stay cheap under load the app couldn't take.
 *
 * @var string $appUrl  Absolute base URL of the app host. App routes exist only there.
 */

$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$brand = \Keel\Brand::name();
?>
<section class="marketing-section">
    <div class="wrap">
        <h1><?= $e($brand) ?></h1>
        <p class="lede">
            This is your marketing page. It is served on the apex domain by its own router, with no
            session and no database behind it.
        </p>
        <div class="marketing-cta">
            <a class="btn btn-primary" href="<?= $e($appUrl) ?>/signup">Create an account</a>
            <a class="btn btn-ghost" href="<?= $e($appUrl) ?>/login">Sign in</a>
        </div>
    </div>
</section>

<section class="marketing-section marketing-section-paper">
    <div class="wrap">
        <div class="marketing-section-head">
            <h2>What you get out of the box</h2>
        </div>
        <div class="marketing-faq">
            <details open>
                <summary>Accounts, teams and roles</summary>
                <p>
                    Sign-up with email verification, sign-in with lockout backoff, password reset,
                    two-factor by authenticator app or SMS, a PIN lock, backup codes, remembered
                    devices, and staff impersonation with an audit trail.
                </p>
            </details>
            <details>
                <summary>Organizations</summary>
                <p>
                    Every account belongs to an organization. Invitations, three roles, ownership
                    transfer, and a support hub that shows staff one account's whole story on one
                    screen.
                </p>
            </details>
            <details>
                <summary>Billing</summary>
                <p>
                    Stripe subscriptions collected card-first, dunning with a grace window you
                    control, and webhooks that keep the local entitlement honest.
                </p>
            </details>
        </div>
    </div>
</section>

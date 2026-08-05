# Keel

A zero-dependency PHP 8.4 application framework that starts where most projects spend their first
month: users, organizations, roles, invitations, two-factor auth, impersonation, an audit log, and
Stripe subscriptions — all working, all yours to edit.

No Laravel, no Symfony, no runtime Composer packages. About 3,500 lines of framework and 7,000
lines of application code you can read in an afternoon.

```bash
composer require chrisesser/keel
vendor/bin/keel init
php scripts/init-db.php
php -S localhost:8000 -t public
```

---

## Why this exists

Most frameworks give you a router and a template engine and leave the hard, boring, identical 20%
to you — the part where you decide how a password reset token is stored, what happens to a
customer's access on the fourth failed card retry, and whether an admin impersonating a customer
should still count as an admin.

Keel is those decisions, already made, with the reasoning written down next to them. Where a
choice was not obvious, the comment says what the alternative was and why it lost. You will
disagree with some of them; the code is small enough to change.

## What's in the box

**Accounts.** Sign-up with email verification (no `users` row exists until the address is
confirmed). Sign-in with a timing-safe compare and an escalating lockout. Password reset with
hashed, single-use, expiring tokens. Two-factor by authenticator app or SMS, a PIN lock, backup
codes, and remembered devices on a split selector/validator scheme.

**Organizations.** The unit that owns things and pays for things. Three roles, invitations,
ownership transfer with a one-owner invariant enforced in the model, and a staff support hub that
puts one account's whole story on one screen.

**Impersonation.** Staff can act as a customer. `Auth::isAdmin()` reads the *real* user, so an
impersonated session can never gain admin, and every impersonated action is flagged in the audit
log — because it is otherwise indistinguishable from the customer's own.

**Billing.** Stripe subscriptions collected card-first (SetupIntent, then create the subscription),
which is the flow that doesn't leave half-real subscriptions behind when someone abandons the form.
Dunning with a grace window you control. Webhooks that keep local entitlement honest, including
Stripe's two different API response shapes.

**An audit log.** Append-only, with denormalized labels so last year's entries still describe what
was true last year. Built for the support call, not for compliance theater.

**Two surfaces.** The front controller forks on the `Host` header: your app on one host, a public
marketing site on another, each with its own router so neither can reach the other's routes. Leave
`APP_DOMAIN` blank and it's a single-host app with no ceremony.

## The shape

```
src/                          The framework — namespace Keel\
  Container/                  Reflection-based DI
  Router/                     Route table, {param} matching
  Http/                       Request, Response, Emitter, Errors
  View/                       ~90 lines of require-based templating
  Model/                      Static active record, soft deletes, UUIDs
  Mail/  Sms/                 Provider seams (log in dev, Mailgun/Twilio in prod)
  Security/                   Hand-rolled RFC 6238 TOTP, libsodium encryption
  Accounts/                   Users, orgs, memberships, invitations, the audit log
  Billing/                    Stripe subscriptions
  Marketing/                  The public surface

resources/                    Copied into YOUR project by `keel init` — then it's yours
  views/  public/  config/  scripts/  schema.sql
```

`keel init` copies `resources/` out of the package and into your project. Those files are yours
afterwards: a framework upgrade can never revert a view you edited or a route you added. That is
the trade — views are the part every application rewrites, so they are the part that gets copied
rather than rendered out of `vendor/`.

## Design notes

A few things that are deliberate, and easy to undo by accident:

- **The session cookie is host-only** (`domain => ''`). Widening it to `.example.com` hands your
  session to every sibling subdomain.
- **Entitlement is read from the local column**, never from a Stripe API call. It survives Stripe
  being unreachable, it's one query instead of a round trip, and the grace window is your decision.
- **`memberships`' unique key includes `deleted`.** Removal is a soft delete, so without it,
  re-inviting someone you removed fails on a row nobody can see.
- **Every guard is an early return in the action.** There is no middleware. You can read any
  controller method and see exactly what it checks.
- **Restricted UI is shown locked, not hidden.** A teammate who can't find the Billing tab files a
  support ticket; one who sees it greyed out with a sentence saying why does not.

## Requirements

PHP 8.4+, MySQL 8.0+ (optional — leave `DB_NAME` blank for an app with no database), and
`ext-pdo_mysql`, `ext-mbstring`, `ext-json`, `ext-openssl`. `ext-sodium` for two-factor,
`stripe/stripe-php` for billing. `php scripts/check-env.php` tells you what's missing.

Apache with `mod_rewrite`, or any server that routes everything to `public/index.php`.

## Development

```bash
composer install
vendor/bin/phpstan analyse     # level 5, clean
php tests/resolve.php          # every class loads and every referenced type resolves
```

## License

MIT.

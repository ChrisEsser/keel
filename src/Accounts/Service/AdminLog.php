<?php

declare(strict_types=1);

namespace Keel\Accounts\Service;

use Keel\Accounts\Model\AdminEventModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\UserModel;
use Keel\Auth;

// The platform's activity log: what happened, to whom, and who did it.
//
// It exists for one concrete moment — a customer is on the phone saying "I don't have access"
// — and the answer is somewhere in a sequence of events nobody currently records: their
// invitation was revoked, an admin changed their role, their card failed three weeks ago and
// the dunning mail went to an address they don't read. Reconstructing that from application
// state is guesswork; this is the tape.
//
// Static rather than injected on purpose. Events are recorded from controllers, services, sweeps
// and CLI scripts alike — including from models, which have no container — and an audit trail
// that some call sites can't reach isn't one. It has no configuration and one dependency (the
// PDO connection the models already share), so there is nothing a container would be resolving.
//
// **Logging must never break the thing it logs.** Every write is wrapped: a failure here becomes
// an error_log line, never an exception in the middle of a member removal or a Stripe webhook.
final class AdminLog
{
    // Every event the system records, with the label the admin area shows and the category it
    // filters under. New events go here first — the catalog is what makes the filter dropdown,
    // the badge colour and the log itself agree, and a key that isn't listed still records (it
    // just reads as its raw key), so a missing entry degrades instead of losing the event.
    //
    // Categories: access · account · team · billing · email · admin
    //
    // The category is also the retention unit (see RETENTION_DAYS), so events are grouped by how
    // long they stay useful, not only by what they touch. That's why sign-ins ('access') are
    // separate from the durable identity and security changes ('account') they'd otherwise sit
    // with: sign-ins are almost all the volume and go stale in weeks, while "when did they turn
    // two-factor off" is worth years.
    public const EVENTS = [
        // ── access (high-volume session traffic) ───────────────────────────────
        'auth.login'                    => ['Signed in', 'access'],
        'auth.login_failed'             => ['Failed sign-in', 'access'],
        'auth.locked_out'               => ['Locked out', 'access'],

        // ── account (identity + security, low volume, long-lived) ──────────────
        'auth.password_reset_requested' => ['Password reset requested', 'account'],
        'auth.password_reset_completed' => ['Password reset completed', 'account'],
        'auth.2fa_enabled'              => ['Two-factor turned on', 'account'],
        'auth.2fa_disabled'             => ['Two-factor turned off', 'account'],
        'user.registered'               => ['Account created', 'account'],
        'user.deleted'                  => ['Account deleted', 'account'],

        // ── team ──────────────────────────────────────────────────────────────
        'member.invited'                => ['Invitation sent', 'team'],
        'member.invite_accepted'        => ['Invitation accepted', 'team'],
        'member.added'                  => ['Member added', 'team'],
        'member.removed'                => ['Member removed', 'team'],
        'member.role_changed'           => ['Role changed', 'team'],
        'org.ownership_transferred'     => ['Ownership transferred', 'team'],
        'org.created'                   => ['Organization created', 'team'],
        'org.deleted'                   => ['Organization deleted', 'team'],

        // ── billing ───────────────────────────────────────────────────────────
        'billing.subscription_started'  => ['Subscription started', 'billing'],
        'billing.subscription_changed'  => ['Subscription changed', 'billing'],
        'billing.subscription_canceled' => ['Subscription canceled', 'billing'],
        'billing.subscription_resumed'  => ['Subscription resumed', 'billing'],
        'billing.payment_failed'        => ['Payment failed', 'billing'],
        'billing.payment_recovered'     => ['Payment recovered', 'billing'],
        'billing.payment_method_added'  => ['Payment method added', 'billing'],
        'billing.payment_method_removed' => ['Payment method removed', 'billing'],

        // ── email (what we sent them, and when) ────────────────────────────────
        // Deliberately retained as long as billing: the dunning emails ARE the evidence for the
        // billing events, so keeping one without the other leaves you able to see that a payment
        // failed but not that anyone was ever told.
        'email.payment_failed_sent'     => ['Sent: payment failed', 'email'],
        'email.grace_ending_sent'       => ['Sent: grace ending', 'email'],
        'email.subscription_canceled_sent' => ['Sent: subscription canceled', 'email'],

        // ── admin ─────────────────────────────────────────────────────────────
        'admin.impersonation_started'   => ['Impersonation started', 'admin'],
        'admin.impersonation_stopped'   => ['Impersonation stopped', 'admin'],
    ];

    /** Category of every event, in the order the admin area offers them. */
    public const CATEGORIES = ['access', 'account', 'team', 'billing', 'email', 'admin'];

    // How long each category is kept, in days — enforced by scripts/prune-activity-log.php.
    //
    // Retention is set by the longest cycle the events have to explain, not by a round number:
    //
    //   access   90d   Sign-ins and lockouts: nearly all the rows, and only ever asked about
    //                  recently ("can they get in this week?"). Nobody asks about a sign-in from
    //                  last year, and keeping them is what would make the log's LIKE search slow.
    //   account  2y    Password resets, two-factor changes, account creation/deletion. Tiny
    //                  volume, and the trail an account-takeover question reads.
    //   team     2y    Who was invited, added, removed, re-roled. "I used to be able to do this"
    //                  can surface a long time after the change that caused it.
    //   billing  3y    Money questions reach back furthest, and the volume is trivial.
    //   email    3y    Matched to billing, for the reason given on those rows above.
    //   admin    3y    Impersonation. This is the accountability record for staff acting as
    //                  customers, so it outlives the sessions it describes.
    //
    // Note the category is stamped on the row at write time: re-categorising an event later
    // changes the retention of new rows only, never rows already written.
    public const RETENTION_DAYS = [
        'access'  => 90,
        'account' => 730,
        'team'    => 730,
        'billing' => 1095,
        'email'   => 1095,
        'admin'   => 1095,
    ];

    // Anything recorded under an unlisted event falls back to the 'admin' category, so this is
    // only a floor for a category that gets added without a retention entry. Long rather than
    // short: over-keeping a handful of rows is recoverable, deleting the one that explained a
    // support call is not.
    public const DEFAULT_RETENTION_DAYS = 1095;

    /**
     * Record one event.
     *
     * @param string $event   A key from EVENTS.
     * @param string $summary One plain sentence, already naming its subjects — this is what a
     *                        support person reads, so it must stand alone without joins.
     * @param array{
     *     org?: OrganizationModel|null,
     *     user?: UserModel|null,
     *     actor?: UserModel|null,
     *     system?: bool,
     *     meta?: array<string, mixed>
     * } $ctx `user` is the SUBJECT (who it happened to), `actor` overrides who did it — pass it
     *        only when the actor isn't the logged-in user. `system: true` marks a sweep or webhook
     *        that has no actor at all, which is different from "we didn't record one".
     */
    public static function record(string $event, string $summary, array $ctx = []): void
    {
        try {
            $row = new AdminEventModel();
            $row->event = $event;
            $row->category = self::EVENTS[$event][1] ?? 'admin';
            // Truncated rather than rejected: a summary that ran long is still worth having, and
            // a logging call is not the place to start throwing.
            $row->summary = mb_substr($summary, 0, 255);

            $actor = $ctx['actor'] ?? (($ctx['system'] ?? false) ? null : self::currentActor());
            $row->actor_user_id = $actor?->id;
            $row->actor_label = $actor !== null
                ? self::label($actor->fullName())
                : (($ctx['system'] ?? false) ? 'System' : '');

            $org = $ctx['org'] ?? null;
            $row->org_id = $org?->id;
            $row->org_label = self::label($org !== null ? $org->name : '');

            $subject = $ctx['user'] ?? null;
            $row->subject_user_id = $subject?->id;
            $row->subject_label = self::label($subject?->fullName() ?? '');

            // Impersonation is invisible everywhere else: acting as a customer leaves records
            // indistinguishable from the customer's own. Flag it here or it's unknowable later.
            $row->impersonated = (self::sessionAvailable() && Auth::isImpersonating()) ? 1 : 0;
            // Empty on CLI, where there is no peer to resolve.
            $row->ip = ClientIp::resolve();

            // Emails and other identifiers live here rather than in the labels: the search covers
            // meta, so looking someone up by address works without the display text carrying it.
            $meta = $ctx['meta'] ?? [];
            if ($subject !== null) $meta['subject_email'] = $subject->email;
            if ($actor !== null) $meta['actor_email'] = $actor->email;
            if ($org !== null) $meta['org_email'] = $org->email;
            $row->meta = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES);

            $row->save();
        } catch (\Throwable $e) {
            // Never let the audit trail take down the action it was auditing.
            error_log('AdminLog::record(' . $event . '): ' . $e->getMessage());
        }
    }

    /** Human label for an event key — falls back to the raw key so an unlisted event still reads. */
    public static function labelFor(string $event): string
    {
        return self::EVENTS[$event][0] ?? $event;
    }

    // The REAL logged-in user, never the impersonated one: "who did this" has to mean the person
    // at the keyboard, or an admin acting as a customer would be recorded as the customer.
    private static function currentActor(): ?UserModel
    {
        return self::sessionAvailable() ? Auth::actualUser() : null;
    }

    // Keyed on the superglobal, not session_status(): Auth reads $_SESSION directly, and a CLI
    // sweep has no $_SESSION at all — which is precisely the "no actor, this was the system"
    // case. Checking the session's *status* instead would also drop the actor anywhere the
    // session is populated without an active handler, silently attributing real people's
    // actions to the system.
    private static function sessionAvailable(): bool
    {
        return isset($_SESSION);
    }

    private static function label(string $value): string
    {
        return mb_substr(trim($value), 0, 160);
    }
}

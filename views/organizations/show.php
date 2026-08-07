<?php
/**
 * The staff support hub for one organization. Admin-only — OrgAdminController::show has already
 * refused everyone else.
 *
 * Server-rendered end to end, unlike /activity which hydrates from an API. That is a filtered,
 * paged list that changes as you use it; this is a snapshot of one account at one moment, and
 * every panel would otherwise need an endpoint to say what the controller already knows.
 *
 * This is a page to EXTEND. Whatever your product gives an organization, a support call is about
 * that thing -- add a panel here rather than building a second staff page, and the person on the
 * phone keeps one screen open instead of three.
 *
 * @var \Framework\Accounts\Model\OrganizationModel $org
 * @var \Framework\Accounts\Model\UserModel|null $owner
 * @var list<array{membership: \Framework\Accounts\Model\MembershipModel, user: \Framework\Accounts\Model\UserModel}> $team
 * @var int $pendingInvites
 * @var list<\Framework\Accounts\Model\AdminEventModel> $activity
 */

$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// One row of the label/value lists. Blank values are dropped rather than rendered empty: a support
// page reads faster when the absent facts are absent.
$field = function (string $label, ?string $value) use ($e): void {
    if ($value === null || trim($value) === '') return;
    echo '<div class="cr-field"><span class="cr-field-label">' . $e($label) . '</span>'
        . '<span class="cr-field-value">' . nl2br($e($value)) . '</span></div>';
};

$dateTime = fn(?int $ts): ?string => $ts === null ? null : date('M j, Y H:i', $ts) . ' UTC';

// Mirrors the STATUS map in views/organizations/list.php, off the same planState().
// The third entry is the stat-tile value: a tile is one or two words wide, and "No subscription"
// wraps to three lines in it while the badge beside the title has room for the full phrase.
$planBadge = [
    'active' => ['Active', 'success', 'Active'],
    'past_due' => ['Past due', 'warning', 'Past due'],
    'canceled' => ['Canceled', 'danger', 'Canceled'],
    'none' => ['No subscription', 'secondary', 'None'],
][$org->planState()] ?? ['No subscription', 'secondary', 'None'];

$hasBilling = $org->stripe_customer_id !== null || $org->subscription_status !== null;
?>
<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-icon"><i data-lucide="building-2"></i></div>
        <div>
            <h1 class="page-header-title"><?= $e($org->displayName()) ?></h1>
            <div class="page-header-subtitle">
                <a href="mailto:<?= $e($org->email) ?>"><?= $e($org->email) ?></a>
                <?php if ($owner !== null): ?>
                    &middot; owned by <a href="/users/<?= $e($owner->uid) ?>"><?= $e($owner->fullName()) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header-actions">
        <span class="badge badge-<?= $e($planBadge[1]) ?>"><?= $e($planBadge[0]) ?></span>
        <?php // What the customer actually sees, for when the call needs you looking at their screen. ?>
        <a href="/organizations/<?= $e($org->uid) ?>/dashboard" class="btn btn-ghost btn-sm"><i data-lucide="eye"></i> Customer view</a>
        <a href="/activity?org=<?= $e($org->uid) ?>" class="btn btn-ghost btn-sm"><i data-lucide="history"></i> Activity</a>
        <button class="btn btn-ghost btn-sm" onclick="ModalLoader.open('org-settings', ORG_UID)"><i data-lucide="settings"></i> Settings</button>
    </div>
</div>

<div class="org-detail">
    <div class="org-detail-main">

        <?php // ── Team ─────────────────────────────────────────────────────────── ?>
        <section class="cr-panel">
            <div class="org-panel-head">
                <h2 class="cr-panel-title">Team</h2>
                <?php if ($pendingInvites > 0): ?>
                    <span class="org-panel-note"><?= (int) $pendingInvites ?> invitation<?= $pendingInvites === 1 ? '' : 's' ?> pending</span>
                <?php endif; ?>
            </div>
            <?php if ($team === []): ?>
                <div class="list-empty"><p>Nobody belongs to this organization.</p></div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Person</th><th>Email</th><th>Role</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($team as $entry): $m = $entry['membership']; $u = $entry['user']; ?>
                            <tr>
                                <td><a href="/users/<?= $e($u->uid) ?>"><?= $e($u->fullName()) ?></a></td>
                                <td><?= $e($u->email) ?></td>
                                <td>
                                    <select data-role="<?= $e($m->role->value) ?>" onchange="updateMembershipRole('<?= $e($m->uid) ?>', this)">
                                        <?php foreach (['user' => 'User', 'admin' => 'Admin', 'owner' => 'Owner'] as $value => $label): ?>
                                            <option value="<?= $value ?>"<?= $m->role->value === $value ? ' selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php // Straight to what this person sees, without a detour via /users. ?>
                                    <?php if (\Framework\Auth::actualUser()?->uid !== $u->uid): ?>
                                        <button class="btn btn-ghost btn-icon" data-tooltip="Sign in as them" onclick="impersonateUser(<?= $e(json_encode($u->email)) ?>)"><i data-lucide="user-check"></i></button>
                                    <?php endif; ?>
                                    <button class="btn btn-ghost-danger btn-icon" data-tooltip="Remove" onclick="removeMembership('<?= $e($m->uid) ?>', this)"><i data-lucide="trash-2"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php // ── Activity ─────────────────────────────────────────────────────── ?>
        <section class="cr-panel">
            <div class="org-panel-head">
                <h2 class="cr-panel-title">Recent activity</h2>
                <a class="org-panel-note" href="/activity?org=<?= $e($org->uid) ?>">All</a>
            </div>
            <?php if ($activity === []): ?>
                <div class="list-empty"><p>Nothing recorded yet.</p></div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <tbody>
                        <?php foreach ($activity as $event): ?>
                            <tr>
                                <td><?= $e($event->summary) ?></td>
                                <td class="activity-when"><?= $e(substr((string) $event->created_at, 0, 10)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="org-detail-side">

        <div class="stat-grid">
            <div class="stat-tile">
                <div class="stat-tile-label">Plan</div>
                <div class="stat-tile-value"><?= $e($planBadge[2]) ?></div>
                <div class="stat-tile-hint"><?= $org->hasActivePlan() ? 'entitled' : 'not entitled' ?></div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile-label">Team</div>
                <div class="stat-tile-value"><?= count($team) ?></div>
                <div class="stat-tile-hint">people</div>
            </div>
        </div>

        <?php // ── Billing ──────────────────────────────────────────────────────── ?>
        <section class="cr-panel">
            <h2 class="cr-panel-title">Billing</h2>
            <?php // $field() drops blank values, so with nothing to show this panel would render as
                  // a bare heading and read as broken rather than as empty. ?>
            <?php if (!$hasBilling): ?>
                <div class="list-empty"><p>This organization has never been billed.</p></div>
            <?php else: ?>
            <div class="cr-fields">
                <?php
                $field('Stripe customer', $org->stripe_customer_id);
                $field('Stripe subscription', $org->stripe_subscription_id);
                $field('Status', $org->subscription_status);
                $field('Renews', $dateTime($org->subscription_renewal_at));
                $field('Card', $org->stripe_card_brand !== null
                    ? $org->stripe_card_brand . ' ....' . $org->stripe_card_last4
                    : null);
                ?>
            </div>
            <?php endif; ?>
        </section>

        <?php // ── Lifecycle ────────────────────────────────────────────────────── ?>
        <section class="cr-panel">
            <div class="org-panel-head">
                <h2 class="cr-panel-title">Lifecycle</h2>
                <span class="org-panel-note">Why they can or can't use it</span>
            </div>
            <div class="cr-fields">
                <?php
                $field('Entitled', $org->hasActivePlan() ? 'yes' : 'no');
                $field('Payment failed', $dateTime($org->past_due_since));
                if ($org->past_due_since !== null) $field('Grace ends', $dateTime($org->graceEndsAt()));
                $field('Lapsed', $dateTime($org->lapsed_at));
                ?>
            </div>
        </section>
    </div>
</div>

<script>
const ORG_UID = '<?= $e($org->uid) ?>';

// The same three helpers as views/users/show.php, against the same endpoints. Copies rather than a
// shared module: the two pages render different rows around them, and the membership API is two
// calls with no state between them.

function impersonateUser(identifier) {
    fetch('/api/admin/impersonate', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({identifier}),
    }).then(r => r.json()).then(data => {
        if (data.success) window.location = '/dashboard';
        else toast(data.message || 'Could not impersonate.', 'error');
    });
}

async function updateMembershipRole(uid, select) {
    const role = select.value;
    const previous = select.dataset.role ?? role;

    const res = await fetch('/api/memberships/' + uid, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ role }),
    });
    const data = await res.json();

    if (!data.success) {
        select.value = previous;
        toast((data.errors ?? [data.message]).join(' '), 'error');
        return;
    }

    select.dataset.role = role;
    toast('Role updated.', 'success');
}

async function removeMembership(uid, btn) {
    if (!await confirmDialog('Remove this membership?', { danger: true, confirmText: 'Remove' })) return;

    const res = await fetch('/api/memberships/' + uid, { method: 'DELETE' });
    const data = await res.json();

    if (!data.success) { toast(data.message, 'error'); return; }

    toast('Membership removed.', 'success');
    btn.closest('tr').remove();
}
</script>

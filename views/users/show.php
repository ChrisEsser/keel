<?php
$roleBadgeClass = ['owner' => 'primary', 'admin' => 'secondary', 'user' => 'secondary'];
?>
<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-icon"><i data-lucide="user"></i></div>
        <div>
            <h1 class="page-header-title"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h1>
            <span class="page-header-subtitle"><?= htmlspecialchars($user['email']) ?></span>
        </div>
    </div>
    <div class="page-header-actions">
        <?php // Straight to everything that has happened to this person — the first click of a
              // "they say they can't get in" call. ?>
        <a href="/activity?user=<?= htmlspecialchars($user['uid']) ?>" class="btn btn-ghost-primary"><i data-lucide="history"></i> Activity</a>
        <?php // Sign in as this person to see exactly what they see. Admin-only page; hidden on your
              // own record since impersonating yourself does nothing. ?>
        <?php if (\Keel\Auth::actualUser()?->uid !== $user['uid']): ?>
        <button class="btn btn-ghost-primary" onclick="impersonateUser(<?= htmlspecialchars(json_encode($user['email']), ENT_QUOTES) ?>)"><i data-lucide="user-check"></i> Impersonate</button>
        <?php endif; ?>
        <button class="btn btn-ghost-primary" onclick="ModalLoader.open('user-settings', '<?= htmlspecialchars($user['uid']) ?>')"><i data-lucide="settings"></i> Settings</button>
    </div>
</div>

<dl>
    <dt>First Name</dt> <dd><?= htmlspecialchars($user['first_name']) ?></dd>
    <dt>Last Name</dt>  <dd><?= htmlspecialchars($user['last_name']) ?></dd>
    <dt>Email</dt>      <dd><?= htmlspecialchars($user['email']) ?></dd>
    <dt>Two-Factor Auth</dt>
    <dd id="twofa-status">
        <?= $user['two_factor_enabled'] ? htmlspecialchars($user['two_factor_method_label']) : 'Disabled' ?>
        <?php if ($user['two_factor_enabled']): ?>
            <button class="btn btn-ghost-danger btn-sm" onclick="disableTwoFactor('<?= htmlspecialchars($user['uid']) ?>', this)" style="margin-left:0.75rem;"><i data-lucide="shield-off"></i> Disable 2FA</button>
        <?php endif; ?>
    </dd>
</dl>

<h2 style="margin-top:2rem;">Organizations</h2>
<p id="no-memberships" <?= empty($memberships) ? '' : 'style="display:none;"' ?>>This user is not a member of any organization.</p>
<div class="card-grid" id="memberships-grid" <?= empty($memberships) ? 'style="display:none;"' : '' ?>>
    <?php foreach ($memberships as $m): ?>
    <div class="entity-card" data-url="/organizations/<?= htmlspecialchars($m['org_uid']) ?>">
        <div class="entity-card-header">
            <div class="entity-card-icon"><i data-lucide="building-2"></i></div>
            <div class="entity-card-title"><?= htmlspecialchars($m['org_name']) ?></div>
        </div>
        <div class="entity-card-body">
            <div class="entity-card-badges">
                <span class="badge badge-<?= $roleBadgeClass[$m['role']] ?? 'secondary' ?>"><?= htmlspecialchars($m['role_label']) ?></span>
            </div>
        </div>
        <div class="entity-card-actions">
            <select data-role="<?= htmlspecialchars($m['role']) ?>" onchange="updateMembershipRole('<?= htmlspecialchars($m['uid']) ?>', this)">
                <option value="user" <?= $m['role'] === 'user' ? 'selected' : '' ?>>User</option>
                <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="owner" <?= $m['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
            </select>
            <button class="btn btn-ghost-danger btn-icon" data-tooltip="Remove" onclick="removeMembership('<?= htmlspecialchars($m['uid']) ?>', this)"><i data-lucide="trash-2"></i></button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<button class="btn btn-ghost-primary" style="margin-top:1rem;" onclick="ModalLoader.open('org-lookup', USER_UID, { onAdded: renderMembershipCard })"><i data-lucide="plus"></i> Add to organization</button>

<script>
// Admin-only (this whole page is). Resolves the target by email, then lands on their dashboard as
// them; the "Impersonating …" banner + Stop control come from the layout.
function impersonateUser(identifier) {
    if (!identifier) return;
    fetch('/api/admin/impersonate', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({identifier}),
    }).then(r => r.json()).then(data => {
        if (data.success) window.location = '/dashboard';
        else toast(data.message || 'Could not impersonate.', 'error');
    });
}
const USER_UID = '<?= htmlspecialchars($user['uid']) ?>';
const ROLE_LABELS = { owner: 'Owner', admin: 'Admin', user: 'User' };
const ROLE_BADGE_CLASS = { owner: 'primary', admin: 'secondary', user: 'secondary' };

document.querySelectorAll('.card-grid').forEach(grid => {
    grid.addEventListener('click', e => {
        if (e.target.closest('.entity-card-actions')) return;
        const card = e.target.closest('[data-url]');
        if (card) window.location.href = card.dataset.url;
    });
});

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
    const badge = select.closest('.entity-card').querySelector('.badge');
    badge.className = 'badge badge-' + (ROLE_BADGE_CLASS[role] ?? 'secondary');
    badge.textContent = ROLE_LABELS[role] ?? role;
    toast('Role updated.', 'success');
}

async function removeMembership(uid, btn) {
    if (!await confirmDialog('Remove this membership?', { danger: true, confirmText: 'Remove' })) return;
    fetch('/api/memberships/' + uid, { method: 'DELETE' })
        .then(r => r.json())
        .then(data => { if (data.success) { toast('Membership removed.', 'success'); btn.closest('.entity-card').remove(); } else toast(data.message, 'error'); });
}

// Called by the org-lookup modal (onAdded) once a membership is created. m = {uid, org_uid,
// org_name, role}. Appends the new org card without a reload, matching the server-rendered ones.
function renderMembershipCard(m) {
    document.getElementById('no-memberships').style.display = 'none';
    const grid = document.getElementById('memberships-grid');
    grid.style.display = '';

    const card = document.createElement('div');
    card.className = 'entity-card';
    card.dataset.url = '/organizations/' + m.org_uid;
    card.innerHTML = `
        <div class="entity-card-header">
            <div class="entity-card-icon"><i data-lucide="building-2"></i></div>
            <div class="entity-card-title">${(m.org_name || '').replace(/</g, '&lt;')}</div>
        </div>
        <div class="entity-card-body">
            <div class="entity-card-badges">
                <span class="badge badge-${ROLE_BADGE_CLASS[m.role] ?? 'secondary'}">${ROLE_LABELS[m.role] ?? m.role}</span>
            </div>
        </div>
        <div class="entity-card-actions">
            <select data-role="${m.role}" onchange="updateMembershipRole('${m.uid}', this)">
                <option value="user"${m.role === 'user' ? ' selected' : ''}>User</option>
                <option value="admin"${m.role === 'admin' ? ' selected' : ''}>Admin</option>
                <option value="owner"${m.role === 'owner' ? ' selected' : ''}>Owner</option>
            </select>
            <button class="btn btn-ghost-danger btn-icon" data-tooltip="Remove" onclick="removeMembership('${m.uid}', this)"><i data-lucide="trash-2"></i></button>
        </div>
    `;
    grid.appendChild(card);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function disableTwoFactor(uid, btn) {
    if (!await confirmDialog("Disable two-factor authentication for this user? This also clears their backup codes and revokes trusted-device 2FA status.", { danger: true, confirmText: 'Disable 2FA' })) return;
    fetch('/api/users/' + uid + '/2fa/disable', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('twofa-status').textContent = 'Disabled';
                toast('Two-factor authentication disabled.', 'success');
            } else {
                toast(data.message, 'error');
            }
        });
}
</script>

<p><a href="/users">← Back to list</a></p>

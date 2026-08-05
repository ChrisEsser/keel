<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-icon"><i data-lucide="users"></i></div>
        <div>
            <h1 class="page-header-title">Users</h1>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="ModalLoader.open('user-create')"><i data-lucide="plus"></i> New User</button>
    </div>
</div>

<template id="users-tpl">
    <div class="entity-card" data-url="/users/{{uid}}">
        <div class="entity-card-header">
            <div class="entity-card-icon"><i data-lucide="users"></i></div>
            <div class="entity-card-title">{{first_name}} {{last_name}}</div>
        </div>
        <div class="entity-card-body">
            <div class="entity-card-subtitle">{{email}}</div>
            <div class="entity-card-badges">
                <span class="badge badge-{{role_badge_class}}">{{role_label}}</span>
            </div>
        </div>
        <div class="entity-card-actions">
            <button class="btn btn-ghost-primary btn-icon impersonate-btn" data-tooltip="Impersonate" data-email="{{email}}" style="{{impersonate_style}}"><i data-lucide="user-check"></i></button>
            <button class="btn btn-ghost-primary btn-icon" data-tooltip="Settings" onclick="ModalLoader.open('user-settings', '{{uid}}')"><i data-lucide="settings"></i></button>
            <button class="btn btn-ghost-danger btn-icon" data-tooltip="Delete" onclick="deleteUser('{{uid}}')"><i data-lucide="trash-2"></i></button>
        </div>
    </div>
</template>

<div class="card-grid" id="users-tbody">
    <p class="list-loading">Loading…</p>
</div>

<script>
async function deleteUser(uid) {
    if (!await confirmDialog('Delete this user?', { danger: true, confirmText: 'Delete' })) return;
    fetch('/api/users/' + uid, {method: 'DELETE'})
        .then(r => r.json())
        .then(data => { if (data.success) { toast('User deleted.', 'success'); userList.reload(); } else toast(data.message, 'error'); });
}

// Admin-only (this whole page is). Resolves the target by email, then lands on their dashboard
// as them; the "Impersonating …" banner + Stop control come from the layout.
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
document.getElementById('users-tbody').addEventListener('click', e => {
    const imp = e.target.closest('.impersonate-btn');
    if (imp) { e.stopPropagation(); impersonateUser(imp.dataset.email); return; }
    if (e.target.closest('.entity-card-actions')) return;
    const card = e.target.closest('[data-url]');
    if (card) window.location.href = card.dataset.url;
});
const userList = new ApiList('users-tbody', {
    mapItem: item => ({
        ...item,
        role_label: item.is_admin ? 'Admin' : 'User',
        role_badge_class: item.is_admin ? 'primary' : 'secondary',
        // No point impersonating yourself -- hide it on your own card. CURRENT_USER_UID is the
        // real admin here (you can't reach this admin-only page while impersonating).
        impersonate_style: item.uid === CURRENT_USER_UID ? 'display:none' : '',
    }),
});
</script>

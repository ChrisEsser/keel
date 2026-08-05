
<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-icon"><i data-lucide="building-2"></i></div>
        <div>
            <h1 class="page-header-title">Organizations</h1>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="ModalLoader.open('org-create')"><i data-lucide="plus"></i> New Organization</button>
    </div>
</div>

<template id="organizations-tpl">
    <?php // The staff hub, not the customer's dashboard -- see Keel\Accounts\Controller\OrgAdminController. ?>
    <div class="entity-card" data-url="/organizations/{{uid}}">
        <div class="entity-card-header">
            <div class="entity-card-icon"><i data-lucide="building-2"></i></div>
            <div class="entity-card-title">{{name}}</div>
        </div>
        <div class="entity-card-body">
            <div class="entity-card-subtitle">{{email}}</div>
            <div class="entity-card-badges">
                <span class="badge badge-{{plan_badge_class}}">{{plan_label}}</span>
                <span class="badge badge-{{status_badge_class}}">{{status_label}}</span>
            </div>
        </div>
        <div class="entity-card-actions">
            <a href="/activity?org={{uid}}" class="btn btn-ghost-primary btn-icon" data-tooltip="Activity" onclick="event.stopPropagation()"><i data-lucide="history"></i></a>
            <button class="btn btn-ghost-primary btn-icon" data-tooltip="Settings" onclick="ModalLoader.open('org-settings', '{{uid}}')"><i data-lucide="settings"></i></button>
            <button class="btn btn-ghost-danger btn-icon" data-tooltip="Delete" onclick="deleteOrg('{{uid}}')"><i data-lucide="trash-2"></i></button>
        </div>
    </div>
</template>

<div class="card-grid" id="organizations-tbody">
    <p class="list-loading">Loading…</p>
</div>

<script>
async function deleteOrg(uid) {
    if (!await confirmDialog('Delete this organization?', { danger: true, confirmText: 'Delete' })) return;
    fetch('/api/organizations/' + uid, {method: 'DELETE'})
        .then(r => r.json())
        .then(data => { if (data.success) { toast('Organization deleted.', 'success'); orgList.reload(); } else toast(data.message, 'error'); });
}
document.getElementById('organizations-tbody').addEventListener('click', e => {
    if (e.target.closest('.entity-card-actions')) return;
    const card = e.target.closest('[data-url]');
    if (card) window.location.href = card.dataset.url;
});
const orgList = new ApiList('organizations-tbody', {
    mapItem: item => {
        // plan_state comes from OrganizationModel::planState() -- the single source of truth. This
        // used to re-derive "active" as (status && status !== 'canceled'), which rendered a past_due
        // org as a green "Active".
        const STATUS = {
            active:   {label: 'Active',          badge: 'success'},
            past_due: {label: 'Past due',        badge: 'warning'},
            comped:   {label: 'Comped',          badge: 'primary'},
            canceled: {label: 'Canceled',        badge: 'danger'},
            none:     {label: 'No subscription', badge: 'secondary'},
        };
        const status = STATUS[item.plan_state] ?? STATUS.none;
        return {
            ...item,
            // The monthly total, not a tier name -- there isn't one any more. An org with no
            // subscription shows a dash rather than "$0/mo", which would read as a live free plan.
            plan_label: item.has_active_plan ? item.subscription.total + '/mo' : '—',
            plan_badge_class: item.has_active_plan ? 'primary' : 'secondary',
            status_label: status.label,
            status_badge_class: status.badge,
        };
    },
});
</script>

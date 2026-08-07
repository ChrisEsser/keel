<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-icon"><i data-lucide="history"></i></div>
        <div>
            <h1 class="page-header-title">Activity</h1>
            <?php if ($scopeUser): ?>
                <div class="page-header-subtitle"><?= htmlspecialchars($scopeUser['name']) ?> &middot; <?= htmlspecialchars($scopeUser['email']) ?></div>
            <?php elseif ($scopeOrg): ?>
                <div class="page-header-subtitle"><?= htmlspecialchars($scopeOrg['name']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if ($scopeUser || $scopeOrg): ?>
            <a href="/activity" class="btn btn-ghost btn-sm"><i data-lucide="x"></i> Clear scope</a>
        <?php endif; ?>
    </div>
</div>

<?php // Filters narrow an already-useful list rather than building a query: the default view is
      // everything, newest first, which is the right starting point when someone is describing a
      // problem you haven't identified yet. ?>
<div class="activity-filters">
    <select id="af-category">
        <option value="">All categories</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(ucfirst($cat)) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="af-event">
        <option value="">All events</option>
        <?php foreach ($events as $key => [$label, $cat]): ?>
            <option value="<?= htmlspecialchars($key) ?>" data-category="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" id="af-from" aria-label="From date">
    <input type="date" id="af-to" aria-label="To date">
    <button class="btn btn-ghost btn-sm" id="af-reset"><i data-lucide="rotate-ccw"></i> Reset</button>
</div>

<template id="activity-tpl">
    <tr>
        <td class="activity-when" title="{{created_at}}">{{when}}</td>
        <td><span class="badge badge-{{badge_class}}">{{event_label}}</span></td>
        <td class="activity-summary">{{summary}}{{impersonation_note}}</td>
        <td class="activity-who">{{actor_label}}</td>
        <td class="activity-org">{{org_label}}</td>
    </tr>
</template>

<div style="overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr><th>When</th><th>Event</th><th>What happened</th><th>By</th><th>Organization</th></tr>
        </thead>
        <tbody id="activity-tbody"><tr><td colspan="5" class="list-loading">Loading&hellip;</td></tr></tbody>
    </table>
</div>

<script>
// Category -> badge colour. Deliberately coarse: the eye should sort "money problem" from
// "someone changed the team" at a glance, without learning forty distinct colours.
const CATEGORY_BADGE = {
    access: 'secondary',
    account: 'primary',
    team: 'secondary',
    billing: 'warning',
    email: 'secondary',
    admin: 'danger',
};

// Relative for anything recent (what a support call is about), absolute once it's old enough that
// "3 weeks ago" stops being a useful answer. The exact timestamp is always in the title attribute.
function whenLabel(utc) {
    const dt = new Date(utc.replace(' ', 'T') + 'Z');
    if (isNaN(dt)) return utc;
    const mins = Math.round((Date.now() - dt.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    if (mins < 1440) return Math.round(mins / 60) + 'h ago';
    if (mins < 10080) return Math.round(mins / 1440) + 'd ago';
    return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

const SCOPE = new URLSearchParams(location.search);

// Every filter is readable from the URL, so a link can point at "this org's billing events" and
// land with the controls already showing what's being filtered — a filtered list whose dropdowns
// say "All" is a page lying about what it's showing.
for (const [param, id] of [['category', 'af-category'], ['event', 'af-event'], ['from', 'af-from'], ['to', 'af-to']]) {
    if (SCOPE.get(param)) document.getElementById(id).value = SCOPE.get(param);
}

function buildUrl() {
    const p = new URLSearchParams();
    for (const k of ['org', 'user']) {
        if (SCOPE.get(k)) p.set(k, SCOPE.get(k));
    }
    const category = document.getElementById('af-category').value;
    const event = document.getElementById('af-event').value;
    const from = document.getElementById('af-from').value;
    const to = document.getElementById('af-to').value;
    if (category) p.set('category', category);
    if (event) p.set('event', event);
    if (from) p.set('from', from);
    if (to) p.set('to', to);
    const qs = p.toString();
    return '/api/activity' + (qs ? '?' + qs : '');
}

syncEventOptions();

const activityList = new ApiList('activity-tbody', {
    url: buildUrl(),
    perPage: 25,
    emptyLabel: 'activity',
    mapItem: item => ({
        ...item,
        when: whenLabel(item.created_at),
        badge_class: CATEGORY_BADGE[item.category] ?? 'secondary',
        actor_label: item.actor_label || '—',
        org_label: item.org_label || '—',
        // Worth calling out inline: an action taken while impersonating reads as the customer's
        // own everywhere else in the system.
        impersonation_note: item.impersonated ? ' (while impersonating)' : '',
    }),
});

// The event list is long, so picking a category narrows it to that category's events — and
// picking an event implies its category.
function syncEventOptions() {
    const cat = document.getElementById('af-category').value;
    const sel = document.getElementById('af-event');
    let hideSelected = false;
    for (const opt of sel.options) {
        if (!opt.value) continue;
        const hide = cat !== '' && opt.dataset.category !== cat;
        opt.hidden = hide;
        if (hide && opt.selected) hideSelected = true;
    }
    if (hideSelected) sel.value = '';
}

function refresh() {
    syncEventOptions();
    activityList.options.url = buildUrl();
    activityList.reload();
}

for (const id of ['af-category', 'af-event', 'af-from', 'af-to']) {
    document.getElementById(id).addEventListener('change', refresh);
}
document.getElementById('af-reset').addEventListener('click', () => {
    for (const id of ['af-category', 'af-event', 'af-from', 'af-to']) document.getElementById(id).value = '';
    refresh();
});
</script>

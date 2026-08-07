<?php
/**
 * Organization settings: General, Team, Billing.
 *
 * Loaded on demand by ModalLoader from /api/modals/org-settings, so its markup and its JavaScript
 * are not on every page in the application -- only on the ones where someone opens it.
 *
 * EVERY member opens this modal, including plain users who can change almost nothing in it. The
 * panels they can't act on stay visible and get locked with an explanation rather than being
 * hidden: a teammate who can't find the Billing tab files a support ticket asking where it went,
 * and one who can see it greyed out with a sentence saying why does not. See osSetPanelLock().
 *
 * The locking is presentation only. Every endpoint behind these controls runs the same OrgGuard
 * check server-side and answers 403 regardless of what the DOM says.
 */
?>
<div class="modal-overlay" id="org-settings-overlay">
    <div class="modal modal--sidebar" id="org-settings-modal">
        <button class="modal-close"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <nav class="modal-sidebar">
                <div class="modal-sidebar-header" data-modal-title>Organization Settings</div>
                <a href="#" data-panel="general" class="active">
                    <i data-lucide="building-2"></i> General
                </a>
                <a href="#" data-panel="members">
                    <i data-lucide="users-round"></i> Team
                </a>
                <a href="#" data-panel="payment">
                    <i data-lucide="credit-card"></i> Billing
                </a>
            </nav>
            <div class="modal-content">

                <div class="modal-panel active" data-panel="general">
                    <h2>General</h2>
                    <div data-msg="general" style="display:none;"></div>
                    <form data-panel-form="general">
                        <div class="modal-form-row">
                            <label class="modal-form-label" for="os-name">Name</label>
                            <div class="modal-form-field"><input id="os-name" data-field="name" type="text" placeholder="My Workspace"></div>
                        </div>
                        <div class="modal-form-row">
                            <label class="modal-form-label required" for="os-email">Email</label>
                            <div class="modal-form-field"><input id="os-email" data-field="email" type="email" required></div>
                        </div>
                        <div class="modal-form-row">
                            <div class="modal-form-label-col">
                                <label class="modal-form-label" for="os-postal-address">Mailing address</label>
                                <div class="form-text">Added to the footer of marketing email, where the law requires one.</div>
                            </div>
                            <div class="modal-form-field">
                                <input id="os-postal-address" data-field="postal_address" type="text" placeholder="12 Main St, Springfield, IL 62701">
                            </div>
                        </div>
                        <div class="modal-form-actions">
                            <button type="submit" class="btn-primary"><i data-lucide="check"></i> Save</button>
                        </div>
                    </form>
                </div>

                <div class="modal-panel" data-panel="members">
                    <h2>Team</h2>

                    <template id="om-members-tpl">
                        <tr>
                            <td>{{user_name}}</td>
                            <td>{{user_email}}</td>
                            <td>
                                <span style="display:{{role_span_display}}">{{role_label}}</span>
                                <select data-value="{{role}}" style="display:{{role_select_display}}"
                                        onchange="omUpdateRole('{{uid}}', this.value)">
                                    <option value="admin">Admin</option>
                                    <option value="user">User</option>
                                </select>
                            </td>
                            <td>
                                <button class="btn btn-ghost-danger btn-sm" style="display:{{remove_display}}"
                                        onclick="omRemoveMembership('{{uid}}')">
                                    <i data-lucide="trash-2"></i> Remove
                                </button>
                                <button class="btn btn-ghost btn-sm" style="display:{{transfer_display}}"
                                        onclick="omTransferOwnership('{{uid}}', '{{user_name}}')">
                                    <i data-lucide="arrow-right-left"></i> Set Owner
                                </button>
                            </td>
                        </tr>
                    </template>

                    <table class="data-table">
                        <thead>
                            <tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr>
                        </thead>
                        <tbody id="om-members-tbody">
                            <tr><td colspan="4" style="text-align:center;">Loading…</td></tr>
                        </tbody>
                    </table>

                    <h3 class="modal-section-heading">Invite teammate</h3>
                    <div id="om-invite-msg" style="display:none;"></div>
                    <form id="om-invite-form" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <input id="om-invite-email" data-field="email" type="email" required placeholder="Email address">
                        <select id="om-invite-role">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                        <button type="submit" class="btn-primary"><i data-lucide="send"></i> Send Invite</button>
                    </form>
                </div>

                <div class="modal-panel" data-panel="payment">

                    <div id="ob-msg" style="display:none;"></div>

                    <!-- No active subscription -->
                    <div id="ob-no-sub">
                        <div style="display:flex;align-items:flex-start;padding:0.5rem 0 1.25rem;">
                            <div class="page-header-icon"><i data-lucide="layers"></i></div>
                            <div style="flex:1;">
                                <div style="font-weight:600;font-size:0.95rem;">No active plan</div>
                                <div style="font-size:0.85rem;color:var(--ink-subtle);margin-top:0.15rem;">Monthly</div>
                            </div>
                            <a class="btn btn-ghost" href="#" onclick="obOpenPlansModal(); return false;"><i data-lucide="layers"></i> Choose a plan</a>
                        </div>
                    </div>

                    <!-- Active subscription -->
                    <div id="ob-has-sub" style="display:none;">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;padding:0.75rem 0;font-weight:600;">
                            <span>Subscription</span>
                            <span id="ob-plan-status" style="font-size:0.85rem;color:var(--ink-subtle);font-weight:400;"></span>
                        </div>
                        <div id="ob-renewal-text" style="font-size:0.85rem;color:var(--ink-subtle);"></div>

                        <div style="display:flex;gap:0.5rem;padding:1rem 0 1.25rem;">
                            <a class="btn btn-ghost" href="#" onclick="obOpenPlansModal(); return false;"><i data-lucide="layers"></i> Change subscription</a>
                        </div>

                        <div id="ob-payment">
                            <hr class="divider" style="margin:0 0 1.25rem;">

                            <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.75rem;">Payment</div>
                            <div style="display:flex;align-items:center;gap:0.75rem;padding-bottom:0.75rem;">
                                <span id="ob-card-brand-badge" class="ob-card-brand"></span>
                                <span id="ob-card-info" style="font-size:0.875rem;"></span>
                                <button type="button" class="btn btn-ghost" style="margin-left:auto;" onclick="obOpenUpdateCard(this)"><i data-lucide="pencil"></i> Update</button>
                            </div>
                        </div>
                    </div>

                    <!-- Invoices — shown for any org with a Stripe customer -->
                    <div id="ob-invoices" style="display:none;">
                        <hr class="divider" style="margin:0 0 1.25rem;">
                        <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.75rem;">Invoices</div>
                        <table class="data-table">
                            <thead>
                                <tr><th>Date</th><th>Total</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody id="ob-invoices-tbody"></tbody>
                        </table>
                    </div>

                    <!-- Cancel plan — shown when there is a live subscription -->
                    <div id="ob-cancel" style="display:none;">
                        <hr class="divider" style="margin:0 0 1.25rem;">
                        <div style="display:flex;align-items:center;gap:0.75rem;justify-content:flex-end;">
                            <button type="button" class="btn btn-danger" onclick="obCancelPlan(this)"><i data-lucide="x"></i> Cancel Plan</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php // Nested overlay for Stripe's card entry. Outside the panels above so it stacks over them --
      // AjaxModal keeps a static stack, so Escape closes only the topmost. ?>
<div class="modal-overlay" id="ob-card-overlay" style="display:none;">
    <div class="modal modal--plain" style="max-width:440px;width:100%;">
        <button class="modal-close" onclick="obCloseCardModal()"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <div class="modal-content">
                <h2 id="ob-card-title">Payment details</h2>
                <div id="ob-card-msg" style="display:none;"></div>
                <div id="ob-payment-element" style="margin-bottom:1.25rem;"></div>
                <button type="button" class="btn-primary" id="ob-card-submit" onclick="obSubmitCard()">
                    <i data-lucide="check"></i> <span id="ob-card-submit-label">Confirm</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Stripe.js is only loaded where a publishable key is configured (see layouts/main.php), so an
// application with no billing gets null here and the Billing panel simply stays in its "no plan"
// state rather than throwing on load.
const _stripe = window.Stripe ? Stripe('<?= htmlspecialchars($_ENV['STRIPE_PUBLIC_KEY'] ?? '', ENT_QUOTES, 'UTF-8') ?>') : null;

let _omMemberList = null;
let _obCardElements = null;
let _obPaymentElement = null;

// ── Billing ──────────────────────────────────────────────────────────────────

function obUpdateBilling(data) {
    // "has a live subscription in Stripe" (active OR past_due) -- a past_due org still has one to
    // manage and cancel, so it keeps this panel. Entitlement is a separate question
    // (has_active_plan), which is exactly why both exist.
    const paying = data.plan_state === 'active' || data.plan_state === 'past_due';

    document.getElementById('ob-no-sub').style.display = paying ? 'none' : '';
    document.getElementById('ob-has-sub').style.display = paying ? '' : 'none';
    // Nothing to cancel without a subscription id.
    document.getElementById('ob-cancel').style.display = paying ? '' : 'none';
    document.getElementById('ob-payment').style.display = paying ? '' : 'none';

    if (paying) {
        document.getElementById('ob-plan-status').textContent =
            data.plan_state === 'past_due' ? 'Payment failed' : 'Active';

        const renewalEl = document.getElementById('ob-renewal-text');

        if (data.plan_state === 'past_due') {
            // Don't promise an auto-renewal we couldn't collect on -- say what's actually wrong.
            renewalEl.classList.add('field-msg--error');
            renewalEl.textContent = "We couldn't process your last payment. Update your card below to keep your subscription active.";
        } else if (data.subscription_renewal_at) {
            const d = new Date(data.subscription_renewal_at * 1000);
            renewalEl.classList.remove('field-msg--error');
            renewalEl.textContent = 'Your subscription will auto renew on ' +
                d.toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric'}) + '.';
        } else {
            renewalEl.classList.remove('field-msg--error');
            renewalEl.textContent = '';
        }

        const brandEl = document.getElementById('ob-card-brand-badge');
        brandEl.textContent = '';
        brandEl.className = 'ob-card-brand';
        document.getElementById('ob-card-info').textContent = 'Card on file…';
    }

    lucide.createIcons();
}

function obOpenPlansModal() {
    ModalLoader.open('plans', orgModal.uid);
}

async function obOpenUpdateCard(btn) {
    obForm.showMsg('', false);
    setButtonLoading(btn, true, 'Loading…');

    const res = await fetch('/api/billing/setup-card', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_uid: orgModal.uid}),
    });
    const data = await res.json();
    setButtonLoading(btn, false);

    if (data.success) obOpenCardModal(data.clientSecret);
    else obForm.showMsg(data.message ?? 'Something went wrong.', true);
}

function obOpenCardModal(clientSecret) {
    document.getElementById('ob-card-title').textContent = 'Update payment method';
    document.getElementById('ob-card-submit-label').textContent = 'Update';
    obCardForm.showMsg('', false);
    document.getElementById('ob-card-submit').disabled = false;

    _obCardElements = _stripe.elements({clientSecret});
    _obPaymentElement = _obCardElements.create('payment');
    _obPaymentElement.mount('#ob-payment-element');

    document.getElementById('ob-card-overlay').style.display = 'flex';
    lucide.createIcons();
}

async function obSubmitCard() {
    const btn = document.getElementById('ob-card-submit');
    obCardForm.showMsg('', false);
    setButtonLoading(btn, true, 'Saving…');

    // redirect:'if_required' keeps most cards inside this modal. The ones that genuinely need a
    // redirect leave the page and come back to /billing/return, which finishes the job
    // server-side -- see BillingController::return, because this callback will not be here to.
    const returnUrl = `${window.location.origin}/billing/return?mode=update_card&org_uid=${encodeURIComponent(orgModal.uid)}`;

    const result = await _stripe.confirmSetup({
        elements: _obCardElements,
        confirmParams: {return_url: returnUrl},
        redirect: 'if_required',
    });

    if (result.error) {
        obCardForm.showMsg(result.error.message, true);
        setButtonLoading(btn, false);
        return;
    }

    const pm = result.setupIntent?.payment_method;
    if (!pm) { obCardForm.showMsg('Could not retrieve payment method.', true); setButtonLoading(btn, false); return; }

    const confirmRes = await fetch('/api/billing/confirm-card', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_uid: orgModal.uid, payment_method_id: pm}),
    });
    const confirmData = await confirmRes.json();
    setButtonLoading(btn, false);

    if (confirmData.success) {
        obCloseCardModal();
        obLoadBillingInfo(orgModal.uid);
    } else {
        obCardForm.showMsg(confirmData.message ?? 'Something went wrong.', true);
    }
}

function obCloseCardModal() {
    if (_obPaymentElement) {
        _obPaymentElement.unmount();
        _obPaymentElement = null;
    }
    _obCardElements = null;
    document.getElementById('ob-card-overlay').style.display = 'none';
}

async function obLoadBillingInfo(orgUid) {
    const res = await fetch('/api/billing/invoices?org_uid=' + encodeURIComponent(orgUid));
    const data = await res.json();
    if (!data.success) return;

    const card = data.card;
    const brandEl = document.getElementById('ob-card-brand-badge');
    const cardInfoEl = document.getElementById('ob-card-info');
    if (brandEl && cardInfoEl) {
        if (card) {
            const brand = card.brand ?? '';
            brandEl.textContent = brand || '••••';
            brandEl.className = 'ob-card-brand' + (brand ? ' brand-' + brand.toLowerCase() : '');
            const brandLabel = brand ? (brand.charAt(0).toUpperCase() + brand.slice(1)) : '';
            const exp = card.exp_month && card.exp_year
                ? ` · Exp ${card.exp_month}/${String(card.exp_year).slice(-2)}`
                : '';
            cardInfoEl.textContent = brandLabel && card.last4 ? `${brandLabel} •••• ${card.last4}${exp}` : 'Card on file';
        } else {
            brandEl.textContent = '';
            brandEl.className = 'ob-card-brand';
            cardInfoEl.textContent = 'No card on file';
        }
    }

    const section = document.getElementById('ob-invoices');
    const tbody = document.getElementById('ob-invoices-tbody');
    if (!data.invoices.length) {
        section.style.display = 'none';
        return;
    }

    const statusBadge = {
        paid: 'badge-success',
        open: 'badge-secondary',
        draft: 'badge-secondary',
        uncollectible: 'badge-danger',
        void: 'badge-danger',
    };

    tbody.innerHTML = data.invoices.map(inv => {
        const date = new Date(inv.date * 1000).toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric'});
        const total = (inv.total / 100).toLocaleString(undefined, {style: 'currency', currency: inv.currency.toUpperCase()});
        const badge = statusBadge[inv.status] ?? 'badge-secondary';
        const label = inv.status.charAt(0).toUpperCase() + inv.status.slice(1);
        // Stripe's own hosted invoice, not one we render: it is the copy that is legally the
        // invoice, and it stays correct when a refund or credit note lands later.
        const link = inv.url ? `<a href="${esc(inv.url)}" target="_blank" rel="noopener" style="font-size:0.8rem;">View</a>` : '';
        return `<tr>
            <td>${esc(date)}</td>
            <td>${esc(total)}</td>
            <td><span class="badge ${badge}">${esc(label)}</span></td>
            <td>${link}</td>
        </tr>`;
    }).join('');

    section.style.display = '';
}

async function obCancelPlan(btn) {
    if (!await confirmDialog('Cancel your plan? Your subscription will end immediately.', { danger: true, confirmText: 'Cancel plan' })) return;

    obForm.showMsg('', false);
    setButtonLoading(btn, true, 'Canceling…');

    const res = await fetch('/api/billing/cancel-subscription', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_uid: orgModal.uid}),
    });
    const data = await res.json();
    setButtonLoading(btn, false);

    if (data.success) obUpdateBilling(data.data);
    else obForm.showMsg(data.message ?? 'Something went wrong.', true);
}

// ── Panel locking ────────────────────────────────────────────────────────────

// Every member opens this modal, but Team and Billing only act for an owner or admin. The panels
// stay visible rather than being hidden -- a plain user still needs to see who is on the team and
// what plan the org is on -- so instead each one keeps its content, turns its controls off, and
// says why.
const OS_LOCK_NOTES = {
    members: 'Only an owner or admin can invite or remove teammates. You can see the team here, but not change it.',
    payment: 'Only an owner or admin can change the plan or payment method for this organization.',
};

function osSetPanelLock(name, locked, note = null) {
    const panel = document.querySelector('#org-settings-overlay .modal-panel[data-panel="' + name + '"]');
    if (!panel) return;

    const existing = panel.querySelector('[data-lock-note]');
    const text = note ?? OS_LOCK_NOTES[name];
    if (locked && existing === null) {
        const el = document.createElement('div');
        el.className = 'alert alert-info';
        el.setAttribute('data-lock-note', '');
        el.textContent = text;
        // Below the panel heading where there is one; Billing opens straight into its content.
        const heading = panel.querySelector('h2');
        if (heading !== null) heading.after(el); else panel.prepend(el);
    } else if (locked && existing !== null) {
        // Already locked, but possibly for a different reason than last time this org was opened.
        existing.textContent = text;
    } else if (!locked && existing !== null) {
        existing.remove();
    }

    panel.querySelectorAll('button, input, select, textarea').forEach(el => { el.disabled = locked; });
    // Link-buttons ("Choose a plan") can't take :disabled -- see base.css.
    panel.querySelectorAll('a.btn').forEach(el => {
        if (locked) el.setAttribute('aria-disabled', 'true');
        else el.removeAttribute('aria-disabled');
    });
}

// ── Wiring ───────────────────────────────────────────────────────────────────

const orgModal = new AjaxModal('org-settings-overlay', {
    url: '/api/organizations',
    titleField: 'display_name',
    onLoad(data) {
        // Absent capabilities mean a payload older than this code -- lock rather than expose.
        const can = data.caller_can ?? {};
        osSetPanelLock('members', !can.manage_team);
        osSetPanelLock('payment', !can.manage_billing);

        obUpdateBilling(data);
        if (data.stripe_customer_id) obLoadBillingInfo(data.uid);

        _omMemberList = new ApiList('om-members-tbody', {
            filter: false,
            pagination: false,
            url: '/api/organizations/' + data.uid + '/members',
        });
    },
});
ModalLoader.register('org-settings', orgModal);

// Panel sub-flows that AjaxModal's own form handling doesn't cover -- each owns the message slot
// for its panel, and the one that takes input (invite a teammate) also gets required-field
// marking. See ModalForm in app.js.
const omInviteForm = new ModalForm('om-invite-form', 'om-invite-msg');
const obForm = new ModalForm(document.querySelector('#org-settings-overlay .modal-panel[data-panel="payment"]'), 'ob-msg');
// The nested card overlay sits outside those panels.
const obCardForm = new ModalForm('ob-card-overlay', 'ob-card-msg');

// ── Team ─────────────────────────────────────────────────────────────────────

function omUpdateRole(uid, role) {
    fetch('/api/memberships/' + uid, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({role}),
    }).then(r => r.json()).then(data => {
        if (!data.success) toast(data.message ?? 'Failed to update role.', 'error');
    });
}

async function omRemoveMembership(uid) {
    if (!await confirmDialog('Remove this member?', { danger: true, confirmText: 'Remove' })) return;
    fetch('/api/memberships/' + uid, {method: 'DELETE'})
        .then(r => r.json())
        .then(data => { if (data.success) _omMemberList?.reload(); else toast(data.message, 'error'); });
}

async function omTransferOwnership(membershipUid, name) {
    if (!await confirmDialog('Transfer ownership to ' + name + '? The current owner becomes an Admin.', { confirmText: 'Transfer' })) return;
    fetch('/api/organizations/' + orgModal.uid + '/transfer-ownership', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({membership_uid: membershipUid}),
    }).then(r => r.json()).then(data => {
        if (data.success) _omMemberList?.reload();
        else toast(data.message ?? 'Transfer failed.', 'error');
    });
}

onAjaxSubmit(document.getElementById('om-invite-form'), async () => {
    if (!omInviteForm.check()) return;

    const res = await fetch('/api/invitations', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            org_id: orgModal.uid,
            email: document.getElementById('om-invite-email').value,
            role: document.getElementById('om-invite-role').value,
        }),
    });
    const data = await res.json();

    if (data.success) {
        omInviteForm.showMsg('Invitation sent.', false);
        document.getElementById('om-invite-email').value = '';
    } else {
        omInviteForm.failResponse(data);
    }
}, 'Sending…');
</script>

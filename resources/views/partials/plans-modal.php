<?php
/**
 * Start, change or resize the subscription.
 *
 * Keel ships one recurring price with a quantity, because that is the smallest shape that is
 * genuinely correct end to end -- the SetupIntent ordering, the 3-D Secure redirect, the return
 * path -- and those are the parts worth inheriting. What you sell is yours to decide: add a price
 * map and more lines here and in Keel\Billing\StripeService, and the flow around them is unchanged.
 *
 * PRICE_LABEL and PRICE_EACH below are display copy only. The amount actually charged is whatever
 * the Stripe price at STRIPE_PRICE_ID says, and Stripe is the authority -- these two constants
 * exist so the modal can show a running total before the customer commits, not to compute a bill.
 * If they disagree with Stripe, Stripe wins and the customer sees the real number on the invoice.
 */

$priceLabel = $_ENV['STRIPE_PRICE_LABEL'] ?? 'Subscription';
$priceEach  = (int) ($_ENV['STRIPE_PRICE_CENTS'] ?? 0);
?>
<div class="modal-overlay" id="plans-overlay">
    <div class="modal modal--plain pp-modal">
        <button class="modal-close" aria-label="Close"><i data-lucide="x"></i></button>

        <div class="modal-body">
            <div class="modal-content">
                <div class="pp-head">
                    <h2>Your subscription</h2>
                    <p>Change it any time. Increases are billed right away, and decreases are credited back on the same invoice.</p>
                </div>

                <div id="pp-msg" class="pp-msg"></div>

                <div class="pp-line">
                    <div class="pp-line-main">
                        <div class="pp-line-label"><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="pp-line-note" id="pp-line-note"></div>
                    </div>
                    <div class="pp-qty">
                        <button type="button" class="btn btn-ghost btn-icon" aria-label="Fewer" onclick="ppStep(-1)"><i data-lucide="minus"></i></button>
                        <span id="pp-qty" class="pp-qty-value">1</span>
                        <button type="button" class="btn btn-ghost btn-icon" aria-label="More" onclick="ppStep(1)"><i data-lucide="plus"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <?php // Outside .modal-body so it stays pinned while the content scrolls. The total and the
              // button that commits it are the two things that must never scroll off. ?>
        <div class="pp-foot">
            <div class="pp-foot-row">
                <span class="pp-total-label">Total</span>
                <span class="pp-total-amount"><span id="pp-total">$0</span><span class="pp-total-period">/mo</span></span>
            </div>
            <button type="button" class="btn btn-primary pp-save" id="pp-save" onclick="ppSave()">Save changes</button>
            <div class="pp-foot-note" id="pp-save-note"></div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="pp-card-overlay" style="display:none;">
    <div class="modal modal--plain" style="max-width:440px;width:100%;">
        <button class="modal-close" onclick="ppCloseCardModal()"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <div class="modal-content">
                <h2>Payment details</h2>
                <div id="pp-card-msg" style="display:none;"></div>
                <div id="pp-payment-element" style="margin-bottom:1.25rem;"></div>
                <button type="button" class="btn-primary" id="pp-card-submit" onclick="ppSubmitCard()">Subscribe</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Clears the absolutely-positioned close button, which sits over scrolling content. */
.pp-modal .modal-content { padding-top: 2.5rem; }

.pp-head { margin-bottom: 1.25rem; }
.pp-head h2 { margin: 0 0 0.3rem; font-size: 1.15rem; font-weight: 700; color: var(--ink); }
.pp-head p { margin: 0; font-size: 0.875rem; line-height: 1.5; color: var(--ink-subtle); }

.pp-msg { display: none; margin-bottom: 1rem; }

.pp-line { display: flex; align-items: center; gap: var(--space-4); padding: var(--space-4) 0; border-top: 1px solid var(--border-subtle); }
.pp-line-main { flex: 1; min-width: 0; }
.pp-line-label { font-weight: 600; font-size: 0.95rem; color: var(--ink); }
.pp-line-note { margin-top: 0.15rem; font-size: 0.85rem; color: var(--ink-subtle); }

.pp-qty { display: inline-flex; align-items: center; gap: var(--space-2); }
.pp-qty-value { min-width: 2ch; text-align: center; font-variant-numeric: tabular-nums; font-weight: 600; }

.pp-foot { border-top: 1px solid var(--border); padding: var(--space-4) var(--space-5); background: var(--surface); }
.pp-foot-row { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: var(--space-3); }
.pp-total-label { font-weight: 600; }
.pp-total-amount { font-weight: 700; font-size: 1.1rem; }
.pp-total-period { font-size: 0.8rem; font-weight: 400; color: var(--ink-subtle); }
.pp-save { width: 100%; justify-content: center; }
.pp-foot-note { margin-top: var(--space-2); font-size: 0.8rem; color: var(--ink-subtle); text-align: center; }
</style>

<script>
const PP_PRICE_EACH = <?= $priceEach ?>;

const _ppStripe = window.Stripe ? Stripe('<?= htmlspecialchars($_ENV['STRIPE_PUBLIC_KEY'] ?? '', ENT_QUOTES, 'UTF-8') ?>') : null;

let _ppData = {};
let _ppQty = 1;
let _ppStartQty = 1;
let _ppElements = null;
let _ppPaymentElement = null;

function ppMoney(cents) {
    return (cents / 100).toLocaleString(undefined, {style: 'currency', currency: 'USD', minimumFractionDigits: 0});
}

function ppHasSubscription(data) {
    return data.plan_state === 'active' || data.plan_state === 'past_due';
}

function ppSetMsg(text) {
    const el = document.getElementById('pp-msg');
    el.textContent = text ?? '';
    el.className = 'pp-msg alert alert-error';
    el.style.display = text ? '' : 'none';
}

function ppSetCardMsg(text) {
    const el = document.getElementById('pp-card-msg');
    el.textContent = text ?? '';
    el.className = 'alert alert-error';
    el.style.display = text ? '' : 'none';
}

function ppStep(delta) {
    _ppQty = Math.max(1, _ppQty + delta);
    ppRender();
}

function ppRender() {
    document.getElementById('pp-qty').textContent = _ppQty;
    document.getElementById('pp-total').textContent = ppMoney(PP_PRICE_EACH * _ppQty);
    document.getElementById('pp-line-note').textContent =
        PP_PRICE_EACH ? `${ppMoney(PP_PRICE_EACH)} each, per month` : '';

    const save = document.getElementById('pp-save');
    const note = document.getElementById('pp-save-note');

    if (!ppHasSubscription(_ppData)) {
        setButtonLabel(save, 'Subscribe');
        save.disabled = false;
        note.textContent = 'You can cancel any time.';
        return;
    }

    const changed = _ppQty !== _ppStartQty;
    setButtonLabel(save, 'Save changes');
    save.disabled = !changed;
    note.textContent = changed
        ? (_ppQty > _ppStartQty
            ? "You'll be charged the difference for the rest of this month right away."
            : "The difference is credited back on your next invoice.")
        : '';
}

async function ppSave() {
    ppSetMsg('');
    if (!ppHasSubscription(_ppData)) return ppStartSubscribe();

    // Only decreases need confirming. Adding is reversible and costs a known amount; taking
    // something away is the one that surprises people a month later.
    if (_ppQty < _ppStartQty) {
        const ok = await confirmDialog(
            `Reduce your subscription from ${_ppStartQty} to ${_ppQty}? The difference is credited back on your next invoice.`,
            { confirmText: 'Reduce' }
        );
        if (!ok) return;
    }

    const btn = document.getElementById('pp-save');
    setButtonLoading(btn, true, 'Saving…');

    const res = await fetch('/api/billing/update-subscription', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_uid: _ppData.uid, quantity: _ppQty}),
    });
    const data = await res.json();

    if (data.success) {
        // Reload on close so anything gated behind the subscription reflects the change
        // immediately. flashToast, not toast: the close below reloads the page out from under it.
        flashToast('Subscription updated.', 'success');
        plansModal.markDirty();
        plansModal.close();
    } else {
        setButtonLoading(btn, false);
        ppSetMsg(data.message ?? 'Something went wrong.');
    }
}

async function ppStartSubscribe() {
    const btn = document.getElementById('pp-save');
    setButtonLoading(btn, true, 'Starting…');

    const res = await fetch('/api/billing/setup-subscription', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_uid: _ppData.uid}),
    });
    const data = await res.json();
    setButtonLoading(btn, false);

    if (data.success) ppOpenCardModal(data.clientSecret);
    else ppSetMsg(data.message ?? 'Something went wrong.');
}

function ppOpenCardModal(clientSecret) {
    ppSetCardMsg('');
    document.getElementById('pp-card-submit').disabled = false;

    _ppElements = _ppStripe.elements({clientSecret});
    _ppPaymentElement = _ppElements.create('payment');
    _ppPaymentElement.mount('#pp-payment-element');

    document.getElementById('pp-card-overlay').style.display = 'flex';
    lucide.createIcons();
}

function ppCloseCardModal() {
    if (_ppPaymentElement) {
        _ppPaymentElement.unmount();
        _ppPaymentElement = null;
    }
    _ppElements = null;
    document.getElementById('pp-card-overlay').style.display = 'none';
}

async function ppSubmitCard() {
    const btn = document.getElementById('pp-card-submit');
    ppSetCardMsg('');
    setButtonLoading(btn, true, 'Processing…');

    // The quantity rides in the return URL because a 3-D Secure redirect leaves the page: when the
    // browser comes back, this closure is gone and only the query string survives to tell
    // BillingController::return what was being bought.
    const returnUrl = `${window.location.origin}/billing/return?mode=setup_subscribe`
        + `&org_uid=${encodeURIComponent(_ppData.uid)}&quantity=${_ppQty}`;

    const result = await _ppStripe.confirmSetup({
        elements: _ppElements,
        confirmParams: {return_url: returnUrl},
        redirect: 'if_required',
    });

    if (result.error) {
        ppSetCardMsg(result.error.message);
        setButtonLoading(btn, false);
        return;
    }

    const pm = result.setupIntent?.payment_method;
    if (!pm) { ppSetCardMsg('Could not retrieve payment method.'); setButtonLoading(btn, false); return; }

    const confirmRes = await fetch('/api/billing/activate-subscription', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_uid: _ppData.uid, payment_method_id: pm, quantity: _ppQty}),
    });
    const confirmData = await confirmRes.json();
    setButtonLoading(btn, false);

    if (confirmData.success) {
        ppCloseCardModal();
        // Reload on close so paywalled UI unlocks immediately (see ppSave).
        plansModal.markDirty();
        plansModal.close();
    } else {
        ppSetCardMsg(confirmData.message ?? 'Something went wrong.');
    }
}

const plansModal = new AjaxModal('plans-overlay', {
    url: '/api/organizations',
    onLoad(data) {
        _ppData = data;
        _ppStartQty = Math.max(1, Number(data.subscription_quantity ?? 1));
        _ppQty = _ppStartQty;
        ppSetMsg('');
        ppRender();
    },
});
ModalLoader.register('plans', plansModal);
</script>

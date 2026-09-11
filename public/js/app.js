// ── HTML escaping ────────────────────────────────────────────────────────────
// Shared by MultiSelect below and any page that doesn't define its own.

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}


// ── Date formatting ─────────────────────────────────────────────────────────
// Every stamp the API hands the browser is UTC -- Database::connect() pins the MySQL session and
// check-env.php asserts date.timezone on both SAPIs -- but a MySQL datetime string carries no
// timezone suffix, so `new Date("2026-09-11 21:27:00")` is parsed as LOCAL time. That is not an
// error; it is a silently wrong answer, correct only for readers who happen to be in UTC.
// Appending the Z is the whole fix, and every screen showing a datetime should come through here
// rather than reinvent it.

function _parseUtc(str) {
    const d = new Date(String(str).replace(' ', 'T') + 'Z');
    return isNaN(d) ? null : d;
}

// Compact, for a table cell.
function fmtDate(str) {
    if (!str) return '—';
    const d = _parseUtc(str);
    if (d === null) return str;
    return d.toLocaleString(navigator.language, {
        month: 'numeric', day: 'numeric', year: '2-digit',
        hour: 'numeric', minute: '2-digit',
    });
}

// The same instant in prose, for a status line with room to spell it out ("Sep 11, 2026 at
// 2:27 AM"). Same parsing; the only thing that differs is how much of it is shown.
function fmtDateLong(str) {
    if (!str) return '—';
    const d = _parseUtc(str);
    if (d === null) return str;
    const day = d.toLocaleDateString(navigator.language, { month: 'short', day: 'numeric', year: 'numeric' });
    const time = d.toLocaleTimeString(navigator.language, { hour: 'numeric', minute: '2-digit' });
    return `${day} at ${time}`;
}

// The day alone, spelled the way a card title or a heading already spells it ("Sep 10, 2026").
function fmtDayLong(str) {
    if (!str) return '—';
    const d = _parseUtc(str);
    if (d === null) return str;
    return d.toLocaleDateString(navigator.language, { month: 'short', day: 'numeric', year: 'numeric' });
}

// Date without the time, for a column too narrow to carry both.
function fmtDay(str) {
    if (!str) return '—';
    const d = _parseUtc(str);
    if (d === null) return str;
    return d.toLocaleDateString(navigator.language);
}

/**
 * Fills every [data-utc] element from its own stamp, so a server-rendered timestamp is shown in
 * the READER's timezone rather than the server's.
 *
 * The server genuinely cannot do this -- it does not know where the reader is -- so a PHP date()
 * call renders a UTC instant as though it were local and is hours out for everyone outside UTC.
 * The fix is to emit the raw stamp and format it here:
 *
 *     <time data-utc="<?= $e($row->created_at) ?>"></time>
 *
 * data-utc-format picks the shape: "long" for the prose form, "day" for the day alone, omitted
 * for the compact one. A date-only element must stay date-only -- quietly growing a time onto a
 * card title is a regression even though the underlying fix is right.
 */
function hydrateUtcStamps(root = document) {
    root.querySelectorAll('[data-utc]').forEach(el => {
        const stamp = el.getAttribute('data-utc');
        if (!stamp) return;
        const how = el.dataset.utcFormat;
        el.textContent = how === 'long' ? fmtDateLong(stamp)
            : how === 'day' ? fmtDayLong(stamp)
            : fmtDate(stamp);
    });
}

document.addEventListener('DOMContentLoaded', () => hydrateUtcStamps());

window.fmtDate = fmtDate;
window.fmtDateLong = fmtDateLong;
window.fmtDayLong = fmtDayLong;
window.fmtDay = fmtDay;
window.hydrateUtcStamps = hydrateUtcStamps;


// ── UUID generation ──────────────────────────────────────────────────────────
// crypto.randomUUID() only exists in a secure context (HTTPS, or localhost) -- over plain HTTP on
// any other host it is undefined, so calling it directly throws and takes down the rest of the
// enclosing <script> block with it. crypto.getRandomValues(), unlike randomUUID(), is available in
// every context, so this always produces a real RFC4122 v4 UUID -- not merely a unique string,
// since server-side route matching elsewhere expects that exact shape.
//
// Lives in app.js rather than a feature file because app.js is loaded from <head> in both layouts,
// so this is defined before any inline page script parses. Other scripts load at
// the END of <body> -- a helper defined there is unavailable to top-level view code.

function _generateUuidV4() {
    if (crypto.randomUUID) return crypto.randomUUID();

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}


// ── Button loading state ─────────────────────────────────────────────────────
// Swaps a button's contents for a spinner (and disables it) while an async action
// runs, then restores the original label. Reused for the Stripe billing/payouts
// buttons where a network + Stripe round-trip leaves a visible pause.

function setButtonLoading(btn, loading, label) {
    if (!btn) return;
    if (loading) {
        if (btn.dataset.loading === '1') return;
        btn.dataset.loading = '1';
        btn.dataset.prevHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>'
            + (label ? ' ' + esc(label) : '');
    } else {
        if (btn.dataset.loading !== '1') return;
        btn.innerHTML = btn.dataset.prevHtml;
        btn.disabled = false;
        delete btn.dataset.loading;
        delete btn.dataset.prevHtml;
        if (window.lucide) lucide.createIcons();
    }
}
window.setButtonLoading = setButtonLoading;

// Relabel a button that may be mid-spinner right now -- a create form that flips itself to
// edit mode while the save is still in flight retitles its own submit button. Writing
// innerHTML directly there would be thrown away when the spinner is cleared.
function setButtonLabel(btn, html) {
    if (!btn) return;
    if (btn.dataset.loading === '1') btn.dataset.prevHtml = html;
    else btn.innerHTML = html;
}
window.setButtonLabel = setButtonLabel;


// ── Copy to clipboard ────────────────────────────────────────────────────────
// navigator.clipboard exists only in a secure context (HTTPS or localhost), so on a plain-HTTP
// host every copy button silently does nothing -- and says nothing, which is the worse half. The
// textarea + execCommand fallback is deprecated but works everywhere, and this is the one case
// where "deprecated but universal" beats "correct but absent".
//
// Returns true/false rather than toasting, because callers want different words: one says what it
// copied, another says nothing and flashes the control instead.

async function copyText(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
}
window.copyText = copyText;

// One value plus its copy button, as markup. Kept here so every table spells the affordance the
// same way, and so the data-copy attribute the handler below looks for is always written.
function copyableCode(value) {
    const v = esc(value);
    return `<span class="copy-field"><code>${v}</code>`
        + `<button type="button" class="copy-btn" data-copy="${v}" title="Copy" aria-label="Copy ${v}">`
        + '<i data-lucide="copy"></i></button></span>';
}
window.copyableCode = copyableCode;

/**
 * Wires the copy buttons inside a container of values people have to retype somewhere else.
 *
 * An explicit button rather than click-the-text: a monospace string in a table gives a reader no
 * reason to try clicking it, so the affordance has to be visible to exist.
 *
 * Delegated from the container rather than bound per button, because a table like this re-renders
 * and re-binding each time is how you end up copying twice per click. Idempotent for the same
 * reason -- calling it again after a re-render is the expected usage.
 */
function bindCopyableCode(container) {
    if (!container || container.dataset.copyBound === '1') return;
    container.dataset.copyBound = '1';

    container.addEventListener('click', async (e) => {
        const btn = e.target.closest('button.copy-btn');
        if (!btn || !container.contains(btn)) return;
        e.preventDefault();

        const value = btn.dataset.copy || '';
        if (!value) return;

        const ok = await copyText(value);
        // Feedback on the button itself. Six values copied in a row would be six toasts, and the
        // question actually being asked is "did THAT one take".
        btn.classList.toggle('copied', ok);
        btn.classList.toggle('copy-failed', !ok);
        btn.innerHTML = `<i data-lucide="${ok ? 'check' : 'x'}"></i>`;
        btn.setAttribute('aria-label', (ok ? 'Copied: ' : 'Could not copy: ') + value);
        if (window.lucide) lucide.createIcons();

        clearTimeout(btn._copyTimer);
        btn._copyTimer = setTimeout(() => {
            btn.classList.remove('copied', 'copy-failed');
            btn.innerHTML = '<i data-lucide="copy"></i>';
            btn.setAttribute('aria-label', 'Copy ' + value);
            if (window.lucide) lucide.createIcons();
        }, 1400);
    });
}
window.bindCopyableCode = bindCopyableCode;

// For a secret shown once and unrecoverable -- an API key, a recovery code. Worth a toast rather
// than a flash on the button, because somebody who trusts a silent copy and closes the panel has
// lost it for good, and the failure message has to tell them what to do instead.
async function copyKeyValue(el) {
    if (!el) return;
    const ok = await copyText((el.textContent || '').trim());
    toast(ok ? 'Copied.' : 'Could not copy. Select it and copy it by hand.', ok ? 'success' : 'error');
}
window.copyKeyValue = copyKeyValue;


// ── Prose ────────────────────────────────────────────────────────────────────
// "Home", "Home and About", "Home, About and 3 other places" -- capped, so a value used in fifty
// places doesn't produce a confirm dialog nobody reads.

function listPhrase(items, max = 5) {
    const shown = items.slice(0, max);
    const extra = items.length - shown.length;
    if (extra > 0) shown.push(`${extra} other place${extra === 1 ? '' : 's'}`);
    if (shown.length === 1) return shown[0];
    return shown.slice(0, -1).join(', ') + ' and ' + shown[shown.length - 1];
}
window.listPhrase = listPhrase;


// ── AJAX form submit ─────────────────────────────────────────────────────────
// Every form that posts with fetch() instead of a native submit goes through here (or
// through AjaxModal, which calls the same code path for its [data-panel-form] panels).
// It owns the two things each handler would otherwise re-implement by hand:
//
//   * the submit button is disabled and shows a spinner for the whole round-trip, so a
//     slow save doesn't look like a dead click, and
//   * a second submit while one is in flight is dropped rather than firing a duplicate
//     POST -- Enter-Enter and double-clicks are the common way people create two records.
//
// `handler` is `async (form, event) => ...`. Return `true` from it to keep the spinner up
// for a flow that is navigating or reloading: restoring the label there would flash the
// old state back in for the moment before the page goes away.
//
// The set below holds the buttons spinning because a submit is in flight right now, so
// clearStuckLoading() can tell those apart from one left spinning by a finished submit.
// Nearly every handler opens with `modalForm.check()`, whose reset() would otherwise clear
// the spinner one statement after this set it.

const _submitsInFlight = new Set();

function onAjaxSubmit(form, handler, label) {
    // Validation is the handler's (i.e. ModalForm's) -- suppress the native bubble so it
    // doesn't fire instead of, or alongside, our alert + has-error marks.
    form.setAttribute('novalidate', '');

    form.addEventListener('submit', async e => {
        e.preventDefault();

        const btn = submitButtonOf(form);
        if (btn && btn.dataset.loading === '1') return;

        setButtonLoading(btn, true, label);
        if (btn) _submitsInFlight.add(btn);
        try {
            if (await handler(form, e) !== true) setButtonLoading(btn, false);
        } catch (err) {
            setButtonLoading(btn, false);
            throw err;
        } finally {
            if (btn) _submitsInFlight.delete(btn);
        }
    });
}
window.onAjaxSubmit = onAjaxSubmit;

// The button a form's spinner belongs in. Scoped to this form so a modal holding several
// panel forms doesn't spin the wrong panel's button, and `:not([form])` leaves alone a
// button that lives inside this form but submits a different one.
function submitButtonOf(form) {
    return form.querySelector('button[type="submit"]:not([form]), input[type="submit"]:not([form])')
        ?? (form.id ? document.querySelector(`button[type="submit"][form="${form.id}"]`) : null);
}
window.submitButtonOf = submitButtonOf;

// A handler that closes its modal on success may leave the spinner up on purpose (see
// onAjaxSubmit's `true`), which would still be there the next time that modal -- the same
// DOM, reused -- is opened. Reopening clears it. A submit still in flight is left alone:
// this also runs mid-submit, via the reset() inside ModalForm.check().
function clearStuckLoading(root) {
    root.querySelectorAll('[data-loading="1"]').forEach(btn => {
        if (!_submitsInFlight.has(btn)) setButtonLoading(btn, false);
    });
}
window.clearStuckLoading = clearStuckLoading;

// Dismiss-on-backdrop, minus the papercut: a plain `click` on the overlay also fires when
// the press started inside the modal (dragging a text selection, or a slider thumb, past
// the edge before releasing), which would throw away the user's work. Require the press
// AND the release to both land on the backdrop.
function onBackdropDismiss(overlay, close) {
    let pressedBackdrop = false;
    overlay.addEventListener('mousedown', e => { pressedBackdrop = e.target === overlay; });
    overlay.addEventListener('click', e => {
        if (e.target === overlay && pressedBackdrop) close();
        pressedBackdrop = false;
    });
}
window.onBackdropDismiss = onBackdropDismiss;


// ── Field-level form errors ──────────────────────────────────────────────────
// A message printed under the input it belongs to, next to the .has-error border. The modal
// `.alert` is still where a whole-form failure goes; this is for a rejection the user fixes in one
// field -- a name already taken, a malformed URL -- which reads better beside the field than in a
// banner at the top of the modal, sometimes a scroll away from the control it is about.

// Appended to the field's .modal-form-field wrapper rather than inserted after the input, so it
// lands underneath even when the control sits in a flex row beside a prefix, or is one of two
// controls that swap places.
function setFieldError(el, text) {
    if (!el) return;
    clearFieldError(el);
    el.classList.add('has-error');
    const msg = document.createElement('div');
    msg.className = 'field-error';
    msg.textContent = text;
    (el.closest('.modal-form-field') ?? el.parentNode)?.appendChild(msg);
}

function clearFieldError(el) {
    const wrap = el?.closest?.('.modal-form-field') ?? el?.parentNode;
    wrap?.querySelectorAll?.('.field-error').forEach(node => node.remove());
}

function clearFieldErrors(root) {
    root?.querySelectorAll?.('.field-error').forEach(node => node.remove());
}

// The element a message for `name` should be printed under, or null when there isn't one.
//
// Null is a real answer, not a failure: a [data-field] may be a hidden input carrying a value for
// a picker, or sit in a panel that isn't showing, and a message attached to either is a message
// nobody can read. Those go to the alert instead -- the difference between saying it somewhere
// and saying it nowhere. Where a hidden input stands in for a visible control, the visible one
// claims the message with data-error-for="<field>".
function resolveErrorField(root, name) {
    const canShow = el => el && el.type !== 'hidden' && el.getClientRects().length > 0;
    const standIn = root.querySelector(`[data-error-for="${name}"]`);
    if (canShow(standIn)) return standIn;

    const field = root.querySelector(`[data-field="${name}"]`);
    return canShow(field) ? field : null;
}

// Renders a failed response as close to its cause as the shape allows: a 422 keyed by field name
// ({slug: "..."}) prints each message under its own input, and only what has nowhere to land -- an
// unmapped key, a list-shaped `errors`, a bare `message` -- is left over. Returns that leftover as
// the alert text, which is '' when every message found a field.
function renderResponseErrors(root, data) {
    const errors = data.errors;
    if (!errors || Array.isArray(errors) || typeof errors !== 'object') {
        return errors ? Object.values(errors).join(' ') : (data.message || 'An error occurred.');
    }

    const leftover = [];
    let first = null;
    for (const [name, text] of Object.entries(errors)) {
        const el = resolveErrorField(root, name);
        if (!el) { leftover.push(text); continue; }
        setFieldError(el, text);
        if (!first) first = el;
    }
    first?.focus();
    return leftover.join(' ');
}

window.setFieldError = setFieldError;
window.clearFieldError = clearFieldError;
window.clearFieldErrors = clearFieldErrors;
window.renderResponseErrors = renderResponseErrors;


// ── AjaxModal ──────────────────────────────────────────────────────────────

class AjaxModal {

    // Modals can now stack (e.g. the full-screen plans modal opening on top of
    // org-settings without closing it, so cancelling it returns to the modal
    // behind). Every open instance is tracked here so the shared document-level
    // Escape listener below only dismisses the topmost one instead of all of them.
    static #stack = [];

    #uid = null;
    #parentUid = null;
    #dirty = false;
    #overlay;
    #prevFocus = null;
    #keyTrap = null;

    constructor(overlayId, options = {}) {
        this.#overlay = document.getElementById(overlayId);
        this.options = {
            url: null,
            mode: 'edit',       // 'edit' (default) | 'create'
            createUrl: null,    // string, or (parentUid) => string -- required when mode: 'create'
            parentField: null,  // data-field name auto-populated from open()'s uid arg (JSON-body parent uid)
            titleField: null,
            validators: {},
            onSuccess: {},
            onCreated: null,    // (data, form, modal) => void -- owns all post-create behavior
            onLoad: null,
            ...options,
        };
        this.#wire();
    }

    #wire() {
        this.#overlay.querySelector('.modal-close')
            ?.addEventListener('click', () => this.close());

        onBackdropDismiss(this.#overlay, () => this.close());

        document.addEventListener('keydown', e => {
            // Being top of #stack already implies this modal is open (open() pushes
            // before returning, close() pops before returning), so no separate uid check.
            if (e.key === 'Escape' && AjaxModal.#stack[AjaxModal.#stack.length - 1] === this) {
                this.close();
            }
        });

        this.#overlay.querySelectorAll('.modal-sidebar a[data-panel]').forEach(a => {
            a.addEventListener('click', e => { e.preventDefault(); this.tab(a.dataset.panel); });
        });

        // onAjaxSubmit sets novalidate (required-field checking is #submitForm's) and owns
        // the spinner-and-disable on each panel's submit button, so every AjaxModal-driven
        // form gets that for free.
        const label = this.options.mode === 'create' ? 'Creating…' : 'Saving…';
        this.#overlay.querySelectorAll('form[data-panel-form]').forEach(form => {
            onAjaxSubmit(form, () => this.#submitForm(form), form.dataset.loadingLabel ?? label);
        });

        // Clear a field's has-error state as soon as the user acts on it, rather than
        // making them re-submit just to see the red border go away. Not limited to
        // [data-field]: a validator may mark the visible control standing in for a hidden
        // one (redirect-settings' destination picker writes through to a hidden to_url).
        const clear = e => { e.target.classList?.remove('has-error'); clearFieldError(e.target); };
        this.#overlay.addEventListener('input', clear);
        this.#overlay.addEventListener('change', clear);
    }

    open(uid, panel = null) {
        this.#dirty = false;

        this.#overlay.querySelectorAll('[data-msg]').forEach(el => {
            el.textContent = '';
            el.className = '';
            el.style.display = 'none';
        });
        this.#overlay.querySelectorAll('input[type=password]').forEach(i => i.value = '');
        this.#overlay.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
        clearFieldErrors(this.#overlay);
        clearStuckLoading(this.#overlay);

        // display must flip BEFORE tab(): tab() measures the sidebar to scroll the active tab
        // into view (see #scrollTabIntoView), and every dimension on a display:none tree reads
        // as 0 -- the scroll would silently compute against a collapsed box and go nowhere.
        this.#overlay.style.display = 'flex';

        const targetPanel = panel ?? this.#overlay.querySelector('.modal-sidebar a[data-panel]')?.dataset.panel;
        if (targetPanel) this.tab(targetPanel);

        this.#activateA11y();

        AjaxModal.#stack = AjaxModal.#stack.filter(m => m !== this);
        AjaxModal.#stack.push(this);

        if (this.options.mode === 'create') {
            // No record exists yet -- uid here is the parent (e.g. the org a
            // nested resource is being created under), or absent entirely.
            this.#uid = null;
            this.#parentUid = uid ?? null;
            this.#overlay.querySelectorAll('form[data-panel-form]').forEach(f => f.reset());
            if (this.options.parentField) {
                const el = this.#overlay.querySelector(`[data-field="${this.options.parentField}"]`);
                if (el) el.value = uid ?? '';
            }
            return;
        }

        this.#uid = uid;
        fetch(`${this.options.url}/${uid}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.#populate(data.data);
                    this.options.onLoad?.call(this, data.data);
                }
            });
    }

    // Mark the modal so closing it reloads the page (see close()). Custom flows that
    // change server state outside the built-in panel-save path -- e.g. the plans modal
    // switching/activating a subscription -- call this so paywalled UI refreshes without
    // a manual reload.
    markDirty() {
        this.#dirty = true;
    }

    close() {
        this.#overlay.style.display = 'none';
        this.#uid = null;
        AjaxModal.#stack = AjaxModal.#stack.filter(m => m !== this);
        if (this.#keyTrap) {
            this.#overlay.removeEventListener('keydown', this.#keyTrap);
            this.#keyTrap = null;
        }
        if (this.#prevFocus?.focus) this.#prevFocus.focus();
        if (this.#dirty) {
            this.#dirty = false;
            // The site editor supplies a hook to refresh its working copy in place — a full
            // reload there would discard unsaved edits. Everywhere else keeps the reload.
            if (window.SITE_EDITOR_HOOKS?.afterModalSave) {
                window.SITE_EDITOR_HOOKS.afterModalSave();
                // No reload on this path, so a flashed save toast would never be drained by a
                // fresh load — surface it now instead.
                drainFlashToast();
            } else {
                location.reload();
            }
        }
    }

    // Mark the dialog for assistive tech, move focus inside, and trap Tab so
    // keyboard users can't drift into the page behind the modal (WCAG 2.4.3 / 2.1.2).
    #activateA11y() {
        const modal = this.#overlay.querySelector('.modal');
        if (modal) {
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
        }
        this.#prevFocus = document.activeElement;
        (firstFocusable(this.#overlay) || modal)?.focus?.();
        this.#keyTrap = e => { if (e.key === 'Tab') trapTab(e, this.#overlay); };
        this.#overlay.addEventListener('keydown', this.#keyTrap);
    }

    tab(name) {
        this.#overlay.querySelectorAll('.modal-sidebar a[data-panel]').forEach(a => {
            const active = a.dataset.panel === name;
            a.classList.toggle('active', active);
            // Below 860px .modal-sidebar is a horizontal scroll strip (see app.css), so opening
            // straight to a tab beyond the first one can land off-screen -- e.g. a deep link that
            // jumps a support case straight to Domains. Only the sidebar's own scrollLeft moves;
            // unlike Element.scrollIntoView() this can never also scroll the page behind the modal.
            if (active) this.#scrollTabIntoView(a);
        });
        this.#overlay.querySelectorAll('.modal-panel[data-panel]').forEach(p =>
            p.classList.toggle('active', p.dataset.panel === name)
        );
    }

    #scrollTabIntoView(a) {
        const sidebar = a.closest('.modal-sidebar');
        if (!sidebar) return;
        const center = a.offsetLeft + a.offsetWidth / 2 - sidebar.clientWidth / 2;
        // Clamping rather than centering unconditionally is what gives the "as close to centered
        // as it can get" behavior for a tab near either end -- e.g. the last tab can't have empty
        // strip after it just to sit in the middle, so it settles at the max scroll instead.
        sidebar.scrollLeft = Math.max(0, Math.min(center, sidebar.scrollWidth - sidebar.clientWidth));
    }

    showMsg(panel, text, isError) {
        const el = this.#overlay.querySelector(`[data-msg="${panel}"]`);
        if (!el) return;
        el.textContent = text;
        if (text) {
            el.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
            el.style.display = '';
        } else {
            el.className = '';
            el.style.display = 'none';
        }
    }

    #populate(data) {
        const titleEl = this.#overlay.querySelector('[data-modal-title]');
        if (titleEl && this.options.titleField) {
            const val = this.options.titleField.split('+')
                .map(f => data[f.trim()] ?? '').filter(Boolean).join(' ');
            if (val) titleEl.textContent = val;
        }
        this.#overlay.querySelectorAll('[data-field]').forEach(el => {
            const field = el.dataset.field;
            if (!(field in data)) return;
            // A checkbox carries its state in .checked, not .value -- assigning to .value would
            // rename the box rather than tick it. Server booleans arrive as 0/1 (tinyint columns),
            // and "0" is truthy as a string, so compare numerically or every switch reads as on.
            if (AjaxModal.#isCheckbox(el)) el.checked = Number(data[field]) === 1;
            else el.value = data[field] ?? '';
        });
    }

    // Checkboxes are the one control whose value isn't in .value. Kept in one place so populate,
    // submit and the required check can't disagree about what a ticked box means.
    static #isCheckbox(el) {
        return el.tagName === 'INPUT' && el.type === 'checkbox';
    }

    #submitForm(form) {
        const panel = form.dataset.panelForm;
        this.showMsg(panel, '', false);

        const fields = [...form.querySelectorAll('[data-field]')];
        // Form-wide rather than fields-only, so a mark left by a custom validator on a
        // visible stand-in control clears too (see the input/change handlers in #wire).
        form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
        clearFieldErrors(form);

        const payload = {};
        const missing = [];
        fields.forEach(el => {
            // Booleans post as "1"/"0" to match the tinyint columns they came from, and `required`
            // on a checkbox means ticked -- el.value there is the literal "on", which would satisfy
            // a blank check no matter the state of the box.
            if (AjaxModal.#isCheckbox(el)) {
                payload[el.dataset.field] = el.checked ? '1' : '0';
                if (el.required && !el.checked) missing.push(el);
                return;
            }
            payload[el.dataset.field] = el.value;
            if (el.required && !el.value.trim()) missing.push(el);
        });

        if (missing.length) {
            missing.forEach(el => el.classList.add('has-error'));
            this.showMsg(panel, 'Required fields are missing.', true);
            missing[0].focus();
            return;
        }

        const error = this.options.validators[panel]?.(form, payload);
        // A validator that has already put its message somewhere better -- under the offending
        // field, via setFieldError -- returns false to stop the submit without also duplicating it
        // into the alert.
        if (error === false) return;
        if (error) { this.showMsg(panel, error, true); return; }

        if (this.options.mode === 'create') {
            const url = typeof this.options.createUrl === 'function'
                ? this.options.createUrl(this.#parentUid)
                : this.options.createUrl;

            // Returned, not fired-and-forgotten: onAjaxSubmit awaits this to know how long
            // to keep the submit button spinning.
            return fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // No #dirty/reload here -- there's no existing page tied to this record
                    // yet. onCreated owns 100% of what happens next (redirect, close+reload
                    // a list, etc).
                    this.options.onCreated?.call(this, data.data, form, this);
                } else {
                    this.showMsg(panel, renderResponseErrors(form, data), true);
                }
            });
            return;
        }

        return fetch(`${this.options.url}/${this.#uid}`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.#dirty = true;
                if (data.data) this.#populate(data.data);
                this.options.onSuccess[panel]?.(data.data, form, this);

                // A plain single-form modal has nothing more to do once a save lands, so it gets
                // out of the way: close (which refreshes the page behind it) and confirm with a
                // toast, flashed so it survives that reload. A sidebar modal holds several panels
                // the user may keep editing, so it stays open with the inline "Saved." instead.
                const successMsg = form.dataset.successMsg ?? 'Saved.';
                if (this.#isPlain()) {
                    flashToast(successMsg, 'success');
                    this.close();
                } else {
                    this.showMsg(panel, successMsg, false);
                }
            } else {
                // Errors always stay in the form (inline alert + has-error on the offending
                // fields), plain or not -- toasting-and-closing would throw away what the user
                // typed and where the problem is.
                this.showMsg(panel, renderResponseErrors(form, data), true);
            }
        });
    }

    // A modal is "plain" when it has no sidebar -- i.e. a single form, not the multi-panel
    // settings modals. Drives the save-then-close-and-toast behavior in #submitForm.
    #isPlain() {
        return !this.#overlay.querySelector('.modal-sidebar');
    }

    get uid() { return this.#uid; }
}

// ── ModalForm ──────────────────────────────────────────────────────────────
// The same error contract AjaxModal implements above -- an `.alert` message element plus
// `.has-error` on the offending inputs -- for the modals whose submit flow is too custom
// to hand to AjaxModal: file uploads, multi-step pickers, create-then-switch-to-edit.
// Fields opt in with data-field="<name>", exactly as AjaxModal's do; anything without it
// (a hidden org uid, a file picker) is left alone.
//
// The root need not be a <form>: the settings modals drive several sub-flows from a button
// inside a panel <div> (generate an API key, add a sending domain), and those get the same
// treatment. Panels whose messages only report the outcome of an action -- an upload, a
// refund, a DNS check -- use showMsg/showInfo alone and never call check().

class ModalForm {
    #root;
    #msg;

    constructor(root, msg) {
        this.#root = typeof root === 'string' ? document.getElementById(root) : root;
        this.#msg = typeof msg === 'string' ? document.getElementById(msg) : msg;

        // Required-field checking is ours (see check()) -- suppress the browser's native
        // bubble so it doesn't fire instead of/alongside our alert + has-error.
        if (this.#root.tagName === 'FORM') this.#root.setAttribute('novalidate', '');

        // Clear a field's has-error state as soon as the user acts on it, rather than
        // making them re-submit just to see the red border go away.
        const clear = e => { e.target.classList?.remove('has-error'); clearFieldError(e.target); };
        this.#root.addEventListener('input', clear);
        this.#root.addEventListener('change', clear);
    }

    get fields() { return [...this.#root.querySelectorAll('[data-field]')]; }

    showMsg(text, isError) {
        this.#msg.textContent = text;
        if (text) {
            this.#msg.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
            this.#msg.style.display = '';
        } else {
            this.#msg.className = '';
            this.#msg.style.display = 'none';
        }
    }

    // Neither a failure nor a completed save -- progress and deferred-action notices
    // ("Uploading image…") that would otherwise read as one or the other.
    showInfo(text) {
        this.showMsg(text, false);
        if (text) this.#msg.className = 'alert alert-info';
    }

    // The action worked, but the result isn't what the user probably expected -- adding a contact the
    // org has already stopped emailing. Red would say it failed; green would hide the catch.
    showWarn(text) {
        this.showMsg(text, false);
        if (text) this.#msg.className = 'alert alert-warning';
    }

    // Call from open(): reopening must not inherit the previous attempt's alert, red fields,
    // or a submit button still spinning from a save that closed the modal.
    reset() {
        this.showMsg('', false);
        this.#root.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
        clearFieldErrors(this.#root);
        clearStuckLoading(this.#root);
    }

    // Returns true when every required field is filled, so callers read as
    // `if (!modalForm.check()) return;`. Otherwise marks them and focuses the first.
    check() {
        this.reset();
        const missing = this.fields.filter(el => el.required && !el.value.trim());
        if (!missing.length) return true;
        missing.forEach(el => el.classList.add('has-error'));
        this.showMsg('Required fields are missing.', true);
        missing[0].focus();
        return false;
    }

    // A rule the server owns but the form can check first (a destination that must be picked, a
    // malformed URL), landing on the offending control instead of only in the alert. `el` may be
    // any element -- reset() clears has-error form-wide, so it needn't carry a data-field.
    fail(el, text) {
        el.classList.add('has-error');
        this.showMsg(text, true);
        el.focus();
    }

    // The same as fail(), for a message that belongs under the field rather than in the alert --
    // one the user can correct in place, where a banner at the top of the modal is further from
    // the problem than the field itself is.
    failField(el, text) {
        setFieldError(el, text);
        el.focus();
    }

    // Render a failed JSON response -- see renderResponseErrors: field-keyed messages go under
    // their own inputs, and the alert is left with whatever had no field to land on.
    failResponse(data) {
        this.showMsg(renderResponseErrors(this.#root, data), true);
    }
}

// Lazily fetches views/partials/*-modal.php fragments (server-whitelisted in
// App\Controller\ModalController) into #modal-root on first open, instead of
// views/layouts/main.php shipping all of them on every page. Each partial's own
// <script> calls ModalLoader.register('<name>', <instance>) right after constructing
// its AjaxModal (or IIFE) instance so ModalLoader knows which object to delegate
// `.open(...)` to.
const ModalLoader = (() => {
    const pending = {};    // name -> Promise<void>, resolves once fetched+injected+registered
    const instances = {};  // name -> modal instance object exposing .open(...)

    function register(name, instance) {
        instances[name] = instance;
    }

    function load(name) {
        if (pending[name]) return pending[name];

        pending[name] = fetch(`/api/modals/${name}`)
            .then(r => {
                if (!r.ok) throw new Error(`Failed to load modal "${name}" (${r.status})`);
                return r.text();
            })
            .then(html => {
                const root = document.getElementById('modal-root');

                const wrapper = document.createElement('div');
                wrapper.innerHTML = html;

                // Scripts parsed via innerHTML never auto-execute -- pull them out,
                // move the rest into the (already-connected) #modal-root, then
                // re-create real <script> elements so they run.
                const scripts = [...wrapper.querySelectorAll('script')];
                scripts.forEach(s => s.remove());
                while (wrapper.firstChild) root.appendChild(wrapper.firstChild);

                scripts.forEach(oldScript => {
                    const newScript = document.createElement('script');
                    [...oldScript.attributes].forEach(a => newScript.setAttribute(a.name, a.value));
                    newScript.textContent = oldScript.textContent;
                    root.appendChild(newScript);
                });

                if (typeof lucide !== 'undefined') lucide.createIcons();

                if (!instances[name]) {
                    throw new Error(`Modal "${name}" fetched but never called ModalLoader.register('${name}', ...)`);
                }
            })
            .catch(err => {
                pending[name] = null; // allow retry on next open()
                throw err;
            });

        return pending[name];
    }

    function open(name, ...args) {
        return load(name).then(() => instances[name].open(...args));
    }

    return { register, open };
})();


class ApiList {

    #data = [];
    #meta = null;
    #searchInput = null;
    #pagerEl = null;
    #emptyEl = null;
    #page = 1;

    constructor(containerId, options = {}) {
        this.containerId = containerId;
        this.resource = containerId.replace(/-[^-]+$/, '');
        this.options = { filter: true, pagination: true, perPage: 15, mapItem: null, renderItem: null, filterItems: null, emptyLabel: null, emptyHtml: null, onLoad: null, onRender: null, ...options };
        this.load();
    }

    /**
     * The rows currently loaded, as the server sent them.
     *
     * A copy, not the array itself: a caller that sorted it in place would silently reorder the
     * list on the next render, from a line that looks like it only reads.
     */
    get data() { return [...this.#data]; }

    #fillTemplate(data) {
        const tpl = document.getElementById(`${this.resource}-tpl`);
        const clone = tpl.content.cloneNode(true);

        const walker = document.createTreeWalker(clone, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            node.nodeValue = node.nodeValue.replace(/\{\{(\w+)\}\}/g,
                (_, k) => (k in data ? data[k] : ''));
        }

        clone.querySelectorAll('*').forEach(el => {
            Array.from(el.attributes).forEach(attr => {
                attr.value = attr.value.replace(/\{\{(\w+)\}\}/g,
                    (_, k) => (k in data ? data[k] : ''));
            });
        });

        // Set <select> values from data-value after attribute substitution
        clone.querySelectorAll('select[data-value]').forEach(sel => {
            sel.value = sel.dataset.value;
        });

        return clone;
    }

    #showMessage(container, text, color) {
        if (container.tagName === 'TBODY') {
            container.innerHTML = `<tr><td colspan="100" style="text-align:center${color ? ';color:' + color : ''}">${text}</td></tr>`;
        } else {
            const p = document.createElement('p');
            p.style.cssText = `text-align:center${color ? ';color:' + color : ''}`;
            p.textContent = text;
            container.replaceChildren(p);
        }
    }

    #buildEmptyFragment() {
        const wrap = document.createElement('div');
        wrap.className = 'list-empty';
        // The custom emptyHtml (e.g. a "create your first thing" CTA) is for a genuinely empty
        // resource. When a search simply matched nothing, fall back to the neutral "No … found."
        // display every list uses — inviting the user to create something mid-search is wrong.
        const searching = (this.#searchInput?.value.trim() ?? '') !== '';
        if (this.options.emptyHtml && !searching) {
            // Callable so it can describe the CURRENT state rather than a fixed one -- a list
            // narrowed by a client-side filter is empty for a different reason, and says so.
            wrap.innerHTML = typeof this.options.emptyHtml === 'function'
                ? this.options.emptyHtml()
                : this.options.emptyHtml;
            return wrap;
        }
        const icon = document.createElement('i');
        icon.setAttribute('data-lucide', 'inbox');
        const p = document.createElement('p');
        p.textContent = `No ${this.options.emptyLabel ?? this.resource} found.`;
        wrap.append(icon, p);
        return wrap;
    }

    #showEmpty(container) {
        this.#emptyEl?.remove();
        if (container.tagName === 'TBODY') {
            container.innerHTML = '<tr><td colspan="100"></td></tr>';
            container.querySelector('td').appendChild(this.#buildEmptyFragment());
            this.#emptyEl = null;
        } else {
            container.replaceChildren();
            this.#emptyEl = this.#buildEmptyFragment();
            container.insertAdjacentElement('afterend', this.#emptyEl);
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    #render(items) {
        const container = document.getElementById(this.containerId);

        // A client-side narrowing of what the server already sent -- a folder chip, a status
        // toggle -- filtering on a field every row carries.
        //
        // Correct ONLY for a list that is whole, i.e. pagination:false. Filtering a PAGE shows an
        // empty folder whose contents were on page two, which reads as data loss rather than as a
        // paging artifact.
        if (this.options.filterItems) {
            items = items.filter(this.options.filterItems);
        }

        if (!items.length) {
            this.#showEmpty(container);
            this.#afterRender();
            return;
        }
        this.#emptyEl?.remove();
        this.#emptyEl = null;
        container.replaceChildren();

        // renderItem is for a list whose rows a <template> cannot express -- a card with
        // conditional badges, an optional link, a note that is only sometimes there.
        //
        // Note the difference in who owns escaping. The template path substitutes into text nodes
        // and attribute values, so it cannot emit markup no matter what a row contains. A renderer
        // building its own HTML string is responsible for escaping what it interpolates (esc() is
        // right there), exactly as it was before it was handed to this class.
        if (this.options.renderItem) {
            container.insertAdjacentHTML('beforeend', items.map(item =>
                this.options.renderItem(this.options.mapItem ? this.options.mapItem(item) : item)
            ).join(''));
        } else {
            items.forEach(item => {
                const mapped = this.options.mapItem ? this.options.mapItem(item) : item;
                container.appendChild(this.#fillTemplate(mapped));
            });
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();
        this.#afterRender();
    }

    /**
     * Called every time the list is drawn, whether that came from a fetch or from rerender().
     *
     * onLoad is NOT this: it fires once per response, so anything it attached to a row was
     * silently lost the moment the list re-drew from rows already in hand. Anything that must
     * survive a re-render belongs here.
     */
    #afterRender() {
        // Rows arrive long after DOMContentLoaded, so the page-load pass over [data-utc] never
        // sees them. Every list gets one here instead, which is what lets a row template write
        // <time data-utc="{{created_at}}"> and have it simply work -- the alternative was each
        // endpoint formatting a date server-side, where the reader's timezone is not known.
        const container = document.getElementById(this.containerId);
        if (container) hydrateUtcStamps(container);
        this.options.onRender?.(this.#data);
    }

    #update() {
        this.#render(this.#data);
        if (this.#pagerEl && this.#meta) this.#updatePager(this.#meta);
    }

    #buildSearch() {
        const container = document.getElementById(this.containerId);
        const anchor = container.tagName === 'TBODY' ? container.closest('table') : container;
        
        const wrapper = document.createElement('div');
        wrapper.className = 'list-search';
        const icon = document.createElement('i');
        icon.setAttribute('data-lucide', 'search');
        this.#searchInput = document.createElement('input');
        this.#searchInput.type = 'search';
        this.#searchInput.placeholder = 'Search…';
        wrapper.appendChild(icon);
        wrapper.appendChild(this.#searchInput);
        anchor.insertAdjacentElement('beforebegin', wrapper);

        this.#searchInput.addEventListener('input', () => {
            this.#page = 1;
            this.load();
        });
    }

    #buildPager() {
        const container = document.getElementById(this.containerId);
        const anchor = container.tagName === 'TBODY' ? container.closest('table') : container;
        this.#pagerEl = document.createElement('div');
        this.#pagerEl.className = 'list-pager';
        anchor.insertAdjacentElement('afterend', this.#pagerEl);
    }

    #updatePager(meta) {
        if (meta.total_pages <= 1) {
            this.#pagerEl.style.display = 'none';
            return;
        }
        this.#pagerEl.style.display = '';

        const prev = document.createElement('button');
        prev.textContent = '← Prev';
        prev.disabled = meta.page <= 1;
        prev.addEventListener('click', () => { this.#page--; this.load(); });

        const info = document.createElement('span');
        info.textContent = `Page ${meta.page} of ${meta.total_pages}`;

        const next = document.createElement('button');
        next.textContent = 'Next →';
        next.disabled = meta.page >= meta.total_pages;
        next.addEventListener('click', () => { this.#page++; this.load(); });

        this.#pagerEl.replaceChildren(prev, info, next);
    }

    load() {
        const container = document.getElementById(this.containerId);
        const params = new URLSearchParams({
            page: this.#page,
            per_page: this.options.perPage,
        });
        const search = this.#searchInput?.value.trim() ?? '';
        if (search) params.set('search', search);
        const baseUrl = this.options.url ?? `/api/${this.resource}`;
        const sep = baseUrl.includes('?') ? '&' : '?';
        fetch(`${baseUrl}${sep}${params}`)
            .then(r => r.json())
            .then(
                json => {
                    this.#data = (json.success && json.data) ? json.data : [];
                    this.#meta = json.meta ?? null;
                    // Only offer a search box when there's something to search: results present, or
                    // a search term already active (so a no-match search can still be cleared). An
                    // empty resource with no active search — a brand-new org's list, say —
                    // gets no box at all, and deleting the last item removes it again.
                    if (this.options.filter) {
                        if (!this.#searchInput && (this.#data.length > 0 || search !== '')) {
                            this.#buildSearch();
                        } else if (this.#searchInput && this.#data.length === 0 && search === '') {
                            this.#searchInput.closest('.list-search')?.remove();
                            this.#searchInput = null;
                        }
                    }
                    if (this.options.pagination && !this.#pagerEl) this.#buildPager();
                    this.#update();
                    // For a list that owns surrounding chrome — a whole section that should only
                    // exist when there are rows (the Members page's pending invitations).
                    this.options.onLoad?.(this.#data, this.#meta);
                },
                () => this.#showMessage(container, `Failed to load ${this.resource}.`, 'red')
            );
    }

    /**
     * Re-draw from the rows already fetched, without asking the server again.
     *
     * For a filter that lives in the browser: switching folder changes which rows are shown, not
     * which rows exist. reload() would also work, and would spend a request per click to receive
     * the same list back.
     */
    rerender() { this.#update(); }

    reload() { this.load(); }

    // Swaps the base endpoint (e.g. to add/remove a filter query param) and reloads from page 1 --
    // page/per_page/search are re-appended by load(). Used by the Products list's category filter.
    setUrl(url) {
        this.options.url = url;
        this.#page = 1;
        this.load();
    }
}


// ── MultiSelect ─────────────────────────────────────────────────────────────

class MultiSelect {
    constructor(el, placeholder) {
        this.el = el;
        this.placeholder = placeholder;
        el.style.position = 'relative';
        el.innerHTML = `
            <div class="ms-trigger">
                <span class="ms-label">${esc(placeholder)}</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="ms-drop"></div>
        `;
        this._trigger = el.querySelector('.ms-trigger');
        this._drop    = el.querySelector('.ms-drop');
        this._label   = el.querySelector('.ms-label');

        this._trigger.addEventListener('click', e => { e.stopPropagation(); this._toggle(); });
        document.addEventListener('click', e => { if (!el.contains(e.target)) this._close(); });
    }

    setItems(items) {
        this._drop.innerHTML = items.length === 0
            // Set `ms.emptyLabel` to say WHY it is empty -- "no lists yet", "none left to add" --
            // which is usually the more useful sentence than the generic one.
            ? `<div class="ms-empty">${esc(this.emptyLabel || 'None available.')}</div>`
            : items.map(i =>
                `<label class="ms-item">
                    <input type="checkbox" value="${esc(i.value)}">
                    <span>${esc(i.label)}</span>
                </label>`
              ).join('');
        this._drop.querySelectorAll('input').forEach(cb =>
            cb.addEventListener('change', () => this._update())
        );
        // Re-applied because setItems() just rebuilt the checkboxes the last call disabled.
        this.setDisabled(this._disabled === true);
        this._update();
    }

    /**
     * Lock the control without hiding it, the way a disabled <select> is locked.
     *
     * Shown rather than removed on purpose: a control that isn't there can't explain why it isn't
     * there, and the reason belongs beside it -- so a reader who expected to find it here gets an
     * answer instead of hunting for a control that was never missing.
     */
    setDisabled(flag) {
        this._disabled = flag;
        this.el.classList.toggle('ms-disabled', flag);
        this._drop.querySelectorAll('input').forEach(cb => { cb.disabled = flag; });
        if (flag) this._close();
    }

    getValues() {
        return [...this._drop.querySelectorAll('input:checked')].map(cb => cb.value);
    }

    setValues(vals) {
        vals.forEach(v => {
            const cb = this._drop.querySelector(`input[value="${CSS.escape(v)}"]`);
            if (cb) cb.checked = true;
        });
        this._update();
    }

    _update() {
        const checked = this._drop.querySelectorAll('input:checked');
        this._label.classList.toggle('has-value', checked.length !== 0);
        if (checked.length === 0) {
            this._label.textContent = this.placeholder;
        } else if (checked.length === 1) {
            this._label.textContent = checked[0].nextElementSibling.textContent;
        } else {
            this._label.textContent = checked.length + ' selected';
        }
    }

    _toggle() {
        if (this._disabled) return;
        const opening = !this._drop.classList.contains('ms-drop--open');
        if (opening) Object.values(_multiSelects).forEach(ms => ms._close());
        this._drop.classList.toggle('ms-drop--open', opening);
    }
    _close() { this._drop.classList.remove('ms-drop--open'); }
}

const _multiSelects = {};

function _getMs(id, placeholder) {
    if (!_multiSelects[id]) _multiSelects[id] = new MultiSelect(document.getElementById(id), placeholder);
    return _multiSelects[id];
}


// ── Mobile sidebar drawer ────────────────────────────────────────────────────
// Below the responsive breakpoint (app.css) the sidebar is an off-canvas drawer.
// These toggle body.sidebar-open (wired via onclick in views/layouts/main.php)
// and keep the hamburger's aria-expanded in sync for screen readers.

let _sidebarKeydown = null;

function _syncSidebarAria() {
    const open = document.body.classList.contains('sidebar-open');
    document.querySelector('.mobile-nav-toggle')
        ?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function toggleSidebar() {
    if (document.body.classList.contains('sidebar-open')) closeSidebar();
    else openSidebar();
}

function openSidebar() {
    document.body.classList.add('sidebar-open');
    _syncSidebarAria();
    const sidebar = document.getElementById('app-sidebar');
    // Treat the open drawer like a modal: move focus in, trap Tab, Escape closes
    // (WCAG 2.1.2). The listener is added on open and removed on close.
    _sidebarKeydown = e => {
        if (e.key === 'Escape') closeSidebar();
        else if (e.key === 'Tab' && sidebar) trapTab(e, sidebar);
    };
    document.addEventListener('keydown', _sidebarKeydown, true);
    if (sidebar) (firstFocusable(sidebar) || sidebar).focus?.();
}

function closeSidebar() {
    if (!document.body.classList.contains('sidebar-open')) return;
    document.body.classList.remove('sidebar-open');
    _syncSidebarAria();
    if (_sidebarKeydown) {
        document.removeEventListener('keydown', _sidebarKeydown, true);
        _sidebarKeydown = null;
    }
    document.querySelector('.mobile-nav-toggle')?.focus?.();
}


// toast(), confirmDialog() and the focus helpers (focusableWithin/firstFocusable/
// trapTab, used by AjaxModal above) now live in public/js/feedback.js, loaded
// before app.js in the main layout and standalone in the fullscreen builder.

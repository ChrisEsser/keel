// ── HTML escaping ────────────────────────────────────────────────────────────
// Shared by MultiSelect below and any page that doesn't define its own.

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}


// ── Date formatting ─────────────────────────────────────────────────────────

function fmtDate(str) {
    if (!str) return '—';
    // MySQL datetime strings are stored in UTC (PHP's date.timezone is UTC)
    // but have no timezone suffix, so append one to avoid the browser
    // parsing them as local time.
    const d = new Date(str.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return str;
    return d.toLocaleString(navigator.language, {
        month: 'numeric', day: 'numeric', year: '2-digit',
        hour: 'numeric', minute: '2-digit',
    });
}


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
        const clear = e => e.target.classList?.remove('has-error');
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
        clearStuckLoading(this.#overlay);

        const targetPanel = panel ?? this.#overlay.querySelector('.modal-sidebar a[data-panel]')?.dataset.panel;
        if (targetPanel) this.tab(targetPanel);

        this.#overlay.style.display = 'flex';
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
        this.#overlay.querySelectorAll('.modal-sidebar a[data-panel]').forEach(a =>
            a.classList.toggle('active', a.dataset.panel === name)
        );
        this.#overlay.querySelectorAll('.modal-panel[data-panel]').forEach(p =>
            p.classList.toggle('active', p.dataset.panel === name)
        );
    }

    // A 422 body may key its errors by field name ({slug: "..."}) instead of returning a bare
    // list. When it does, mark those inputs the same way an empty required field is marked, so a
    // server-side rejection (a duplicate value, say -- something only the server can know) lands
    // on the offending field instead of only in the alert. A list-shaped `errors` has no field
    // names to map, so it just shows the alert as before.
    #markFieldErrors(form, errors) {
        if (!errors || Array.isArray(errors) || typeof errors !== 'object') return;
        let first = null;
        for (const key of Object.keys(errors)) {
            const el = form.querySelector(`[data-field="${key}"]`);
            if (!el) continue;
            el.classList.add('has-error');
            if (!first) first = el;
        }
        first?.focus();
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
                    const msg = data.errors
                        ? Object.values(data.errors).join(' ')
                        : (data.message || 'An error occurred.');
                    this.#markFieldErrors(form, data.errors);
                    this.showMsg(panel, msg, true);
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
                const msg = data.errors
                    ? Object.values(data.errors).join(' ')
                    : (data.message || 'An error occurred.');
                this.#markFieldErrors(form, data.errors);
                this.showMsg(panel, msg, true);
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
        const clear = e => e.target.classList?.remove('has-error');
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

    // Render a failed JSON response. A 422 keyed by field name ({slug: "..."}) also marks that
    // input, so a server-only rejection like a duplicate value lands on the field; a list-shaped
    // `errors` has no field names to map and just shows the alert.
    failResponse(data) {
        const errors = data.errors;
        const text = errors
            ? Object.values(errors).join(' ')
            : (data.message || 'An error occurred.');
        if (errors && !Array.isArray(errors) && typeof errors === 'object') {
            let first = null;
            for (const key of Object.keys(errors)) {
                const el = this.#root.querySelector(`[data-field="${key}"]`);
                if (!el) continue;
                el.classList.add('has-error');
                if (!first) first = el;
            }
            first?.focus();
        }
        this.showMsg(text, true);
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
        this.options = { filter: true, pagination: true, perPage: 15, mapItem: null, emptyLabel: null, emptyHtml: null, onLoad: null, ...options };
        this.load();
    }

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
            wrap.innerHTML = this.options.emptyHtml;
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
        if (!items.length) {
            this.#showEmpty(container);
            return;
        }
        this.#emptyEl?.remove();
        this.#emptyEl = null;
        container.replaceChildren();
        items.forEach(item => {
            const mapped = this.options.mapItem ? this.options.mapItem(item) : item;
            container.appendChild(this.#fillTemplate(mapped));
        });
        if (typeof lucide !== 'undefined') lucide.createIcons();
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
                <span class="ms-label">${placeholder}</span>
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
            ? '<div class="ms-empty">None available.</div>'
            : items.map(i =>
                `<label class="ms-item">
                    <input type="checkbox" value="${esc(i.value)}">
                    <span>${esc(i.label)}</span>
                </label>`
              ).join('');
        this._drop.querySelectorAll('input').forEach(cb =>
            cb.addEventListener('change', () => this._update())
        );
        this._update();
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

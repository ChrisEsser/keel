// ── Shared feedback bundle ────────────────────────────────────────────────────
// toast(), confirmDialog(), and focus helpers, extracted from app.js so BOTH the
// main app layout AND the fullscreen builder layout (which does not load app.js)
// can use them. Loaded before app.js in views/layouts/main.php (AjaxModal here
// relies on firstFocusable/trapTab). Styles live in public/css/app.css, which
// every layout loads. No DOM dependency at parse time.


// ── Focus helpers (shared by modals + confirm dialog) ─────────────────────────

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), ' +
    'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Visible, focusable descendants in DOM order. offsetParent skips hidden panels.
function focusableWithin(container) {
    return [...container.querySelectorAll(FOCUSABLE)].filter(el => el.offsetParent !== null);
}

function firstFocusable(container) {
    return focusableWithin(container)[0] ?? null;
}

// Keep Tab focus cycling inside `container` (call from a keydown handler on Tab).
function trapTab(e, container) {
    const list = focusableWithin(container);
    if (!list.length) return;
    const first = list[0];
    const last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}


// ── Toast notifications ───────────────────────────────────────────────────────
// window.toast(message, type) where type ∈ info | success | error | warn.
// Replaces alert() for non-blocking feedback. Errors use role="alert" so screen
// readers announce them immediately; others use a polite live region.

// Static, trusted SVG glyphs per type. They inherit --toast-tone via currentColor.
const TOAST_ICONS = {
    success: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8.25"/><path d="M6.5 10.2 9 12.7l4.5-5"/></svg>',
    error: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8.25"/><path d="M7 7l6 6M13 7l-6 6"/></svg>',
    warn: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 2.5 18.5 17H1.5z"/><path d="M10 8v4"/><circle cx="10" cy="14.6" r="0.6" fill="currentColor" stroke="none"/></svg>',
    info: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8.25"/><path d="M10 9v4.5"/><circle cx="10" cy="6.2" r="0.6" fill="currentColor" stroke="none"/></svg>',
};

// A toast that survives a full-page reload. Plain modals close + reload the page behind them on
// save (see AjaxModal), which would discard a toast shown before navigating -- so the message is
// stashed in sessionStorage and surfaced by drainFlashToast() on the next load. drainFlashToast()
// is also called directly on the no-reload path (the site editor refreshes in place), so the toast
// shows either way. Falls back to an immediate toast if storage is unavailable.
const FLASH_TOAST_KEY = 'flash-toast';
function flashToast(message, type = 'info') {
    try {
        sessionStorage.setItem(FLASH_TOAST_KEY, JSON.stringify({ message, type }));
    } catch (e) {
        toast(message, type);
    }
}
window.flashToast = flashToast;

function drainFlashToast() {
    let raw;
    try {
        raw = sessionStorage.getItem(FLASH_TOAST_KEY);
        if (raw) sessionStorage.removeItem(FLASH_TOAST_KEY);
    } catch (e) {
        return;
    }
    if (!raw) return;
    try {
        const { message, type } = JSON.parse(raw);
        if (message) toast(message, type);
    } catch (e) { /* malformed — nothing to show */ }
}
window.drainFlashToast = drainFlashToast;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', drainFlashToast);
} else {
    drainFlashToast();
}

function toast(message, type = 'info', opts = {}) {
    let region = document.getElementById('toast-region');
    if (!region) {
        region = document.createElement('div');
        region.id = 'toast-region';
        region.className = 'toast-region';
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
    }

    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast__icon';
    icon.innerHTML = TOAST_ICONS[type] ?? TOAST_ICONS.info; // trusted static SVG
    const msg = document.createElement('span');
    msg.className = 'toast__msg';
    msg.textContent = message; // user-supplied — keep as text, never innerHTML
    el.append(icon, msg);

    region.appendChild(el);
    requestAnimationFrame(() => el.classList.add('toast--in'));

    let removed = false;
    const remove = () => {
        if (removed) return;
        removed = true;
        el.classList.remove('toast--in');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
        setTimeout(() => el.remove(), 400); // fallback if no transition fires
    };

    const duration = opts.duration ?? (type === 'error' ? 6000 : 4000);
    const timer = setTimeout(remove, duration);
    el.addEventListener('click', () => { clearTimeout(timer); remove(); });
    return el;
}
window.toast = toast;


// ── Confirm dialog ────────────────────────────────────────────────────────────
// Promise-based replacement for native confirm(). Usage:
//   if (!await confirmDialog('Delete this?', { danger: true, confirmText: 'Delete' })) return;
// Resolves true on confirm, false on cancel/Escape/backdrop. Focus is trapped and
// restored (WCAG 2.4.3 / 2.1.2).
//
// Focus lands on the confirm button so the common case is one keystroke. Pass
// { focus: 'cancel' } where confirming is expensive and irreversible -- taking
// someone's money, mainly -- so a stray Enter cancels instead of spending.

function confirmDialog(message, opts = {}) {
    const {
        title = 'Please confirm',
        confirmText = 'Confirm',
        cancelText = 'Cancel',
        danger = false,
        focus = 'confirm',
    } = opts;

    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay confirm-overlay';
        overlay.style.display = 'flex';
        overlay.innerHTML =
            `<div class="modal modal--confirm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
                <div class="confirm-body">
                    <h2 id="confirm-title" class="confirm-title"></h2>
                    <p class="confirm-message"></p>
                </div>
                <div class="confirm-actions">
                    <button type="button" class="btn btn-ghost confirm-cancel"></button>
                    <button type="button" class="btn confirm-ok"></button>
                </div>
            </div>`;

        overlay.querySelector('.confirm-title').textContent = title;
        overlay.querySelector('.confirm-message').textContent = message;
        const okBtn = overlay.querySelector('.confirm-ok');
        const cancelBtn = overlay.querySelector('.confirm-cancel');
        okBtn.textContent = confirmText;
        cancelBtn.textContent = cancelText;
        okBtn.classList.add(danger ? 'btn-danger' : 'btn-primary');

        document.body.appendChild(overlay);
        const prevFocus = document.activeElement;
        (focus === 'cancel' ? cancelBtn : okBtn).focus();

        const cleanup = result => {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            if (prevFocus?.focus) prevFocus.focus();
            resolve(result);
        };
        const onKey = e => {
            if (e.key === 'Escape') { e.preventDefault(); cleanup(false); }
            else if (e.key === 'Tab') { trapTab(e, overlay); }
        };

        okBtn.addEventListener('click', () => cleanup(true));
        cancelBtn.addEventListener('click', () => cleanup(false));
        // onBackdropDismiss lives in app.js, which every page loading this file also loads.
        onBackdropDismiss(overlay, () => cleanup(false));
        document.addEventListener('keydown', onKey, true);
    });
}
window.confirmDialog = confirmDialog;


// ── Prompt dialog ─────────────────────────────────────────────────────────────
// Promise-based replacement for native prompt(), same shell and same focus rules
// as confirmDialog above. Usage:
//   const reason = await promptDialog('Why is this site being suspended?');
//   if (!reason) return;
// Resolves the trimmed string on confirm, or null on cancel/Escape/backdrop. An
// empty box cannot be confirmed -- every caller so far wants a reason recorded,
// and "" would satisfy a truthiness check while recording nothing.

function promptDialog(message, opts = {}) {
    const {
        title = 'Please confirm',
        confirmText = 'Confirm',
        cancelText = 'Cancel',
        danger = false,
        placeholder = '',
        maxLength = 255,
    } = opts;

    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay confirm-overlay';
        overlay.style.display = 'flex';
        overlay.innerHTML =
            `<div class="modal modal--confirm" role="dialog" aria-modal="true" aria-labelledby="prompt-title">
                <div class="confirm-body">
                    <h2 id="prompt-title" class="confirm-title"></h2>
                    <p class="confirm-message"></p>
                    <input type="text" class="confirm-input">
                </div>
                <div class="confirm-actions">
                    <button type="button" class="btn btn-ghost confirm-cancel"></button>
                    <button type="button" class="btn confirm-ok"></button>
                </div>
            </div>`;

        overlay.querySelector('.confirm-title').textContent = title;
        overlay.querySelector('.confirm-message').textContent = message;
        const input = overlay.querySelector('.confirm-input');
        input.placeholder = placeholder;
        input.maxLength = maxLength;
        const okBtn = overlay.querySelector('.confirm-ok');
        const cancelBtn = overlay.querySelector('.confirm-cancel');
        okBtn.textContent = confirmText;
        cancelBtn.textContent = cancelText;
        okBtn.classList.add(danger ? 'btn-danger' : 'btn-primary');
        okBtn.disabled = true;

        document.body.appendChild(overlay);
        const prevFocus = document.activeElement;
        // Focus the box, not the button: there is nothing to confirm until something is typed.
        input.focus();

        const cleanup = result => {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            if (prevFocus?.focus) prevFocus.focus();
            resolve(result);
        };
        const submit = () => {
            const value = input.value.trim();
            if (value !== '') cleanup(value);
        };
        const onKey = e => {
            if (e.key === 'Escape') { e.preventDefault(); cleanup(null); }
            else if (e.key === 'Enter' && e.target === input) { e.preventDefault(); submit(); }
            else if (e.key === 'Tab') { trapTab(e, overlay); }
        };

        input.addEventListener('input', () => { okBtn.disabled = input.value.trim() === ''; });
        okBtn.addEventListener('click', submit);
        cancelBtn.addEventListener('click', () => cleanup(null));
        onBackdropDismiss(overlay, () => cleanup(null));
        document.addEventListener('keydown', onKey, true);
    });
}
window.promptDialog = promptDialog;

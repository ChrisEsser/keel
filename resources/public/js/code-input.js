// One box per character for short numeric codes -- 2FA codes, login PINs, the TOTP/SMS
// confirmation fields in the settings modal.
//
// This enhances an existing input rather than replacing it: the original element keeps its id,
// name and value and merely becomes type=hidden, so the server contract is unchanged and the
// modal's `getElementById('sec-totp-code').value` reads keep working. With JS off (or if this
// file fails to load) the plain input renders and the form still submits, which is also why the
// screens keep their submit button.
//
// Hiding it via type=hidden rather than CSS matters: a clipped-but-present input still takes
// part in constraint validation, and `pattern="[0-9]*"` on a control the user can't focus makes
// the browser refuse to submit at all.
//
// Opt in with data-code-input on the input. Length comes from maxlength and masking from
// type=password, both of which the call sites already declare. data-autosubmit additionally
// submits the owning form once the last box is filled.
window.CodeInput = (function () {

    const instances = new WeakMap();

    // Masking via CSS keeps the segments type=text, so a row of four boxes never looks like a
    // password field to Chrome or 1Password. Firefox only learned -webkit-text-security in 118,
    // so fall back to real password inputs rather than showing the digits in the clear.
    const CSS_MASK = typeof CSS !== 'undefined' && CSS.supports && CSS.supports('-webkit-text-security', 'disc');

    function enhance(input) {
        if (instances.has(input)) return;

        const length = parseInt(input.getAttribute('maxlength') || input.dataset.length || '6', 10);
        if (!(length > 1)) return;

        const mask = input.type === 'password' || input.hasAttribute('data-mask');
        const autofocus = input.hasAttribute('autofocus');
        const form = input.form;
        const autosubmit = input.hasAttribute('data-autosubmit') && form !== null;

        const wrap = document.createElement('div');
        wrap.className = 'code-input' + (mask && CSS_MASK ? ' code-input--mask' : '');
        wrap.style.setProperty('--code-len', String(length));
        wrap.setAttribute('role', 'group');
        labelGroup(input, wrap);

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.type = 'hidden';

        const boxes = [];
        for (let i = 0; i < length; i++) {
            const box = document.createElement('input');
            // No name (six stray POST fields otherwise) and no maxlength -- maxlength truncates
            // iOS one-time-code autofill to a single digit and blocks typing over a filled box.
            box.type = mask && !CSS_MASK ? 'password' : 'text';
            box.inputMode = 'numeric';
            box.autocomplete = mask ? 'off' : 'one-time-code';
            box.spellcheck = false;
            box.setAttribute('autocapitalize', 'off');
            box.setAttribute('autocorrect', 'off');
            box.setAttribute('aria-label', `Digit ${i + 1} of ${length}`);
            wrap.appendChild(box);
            boxes.push(box);
        }

        let submitted = false;

        const inst = { input, boxes, length, wrap, sync };
        instances.set(input, inst);

        function sync() {
            const v = digits(input.value).slice(0, length);
            boxes.forEach((b, i) => { b.value = v[i] || ''; });
        }

        function collect() {
            input.value = boxes.map(b => b.value).join('');
        }

        function clearError() {
            boxes.forEach(b => b.classList.remove('has-error'));
        }

        // Typing, pasting and autofill all land here: whatever arrives is reduced to digits and
        // spread across the boxes from `start`, so a six-digit autofill dropped into box 1
        // behaves exactly like a paste.
        function distribute(text, start) {
            const v = digits(text);
            if (!v) return;
            let i = start;
            for (const ch of v) {
                if (i >= length) break;
                boxes[i].value = ch;
                i++;
            }
            collect();
            focusBox(Math.min(i, length - 1));
            maybeSubmit();
        }

        function focusBox(i) {
            const b = boxes[i];
            if (b) { b.focus(); b.select(); }
        }

        function complete() {
            return boxes.every(b => b.value !== '');
        }

        function maybeSubmit() {
            if (!autosubmit || submitted || !complete()) return;
            submitted = true;
            const btn = form.querySelector('button[type=submit], input[type=submit]');
            // A short beat so the last digit is visibly on screen before the page navigates --
            // the 2FA endpoint only allows five attempts, so a code the user never saw is
            // expensive to get wrong.
            setTimeout(() => {
                wrap.setAttribute('aria-busy', 'true');
                if (btn) btn.disabled = true;
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }, 120);
        }

        boxes.forEach((box, i) => {
            box.addEventListener('focus', () => box.select());

            box.addEventListener('input', () => {
                clearError();
                const raw = box.value;
                const v = digits(raw);
                if (v.length > 1) {
                    box.value = '';
                    distribute(v, i);
                    return;
                }
                box.value = v;
                collect();
                if (v) {
                    if (i < length - 1) focusBox(i + 1);
                    maybeSubmit();
                }
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && box.value === '') {
                    e.preventDefault();
                    const prev = boxes[i - 1];
                    if (prev) { prev.value = ''; collect(); prev.focus(); }
                } else if (e.key === 'Delete') {
                    e.preventDefault();
                    box.value = '';
                    collect();
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    focusBox(i - 1);
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    focusBox(i + 1);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    focusBox(0);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    focusBox(length - 1);
                }
            });
        });

        wrap.addEventListener('paste', (e) => {
            e.preventDefault();
            clearError();
            const text = (e.clipboardData || window.clipboardData).getData('text');
            const v = digits(text);
            if (!v) return;
            // A full-length paste always means "this is the whole code", wherever the caret was.
            distribute(v, v.length === length ? 0 : boxes.indexOf(document.activeElement));
        });

        sync();
        if (autofocus) focusBox(0);

        if (autosubmit) {
            // Coming back via the back button restores the disabled button from bfcache, which
            // would otherwise leave the form dead.
            window.addEventListener('pageshow', () => {
                submitted = false;
                wrap.removeAttribute('aria-busy');
                const btn = form.querySelector('button[type=submit], input[type=submit]');
                if (btn) btn.disabled = false;
            });
        }
    }

    // The original label now points at a hidden input, so clicking it does nothing and screen
    // readers announce nothing. Re-point it at the group instead. The modal's code fields have
    // no label at all -- their placeholder is the only naming they ever had.
    function labelGroup(input, wrap) {
        let label = input.id ? document.querySelector(`label[for="${CSS.escape(input.id)}"]`) : null;
        if (!label) {
            const row = input.closest('.modal-form-row');
            label = row ? row.querySelector('.modal-form-label') : null;
        }
        if (label) {
            if (!label.id) label.id = (input.id || 'code') + '-label';
            label.removeAttribute('for');
            wrap.setAttribute('aria-labelledby', label.id);
        } else if (input.placeholder) {
            wrap.setAttribute('aria-label', input.placeholder);
        }
    }

    function digits(s) {
        return String(s ?? '').replace(/\D/g, '');
    }

    // Idempotent -- safe to call again on a root that is already enhanced.
    function scan(root) {
        (root || document).querySelectorAll('[data-code-input]').forEach(enhance);
    }

    // Repaint the boxes after code sets .value directly (assignment fires no event). A no-op on
    // anything that isn't an enhanced input, so callers can pass any field.
    function sync(el) {
        const inst = el ? instances.get(el) : null;
        if (inst) inst.sync();
    }

    document.addEventListener('DOMContentLoaded', () => scan(document));

    return { scan, sync };
})();

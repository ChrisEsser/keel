// FileUploader — a self-contained, dependency-free file-upload drop-zone.
//
// Enhances an existing <input type="file"> into a drag-and-drop / click-to-upload zone with
// client-side size/type validation, a live preview (image thumbnail, or a colored file-type icon
// + name), and an optional "already uploaded" state. It exposes exactly one global,
// `FileUploader`, and injects its own scoped stylesheet — it assumes no other script, stylesheet,
// or icon font is present (mirroring public/js/color-picker-widget.js).
//
// The widget is upload-agnostic: it manages selection + preview + validation and hands the chosen
// File to the host via events / getFile(); the host decides when/how to POST it.
//
//   const up = new FileUploader(inputEl, { maxSize: 8*1024*1024, accept: 'image/png,image/jpeg' });
//   up.on('change', ({ file }) => { /* enable save, etc. */ });
//   up.on('error',  ({ message }) => { /* already shown inline; optional extra handling */ });
//   up.setExisting({ url: existingUrl, name: 'Current image', type: 'image' }); // show what's there
//   const file = up.getFile(); // null until the user picks/drops one
//
(function (global) {
    'use strict';

    // ---- Scoped styles (injected once) -----------------------------------------------------
    // Uses the app's design tokens where present, with literal fallbacks so the widget still
    // looks right dropped into a bare page. Colored file-type icons reuse the same palette as
    // the app's .file-tile-icon--* (public/css/app.css) so previews read consistently.
    const FU_CSS = `
.fu-native{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;}
.fu-dropzone{display:block;width:100%;box-sizing:border-box;border:2px dashed var(--border,#e2ddd3);border-radius:8px;background:var(--surface,#fff);padding:0.5rem 1rem;cursor:pointer;text-align:center;transition:border-color .15s,background .15s;}
.fu-dropzone:hover{border-color:var(--accent,#c2703d);}
.fu-dropzone:focus-visible{outline:2px solid var(--accent,#c2703d);outline-offset:2px;}
.fu-dropzone.fu-drag{border-color:var(--accent,#c2703d);background:var(--surface-alt,#f5f1eb);}
.fu-empty{display:flex;flex-direction:column;align-items:center;gap:.35rem;padding:.75rem .5rem;pointer-events:none;}
.fu-empty-icon svg{width:1.9rem;height:1.9rem;color:var(--ink-faint,#a8a29e);}
.fu-label{font-size:.875rem;color:var(--ink-muted,#57534e);}
.fu-hint{font-size:.75rem;color:var(--ink-subtle,#8a827a);}
.fu-preview{display:flex;align-items:center;gap:.75rem;text-align:left;pointer-events:none;}
.fu-thumb{width:56px;height:56px;flex-shrink:0;object-fit:cover;border-radius:6px;background:var(--surface-alt,#f5f1eb);}
.fu-fileicon{width:56px;height:56px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border-radius:6px;background:var(--surface-alt,#f5f1eb);}
.fu-fileicon svg{width:1.9rem;height:1.9rem;}
.fu-meta{display:flex;flex-direction:column;min-width:0;flex:1;gap:.1rem;}
.fu-name{font-size:.85rem;color:var(--ink,#1c1917);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.fu-size{font-size:.72rem;color:var(--ink-subtle,#8a827a);}
.fu-replace{font-size:.72rem;color:var(--ink-subtle,#8a827a);flex-shrink:0;}
.fu-remove{pointer-events:auto;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border:none;background:transparent;color:var(--ink-faint,#a8a29e);border-radius:999px;cursor:pointer;}
.fu-remove:hover{color:var(--danger,#dc2626);background:var(--surface-alt,#f5f1eb);}
.fu-remove svg{width:1rem;height:1rem;}
.fu-error{margin-top:.4rem;font-size:.78rem;color:var(--danger,#dc2626);}
`;

    function ensureStyles() {
        if (document.getElementById('file-uploader-styles')) return;
        const style = document.createElement('style');
        style.id = 'file-uploader-styles';
        style.textContent = FU_CSS;
        document.head.appendChild(style);
    }

    // ---- Inlined icons (no lucide dependency) ----------------------------------------------
    const ICON_UPLOAD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>';
    const ICON_X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>';
    const ICON_FILE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';

    // extension → type key (mirrors views/files/list.php FILE_TYPE_META); type key → color
    // (mirrors .file-tile-icon--* in public/css/app.css).
    const TYPE_BY_EXT = {
        pdf: 'pdf', doc: 'doc', docx: 'doc', xls: 'sheet', xlsx: 'sheet', csv: 'sheet',
        ppt: 'slides', pptx: 'slides', zip: 'archive', txt: 'plain',
    };
    const TYPE_COLORS = {
        pdf: '#dc2626', doc: '#2563eb', sheet: '#16a34a', slides: '#ea580c',
        archive: '#9333ea', plain: '#78716c', image: '#0ea5e9',
    };
    const IMAGE_EXTS = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.svg']);

    // ---- Helpers ---------------------------------------------------------------------------

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }

    function humanSize(bytes) {
        if (bytes == null) return '';
        const u = ['B', 'KB', 'MB', 'GB'];
        let i = 0, n = bytes;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        const s = i === 0 ? String(n) : n.toFixed(n < 10 ? 1 : 0);
        return s.replace(/\.0$/, '') + ' ' + u[i];
    }

    function extOf(name) {
        const n = (name || '').toLowerCase();
        return n.includes('.') ? n.slice(n.lastIndexOf('.')) : '';
    }

    // Parses an accept string ('.csv, image/png, image/*') into { exts:Set, mimes:[] }, or null
    // when it constrains nothing (so the caller can skip type validation entirely).
    function parseAccept(accept) {
        if (!accept) return null;
        const exts = new Set(), mimes = [];
        accept.split(',').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(tok => {
            if (tok.startsWith('.')) exts.add(tok);
            else if (tok.includes('/')) mimes.push(tok);
            else exts.add('.' + tok);
        });
        return (exts.size || mimes.length) ? { exts, mimes } : null;
    }

    function matchesAccept(file, acceptList) {
        if (!acceptList) return true;
        if (acceptList.exts.has(extOf(file.name))) return true;
        const t = (file.type || '').toLowerCase();
        return acceptList.mimes.some(m => m === t || (m.endsWith('/*') && t.startsWith(m.slice(0, -1))));
    }

    function acceptIsAllImage(acceptList) {
        if (!acceptList) return false;
        for (const e of acceptList.exts) if (!IMAGE_EXTS.has(e)) return false;
        for (const m of acceptList.mimes) if (!m.startsWith('image/')) return false;
        return acceptList.exts.size > 0 || acceptList.mimes.length > 0;
    }

    // A friendly "PNG, JPG · up to 8 MB" derived from accept + maxSize.
    function defaultHint(acceptList, maxSize) {
        const parts = [];
        if (acceptList) {
            const labels = new Set();
            for (const e of acceptList.exts) labels.add(e.slice(1).toUpperCase());
            for (const m of acceptList.mimes) {
                if (m.endsWith('/*')) labels.add(m.split('/')[0] + 's');
                else { let s = m.split('/')[1] || m; if (s === 'jpeg') s = 'jpg'; labels.add(s.toUpperCase()); }
            }
            if (labels.size) parts.push([...labels].join(', '));
        }
        if (maxSize) parts.push('up to ' + humanSize(maxSize));
        return parts.join(' · ');
    }

    // ---- Widget ----------------------------------------------------------------------------

    class FileUploader {
        #input;
        #zone;
        #errEl;
        #listeners = new Map();
        #file = null;
        #existing = null;
        #acceptList;
        #maxSize;
        #imageMode;   // true | false | 'auto'
        #label;
        #hint;
        #objUrl = null;
        #destroyed = false;

        constructor(input, options = {}) {
            ensureStyles();
            this.#input = typeof input === 'string' ? document.getElementById(input) : input;
            if (!this.#input) throw new Error('FileUploader: input element not found');

            const accept = (options.accept ?? this.#input.getAttribute('accept') ?? '').trim();
            if (accept) this.#input.setAttribute('accept', accept);
            this.#acceptList = parseAccept(accept);
            this.#maxSize = options.maxSize || 0;
            this.#imageMode = options.image === undefined
                ? (acceptIsAllImage(this.#acceptList) ? true : 'auto')
                : options.image;
            this.#label = options.label || 'Drag & drop or click to upload';
            this.#hint = options.hint !== undefined ? options.hint : defaultHint(this.#acceptList, this.#maxSize);
            this.#existing = options.existing || null;

            if (options.onChange) this.on('change', options.onChange);
            if (options.onError) this.on('error', options.onError);
            if (options.onClear) this.on('clear', options.onClear);

            this.#build();
            this.#render();
        }

        // ---- Public API -----------------------------------------------------------------

        getFile() { return this.#file; }

        // Removes the pending selection, reverting to the existing-file preview (or empty).
        clear() {
            const had = !!this.#file;
            this.#file = null;
            this.#syncInput();
            this.#clearError();
            this.#render();
            if (had) this.#emit('clear', {});
        }

        // Seeds (or replaces) the "already uploaded" preview; also drops any pending selection.
        // Pass null to show nothing.
        setExisting(obj) {
            this.#existing = obj || null;
            this.#file = null;
            this.#syncInput();
            this.#clearError();
            this.#render();
        }

        on(event, handler) {
            if (!this.#listeners.has(event)) this.#listeners.set(event, new Set());
            this.#listeners.get(event).add(handler);
            return () => { const set = this.#listeners.get(event); if (set) set.delete(handler); };
        }

        destroy() {
            if (this.#destroyed) return;
            this.#destroyed = true;
            if (this.#objUrl) { URL.revokeObjectURL(this.#objUrl); this.#objUrl = null; }
            if (this.#zone && this.#zone.parentNode) this.#zone.parentNode.removeChild(this.#zone);
            if (this.#errEl && this.#errEl.parentNode) this.#errEl.parentNode.removeChild(this.#errEl);
            this.#input.classList.remove('fu-native');
            this.#input.removeAttribute('aria-hidden');
            this.#input.removeAttribute('tabindex');
            this.#listeners.clear();
        }

        // ---- Internal -------------------------------------------------------------------

        #build() {
            this.#input.classList.add('fu-native');
            this.#input.setAttribute('tabindex', '-1');
            this.#input.setAttribute('aria-hidden', 'true');

            const zone = document.createElement('div');
            zone.className = 'fu-dropzone';
            zone.setAttribute('role', 'button');
            zone.setAttribute('tabindex', '0');
            zone.setAttribute('aria-label', this.#label);
            this.#zone = zone;

            const err = document.createElement('div');
            err.className = 'fu-error';
            err.hidden = true;
            this.#errEl = err;

            this.#input.after(zone);
            zone.after(err);

            this.#input.addEventListener('change', () => {
                const f = this.#input.files && this.#input.files[0];
                if (f) this.#take(f);
            });

            zone.addEventListener('click', e => {
                if (e.target.closest('.fu-remove')) { e.preventDefault(); this.clear(); return; }
                this.#input.click();
            });
            zone.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.#input.click(); }
            });

            ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => {
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
                zone.classList.add('fu-drag');
            }));
            ['dragleave', 'dragend'].forEach(ev => zone.addEventListener(ev, e => {
                if (ev === 'dragleave' && zone.contains(e.relatedTarget)) return;
                zone.classList.remove('fu-drag');
            }));
            zone.addEventListener('drop', e => {
                e.preventDefault();
                zone.classList.remove('fu-drag');
                const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (f) this.#take(f);
            });
        }

        #take(file) {
            const err = this.#validate(file);
            this.#clearError();
            if (err) {
                this.#showError(err);
                this.#emit('error', { message: err, file });
                return;
            }
            this.#file = file;
            this.#syncInput();
            this.#render();
            this.#emit('change', { file });
        }

        #validate(file) {
            if (!matchesAccept(file, this.#acceptList)) return 'That file type isn’t supported.';
            if (this.#maxSize && file.size > this.#maxSize) {
                return 'That file is too large (max ' + humanSize(this.#maxSize) + ').';
            }
            return null;
        }

        // Mirror the current selection into the native input so form semantics still hold.
        #syncInput() {
            try {
                const dt = new DataTransfer();
                if (this.#file) dt.items.add(this.#file);
                this.#input.files = dt.files;
            } catch (e) { /* DataTransfer unsupported — getFile() is still the source of truth */ }
        }

        #isImage(type, name) {
            if (this.#imageMode === true) return true;
            if (this.#imageMode === false) return false;
            if (type && type.startsWith('image/')) return true;
            return IMAGE_EXTS.has(extOf(name));
        }

        #render() {
            if (this.#objUrl) { URL.revokeObjectURL(this.#objUrl); this.#objUrl = null; }

            if (!this.#file && !this.#existing) {
                this.#zone.innerHTML =
                    `<div class="fu-empty"><span class="fu-empty-icon">${ICON_UPLOAD}</span>`
                    + `<span class="fu-label">${esc(this.#label)}</span>`
                    + (this.#hint ? `<span class="fu-hint">${esc(this.#hint)}</span>` : '')
                    + `</div>`;
                return;
            }

            let name, sizeText, isImg, imgSrc, tag;
            if (this.#file) {
                name = this.#file.name;
                sizeText = humanSize(this.#file.size);
                isImg = this.#isImage(this.#file.type, this.#file.name);
                if (isImg) { this.#objUrl = URL.createObjectURL(this.#file); imgSrc = this.#objUrl; }
                tag = `<button type="button" class="fu-remove" aria-label="Remove">${ICON_X}</button>`;
            } else {
                name = this.#existing.name || 'Current file';
                sizeText = 'Uploaded';
                isImg = this.#isImage(this.#existing.type, this.#existing.url);
                imgSrc = isImg ? this.#existing.url : null;
                tag = `<span class="fu-replace">Replace</span>`;
            }

            const iconColor = TYPE_COLORS[TYPE_BY_EXT[extOf(name).slice(1)] || 'plain'];
            const media = imgSrc
                ? `<img class="fu-thumb" src="${esc(imgSrc)}" alt="">`
                : `<span class="fu-fileicon" style="color:${isImg ? TYPE_COLORS.image : iconColor}">${ICON_FILE}</span>`;

            this.#zone.innerHTML =
                `<div class="fu-preview">${media}`
                + `<span class="fu-meta"><span class="fu-name">${esc(name)}</span>`
                + (sizeText ? `<span class="fu-size">${esc(sizeText)}</span>` : '')
                + `</span>${tag}</div>`;
        }

        #showError(msg) {
            this.#errEl.textContent = msg;
            this.#errEl.hidden = false;
        }

        #clearError() {
            this.#errEl.textContent = '';
            this.#errEl.hidden = true;
        }

        #emit(event, payload) {
            const set = this.#listeners.get(event);
            if (set) set.forEach(fn => { try { fn(payload); } catch (e) { /* listener threw */ } });
        }
    }

    global.FileUploader = FileUploader;
})(window);

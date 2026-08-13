// ColorPickerWidget — a self-contained, dependency-free color picker.
//
// Drop this single file into any project via a plain <script> tag. It exposes exactly one
// global, `ColorPickerWidget`, and injects its own scoped stylesheet — it never assumes any
// other script, stylesheet, or icon font is present on the page.
//
//   const widget = new ColorPickerWidget({ value: '#3b82f6', showAlpha: true });
//   widget.mount(document.getElementById('host'));
//   widget.on('input', ({ hex, alpha }) => { ... live preview ... });
//   widget.on('change', ({ hex, alpha }) => { ... commit ... });
//
(function (global) {
    'use strict';

    // ---- Pure color-space math -------------------------------------------------------------
    // Canonical internal state is always {h: 0-360, s: 0-1, v: 0-1, a: 0-1}. Every other
    // representation (hex/rgb/hsl) is a derived read computed by the functions below; nothing
    // here touches the DOM or any instance state, so it's all safely reusable outside the widget
    // too (e.g. `ColorPickerWidget.hsvToRgb(...)`).

    function clamp(n, min, max) {
        return Math.min(max, Math.max(min, n));
    }

    function hsvToRgb({ h, s, v }) {
        h = ((h % 360) + 360) % 360;
        s = clamp(s, 0, 1);
        v = clamp(v, 0, 1);
        const c = v * s;
        const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
        const m = v - c;
        let r1, g1, b1;
        if (h < 60) { r1 = c; g1 = x; b1 = 0; }
        else if (h < 120) { r1 = x; g1 = c; b1 = 0; }
        else if (h < 180) { r1 = 0; g1 = c; b1 = x; }
        else if (h < 240) { r1 = 0; g1 = x; b1 = c; }
        else if (h < 300) { r1 = x; g1 = 0; b1 = c; }
        else { r1 = c; g1 = 0; b1 = x; }
        return {
            r: Math.round((r1 + m) * 255),
            g: Math.round((g1 + m) * 255),
            b: Math.round((b1 + m) * 255),
        };
    }

    function rgbToHsv({ r, g, b }) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        const d = max - min;
        let h = 0;
        if (d !== 0) {
            if (max === r) h = 60 * (((g - b) / d) % 6);
            else if (max === g) h = 60 * ((b - r) / d + 2);
            else h = 60 * ((r - g) / d + 4);
        }
        if (h < 0) h += 360;
        const s = max === 0 ? 0 : d / max;
        const v = max;
        return { h, s, v };
    }

    function rgbToHex({ r, g, b }) {
        const c = n => clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0');
        return `#${c(r)}${c(g)}${c(b)}`;
    }

    function hexToRgb(hex) {
        let h = String(hex).trim().replace(/^#/, '');
        if (h.length === 3) h = h.split('').map(c => c + c).join('');
        if (!/^[0-9a-f]{6}$/i.test(h)) return null;
        const num = parseInt(h, 16);
        return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
    }

    function hexToRgba(hex) {
        let h = String(hex).trim().replace(/^#/, '');
        if (h.length === 4) h = h.split('').map(c => c + c).join('');
        if (h.length === 8) {
            if (!/^[0-9a-f]{8}$/i.test(h)) return null;
            const num = parseInt(h, 16);
            return {
                r: (num >> 24) & 255, g: (num >> 16) & 255, b: (num >> 8) & 255,
                a: ((num & 255) / 255),
            };
        }
        const rgb = hexToRgb(h);
        return rgb ? { ...rgb, a: 1 } : null;
    }

    function rgbToHsl({ r, g, b }) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        const d = max - min;
        let h = 0;
        if (d !== 0) {
            if (max === r) h = 60 * (((g - b) / d) % 6);
            else if (max === g) h = 60 * ((b - r) / d + 2);
            else h = 60 * ((r - g) / d + 4);
        }
        if (h < 0) h += 360;
        const l = (max + min) / 2;
        const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
        return { h, s, l };
    }

    function hslToRgb({ h, s, l }) {
        h = ((h % 360) + 360) % 360;
        s = clamp(s, 0, 1);
        l = clamp(l, 0, 1);
        const c = (1 - Math.abs(2 * l - 1)) * s;
        const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
        const m = l - c / 2;
        let r1, g1, b1;
        if (h < 60) { r1 = c; g1 = x; b1 = 0; }
        else if (h < 120) { r1 = x; g1 = c; b1 = 0; }
        else if (h < 180) { r1 = 0; g1 = c; b1 = x; }
        else if (h < 240) { r1 = 0; g1 = x; b1 = c; }
        else if (h < 300) { r1 = x; g1 = 0; b1 = c; }
        else { r1 = c; g1 = 0; b1 = x; }
        return {
            r: Math.round((r1 + m) * 255),
            g: Math.round((g1 + m) * 255),
            b: Math.round((b1 + m) * 255),
        };
    }

    function hsvToHsl(hsv) {
        return rgbToHsl(hsvToRgb(hsv));
    }

    function hslToHsv(hsl) {
        return rgbToHsv(hslToRgb(hsl));
    }

    // Parses a CSS-style color string (#hex, #hex8, rgb()/rgba(), hsl()/hsla()) into canonical
    // {h,s,v,a}. Returns null for anything unrecognized -- callers decide the reject/revert policy.
    function parseColorString(str) {
        const s = String(str || '').trim();
        if (!s) return null;

        if (s[0] === '#') {
            const rgba = hexToRgba(s);
            if (!rgba) return null;
            return { ...rgbToHsv(rgba), a: rgba.a };
        }

        let m = /^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/i.exec(s);
        if (m) {
            const rgb = { r: clamp(Number(m[1]), 0, 255), g: clamp(Number(m[2]), 0, 255), b: clamp(Number(m[3]), 0, 255) };
            const a = m[4] !== undefined ? clamp(Number(m[4]), 0, 1) : 1;
            return { ...rgbToHsv(rgb), a };
        }

        m = /^hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%\s*(?:,\s*([\d.]+)\s*)?\)$/i.exec(s);
        if (m) {
            const hsl = { h: clamp(Number(m[1]), 0, 360), s: clamp(Number(m[2]) / 100, 0, 1), l: clamp(Number(m[3]) / 100, 0, 1) };
            const a = m[4] !== undefined ? clamp(Number(m[4]), 0, 1) : 1;
            return { ...hslToHsv(hsl), a };
        }

        return null;
    }

    // Converts the widget's own separate manual-entry field values (as parsed numbers, one
    // family per format) into canonical {h,s,v,a}. Distinct from parseColorString, which parses
    // a single formatted string -- these operate on the discrete field values a user is editing.
    function rgbFieldsToState({ r, g, b, a }) {
        const rgb = { r: clamp(Number(r) || 0, 0, 255), g: clamp(Number(g) || 0, 0, 255), b: clamp(Number(b) || 0, 0, 255) };
        const hsv = rgbToHsv(rgb);
        return { ...hsv, a: a !== undefined ? clamp(Number(a) || 0, 0, 100) / 100 : 1 };
    }

    function hslFieldsToState({ h, s, l, a }) {
        const hsl = { h: clamp(Number(h) || 0, 0, 360), s: clamp(Number(s) || 0, 0, 100) / 100, l: clamp(Number(l) || 0, 0, 100) / 100 };
        const hsv = hslToHsv(hsl);
        return { ...hsv, a: a !== undefined ? clamp(Number(a) || 0, 0, 100) / 100 : 1 };
    }

    function hsvFieldsToState({ h, s, v, a }) {
        return {
            h: clamp(Number(h) || 0, 0, 360),
            s: clamp(Number(s) || 0, 0, 100) / 100,
            v: clamp(Number(v) || 0, 0, 100) / 100,
            a: a !== undefined ? clamp(Number(a) || 0, 0, 100) / 100 : 1,
        };
    }

    function hexFieldToState(hex) {
        const rgb = hexToRgb(hex);
        if (!rgb) return null;
        return { ...rgbToHsv(rgb), a: 1 };
    }

    function swatchCssValue(hex, alphaFraction) {
        if (alphaFraction >= 1) return hex;
        const rgb = hexToRgb(hex);
        return rgb ? `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alphaFraction})` : hex;
    }

    // ---- Self-contained stylesheet, injected once no matter how many instances exist --------

    const CPW_CSS = `
.cpw-trigger {
  all: unset;
  box-sizing: border-box;
  display: inline-block;
  width: 32px;
  height: 32px;
  padding: 2px;
  border: 1px solid rgba(0,0,0,0.3);
  border-radius: 6px;
  cursor: pointer;
  background: #fff;
}
.cpw-trigger:focus-visible { outline: 2px solid #3b82f6; outline-offset: 1px; }
.cpw-trigger-swatch, .cpw-swatch, .cpw-checkerboard-bg {
  background-image:
    linear-gradient(45deg, #999 25%, transparent 25%),
    linear-gradient(-45deg, #999 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #999 75%),
    linear-gradient(-45deg, transparent 75%, #999 75%);
  background-size: 8px 8px;
  background-position: 0 0, 0 4px, 4px -4px, -4px 0px;
}
.cpw-trigger-swatch {
  display: block;
  width: 100%;
  height: 100%;
  position: relative;
  border-radius: 4px;
  overflow: hidden;
}
.cpw-swatch-fill { position: absolute; inset: 0; }

.cpw-root {
  --cpw-bg: #ffffff;
  --cpw-fg: #1f2937;
  --cpw-border: rgba(0,0,0,0.12);
  --cpw-panel: #f3f4f6;
  --cpw-accent: #3b82f6;
  box-sizing: border-box;
  position: fixed;
  z-index: 999999;
  width: 232px;
  padding: 12px;
  background: var(--cpw-bg);
  color: var(--cpw-fg);
  border: 1px solid var(--cpw-border);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.28);
  font: 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.cpw-root * { box-sizing: border-box; }
.cpw-root.cpw-theme-dark {
  --cpw-bg: #1c1917;
  --cpw-fg: #e7e5e4;
  --cpw-border: rgba(255,255,255,0.12);
  --cpw-panel: #292524;
  --cpw-accent: #3b82f6;
}

.cpw-sv-area {
  position: relative;
  width: 100%;
  height: 140px;
  border-radius: 6px;
  overflow: hidden;
  cursor: crosshair;
  margin-bottom: 10px;
  outline: none;
  touch-action: none;
}
.cpw-sv-canvas { display: block; width: 100%; height: 100%; }
.cpw-sv-thumb {
  position: absolute;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px rgba(0,0,0,0.4);
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.cpw-slider {
  position: relative;
  height: 12px;
  border-radius: 6px;
  margin-bottom: 10px;
  cursor: pointer;
  outline: none;
  touch-action: none;
}
.cpw-hue-slider {
  background: linear-gradient(to right, #f00 0%, #ff0 17%, #0f0 33%, #0ff 50%, #00f 67%, #f0f 83%, #f00 100%);
}
.cpw-alpha-slider { overflow: hidden; }
.cpw-checkerboard-bg { position: absolute; inset: 0; }
.cpw-alpha-track { position: absolute; inset: 0; }
.cpw-slider-thumb {
  position: absolute;
  top: 50%;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px rgba(0,0,0,0.4);
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.cpw-tool-row { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.cpw-eyedropper-btn {
  all: unset;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: 1px solid var(--cpw-border);
  cursor: pointer;
  color: var(--cpw-fg);
  flex-shrink: 0;
}
.cpw-eyedropper-btn:hover { border-color: var(--cpw-accent); }
.cpw-eyedropper-btn svg { width: 14px; height: 14px; }

.cpw-format-row { display: flex; flex: 1; gap: 4px; }
.cpw-format-btn {
  all: unset;
  box-sizing: border-box;
  flex: 1;
  text-align: center;
  font-size: 10px;
  padding: 4px 0;
  border-radius: 4px;
  border: 1px solid var(--cpw-border);
  cursor: pointer;
  color: var(--cpw-fg);
}
.cpw-format-btn.active { background: var(--cpw-accent); border-color: var(--cpw-accent); color: #fff; }

.cpw-fields-row { display: flex; gap: 6px; margin-bottom: 10px; }
.cpw-field-wrap { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.cpw-field-label { font-size: 9px; color: var(--cpw-fg); opacity: 0.7; text-align: center; }
.cpw-field {
  width: 100%;
  padding: 4px 2px;
  text-align: center;
  font-size: 11px;
  font-family: monospace;
  background: var(--cpw-panel);
  color: var(--cpw-fg);
  border: 1px solid var(--cpw-border);
  border-radius: 4px;
}

.cpw-swatch-section { margin-bottom: 8px; }
.cpw-swatch-row-label { font-size: 9px; color: var(--cpw-fg); opacity: 0.6; margin-bottom: 4px; }
.cpw-swatch-row { display: flex; flex-wrap: wrap; gap: 5px; }
.cpw-swatch {
  all: unset;
  box-sizing: border-box;
  position: relative;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  border: 1px solid var(--cpw-border);
  cursor: pointer;
  overflow: hidden;
}
.cpw-swatch:hover { border-color: var(--cpw-accent); }

.cpw-actions-row { display: flex; justify-content: flex-end; margin-top: 4px; }
.cpw-cancel-btn {
  all: unset;
  box-sizing: border-box;
  padding: 5px 14px;
  font-size: 11px;
  border-radius: 6px;
  border: 1px solid var(--cpw-border);
  cursor: pointer;
  color: var(--cpw-fg);
}
.cpw-cancel-btn:hover { border-color: var(--cpw-accent); }
`;

    function ensureStyles() {
        if (document.getElementById('cpw-styles')) return;
        const style = document.createElement('style');
        style.id = 'cpw-styles';
        style.textContent = CPW_CSS;
        document.head.appendChild(style);
    }

    const EYEDROPPER_ICON_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="m2 22 1-4 9.6-9.6"/>' +
        '<path d="m13.4 8.4 4.243-4.243a1 1 0 0 1 1.415 0l1.586 1.586a1 1 0 0 1 0 1.414L16.4 11.4"/>' +
        '<path d="M3 21h4"/></svg>';

    // ---- Widget ------------------------------------------------------------------------------

    class ColorPickerWidget {
        static clamp = clamp;
        static hsvToRgb = hsvToRgb;
        static rgbToHsv = rgbToHsv;
        static rgbToHex = rgbToHex;
        static hexToRgb = hexToRgb;
        static hexToRgba = hexToRgba;
        static rgbToHsl = rgbToHsl;
        static hslToRgb = hslToRgb;
        static hsvToHsl = hsvToHsl;
        static hslToHsv = hslToHsv;
        static parseColorString = parseColorString;
        static rgbFieldsToState = rgbFieldsToState;
        static hslFieldsToState = hslFieldsToState;
        static hsvFieldsToState = hsvFieldsToState;
        static hexFieldToState = hexFieldToState;

        #container;
        #format;
        #presets;
        #recent;
        #maxRecent;
        #autoPushRecent;
        #showAlpha;
        #theme;
        #eyedropperEnabled;
        #listeners;
        #state;
        #dom = {};
        #isOpen = false;
        #destroyed = false;
        #svSize = null;
        #svCtx = null;
        #onDocPointerDown = null;
        #onDocKeyDown = null;
        #cancelButton;
        #stateOnOpen = null;

        constructor(options = {}) {
            ensureStyles();
            this.#container = options.container || document.body;
            this.#format = options.format || 'hex';
            this.#presets = (options.presets || []).slice();
            this.#recent = (options.recent || []).slice();
            this.#maxRecent = options.maxRecent || 8;
            this.#autoPushRecent = options.autoPushRecentOnCommit !== false;
            this.#showAlpha = options.showAlpha !== false;
            this.#theme = options.theme || 'auto';
            this.#eyedropperEnabled = options.eyedropper !== false && ('EyeDropper' in global);
            this.#cancelButton = options.cancelButton !== false;
            this.#listeners = new Map();

            if (options.onInput) this.on('input', options.onInput);
            if (options.onChange) this.on('change', options.onChange);
            if (options.onOpen) this.on('open', options.onOpen);
            if (options.onClose) this.on('close', options.onClose);
            if (options.onCancel) this.on('cancel', options.onCancel);
            if (options.onRecentChange) this.on('recentchange', options.onRecentChange);

            this.#state = parseColorString(options.value) || { h: 0, s: 0, v: 0, a: 1 };
        }

        // ---- Public API -----------------------------------------------------------------

        mount(containerEl) {
            this.#buildTrigger();
            containerEl.appendChild(this.#dom.trigger);
            this.#renderTrigger();
            return this.#dom.trigger;
        }

        open() {
            if (this.#destroyed || this.#isOpen) return;
            if (!this.#dom.popover) this.#buildPopover();
            // Snapshot the color as of THIS open() call (not construction time) -- if the host
            // called setValue() between a previous close() and now, or this is the first open,
            // either way "original" means "what it was showing right before the user started
            // touching it this time," which the in-popover Cancel button reverts to.
            this.#stateOnOpen = { ...this.#state };
            this.#isOpen = true;
            this.#dom.popover.style.display = 'block';
            this.#renderFromState();
            this.#position();
            this.#dom.trigger.setAttribute('aria-expanded', 'true');

            this.#onDocPointerDown = (e) => {
                if (this.#dom.popover.contains(e.target) || this.#dom.trigger.contains(e.target)) return;
                this.close();
            };
            this.#onDocKeyDown = (e) => { if (e.key === 'Escape') this.close(); };
            document.addEventListener('pointerdown', this.#onDocPointerDown, true);
            document.addEventListener('keydown', this.#onDocKeyDown);

            this.#emit('open', undefined);
        }

        close() {
            if (!this.#isOpen) return;
            this.#isOpen = false;
            if (this.#dom.popover) this.#dom.popover.style.display = 'none';
            if (this.#dom.trigger) this.#dom.trigger.setAttribute('aria-expanded', 'false');
            if (this.#onDocPointerDown) {
                document.removeEventListener('pointerdown', this.#onDocPointerDown, true);
                this.#onDocPointerDown = null;
            }
            if (this.#onDocKeyDown) {
                document.removeEventListener('keydown', this.#onDocKeyDown);
                this.#onDocKeyDown = null;
            }
            this.#emit('close', undefined);
        }

        toggle() { this.#isOpen ? this.close() : this.open(); }
        isOpen() { return this.#isOpen; }

        getValue(format = 'hex') {
            const rgb = hsvToRgb(this.#state);
            const hsl = hsvToHsl(this.#state);
            const a = this.#state.a;
            switch (format) {
                case 'hex': return rgbToHex(rgb);
                case 'hex8': {
                    const c = n => clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0');
                    return rgbToHex(rgb) + c(a * 255);
                }
                case 'rgb': return `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`;
                case 'rgba': return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${a})`;
                case 'hsl': return `hsl(${Math.round(hsl.h)}, ${Math.round(hsl.s * 100)}%, ${Math.round(hsl.l * 100)}%)`;
                case 'hsla': return `hsla(${Math.round(hsl.h)}, ${Math.round(hsl.s * 100)}%, ${Math.round(hsl.l * 100)}%, ${a})`;
                case 'hsv': return `hsv(${Math.round(this.#state.h)}, ${Math.round(this.#state.s * 100)}%, ${Math.round(this.#state.v * 100)}%)`;
                case 'hsva': return `hsva(${Math.round(this.#state.h)}, ${Math.round(this.#state.s * 100)}%, ${Math.round(this.#state.v * 100)}%, ${a})`;
                default: return rgbToHex(rgb);
            }
        }

        getAlpha() { return this.#state.a; }

        setValue(value, { silent = false } = {}) {
            const parsed = parseColorString(value);
            if (!parsed) return false;
            this.#state = parsed;
            this.#renderFromState();
            if (!silent) {
                this.#emit('input', this.#payload('set'));
                this.#emit('change', this.#payload('set'));
            }
            return true;
        }

        setAlpha(a) {
            this.#state = { ...this.#state, a: clamp(Number(a), 0, 1) };
            this.#renderFromState();
        }

        addRecent(entry) {
            this.#recent = [entry, ...this.#recent.filter(e => e.value !== entry.value)].slice(0, this.#maxRecent);
            if (this.#dom.recentRow) {
                this.#renderSwatchList(this.#dom.recentRow, this.#recent);
                this.#toggleSwatchSectionVisibility();
            }
            this.#emit('recentchange', this.#recent.slice());
        }

        getRecent() { return this.#recent.slice(); }

        on(event, handler) {
            if (!this.#listeners.has(event)) this.#listeners.set(event, new Set());
            this.#listeners.get(event).add(handler);
            return () => { const set = this.#listeners.get(event); if (set) set.delete(handler); };
        }

        destroy() {
            if (this.#destroyed) return;
            this.#destroyed = true;
            this.close();
            if (this.#dom.trigger && this.#dom.trigger.parentNode) this.#dom.trigger.parentNode.removeChild(this.#dom.trigger);
            if (this.#dom.popover && this.#dom.popover.parentNode) this.#dom.popover.parentNode.removeChild(this.#dom.popover);
            this.#listeners.clear();
        }

        // ---- Internal: state / events -----------------------------------------------------

        #payload(source) {
            const rgb = hsvToRgb(this.#state);
            const hsl = hsvToHsl(this.#state);
            return {
                hex: rgbToHex(rgb),
                rgb,
                hsl: { h: Math.round(hsl.h), s: Math.round(hsl.s * 100), l: Math.round(hsl.l * 100) },
                hsv: { h: Math.round(this.#state.h), s: Math.round(this.#state.s * 100), v: Math.round(this.#state.v * 100) },
                alpha: this.#state.a,
                source,
            };
        }

        #emit(event, payload) {
            const set = this.#listeners.get(event);
            if (!set) return;
            for (const fn of Array.from(set)) {
                try { fn(payload); } catch (err) { console.error('ColorPickerWidget listener error', err); }
            }
        }

        #applyState(newState, { source = 'unknown', commit = false, skipFieldsRerender = false } = {}) {
            this.#state = newState;
            this.#renderFromState({ skipFieldsRerender });
            this.#emit('input', this.#payload(source));
            if (commit) this.#commit(source);
        }

        #commit(source) {
            this.#emit('change', this.#payload(source));
            if (this.#autoPushRecent) this.#pushRecent();
        }

        // Reverts to whatever the color was when this popover was most recently opened,
        // discarding any SV/hue/alpha/swatch/text/eyedropper edits made since -- not the page's
        // state from before the tool was ever invoked (that's the host's own outer Cancel, e.g.
        // this app's tool-card Cancel button, which is a separate, broader concern).
        #cancelToOriginal() {
            if (this.#stateOnOpen) {
                this.#state = { ...this.#stateOnOpen };
                this.#renderFromState();
                this.#emit('input', this.#payload('cancel'));
                this.#emit('change', this.#payload('cancel'));
                this.#emit('cancel', this.#payload('cancel'));
            }
            this.close();
        }

        #pushRecent() {
            const rgb = hsvToRgb(this.#state);
            const hex = rgbToHex(rgb);
            const value = this.#state.a < 1 ? `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${this.#state.a})` : hex;
            const entry = { value, hex, alpha: Math.round(this.#state.a * 100) };
            this.#recent = [entry, ...this.#recent.filter(e => e.value !== value)].slice(0, this.#maxRecent);
            if (this.#dom.recentRow) {
                this.#renderSwatchList(this.#dom.recentRow, this.#recent);
                this.#toggleSwatchSectionVisibility();
            }
            this.#emit('recentchange', this.#recent.slice());
        }

        #resolveTheme() {
            if (this.#theme === 'auto') {
                return (global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            return this.#theme === 'light' ? 'light' : 'dark';
        }

        // ---- Internal: DOM building --------------------------------------------------------

        #buildTrigger() {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cpw-trigger';
            btn.setAttribute('aria-haspopup', 'dialog');
            btn.setAttribute('aria-expanded', 'false');
            const swatch = document.createElement('span');
            swatch.className = 'cpw-trigger-swatch';
            const fill = document.createElement('span');
            fill.className = 'cpw-swatch-fill';
            swatch.appendChild(fill);
            btn.appendChild(swatch);
            btn.addEventListener('click', () => this.toggle());
            this.#dom.trigger = btn;
            this.#dom.triggerFill = fill;
        }

        #buildSwatchSection(labelText) {
            const wrap = document.createElement('div');
            wrap.className = 'cpw-swatch-section';
            const label = document.createElement('div');
            label.className = 'cpw-swatch-row-label';
            label.textContent = labelText;
            const row = document.createElement('div');
            row.className = 'cpw-swatch-row';
            wrap.appendChild(label);
            wrap.appendChild(row);
            return { wrap, row };
        }

        #buildPopover() {
            const root = document.createElement('div');
            root.className = `cpw-root cpw-theme-${this.#resolveTheme()}`;
            root.style.display = 'none';
            root.setAttribute('role', 'dialog');
            root.setAttribute('aria-modal', 'false');

            const svArea = document.createElement('div');
            svArea.className = 'cpw-sv-area';
            svArea.tabIndex = 0;
            const dpr = global.devicePixelRatio || 1;
            const svW = 200, svH = 140;
            const canvas = document.createElement('canvas');
            canvas.className = 'cpw-sv-canvas';
            canvas.width = svW * dpr;
            canvas.height = svH * dpr;
            const ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);
            this.#svSize = { w: svW, h: svH };
            this.#svCtx = ctx;
            const svThumb = document.createElement('div');
            svThumb.className = 'cpw-sv-thumb';
            svArea.appendChild(canvas);
            svArea.appendChild(svThumb);

            const hueSlider = document.createElement('div');
            hueSlider.className = 'cpw-slider cpw-hue-slider';
            hueSlider.tabIndex = 0;
            const hueThumb = document.createElement('div');
            hueThumb.className = 'cpw-slider-thumb';
            hueSlider.appendChild(hueThumb);

            const alphaSlider = document.createElement('div');
            alphaSlider.className = 'cpw-slider cpw-alpha-slider';
            alphaSlider.tabIndex = 0;
            const alphaCheckerboard = document.createElement('div');
            alphaCheckerboard.className = 'cpw-checkerboard-bg';
            const alphaTrack = document.createElement('div');
            alphaTrack.className = 'cpw-alpha-track';
            const alphaThumb = document.createElement('div');
            alphaThumb.className = 'cpw-slider-thumb';
            alphaSlider.appendChild(alphaCheckerboard);
            alphaSlider.appendChild(alphaTrack);
            alphaSlider.appendChild(alphaThumb);
            if (!this.#showAlpha) alphaSlider.style.display = 'none';

            const toolRow = document.createElement('div');
            toolRow.className = 'cpw-tool-row';
            let eyedropperBtn = null;
            if (this.#eyedropperEnabled) {
                eyedropperBtn = document.createElement('button');
                eyedropperBtn.type = 'button';
                eyedropperBtn.className = 'cpw-eyedropper-btn';
                eyedropperBtn.title = 'Pick color from screen';
                eyedropperBtn.innerHTML = EYEDROPPER_ICON_SVG;
                eyedropperBtn.addEventListener('click', () => this.#onEyedropperClick());
                toolRow.appendChild(eyedropperBtn);
            }

            const formatRow = document.createElement('div');
            formatRow.className = 'cpw-format-row';
            const formatBtns = {};
            for (const fmt of ['hex', 'rgb', 'rgba', 'hsl', 'hsv']) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cpw-format-btn';
                btn.textContent = fmt.toUpperCase();
                btn.dataset.format = fmt;
                btn.addEventListener('click', () => this.#setFormat(fmt));
                formatRow.appendChild(btn);
                formatBtns[fmt] = btn;
            }
            toolRow.appendChild(formatRow);

            const fieldsRow = document.createElement('div');
            fieldsRow.className = 'cpw-fields-row';

            const presetsSection = this.#buildSwatchSection('Presets');
            const recentSection = this.#buildSwatchSection('Recent');

            root.appendChild(svArea);
            root.appendChild(hueSlider);
            root.appendChild(alphaSlider);
            root.appendChild(toolRow);
            root.appendChild(fieldsRow);
            root.appendChild(presetsSection.wrap);
            root.appendChild(recentSection.wrap);

            if (this.#cancelButton) {
                const actionsRow = document.createElement('div');
                actionsRow.className = 'cpw-actions-row';
                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'cpw-cancel-btn';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.addEventListener('click', () => this.#cancelToOriginal());
                actionsRow.appendChild(cancelBtn);
                root.appendChild(actionsRow);
            }

            this.#container.appendChild(root);

            this.#dom.popover = root;
            this.#dom.svArea = svArea;
            this.#dom.svThumb = svThumb;
            this.#dom.hueSlider = hueSlider;
            this.#dom.hueThumb = hueThumb;
            this.#dom.alphaSlider = alphaSlider;
            this.#dom.alphaTrack = alphaTrack;
            this.#dom.alphaThumb = alphaThumb;
            this.#dom.formatBtns = formatBtns;
            this.#dom.fieldsRow = fieldsRow;
            this.#dom.presetsRow = presetsSection.row;
            this.#dom.presetsWrap = presetsSection.wrap;
            this.#dom.recentRow = recentSection.row;
            this.#dom.recentWrap = recentSection.wrap;

            this.#attachSvDrag();
            this.#attachHueDrag();
            this.#attachAlphaDrag();

            this.#renderSwatchList(this.#dom.presetsRow, this.#presets);
            this.#renderSwatchList(this.#dom.recentRow, this.#recent);
            this.#toggleSwatchSectionVisibility();
            this.#updateFormatButtons();
            this.#renderFields();
        }

        // ---- Internal: rendering -----------------------------------------------------------

        #renderFromState({ skipFieldsRerender = false } = {}) {
            if (this.#svCtx) this.#paintSvCanvas();
            if (this.#dom.svThumb) this.#positionSvThumb();
            if (this.#dom.hueThumb) this.#positionHueThumb();
            if (this.#dom.alphaThumb) { this.#positionAlphaThumb(); this.#updateAlphaTrack(); }
            this.#renderTrigger();
            if (!skipFieldsRerender && this.#dom.fieldsRow) this.#renderFields();
        }

        #renderTrigger() {
            if (!this.#dom.triggerFill) return;
            const hex = rgbToHex(hsvToRgb(this.#state));
            this.#dom.triggerFill.style.background = swatchCssValue(hex, this.#showAlpha ? this.#state.a : 1);
        }

        #paintSvCanvas() {
            const ctx = this.#svCtx;
            const { w, h } = this.#svSize;
            const rgb = hsvToRgb({ h: this.#state.h, s: 1, v: 1 });
            ctx.fillStyle = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`;
            ctx.fillRect(0, 0, w, h);
            let grad = ctx.createLinearGradient(0, 0, w, 0);
            grad.addColorStop(0, 'rgba(255,255,255,1)');
            grad.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, w, h);
            grad = ctx.createLinearGradient(0, 0, 0, h);
            grad.addColorStop(0, 'rgba(0,0,0,0)');
            grad.addColorStop(1, 'rgba(0,0,0,1)');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, w, h);
        }

        #positionSvThumb() {
            const { s, v } = this.#state;
            const { w, h } = this.#svSize;
            this.#dom.svThumb.style.left = (s * w) + 'px';
            this.#dom.svThumb.style.top = ((1 - v) * h) + 'px';
        }

        #positionHueThumb() {
            const w = this.#dom.hueSlider.clientWidth;
            this.#dom.hueThumb.style.left = ((this.#state.h / 360) * w) + 'px';
        }

        #positionAlphaThumb() {
            const w = this.#dom.alphaSlider.clientWidth;
            this.#dom.alphaThumb.style.left = (this.#state.a * w) + 'px';
        }

        #updateAlphaTrack() {
            const rgb = hsvToRgb(this.#state);
            this.#dom.alphaTrack.style.background =
                `linear-gradient(to right, rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0), rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 1))`;
        }

        #updateFormatButtons() {
            for (const [fmt, btn] of Object.entries(this.#dom.formatBtns)) {
                btn.classList.toggle('active', fmt === this.#format);
            }
        }

        #setFormat(fmt) {
            this.#format = fmt;
            this.#updateFormatButtons();
            this.#renderFields();
        }

        #renderSwatchList(rowEl, list) {
            rowEl.innerHTML = '';
            for (const entry of list) {
                const hex = entry.hex || entry.value;
                const alphaFraction = entry.alpha !== undefined ? entry.alpha / 100 : 1;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cpw-swatch';
                btn.title = entry.value || hex;
                const fill = document.createElement('span');
                fill.className = 'cpw-swatch-fill';
                fill.style.background = swatchCssValue(hex, alphaFraction);
                btn.appendChild(fill);
                btn.addEventListener('click', () => {
                    const parsed = parseColorString(hex);
                    if (!parsed) return;
                    // Only override alpha when the swatch itself carries a non-opaque alpha --
                    // an opaque preset/design-color pick must never clobber a user-set alpha.
                    const newState = { ...parsed, a: alphaFraction < 1 ? alphaFraction : this.#state.a };
                    this.#applyState(newState, { source: 'swatch', commit: true });
                });
                rowEl.appendChild(btn);
            }
        }

        #toggleSwatchSectionVisibility() {
            if (this.#dom.presetsWrap) this.#dom.presetsWrap.style.display = this.#presets.length ? '' : 'none';
            if (this.#dom.recentWrap) this.#dom.recentWrap.style.display = this.#recent.length ? '' : 'none';
        }

        #renderFields() {
            const row = this.#dom.fieldsRow;
            if (!row) return;
            row.innerHTML = '';
            const rgb = hsvToRgb(this.#state);
            const hsl = hsvToHsl(this.#state);
            const alphaPct = Math.round(this.#state.a * 100);

            const addField = (labelText, key, value) => {
                const wrap = document.createElement('label');
                wrap.className = 'cpw-field-wrap';
                const labelEl = document.createElement('span');
                labelEl.className = 'cpw-field-label';
                labelEl.textContent = labelText;
                const input = document.createElement('input');
                input.type = 'text';
                input.inputMode = 'numeric';
                input.className = 'cpw-field';
                input.dataset.field = key;
                input.value = value;
                wrap.appendChild(labelEl);
                wrap.appendChild(input);
                row.appendChild(wrap);
            };

            if (this.#format === 'hex') {
                addField('HEX', 'hex', rgbToHex(rgb));
            } else if (this.#format === 'rgb') {
                addField('R', 'r', rgb.r);
                addField('G', 'g', rgb.g);
                addField('B', 'b', rgb.b);
            } else if (this.#format === 'rgba') {
                addField('R', 'r', rgb.r);
                addField('G', 'g', rgb.g);
                addField('B', 'b', rgb.b);
                addField('A', 'a', alphaPct);
            } else if (this.#format === 'hsl') {
                addField('H', 'h', Math.round(hsl.h));
                addField('S', 's', Math.round(hsl.s * 100));
                addField('L', 'l', Math.round(hsl.l * 100));
            } else if (this.#format === 'hsv') {
                addField('H', 'h', Math.round(this.#state.h));
                addField('S', 's', Math.round(this.#state.s * 100));
                addField('V', 'v', Math.round(this.#state.v * 100));
            }

            this.#attachFieldEvents(row);
        }

        #collectFieldValues(row) {
            const values = {};
            row.querySelectorAll('.cpw-field').forEach(input => { values[input.dataset.field] = input.value; });
            return values;
        }

        #parseCurrentFields() {
            const values = this.#collectFieldValues(this.#dom.fieldsRow);
            let parsed = null;
            if (this.#format === 'hex') parsed = hexFieldToState(values.hex);
            else if (this.#format === 'rgb' || this.#format === 'rgba') parsed = rgbFieldsToState(values);
            else if (this.#format === 'hsl') parsed = hslFieldsToState(values);
            else if (this.#format === 'hsv') parsed = hsvFieldsToState(values);
            if (!parsed) return null;
            // Formats without their own alpha field must never silently reset alpha to 1 --
            // preserve whatever the alpha slider currently holds.
            if (this.#format !== 'rgba') parsed.a = this.#state.a;
            return parsed;
        }

        #attachFieldEvents(row) {
            row.querySelectorAll('.cpw-field').forEach(input => {
                input.addEventListener('input', () => {
                    const parsed = this.#parseCurrentFields();
                    if (!parsed) return;
                    this.#applyState(parsed, { source: 'text', commit: false, skipFieldsRerender: true });
                });
                input.addEventListener('blur', () => {
                    this.#renderFields();
                    this.#commit('text');
                });
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') input.blur();
                });
            });
        }

        // ---- Internal: drag interaction ----------------------------------------------------

        #attachSvDrag() {
            const area = this.#dom.svArea;
            const update = (clientX, clientY) => {
                const rect = area.getBoundingClientRect();
                const x = clamp(clientX - rect.left, 0, rect.width);
                const y = clamp(clientY - rect.top, 0, rect.height);
                const s = rect.width > 0 ? x / rect.width : 0;
                const v = rect.height > 0 ? 1 - y / rect.height : 0;
                this.#applyState({ ...this.#state, s, v }, { source: 'sv' });
            };
            let dragging = false;
            const onMove = (e) => { if (dragging) update(e.clientX, e.clientY); };
            const onUp = () => {
                if (!dragging) return;
                dragging = false;
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                this.#commit('sv');
            };
            area.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                dragging = true;
                update(e.clientX, e.clientY);
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp, { once: true });
            });
            area.addEventListener('keydown', (e) => {
                const step = e.shiftKey ? 0.1 : 0.01;
                let { s, v } = this.#state;
                let handled = true;
                if (e.key === 'ArrowLeft') s = clamp(s - step, 0, 1);
                else if (e.key === 'ArrowRight') s = clamp(s + step, 0, 1);
                else if (e.key === 'ArrowUp') v = clamp(v + step, 0, 1);
                else if (e.key === 'ArrowDown') v = clamp(v - step, 0, 1);
                else handled = false;
                if (handled) {
                    e.preventDefault();
                    this.#applyState({ ...this.#state, s, v }, { source: 'sv', commit: true });
                }
            });
        }

        #attachHueDrag() {
            const slider = this.#dom.hueSlider;
            const update = (clientX) => {
                const rect = slider.getBoundingClientRect();
                const x = clamp(clientX - rect.left, 0, rect.width);
                const h = rect.width > 0 ? (x / rect.width) * 360 : 0;
                this.#applyState({ ...this.#state, h }, { source: 'hue' });
            };
            let dragging = false;
            const onMove = (e) => { if (dragging) update(e.clientX); };
            const onUp = () => {
                if (!dragging) return;
                dragging = false;
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                this.#commit('hue');
            };
            slider.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                dragging = true;
                update(e.clientX);
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp, { once: true });
            });
            slider.addEventListener('keydown', (e) => {
                const step = e.shiftKey ? 10 : 1;
                if (e.key === 'ArrowLeft') { e.preventDefault(); this.#applyState({ ...this.#state, h: clamp(this.#state.h - step, 0, 360) }, { source: 'hue', commit: true }); }
                else if (e.key === 'ArrowRight') { e.preventDefault(); this.#applyState({ ...this.#state, h: clamp(this.#state.h + step, 0, 360) }, { source: 'hue', commit: true }); }
            });
        }

        #attachAlphaDrag() {
            const slider = this.#dom.alphaSlider;
            const update = (clientX) => {
                const rect = slider.getBoundingClientRect();
                const x = clamp(clientX - rect.left, 0, rect.width);
                const a = rect.width > 0 ? x / rect.width : 0;
                this.#applyState({ ...this.#state, a }, { source: 'alpha' });
            };
            let dragging = false;
            const onMove = (e) => { if (dragging) update(e.clientX); };
            const onUp = () => {
                if (!dragging) return;
                dragging = false;
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                this.#commit('alpha');
            };
            slider.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                dragging = true;
                update(e.clientX);
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp, { once: true });
            });
            slider.addEventListener('keydown', (e) => {
                const step = e.shiftKey ? 0.1 : 0.01;
                if (e.key === 'ArrowLeft') { e.preventDefault(); this.#applyState({ ...this.#state, a: clamp(this.#state.a - step, 0, 1) }, { source: 'alpha', commit: true }); }
                else if (e.key === 'ArrowRight') { e.preventDefault(); this.#applyState({ ...this.#state, a: clamp(this.#state.a + step, 0, 1) }, { source: 'alpha', commit: true }); }
            });
        }

        // ---- Internal: eyedropper ----------------------------------------------------------

        async #onEyedropperClick() {
            if (!('EyeDropper' in global)) return;
            this.#emit('eyedropper-start', undefined);
            try {
                const result = await new global.EyeDropper().open();
                const rgb = hexToRgb(result.sRGBHex);
                if (!rgb) return;
                const hsv = rgbToHsv(rgb);
                this.#applyState({ ...hsv, a: this.#state.a }, { source: 'eyedropper', commit: true });
            } catch (err) {
                if (err && err.name === 'AbortError') {
                    this.#emit('eyedropper-cancel', undefined);
                } else {
                    console.warn('ColorPickerWidget: eyedropper failed', err);
                }
            }
        }

        // ---- Internal: popover positioning -------------------------------------------------

        #position() {
            const trigger = this.#dom.trigger;
            const pop = this.#dom.popover;
            const rect = trigger.getBoundingClientRect();
            const popRect = pop.getBoundingClientRect();
            const vw = global.innerWidth, vh = global.innerHeight;

            let top = rect.bottom + 6;
            if (top + popRect.height > vh) {
                const above = rect.top - popRect.height - 6;
                top = above >= 0 ? above : Math.max(6, vh - popRect.height - 6);
            }
            let left = rect.left;
            if (left + popRect.width > vw) left = Math.max(6, vw - popRect.width - 6);

            pop.style.top = top + 'px';
            pop.style.left = left + 'px';
        }
    }

    global.ColorPickerWidget = ColorPickerWidget;
})(window);

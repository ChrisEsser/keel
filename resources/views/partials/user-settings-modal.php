<div class="modal-overlay" id="user-settings-overlay">
    <div class="modal modal--sidebar" id="user-settings-modal">
        <button class="modal-close"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <nav class="modal-sidebar">
                <div class="modal-sidebar-header" data-modal-title>User Settings</div>
                <a href="#" data-panel="general" class="active">
                    <i data-lucide="user"></i> General
                </a>
                <a href="#" data-panel="security">
                    <i data-lucide="lock"></i> Security
                </a>
                <a href="#" data-panel="preferences">
                    <i data-lucide="sliders-horizontal"></i> Preferences
                </a>
            </nav>
            <div class="modal-content">

                <div class="modal-panel active" data-panel="general">
                    <h2>General</h2>
                    <div data-msg="general" style="display:none;"></div>
                    <form data-panel-form="general">
                        <div class="modal-form-row">
                            <label class="modal-form-label required" for="us-first-name">First Name</label>
                            <div class="modal-form-field"><input id="us-first-name" data-field="first_name" type="text" required></div>
                        </div>
                        <div class="modal-form-row">
                            <label class="modal-form-label required" for="us-last-name">Last Name</label>
                            <div class="modal-form-field"><input id="us-last-name" data-field="last_name" type="text" required></div>
                        </div>
                        <div class="modal-form-row">
                            <label class="modal-form-label required" for="us-email">Email</label>
                            <div class="modal-form-field"><input id="us-email" data-field="email" type="email" required></div>
                        </div>
                        <div class="modal-form-actions">
                            <button type="submit" class="btn-primary"><i data-lucide="check"></i> Save</button>
                        </div>
                    </form>
                </div>

                <div class="modal-panel" data-panel="security">
                    <h2>Security</h2>
                    <div data-msg="security" style="display:none;"></div>
                    <form data-panel-form="security" data-success-msg="Password updated.">
                        <div class="modal-form-row">
                            <label class="modal-form-label" for="us-password">New Password</label>
                            <div class="modal-form-field"><input id="us-password" data-field="password" type="password" autocomplete="new-password"></div>
                        </div>
                        <div class="modal-form-row">
                            <label class="modal-form-label" for="us-password-confirm">Confirm Password</label>
                            <div class="modal-form-field"><input id="us-password-confirm" type="password" autocomplete="new-password"></div>
                        </div>
                        <div class="modal-form-actions">
                            <button type="submit" class="btn-primary"><i data-lucide="check"></i> Update Password</button>
                        </div>
                    </form>

                    <div id="sec-other-user-note" style="display:none;color:#78716c;font-size:0.875rem;margin-top:1.5rem;">
                        Two-factor authentication, PIN, and trusted devices can only be managed by the account owner.
                    </div>

                    <div id="sec-self-only">
                        <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5ddd0;">
                        <?php /* id is a scroll target: the post-login security checkup opens this
                                 modal specifically for 2FA, which sits below the password form. */ ?>
                        <h3 id="sec-2fa-heading" style="margin-bottom:0.5rem;">Two-Factor Authentication</h3>
                        <div id="sec-2fa-msg" style="display:none;"></div>

                        <div id="sec-2fa-status-view">
                            <p id="sec-2fa-status-text" style="color:#78716c;font-size:0.875rem;"></p>
                            <div id="sec-2fa-enable-buttons">
                                <button type="button" class="btn-primary btn-sm" id="sec-2fa-setup-totp-btn"><i data-lucide="smartphone"></i> Set up authenticator app</button>
                                <button type="button" class="btn-ghost btn-sm" id="sec-2fa-setup-sms-btn"><i data-lucide="message-square"></i> Set up SMS</button>
                            </div>
                            <div id="sec-2fa-disable-row" style="display:none;">
                                <div class="modal-form-row">
                                    <div class="modal-form-field"><input type="password" id="sec-2fa-disable-password" placeholder="Current password"></div>
                                    <button type="button" class="btn-ghost btn-sm" id="sec-2fa-disable-btn"><i data-lucide="shield-off"></i> Disable 2FA</button>
                                </div>
                                <div class="modal-form-row">
                                    <div class="modal-form-field"><input type="password" id="sec-backup-regen-password" placeholder="Current password"></div>
                                    <button type="button" class="btn-ghost btn-sm" id="sec-backup-regen-btn"><i data-lucide="refresh-cw"></i> Regenerate backup codes</button>
                                </div>
                            </div>
                        </div>

                        <div id="sec-2fa-totp-setup" style="display:none;">
                            <p style="font-size:0.875rem;color:#78716c;">Scan with Google Authenticator, Authy, 1Password, or any TOTP app.</p>
                            <?php /* The logo overlays the QR rather than being drawn into it, so it
                                     doesn't matter whether qrcode.js ends on a <canvas> or an <img>.
                                     Safe because the library defaults to error-correction level H. */ ?>
                            <div class="totp-qr">
                                <div id="sec-totp-qr"></div>
                                <img class="totp-qr-logo" src="/img/logo_icon.png" alt="">
                            </div>
                            <p style="font-size:0.8rem;">Manual entry code: <code id="sec-totp-secret"></code></p>
                            <div class="modal-form-row">
                                <div class="modal-form-field"><input id="sec-totp-code" placeholder="6-digit code" inputmode="numeric" pattern="[0-9]*" maxlength="6" data-code-input></div>
                                <button type="button" class="btn-primary btn-sm" id="sec-totp-confirm-btn"><i data-lucide="shield-check"></i> Confirm</button>
                                <button type="button" class="btn-ghost btn-sm" id="sec-totp-cancel-btn">Cancel</button>
                            </div>
                        </div>

                        <div id="sec-2fa-sms-setup" style="display:none;">
                            <div id="sec-sms-step-phone">
                                <div class="modal-form-row">
                                    <div class="modal-form-field"><input id="sec-sms-phone" type="tel" placeholder="+15551234567"></div>
                                    <button type="button" class="btn-primary btn-sm" id="sec-sms-send-btn"><i data-lucide="send"></i> Send code</button>
                                </div>
                            </div>
                            <div id="sec-sms-step-code" style="display:none;">
                                <div class="modal-form-row">
                                    <div class="modal-form-field"><input id="sec-sms-code" placeholder="6-digit code" inputmode="numeric" pattern="[0-9]*" maxlength="6" data-code-input></div>
                                    <button type="button" class="btn-primary btn-sm" id="sec-sms-confirm-btn"><i data-lucide="shield-check"></i> Confirm</button>
                                </div>
                            </div>
                            <button type="button" class="btn-ghost btn-sm" id="sec-sms-cancel-btn">Cancel</button>
                        </div>

                        <div id="sec-backup-codes-view" style="display:none;">
                            <p style="font-size:0.8rem;color:#78716c;">Save these somewhere safe -- each code works once. Generating new codes invalidates the old ones.</p>
                            <ul id="sec-backup-codes-list" style="font-family:monospace;font-size:0.95rem;line-height:1.7;"></ul>
                            <button type="button" class="btn-ghost btn-sm" id="sec-backup-codes-done-btn">Done</button>
                        </div>

                        <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5ddd0;">
                        <h3 style="margin-bottom:0.25rem;">PIN Quick-Unlock</h3>
                        <p style="font-size:0.8rem;color:#78716c;margin-top:0;">Only usable on a device you've checked "Remember me" on -- lets you skip your full password (and 2FA) with a short PIN on that device.</p>
                        <div id="sec-pin-msg" style="display:none;"></div>
                        <p id="sec-pin-status-text" style="color:#78716c;font-size:0.875rem;"></p>

                        <div id="sec-pin-setup-row">
                            <div class="modal-form-row">
                                <label class="modal-form-label">Current password</label>
                                <div class="modal-form-field"><input type="password" id="sec-pin-password"></div>
                            </div>
                            <div class="modal-form-row">
                                <label class="modal-form-label">New PIN</label>
                                <div class="modal-form-field"><input type="password" id="sec-pin-new" inputmode="numeric" pattern="[0-9]*" maxlength="4" data-code-input></div>
                            </div>
                            <div class="modal-form-row">
                                <label class="modal-form-label">Confirm PIN</label>
                                <div class="modal-form-field"><input type="password" id="sec-pin-confirm" inputmode="numeric" pattern="[0-9]*" maxlength="4" data-code-input></div>
                            </div>
                            <div class="modal-form-actions">
                                <button type="button" class="btn-primary btn-sm" id="sec-pin-enable-btn"><i data-lucide="lock"></i> Enable PIN</button>
                            </div>
                        </div>
                        <div id="sec-pin-disable-row" style="display:none;">
                            <div class="modal-form-row">
                                <div class="modal-form-field"><input type="password" id="sec-pin-disable-password" placeholder="Current password"></div>
                                <button type="button" class="btn-ghost btn-sm" id="sec-pin-disable-btn"><i data-lucide="shield-off"></i> Disable PIN</button>
                            </div>
                        </div>

                        <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5ddd0;">
                        <h3 style="margin-bottom:0.5rem;">Trusted Devices</h3>
                        <div id="sec-devices-list"></div>
                        <button type="button" class="btn-ghost btn-sm" id="sec-devices-revoke-all-btn" style="display:none;margin-top:0.5rem;"><i data-lucide="log-out"></i> Sign out all devices</button>
                    </div>
                </div>

                <div class="modal-panel" data-panel="preferences">
                    <h2>Preferences</h2>
                </div>

            </div>
        </div>
    </div>
</div>
<script src="<?= $this->asset('/js/qrcode.min.js') ?>"></script>
<script>
const userModal = new AjaxModal('user-settings-overlay', {
    url: '/api/users',
    titleField: 'first_name+last_name',
    validators: {
        security(form, payload) {
            const confirm = form.querySelector('#us-password-confirm');
            if (!payload.password) return 'Password cannot be blank.';
            if (payload.password !== confirm?.value) return 'Passwords do not match.';
        },
    },
    onSuccess: {
        security(data, form) {
            form.querySelectorAll('input[type=password]').forEach(i => i.value = '');
        },
    },
    onLoad(data) {
        secPanel.load(data.uid);
    },
});
ModalLoader.register('user-settings', userModal);

const secPanel = (() => {
    const selfOnly = document.getElementById('sec-self-only');
    const otherNote = document.getElementById('sec-other-user-note');

    // This partial is injected at runtime, so the code fields miss code-input.js's
    // DOMContentLoaded pass and have to be enhanced here.
    CodeInput.scan(document.getElementById('user-settings-overlay'));

    function load(uid) {
        if (uid !== CURRENT_USER_UID) {
            selfOnly.style.display = 'none';
            otherNote.style.display = 'block';
            return;
        }
        selfOnly.style.display = '';
        otherNote.style.display = 'none';
        resetSubViews();
        refresh();
    }

    function resetSubViews() {
        document.getElementById('sec-2fa-status-view').style.display = '';
        document.getElementById('sec-2fa-totp-setup').style.display = 'none';
        document.getElementById('sec-2fa-sms-setup').style.display = 'none';
        document.getElementById('sec-backup-codes-view').style.display = 'none';
        document.getElementById('sec-sms-step-phone').style.display = '';
        document.getElementById('sec-sms-step-code').style.display = 'none';
        ['sec-totp-code', 'sec-sms-phone', 'sec-sms-code', 'sec-pin-password', 'sec-pin-new',
         'sec-pin-confirm', 'sec-pin-disable-password', 'sec-2fa-disable-password', 'sec-backup-regen-password']
            .forEach(id => { const el = document.getElementById(id); el.value = ''; CodeInput.sync(el); });
        msg('sec-2fa-msg', '', false);
        msg('sec-pin-msg', '', false);
    }

    // Same alert contract as AjaxModal.showMsg()/ModalForm (app.js) -- these panels drive
    // their own fetches, so they set the classes themselves rather than going through either.
    function msg(elId, text, isError) {
        const el = document.getElementById(elId);
        el.textContent = text;
        if (text) {
            el.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
            el.style.display = '';
        } else {
            el.className = '';
            el.style.display = 'none';
        }
    }

    function refresh() {
        fetch('/api/account/security')
            .then(r => r.json())
            .then(data => { if (data.success) render(data.data); });
    }

    function render(data) {
        const enableBtns = document.getElementById('sec-2fa-enable-buttons');
        const disableRow = document.getElementById('sec-2fa-disable-row');
        const statusText = document.getElementById('sec-2fa-status-text');

        if (!data.encryption_configured) {
            statusText.textContent = 'Two-factor authentication is not configured on this server.';
            enableBtns.style.display = 'none';
            disableRow.style.display = 'none';
        } else if (data.two_factor_enabled) {
            statusText.textContent = `Enabled via ${data.two_factor_method_label}.`;
            enableBtns.style.display = 'none';
            disableRow.style.display = '';
        } else {
            statusText.textContent = 'Not enabled.';
            enableBtns.style.display = '';
            disableRow.style.display = 'none';
            document.getElementById('sec-2fa-setup-sms-btn').style.display = data.sms_available ? '' : 'none';
        }

        const pinStatus = document.getElementById('sec-pin-status-text');
        const pinSetupRow = document.getElementById('sec-pin-setup-row');
        const pinDisableRow = document.getElementById('sec-pin-disable-row');
        if (data.pin_enabled) {
            pinStatus.textContent = 'Enabled.';
            pinSetupRow.style.display = 'none';
            pinDisableRow.style.display = '';
        } else {
            pinStatus.textContent = '';
            pinSetupRow.style.display = '';
            pinDisableRow.style.display = 'none';
        }

        renderDevices(data.devices);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function renderDevices(devices) {
        const list = document.getElementById('sec-devices-list');
        const revokeAllBtn = document.getElementById('sec-devices-revoke-all-btn');
        list.innerHTML = '';

        if (!devices.length) {
            list.innerHTML = '<p style="color:#78716c;font-size:0.875rem;">No remembered devices.</p>';
            revokeAllBtn.style.display = 'none';
            return;
        }

        revokeAllBtn.style.display = '';
        const tbl = document.createElement('table');
        tbl.className = 'data-table modal-table';
        const tbody = document.createElement('tbody');
        devices.forEach(d => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escHtml(d.device_label || 'Unknown device')}</td>
                <td>${escHtml(d.last_used_at || '—')}</td>
                <td>${d.trust_2fa_until_active ? '2FA trusted' : ''}</td>
                <td><button type="button" class="btn-sm btn-ghost" data-id="${d.id}"><i data-lucide="x"></i> Revoke</button></td>`;
            tbody.appendChild(tr);
        });
        tbl.appendChild(tbody);
        list.appendChild(tbl);

        list.querySelectorAll('button[data-id]').forEach(btn => {
            btn.addEventListener('click', () => {
                fetch(`/api/account/devices/${btn.dataset.id}/revoke`, { method: 'POST' })
                    .then(r => r.json())
                    .then(d => { if (d.success) refresh(); });
            });
        });
    }

    document.getElementById('sec-devices-revoke-all-btn').addEventListener('click', () => {
        fetch('/api/account/devices/revoke-all', { method: 'POST' })
            .then(r => r.json())
            .then(d => { if (d.success) refresh(); });
    });

    // -- 2FA: TOTP setup --
    document.getElementById('sec-2fa-setup-totp-btn').addEventListener('click', () => {
        fetch('/api/account/2fa/totp/setup', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { msg('sec-2fa-msg', data.message || 'Error.', true); return; }
                document.getElementById('sec-2fa-status-view').style.display = 'none';
                document.getElementById('sec-2fa-totp-setup').style.display = '';
                document.getElementById('sec-totp-secret').textContent = data.data.secret;
                const qrEl = document.getElementById('sec-totp-qr');
                qrEl.innerHTML = '';
                new QRCode(qrEl, { text: data.data.uri, width: 180, height: 180 });
            });
    });

    document.getElementById('sec-totp-confirm-btn').addEventListener('click', () => {
        const code = document.getElementById('sec-totp-code').value.trim();
        fetch('/api/account/2fa/totp/confirm', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }),
        }).then(r => r.json()).then(data => {
            if (!data.success) { msg('sec-2fa-msg', data.message || 'Incorrect code.', true); return; }
            document.getElementById('sec-2fa-totp-setup').style.display = 'none';
            // Two-factor is now on — reload on close so pages that key off it (the post-login
            // security checkup, most obviously) aren't left showing the old state.
            userModal.markDirty();
            showBackupCodes(data.data.backup_codes);
        });
    });

    document.getElementById('sec-totp-cancel-btn').addEventListener('click', () => {
        document.getElementById('sec-2fa-totp-setup').style.display = 'none';
        document.getElementById('sec-2fa-status-view').style.display = '';
    });

    // -- 2FA: SMS setup --
    document.getElementById('sec-2fa-setup-sms-btn').addEventListener('click', () => {
        document.getElementById('sec-2fa-status-view').style.display = 'none';
        document.getElementById('sec-2fa-sms-setup').style.display = '';
        document.getElementById('sec-sms-step-phone').style.display = '';
        document.getElementById('sec-sms-step-code').style.display = 'none';
    });

    document.getElementById('sec-sms-send-btn').addEventListener('click', () => {
        const phoneEl = document.getElementById('sec-sms-phone');
        const phone_number = phoneEl.value.trim();
        if (!phone_number) {
            phoneEl.classList.add('has-error');
            msg('sec-2fa-msg', 'Phone number is required.', true);
            phoneEl.focus();
            return;
        }
        phoneEl.classList.remove('has-error');
        fetch('/api/account/2fa/sms/send', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ phone_number }),
        }).then(r => r.json()).then(data => {
            if (!data.success) { msg('sec-2fa-msg', data.message || 'Error.', true); return; }
            document.getElementById('sec-sms-step-phone').style.display = 'none';
            document.getElementById('sec-sms-step-code').style.display = '';
        });
    });

    document.getElementById('sec-sms-confirm-btn').addEventListener('click', () => {
        const code = document.getElementById('sec-sms-code').value.trim();
        fetch('/api/account/2fa/sms/confirm', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }),
        }).then(r => r.json()).then(data => {
            if (!data.success) { msg('sec-2fa-msg', data.message || 'Incorrect code.', true); return; }
            document.getElementById('sec-2fa-sms-setup').style.display = 'none';
            userModal.markDirty();
            showBackupCodes(data.data.backup_codes);
        });
    });

    document.getElementById('sec-sms-cancel-btn').addEventListener('click', () => {
        document.getElementById('sec-2fa-sms-setup').style.display = 'none';
        document.getElementById('sec-2fa-status-view').style.display = '';
    });

    function showBackupCodes(codes) {
        document.getElementById('sec-backup-codes-list').innerHTML = codes.map(c => `<li>${escHtml(c)}</li>`).join('');
        document.getElementById('sec-backup-codes-view').style.display = '';
    }

    document.getElementById('sec-backup-codes-done-btn').addEventListener('click', () => {
        document.getElementById('sec-backup-codes-view').style.display = 'none';
        document.getElementById('sec-2fa-status-view').style.display = '';
        refresh();
    });

    document.getElementById('sec-2fa-disable-btn').addEventListener('click', () => {
        const password = document.getElementById('sec-2fa-disable-password').value;
        fetch('/api/account/2fa/disable', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password }),
        }).then(r => r.json()).then(data => {
            document.getElementById('sec-2fa-disable-password').value = '';
            if (!data.success) { msg('sec-2fa-msg', data.message || 'Error.', true); return; }
            msg('sec-2fa-msg', '2FA disabled.', false);
            refresh();
        });
    });

    document.getElementById('sec-backup-regen-btn').addEventListener('click', () => {
        const password = document.getElementById('sec-backup-regen-password').value;
        fetch('/api/account/2fa/backup-codes/regenerate', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password }),
        }).then(r => r.json()).then(data => {
            document.getElementById('sec-backup-regen-password').value = '';
            if (!data.success) { msg('sec-2fa-msg', data.message || 'Error.', true); return; }
            document.getElementById('sec-2fa-status-view').style.display = 'none';
            showBackupCodes(data.data.backup_codes);
        });
    });

    // -- PIN --
    document.getElementById('sec-pin-enable-btn').addEventListener('click', () => {
        const password = document.getElementById('sec-pin-password').value;
        const pin = document.getElementById('sec-pin-new').value.trim();
        const pin_confirm = document.getElementById('sec-pin-confirm').value.trim();
        fetch('/api/account/pin/setup', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password, pin, pin_confirm }),
        }).then(r => r.json()).then(data => {
            if (!data.success) { msg('sec-pin-msg', data.message || 'Error.', true); return; }
            ['sec-pin-password', 'sec-pin-new', 'sec-pin-confirm'].forEach(id => { const el = document.getElementById(id); el.value = ''; CodeInput.sync(el); });
            msg('sec-pin-msg', 'PIN enabled.', false);
            refresh();
        });
    });

    document.getElementById('sec-pin-disable-btn').addEventListener('click', () => {
        const password = document.getElementById('sec-pin-disable-password').value;
        fetch('/api/account/pin/disable', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password }),
        }).then(r => r.json()).then(data => {
            document.getElementById('sec-pin-disable-password').value = '';
            if (!data.success) { msg('sec-pin-msg', data.message || 'Error.', true); return; }
            msg('sec-pin-msg', 'PIN disabled.', false);
            refresh();
        });
    });

    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return { load };
})();
</script>

<span class="guest-eyebrow">// verify</span>
<h1>Verify it's you</h1>
<p style="color:var(--ink-subtle);font-size:0.875rem;margin:0 0 1.25rem;">
<?php if ($method === 'sms'): ?>
    Enter the 6-digit code we texted you.
<?php else: ?>
    Enter the 6-digit code from your authenticator app.
<?php endif; ?>
</p>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/login/2fa" class="auth-form">
    <?php /* Above the code field on purpose: the code auto-submits on the last digit, so
             anything below it would be unreachable. */ ?>
    <label class="form-check"><input type="checkbox" name="trust_device" value="1"> Trust this device for 30 days</label>
    <div class="form-field">
        <label for="code">Code</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus autocomplete="one-time-code" data-code-input data-autosubmit>
    </div>
    <div class="form-actions">
        <?= \Keel\Csrf::field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '') ?>">
        <button type="submit" class="btn-primary"><i data-lucide="shield-check"></i> Verify</button>
    </div>
</form>

<?php if ($method === 'sms'): ?>
<form method="POST" action="/login/2fa/resend" style="margin-top:0.5rem;">
    <?= \Keel\Csrf::field() ?>
    <button type="submit" class="btn-link">Resend code</button>
</form>
<?php endif; ?>

<p style="margin-top:1.25rem;font-size:0.875rem;">
    <a href="#" onclick="document.getElementById('backup-code-row').style.display='block';this.style.display='none';return false;">Use a backup code instead</a>
</p>
<div id="backup-code-row" style="display:none;">
    <form method="POST" action="/login/2fa" class="auth-form">
        <div class="form-field">
            <label for="backup_code">Backup code</label>
            <input id="backup_code" name="backup_code" type="text" autocomplete="off">
        </div>
        <div class="form-actions">
            <?= \Keel\Csrf::field() ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '') ?>">
            <button type="submit" class="btn-primary"><i data-lucide="key"></i> Verify with backup code</button>
        </div>
    </form>
</div>

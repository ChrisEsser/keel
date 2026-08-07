<span class="guest-eyebrow">// welcome back</span>
<h1>Welcome back<?= !empty($first_name) ? ', ' . htmlspecialchars($first_name) : '' ?></h1>
<p style="color:var(--ink-subtle);font-size:0.875rem;margin:0 0 1.25rem;">Enter your PIN to continue on this device.</p>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/login/pin" class="auth-form">
    <div class="form-field">
        <label for="pin">PIN</label>
        <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="4" autofocus autocomplete="off" data-code-input>
    </div>
    <div class="form-actions">
        <?= \Framework\Csrf::field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '') ?>">
        <button type="submit" class="btn-primary"><i data-lucide="log-in"></i> Continue</button>
    </div>
</form>

<p style="margin-top:1.25rem;"><a href="/login/forget-device">Not you? Sign in with a different account</a></p>

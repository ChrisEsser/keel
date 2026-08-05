<span class="guest-eyebrow">// sign in</span>
<h1>Sign In</h1>

<?php if (!empty($reset)): ?>
    <p class="auth-success">Your password has been reset. Please sign in.</p>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/login" class="auth-form">
    <div class="form-field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autofocus>
    </div>
    <div class="form-field">
        <label for="password">Password</label>
        <div class="password-input">
            <input id="password" name="password" type="password">
            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><i data-lucide="eye"></i></button>
        </div>
    </div>
    <label class="form-check"><input type="checkbox" name="remember" value="1"> Remember me</label>
    <a href="/login/forgot-password" class="auth-link-sm">Forgot your password?</a>
    <?= $this->insert('partials/form-guard') ?>
    <div class="form-actions">
        <?= \Keel\Csrf::field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '') ?>">
        <button type="submit" class="btn-primary"><i data-lucide="log-in"></i> Sign In</button>
    </div>
</form>

<p style="margin-top:1.25rem;">Don't have an account? <a href="/signup">Sign up</a></p>

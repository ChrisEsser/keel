<span class="guest-eyebrow">// reset password</span>
<h1>Forgot Password</h1>

<p>Enter your email address and we'll send you a link to reset your password.</p>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/login/forgot-password" class="auth-form">
    <div class="form-field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($email ?? '') ?>" autofocus>
    </div>
    <?= $this->insert('partials/form-guard') ?>
    <div class="form-actions">
        <?= \Framework\Csrf::field() ?>
        <button type="submit" class="btn-primary"><i data-lucide="mail"></i> Send Reset Link</button>
    </div>
</form>

<p style="margin-top:1.25rem;"><a href="/login">Back to sign in</a></p>

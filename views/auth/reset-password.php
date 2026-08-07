<?php if ($state === 'valid'): ?>

<span class="guest-eyebrow">// new password</span>
<h1>Reset Your Password</h1>
<p>Choose a new password for your account.</p>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/login/reset/<?= htmlspecialchars($token) ?>" class="auth-form">
    <div class="form-field">
        <label for="password">New Password</label>
        <div class="password-input">
            <input id="password" name="password" type="password" autofocus>
            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><i data-lucide="eye"></i></button>
        </div>
    </div>
    <div class="form-field">
        <label for="password_confirm">Confirm Password</label>
        <div class="password-input">
            <input id="password_confirm" name="password_confirm" type="password">
            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><i data-lucide="eye"></i></button>
        </div>
    </div>
    <div class="form-actions">
        <?= \Framework\Csrf::field() ?>
        <button type="submit" class="btn-primary"><i data-lucide="check"></i> Reset Password</button>
    </div>
</form>

<?php elseif ($state === 'expired'): ?>

<span class="guest-eyebrow">// reset password</span>
<h1>Link Expired</h1>
<p>This password reset link has expired. Request a new one and we'll send a fresh link to your inbox.</p>

<p style="margin-top:1.25rem;"><a href="/login/forgot-password">Request a new link</a></p>

<?php elseif ($state === 'used'): ?>

<span class="guest-eyebrow">// reset password</span>
<h1>Link Already Used</h1>
<p>This password reset link has already been used. <a href="/login">Sign in</a>, or <a href="/login/forgot-password">request a new link</a> if you still need to reset your password.</p>

<?php else: /* not_found */ ?>

<span class="guest-eyebrow">// reset password</span>
<h1>Invalid Link</h1>
<p>This password reset link is invalid or doesn't exist. <a href="/login/forgot-password">Request a new one</a>.</p>

<?php endif; ?>

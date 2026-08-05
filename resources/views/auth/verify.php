<?php if ($state === 'valid'): ?>

<span class="guest-eyebrow">// set password</span>
<h1>Set Your Password</h1>
<p>Welcome, <?= htmlspecialchars($first_name) ?>! Choose a password to complete your account setup.</p>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/verify/<?= htmlspecialchars($token) ?>" class="auth-form">
    <div class="form-field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autofocus>
        <p class="form-text">At least 8 characters.</p>
    </div>
    <div class="form-field">
        <label for="password_confirm">Confirm Password</label>
        <input id="password_confirm" name="password_confirm" type="password">
    </div>
    <div class="form-actions">
        <?= \Keel\Csrf::field() ?>
        <button type="submit" class="btn-primary"><i data-lucide="check"></i> Set Password</button>
    </div>
</form>

<?php elseif ($state === 'expired'): ?>

<span class="guest-eyebrow">// verify email</span>
<h1>Link Expired</h1>
<p>This verification link has expired. Request a new one and we'll send a fresh link to your inbox.</p>

<form method="POST" action="/verify/<?= htmlspecialchars($token) ?>/resend">
    <?= \Keel\Csrf::field() ?>
    <?= $this->insert('partials/form-guard') ?>
    <button type="submit" class="btn-primary"><i data-lucide="refresh-cw"></i> Resend Verification Email</button>
</form>

<p style="margin-top:1.25rem;"><a href="/signup">Start over</a></p>

<?php elseif ($state === 'used'): ?>

<span class="guest-eyebrow">// verify email</span>
<h1>Already Verified</h1>
<p>This link has already been used. Your account is set up — <a href="/login">sign in</a> to continue.</p>

<?php else: /* not_found */ ?>

<span class="guest-eyebrow">// verify email</span>
<h1>Invalid Link</h1>
<p>This verification link is invalid or doesn't exist. <a href="/signup">Sign up</a> to get started.</p>

<?php endif; ?>

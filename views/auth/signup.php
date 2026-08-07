<span class="guest-eyebrow">// create account</span>
<h1>Create Account</h1>

<?php if (!empty($error)): ?>
    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" action="/signup" class="auth-form">
    <div class="form-field">
        <label for="first_name">First Name</label>
        <input id="first_name" name="first_name" type="text" autofocus value="<?= htmlspecialchars($first_name ?? '') ?>">
    </div>
    <div class="form-field">
        <label for="last_name">Last Name</label>
        <input id="last_name" name="last_name" type="text" value="<?= htmlspecialchars($last_name ?? '') ?>">
    </div>
    <div class="form-field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($email ?? '') ?>">
    </div>
    <div class="form-field">
        <label for="company">Company <span class="form-optional">(optional)</span></label>
        <input id="company" name="company" type="text" value="<?= htmlspecialchars($company ?? '') ?>">
    </div>
    <?= $this->insert('partials/form-guard') ?>
    <div class="form-actions">
        <?= \Framework\Csrf::field() ?>
        <button type="submit" class="btn-primary"><i data-lucide="user-plus"></i> Create Account</button>
    </div>
</form>

<p style="margin-top:1.25rem;">Already have an account? <a href="/login">Sign In</a></p>

<?php if ($state === 'not_found'): ?>

    <h1>Invitation Not Found</h1>
    <p>This invitation link is invalid.</p>
    <p><a href="/">Go home</a></p>

<?php elseif ($state === 'expired'): ?>

    <h1>Invitation Expired</h1>
    <p>This invitation to <strong><?= htmlspecialchars($org['display_name'] ?? $org['name'] ?? '') ?></strong> has expired.</p>
    <p>Please ask the organization owner to send a new invite.</p>
    <p><a href="/">Go home</a></p>

<?php elseif ($state === 'accepted'): ?>

    <h1>Already Accepted</h1>
    <p>This invitation has already been accepted.</p>
    <?php if ($org): ?>
    <p><a href="/organizations/<?= htmlspecialchars($org['uid']) ?>">Go to <?= htmlspecialchars($org['display_name'] ?? $org['name']) ?></a></p>
    <?php else: ?>
    <p><a href="/dashboard">Go to dashboard</a></p>
    <?php endif; ?>

<?php elseif ($state === 'confirm'): ?>

    <h1>Accept Invitation</h1>
    <p>You've been invited to join <strong><?= htmlspecialchars($org['display_name'] ?? $org['name']) ?></strong> as <strong><?= htmlspecialchars($invitation['role_label']) ?></strong>.</p>

    <form method="POST" action="/invitations/<?= htmlspecialchars($invitation['token']) ?>/accept" style="margin-top:1.5rem;">
        <?= \Keel\Csrf::field() ?>
        <button type="submit" class="btn-primary"><i data-lucide="check"></i> Accept Invitation</button>
    </form>

<?php elseif ($state === 'wrong_account'): ?>

    <h1>Wrong Account</h1>
    <p>This invitation is for <strong><?= htmlspecialchars($invitation['email']) ?></strong>.</p>
    <p>You're currently signed in as <strong><?= htmlspecialchars($currentEmail) ?></strong>.</p>
    <p>Please <a href="/logout">sign out</a> and sign in with the invited email address to accept.</p>

<?php elseif ($state === 'login'): ?>

    <h1>Sign In to Accept</h1>
    <p>You've been invited to join <strong><?= htmlspecialchars($org['display_name'] ?? $org['name']) ?></strong> as <strong><?= htmlspecialchars($invitation['role_label']) ?></strong>.</p>
    <p>An account already exists for <strong><?= htmlspecialchars($invitation['email']) ?></strong>. Please sign in to accept.</p>
    <div style="margin-top:1.5rem;">
        <a href="/login?redirect=<?= urlencode('/invitations/' . $token) ?>" class="btn btn-primary"><i data-lucide="log-in"></i> Sign in</a>
    </div>

<?php elseif ($state === 'register'): ?>

    <h1>Create an Account</h1>
    <p>You've been invited to join <strong><?= htmlspecialchars($org['display_name'] ?? $org['name']) ?></strong> as <strong><?= htmlspecialchars($invitation['role_label']) ?></strong>.</p>
    <?php // The address is shown, never entered: it comes from the invitation row, and letting it
          // be typed would turn this into a general-purpose account factory. ?>
    <p>Set a password for <strong><?= htmlspecialchars($invitation['email']) ?></strong> to accept.</p>

    <?php if (!empty($error)): ?>
        <p class="auth-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="/invitations/<?= htmlspecialchars($token) ?>/register" class="auth-form">
        <div class="form-field">
            <label for="first_name">First name</label>
            <input id="first_name" name="first_name" type="text" value="<?= htmlspecialchars($first_name ?? '') ?>" autofocus>
        </div>
        <div class="form-field">
            <label for="last_name">Last name</label>
            <input id="last_name" name="last_name" type="text" value="<?= htmlspecialchars($last_name ?? '') ?>">
        </div>
        <div class="form-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password">
            <p class="form-text">At least 8 characters.</p>
        </div>
        <div class="form-field">
            <label for="password_confirm">Confirm password</label>
            <input id="password_confirm" name="password_confirm" type="password">
        </div>
        <div class="form-actions">
            <?= \Keel\Csrf::field() ?>
            <?= $this->insert('partials/form-guard') ?>
            <button type="submit" class="btn-primary"><i data-lucide="user-plus"></i> Create account</button>
        </div>
    </form>

<?php endif; ?>

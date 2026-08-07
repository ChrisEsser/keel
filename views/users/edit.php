<?php $isNew = ($user === null); ?>
<h1><?= $isNew ? 'New User' : 'Edit User' ?></h1>

<div id="errors" style="color:var(--danger); margin-bottom:1rem;"></div>
<?php if (!$isNew): ?><div id="success" style="color:var(--success); margin-bottom:1rem;"></div><?php endif; ?>

<form id="form">
    <table class="form-table">
        <tr>
            <td><label for="first_name">First Name</label></td>
            <td><input id="first_name" name="first_name" type="text" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" autofocus></td>
        </tr>
        <tr>
            <td><label for="last_name">Last Name</label></td>
            <td><input id="last_name" name="last_name" type="text" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"></td>
        </tr>
        <tr>
            <td><label for="email">Email</label></td>
            <td><input id="email" name="email" type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>"></td>
        </tr>
        <tr>
            <td><label for="password"><?= $isNew ? 'Password' : 'New Password' ?></label></td>
            <td>
                <input id="password" name="password" type="password"
                    <?= !$isNew ? 'placeholder="Leave blank to keep current"' : '' ?>>
            </td>
        </tr>
        <tr>
            <td></td>
            <td style="padding-top:0.75rem;">
                <button type="submit" class="btn-primary">
                    <i data-lucide="<?= $isNew ? 'plus' : 'check' ?>"></i> <?= $isNew ? 'Create' : 'Save' ?>
                </button>
                <a href="<?= htmlspecialchars($cancelUrl) ?>" style="margin-left:1rem;">Cancel</a>
            </td>
        </tr>
    </table>
</form>

<script>
const isNew = <?= $isNew ? 'true' : 'false' ?>;
const uid = <?= !$isNew ? json_encode($user['uid']) : 'null' ?>;

onAjaxSubmit(document.getElementById('form'), async () => {
    document.getElementById('errors').textContent = '';
    if (!isNew) document.getElementById('success').textContent = '';

    const res = await fetch(isNew ? '/api/users' : '/api/users/' + uid, {
        method: isNew ? 'POST' : 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            first_name: document.getElementById('first_name').value,
            last_name: document.getElementById('last_name').value,
            email: document.getElementById('email').value,
            password: document.getElementById('password').value,
        }),
    });
    const data = await res.json();

    if (data.success) {
        if (isNew) {
            window.location = '/users/' + data.data.uid;
            return true; // navigating away -- keep the spinner rather than flashing "Create" back
        }
        document.getElementById('success').textContent = 'Saved.';
        document.getElementById('password').value = '';
    } else {
        document.getElementById('errors').textContent = (data.errors ?? [data.message]).join('\n');
    }
}, isNew ? 'Creating…' : 'Saving…');
</script>

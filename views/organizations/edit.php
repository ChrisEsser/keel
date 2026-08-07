<?php $isNew = ($organization === null); ?>
<h1><?= $isNew ? 'New Organization' : 'Edit Organization' ?></h1>

<div id="errors" style="color:var(--danger); margin-bottom:1rem;"></div>
<?php if (!$isNew): ?><div id="success" style="color:var(--success); margin-bottom:1rem;"></div><?php endif; ?>

<form id="form">
    <table class="form-table">
        <tr>
            <td><label for="name">Name</label></td>
            <td><input id="name" name="name" type="text" value="<?= htmlspecialchars($organization['name'] ?? '') ?>" autofocus></td>
        </tr>
        <tr>
            <td><label for="email">Email</label></td>
            <td><input id="email" name="email" type="email" value="<?= htmlspecialchars($organization['email'] ?? '') ?>"></td>
        </tr>
        <tr>
            <td></td>
            <td style="padding-top:0.75rem;">
                <button type="submit" class="btn-primary">
                    <i data-lucide="<?= $isNew ? 'plus' : 'check' ?>"></i> <?= $isNew ? 'Create' : 'Save' ?>
                </button>
                <a href="<?= $isNew ? '/organizations' : '/organizations/' . htmlspecialchars($organization['uid']) . '/dashboard' ?>" style="margin-left:1rem;">Cancel</a>
            </td>
        </tr>
    </table>
</form>

<script>
const isNew = <?= $isNew ? 'true' : 'false' ?>;
const uid = <?= !$isNew ? json_encode($organization['uid']) : 'null' ?>;

onAjaxSubmit(document.getElementById('form'), async () => {
    document.getElementById('errors').textContent = '';
    if (!isNew) document.getElementById('success').textContent = '';

    const res = await fetch(isNew ? '/api/organizations' : '/api/organizations/' + uid, {
        method: isNew ? 'POST' : 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            name: document.getElementById('name').value,
            email: document.getElementById('email').value,
        }),
    });
    const data = await res.json();

    if (data.success) {
        if (isNew) {
            window.location = '/organizations/' + data.data.uid + '/dashboard';
            return true; // navigating away -- keep the spinner rather than flashing "Create" back
        }
        document.getElementById('success').textContent = 'Saved.';
    } else {
        document.getElementById('errors').textContent = (data.errors ?? [data.message]).join('\n');
    }
}, isNew ? 'Creating…' : 'Saving…');
</script>

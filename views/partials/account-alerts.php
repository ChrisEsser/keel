<?php
// Account-level alert bar, rendered at the top of .page-content (above each page's .page-header).
// Surfaces the same "action needed" states that go out as dunning emails -- a failed payment, an
// account about to lose access -- so they can't be missed. Shown on any org-context page;
// requires $sidebarOrg (uid) to be set by the layout.
//
// Everyone in the org sees the alerts; only owners/admins (and platform admins) get the fix-it
// button -- others get an "ask an owner" nudge baked into the message. Not dismissible: these
// persist until the underlying problem is resolved.

$org = \Framework\Accounts\Model\OrganizationModel::findByUid($sidebarOrg['uid']);
if ($org === null) {
    return;
}

// effectiveIsAdmin, not isAdmin: this decides what to DRAW, and an admin who is impersonating
// should see the screen the customer sees. OrgGuard is the access-control answer and is checked
// again server-side by every endpoint behind these buttons.
$canManageBilling = \Framework\Auth::effectiveIsAdmin()
    || (\Framework\Accounts\OrgGuard::membership($org)?->role->canManageBilling() ?? false);

$accountAlerts = (new \Framework\Accounts\Service\AccountAlerts())->forOrg($org, $canManageBilling);
if ($accountAlerts === []) {
    return;
}
?>
<div class="site-alerts">
    <?php foreach ($accountAlerts as $alert): ?>
        <div class="site-alert site-alert--<?= htmlspecialchars($alert['severity']) ?>">
            <i data-lucide="<?= htmlspecialchars($alert['icon']) ?>" class="site-alert-icon"></i>
            <div class="site-alert-body">
                <strong><?= htmlspecialchars($alert['title']) ?></strong>
                <?= htmlspecialchars($alert['message']) ?>
            </div>
            <?php if (!empty($alert['action'])): ?>
                <?php if (isset($alert['action']['href'])): ?>
                    <a class="btn btn-sm site-alert-action" href="<?= htmlspecialchars($alert['action']['href']) ?>">
                        <?= htmlspecialchars($alert['action']['label']) ?>
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm site-alert-action" onclick="<?= htmlspecialchars($alert['action']['onclick']) ?>">
                        <?= htmlspecialchars($alert['action']['label']) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

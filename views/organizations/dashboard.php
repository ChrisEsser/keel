<?php
/**
 * The customer's home screen for one organization.
 *
 * Deliberately close to empty. Keel knows about accounts, teams and billing, and it has nothing
 * useful to say about what YOUR product does -- so this is a frame with a plan banner and a team
 * summary, and the space below is yours.
 *
 * @var array $organization  OrganizationModel::toArray()
 * @var bool  $canManage     The viewer may act on this org's content
 * @var bool  $hasActivePlan
 */

$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<?php // Staff land here from "Customer view" on the support hub, and from impersonating someone.
      // Without this there is no way back up that isn't the browser's Back button. Hidden while
      // impersonating: effectiveIsAdmin() reads the impersonated user, which is the point -- you
      // are supposed to be seeing exactly what they see. ?>
<?php if (\Framework\Auth::effectiveIsAdmin()): ?>
    <p style="margin-bottom:1rem;"><a href="/organizations/<?= $e($organization['uid']) ?>"><i data-lucide="arrow-left"></i> Back to the organization</a></p>
<?php endif; ?>

<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-icon"><i data-lucide="home"></i></div>
        <div>
            <h1 class="page-header-title"><?= $e($organization['display_name']) ?></h1>
            <div class="page-header-subtitle">Your workspace</div>
        </div>
    </div>
</div>

<?php if (!$hasActivePlan && $canManage): ?>
    <?php // Above the fold and not dismissible, but not a wall either: someone who hasn't
          // subscribed yet should still be able to look around. Gate the features, not the door. ?>
    <div class="alert alert-info alert-with-action">
        <span>Start a subscription to unlock everything in <?= $e($organization['display_name']) ?>.</span>
        <button class="btn btn-primary btn-sm alert-action" onclick="ModalLoader.open('plans', '<?= $e($organization['uid']) ?>')">
            <i data-lucide="layers"></i> Choose a plan
        </button>
    </div>
<?php endif; ?>

<div class="content-card">
    <h2 class="section-heading">Getting started</h2>
    <p class="text-muted">
        This is where your application goes. Replace this view with whatever your product's home
        screen should be — the organization, the team and the subscription around it are already
        wired up.
    </p>
    <?php // A plain list, not .first-run-steps -- that one is centered for a full-page empty state
          // and reads as misaligned inside a left-aligned card. ?>
    <ol class="text-muted" style="margin:1rem 0 0; padding-left:1.25rem; line-height:1.9;">
        <li>Invite your teammates from Settings &rarr; Team</li>
        <li>Add a subscription in Settings &rarr; Billing</li>
        <li>Build the thing you actually came here to build</li>
    </ol>
</div>

<?php
/**
 * The app's one persistent chrome bar.
 *
 * It replaced TWO bars that each only existed half the time: `.mobile-topbar` (only below 860px)
 * and `.org-header-bar` (only when an organization was in scope). Screens with no `$sidebarOrg` --
 * the user list, the activity log -- gain a bar they never had, and the account controls leave the
 * sidebar's foot for somewhere they can be reached without scrolling a long nav.
 *
 * It spans the CONTENT column, not the window: the rail runs the full height of the viewport and
 * its own brand caps it. Below 860px the rail goes off-canvas, so the bar takes the full width and
 * picks up the hamburger and the mark.
 *
 * Left is where you are: the organization, then the breadcrumb. Right is who you are.
 *
 * Breadcrumbs are read from the render data rather than sniffed off the request path here. The
 * controller knows where it is in a way a path-matcher can only approximate, and `$breadcrumbs` is
 * already the layout's contract.
 *
 * ## Adding a control of your own
 *
 * `.topbar-icon-btn` is the slot: an icon button between the scope and the account menu, for the
 * one or two things an application wants reachable from every screen (a help panel, an assistant, a
 * notification tray). It is styled but unused here, as is the tooltip flip that serves it --
 * `.app-topbar [data-tooltip]::after` in app.css, which drops the bubble BELOW its trigger because
 * the bar is fixed to the top of the viewport and the default would render off-screen.
 *
 *     <button class="topbar-icon-btn" type="button" aria-label="Help" data-tooltip="Help"
 *             onclick="ModalLoader.open('help')"><i data-lucide="circle-help"></i></button>
 *
 * @var array|null  $sidebarOrg    ['uid' => ..., 'name' => ...]
 * @var array       $sidebarOrgs   Every organization the viewer belongs to.
 * @var bool        $canEditOrg
 * @var array|null  $breadcrumbs
 * @var string|null $orgSwitchPath
 */

$tbEsc = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$tbCrumbs = $breadcrumbs ?? [];

// The organization control IS crumb zero. Rendering both prints the same name twice a few pixels
// apart, which reads as a bug rather than as a hierarchy.
if (isset($sidebarOrg) && $tbCrumbs !== [] && ($tbCrumbs[0]['label'] ?? null) === $sidebarOrg['name']) {
    array_shift($tbCrumbs);
}

// Everywhere but here: you cannot switch to the organization you are already in.
//
// Gated on being a member of the one on screen, which staff inspecting a customer are not. Offering
// an admin "switch to" their own three businesses from the middle of somebody else's dashboard
// reads as a bug -- the list has nothing to do with the org whose name it hangs under.
$tbOtherOrgs = [];
if (isset($sidebarOrg)) {
    $tbIsMember = false;
    foreach ($sidebarOrgs as $tbSo) {
        if ($tbSo['uid'] === $sidebarOrg['uid']) {
            $tbIsMember = true;
            break;
        }
    }
    if ($tbIsMember) {
        foreach ($sidebarOrgs as $tbSo) {
            if ($tbSo['uid'] !== $sidebarOrg['uid']) {
                $tbOtherOrgs[] = $tbSo;
            }
        }
    }
}

// A menu only when there is something to put in it; otherwise the organization is just a label.
$tbOrgMenu = isset($sidebarOrg) && ($tbOtherOrgs !== [] || $canEditOrg);
$tbSwitchPath = $orgSwitchPath ?? '/dashboard';

// Initials, not a photo: this framework stores no avatars, and a generic silhouette tells you
// nothing that the two letters do not.
$tbUser = \Framework\Auth::user();
$tbName = $tbUser?->fullName() ?? '';
$tbParts = preg_split('/\s+/', trim($tbName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$tbInitials = $tbParts !== []
    ? mb_strtoupper(mb_substr($tbParts[0], 0, 1) . (count($tbParts) > 1 ? mb_substr((string) end($tbParts), 0, 1) : ''))
    : mb_strtoupper(mb_substr($tbUser?->email ?? '?', 0, 1));

// Auth::user() is whoever is being impersonated; Auth::actualUser() is the admin driving it.
$tbActual = \Framework\Auth::isImpersonating() ? \Framework\Auth::actualUser() : null;
?>
<header class="app-topbar">
    <div class="app-topbar-lead">
        <?php // Still named .mobile-nav-toggle: app.js looks it up by that class, and the name is
              // still true -- it is display:none above the breakpoint. ?>
        <button class="mobile-nav-toggle" type="button" aria-label="Open navigation menu"
                aria-expanded="false" aria-controls="app-sidebar" onclick="toggleSidebar()">
            <i data-lucide="menu"></i>
        </button>
        <a class="app-topbar-brand" href="/dashboard">
            <img src="/img/logo-mark.svg" alt="" class="app-topbar-brand-icon">
            <span class="app-topbar-wordmark"><?= $tbEsc(\Framework\Brand::name()) ?></span>
        </a>
    </div>

    <div class="app-topbar-scope">
        <?php if ($tbOrgMenu): ?>
            <div class="topbar-menu" id="org-menu">
                <button class="topbar-org-trigger" type="button"
                        aria-haspopup="menu" aria-expanded="false" aria-controls="org-menu-drop">
                    <span class="topbar-org-name"><?= $tbEsc($sidebarOrg['name']) ?></span>
                    <i data-lucide="chevron-down" class="topbar-menu-chevron" aria-hidden="true"></i>
                </button>
                <div class="topbar-menu-drop topbar-menu-drop--left" id="org-menu-drop" role="menu" hidden>
                    <div class="topbar-menu-head">
                        <span class="topbar-menu-head-name"><?= $tbEsc($sidebarOrg['name']) ?></span>
                    </div>
                    <?php if ($canEditOrg): ?>
                        <button class="topbar-menu-item" type="button" role="menuitem"
                                onclick="ModalLoader.open('org-settings', '<?= $tbEsc($sidebarOrg['uid']) ?>')">
                            <i data-lucide="settings"></i>Organization settings
                        </button>
                    <?php endif; ?>
                    <?php if ($tbOtherOrgs !== []): ?>
                        <div class="topbar-menu-label">Switch to</div>
                        <?php foreach ($tbOtherOrgs as $tbSo): ?>
                            <?php // The current screen's path, not /dashboard: a screen that exists
                                  // for every organization should survive the switch. ?>
                            <a class="topbar-menu-item" role="menuitem"
                               href="/organizations/<?= $tbEsc($tbSo['uid']) . $tbEsc($tbSwitchPath) ?>">
                                <i data-lucide="building-2"></i><?= $tbEsc($tbSo['name']) ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif (isset($sidebarOrg)): ?>
            <span class="topbar-org-static"><?= $tbEsc($sidebarOrg['name']) ?></span>
        <?php endif; ?>

        <?php // Guarded on a non-empty list: an empty <nav> still eats a flex gap. ?>
        <?php if ($tbCrumbs !== []): ?>
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <?php foreach ($tbCrumbs as $tbI => $tbCrumb): ?>
                    <?php if ($tbI > 0 || isset($sidebarOrg)): ?><span class="breadcrumb-sep" aria-hidden="true">›</span><?php endif; ?>
                    <?php if (!empty($tbCrumb['url'])): ?>
                        <a href="<?= $tbEsc($tbCrumb['url']) ?>"><?= $tbEsc($tbCrumb['label']) ?></a>
                    <?php else: ?>
                        <span class="breadcrumb-current" aria-current="page"><?= $tbEsc($tbCrumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>

    <?php if (\Framework\Auth::check()): ?>
        <div class="app-topbar-account">
            <div class="topbar-menu" id="account-menu">
                <button class="topbar-menu-trigger" type="button"
                        aria-haspopup="menu" aria-expanded="false" aria-controls="account-menu-drop">
                    <span class="topbar-avatar" aria-hidden="true"><?= $tbEsc($tbInitials) ?></span>
                    <span class="topbar-menu-name"><?= $tbEsc($tbName) ?></span>
                    <i data-lucide="chevron-down" class="topbar-menu-chevron" aria-hidden="true"></i>
                </button>
                <div class="topbar-menu-drop" id="account-menu-drop" role="menu" hidden>
                    <div class="topbar-menu-head">
                        <span class="topbar-menu-head-name"><?= $tbEsc($tbName) ?></span>
                        <span class="topbar-menu-head-email"><?= $tbEsc($tbUser?->email ?? '') ?></span>
                        <?php if ($tbActual !== null): ?>
                            <span class="topbar-menu-head-alt">Impersonated by <?= $tbEsc($tbActual->fullName()) ?></span>
                        <?php endif; ?>
                    </div>
                    <button class="topbar-menu-item" type="button" role="menuitem"
                            onclick="ModalLoader.open('user-settings', '<?= $tbEsc($tbUser?->uid ?? '') ?>')">
                        <i data-lucide="settings"></i>Account settings
                    </button>
                    <a class="topbar-menu-item<?= $tbActual !== null ? ' topbar-menu-item--danger' : '' ?>" href="/logout" role="menuitem">
                        <i data-lucide="log-out"></i>Sign out<?= $tbActual !== null ? ' (' . $tbEsc($tbActual->fullName()) . ')' : '' ?>
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</header>

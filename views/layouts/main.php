<?php
/**
 * The authenticated application shell: sidebar, org switcher, breadcrumbs, modal root.
 *
 * ## Supplying navigation
 *
 * Pass `$nav` in the render data. It is a flat list, and each entry is one of three shapes:
 *
 *   ['label' => 'Users',  'href' => '/users', 'icon' => 'users', 'match' => 'prefix']
 *   ['section' => 'Acme Inc']
 *   ['label' => 'Billing', 'icon' => 'credit-card', 'items' => [ ...links... ]]
 *
 * `icon` is a Lucide icon name. `match` is 'exact' (default) or 'prefix' and decides when the
 * link renders as active; a link may also carry `matchAny => ['/a', '/b']` when one entry owns
 * several paths. A group renders collapsed unless one of its items is active.
 *
 * Omit `$nav` entirely and you get Keel's own default: the admin section for staff, and the
 * current organization's dashboard for everyone else. That is enough to run the framework's
 * built-in screens and nothing else, which is the right starting point for a new application --
 * add your product's entries by passing `$nav` from your controllers, or by sharing a builder on
 * the View at wiring time.
 *
 * This used to be a hand-written if/elseif chain over $_SERVER['REQUEST_URI'] with one branch per
 * feature. It worked, and it meant every new screen edited the layout -- so the layout knew every
 * route in the product and the product could not be reused.
 *
 * @var string|null $title
 * @var string      $content
 * @var array|null  $nav          Navigation entries, as above.
 * @var array|null  $breadcrumbs  [['label' => ..., 'url' => ...|null], ...]. The controller knows
 *                                where it is; the layout does not.
 * @var array|null  $sidebarOrg   ['uid' => ..., 'name' => ...] -- draws the org header bar.
 */

// Every screen in this layout is a legitimate "return target", so record it on the rewind stack.
// A layout that is deliberately not a return target (a fullscreen editor, say) should not.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    \Framework\Nav::record(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
}

$bannerOffset = \Framework\Auth::isImpersonating() ? '50px' : '0px';
$navPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

$sidebarOrgs = [];
$canEditOrg = false;
if (\Framework\Auth::check() && !\Framework\Auth::effectiveIsAdmin()) {
    // Memberships outlive their organization -- deleting an org doesn't cascade, so a null find()
    // here is a soft-deleted org and the membership must be dropped rather than rendered as a
    // blank, unclickable row.
    $sidebarOrgs = array_values(array_filter(array_map(function (\Framework\Accounts\Model\MembershipModel $m) {
        $o = \Framework\Accounts\Model\OrganizationModel::find($m->org_id);
        return $o === null ? null : ['uid' => $o->uid, 'name' => $o->displayName(), 'role' => $m->role->value];
    }, \Framework\Accounts\Model\MembershipModel::findByUser(\Framework\Auth::user()->id))));
}
if (isset($sidebarOrg)) {
    if (\Framework\Auth::isAdmin()) {
        $canEditOrg = true;
    } else {
        // Every member gets the Settings button. The modal opens for all of them and locks the
        // panels they can't act on rather than hiding the door -- a plain user still needs their
        // own preferences in there.
        foreach ($sidebarOrgs as $so) {
            if ($so['uid'] === $sidebarOrg['uid']) {
                $canEditOrg = true;
                break;
            }
        }
    }
}

$isActive = static function (array $item) use ($navPath): bool {
    foreach ($item['matchAny'] ?? [] as $prefix) {
        if (str_starts_with($navPath, $prefix)) return true;
    }
    if (!isset($item['href'])) return false;

    return ($item['match'] ?? 'exact') === 'prefix'
        ? str_starts_with($navPath, $item['href'])
        : $navPath === $item['href'];
};

// Keel's own default nav, used when the application doesn't supply one.
if (!isset($nav)) {
    $nav = [];
    if (\Framework\Auth::effectiveIsAdmin()) {
        $nav[] = ['section' => 'Admin'];
        $nav[] = ['label' => 'Activity', 'href' => '/activity', 'icon' => 'history'];
        $nav[] = ['label' => 'Organizations', 'href' => '/organizations', 'icon' => 'building-2', 'match' => 'prefix'];
        $nav[] = ['label' => 'Users', 'href' => '/users', 'icon' => 'users', 'match' => 'prefix'];
    }
    if (isset($sidebarOrg)) {
        $orgBase = '/organizations/' . $sidebarOrg['uid'];
        $nav[] = ['section' => $sidebarOrg['name']];
        $nav[] = ['label' => 'Dashboard', 'href' => $orgBase . '/dashboard', 'icon' => 'home'];
    }
}

$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? \Framework\Brand::name()) ?></title>
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/img/favicon/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preload" href="/fonts/poppins-400-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/nunito-600-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= $this->asset('/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('/css/base.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('/css/app.css') ?>">
    <script src="<?= $this->asset('/js/feedback.js') ?>"></script>
    <script src="<?= $this->asset('/js/app.js') ?>"></script>
    <script src="<?= $this->asset('/js/code-input.js') ?>"></script>
    <?php // Only loaded where billing is actually configured -- an app with no Stripe keys has no
          // reason to hand every page a third-party script tag. ?>
    <?php if (!empty($_ENV['STRIPE_PUBLIC_KEY'])): ?>
        <script src="https://js.stripe.com/v3/"></script>
    <?php endif; ?>
    <style>
        :root {
            --banner-offset: <?= $bannerOffset ?>;
        }
    </style>
</head>
<body>

<a class="skip-link" href="#main-content">Skip to content</a>

<?php if (\Framework\Auth::isImpersonating()): ?>
    <div class="banner">
        Impersonating <strong><?= $e(\Framework\Auth::user()?->fullName()) ?></strong>
        &nbsp;
        <button onclick="stopImpersonating()"><i data-lucide="x"></i> Stop</button>
    </div>
    <script>
    function stopImpersonating() {
        fetch('/api/admin/impersonate/stop', {method:'POST'})
            .then(() => window.location = '/dashboard');
    }
    </script>
<?php endif; ?>

<aside class="sidebar" id="app-sidebar">
    <div class="sidebar-brand">
        <img src="/img/logo-mark.svg" alt="" class="sidebar-brand-icon">
        <span><?= $e(\Framework\Brand::name()) ?></span>
    </div>
    <nav>
        <?php foreach ($nav as $entry): ?>
            <?php if (isset($entry['section'])): ?>
                <div class="sidebar-section-label"><?= $e($entry['section']) ?></div>
            <?php elseif (isset($entry['items'])): ?>
                <?php
                $groupOpen = false;
                foreach ($entry['items'] as $child) {
                    if ($isActive($child)) { $groupOpen = true; break; }
                }
                ?>
                <div class="sidebar-nav-group <?= $groupOpen ? 'open' : '' ?>">
                    <button class="sidebar-nav-group-toggle" type="button"
                            onclick="this.closest('.sidebar-nav-group').classList.toggle('open')">
                        <?php if (isset($entry['icon'])): ?><i data-lucide="<?= $e($entry['icon']) ?>"></i><?php endif; ?>
                        <?= $e($entry['label'] ?? '') ?>
                        <i data-lucide="chevron-right" class="sidebar-nav-group-chevron"></i>
                    </button>
                    <div class="sidebar-nav-group-items">
                        <?php foreach ($entry['items'] as $child): ?>
                            <a href="<?= $e($child['href'] ?? '#') ?>"<?= $isActive($child) ? ' class="active"' : '' ?>>
                                <?php if (isset($child['icon'])): ?><i data-lucide="<?= $e($child['icon']) ?>"></i><?php endif; ?>
                                <?= $e($child['label'] ?? '') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= $e($entry['href'] ?? '#') ?>"<?= $isActive($entry) ? ' class="active"' : '' ?>>
                    <?php if (isset($entry['icon'])): ?><i data-lucide="<?= $e($entry['icon']) ?>"></i><?php endif; ?>
                    <?= $e($entry['label'] ?? '') ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php if (\Framework\Auth::check()): ?>
        <div class="sidebar-user">
            <a href="#" onclick="ModalLoader.open('user-settings', '<?= $e(\Framework\Auth::user()?->uid) ?>'); return false;"><i data-lucide="settings"></i>&nbsp;&nbsp;<?= $e(\Framework\Auth::user()?->fullName()) ?></a><br>
            <a href="/logout"><i data-lucide="log-out"></i>&nbsp;&nbsp;Sign out</a>
        </div>
    <?php endif; ?>
</aside>

<div class="sidebar-backdrop" onclick="closeSidebar()"></div>

<div class="page-wrap">
    <div class="mobile-topbar">
        <button class="mobile-nav-toggle" type="button" aria-label="Open navigation menu"
                aria-expanded="false" aria-controls="app-sidebar" onclick="toggleSidebar()">
            <i data-lucide="menu"></i>
        </button>
        <span class="mobile-topbar-brand"><?= $e(\Framework\Brand::name()) ?></span>
    </div>
    <?php if (isset($sidebarOrg)): ?>
        <div class="org-header-bar">
            <div class="breadcrumb">
                <?php foreach ($breadcrumbs ?? [] as $i => $crumb): ?>
                    <?php if ($i > 0): ?><span class="breadcrumb-sep">›</span><?php endif; ?>
                    <?php if (!empty($crumb['url'])): ?>
                        <a href="<?= $e($crumb['url']) ?>"><?= $e($crumb['label']) ?></a>
                    <?php else: ?>
                        <span class="breadcrumb-current"><?= $e($crumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="org-header-controls">
                <?php if (!\Framework\Auth::effectiveIsAdmin() && count($sidebarOrgs) > 1): ?>
                    <select onchange="window.location='/organizations/'+this.value+'/dashboard'">
                        <?php foreach ($sidebarOrgs as $so): ?>
                        <option value="<?= $e($so['uid']) ?>" <?= $so['uid'] === $sidebarOrg['uid'] ? 'selected' : '' ?>>
                            <?= $e($so['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <span class="org-header-name"><?= $e($sidebarOrg['name']) ?></span>
                <?php endif; ?>
                <?php if ($canEditOrg): ?>
                    <button class="btn btn-ghost-primary btn-sm" onclick="ModalLoader.open('org-settings', '<?= $e($sidebarOrg['uid']) ?>')"><i data-lucide="settings"></i> Settings</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="page-content" id="main-content" tabindex="-1">
        <?php if (isset($sidebarOrg) && \Framework\Auth::check()): ?>
            <?php require __DIR__ . '/../partials/account-alerts.php'; ?>
        <?php endif; ?>
        <?= $content ?>
    </div>
</div>

<script>
const CURRENT_USER_UID = '<?= $e(\Framework\Auth::user()?->uid) ?>';
const CURRENT_USER_EMAIL = '<?= $e(\Framework\Auth::user()?->email) ?>';
</script>
<div id="modal-root"></div>
<?php
// The post-login two-factor offer, raised by DashboardController on the way through /dashboard and
// spent here on whichever screen the user was actually heading for. Included rather than fetched on
// demand: it appears on at most one page load per sign-in, so shipping the markup only on that load
// is cheaper than a round trip — and it keeps the prompt out of ModalController's whitelist, which
// has no per-page gate.
if (!empty($_SESSION['security_checkup'])) {
    unset($_SESSION['security_checkup']);
    require __DIR__ . '/../partials/security-checkup-modal.php';
}
?>
<script src="https://unpkg.com/lucide@1.24.0/dist/umd/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>

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
 * A link may also carry `except => ['/fragment', ...]`: a path containing any of them is never
 * active, however well it matches otherwise. It exists because one feature can live inside
 * another's URL family -- see $isActive.
 *
 * Omit `$nav` entirely and you get Keel's own default: the admin group for staff, and the
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
 * @var array|null  $sidebarOrg   ['uid' => ..., 'name' => ...] -- names the organization in the bar.
 * @var string|null $orgSwitchPath Where the org switcher sends you, appended to
 *                                /organizations/{uid}. Defaults to '/dashboard'. A screen that
 *                                exists for every organization should pass its own path, so
 *                                switching keeps you where you were.
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
if (\Framework\Auth::check()) {
    // Built from ACTUAL memberships, for admins too.
    //
    // This used to skip admins entirely, on the reasonable assumption that platform staff are not
    // members of anything -- so the list would be empty and the switcher meaningless. That
    // assumption breaks the moment the admin is also the customer, which is the normal case for a
    // single-operator install: the person running several of their own organizations was handed no
    // way to move between them.
    //
    // Reading real memberships is correct either way. Staff who belong to nothing still get an
    // empty list and no switcher, exactly as before; an admin who genuinely belongs to three
    // organizations gets the three they belong to -- never every organization on the platform,
    // which is what the support hub at /organizations is for.
    //
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
    // `except` first, and it beats every other rule. A prefix is the only way to say "this entry
    // owns a family of URLs", and sometimes another feature lives inside that family: an
    // organization's own screens sit under /organizations/{uid}/, so the admin "Organizations"
    // entry matched their prefix on every one of them -- lighting up as active and, now that the
    // admin entries are a group, forcing that group open on a screen it has nothing to do with.
    foreach ($item['except'] ?? [] as $fragment) {
        if (str_contains($navPath, $fragment)) return false;
    }

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
        // One collapsible group rather than a flat section. The platform screens are a place staff
        // visit occasionally; giving them three permanent rows at the top of every sidebar makes
        // them look like the main event. The group opens itself when one of its own pages is
        // active, so it can never hide where you already are.
        //
        // It lives on Framework\AdminNav rather than here because passing $nav REPLACES this whole
        // default -- an application with its own navigation splices the group back in from there.
        $nav[] = \Framework\AdminNav::group();
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
    <?php // A self-contained colour picker: one global (ColorPickerWidget), its own injected
          // stylesheet, no dependency on any other script or icon font. Loaded globally rather than
          // per-page because the modals that would use it arrive through ModalLoader, which injects
          // HTML into a page that has already finished loading its scripts. ?>
    <script src="<?= $this->asset('/js/color-picker-widget.js') ?>"></script>
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

<?php require __DIR__ . '/../partials/app-topbar.php'; ?>

<?php // No account footer any more -- that moved to the topbar. The brand stays: it caps the rail,
      // which runs the full height of the window, and a dark column starting below a bar reads as a
      // panel hanging off it rather than as the shell's spine. The one scroll container is <nav>. ?>
<aside class="sidebar" id="app-sidebar">
    <a class="sidebar-brand" href="/dashboard">
        <img src="/img/logo-mark.svg" alt="" class="sidebar-brand-icon">
        <span><?= $e(\Framework\Brand::name()) ?></span>
    </a>
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
                    <?php // toggleNavGroup() rather than an inline classList.toggle: the inline
                          // version left aria-expanded reporting whatever state the page was
                          // rendered in, so a screen reader was told "collapsed" about a section
                          // the user had just opened. ?>
                    <button class="sidebar-nav-group-toggle" type="button"
                            aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>"
                            onclick="toggleNavGroup(this)">
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
</aside>

<div class="sidebar-backdrop" onclick="closeSidebar()"></div>

<div class="page-wrap">
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

<?php
/**
 * The public marketing shell, served on the apex (APP_DOMAIN).
 *
 * Deliberately free of Auth, Nav and models: it has to stay renderable with the session cold and
 * the database irrelevant, which is what keeps it cheap — this is the surface that gets the
 * traffic spike, and it should cost a template render and nothing else.
 *
 * Every link into the application is ABSOLUTE off APP_URL. App routes exist only on the app host,
 * so a relative /login here would 404 on the apex.
 *
 * @var string|null $title
 * @var string|null $description
 * @var string      $content
 */

$appUrl = \Framework\Host::appUrl();
$appDomain = \Framework\Host::appDomain();
$canonical = \Framework\Host::requestScheme() . '://' . $appDomain . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$brand = \Framework\Brand::name();
$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? $brand) ?></title>
    <meta name="description" content="<?= $e($description ?? '') ?>">
    <link rel="canonical" href="<?= $e($canonical) ?>">
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/img/favicon/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preload" href="/fonts/poppins-400-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= $this->asset('/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('/css/base.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('/css/marketing.css') ?>">
</head>
<body class="marketing-layout">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="marketing-header">
        <a class="marketing-brand" href="/">
            <img class="marketing-brand-mark" src="/img/logo-mark.svg" alt="">
            <span class="wordmark"><?= $e($brand) ?></span>
        </a>
        <nav class="marketing-nav">
            <a href="<?= $e($appUrl) ?>/login">Sign in</a>
            <a href="<?= $e($appUrl) ?>/signup">Get started</a>
        </nav>
    </header>

    <main id="main-content">
        <?= $content ?>
    </main>

    <footer class="marketing-footer">
        <div class="marketing-footer-brand">
            <a class="marketing-brand" href="/">
                <img class="marketing-brand-mark" src="/img/logo-mark.svg" alt="">
                <span class="wordmark"><?= $e($brand) ?></span>
            </a>
        </div>
        <nav class="marketing-footer-links">
            <div class="marketing-footer-col">
                <h3>Account</h3>
                <a href="<?= $e($appUrl) ?>/login">Sign in</a>
                <a href="<?= $e($appUrl) ?>/signup">Create an account</a>
            </div>
        </nav>
        <div class="marketing-footer-legal">&copy; <?= date('Y') ?> <?= $e($brand) ?></div>
    </footer>
<script src="https://unpkg.com/lucide@1.24.0/dist/umd/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>

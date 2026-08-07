<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? \Keel\Brand::name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/img/favicon/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preload" href="/fonts/poppins-400-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= $this->asset('/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('/css/base.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('/css/guest.css') ?>">
</head>
<body class="guest-layout">
    <a class="guest-brand" href="/">
        <img src="/img/logo-mark.svg" alt="" class="guest-brand-mark">
        <span class="wordmark"><?= \Keel\Brand::name() ?></span>
    </a>
    <div class="guest-card">
        <?= $content ?>
    </div>
<script src="https://unpkg.com/lucide@1.24.0/dist/umd/lucide.min.js"></script>
<script>lucide.createIcons();</script>
<script src="<?= $this->asset('/js/code-input.js') ?>"></script>
<script src="<?= $this->asset('/js/form-guard.js') ?>"></script>
<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.password-toggle');
    if (!btn) return;
    const input = btn.parentElement.querySelector('input');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.innerHTML = '<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>';
    lucide.createIcons();
});
</script>
</body>
</html>

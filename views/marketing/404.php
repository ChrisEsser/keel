<?php
// Errors::notFound() renders this with no data, so the app link is read straight from config.
$appUrl = \Framework\Host::appUrl();
?>
<section class="marketing-404">
    <div class="wrap m404-wrap">
        <span class="mkt-mono m404-code">ERROR 404</span>
        <h1>There's nothing at this address</h1>
        <p class="lede">The page may have moved, or it may never have existed. Either way, this isn't it.</p>

        <div class="marketing-cta m404-cta">
            <a class="btn btn-primary btn-lg" href="/">Back to home</a>
            <a class="marketing-cta-secondary" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>">Open the app &rarr;</a>
        </div>
    </div>
</section>

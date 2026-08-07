<?php
/**
 * Bot-defence fields for the public platform forms, checked by Framework\Accounts\Service\PublicFormGuard.
 * Drop one of these inside every logged-out <form> alongside Csrf::field():
 *
 *     <?= $this->insert('partials/form-guard') ?>
 *
 * Three things ship together because the guard reads all three:
 *
 *  - `_hp`         a honeypot. Hidden from people, invisible to screen readers via aria-hidden
 *                  and removed from tab order, so only a script that fills every field trips it.
 *                  Positioned off-canvas rather than display:none — some bots skip hidden inputs.
 *  - `_elapsed_ms` milliseconds between the form rendering and the submit. Bots post instantly;
 *                  people take seconds. Left at 0 when JS is off, and the guard treats 0 as
 *                  "unknown" rather than "bot", so the forms still work without JS.
 *  - Turnstile     Cloudflare's challenge widget, rendered only when the platform has keys
 *                  configured. In managed mode most real visitors never see an interaction.
 *
 * $guard is injected into every guest view by View::render(); see config/container.php.
 */
$guard = $guard ?? null;
?>
<div class="form-guard" aria-hidden="true">
    <label for="_hp">Leave this field empty</label>
    <input id="_hp" name="_hp" type="text" tabindex="-1" autocomplete="off" value="">
</div>
<input type="hidden" name="_elapsed_ms" value="0" data-form-elapsed>
<?php if ($guard !== null && $guard->turnstileEnabled()): ?>
    <div class="cf-turnstile"
         data-sitekey="<?= htmlspecialchars($guard->turnstileSiteKey()) ?>"
         data-appearance="interaction-only"
         data-response-field-name="cf-turnstile-response"></div>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>

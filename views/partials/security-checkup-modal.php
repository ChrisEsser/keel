<?php
/**
 * The post-login two-factor offer (raised by DashboardController, spent by layouts/main).
 *
 * A modal rather than a screen: it is optional, it only appears now and then, and it interrupts
 * someone on their way somewhere else. Closing it IS the "not now" answer, so every way out of it
 * -- the X, the backdrop, Escape, "Remind me later" -- snoozes the prompt. The one exception is
 * "Set up", which is them acting on it, not deferring it.
 *
 * Setup itself lives in the Security tab of the user settings modal; both buttons just open it
 * there. Closing that modal after a successful setup reloads the page, and the prompt does not
 * come back because shouldPromptForTwoFactor() now says so.
 */
$smsAvailable = ($_ENV['TWILIO_ACCOUNT_SID'] ?? '') !== '' && ($_ENV['TWILIO_AUTH_TOKEN'] ?? '') !== '';
?>
<div class="modal-overlay" id="security-checkup-overlay">
    <div class="modal modal--plain">
        <button class="modal-close" aria-label="Close"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <div class="modal-content checkup-modal">
                <div class="checkup-icon"><i data-lucide="shield-check"></i></div>

                <h2>Add an extra layer of security</h2>
                <p class="checkup-lead">
                    Passwords get reused, guessed and leaked. A second step when you sign in means your
                    account stays yours even if your password doesn't. It takes about a minute to set up.
                </p>

                <div class="checklist-item">
                    <div class="checklist-item-icon"><i data-lucide="smartphone"></i></div>
                    <div class="checklist-item-label">
                        Authenticator app
                        <span class="checklist-item-optional">Recommended</span>
                        <span class="checklist-item-hint">Codes from an app on your phone. Works without signal.</span>
                    </div>
                    <button class="btn btn-primary btn-sm" data-checkup-setup>Set up</button>
                </div>

                <?php if ($smsAvailable): ?>
                    <div class="checklist-item">
                        <div class="checklist-item-icon"><i data-lucide="message-square"></i></div>
                        <div class="checklist-item-label">
                            Text message
                            <span class="checklist-item-hint">We text you a code each time you sign in.</span>
                        </div>
                        <button class="btn btn-ghost btn-sm" data-checkup-setup>Set up</button>
                    </div>
                <?php endif; ?>

                <div class="checkup-skip">
                    <button type="button" class="btn-link" id="checkup-later">Remind me later</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const overlay = document.getElementById('security-checkup-overlay');
    const CSRF = <?= json_encode(\Keel\Csrf::token()) ?>;
    let settled = false;   // the snooze is posted at most once, however they leave

    // Deferring is a background fact, not something to wait on or report: the modal closes either
    // way, and the worst case of a failed post is being asked again next sign-in.
    function close(snooze = true) {
        overlay.style.display = 'none';
        if (!snooze || settled) return;
        settled = true;
        fetch('/security-checkup/snooze', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ _csrf: CSRF }),
        }).catch(() => {});
    }

    overlay.querySelector('.modal-close').addEventListener('click', () => close());
    document.getElementById('checkup-later').addEventListener('click', () => close());
    onBackdropDismiss(overlay, close);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && overlay.style.display === 'flex') close();
    });

    overlay.querySelectorAll('[data-checkup-setup]').forEach(btn => {
        btn.addEventListener('click', () => {
            // No snooze -- they're acting on the prompt. Abandoning setup half-way should leave
            // them asked again next time, not quietly quieted for a fortnight.
            close(false);
            ModalLoader.open('user-settings', CURRENT_USER_UID, 'security');

            // The Security panel is fetched and rendered asynchronously, and two-factor sits below
            // the change-password form — so wait for the heading to exist, then bring it into view.
            // Giving up after a few seconds keeps a slow or failed load from polling forever.
            const deadline = Date.now() + 3000;
            const timer = setInterval(() => {
                const heading = document.getElementById('sec-2fa-heading');
                if (heading) {
                    clearInterval(timer);
                    heading.scrollIntoView({ block: 'start', behavior: 'smooth' });
                } else if (Date.now() > deadline) {
                    clearInterval(timer);
                }
            }, 50);
        });
    });

    overlay.style.display = 'flex';
})();
</script>

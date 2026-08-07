// Stamps how long a public form was on screen before it was submitted, read by
// App\Service\PublicFormGuard as one of its bot signals. Paired with
// views/partials/form-guard.php, which renders the hidden field this fills in.
//
// A person reads labels and types; a script posts the moment the page parses. The guard only
// rejects implausibly FAST submissions — a missing or zero value means "JS didn't run", which it
// treats as unknown rather than as a bot, so these forms keep working with JS disabled.
//
// Timed from page load rather than first interaction: a password manager can fill and submit a
// login form with no keystrokes at all, and that's a real user.
(function () {
    'use strict';

    var fields = document.querySelectorAll('[data-form-elapsed]');
    if (!fields.length) return;

    var start = Date.now();

    // Update on submit rather than on a timer — one read at the moment it matters.
    document.addEventListener('submit', function (e) {
        var field = e.target.querySelector('[data-form-elapsed]');
        if (field) field.value = String(Date.now() - start);
    }, true); // capture, so it runs before any handler that might cancel the event
})();

<?php

declare(strict_types=1);

namespace Framework;

// The application's name, in one place.
//
// This exists because the alternative doesn't hold. Write the name literally into each view and
// within a few months there are three of them live at once -- one in the app shell, a different
// one in the guest shell, a third in the manifest -- and nobody notices until a customer asks
// which product they're actually using. Renaming is a one-line edit in .env here.
//
// Not reachable from PHP -- update these by hand when the name changes:
//   - public/site.webmanifest   (static JSON: name, short_name)
//   - public/img/logo.svg       (the wordmark is drawn, not typeset)
//   - MAIL_FROM_NAME in .env    (per-install, and it's what recipients see in their inbox)
final class Brand
{
    public static function name(): string
    {
        $name = trim((string) ($_ENV['APP_NAME'] ?? ''));

        return $name !== '' ? $name : 'Keel';
    }
}

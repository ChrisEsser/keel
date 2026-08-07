<?php

declare(strict_types=1);

namespace Framework;

// Session-backed navigation history ("rewind" stack). Screens that are legitimate
// return targets record themselves (see views/layouts/main.php), and screens whose
// Back control should rewind resolve their target with back()/previous(). This lets a
// Back link return to wherever the user actually came from instead of a hardcoded page.
//
// Tradeoff: the stack lives in the session, so it is shared across a user's browser tabs
// and can desync under concurrent navigation. Callers always pass a safe default, so Back
// still lands somewhere sane; the truncate-on-revisit rule re-coheres a single trail.
class Nav
{
    private const KEY = 'nav_stack';
    private const MAX = 10;

    // Append $path to the trail. Consecutive duplicates are ignored; revisiting an earlier
    // screen truncates everything above it (the user navigated back) so the stack stays a
    // coherent trail and does not grow while ping-ponging between two screens.
    public static function record(string $path): void
    {
        if (!str_starts_with($path, '/')) {
            return;
        }
        Auth::start();

        $stack = $_SESSION[self::KEY] ?? [];

        if ($stack && end($stack) === $path) {
            return;
        }

        $existing = array_search($path, $stack, true);
        if ($existing !== false) {
            $stack = array_slice($stack, 0, $existing);
        }

        $stack[] = $path;
        if (count($stack) > self::MAX) {
            $stack = array_slice($stack, -self::MAX);
        }

        $_SESSION[self::KEY] = array_values($stack);
    }

    // Back target for a screen that does NOT record itself (e.g. the fullscreen editor):
    // the last recorded screen, else $default.
    public static function back(string $default): string
    {
        Auth::start();
        $stack = $_SESSION[self::KEY] ?? [];
        $top = end($stack);
        return $top !== false ? $top : $default;
    }

    // Back target for a screen that DOES record itself: the entry immediately below
    // $current in the trail (skipping $current itself), else $default.
    public static function previous(string $default, string $current): string
    {
        Auth::start();
        $stack = $_SESSION[self::KEY] ?? [];
        $index = array_search($current, $stack, true);
        if ($index !== false && $index > 0) {
            return $stack[$index - 1];
        }
        return $default;
    }
}

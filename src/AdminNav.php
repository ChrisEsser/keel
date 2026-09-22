<?php

declare(strict_types=1);

namespace Framework;

// The platform screens, as one collapsible sidebar group.
//
// This is a class rather than a literal in views/layouts/main.php because passing $nav REPLACES the
// layout's default. An application that supplies its own navigation -- which every application does,
// the moment it has a second screen -- would otherwise silently lose the admin links, and the only
// way back to /users would be typing the URL. Copying the array into each app's nav builder is the
// other way out, and two copies of the same menu drift: the drift shows up as an entry that exists
// on one screen and not another.
//
//     $nav = [];
//     if (\Framework\Auth::effectiveIsAdmin()) {
//         $nav[] = \Framework\AdminNav::group();
//     }
//     $nav[] = ['section' => $org->displayName()];
//     // ... your product's own entries
//
// Not on Framework\Nav: despite the name, that is the session-backed rewind stack, not a nav
// builder.
final class AdminNav
{
    /**
     * One group rather than a flat section. These are screens staff visit occasionally; three
     * permanent rows at the top of every sidebar make them look like the main event.
     *
     * The layout opens a group automatically when one of its children is the current page, so this
     * can never collapse the screen you are already on out of sight.
     *
     * @return array<string,mixed>
     */
    public static function group(): array
    {
        return [
            'label' => 'Admin',
            'icon'  => 'shield',
            'items' => [
                ['label' => 'Activity', 'href' => '/activity', 'icon' => 'history'],
                // 'except' is load-bearing, not a nicety. A customer's own screens live under
                // /organizations/{uid}/, so a bare prefix match here is active on every one of them
                // -- and because these are a group now, that also forces the group open on a screen
                // that has nothing to do with it. '/dashboard' covers the default org nav this
                // framework ships; an application that adds its own screens under an organization
                // adds their prefixes here too.
                ['label' => 'Organizations', 'href' => '/organizations', 'icon' => 'building-2', 'match' => 'prefix', 'except' => ['/dashboard']],
                ['label' => 'Users', 'href' => '/users', 'icon' => 'users', 'match' => 'prefix'],
            ],
        ];
    }
}

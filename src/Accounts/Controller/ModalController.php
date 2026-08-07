<?php

declare(strict_types=1);

namespace Framework\Accounts\Controller;

use Framework\Auth;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\View\View;

/**
 * Serves modal partials on demand to `ModalLoader.open(name)` in public/js/app.js.
 *
 * The point is weight. The org-settings modal alone is several hundred lines of markup plus its
 * own JavaScript; shipping that inside every page load costs every visitor for a panel most of
 * them never open. Fetched on first use and cached in the DOM afterwards, it costs only the people
 * who use it.
 *
 * ## The allow-list is the security boundary
 *
 * `$name` arrives from the browser and becomes part of a template path. A whitelist -- not a
 * sanitizer, not a regex -- is what keeps that from being a file-read primitive. Registering a new
 * modal means adding it here on purpose.
 *
 * Note that a partial served here renders with NO layout and NO per-modal permission check: these
 * are shells, and every one of them hydrates from an API endpoint that runs its own authorization.
 * Do not put anything in a modal partial that is itself secret.
 */
class ModalController
{
    /**
     * Modal name -> template path under views/.
     *
     * Add your application's modals here. Everything listed is reachable by any signed-in user,
     * so the markup must be safe for all of them to see empty.
     */
    private const MODALS = [
        'org-settings' => 'partials/org-settings-modal',
        'user-settings' => 'partials/user-settings-modal',
        'user-create' => 'partials/user-create-modal',
        'org-create' => 'partials/org-create-modal',
        'org-lookup' => 'partials/org-lookup-modal',
        'plans' => 'partials/plans-modal',
    ];

    public function __construct(private View $view) {}

    public function fragment(Request $request): Response
    {
        if (!Auth::check()) {
            return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $name = (string) $request->getAttribute('name');
        $template = self::MODALS[$name] ?? null;

        if ($template === null) {
            return Response::json(['success' => false, 'message' => 'Unknown modal.'], 404);
        }

        // No layout: the fragment is injected into #modal-root on a page that already has one.
        return Response::html($this->view->render($template, [], null));
    }
}

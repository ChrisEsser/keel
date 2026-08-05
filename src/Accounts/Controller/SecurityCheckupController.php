<?php

declare(strict_types=1);

namespace Keel\Accounts\Controller;

use Keel\Auth;
use Keel\Csrf;
use Keel\Http\Request;
use Keel\Http\Response;

// Snooze for the post-login two-factor offer.
//
// The offer itself is views/partials/security-checkup-modal.php, raised by DashboardController
// when UserModel::shouldPromptForTwoFactor() says it's time and shown by layouts/main on whichever
// screen the user was heading for. It used to be a page of its own at /security-checkup; that put
// people somewhere they never asked to go, with an empty sidebar, to answer a question they were
// allowed to ignore.
//
// Setup runs in the existing Security tab of the user settings modal rather than being duplicated
// here, so all this controller owns is the "not now".
class SecurityCheckupController
{
    public function snooze(Request $request): Response
    {
        if (!Auth::check()) {
            return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // A failed CSRF check costs them the quiet period and nothing else. The caller closes the
        // modal either way and never reads this — being asked again next sign-in is the whole of
        // the downside, and it is not worth an error in front of someone who was dismissing a
        // suggestion.
        if (!Csrf::verify($request->getBody()['_csrf'] ?? null)) {
            return Response::json(['success' => false, 'message' => 'Expired token.'], 419);
        }

        $user = Auth::actualUser();
        $user->two_factor_prompt_snoozed_at = gmdate('Y-m-d H:i:s');
        $user->save();

        return Response::json(['success' => true]);
    }
}

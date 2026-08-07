<?php

declare(strict_types=1);

namespace Framework\Accounts\Controller;

use Framework\Accounts\Model\MembershipModel;
use Framework\Accounts\Model\OrganizationModel;
use Framework\Accounts\Model\Role;
use Framework\Auth;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\View\View;

class DashboardController
{
    public function __construct(private View $view) {}

    public function index(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');

        // Every login lands here before bouncing on to the real destination, which makes it
        // the one place to catch someone right after sign-in and offer two-factor setup.
        //
        // Raises a flag rather than redirecting to a page of its own: the offer is optional and
        // skippable, and standing it up as a screen put the user somewhere they never asked to go
        // -- with an empty sidebar, since an interstitial has no org context to fill it with. As a
        // flag it survives the redirect below and is spent by layouts/main on whichever screen
        // they were actually heading for, which is where a dismissable prompt belongs.
        if (!empty($_SESSION['post_login'])) {
            unset($_SESSION['post_login']);
            if ($this->shouldOfferTwoFactor()) {
                $_SESSION['security_checkup'] = true;
            }
        }

        if (Auth::isAdmin() && !Auth::isImpersonating()) {
            return Response::redirect('/organizations');
        }

        $memberships = MembershipModel::findByUser(Auth::user()->id);

        foreach ($memberships as $membership) {
            $org = OrganizationModel::find($membership->org_id);
            if ($org !== null) {
                return Response::redirect('/organizations/' . $org->uid . '/dashboard');
            }
        }

        return Response::html($this->view->render('dashboard/no_orgs'));
    }

    // Never nag through an impersonation session — that's an admin looking at someone else's
    // account, not the account owner. Without APP_ENCRYPTION_KEY the TOTP secret can't be
    // stored at all (Framework\Security\Crypto throws), so offering setup would dead-end.
    private function shouldOfferTwoFactor(): bool
    {
        return !Auth::isImpersonating()
            && ($_ENV['APP_ENCRYPTION_KEY'] ?? '') !== ''
            && (Auth::actualUser()?->shouldPromptForTwoFactor() ?? false);
    }

    // Self-serve rescue for a logged-in user who belongs to no organization: create a
    // workspace and make them its Owner. (OrganizationController::store is admin-only and
    // doesn't attach the creator, so it can't serve this case.)
    public function createWorkspace(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');

        $user = Auth::user();

        // Enforce what this method has always claimed: it is a rescue for someone with NO
        // organization. It never checked, so any logged-in account could post here repeatedly and
        // mint workspaces without limit -- and since every new workspace carries its own one-time
        // build-credit pot, that was a way to draw the free allowance over and over from a single
        // account. Someone who already has one gets sent to it, exactly as index() would.
        foreach (MembershipModel::findByUser($user->id) as $membership) {
            $org = OrganizationModel::find($membership->org_id);
            if ($org !== null) {
                return Response::redirect('/organizations/' . $org->uid . '/dashboard');
            }
        }

        $name = trim($request->getBody()['name'] ?? '');

        $org = new OrganizationModel();
        $org->name    = $name;   // '' when unnamed -> displays "My Workspace" (OrganizationModel::displayName)
        $org->email   = $user->email;
        $org->save();

        $membership = new MembershipModel();
        $membership->user_id = $user->id;
        $membership->org_id  = $org->id;
        $membership->role    = Role::Owner;
        $membership->save();

        return Response::redirect('/organizations/' . $org->uid . '/dashboard');
    }
}

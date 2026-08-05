<?php

declare(strict_types=1);

namespace Keel\Accounts\Controller;

use Keel\Accounts\Model\AdminEventModel;
use Keel\Accounts\Model\InvitationModel;
use Keel\Accounts\Model\MembershipModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\Role;
use Keel\Accounts\Model\UserModel;
use Keel\Auth;
use Keel\Http\Errors;
use Keel\Http\Request;
use Keel\Http\Response;
use Keel\View\View;

/**
 * The support hub for one organization: everything staff need on a call, on one page.
 *
 * Separate from OrganizationController because the gate is different. Every org-scoped method
 * there is admin-OR-member and branches on callerCapabilities(); nothing here is. This page
 * carries impersonation and other people's roles, so it is unconditionally Auth::isAdmin() --
 * and keeping the two apart means neither gate can be pasted into the wrong place.
 *
 * This is a seam an application extends. Whatever your product gives an organization -- sites,
 * projects, devices, invoices -- the support call is about that thing, so add it to show()'s
 * payload and to views/organizations/show.php rather than building a second staff page for it.
 */
class OrgAdminController
{
    public function __construct(
        private View $view,
        private Errors $errors,
    ) {}

    public function show(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');

        $org = OrganizationModel::findByUid((string) $request->getAttribute('uid'));
        if ($org === null) return $this->errors->notFound();

        // A member who follows an old bookmark goes where they were always going. The hub is
        // staff-only; there is nothing on it a customer should see about their own org.
        if (!Auth::isAdmin()) return Response::redirect('/organizations/' . $org->uid . '/dashboard');

        // Deliberately no 'sidebarOrg': that would make the layout draw the CUSTOMER's org nav
        // around a staff page. The hub belongs under the admin nav, alongside /users/{uid}, and
        // /organizations stays highlighted by prefix.
        return Response::html($this->view->render('organizations/show', [
            'org' => $org,
            'owner' => $this->owner($org),
            'team' => $this->team($org),
            'pendingInvites' => InvitationModel::countPending($org->id),
            'activity' => AdminEventModel::search(['org_id' => $org->id], 1, 10)['items'],
        ]));
    }

    private function owner(OrganizationModel $org): ?UserModel
    {
        foreach (MembershipModel::findByOrganization($org->id) as $membership) {
            if ($membership->role === Role::Owner) {
                return UserModel::find($membership->user_id);
            }
        }

        return null;
    }

    /** @return list<array{membership: MembershipModel, user: UserModel}> */
    private function team(OrganizationModel $org): array
    {
        $out = [];
        foreach (MembershipModel::findByOrganization($org->id) as $membership) {
            $user = UserModel::find($membership->user_id);
            if ($user === null) continue;   // a deleted account whose membership row outlived it
            $out[] = ['membership' => $membership, 'user' => $user];
        }

        // Owner first, then admins, then everyone else -- the order a support call needs them in.
        $rank = ['owner' => 0, 'admin' => 1, 'user' => 2];
        usort($out, static fn(array $a, array $b) =>
            ($rank[$a['membership']->role->value] ?? 9) <=> ($rank[$b['membership']->role->value] ?? 9));

        return $out;
    }
}

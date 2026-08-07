<?php

declare(strict_types=1);

namespace Framework\Accounts\Controller;

use Framework\Accounts\Model\MembershipModel;
use Framework\Accounts\OrgGuard;
use Framework\Accounts\Model\OrganizationModel;
use Framework\Accounts\Model\Role;
use Framework\Accounts\Model\UserModel;
use Framework\Accounts\Service\AdminLog;
use Framework\Auth;
use Framework\Http\Errors;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\View\View;

// Organization CRUD, the member roster, and ownership transfer.
//
// /organizations/{uid} itself is deliberately NOT here -- that is the staff support hub, and lives
// on OrgAdminController.
class OrganizationController
{
    public function __construct(
        private View $view,
        private Errors $errors,
    ) {}

    public function list(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');
        if (!Auth::isAdmin()) return Response::redirect('/dashboard');

        return Response::html($this->view->render('organizations/list', []));
    }

    public function get(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $search = trim($request->query('search', ''));

        $result = OrganizationModel::searchForAdmin($page, $perPage, $search);
        $totalPages = (int) ceil($result['total'] / $perPage);

        return Response::json([
            'success' => true,
            'data' => array_map(function (OrganizationModel $o) {
                $arr = $o->toArray();
                // Owner name rides along so admin surfaces (org list, the user-page org-lookup modal)
                // can show WHO owns each org -- the point of being able to search by owner name.
                $owner = $this->ownerLabel($o);
                $arr['owner_name'] = $owner;
                // Support-facing: an unnamed org (the customer skipped the optional company field)
                // shows its OWNER'S name in the name slot instead of yet another "My Workspace" card,
                // so a support rep fielding a call can actually tell them apart.
                if (trim($o->name) === '') {
                    $arr['name'] = $owner ?? OrganizationModel::DEFAULT_NAME;
                }
                return $arr;
            }, $result['items']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => $totalPages ?: 1,
            ],
        ]);
    }

    public function dashboard(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');

        $uid = $request->getAttribute('uid');
        $org = OrganizationModel::findByUid($uid);

        if ($org === null) {
            return $this->errors->notFound();
        }

        // Not a member and not staff: 404 rather than 403. A stranger probing org uids shouldn't
        // learn which ones exist.
        if (!Auth::isAdmin() && OrgGuard::membership($org) === null) {
            return $this->errors->notFound();
        }
        $canManage = OrgGuard::canManageContent($org);

        $html = $this->view->render('organizations/dashboard', [
            'organization' => $org->toArray(),
            'sidebarOrg' => ['uid' => $org->uid, 'name' => $org->displayName()],
            'canManage' => $canManage,
            'hasActivePlan' => $org->hasActivePlan(),
        ]);
        return Response::html($html);
    }

    // No show() here: /organizations/{uid} is the staff support hub, and lives on
    // OrgAdminController. The member redirect it would do is the first thing that controller does
    // for a non-admin.

    public function getMembers(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $org = OrganizationModel::findByUid($uid);

        if ($org === null) {
            return Response::json(['success' => false, 'message' => 'Organization not found.'], 404);
        }

        $isAdmin = Auth::isAdmin();
        $callerMembership = OrgGuard::membership($org);
        // A system admin gets the owner's controls on every org, member or not.
        $callerCanSetRoles = $isAdmin || $callerMembership?->role === Role::Owner;

        // Any member may READ the roster -- the Team panel renders for everyone, read-only for
        // those who can't manage it. Mutating the roster is a separate check, enforced per-action
        // in MembershipController and InvitationController; the display flags below only decide
        // which controls are worth drawing.
        if (!$isAdmin && $callerMembership === null) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $callerCanManageTeam = $isAdmin || $callerMembership->role->canManageTeam();

        $members = array_filter(array_map(function (MembershipModel $m) use ($callerCanSetRoles, $callerCanManageTeam) {
            $u = UserModel::find($m->user_id);
            if ($u === null) {
                return null;
            }
            $isOwner = $m->role === Role::Owner;
            return $m->toArray() + [
                'user_name' => $u->fullName(),
                'user_email' => $u->email,
                'role_span_display' => ($isOwner || !$callerCanSetRoles) ? '' : 'none',
                'role_select_display' => ($isOwner || !$callerCanSetRoles) ? 'none' : '',
                'remove_display' => ($isOwner || !$callerCanManageTeam) ? 'none' : '',
                'transfer_display' => ($isOwner || !$callerCanSetRoles) ? 'none' : '',
            ];
        }, MembershipModel::findByOrganization($org->id)));

        return Response::json(['success' => true, 'data' => array_values($members)]);
    }

    public function transferOwnership(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $org = OrganizationModel::findByUid($uid);

        if ($org === null) {
            return Response::json(['success' => false, 'message' => 'Organization not found.'], 404);
        }

        $callerMembership = OrgGuard::membership($org);
        if (!Auth::isAdmin() && $callerMembership?->role !== Role::Owner) {
            return Response::json(['success' => false, 'message' => 'Only the owner can transfer ownership.'], 403);
        }

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $membershipUid = (string) ($input['membership_uid'] ?? '');
        $target = $membershipUid !== '' ? MembershipModel::findByUid($membershipUid) : null;

        if ($target === null || $target->org_id !== $org->id) {
            return Response::json(['success' => false, 'message' => 'Member not found in this organization.'], 404);
        }

        if ($target->role === Role::Owner) {
            return Response::json(['success' => false, 'message' => 'That member is already the owner.'], 422);
        }

        // Demotes whoever holds the role, which is the caller for an owner-driven transfer but
        // some third party when a system admin drives it -- an admin passing through shouldn't
        // be re-roled just for making the change.
        MembershipModel::makeSoleOwner($target);

        $newOwner = UserModel::find($target->user_id);
        AdminLog::record('org.ownership_transferred', Auth::actualUser()?->fullName() . ' made ' . ($newOwner?->fullName() ?? 'a member') . ' the owner of ' . $org->displayName(), [
            'org' => $org,
            'user' => $newOwner,
            'meta' => ['previous_role' => $target->role->value],
        ]);

        return Response::json(['success' => true]);
    }

    public function edit(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');

        $uid = $request->getAttribute('uid');

        if ($uid === null) {
            if (!Auth::isAdmin()) return Response::redirect('/organizations');
            return Response::html($this->view->render('organizations/edit', [
                'organization' => null,
                'sidebarOrg' => null,
            ]));
        }

        $org = OrganizationModel::findByUid($uid);
        if ($org === null) return $this->errors->notFound();

        if (!OrgGuard::canManageContent($org)) {
            return Response::redirect("/organizations/$uid");
        }

        return Response::html($this->view->render('organizations/edit', [
            'organization' => $org->toArray(),
            'sidebarOrg' => ['uid' => $org->uid, 'name' => $org->displayName()],
        ]));
    }

    public function apiShow(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $org = OrganizationModel::findByUid($uid);

        if ($org === null) {
            return Response::json(['success' => false, 'message' => 'Organization not found.'], 404);
        }

        if (!OrgGuard::canManageContent($org)) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return Response::json(['success' => true, 'data' => $this->payload($org)]);
    }

    public function update(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $org = OrganizationModel::findByUid($uid);

        if ($org === null) {
            return Response::json(['success' => false, 'message' => 'Organization not found.'], 404);
        }

        if (!OrgGuard::canManageContent($org)) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();

        $org->name = trim($input['name'] ?? $org->name);
        $org->email = trim($input['email'] ?? $org->email);

        // Some jurisdictions require a physical address in commercial email. Blank is allowed here
        // (a footer just omits the line) rather than blocking an unrelated org rename on it.
        if (array_key_exists('postal_address', $input)) {
            $address = trim((string) $input['postal_address']);
            $org->postal_address = $address !== '' ? $address : null;
        }

        // Deliberately no admin override of the subscription here. Entitlement is whatever Stripe
        // says it is; hand-editing it would mean the app and the invoice disagree about what the
        // customer is buying -- silently, and in the customer's favour or ours depending on the
        // direction. Change what they pay for through the billing endpoints, which talk to Stripe.

        $errors = $org->validate();
        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        $org->save();

        return Response::json(['success' => true, 'data' => $this->payload($org)]);
    }

    public function store(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();

        $org = new OrganizationModel();
        $org->name = trim($input['name'] ?? '');
        $org->email = trim($input['email'] ?? '');

        $errors = $org->validate();
        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        $org->save();

        AdminLog::record('org.created', Auth::actualUser()?->fullName() . ' created the organization ' . $org->displayName(), ['org' => $org]);

        return Response::json(['success' => true, 'data' => $org->toArray()], 201);
    }

    public function destroy(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $uid = $request->getAttribute('uid');
        $org = OrganizationModel::findByUid($uid);

        if ($org === null) {
            return Response::json(['success' => false, 'message' => 'Organization not found.'], 404);
        }

        // Soft delete: the row stops hydrating (Model::find filters it) but stays for the support
        // question that follows. An application holding resources in a shared namespace -- a
        // subdomain, a sending domain, anything another org could claim next -- should release
        // them here, before the delete, or they stay squatted by an org that no longer exists.
        $org->delete();

        AdminLog::record('org.deleted', Auth::actualUser()?->fullName() . ' deleted the organization ' . $org->displayName(), [
            'org' => $org,
        ]);

        return Response::json(['success' => true, 'message' => 'Organization deleted.']);
    }

    /**
     * The org as the settings modal needs it: its own fields plus what the CALLER may do with it.
     *
     * caller_can rides on the payload rather than being a second request because the modal locks
     * its panels the moment it opens -- a separate round trip would mean a window where every
     * control is live for someone who may not be allowed to use it.
     */
    private function payload(OrganizationModel $org): array
    {
        return $org->toArray() + ['caller_can' => OrgGuard::capabilities($org)];
    }

    // The owner's display name, used as the admin org-list label for orgs that were never named.
    // Null only if the org somehow has no owner membership or the owner user is gone.
    private function ownerLabel(OrganizationModel $org): ?string
    {
        foreach (MembershipModel::findByOrganization($org->id) as $membership) {
            if ($membership->role === Role::Owner) {
                return UserModel::find($membership->user_id)?->fullName();
            }
        }
        return null;
    }
}

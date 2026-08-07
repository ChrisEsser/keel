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
use Framework\Http\Request;
use Framework\Http\Response;

class MembershipController
{
    public function store(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();

        $userUid = (string) ($input['user_uid'] ?? '');
        $orgUid = (string) ($input['org_uid'] ?? '');
        $role = Role::tryFrom((string) ($input['role'] ?? ''));

        $user = $userUid !== '' ? UserModel::findByUid($userUid) : null;
        $org = $orgUid !== '' ? OrganizationModel::findByUid($orgUid) : null;

        $errors = [];

        if ($user === null) {
            $errors[] = 'user_uid must reference an existing user.';
        }
        if ($org === null) {
            $errors[] = 'org_uid must reference an existing organization.';
        }
        if ($role === null) {
            $errors[] = 'role must be one of: ' . implode(', ', Role::values()) . '.';
        }

        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        if (MembershipModel::findByUserAndOrg($user->id, $org->id) !== null) {
            return Response::json([
                'success' => false,
                'errors' => ['User is already a member of this organization. Change their role on the organization card instead.'],
            ], 409);
        }

        $membership = new MembershipModel();
        $membership->user_id = $user->id;
        $membership->org_id = $org->id;
        $membership->role = $role;
        $membership->save();

        if ($role === Role::Owner) {
            MembershipModel::makeSoleOwner($membership);
        }

        AdminLog::record('member.added', Auth::actualUser()?->fullName() . ' added ' . $user->fullName() . ' to ' . $org->displayName() . ' as ' . $role->value, [
            'org' => $org,
            'user' => $user,
            'meta' => ['role' => $role->value],
        ]);

        return Response::json(['success' => true, 'data' => $membership->toArray()], 201);
    }

    public function update(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $membership = MembershipModel::findByUid($uid);

        if ($membership === null) {
            return Response::json(['success' => false, 'message' => 'Membership not found.'], 404);
        }

        // Only the org owner (or system admin) may change member roles.
        if (!Auth::isAdmin()) {
            $user = Auth::user();
            $userMembership = MembershipModel::findByUserAndOrg($user->id, $membership->org_id);
            if ($userMembership === null || $userMembership->role !== Role::Owner) {
                return Response::json(['success' => false, 'message' => 'Only the owner can change member roles.'], 403);
            }
        }

        if ($membership->role === Role::Owner && !Auth::isAdmin()) {
            return Response::json(['success' => false, 'message' => 'Use transfer ownership to change the owner role.'], 403);
        }

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $role = Role::tryFrom((string) ($input['role'] ?? ''));

        if ($role === null) {
            return Response::json([
                'success' => false,
                'errors' => ['role must be one of: ' . implode(', ', Role::values()) . '.'],
            ], 422);
        }

        if ($role === Role::Owner && !Auth::isAdmin()) {
            return Response::json(['success' => false, 'message' => 'Use transfer ownership to assign the owner role.'], 403);
        }

        $previous = $membership->role;
        $membership->role = $role;
        $membership->save();

        if ($role === Role::Owner) {
            MembershipModel::makeSoleOwner($membership);
        }

        // "I used to be able to do X" is a role change nobody announced. The old role is the
        // part that makes the row answer the question. Re-submitting the same role is a no-op,
        // and a log full of "changed from admin to admin" is a log people stop reading.
        $subject = $previous === $role ? null : UserModel::find($membership->user_id);
        $org = $previous === $role ? null : OrganizationModel::find($membership->org_id);
        if ($previous !== $role) AdminLog::record('member.role_changed', Auth::actualUser()?->fullName() . ' changed ' . ($subject?->fullName() ?? 'a member') . ' from ' . $previous->value . ' to ' . $role->value . ' in ' . ($org !== null ? $org->name : 'an organization'), [
            'org' => $org,
            'user' => $subject,
            'meta' => ['from' => $previous->value, 'to' => $role->value],
        ]);

        return Response::json(['success' => true, 'data' => $membership->toArray()]);
    }

    public function destroy(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $membership = MembershipModel::findByUid($uid);

        if ($membership === null) {
            return Response::json(['success' => false, 'message' => 'Membership not found.'], 404);
        }

        $org = OrganizationModel::find($membership->org_id);

        // Only org owners/admins (or system admins) may remove members.
        if ($org === null || !OrgGuard::canManageTeam($org)) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if ($membership->role === Role::Owner && !Auth::isAdmin()) {
            return Response::json(['success' => false, 'message' => 'Transfer ownership before removing the owner.'], 403);
        }

        // Read before the delete — afterwards there is no row to resolve the names from.
        $subject = UserModel::find($membership->user_id);
        $role = $membership->role;

        $membership->delete();

        AdminLog::record('member.removed', Auth::actualUser()?->fullName() . ' removed ' . ($subject?->fullName() ?? 'a member') . ' from ' . $org->name, [
            'org' => $org,
            'user' => $subject,
            'meta' => ['role' => $role->value],
        ]);

        return Response::json(['success' => true, 'message' => 'Membership removed.']);
    }
}

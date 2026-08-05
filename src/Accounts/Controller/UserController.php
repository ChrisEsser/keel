<?php

declare(strict_types=1);

namespace Keel\Accounts\Controller;

use Keel\Accounts\Model\BackupCodeModel;
use Keel\Accounts\Model\MembershipModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\RememberTokenModel;
use Keel\Accounts\Model\TwoFactorMethod;
use Keel\Accounts\Model\UserModel;
use Keel\Accounts\Service\AdminLog;
use Keel\Auth;
use Keel\Http\Errors;
use Keel\Http\Request;
use Keel\Http\Response;
use Keel\View\View;

class UserController
{
    public function __construct(private View $view, private Errors $errors) {}

    public function list(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');
        if (!Auth::isAdmin()) return Response::redirect('/dashboard');

        $html = $this->view->render('users/list', []);
        return Response::html($html);
    }

    public function get(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $search = trim($request->query('search', ''));

        $result = UserModel::paginate($page, $perPage, $search);
        $totalPages = (int) ceil($result['total'] / $perPage);

        return Response::json([
            'success' => true,
            'data' => array_map(fn(UserModel $u) => $u->toArray(), $result['items']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => $totalPages ?: 1,
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');
        if (!Auth::isAdmin()) return Response::redirect('/dashboard');

        $uid = $request->getAttribute('uid');
        $user = UserModel::findByUid($uid);

        if ($user === null) {
            return $this->errors->notFound();
        }

        $memberships = array_filter(array_map(function (MembershipModel $m) {
            $org = OrganizationModel::find($m->org_id);
            if ($org === null) {
                return null;
            }
            return $m->toArray() + ['org_name' => $org->displayName(), 'org_uid' => $org->uid];
        }, MembershipModel::findByUser($user->id)));

        $html = $this->view->render('users/show', [
            'user' => $user->toArray(),
            'memberships' => $memberships,
        ]);
        return Response::html($html);
    }

    public function edit(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');

        $uid = $request->getAttribute('uid');

        if ($uid === null) {
            if (!Auth::isAdmin()) return Response::redirect('/users');
            return Response::html($this->view->render('users/edit', [
                'user' => null,
                'cancelUrl' => '/users',
            ]));
        }

        $user = UserModel::findByUid($uid);
        if ($user === null) return $this->errors->notFound();

        if (!Auth::isAdmin() && Auth::user()->id !== $user->id) {
            return Response::redirect('/dashboard');
        }

        $cancelUrl = Auth::isAdmin() ? "/users/$uid" : '/dashboard';

        return Response::html($this->view->render('users/edit', [
            'user' => $user->toArray(),
            'cancelUrl' => $cancelUrl,
        ]));
    }

    public function apiShow(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $user = UserModel::findByUid($uid);

        if ($user === null) {
            return Response::json(['success' => false, 'message' => 'User not found.'], 404);
        }

        if (!Auth::isAdmin() && Auth::user()->id !== $user->id) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return Response::json(['success' => true, 'data' => $user->toArray()]);
    }

    public function update(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $uid = $request->getAttribute('uid');
        $user = UserModel::findByUid($uid);

        if ($user === null) {
            return Response::json(['success' => false, 'message' => 'User not found.'], 404);
        }

        if (!Auth::isAdmin() && Auth::user()->id !== $user->id) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();

        $user->first_name = trim($input['first_name'] ?? $user->first_name);
        $user->last_name = trim($input['last_name'] ?? $user->last_name);
        $user->email = trim($input['email'] ?? $user->email);

        $newPassword = trim($input['password'] ?? '');
        if ($newPassword !== '') {
            $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $errors = $user->validate();
        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        $user->save();

        return Response::json(['success' => true, 'data' => $user->toArray()]);
    }

    public function store(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();

        $user = new UserModel();
        $user->first_name = trim($input['first_name'] ?? '');
        $user->last_name = trim($input['last_name'] ?? '');
        $user->email = trim($input['email'] ?? '');

        $password = trim($input['password'] ?? '');
        if ($password !== '') {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
        }

        $errors = $user->validate();
        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        $user->save();

        return Response::json(['success' => true, 'data' => $user->toArray()], 201);
    }

    // Support-assisted 2FA recovery: an admin can clear a locked-out user's 2FA setup when
    // they've lost their device and their backup codes. There's no self-service path for this
    // by design — losing both would otherwise mean permanent lockout.
    public function disableTwoFactor(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $uid = $request->getAttribute('uid');
        $user = UserModel::findByUid($uid);

        if ($user === null) {
            return Response::json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $user->two_factor_enabled = 0;
        $user->two_factor_method = TwoFactorMethod::None;
        $user->two_factor_secret = null;
        $user->phone_number = null;
        $user->save();
        AdminLog::record('auth.2fa_disabled', Auth::actualUser()?->fullName() . ' turned off two-factor for ' . $user->fullName(), [
            'user' => $user,
            'meta' => ['by_admin' => true],
        ]);

        BackupCodeModel::deleteAllForUser($user->id);

        foreach (RememberTokenModel::findAllForUser($user->id) as $token) {
            if ($token->trust_2fa_until !== null) {
                $token->trust_2fa_until = null;
                $token->save();
            }
        }

        return Response::json(['success' => true, 'data' => $user->toArray()]);
    }

    public function destroy(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $uid = $request->getAttribute('uid');
        $user = UserModel::findByUid($uid);

        if ($user === null) {
            return Response::json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $user->delete();

        AdminLog::record('user.deleted', Auth::actualUser()?->fullName() . ' deleted the account ' . $user->email, [
            'user' => $user,
        ]);

        return Response::json(['success' => true, 'message' => 'User deleted.']);
    }
}

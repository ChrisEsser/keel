<?php

declare(strict_types=1);

namespace Keel\Accounts\Controller;

use Keel\Brand;
use Keel\Accounts\Model\InvitationModel;
use Keel\Accounts\Model\MembershipModel;
use Keel\Accounts\OrgGuard;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\Role;
use Keel\Accounts\Model\UserModel;
use Keel\Accounts\Service\AdminLog;
use Keel\Auth;
use Keel\Csrf;
use Keel\Http\Errors;
use Keel\Http\Request;
use Keel\Http\Response;
use Keel\Accounts\Service\ClientIp;
use Keel\Accounts\Service\EmailBlocks;
use Keel\Mail\AppMailer;
use Keel\Accounts\Service\PublicFormGuard;
use Keel\View\View;

class InvitationController
{
    public function __construct(
        private View $view,
        private Errors $errors,
        private AppMailer $mailer,
        private PublicFormGuard $guard,
    ) {}

    public function store(Request $request): Response
    {
        if (!Auth::check()) {
            return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $orgUid = (string) ($input['org_id'] ?? '');
        $email = trim((string) ($input['email'] ?? ''));
        $role = Role::tryFrom((string) ($input['role'] ?? ''));

        $org = $orgUid !== '' ? OrganizationModel::findByUid($orgUid) : null;
        if ($org === null) {
            return Response::json(['success' => false, 'message' => 'Organization not found.'], 404);
        }

        $orgId = $org->id;

        if (!Auth::isAdmin() && !$this->userCanManage($orgId)) {
            return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($role === null) {
            $errors[] = 'Role must be one of: ' . implode(', ', Role::values()) . '.';
        }
        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        if (InvitationModel::findPending($orgId, $email) !== null) {
            return Response::json([
                'success' => false,
                'message' => 'A pending invitation for this email already exists.',
            ], 409);
        }

        // No seat cap any more. It was a tier differentiator (1 user on Starter, 2 on Base) with no
        // cost basis behind it -- a teammate logging in costs nothing to serve -- and under itemized
        // pricing there is no tier for it to differentiate. Team members are unlimited on every
        // subscription; websites are what get paid for.

        $token = bin2hex(random_bytes(32));

        $invitation = new InvitationModel();
        $invitation->org_id = $orgId;
        $invitation->email = $email;
        $invitation->role = $role;
        $invitation->token = $token;
        $invitation->expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
        $invitation->save();

        $link = rtrim($_ENV['APP_URL'] ?? '', '/') . "/invitations/$token";

        // Reason line is overridden: the recipient is being invited precisely because they do
        // NOT have an account yet, so the default "you have an account" sentence would be wrong.
        $this->mailer->send(
            $email,
            "You've been invited to {$org->displayName()}",
            (new EmailBlocks())
                ->heading("You've been invited to {$org->displayName()}")
                ->paragraph("You've been invited to join {$org->displayName()} as {$invitation->role->label()}.")
                ->button('Accept invitation', $link)
                ->linkFallback($link)
                ->note('This link expires in 7 days.'),
            "You're receiving this because someone invited you to join {$org->displayName()} on " . Brand::name() . '.',
        );

        // The invitee usually has no account yet, so there is no user to attach — the address is
        // the identity here, and it goes in meta where the log's search reaches it.
        AdminLog::record('member.invited', Auth::actualUser()?->fullName() . ' invited ' . $email . ' to ' . $org->displayName() . ' as ' . $role->value, [
            'org' => $org,
            'meta' => ['invited_email' => $email, 'role' => $role->value, 'expires_at' => $invitation->expires_at],
        ]);

        return Response::json(['success' => true]);
    }

    public function show(Request $request): Response
    {
        $token = $request->getAttribute('token');
        $invitation = InvitationModel::findByToken($token);

        if ($invitation === null) {
            return Response::html($this->view->render('invitations/show', [
                'state' => 'not_found',
            ], 'layouts/guest'));
        }

        $org = OrganizationModel::find($invitation->org_id);

        if ($invitation->isAccepted()) {
            return Response::html($this->view->render('invitations/show', [
                'state' => 'accepted',
                'invitation' => $invitation->toArray(),
                'org' => $org?->toArray(),
            ], 'layouts/guest'));
        }

        if ($invitation->isExpired()) {
            return Response::html($this->view->render('invitations/show', [
                'state' => 'expired',
                'invitation' => $invitation->toArray(),
                'org' => $org?->toArray(),
            ], 'layouts/guest'));
        }

        if (Auth::check()) {
            $currentUser = Auth::user();
            if (strtolower($currentUser->email) === strtolower($invitation->email)) {
                $state = 'confirm';
            } else {
                $state = 'wrong_account';
            }

            return Response::html($this->view->render('invitations/show', [
                'state' => $state,
                'invitation' => $invitation->toArray(),
                'org' => $org?->toArray(),
                'currentEmail' => $currentUser->email,
            ], 'layouts/guest'));
        }

        $existingUser = UserModel::where(['email' => $invitation->email])[0] ?? null;
        $state = $existingUser !== null ? 'login' : 'register';

        return Response::html($this->view->render('invitations/show', [
            'state' => $state,
            'invitation' => $invitation->toArray(),
            'org' => $org?->toArray(),
            'token' => $token,
        ], 'layouts/guest'));
    }

    public function accept(Request $request): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login');
        }

        $token = $request->getAttribute('token');
        $invitation = InvitationModel::findByToken($token);

        if ($invitation === null) {
            return $this->errors->notFound();
        }

        // Joining an org is a state change on a logged-in session. The session cookie is
        // SameSite=Lax so a cross-site POST wouldn't carry it anyway, but the token costs nothing
        // and doesn't depend on the browser enforcing that.
        if (!Csrf::verify($request->getBody()['_csrf'] ?? null)) {
            return Response::redirect("/invitations/$token");
        }

        if ($invitation->isExpired() || $invitation->isAccepted()) {
            return Response::redirect("/invitations/$token");
        }

        $user = Auth::user();
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return Response::redirect("/invitations/$token");
        }

        $org = OrganizationModel::find($invitation->org_id);

        if ($org === null) {
            return $this->errors->notFound();
        }

        $this->joinOrg($user, $invitation, $org);

        return Response::redirect("/organizations/{$org->uid}");
    }

    /**
     * Create an account for someone who was invited but doesn't have one yet, then join them up.
     *
     * This is the only account-creation path besides /signup, and unlike /signup it sends no
     * verification email. What stands in for one: the address is taken from the INVITATION ROW and
     * never from the request body, so the proof of ownership is possession of a 32-byte token that
     * was mailed to that address. A body field would make this an open account factory, which is
     * exactly what the /register route it replaces had become.
     *
     * It also creates no organization. The invitee joins one somebody else is paying for, so this
     * cannot be used to mint build credits -- the reason the old route was worth farming.
     */
    public function createAccount(Request $request): Response
    {
        if (Auth::check()) return Response::redirect('/dashboard');

        $token = $request->getAttribute('token');
        $invitation = InvitationModel::findByToken($token);

        // show() already renders the right page for every one of these, so let it do the talking
        // rather than growing a second set of messages that could drift from the first.
        if ($invitation === null || $invitation->isExpired() || $invitation->isAccepted()) {
            return Response::redirect("/invitations/$token");
        }

        $org = OrganizationModel::find($invitation->org_id);
        if ($org === null) return $this->errors->notFound();

        // Someone who already has an account signs in instead -- taking a password here would let
        // an invitation reset the password of an existing account.
        if (UserModel::where(['email' => $invitation->email])) {
            return Response::redirect('/login?redirect=' . urlencode("/invitations/$token"));
        }

        $input = $request->getBody();
        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $password = $input['password'] ?? '';
        $confirm = $input['password_confirm'] ?? '';

        $errors = [];
        if (!Csrf::verify($input['_csrf'] ?? null)) $errors[] = 'Your session expired, please try again.';
        if ($firstName === '') $errors[] = 'First name is required.';
        // The same policy /signup applies. The retired /register asked only that a password be
        // non-empty, which meant the platform had two different answers to "what is a valid
        // password" depending on which door you came through.
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        // No email is sent from here, so unlike every other guarded action the email bucket is not
        // about protecting a mailbox -- it caps how hard one specific invitation can be hammered.
        $verdict = $this->guard->check('invite', $input, ClientIp::resolve(), $invitation->email);
        if (!$verdict->allowed && !$verdict->silent) {
            $errors[] = $verdict->message();
        }

        if (!empty($errors) || $verdict->silent) {
            return Response::html($this->view->render('invitations/show', [
                'state' => 'register',
                'invitation' => $invitation->toArray(),
                'org' => $org->toArray(),
                'token' => $token,
                'first_name' => $firstName,
                'last_name' => $lastName,
                // A honeypot/timing hit gets a plausible error rather than a success page: there is
                // no email to silently not-send here, so the deceptive path has nothing to hide.
                'error' => $errors ? implode(' ', $errors) : 'Something went wrong, please try again.',
            ], 'layouts/guest'));
        }

        $this->guard->record('invite', ClientIp::resolve(), $invitation->email);

        $user = new UserModel();
        $user->first_name = $firstName;
        $user->last_name = $lastName;
        // From the invitation, never the request. See the docblock.
        $user->email = $invitation->email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        // Start a fresh account already snoozed so the two-factor prompt doesn't interrupt someone
        // the moment they sign up -- it first appears a couple of weeks in, once they've actually
        // used the product. See UserModel::shouldPromptForTwoFactor().
        $user->two_factor_prompt_snoozed_at = gmdate('Y-m-d H:i:s');
        $user->save();

        // The first row of a customer's history, matching SignupController -- worded for the fact
        // that this one joined an existing organization rather than creating one.
        AdminLog::record('user.registered', $user->fullName() . ' created an account to join ' . $org->displayName(), [
            'org' => $org,
            'user' => $user,
            'actor' => $user,
        ]);

        Auth::login($user);

        $this->joinOrg($user, $invitation, $org);

        return Response::redirect("/organizations/{$org->uid}");
    }

    /**
     * Attach a user to the org that invited them and close the invitation.
     *
     * Shared by accept() and createAccount() so the two doors into an organization cannot drift on
     * what joining means -- the membership, the accepted stamp and the audit row travel together.
     */
    private function joinOrg(UserModel $user, InvitationModel $invitation, OrganizationModel $org): void
    {
        if (MembershipModel::findByUserAndOrg($user->id, $org->id) !== null) {
            $invitation->accept();
            return;
        }

        $membership = new MembershipModel();
        $membership->user_id = $user->id;
        $membership->org_id = $org->id;
        $membership->role = $invitation->role;
        $membership->save();

        $invitation->accept();

        AdminLog::record('member.invite_accepted', $user->fullName() . ' accepted their invitation to ' . $org->displayName(), [
            'org' => $org,
            'user' => $user,
            'actor' => $user,
            'meta' => ['role' => $invitation->role->value],
        ]);
    }

    private function userCanManage(int $orgId): bool
    {
        $org = OrganizationModel::find($orgId);

        return $org !== null && OrgGuard::canManageTeam($org);
    }
}

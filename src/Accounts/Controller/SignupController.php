<?php

declare(strict_types=1);

namespace Keel\Accounts\Controller;

use Keel\Accounts\Model\MembershipModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\PendingSignupModel;
use Keel\Accounts\Model\Role;
use Keel\Accounts\Model\UserModel;
use Keel\Accounts\Service\ClientIp;
use Keel\Accounts\Service\PublicFormGuard;
use Keel\Accounts\Service\AdminLog;
use Keel\Auth;
use Keel\Csrf;
use Keel\Http\Request;
use Keel\Http\Response;
use Keel\Accounts\Service\EmailBlocks;
use Keel\Mail\AppMailer;
use Keel\View\View;

class SignupController
{
    public function __construct(
        private View $view,
        private AppMailer $mailer,
        private PublicFormGuard $guard,
    ) {}

    public function show(Request $request): Response
    {
        if (Auth::check()) return Response::redirect('/dashboard');
        return Response::html($this->view->render('auth/signup', [], 'layouts/guest'));
    }

    public function submit(Request $request): Response
    {
        if (Auth::check()) return Response::redirect('/dashboard');

        $input = $request->getBody();
        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        $email     = strtolower(trim($input['email'] ?? ''));
        // Optional. Becomes the organization name; left blank, the org stays unnamed and shows
        // "My Workspace" everywhere (OrganizationModel::displayName).
        $company   = trim($input['company'] ?? '');

        $errors = [];
        if (!Csrf::verify($input['_csrf'] ?? null)) $errors[] = 'Your session expired, please try again.';
        if ($firstName === '') $errors[] = 'First name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';

        if (!empty($errors)) {
            return Response::html($this->view->render('auth/signup', [
                'error' => implode(' ', $errors),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'company' => $company,
            ], 'layouts/guest'));
        }

        $ip = ClientIp::resolve();

        // Each signup sends mail (a verification link, or the "you already have an account"
        // notice), so this is capped the same way password reset is.
        $verdict = $this->guard->check('signup', $input, $ip, $email);
        if (!$verdict->allowed) {
            // Bot-shaped submissions see the same "check your email" page a real one gets,
            // without an account or a message being created.
            if ($verdict->silent) {
                return Response::redirect('/signup/sent?e=' . urlencode($email));
            }

            return Response::html($this->view->render('auth/signup', [
                'error' => $verdict->message(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'company' => $company,
            ], 'layouts/guest'), 429);
        }

        $this->guard->record('signup', $ip, $email);

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');

        // Existing user — send silent "already have an account" email, same response
        $existing = UserModel::where(['email' => $email]);
        if (!empty($existing)) {
            $user = $existing[0];
            $this->mailer->send($email, 'You already have an account', (new EmailBlocks())
                ->heading('You already have an account')
                ->paragraph("Hi {$user->first_name},")
                ->paragraph('Someone (probably you) tried to sign up with this email address, but an account already exists.')
                ->button('Sign in', "{$appUrl}/login")
                ->note("If this wasn't you, you can safely ignore this email."));
            return Response::redirect('/signup/sent?e=' . urlencode($email));
        }

        // Replace any existing pending signup for this email
        foreach (PendingSignupModel::where(['email' => $email]) as $old) {
            $old->delete();
        }

        $token = bin2hex(random_bytes(32));

        $signup = new PendingSignupModel();
        $signup->first_name = $firstName;
        $signup->last_name  = $lastName;
        $signup->email      = $email;
        $signup->org_name   = $company;   // may be '' -> unnamed org, displays "My Workspace"
        $signup->token      = $token;
        $signup->expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $signup->save();

        $link = "{$appUrl}/verify/{$token}";

        $this->mailer->send($email, 'Verify your email address', (new EmailBlocks())
            ->heading('Verify your email address')
            ->paragraph("Hi {$firstName},")
            ->paragraph('Thanks for signing up. Confirm your email and set a password to finish creating your account.')
            ->button('Verify email', $link)
            ->linkFallback($link)
            ->note("This link expires in 24 hours. If you didn't sign up, you can safely ignore this email."));

        return Response::redirect('/signup/sent?e=' . urlencode($email));
    }

    public function sent(Request $request): Response
    {
        $email = $request->query('e', '');
        return Response::html($this->view->render('auth/signup-sent', [
            'email' => $email,
        ], 'layouts/guest'));
    }

    public function verify(Request $request): Response
    {
        if (Auth::check()) return Response::redirect('/dashboard');

        $token  = $request->getAttribute('token');
        $signup = PendingSignupModel::findByToken($token);

        if ($signup === null) {
            return Response::html($this->view->render('auth/verify', [
                'state' => 'not_found',
            ], 'layouts/guest'));
        }

        if ($signup->isUsed()) {
            return Response::html($this->view->render('auth/verify', [
                'state' => 'used',
            ], 'layouts/guest'));
        }

        if ($signup->isExpired()) {
            return Response::html($this->view->render('auth/verify', [
                'state'  => 'expired',
                'token'  => $token,
            ], 'layouts/guest'));
        }

        return Response::html($this->view->render('auth/verify', [
            'state'      => 'valid',
            'token'      => $token,
            'first_name' => $signup->first_name,
        ], 'layouts/guest'));
    }

    public function complete(Request $request): Response
    {
        if (Auth::check()) return Response::redirect('/dashboard');

        $token  = $request->getAttribute('token');
        $signup = PendingSignupModel::findByToken($token);

        if ($signup === null || $signup->isUsed() || $signup->isExpired()) {
            return Response::redirect('/signup');
        }

        $input   = $request->getBody();
        $password = $input['password'] ?? '';
        $confirm  = $input['password_confirm'] ?? '';

        $errors = [];
        if (!Csrf::verify($input['_csrf'] ?? null)) $errors[] = 'Your session expired, please try again.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (!empty($errors)) {
            return Response::html($this->view->render('auth/verify', [
                'state'      => 'valid',
                'token'      => $token,
                'first_name' => $signup->first_name,
                'error'      => implode(' ', $errors),
            ], 'layouts/guest'));
        }

        $user = new UserModel();
        $user->first_name = $signup->first_name;
        $user->last_name  = $signup->last_name;
        $user->email      = $signup->email;
        $user->password   = password_hash($password, PASSWORD_DEFAULT);
        $user->save();

        $org = new OrganizationModel();
        $org->name    = $signup->org_name;   // '' when no company was given -> displays "My Workspace"
        $org->email   = $signup->email;
        $org->save();

        $membership = new MembershipModel();
        $membership->user_id = $user->id;
        $membership->org_id  = $org->id;
        $membership->role    = Role::Owner;
        $membership->save();

        $signup->used_at = date('Y-m-d H:i:s');
        $signup->save();

        // The first row of a customer's history: everything else about them is read against this.
        AdminLog::record('user.registered', $user->fullName() . ' created an account and the organization ' . $org->displayName(), [
            'org' => $org,
            'user' => $user,
            'actor' => $user,
        ]);

        Auth::login($user);

        return Response::redirect("/organizations/{$org->uid}/dashboard");
    }

    public function resend(Request $request): Response
    {
        $token  = $request->getAttribute('token');
        $signup = PendingSignupModel::findByToken($token);

        if ($signup === null || $signup->isUsed()) {
            return Response::redirect('/signup');
        }

        if (!Csrf::verify($request->getBody()['_csrf'] ?? null)) {
            return Response::redirect('/signup/sent?e=' . urlencode($signup->email));
        }

        // Holding a valid token doesn't entitle the holder to unlimited mail: without a cap this
        // is a loop that sends to the pending address as fast as it can be called.
        $ip = ClientIp::resolve();
        $verdict = $this->guard->check('resend', $request->getBody(), $ip, $signup->email);
        if (!$verdict->allowed) {
            // Same confirmation either way — nothing sent.
            return Response::redirect('/signup/sent?e=' . urlencode($signup->email));
        }

        $this->guard->record('resend', $ip, $signup->email);

        $newToken = bin2hex(random_bytes(32));
        $signup->token      = $newToken;
        $signup->expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $signup->save();

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $link   = "{$appUrl}/verify/{$newToken}";

        $this->mailer->send($signup->email, 'Verify your email address', (new EmailBlocks())
            ->heading('Verify your email address')
            ->paragraph("Hi {$signup->first_name},")
            ->paragraph("Here's your new verification link.")
            ->button('Verify email', $link)
            ->linkFallback($link)
            ->note('This link expires in 24 hours.'));

        return Response::redirect('/signup/sent?e=' . urlencode($signup->email));
    }
}

<?php

declare(strict_types=1);

namespace Framework\Accounts\Controller;

use Framework\Accounts\Model\BackupCodeModel;
use Framework\Accounts\Model\LoginChallengeModel;
use Framework\Accounts\Model\PasswordResetModel;
use Framework\Accounts\Model\TwoFactorMethod;
use Framework\Accounts\Model\UserModel;
use Framework\Accounts\Service\AdminLog;
use Framework\Accounts\Service\ClientIp;
use Framework\Accounts\Service\PublicFormGuard;
use Framework\Auth;
use Framework\Csrf;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Accounts\Service\EmailBlocks;
use Framework\Mail\AppMailer;
use Framework\Security\Crypto;
use Framework\Security\Totp;
use Framework\Sms\Sms;
use Framework\View\View;

class AuthController
{
    // Escalating cooldown after consecutive failed logins, indexed by the number of failures
    // recorded so far; the last value repeats forever. Index 0 is unused padding (the count is
    // incremented before lookup), so three failures are free and the fourth starts the ladder.
    //
    // This replaces a flat "5 strikes, locked for 15 minutes". That lockout barely inconvenienced
    // an attacker (who moves to the next account) while handing anyone who knows your email a way
    // to lock you out on demand — fail five times and the real owner is shut out for a quarter of
    // an hour. Escalating delays invert that: the first three fumbles cost a real user nothing,
    // and someone guessing sequentially is throttled to roughly one attempt a minute. Crucially
    // the account is never fully closed — the right password always works once the wait elapses,
    // so the worst a griefer can inflict is a 60-second pause instead of 15 minutes.
    //
    // The cooldown is enforced by refusing early, never by sleep() — holding a PHP worker open for
    // the duration would turn this into a way to exhaust the pool.
    private const LOGIN_DELAYS_SECONDS = [0, 0, 0, 0, 5, 15, 30, 60];

    // Consecutive-failure count resets once the last failure is this old, so yesterday's typos
    // don't put today's first attempt at the top of the ladder.
    private const LOGIN_ATTEMPT_DECAY_MINUTES = 15;
    private const MAX_PIN_ATTEMPTS = 5;
    private const PIN_LOCKOUT_MINUTES = 15;
    private const MAX_2FA_ATTEMPTS = 5;
    private const CHALLENGE_MINUTES = 10;
    private const SMS_RESEND_COOLDOWN_SECONDS = 60;
    private const RESET_TOKEN_MINUTES = 60;

    // A real bcrypt hash of a value nobody can supply, used to spend the same ~100ms on a
    // nonexistent account as on a real one. Without it, "no such user" returns measurably faster
    // than "wrong password" and the login form becomes an account-enumeration oracle.
    private const DUMMY_PASSWORD_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1jlnQpQoJ.C';

    public function __construct(
        private View $view,
        private Sms $sms,
        private AppMailer $mailer,
        private PublicFormGuard $guard,
    ) {}

    public function login(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect('/dashboard');
        }

        if (Auth::pinPendingUser() !== null) {
            return Response::redirect('/login/pin');
        }

        if ($this->currentChallenge() !== null) {
            return Response::redirect('/login/2fa');
        }

        $redirect = $_GET['redirect'] ?? '';

        return Response::html($this->view->render('auth/login', [
            'redirect' => $redirect,
            'reset' => ($_GET['reset'] ?? '') === '1',
        ], 'layouts/guest'));
    }

    public function doLogin(Request $request): Response
    {
        $input = $request->getBody();
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $redirect = $input['redirect'] ?? '';

        if (!Csrf::verify($input['_csrf'] ?? null)) {
            return Response::html(
                $this->view->render('auth/login', ['error' => 'Your session expired, please try again.', 'redirect' => $redirect], 'layouts/guest'),
                400
            );
        }

        $ip = ClientIp::resolve();

        // Bot checks + per-IP/per-email limiting, on top of the per-account lockout below. The
        // account lockout alone never fires against spraying (one attempt each across thousands of
        // addresses), and on its own it hands anyone a way to lock a known user out on demand.
        $verdict = $this->guard->check('login', $input, $ip, $email);
        if (!$verdict->allowed) {
            // A honeypot/timing hit gets the ordinary "invalid credentials" wording rather than a
            // distinct one — indistinguishable from a normal failed login, so there's nothing to
            // tune against. There's no "silent success" for login the way there is for signup.
            return Response::html(
                $this->view->render('auth/login', [
                    'error' => $verdict->silent ? 'Invalid email or password.' : $verdict->message(),
                    'redirect' => $redirect,
                ], 'layouts/guest'),
                $verdict->silent ? 401 : 429
            );
        }

        $users = UserModel::where(['email' => $email]);
        $user = $users[0] ?? null;

        if ($user !== null && $user->locked_until !== null && strtotime($user->locked_until) > time()) {
            $wait = strtotime($user->locked_until) - time();
            // The single most common "I can't get in" cause, and invisible to the customer beyond
            // a wait message they've usually already dismissed by the time they call.
            AdminLog::record('auth.locked_out', $user->fullName() . ' was blocked by the sign-in cooldown', [
                'user' => $user,
                'system' => true,
                'meta' => ['seconds_remaining' => $wait, 'failed_attempts' => $user->failed_login_attempts],
            ]);
            return Response::html(
                $this->view->render('auth/login', [
                    'error' => 'Too many failed attempts. Please try again in ' . self::humanWait($wait) . '.',
                    'redirect' => $redirect,
                ], 'layouts/guest'),
                429
            );
        }

        // Always spend the cost of a hash comparison, even with no such account — see
        // DUMMY_PASSWORD_HASH.
        $passwordOk = $user !== null
            ? password_verify($password, $user->password)
            : self::burnPasswordCompare($password);

        if (!$passwordOk) {
            if ($user !== null) {
                // locked_until doubles as "when the last failure's cooldown ran out", which for a
                // zero-delay early failure is the failure time itself — close enough to age the
                // streak off without carrying a second timestamp column.
                $lastFailure = $user->locked_until !== null ? strtotime($user->locked_until) : 0;
                if ($lastFailure > 0 && time() - $lastFailure > self::LOGIN_ATTEMPT_DECAY_MINUTES * 60) {
                    $user->failed_login_attempts = 0;
                }

                $user->failed_login_attempts++;
                $step = min($user->failed_login_attempts, count(self::LOGIN_DELAYS_SECONDS) - 1);
                $user->locked_until = date('Y-m-d H:i:s', time() + self::LOGIN_DELAYS_SECONDS[$step]);
                $user->save();
            }

            // Only failures count toward the limit, so ordinary use never accumulates.
            $this->guard->record('login', $ip, $email);

            // Recorded for a real account only: logging attempts against addresses that don't
            // exist would fill the log with spray traffic and teach it nothing about customers.
            if ($user !== null) {
                AdminLog::record('auth.login_failed', 'Failed sign-in for ' . $user->email, [
                    'user' => $user,
                    'system' => true,
                    'meta' => ['failed_attempts' => $user->failed_login_attempts],
                ]);
            }

            return Response::html(
                $this->view->render('auth/login', ['error' => 'Invalid email or password.', 'redirect' => $redirect], 'layouts/guest'),
                401
            );
        }

        // A genuine login clears the slate, so a user who fumbled their password a few times
        // isn't left carrying those failures.
        $this->guard->forget('login', $ip, $email);

        $remember = !empty($input['remember']);

        if ((bool) $user->two_factor_enabled && !Auth::currentDeviceTrustedFor2fa()) {
            return $this->startTwoFactorChallenge($user, $remember, $redirect);
        }

        Auth::finishLogin($user, $remember);
        AdminLog::record('auth.login', $user->fullName() . ' signed in', ['user' => $user, 'actor' => $user]);

        if ($redirect !== '' && str_starts_with($redirect, '/')) {
            return Response::redirect($redirect);
        }

        return Response::redirect('/dashboard');
    }

    // Verify against a fixed hash and throw the answer away. Without this, "no such account"
    // returns in microseconds while a real address spends ~100ms in bcrypt, and the difference is
    // measurable over the network -- which turns the login form into an account-enumeration
    // oracle. Always returns false: it exists for the time it takes, not for the answer.
    private static function burnPasswordCompare(string $password): bool
    {
        password_verify($password, self::DUMMY_PASSWORD_HASH);

        return false;
    }

    // "5 seconds" / "1 minute" / "2 minutes". Only ever shown for a cooldown the user is already
    // serving, so it rounds up — telling someone to wait 0 seconds when they still have to is worse
    // than overstating by one.
    private static function humanWait(int $seconds): string
    {
        if ($seconds < 60) {
            $seconds = max(1, $seconds);
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }

        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    private function startTwoFactorChallenge(UserModel $user, bool $remember, string $redirect): Response
    {
        $challenge = new LoginChallengeModel();
        $challenge->user_id = $user->id;
        $challenge->method = $user->two_factor_method->value;
        $challenge->remember_requested = $remember ? 1 : 0;
        $challenge->expires_at = date('Y-m-d H:i:s', time() + self::CHALLENGE_MINUTES * 60);
        $challenge->created_at = date('Y-m-d H:i:s');

        if ($user->two_factor_method === TwoFactorMethod::Sms) {
            $challenge->code_hash = hash('sha256', $this->sendSmsCode($user));
        }

        $challenge->save();
        $_SESSION['login_challenge_uid'] = $challenge->uid;

        $query = $redirect !== '' ? ('?redirect=' . urlencode($redirect)) : '';
        return Response::redirect('/login/2fa' . $query);
    }

    private function sendSmsCode(UserModel $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->sms->send($user->phone_number ?? '', "Your verification code is $code");
        return $code;
    }

    private function currentChallenge(): ?LoginChallengeModel
    {
        $uid = $_SESSION['login_challenge_uid'] ?? null;
        if ($uid === null) {
            return null;
        }

        $challenge = LoginChallengeModel::findByUid($uid);
        if ($challenge === null || $challenge->isExpired()) {
            unset($_SESSION['login_challenge_uid']);
            return null;
        }

        return $challenge;
    }

    public function showTwoFactor(Request $request): Response
    {
        $challenge = $this->currentChallenge();
        if ($challenge === null) {
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('auth/two-factor', [
            'method' => $challenge->method,
            'redirect' => $_GET['redirect'] ?? '',
        ], 'layouts/guest'));
    }

    public function doVerifyTwoFactor(Request $request): Response
    {
        $input = $request->getBody();
        $redirect = $input['redirect'] ?? '';
        $code = trim((string) ($input['code'] ?? ''));
        $backupCode = trim((string) ($input['backup_code'] ?? ''));
        $trustDevice = !empty($input['trust_device']);

        $challenge = $this->currentChallenge();
        if ($challenge === null) {
            return Response::redirect('/login');
        }

        if (!Csrf::verify($input['_csrf'] ?? null)) {
            return Response::html($this->view->render('auth/two-factor', [
                'method' => $challenge->method,
                'redirect' => $redirect,
                'error' => 'Your session expired, please try again.',
            ], 'layouts/guest'), 400);
        }

        $user = UserModel::find($challenge->user_id);
        if ($user === null) {
            unset($_SESSION['login_challenge_uid']);
            return Response::redirect('/login');
        }

        if ($challenge->attempts >= self::MAX_2FA_ATTEMPTS) {
            $challenge->delete();
            unset($_SESSION['login_challenge_uid']);

            return Response::html($this->view->render('auth/login', [
                'error' => 'Too many incorrect codes. Please sign in again.',
                'redirect' => $redirect,
            ], 'layouts/guest'), 429);
        }

        $verified = false;

        if ($backupCode !== '') {
            foreach (BackupCodeModel::findUnusedForUser($user->id) as $stored) {
                if (password_verify($backupCode, $stored->code_hash)) {
                    $stored->used_at = date('Y-m-d H:i:s');
                    $stored->save();
                    $verified = true;
                    break;
                }
            }
        } elseif ($challenge->method === 'totp') {
            $secret = $user->two_factor_secret !== null ? Crypto::decrypt($user->two_factor_secret) : null;
            $verified = $secret !== null && Totp::verify($secret, $code);
        } elseif ($challenge->method === 'sms') {
            $verified = $challenge->code_hash !== null && hash_equals($challenge->code_hash, hash('sha256', $code));
        }

        if (!$verified) {
            $challenge->attempts++;
            $challenge->save();

            return Response::html($this->view->render('auth/two-factor', [
                'method' => $challenge->method,
                'redirect' => $redirect,
                'error' => 'Incorrect code.',
            ], 'layouts/guest'), 401);
        }

        $remember = (bool) $challenge->remember_requested;
        $challenge->delete();
        unset($_SESSION['login_challenge_uid']);

        Auth::finishLogin($user, $remember, $trustDevice);

        if ($redirect !== '' && str_starts_with($redirect, '/')) {
            return Response::redirect($redirect);
        }

        return Response::redirect('/dashboard');
    }

    public function resendTwoFactorCode(Request $request): Response
    {
        $challenge = $this->currentChallenge();
        if ($challenge === null || $challenge->method !== 'sms') {
            return Response::redirect('/login');
        }

        if (time() - strtotime($challenge->created_at) < self::SMS_RESEND_COOLDOWN_SECONDS) {
            return Response::redirect('/login/2fa');
        }

        $user = UserModel::find($challenge->user_id);
        if ($user === null) {
            unset($_SESSION['login_challenge_uid']);
            return Response::redirect('/login');
        }

        $challenge->code_hash = hash('sha256', $this->sendSmsCode($user));
        $challenge->created_at = date('Y-m-d H:i:s');
        $challenge->expires_at = date('Y-m-d H:i:s', time() + self::CHALLENGE_MINUTES * 60);
        $challenge->attempts = 0;
        $challenge->save();

        return Response::redirect('/login/2fa');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        return Response::redirect('/login');
    }

    public function forgotPassword(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->view->render('auth/forgot-password', [], 'layouts/guest'));
    }

    public function doForgotPassword(Request $request): Response
    {
        $input = $request->getBody();
        $email = strtolower(trim($input['email'] ?? ''));

        if (!Csrf::verify($input['_csrf'] ?? null)) {
            return Response::html($this->view->render('auth/forgot-password', [
                'error' => 'Your session expired, please try again.',
                'email' => $email,
            ], 'layouts/guest'), 400);
        }

        $ip = ClientIp::resolve();

        // Every request here can put a message in someone's inbox, so it's capped per IP and per
        // target address (see PublicFormGuard::LIMITS). Uncapped, this endpoint is a mail cannon
        // pointed at any address the caller names, and the bounces land on our sending domain.
        $verdict = $this->guard->check('pwreset', $input, $ip, $email);
        if (!$verdict->allowed) {
            // Bot-shaped submissions get the same confirmation a real one gets — no mail sent.
            if ($verdict->silent) {
                return Response::redirect('/login/forgot-password/sent?e=' . urlencode($email));
            }

            return Response::html($this->view->render('auth/forgot-password', [
                'error' => $verdict->message(),
                'email' => $email,
            ], 'layouts/guest'), 429);
        }

        $this->guard->record('pwreset', $ip, $email);

        $user = UserModel::where(['email' => $email])[0] ?? null;

        // Always respond the same way whether or not the account exists, so this endpoint
        // can't be used to enumerate registered email addresses.
        if ($user !== null) {
            foreach (PasswordResetModel::where(['user_id' => $user->id]) as $old) {
                $old->delete();
            }

            $token = bin2hex(random_bytes(32));

            $reset = new PasswordResetModel();
            $reset->user_id = $user->id;
            // Only the digest is persisted; $token itself goes out in the email below and is
            // never recoverable from the row.
            $reset->token = PasswordResetModel::hashToken($token);
            $reset->expires_at = date('Y-m-d H:i:s', time() + self::RESET_TOKEN_MINUTES * 60);
            $reset->save();

            // "I never got the reset email" is a support call; this is the record that it was
            // asked for, when, and from where.
            AdminLog::record('auth.password_reset_requested', 'Password reset requested for ' . $user->email, [
                'user' => $user,
                'system' => true,
            ]);

            $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
            $link = "{$appUrl}/login/reset/{$token}";

            $this->mailer->send($email, 'Reset your password', (new EmailBlocks())
                ->heading('Reset your password')
                ->paragraph("Hi {$user->first_name},")
                ->paragraph('We received a request to reset the password on your account. Choose a new one using the button below.')
                ->button('Choose a new password', $link)
                ->linkFallback($link)
                ->note("This link expires in 1 hour. If you didn't request a password reset, you can safely ignore this email."));
        }

        return Response::redirect('/login/forgot-password/sent?e=' . urlencode($email));
    }

    public function forgotPasswordSent(Request $request): Response
    {
        return Response::html($this->view->render('auth/forgot-password-sent', [
            'email' => $request->query('e', ''),
        ], 'layouts/guest'));
    }

    public function resetPassword(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect('/dashboard');
        }

        $token = $request->getAttribute('token');
        $reset = PasswordResetModel::findByToken($token);

        if ($reset === null) {
            return Response::html($this->view->render('auth/reset-password', ['state' => 'not_found'], 'layouts/guest'));
        }
        if ($reset->isUsed()) {
            return Response::html($this->view->render('auth/reset-password', ['state' => 'used'], 'layouts/guest'));
        }
        if ($reset->isExpired()) {
            return Response::html($this->view->render('auth/reset-password', ['state' => 'expired'], 'layouts/guest'));
        }

        return Response::html($this->view->render('auth/reset-password', [
            'state' => 'valid',
            'token' => $token,
        ], 'layouts/guest'));
    }

    public function doResetPassword(Request $request): Response
    {
        $token = $request->getAttribute('token');
        $reset = PasswordResetModel::findByToken($token);

        if ($reset === null || $reset->isUsed() || $reset->isExpired()) {
            return Response::redirect('/login/forgot-password');
        }

        $input = $request->getBody();
        $password = $input['password'] ?? '';
        $confirm = $input['password_confirm'] ?? '';

        $errors = [];
        if (!Csrf::verify($input['_csrf'] ?? null)) $errors[] = 'Your session expired, please try again.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (!empty($errors)) {
            return Response::html($this->view->render('auth/reset-password', [
                'state' => 'valid',
                'token' => $token,
                'error' => implode(' ', $errors),
            ], 'layouts/guest'));
        }

        $user = UserModel::find($reset->user_id);
        if ($user === null) {
            return Response::redirect('/login/forgot-password');
        }

        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        $reset->used_at = date('Y-m-d H:i:s');
        $reset->save();

        // A password reset is a strong signal the old credential may have been compromised —
        // sign every other device out so a stolen session/remember-cookie can't linger.
        Auth::revokeAllRememberTokens($user->id);

        AdminLog::record('auth.password_reset_completed', $user->fullName() . ' completed a password reset', [
            'user' => $user,
            'system' => true,
            'meta' => ['other_sessions_revoked' => true],
        ]);

        return Response::redirect('/login?reset=1');
    }

    public function showPin(Request $request): Response
    {
        $user = Auth::pinPendingUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        if ($user->pin_locked_until !== null && strtotime($user->pin_locked_until) > time()) {
            Auth::clearPinPending();
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('auth/pin', [
            'first_name' => $user->first_name,
            'redirect' => $_GET['redirect'] ?? '',
        ], 'layouts/guest'));
    }

    public function doVerifyPin(Request $request): Response
    {
        $input = $request->getBody();
        $pin = (string) ($input['pin'] ?? '');
        $redirect = $input['redirect'] ?? '';

        $user = Auth::pinPendingUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        if (!Csrf::verify($input['_csrf'] ?? null)) {
            return Response::html($this->view->render('auth/pin', [
                'first_name' => $user->first_name,
                'redirect' => $redirect,
                'error' => 'Your session expired, please try again.',
            ], 'layouts/guest'), 400);
        }

        if ($user->pin_locked_until !== null && strtotime($user->pin_locked_until) > time()) {
            Auth::clearPinPending();
            return Response::redirect('/login');
        }

        if ($user->pin_hash === null || !password_verify($pin, $user->pin_hash)) {
            $user->failed_pin_attempts++;
            if ($user->failed_pin_attempts >= self::MAX_PIN_ATTEMPTS) {
                $user->pin_locked_until = date('Y-m-d H:i:s', time() + self::PIN_LOCKOUT_MINUTES * 60);
                $user->failed_pin_attempts = 0;
                $user->save();
                Auth::clearPinPending();

                return Response::html($this->view->render('auth/login', [
                    'error' => 'Too many incorrect PIN attempts. Please sign in with your password.',
                    'redirect' => $redirect,
                ], 'layouts/guest'), 429);
            }
            $user->save();

            return Response::html($this->view->render('auth/pin', [
                'first_name' => $user->first_name,
                'redirect' => $redirect,
                'error' => 'Incorrect PIN.',
            ], 'layouts/guest'), 401);
        }

        Auth::finishPinLogin($user);

        if ($redirect !== '' && str_starts_with($redirect, '/')) {
            return Response::redirect($redirect);
        }

        return Response::redirect('/dashboard');
    }

    public function forgetDevice(Request $request): Response
    {
        Auth::logout();
        return Response::redirect('/login');
    }

    public function impersonate(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $id = (int) $request->getAttribute('id');
        $user = UserModel::find($id);

        if ($user === null) {
            return Response::json(['success' => false, 'message' => "User $id not found."], 404);
        }

        AdminLog::record('admin.impersonation_started', Auth::actualUser()?->fullName() . ' started impersonating ' . $user->fullName(), [
            'user' => $user,
        ]);
        Auth::impersonate($id);
        return Response::json(['success' => true]);
    }

    public function quickImpersonate(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $identifier = trim((string) ($input['identifier'] ?? ''));

        if ($identifier === '') {
            return Response::json(['success' => false, 'message' => 'No identifier provided.'], 422);
        }

        // Try by ID if numeric, otherwise by email
        $user = is_numeric($identifier)
            ? UserModel::find((int) $identifier)
            : (UserModel::where(['email' => $identifier])[0] ?? null);

        if ($user === null) {
            return Response::json(['success' => false, 'message' => "No user found for \"$identifier\"."], 404);
        }

        AdminLog::record('admin.impersonation_started', Auth::actualUser()?->fullName() . ' started impersonating ' . $user->fullName(), [
            'user' => $user,
            'meta' => ['identifier' => $identifier],
        ]);
        Auth::impersonate($user->id);
        return Response::json(['success' => true]);
    }

    public function stopImpersonating(Request $request): Response
    {
        // Recorded BEFORE the session flips back, so the row still carries who was being
        // impersonated rather than just who stopped.
        $subject = Auth::user();
        if (Auth::isImpersonating() && $subject !== null) {
            AdminLog::record('admin.impersonation_stopped', Auth::actualUser()?->fullName() . ' stopped impersonating ' . $subject->fullName(), [
                'user' => $subject,
            ]);
        }
        Auth::stopImpersonating();
        return Response::json(['success' => true]);
    }
}

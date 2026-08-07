<?php

declare(strict_types=1);

namespace Framework;

use Framework\Accounts\Model\RememberTokenModel;
use Framework\Accounts\Model\UserModel;
use Framework\Accounts\Service\ClientIp;

final class Auth
{
    private const REMEMBER_COOKIE = 'remember_token';
    private const REMEMBER_DAYS = 30;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login(UserModel $user): void
    {
        static::finishLogin($user, false, false);
    }

    // The single place a platform login is fully completed -- establishes the session,
    // clears any lockout state, and (when requested) issues a remember-me device cookie.
    public static function finishLogin(UserModel $user, bool $remember = false, bool $trustDevice = false): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;
        unset($_SESSION['impersonate_id'], $_SESSION['pin_pending_user_id']);

        // Consumed once by DashboardController, which decides whether this sign-in is a good
        // moment to offer two-factor setup. Set here rather than in each login path so the
        // password, post-2FA, PIN and remember-me routes are all covered by one line.
        $_SESSION['post_login'] = true;

        $needsSave = false;
        if ($user->failed_login_attempts !== 0 || $user->locked_until !== null) {
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $needsSave = true;
        }
        if ($user->failed_pin_attempts !== 0 || $user->pin_locked_until !== null) {
            $user->failed_pin_attempts = 0;
            $user->pin_locked_until = null;
            $needsSave = true;
        }
        if ($needsSave) {
            $user->save();
        }

        if ($remember || $trustDevice) {
            self::issueRememberToken($user, $trustDevice);
        }
    }

    public static function logout(): void
    {
        self::revokeCurrentDeviceToken();
        self::clearRememberCookie();
        session_destroy();
    }

    // The active user — impersonated user if impersonating, otherwise the real user.
    public static function user(): ?UserModel
    {
        $id = $_SESSION['impersonate_id'] ?? $_SESSION['user_id'] ?? null;
        return $id ? UserModel::find((int) $id) : null;
    }

    // Always the real logged-in user, never the impersonated one.
    public static function actualUser(): ?UserModel
    {
        $id = $_SESSION['user_id'] ?? null;
        return $id ? UserModel::find((int) $id) : null;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    // Checks the real user's is_admin flag — impersonation cannot grant admin access.
    public static function isAdmin(): bool
    {
        $user = static::actualUser();
        return $user !== null && (bool) $user->is_admin;
    }

    // Checks the effective user's is_admin flag — returns the impersonated user's status when impersonating.
    // Use this for UI rendering only; never for access control.
    public static function effectiveIsAdmin(): bool
    {
        $user = static::user();
        return $user !== null && (bool) $user->is_admin;
    }

    public static function isImpersonating(): bool
    {
        return isset($_SESSION['impersonate_id']);
    }

    public static function impersonate(int $userId): void
    {
        $_SESSION['impersonate_id'] = $userId;
    }

    public static function stopImpersonating(): void
    {
        unset($_SESSION['impersonate_id']);
    }

    // -------------------------------------------------------------------------
    // Remember-me (persistent login)
    // -------------------------------------------------------------------------

    // Called once per request when there's no active session. Resolves the remember-me
    // cookie (if any) and either establishes a full session or returns false.
    public static function attemptRememberLogin(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookie, 2);
        $token = RememberTokenModel::findBySelector($selector);

        if ($token === null) {
            self::clearRememberCookie();
            return false;
        }

        if ($token->isExpired()) {
            $token->delete();
            self::clearRememberCookie();
            return false;
        }

        if (!hash_equals($token->validator_hash, hash('sha256', $validator))) {
            // Selector matched but the validator didn't -- a stale/replayed cookie, treat
            // as a stolen token and revoke every device for this user.
            static::revokeAllRememberTokens($token->user_id);
            self::clearRememberCookie();
            return false;
        }

        $user = UserModel::find($token->user_id);
        if ($user === null) {
            $token->delete();
            self::clearRememberCookie();
            return false;
        }

        if ($user->pin_enabled) {
            // Device is recognized, but require the quick-unlock PIN before establishing
            // a full session -- see pinPendingUser()/finishPinLogin(). The selector is kept
            // so a successful PIN entry rotates this same device's token instead of issuing
            // a duplicate one. Per design, a correct PIN skips 2FA too (device + PIN is
            // treated as sufficient), so no 2FA-trust check is needed on this branch.
            $_SESSION['pin_pending_user_id'] = $user->id;
            $_SESSION['pin_pending_selector'] = $selector;
            return false;
        }

        if ($user->two_factor_enabled && !$token->isTrustedFor2fa()) {
            // Cookie is valid, but this device was never (or is no longer) granted a 2FA-skip
            // window -- fall through to a normal password + 2FA login rather than silently
            // resuming. Otherwise "remember me" alone would permanently bypass 2FA.
            return false;
        }

        // Rotate the validator on every successful use.
        $newValidator = bin2hex(random_bytes(32));
        $token->validator_hash = hash('sha256', $newValidator);
        $token->last_used_at = date('Y-m-d H:i:s');
        $token->save();
        self::setRememberCookie($selector, $newValidator, $token->expires_at);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;

        return true;
    }

    // The user awaiting PIN entry on a recognized device, or null if there is none pending.
    public static function pinPendingUser(): ?UserModel
    {
        $id = $_SESSION['pin_pending_user_id'] ?? null;
        return $id ? UserModel::find((int) $id) : null;
    }

    public static function clearPinPending(): void
    {
        unset($_SESSION['pin_pending_user_id'], $_SESSION['pin_pending_selector']);
    }

    // Completes login after a correct PIN entry. Rotates the already-trusted device's
    // token (rather than issuing a new one) so the device list doesn't accumulate a
    // duplicate row every time this device unlocks.
    public static function finishPinLogin(UserModel $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;

        if ($user->failed_pin_attempts !== 0 || $user->pin_locked_until !== null) {
            $user->failed_pin_attempts = 0;
            $user->pin_locked_until = null;
            $user->save();
        }

        $selector = $_SESSION['pin_pending_selector'] ?? null;
        unset($_SESSION['pin_pending_user_id'], $_SESSION['pin_pending_selector']);

        if ($selector !== null) {
            $token = RememberTokenModel::findBySelector($selector);
            if ($token !== null && $token->user_id === $user->id) {
                $newValidator = bin2hex(random_bytes(32));
                $token->validator_hash = hash('sha256', $newValidator);
                $token->last_used_at = date('Y-m-d H:i:s');
                $token->save();
                self::setRememberCookie($selector, $newValidator, $token->expires_at);
            }
        }
    }

    // Whether the current browser presents a remember-me cookie for this user that was
    // explicitly granted a 2FA-skip window ("trust this device for 30 days" at 2FA verify).
    public static function currentDeviceTrustedFor2fa(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookie, 2);
        $token = RememberTokenModel::findBySelector($selector);

        if ($token === null || $token->isExpired()) {
            return false;
        }
        if (!hash_equals($token->validator_hash, hash('sha256', $validator))) {
            return false;
        }

        return $token->isTrustedFor2fa();
    }

    public static function revokeRememberToken(int $tokenId): void
    {
        RememberTokenModel::destroy($tokenId);
    }

    public static function revokeAllRememberTokens(int $userId): void
    {
        RememberTokenModel::deleteAllForUser($userId);
    }

    private static function revokeCurrentDeviceToken(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return;
        }

        [$selector] = explode(':', $cookie, 2);
        RememberTokenModel::findBySelector($selector)?->delete();
    }

    private static function issueRememberToken(UserModel $user, bool $trustDevice): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400);

        $token = new RememberTokenModel();
        $token->user_id = $user->id;
        $token->selector = $selector;
        $token->validator_hash = hash('sha256', $validator);
        $token->expires_at = $expiresAt;
        $token->trust_2fa_until = $trustDevice ? $expiresAt : null;
        $token->device_label = self::deviceLabel();
        $token->ip_address = ClientIp::resolve() ?: null;
        $token->last_used_at = date('Y-m-d H:i:s');
        $token->created_at = date('Y-m-d H:i:s');
        $token->save();

        self::setRememberCookie($selector, $validator, $expiresAt);
    }

    // Short, best-effort device label for the "manage devices" list -- not a full UA parser.
    private static function deviceLabel(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return "$browser on $os";
    }

    private static function setRememberCookie(string $selector, string $validator, string $expiresAt): void
    {
        setcookie(self::REMEMBER_COOKIE, "$selector:$validator", [
            'expires'  => strtotime($expiresAt),
            'path'     => '/',
            'secure'   => str_starts_with($_ENV['APP_URL'] ?? '', 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => str_starts_with($_ENV['APP_URL'] ?? '', 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Framework\Accounts\Controller;

use Framework\Accounts\Model\BackupCodeModel;
use Framework\Accounts\Model\LoginChallengeModel;
use Framework\Accounts\Model\RememberTokenModel;
use Framework\Accounts\Model\TwoFactorMethod;
use Framework\Accounts\Model\UserModel;
use Framework\Accounts\Service\AdminLog;
use Framework\Auth;
use Framework\Brand;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Security\Crypto;
use Framework\Security\Totp;
use Framework\Sms\Sms;

// Self-service account security JSON API (2FA, PIN, trusted devices), driven by the
// Security tab of the User Settings modal (views/partials/user-settings-modal.php).
// Always operates on Auth::actualUser() -- never the impersonated user, and never another
// user's account even if the modal happens to be open for one (the frontend hides this
// section in that case, but every endpoint is self-scoped regardless).
class AccountController
{
    private const BACKUP_CODE_COUNT = 10;
    private const CHALLENGE_MINUTES = 10;

    public function __construct(private Sms $sms) {}

    public function status(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $user = Auth::actualUser();

        $devices = array_map(fn(RememberTokenModel $t) => [
            'id' => $t->id,
            'device_label' => $t->device_label,
            'last_used_at' => $t->last_used_at,
            'expires_at' => $t->expires_at,
            'trust_2fa_until_active' => $t->isTrustedFor2fa(),
        ], RememberTokenModel::findAllForUser($user->id));

        return Response::json(['success' => true, 'data' => [
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'two_factor_method' => $user->two_factor_method->value,
            'two_factor_method_label' => $user->two_factor_method->label(),
            'pin_enabled' => (bool) $user->pin_enabled,
            'sms_available' => self::smsAvailable(),
            'encryption_configured' => ($_ENV['APP_ENCRYPTION_KEY'] ?? '') !== '',
            'devices' => $devices,
        ]]);
    }

    // Offering SMS asks somebody to wait for a text. The default provider is LogSmsProvider,
    // which writes the code to storage/ -- so the button has to read SMS_PROVIDER and not just
    // the Twilio credentials, or a stock install offers a code that is never delivered anywhere
    // the person setting 2FA up can reach.
    private static function smsAvailable(): bool
    {
        return ($_ENV['SMS_PROVIDER'] ?? 'log') === 'twilio'
            && ($_ENV['TWILIO_ACCOUNT_SID'] ?? '') !== ''
            && ($_ENV['TWILIO_AUTH_TOKEN'] ?? '') !== '';
    }

    // -------------------------------------------------------------------------
    // 2FA setup
    // -------------------------------------------------------------------------

    public function totpSetup(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (($_ENV['APP_ENCRYPTION_KEY'] ?? '') === '') {
            return Response::json(['success' => false, 'message' => 'Two-factor authentication is not configured on this server.'], 422);
        }

        $secret = Totp::generateSecret();
        $_SESSION['setup_totp_secret'] = $secret;

        return Response::json(['success' => true, 'data' => [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, Auth::actualUser()->email, Brand::name()),
        ]]);
    }

    public function totpConfirm(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $code = trim((string) ($input['code'] ?? ''));
        $secret = $_SESSION['setup_totp_secret'] ?? null;

        if ($secret === null) {
            return Response::json(['success' => false, 'message' => 'No 2FA setup in progress.'], 422);
        }
        if (!Totp::verify($secret, $code)) {
            return Response::json(['success' => false, 'message' => 'Incorrect code.'], 422);
        }

        $user = Auth::actualUser();
        $user->two_factor_enabled = 1;
        $user->two_factor_method = TwoFactorMethod::Totp;
        $user->two_factor_secret = Crypto::encrypt($secret);
        $user->save();
        AdminLog::record('auth.2fa_enabled', $user->fullName() . ' turned on two-factor (authenticator app)', [
            'user' => $user,
            'actor' => $user,
            'meta' => ['method' => 'totp'],
        ]);
        unset($_SESSION['setup_totp_secret']);

        return Response::json(['success' => true, 'data' => ['backup_codes' => $this->generateBackupCodes($user)]]);
    }

    public function smsSend(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $phone = trim((string) ($input['phone_number'] ?? ''));
        if ($phone === '') {
            return Response::json(['success' => false, 'message' => 'Phone number is required.'], 422);
        }

        $user = Auth::actualUser();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = new LoginChallengeModel();
        $challenge->user_id = $user->id;
        $challenge->method = 'sms';
        $challenge->phone_number = $phone;
        $challenge->code_hash = hash('sha256', $code);
        $challenge->expires_at = date('Y-m-d H:i:s', time() + self::CHALLENGE_MINUTES * 60);
        $challenge->created_at = date('Y-m-d H:i:s');
        $challenge->save();

        $this->sms->send($phone, "Your verification code is $code");
        $_SESSION['setup_sms_challenge_uid'] = $challenge->uid;

        return Response::json(['success' => true]);
    }

    public function smsConfirm(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $code = trim((string) ($input['code'] ?? ''));
        $user = Auth::actualUser();
        $uid = $_SESSION['setup_sms_challenge_uid'] ?? null;
        $challenge = $uid !== null ? LoginChallengeModel::findByUid($uid) : null;

        // The session key is what binds a challenge to whoever asked for it; the user_id check
        // is what makes that binding a fact rather than an assumption, since this class claims
        // every endpoint is self-scoped.
        if ($challenge !== null && $challenge->user_id !== $user->id) {
            $challenge = null;
        }

        if ($challenge === null || $challenge->isExpired()) {
            unset($_SESSION['setup_sms_challenge_uid']);
            return Response::json(['success' => false, 'message' => 'That code has expired, please request a new one.'], 422);
        }
        if (!hash_equals((string) $challenge->code_hash, hash('sha256', $code))) {
            return Response::json(['success' => false, 'message' => 'Incorrect code.'], 422);
        }

        $user->two_factor_enabled = 1;
        $user->two_factor_method = TwoFactorMethod::Sms;
        $user->phone_number = $challenge->phone_number;
        $user->save();
        AdminLog::record('auth.2fa_enabled', $user->fullName() . ' turned on two-factor (text message)', [
            'user' => $user,
            'actor' => $user,
            'meta' => ['method' => 'sms'],
        ]);

        $challenge->delete();
        unset($_SESSION['setup_sms_challenge_uid']);

        return Response::json(['success' => true, 'data' => ['backup_codes' => $this->generateBackupCodes($user)]]);
    }

    private function generateBackupCodes(UserModel $user): array
    {
        BackupCodeModel::deleteAllForUser($user->id);

        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $codes[] = $formatted;

            $code = new BackupCodeModel();
            $code->user_id = $user->id;
            $code->code_hash = password_hash($formatted, PASSWORD_DEFAULT);
            $code->created_at = date('Y-m-d H:i:s');
            $code->save();
        }

        return $codes;
    }

    public function disableTwoFactor(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $user = Auth::actualUser();

        if (!password_verify((string) ($input['password'] ?? ''), $user->password)) {
            return Response::json(['success' => false, 'message' => 'Incorrect password.'], 422);
        }

        $user->two_factor_enabled = 0;
        $user->two_factor_method = TwoFactorMethod::None;
        $user->two_factor_secret = null;
        $user->phone_number = null;
        $user->save();
        // Both a support answer ("why is it asking me for a code?" / "it stopped asking") and a
        // security signal, since turning 2FA off is what an account takeover does first.
        AdminLog::record('auth.2fa_disabled', $user->fullName() . ' turned off two-factor', [
            'user' => $user,
            'actor' => $user,
        ]);

        BackupCodeModel::deleteAllForUser($user->id);

        foreach (RememberTokenModel::findAllForUser($user->id) as $token) {
            if ($token->trust_2fa_until !== null) {
                $token->trust_2fa_until = null;
                $token->save();
            }
        }

        return Response::json(['success' => true]);
    }

    public function regenerateBackupCodes(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $user = Auth::actualUser();

        if (!password_verify((string) ($input['password'] ?? ''), $user->password)) {
            return Response::json(['success' => false, 'message' => 'Incorrect password.'], 422);
        }
        if (!$user->two_factor_enabled) {
            return Response::json(['success' => false, 'message' => 'Two-factor authentication is not enabled.'], 422);
        }

        return Response::json(['success' => true, 'data' => ['backup_codes' => $this->generateBackupCodes($user)]]);
    }

    // -------------------------------------------------------------------------
    // PIN
    // -------------------------------------------------------------------------

    public function setupPin(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $user = Auth::actualUser();
        $pin = trim((string) ($input['pin'] ?? ''));
        $confirm = trim((string) ($input['pin_confirm'] ?? ''));

        if (!password_verify((string) ($input['password'] ?? ''), $user->password)) {
            return Response::json(['success' => false, 'message' => 'Incorrect password.'], 422);
        }
        if (!preg_match('/^\d{4}$/', $pin)) {
            return Response::json(['success' => false, 'message' => 'PIN must be exactly 4 digits.'], 422);
        }
        if ($pin !== $confirm) {
            return Response::json(['success' => false, 'message' => 'PINs do not match.'], 422);
        }

        $user->pin_hash = password_hash($pin, PASSWORD_DEFAULT);
        $user->pin_enabled = 1;
        $user->failed_pin_attempts = 0;
        $user->pin_locked_until = null;
        $user->save();

        return Response::json(['success' => true]);
    }

    public function disablePin(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $input = $request->isJson() ? $request->jsonBody() : $request->getBody();
        $user = Auth::actualUser();

        if (!password_verify((string) ($input['password'] ?? ''), $user->password)) {
            return Response::json(['success' => false, 'message' => 'Incorrect password.'], 422);
        }

        $user->pin_hash = null;
        $user->pin_enabled = 0;
        $user->failed_pin_attempts = 0;
        $user->pin_locked_until = null;
        $user->save();

        return Response::json(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Trusted devices
    // -------------------------------------------------------------------------

    public function revokeDevice(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        $id = (int) $request->getAttribute('id');
        $token = RememberTokenModel::find($id);

        if ($token !== null && $token->user_id === Auth::actualUser()->id) {
            $token->delete();
        }

        return Response::json(['success' => true]);
    }

    public function revokeAllDevices(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        RememberTokenModel::deleteAllForUser(Auth::actualUser()->id);

        return Response::json(['success' => true]);
    }
}

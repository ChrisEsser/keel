<?php

declare(strict_types=1);

namespace Keel\Accounts\Model;

use Keel\Model\Model;

class PasswordResetModel extends Model
{
    protected static string $table = 'password_resets';
    protected static bool $softDeletes = false;
    protected static array $fields = ['user_id', 'token', 'expires_at', 'used_at'];

    public int $id = 0;
    public string $uid = '';
    public int $user_id = 0;
    public string $token = '';
    public string $expires_at = '';
    public ?string $used_at = null;

    // Stored as a SHA-256 digest, never in the clear -- see SiteUserPasswordResetModel::hashToken
    // for why an unsalted digest is the right primitive for a CSPRNG token.
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    // Takes the plaintext token out of the reset link and hashes it before looking up, so callers
    // never handle the stored form.
    public static function findByToken(string $token): ?static
    {
        return static::where(['token' => static::hashToken($token)])[0] ?? null;
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}

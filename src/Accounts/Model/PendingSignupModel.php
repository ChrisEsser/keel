<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

class PendingSignupModel extends Model
{
    protected static string $table = 'pending_signups';
    protected static bool $softDeletes = false;
    protected static array $fields = ['first_name', 'last_name', 'email', 'org_name', 'token', 'expires_at', 'used_at'];

    public int $id = 0;
    public string $uid = '';
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $org_name = '';
    public string $token = '';
    public string $expires_at = '';
    public ?string $used_at = null;

    public static function findByToken(string $token): ?static
    {
        return static::where(['token' => $token])[0] ?? null;
    }

    public static function findByEmail(string $email): ?static
    {
        return static::where(['email' => $email])[0] ?? null;
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

<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

class RememberTokenModel extends Model
{
    protected static string $table = 'remember_tokens';
    protected static bool $softDeletes = false;
    protected static array $fields = [
        'user_id', 'selector', 'validator_hash', 'expires_at',
        'trust_2fa_until', 'device_label', 'ip_address', 'last_used_at', 'created_at',
    ];

    public int $id = 0;
    public int $user_id = 0;
    public string $selector = '';
    public string $validator_hash = '';
    public string $expires_at = '';
    public ?string $trust_2fa_until = null;
    public ?string $device_label = null;
    public ?string $ip_address = null;
    public ?string $last_used_at = null;
    public string $created_at = '';

    public static function findBySelector(string $selector): ?static
    {
        return static::where(['selector' => $selector])[0] ?? null;
    }

    public static function findAllForUser(int $userId): array
    {
        return static::where(['user_id' => $userId]);
    }

    public static function deleteAllForUser(int $userId): void
    {
        foreach (static::findAllForUser($userId) as $token) {
            $token->delete();
        }
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }

    public function isTrustedFor2fa(): bool
    {
        return $this->trust_2fa_until !== null && strtotime($this->trust_2fa_until) > time();
    }
}

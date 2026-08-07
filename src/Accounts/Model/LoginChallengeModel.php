<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

class LoginChallengeModel extends Model
{
    protected static string $table = 'login_challenges';
    protected static bool $softDeletes = false;
    protected static array $fields = [
        'user_id', 'method', 'code_hash', 'phone_number',
        'attempts', 'remember_requested', 'expires_at', 'created_at',
    ];

    public int $id = 0;
    public string $uid = '';
    public int $user_id = 0;
    public string $method = 'totp';
    public ?string $code_hash = null;
    public ?string $phone_number = null;
    public int $attempts = 0;
    public int $remember_requested = 0;
    public string $expires_at = '';
    public string $created_at = '';

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }
}

<?php

declare(strict_types=1);

namespace Keel\Accounts\Model;

use Keel\Model\Model;

class BackupCodeModel extends Model
{
    protected static string $table = 'backup_codes';
    protected static bool $softDeletes = false;
    protected static array $fields = ['user_id', 'code_hash', 'used_at', 'created_at'];

    public int $id = 0;
    public int $user_id = 0;
    public string $code_hash = '';
    public ?string $used_at = null;
    public string $created_at = '';

    public static function findAllForUser(int $userId): array
    {
        return static::where(['user_id' => $userId]);
    }

    public static function findUnusedForUser(int $userId): array
    {
        return array_values(array_filter(
            static::findAllForUser($userId),
            fn(BackupCodeModel $code) => !$code->isUsed(),
        ));
    }

    public static function deleteAllForUser(int $userId): void
    {
        foreach (static::findAllForUser($userId) as $code) {
            $code->delete();
        }
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}

<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

class InvitationModel extends Model
{
    protected static string $table = 'invitations';
    protected static array $fields = ['org_id', 'email', 'role', 'token', 'accepted_at', 'expires_at'];

    public int $id = 0;
    public int $org_id = 0;
    public string $email = '';
    public Role $role = Role::User;
    public string $token = '';
    public ?string $accepted_at = null;
    public string $expires_at = '';

    protected static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = (int) $row['id'];
        $instance->org_id = (int) $row['org_id'];
        $instance->email = $row['email'];
        $instance->role = Role::from($row['role']);
        $instance->token = $row['token'];
        $instance->accepted_at = $row['accepted_at'];
        $instance->expires_at = $row['expires_at'];
        return $instance;
    }

    protected function serializeField(string $field): mixed
    {
        if ($field === 'role') return $this->role->value;
        return parent::serializeField($field);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'org_id' => $this->org_id,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'token' => $this->token,
            'accepted_at' => $this->accepted_at,
            'expires_at' => $this->expires_at,
        ];
    }

    public static function findByToken(string $token): ?static
    {
        return static::where(['token' => $token])[0] ?? null;
    }

    public static function findPending(int $orgId, string $email): ?static
    {
        $all = static::where(['org_id' => $orgId, 'email' => $email]);
        foreach ($all as $invite) {
            if (!$invite->isAccepted() && !$invite->isExpired()) {
                return $invite;
            }
        }
        return null;
    }

    // How many outstanding (unaccepted, unexpired) invitations an org has -- counted alongside its
    // memberships against the per-plan seat cap so a pending invite already holds a seat.
    public static function countPending(int $orgId): int
    {
        $count = 0;
        foreach (static::where(['org_id' => $orgId]) as $invite) {
            if (!$invite->isAccepted() && !$invite->isExpired()) {
                $count++;
            }
        }
        return $count;
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function accept(): void
    {
        $this->accepted_at = date('Y-m-d H:i:s');
        $this->save();
    }
}

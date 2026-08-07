<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

class MembershipModel extends Model
{
    protected static string $table = 'memberships';
    protected static array $fields = ['user_id', 'org_id', 'role'];

    public int $id = 0;
    public string $uid = '';
    public int $user_id = 0;
    public int $org_id = 0;
    public Role $role = Role::User;

    public static function findByUser(int $userId): array
    {
        return static::where(['user_id' => $userId]);
    }

    public static function findByOrganization(int $orgId): array
    {
        return static::where(['org_id' => $orgId]);
    }

    public static function findByUserAndOrg(int $userId, int $orgId): ?self
    {
        return static::where(['user_id' => $userId, 'org_id' => $orgId])[0] ?? null;
    }

    /**
     * An org has exactly one owner. Promotes $target and steps any sitting owner down to admin,
     * so every path that hands out the role -- transfer-ownership, a system admin editing a
     * membership directly -- lands the org in the same shape.
     */
    public static function makeSoleOwner(self $target): void
    {
        foreach (static::findByOrganization($target->org_id) as $member) {
            if ($member->id !== $target->id && $member->role === Role::Owner) {
                $member->role = Role::Admin;
                $member->save();

                // The demotion nobody asked for and nobody is told about: promoting one member
                // silently drops the previous owner to admin, and they find out when something
                // they used to be able to do stops working.
                $demoted = UserModel::find($member->user_id);
                \Framework\Accounts\Service\AdminLog::record('member.role_changed',
                    ($demoted?->fullName() ?? 'A member') . ' was demoted from owner to admin when the owner role moved', [
                        'org' => OrganizationModel::find($member->org_id),
                        'user' => $demoted,
                        'meta' => ['from' => 'owner', 'to' => 'admin', 'automatic' => true],
                    ]);
            }
        }

        if ($target->role !== Role::Owner) {
            $target->role = Role::Owner;
            $target->save();
        }
    }

    // Number of members in an org -- counted with pending invitations against the per-plan seat cap.
    public static function countForOrg(int $orgId): int
    {
        return count(static::findByOrganization($orgId));
    }

    protected static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = (int) $row['id'];
        $instance->uid = (string) ($row['uid'] ?? '');
        $instance->user_id = (int) $row['user_id'];
        $instance->org_id = (int) $row['org_id'];
        $instance->role = Role::from($row['role']);
        return $instance;
    }

    protected function serializeField(string $field): mixed
    {
        if ($field === 'role') {
            return $this->role->value;
        }
        return parent::serializeField($field);
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'user_id' => $this->user_id,
            'org_id' => $this->org_id,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
        ];
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->user_id === 0) {
            $errors[] = 'user_id is required.';
        }
        if ($this->org_id === 0) {
            $errors[] = 'org_id is required.';
        }

        return $errors;
    }
}

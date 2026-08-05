<?php

declare(strict_types=1);

namespace Keel\Accounts\Model;

use Keel\Model\Model;

class UserModel extends Model
{
    protected static string $table = 'users';
    protected static array $fields = [
        'first_name', 'last_name', 'email', 'password', 'is_admin',
        'failed_login_attempts', 'locked_until',
        'pin_hash', 'pin_enabled', 'failed_pin_attempts', 'pin_locked_until',
        'two_factor_enabled', 'two_factor_method', 'two_factor_secret', 'phone_number',
        'two_factor_prompt_snoozed_at',
    ];
    protected static array $searchFields = ['first_name', 'last_name', 'email'];

    public int $id = 0;
    public string $uid = '';
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $password = '';
    public int $is_admin = 0;
    public int $failed_login_attempts = 0;
    public ?string $locked_until = null;
    public ?string $pin_hash = null;
    public int $pin_enabled = 0;
    public int $failed_pin_attempts = 0;
    public ?string $pin_locked_until = null;
    public int $two_factor_enabled = 0;
    public TwoFactorMethod $two_factor_method = TwoFactorMethod::None;
    public ?string $two_factor_secret = null;
    public ?string $phone_number = null;
    public ?string $two_factor_prompt_snoozed_at = null;

    // How long the post-login two-factor prompt stays quiet after "Remind me later".
    public const TWO_FACTOR_SNOOZE_DAYS = 14;

    protected static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = (int) $row['id'];
        $instance->uid = (string) ($row['uid'] ?? '');
        $instance->first_name = $row['first_name'];
        $instance->last_name = $row['last_name'];
        $instance->email = $row['email'];
        $instance->password = $row['password'];
        $instance->is_admin = (int) $row['is_admin'];
        $instance->failed_login_attempts = (int) $row['failed_login_attempts'];
        $instance->locked_until = $row['locked_until'];
        $instance->pin_hash = $row['pin_hash'];
        $instance->pin_enabled = (int) $row['pin_enabled'];
        $instance->failed_pin_attempts = (int) $row['failed_pin_attempts'];
        $instance->pin_locked_until = $row['pin_locked_until'];
        $instance->two_factor_enabled = (int) $row['two_factor_enabled'];
        $instance->two_factor_method = TwoFactorMethod::from($row['two_factor_method'] ?? 'none');
        $instance->two_factor_secret = $row['two_factor_secret'];
        $instance->phone_number = $row['phone_number'];
        $instance->two_factor_prompt_snoozed_at = $row['two_factor_prompt_snoozed_at'] ?? null;
        return $instance;
    }

    protected function serializeField(string $field): mixed
    {
        if ($field === 'two_factor_method') return $this->two_factor_method->value;
        return parent::serializeField($field);
    }

    public function fullName(): string
    {
        return trim("$this->first_name $this->last_name");
    }

    // Whether to show the post-login two-factor prompt. Someone who already has 2FA on is
    // never asked, and dismissing it buys TWO_FACTOR_SNOOZE_DAYS of quiet. Stored and compared
    // in UTC — the connection is pinned to UTC (Framework\Database::connect()), so gmdate()
    // strings sort correctly against the column.
    public function shouldPromptForTwoFactor(): bool
    {
        if ($this->two_factor_enabled) {
            return false;
        }
        if ($this->two_factor_prompt_snoozed_at === null) {
            return true;
        }

        $quietUntil = gmdate('Y-m-d H:i:s', strtotime($this->two_factor_prompt_snoozed_at . ' UTC')
            + self::TWO_FACTOR_SNOOZE_DAYS * 86400);

        return gmdate('Y-m-d H:i:s') >= $quietUntil;
    }

    public function validate(): array
    {
        $errors = [];

        if (trim($this->first_name) === '') {
            $errors[] = 'First name is required.';
        }
        if (trim($this->last_name) === '') {
            $errors[] = 'Last name is required.';
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }

        return $errors;
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
            'two_factor_method' => $this->two_factor_method->value,
            'two_factor_method_label' => $this->two_factor_method->label(),
        ];
    }
}

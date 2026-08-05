<?php

declare(strict_types=1);

namespace Keel\Accounts\Model;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::User  => 'User',
        };
    }

    /**
     * Day-to-day work inside the org -- whatever the product actually does. Every role can do
     * all of it: what separates the roles is the org itself (who belongs to it, who pays for
     * it), not the work.
     *
     * Deliberately a method returning true rather than an absent check. It gives the rule a
     * name, it makes the intent visible at every call site, and it is the one place to change
     * if a read-only role ever lands.
     */
    public function canManageContent(): bool
    {
        return true;
    }

    /** Inviting, removing, and re-roling teammates. */
    public function canManageTeam(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** Plans, cards and invoices. */
    public function canManageBilling(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** Returns all valid role values as strings, for validation error messages. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

declare(strict_types=1);

namespace Keel\Accounts;

use Keel\Accounts\Model\MembershipModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Auth;

/**
 * "May the person at the keyboard do this to this organization?"
 *
 * There is deliberately no middleware in this framework -- the router resolves a controller and
 * calls it, with nothing in between -- so every guard is an early return at the top of an action.
 * That is a real design choice (you can read any action and see exactly what it checks, without
 * chasing a stack of decorators), and it has one real cost: the org-scoped check is nine lines of
 * load-the-membership-then-ask-the-role, and it was pasted into a dozen methods before it was
 * pulled out here. Paste it a thirteenth time and the thirteenth is the one that forgets to handle
 * a null membership.
 *
 * Three questions, matching the three the Role enum answers. All of them are true for a system
 * admin without a membership -- staff act on organizations they don't belong to, which is the
 * whole point of the support hub.
 *
 * Every method fails closed: no session, no membership, or a role that doesn't grant it all read
 * as false.
 */
final class OrgGuard
{
    /** Content: the everyday work of the product. Every role can do this, including plain users. */
    public static function canManageContent(OrganizationModel $org): bool
    {
        return self::check($org, static fn(Model\Role $r): bool => $r->canManageContent());
    }

    /** Team: inviting, removing and re-roling people. Owners and admins only. */
    public static function canManageTeam(OrganizationModel $org): bool
    {
        return self::check($org, static fn(Model\Role $r): bool => $r->canManageTeam());
    }

    /** Billing: the subscription, the card, the invoices. Owners and admins only. */
    public static function canManageBilling(OrganizationModel $org): bool
    {
        return self::check($org, static fn(Model\Role $r): bool => $r->canManageBilling());
    }

    /**
     * The caller's membership, or null if they have none.
     *
     * Returns null for a system admin too -- staff genuinely have no membership, and pretending
     * otherwise would put a fake role in front of any caller that wanted to display one.
     */
    public static function membership(OrganizationModel $org): ?MembershipModel
    {
        $user = Auth::user();

        return $user === null ? null : MembershipModel::findByUserAndOrg($user->id, $org->id);
    }

    /**
     * The three answers at once, for handing to a view.
     *
     * Restricted controls are rendered visible-but-locked rather than hidden (a plain member who
     * can't see the Billing tab files a support ticket asking where it went), so templates need
     * all three up front rather than asking one question per control.
     *
     * @return array{manage_content: bool, manage_team: bool, manage_billing: bool}
     */
    public static function capabilities(OrganizationModel $org): array
    {
        return [
            'manage_content' => self::canManageContent($org),
            'manage_team' => self::canManageTeam($org),
            'manage_billing' => self::canManageBilling($org),
        ];
    }

    /** @param callable(Model\Role): bool $grants */
    private static function check(OrganizationModel $org, callable $grants): bool
    {
        // isAdmin(), not effectiveIsAdmin(): this is access control, so it asks about the REAL
        // logged-in user and staff keep their powers while impersonating. Deciding what to DRAW is
        // the opposite question -- use Auth::effectiveIsAdmin() there, so an impersonating admin
        // sees the screen the customer sees rather than a staff one.
        if (Auth::isAdmin()) {
            return true;
        }

        $membership = self::membership($org);

        return $membership !== null && $grants($membership->role);
    }
}

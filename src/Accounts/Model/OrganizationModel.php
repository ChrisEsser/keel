<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

// An organization is the unit a subscription is bought by and a team belongs to. Users don't pay
// for anything and don't own anything; organizations do. Every app built on Keel that has more
// than one person per account wants this shape, and the ones that don't can ignore it -- a
// single-user app just never shows the team panel.
class OrganizationModel extends Model
{
    protected static string $table = 'organizations';
    protected static array $fields = [
        'name', 'email',
        'stripe_customer_id', 'stripe_subscription_id',
        'stripe_card_brand', 'stripe_card_last4',
        'subscription_status', 'subscription_quantity', 'subscription_renewal_at',
        'past_due_since', 'lapsed_at',
        'postal_address',
    ];
    protected static array $searchFields = ['name', 'email'];

    // Stripe subscription statuses that entitle the org outright. 'past_due' is the one conditional
    // case (see hasActivePlan's grace window); everything else Stripe can report -- unpaid,
    // canceled, incomplete, incomplete_expired, paused -- does not entitle.
    private const ENTITLING_STATUSES = ['active', 'trialing'];

    // Days a past_due org keeps access while Stripe retries the invoice. Stripe's default dunning
    // retries span ~2-3 weeks, so this outlives a transient decline but not an abandoned card.
    // Override with SUBSCRIPTION_GRACE_DAYS (0 = lapse immediately on the first failure).
    private const DEFAULT_GRACE_DAYS = 14;

    public const PLAN_REQUIRED_MESSAGE = 'Your organization needs an active subscription before you can do this.';

    // Shown wherever an org's name would be when the customer never gave one at signup (the company
    // field is optional). The stored name stays empty in that case -- this is display-only -- so
    // support can still tell the "no name" orgs apart by owner.
    public const DEFAULT_NAME = 'My Workspace';

    public int $id = 0;
    public string $uid = '';
    public string $name = '';
    public string $email = '';
    public ?string $stripe_customer_id = null;
    public ?string $stripe_subscription_id = null;
    public ?string $stripe_card_brand = null;
    public ?string $stripe_card_last4 = null;
    public ?string $subscription_status = null;
    // How many of the recurring price they are billed for. Mirrored from Stripe by the webhooks
    // rather than being the source of truth: the plans modal has to seed its stepper before it can
    // render, and asking Stripe on every modal open would put a network round trip in front of a
    // number that changes a few times a year.
    public int $subscription_quantity = 1;
    public ?int $subscription_renewal_at = null;
    // Unix seconds the subscription first went past_due (null = not past due). Drives the grace
    // window in hasActivePlan(); maintained by StripeService's dunning webhook handlers.
    public ?int $past_due_since = null;
    // Unix seconds the org actually went dark -- grace expiry for a lapsed payment, cancellation
    // time for a deliberate cancel (null = never lapsed, or lapsed then came back). Kept as
    // history so a returning customer's support ticket can be answered without guessing.
    public ?int $lapsed_at = null;
    // Some jurisdictions require a physical mailing address in commercial email. Kept here so an
    // app that sends any has somewhere to put it.
    public ?string $postal_address = null;

    // Whether the org is entitled to paid features. 'active'/'trialing' always are. 'past_due'
    // stays entitled until its grace window lapses -- Stripe retries a failed invoice for ~2-3
    // weeks, and cutting off a paying customer on the first declined retry would be wrong.
    // Everything else (unpaid, canceled, incomplete, paused, or no subscription at all) is not.
    //
    // Fails closed, and it is the only thing anything should ask. Nothing outside this class
    // should read subscription_status directly -- that is what let the gate change shape here
    // without touching a caller.
    public function hasActivePlan(): bool
    {
        if ($this->subscription_status === null) return false;
        if (in_array($this->subscription_status, self::ENTITLING_STATUSES, true)) return true;
        if ($this->subscription_status !== 'past_due') return false;

        return time() < $this->graceEndsAt();
    }

    // End of a past_due org's grace window (unix seconds) -- only meaningful while past_due; also
    // used to tell the customer when access lapses. A null past_due_since means the transition was
    // never recorded, so grant grace from now rather than retroactively locking out a customer.
    public function graceEndsAt(): int
    {
        $days = (int) ($_ENV['SUBSCRIPTION_GRACE_DAYS'] ?? self::DEFAULT_GRACE_DAYS);

        return ($this->past_due_since ?? time()) + ($days * 86400);
    }

    // Canonical plan state for display: 'active' | 'past_due' | 'canceled' | 'none'. Every screen
    // that shows a badge reads this rather than re-deriving its own "active" heuristic -- which is
    // how three of them ended up rendering past_due as a green "Active" before it existed.
    // 'canceled' is the catch-all for every non-entitling terminal status (canceled, unpaid,
    // incomplete, paused).
    public function planState(): string
    {
        if ($this->subscription_status === 'past_due') return 'past_due';
        if (in_array($this->subscription_status, self::ENTITLING_STATUSES, true)) return 'active';
        if ($this->subscription_status === null) return 'none';

        return 'canceled';
    }

    // Admin org-list search that ALSO matches on the owner's name. An unnamed org (blank name) is
    // shown by its owner's name in the list, so support must be able to find it by that name too --
    // not just the org's own name/email. LEFT JOINs the owner membership + user. Falls back to a
    // plain listing when $search is blank. Mirrors Model::paginate()'s {items,total} return shape;
    // the generic paginate() can't JOIN, so this is hand-rolled.
    public static function searchForAdmin(int $page, int $perPage, string $search): array
    {
        $offset = ($page - 1) * $perPage;
        $search = trim($search);

        $join = '';
        $where = ['o.deleted = 0'];
        $params = [];
        if ($search !== '') {
            $join = "LEFT JOIN `memberships` m ON m.org_id = o.id AND m.role = 'owner' AND m.deleted = 0
                     LEFT JOIN `users` u ON u.id = m.user_id AND u.deleted = 0";
            $like = '%' . $search . '%';
            $where[] = "(o.name LIKE ? OR o.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?"
                . " OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $params = [$like, $like, $like, $like, $like];
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = static::connection()->prepare("SELECT COUNT(DISTINCT o.id) FROM `organizations` o $join $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = static::connection()->prepare("SELECT DISTINCT o.* FROM `organizations` o $join $whereSql ORDER BY o.id LIMIT ? OFFSET ?");
        foreach ($params as $i => $param) {
            $dataStmt->bindValue($i + 1, $param);
        }
        $dataStmt->bindValue(count($params) + 1, $perPage, \PDO::PARAM_INT);
        $dataStmt->bindValue(count($params) + 2, $offset, \PDO::PARAM_INT);
        $dataStmt->execute();
        $items = array_map(fn(array $row) => static::fromRow($row), $dataStmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    // The name to SHOW: the given company, or "My Workspace" when it was left blank. `name` itself
    // stays raw (empty) so edit forms start empty and admin tooling can detect the unnamed orgs.
    public function displayName(): string
    {
        return trim($this->name) !== '' ? $this->name : self::DEFAULT_NAME;
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        // Derived server-side so the UI has one source of truth for "is this plan OK?".
        $data['plan_state'] = $this->planState();
        $data['has_active_plan'] = $this->hasActivePlan();
        // Raw `name` above may be empty; this is what views should render.
        $data['display_name'] = $this->displayName();
        $data['subscription_quantity'] = $this->subscription_quantity;

        return $data;
    }

    public function validate(): array
    {
        $errors = [];

        if (trim($this->name) === '') {
            $errors[] = 'Name is required.';
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }

        return $errors;
    }
}

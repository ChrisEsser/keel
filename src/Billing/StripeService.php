<?php

declare(strict_types=1);

namespace Framework\Billing;

use Framework\Accounts\Model\OrganizationModel;
use Framework\Accounts\Service\AdminLog;
use Stripe\StripeClient;

/**
 * One organization, one Stripe subscription, one recurring price.
 *
 * The shape is deliberately the smallest one that is still correct end to end: create a customer,
 * collect a card, start a subscription, keep the local `subscription_status` honest as Stripe's
 * webhooks arrive, and stop. An application that sells more than one thing adds a price map and a
 * second item; the parts that are easy to get subtly wrong -- the SetupIntent ordering, the
 * dunning clock, Stripe's two API shapes -- are already right here and don't change.
 *
 * ## Why SetupIntent and not PaymentIntent
 *
 * The obvious flow is: create the subscription, take the invoice it generates, pay that
 * PaymentIntent. It does not work well. The subscription exists in `incomplete` before the card is
 * ever seen, so a customer who abandons the form leaves a half-real subscription behind, and a
 * card needing 3-D Secure leaves it there for up to 23 hours. Collecting the card FIRST
 * (SetupIntent), then creating the subscription with a payment method already attached, means the
 * subscription only ever comes into existence in a state you want.
 *
 * ## What must not be read off Stripe
 *
 * Entitlement. `hasActivePlan()` reads the local status column, which these webhooks maintain.
 * That indirection is load-bearing: it survives Stripe being unreachable, it is one query rather
 * than an API call on every page, and it means the grace window for a failed payment is a decision
 * this application makes rather than one Stripe makes for it.
 *
 * ## Requires stripe/stripe-php
 *
 * The only part of Keel that does, which is why it is a `suggest` and not a `require`. An
 * application that never sets STRIPE_SECRET_KEY never constructs this class.
 */
class StripeService
{
    private StripeClient $stripe;

    public function __construct(
        private SubscriptionMailer $subscriptionMailer,
    ) {
        $this->stripe = new StripeClient($_ENV['STRIPE_SECRET_KEY'] ?? '');
    }

    /**
     * The recurring price everything is sold at.
     *
     * Throws rather than defaulting, because the failure mode of guessing here is charging someone
     * the wrong amount -- and a misconfigured price id that silently becomes '' produces a Stripe
     * error far from its cause.
     */
    public function priceId(): string
    {
        return ($_ENV['STRIPE_PRICE_ID'] ?? '') ?: throw new \RuntimeException(
            'STRIPE_PRICE_ID is not configured.'
        );
    }

    // ── Starting a subscription ───────────────────────────────────────────────────────────────

    /**
     * Step one: ensure a Stripe customer exists and hand the browser a SetupIntent to collect a
     * card into. Returns the client secret.
     */
    public function setupForSubscription(OrganizationModel $org): string
    {
        $customerId = $this->ensureCustomer($org);

        $intent = $this->stripe->setupIntents->create([
            'customer' => $customerId,
            // off_session: this card will be charged again on renewal without the customer
            // present, and telling Stripe so up front is what makes those later charges succeed
            // under SCA rather than being declined for lack of an exemption.
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
        ]);

        return $intent->client_secret;
    }

    /**
     * Step two: the browser confirmed the SetupIntent, so attach the resulting payment method and
     * create the subscription with it. This is the call that charges the first invoice.
     */
    public function activateSubscription(OrganizationModel $org, string $paymentMethodId, int $quantity = 1): void
    {
        $customerId = $this->ensureCustomer($org);

        $this->stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        $this->stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $subscription = $this->stripe->subscriptions->create([
            'customer' => $customerId,
            'items' => [['price' => $this->priceId(), 'quantity' => max(1, $quantity)]],
            'default_payment_method' => $paymentMethodId,
            // org_uid, not id: the webhook resolves the org from this, and an internal integer
            // sitting in a third party's dashboard is worth nothing to anyone reading it.
            'metadata' => ['org_uid' => $org->uid],
        ]);

        $this->applySubscriptionToOrg($org, $subscription);
        $org->save();

        AdminLog::record('billing.subscription_started', 'Subscription started for ' . $org->displayName(), [
            'org' => $org,
            'meta' => ['quantity' => max(1, $quantity)],
        ]);
    }

    // ── Changing and ending it ────────────────────────────────────────────────────────────────

    /**
     * Change how many of the price the org is billed for.
     *
     * Prorated immediately in both directions: going up bills the difference now, going down
     * credits it. That is the honest default -- the alternative (scheduling reductions for the
     * period boundary) is better for revenue and worse for trust, and it needs a whole second
     * mechanism to track what is entitled now versus what will be billed next.
     */
    public function changeQuantity(OrganizationModel $org, int $quantity): void
    {
        if ($org->stripe_subscription_id === null) {
            throw new \RuntimeException('This organization has no subscription to change.');
        }

        $subscription = $this->stripe->subscriptions->retrieve($org->stripe_subscription_id, ['expand' => ['items']]);
        $item = $subscription->items->data[0] ?? throw new \RuntimeException('Subscription has no items.');

        $updated = $this->stripe->subscriptions->update($org->stripe_subscription_id, [
            'items' => [['id' => $item->id, 'quantity' => max(1, $quantity)]],
            'proration_behavior' => 'always_invoice',
        ]);

        $this->applySubscriptionToOrg($org, $updated);
        $org->save();

        AdminLog::record('billing.subscription_changed', 'Subscription changed for ' . $org->displayName(), [
            'org' => $org,
            'meta' => ['from' => (int) ($item->quantity ?? 1), 'to' => max(1, $quantity)],
        ]);
    }

    /**
     * Cancel immediately.
     *
     * Immediate rather than at-period-end because the local state has to mean something the
     * instant it is read: an org that says "canceled" while Stripe still considers it active for
     * three more weeks is a gate that lies in the customer's disfavour. If you want to let them
     * keep what they paid for, don't cancel until the period ends.
     */
    public function cancelSubscription(OrganizationModel $org): void
    {
        if ($org->stripe_subscription_id !== null) {
            $this->stripe->subscriptions->cancel($org->stripe_subscription_id);
        }

        $this->markCanceled($org);

        AdminLog::record('billing.subscription_canceled', 'Subscription canceled for ' . $org->displayName(), [
            'org' => $org,
        ]);
    }

    // ── The card ──────────────────────────────────────────────────────────────────────────────

    /** A SetupIntent for replacing the card on an existing customer. */
    public function createSetupIntent(OrganizationModel $org): string
    {
        $intent = $this->stripe->setupIntents->create([
            'customer' => $this->ensureCustomer($org),
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
        ]);

        return $intent->client_secret;
    }

    /**
     * Point both the customer and the live subscription at a newly collected card.
     *
     * Both, deliberately: setting only the customer default leaves an existing subscription
     * charging the old card, which is exactly the case someone updating their card is trying to
     * fix. The brand/last4 are cached on the org so the billing panel can render without a
     * round-trip to Stripe on every page load.
     */
    public function updateDefaultCard(OrganizationModel $org, string $paymentMethodId): void
    {
        $customerId = $this->ensureCustomer($org);

        $this->stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        $this->stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        if ($org->stripe_subscription_id !== null) {
            $this->stripe->subscriptions->update($org->stripe_subscription_id, [
                'default_payment_method' => $paymentMethodId,
            ]);
        }

        $card = $this->cardOnFile($org);
        $org->stripe_card_brand = $card['brand'] ?? null;
        $org->stripe_card_last4 = $card['last4'] ?? null;
        $org->save();

        AdminLog::record('billing.payment_method_added', 'Payment method updated for ' . $org->displayName(), [
            'org' => $org,
        ]);
    }

    /** @return array{brand: string, last4: string}|null */
    public function cardOnFile(OrganizationModel $org): ?array
    {
        if ($org->stripe_customer_id === null) return null;

        try {
            $customer = $this->stripe->customers->retrieve($org->stripe_customer_id, [
                'expand' => ['invoice_settings.default_payment_method'],
            ]);
        } catch (\Throwable $e) {
            // A billing panel that 500s because Stripe is slow is worse than one that says
            // "no card on file" for a moment.
            error_log('cardOnFile failed for org ' . $org->uid . ': ' . $e->getMessage());
            return null;
        }

        $pm = $customer->invoice_settings->default_payment_method ?? null;
        if (!is_object($pm) || ($pm->type ?? '') !== 'card' || !isset($pm->card)) return null;

        return ['brand' => ucfirst((string) $pm->card->brand), 'last4' => (string) $pm->card->last4];
    }

    /**
     * Card plus recent invoices, for the billing panel.
     *
     * @return array{card: ?array, invoices: list<array>}
     */
    public function getBillingInfo(OrganizationModel $org, int $invoiceLimit = 3): array
    {
        if ($org->stripe_customer_id === null) {
            return ['card' => null, 'invoices' => []];
        }

        $customer = $this->stripe->customers->retrieve($org->stripe_customer_id, [
            'expand' => ['invoice_settings.default_payment_method'],
        ]);

        $card = null;
        $pm = $customer->invoice_settings->default_payment_method ?? null;
        if (is_object($pm) && isset($pm->card)) {
            $card = [
                'brand' => $pm->card->brand,
                'last4' => $pm->card->last4,
                'exp_month' => $pm->card->exp_month,
                'exp_year' => $pm->card->exp_year,
            ];
        }

        $invoices = $this->stripe->invoices->all([
            'customer' => $org->stripe_customer_id,
            'limit' => $invoiceLimit,
        ]);

        return [
            'card' => $card,
            // hosted_invoice_url rather than a PDF we render: Stripe's copy is the one that is
            // legally the invoice, and it stays correct when a refund or credit note lands later.
            'invoices' => array_map(static fn($inv) => [
                'date' => $inv->created,
                'total' => $inv->total,
                'currency' => $inv->currency,
                'status' => $inv->status,
                'url' => $inv->hosted_invoice_url,
            ], $invoices->data),
        ];
    }

    /** Stripe's own hosted billing portal -- invoice history, card management, tax ids. */
    public function createPortalSession(OrganizationModel $org, string $returnUrl): string
    {
        if ($org->stripe_customer_id === null) {
            throw new \RuntimeException('No Stripe customer for this organization.');
        }

        return $this->stripe->billingPortal->sessions->create([
            'customer' => $org->stripe_customer_id,
            'return_url' => $returnUrl,
        ])->url;
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────────────────────

    /**
     * The endpoint's whole body. Signature verification is not optional -- without it this route
     * lets anyone on the internet mark any organization as paid.
     */
    public function handleWebhook(string $payload, string $sigHeader): void
    {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sigHeader,
            $_ENV['STRIPE_WEBHOOK_SECRET'] ?? ''
        );

        // Subscriptions are created via SetupIntent -> activateSubscription(), never Stripe
        // Checkout, so there is deliberately no 'checkout.session.completed' arm.
        match ($event->type) {
            'customer.subscription.updated' => $this->onSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($event->data->object),
            'invoice.payment_succeeded' => $this->onPaymentSucceeded($event->data->object),
            'invoice.payment_failed' => $this->onPaymentFailed($event->data->object),
            default => null,
        };
    }

    // Public so a test can drive the transition without letting the calls above reach Stripe.
    public function onSubscriptionUpdated(object $subscription): void
    {
        $org = $this->orgFromSubscription($subscription);
        if ($org === null) return;

        $this->applySubscriptionToOrg($org, $subscription);
        $org->save();
    }

    public function onSubscriptionDeleted(object $subscription): void
    {
        $org = $this->orgFromSubscription($subscription);
        if ($org === null) return;

        $this->markCanceled($org);
    }

    private function onPaymentSucceeded(object $invoice): void
    {
        if ($this->invoiceSubscriptionId($invoice) === null) return;

        $org = $this->orgByCustomerId((string) $invoice->customer);
        if ($org === null) return;

        $wasFailing = $org->past_due_since !== null || $org->lapsed_at !== null;

        $org->subscription_status = 'active';
        $org->past_due_since = null;   // paid up -- the dunning episode and its grace clock are over
        $org->lapsed_at = null;
        $org->save();

        // Only the recovery is worth a row. An ordinary monthly renewal is not an event anyone
        // goes looking for; "when did their card finally go through" is.
        if ($wasFailing) {
            AdminLog::record('billing.payment_recovered', 'Payment went through for ' . $org->displayName() . ' -- access restored', [
                'org' => $org,
                'system' => true,
            ]);
        }
    }

    private function onPaymentFailed(object $invoice): void
    {
        $org = $this->orgByCustomerId((string) $invoice->customer);
        if ($org === null) return;

        // Stripe fires this on every retry of a failing invoice (~4 over 2-3 weeks) and may
        // redeliver events, but a dunning episode should start ONE grace clock and send ONE email.
        // Keying that off past_due_since rather than off the status is deliberate: a
        // customer.subscription.updated can flip the status to past_due first, and that path never
        // stamps this field (see applySubscriptionToOrg), so the notification cannot be swallowed
        // by event ordering.
        $firstFailure = $org->past_due_since === null;

        $org->subscription_status = 'past_due';
        if ($firstFailure) $org->past_due_since = time();
        $org->save();

        if ($firstFailure) {
            AdminLog::record('billing.payment_failed', 'Payment failed for ' . $org->displayName() . ' -- grace window started', [
                'org' => $org,
                'system' => true,
                'meta' => ['grace_ends_at' => date('Y-m-d', $org->graceEndsAt())],
            ]);
            $this->subscriptionMailer->sendPaymentFailed($org);
        }
    }

    // ── Local state ───────────────────────────────────────────────────────────────────────────

    private function applySubscriptionToOrg(OrganizationModel $org, object $subscription): void
    {
        $org->stripe_subscription_id = $subscription->id;
        $org->subscription_status = $subscription->status;
        $org->subscription_quantity = (int) ($subscription->items->data[0]->quantity ?? 1);
        $org->subscription_renewal_at = $this->renewalTimestamp($subscription);

        // Preserve the grace clock while they're still past_due; clear it the moment they're not.
        // Deliberately does NOT start it -- onPaymentFailed owns that, and its email, so that a
        // customer.subscription.updated arriving first cannot consume the transition and suppress
        // the notification.
        $org->past_due_since = $subscription->status === 'past_due' ? $org->past_due_since : null;

        // Any entitling status means they're back.
        if (in_array($subscription->status, ['active', 'trialing'], true)) {
            $org->lapsed_at = null;
        }
    }

    private function markCanceled(OrganizationModel $org): void
    {
        $org->stripe_subscription_id = null;
        $org->subscription_status = 'canceled';
        $org->subscription_renewal_at = null;
        $org->past_due_since = null;
        // Dark from this moment. Without a timestamp here there is nothing to measure "how long
        // have they been gone" from, which is the question every retention or cleanup job asks.
        $org->lapsed_at = time();
        $org->save();
    }

    /**
     * Re-read a subscription from Stripe and apply it locally. A repair hatch for when local state
     * drifts -- a webhook that never arrived, an endpoint that was down for an afternoon. Returns
     * false when there is no subscription to sync.
     */
    public function syncSubscriptionFromStripe(OrganizationModel $org): bool
    {
        if ($org->stripe_subscription_id === null) return false;

        $subscription = $this->stripe->subscriptions->retrieve($org->stripe_subscription_id, ['expand' => ['items']]);
        $this->applySubscriptionToOrg($org, $subscription);
        $org->save();

        return true;
    }

    // ── Stripe's two shapes ───────────────────────────────────────────────────────────────────
    //
    // The next two methods each read one field from two different places, and both are scar
    // tissue rather than defensiveness.
    //
    // Stripe renders WEBHOOK payloads at the account's own default API version, but anything the
    // SDK fetches itself comes back at the version the SDK pins. Those are usually not the same
    // version, so the same logical field genuinely arrives in two shapes in one application, and
    // reading only the one your SDK documents leaves the other silently null.

    /**
     * When the current period ends.
     *
     * Stripe moved `current_period_end` off the subscription and onto its items. Reading only the
     * old top-level field yields null on a current API version, which quietly leaves every new
     * subscriber with no renewal date.
     *
     * max() rather than [0] is defensive: an item added mid-cycle can briefly carry the older
     * boundary, and taking the earlier of two dates would expire the period early.
     */
    private function renewalTimestamp(object $subscription): ?int
    {
        $ends = [];
        foreach ($subscription->items->data ?? [] as $item) {
            if (isset($item->current_period_end)) $ends[] = (int) $item->current_period_end;
        }
        if ($ends !== []) return max($ends);

        $topLevel = $subscription->current_period_end ?? null;

        return $topLevel !== null ? (int) $topLevel : null;
    }

    /**
     * Which subscription an invoice belongs to, or null for a one-off invoice with none.
     *
     * Same two-shape problem, and it bites harder: Stripe moved the field under
     * `parent.subscription_details`, and onPaymentSucceeded() bails on null -- so the old
     * top-level read disabled that entire handler for accounts on a current API version.
     */
    private function invoiceSubscriptionId(object $invoice): ?string
    {
        $nested = $invoice->parent->subscription_details->subscription ?? null;
        if ($nested !== null) {
            return is_string($nested) ? $nested : ($nested->id ?? null);
        }

        $topLevel = $invoice->subscription ?? null;
        if ($topLevel === null) return null;

        return is_string($topLevel) ? $topLevel : ($topLevel->id ?? null);
    }

    // ── Resolution ────────────────────────────────────────────────────────────────────────────

    private function ensureCustomer(OrganizationModel $org): string
    {
        if ($org->stripe_customer_id !== null && $org->stripe_customer_id !== '') {
            return $org->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email' => $org->email,
            'name' => $org->displayName(),
            'metadata' => ['org_uid' => $org->uid],
        ]);

        $org->stripe_customer_id = $customer->id;
        $org->save();

        return $customer->id;
    }

    // By metadata first, because that's the link we put there ourselves and it survives a customer
    // being re-pointed at a different org. The customer id is the fallback for subscriptions
    // created before the metadata existed, or by hand in the Stripe dashboard.
    private function orgFromSubscription(object $subscription): ?OrganizationModel
    {
        $uid = $subscription->metadata->org_uid ?? null;
        if (is_string($uid) && $uid !== '') {
            $org = OrganizationModel::findByUid($uid);
            if ($org !== null) return $org;
        }

        $customerId = $subscription->customer ?? null;
        $customerId = is_string($customerId) ? $customerId : ($customerId->id ?? null);

        return $customerId !== null ? $this->orgByCustomerId($customerId) : null;
    }

    private function orgByCustomerId(string $customerId): ?OrganizationModel
    {
        return OrganizationModel::where(['stripe_customer_id' => $customerId])[0] ?? null;
    }
}

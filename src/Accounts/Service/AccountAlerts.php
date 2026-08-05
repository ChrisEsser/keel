<?php

declare(strict_types=1);

namespace Keel\Accounts\Service;

use Keel\Accounts\Model\OrganizationModel;

// Computes the account-level warnings shown in the banner above every page header (see
// views/partials/account-alerts.php). These mirror the dunning EMAILS an org already receives --
// a failed payment, an account about to lose access -- so a customer who missed the email still
// can't miss the problem when they log in.
//
// Read-only over the same signals the billing webhooks maintain (OrganizationModel's
// subscription/grace state), so there is one source of truth for "is this account in trouble".
// Never gates access -- purely informational plus a fix-it CTA.
//
// Extend it by adding a private *Alert() method and calling it from forOrg(): an application with
// its own things to lose (domains about to expire, storage over quota) hangs them here so every
// warning arrives in one place rather than each feature growing its own banner.
//
// Each alert is a plain array:
//   severity  'danger' | 'warning'
//   icon      lucide icon name
//   title     short bold lead (e.g. "Payment failed.")
//   message   the sentence(s) after it (plain text; the view escapes everything)
//   action    ['label' => ..., 'onclick' => js] | ['label' => ..., 'href' => url] | null
final class AccountAlerts
{
    /**
     * @param bool $canManageBilling viewer is an owner/admin of this org (or a platform admin) --
     *                               the only people who can act on payment/plan issues. Everyone
     *                               still sees the alert; non-billers just get a "ask an owner"
     *                               nudge instead of the button.
     * @return array<int, array{severity:string,icon:string,title:string,message:string,action:?array}>
     */
    public function forOrg(OrganizationModel $org, bool $canManageBilling): array
    {
        $alerts = [];

        $sub = $this->subscriptionAlert($org, $canManageBilling);
        if ($sub !== null) {
            $alerts[] = $sub;
        }

        return $alerts;
    }

    private function subscriptionAlert(OrganizationModel $org, bool $canBill): ?array
    {
        // Never subscribed -> nothing has lapsed; the empty-state/setup flows handle onboarding.
        if ($org->subscription_status === null) {
            return null;
        }

        $updateCard = $canBill
            ? ['label' => 'Update payment', 'onclick' => $this->openBilling($org)]
            : null;
        $askNote = $canBill ? '' : ' Ask an owner or admin to update the payment method.';

        // Still entitled: the only trouble state is past_due within its grace window -- payment
        // failed, but access continues until grace ends.
        if ($org->hasActivePlan()) {
            if ($org->planState() === 'past_due') {
                return [
                    'severity' => 'danger',
                    'icon' => 'credit-card',
                    'title' => 'Payment failed.',
                    'message' => "We couldn't process your last payment. Update your card by "
                        . $this->fmt($org->graceEndsAt()) . ' to keep your account active.' . $askNote,
                    'action' => $updateCard,
                ];
            }
            return null;   // active / trialing -- all good
        }

        // Not entitled. Two flavours -- a payment that failed through its grace window (fix =
        // update card) vs. a canceled or ended plan (fix = pick a plan).
        if ($org->planState() === 'past_due') {
            return [
                'severity' => 'danger',
                'icon' => 'wifi-off',
                'title' => 'Your account is on hold.',
                'message' => "We still couldn't process your payment, so paid features are turned off. "
                    . 'Update your card and they come straight back.' . $askNote,
                'action' => $updateCard,
            ];
        }

        $reactivate = $canBill
            ? ['label' => 'Reactivate plan', 'onclick' => "ModalLoader.open('plans', '" . $org->uid . "')"]
            : null;
        $reactivateNote = $canBill ? '' : ' Ask an owner or admin to reactivate the plan.';

        return [
            'severity' => 'danger',
            'icon' => 'wifi-off',
            'title' => 'Your subscription has ended.',
            'message' => 'Your plan is no longer active, so paid features are turned off. '
                . 'Reactivate a plan to bring them back.' . $reactivateNote,
            'action' => $reactivate,
        ];
    }

    // Opens the org-settings modal straight to its Billing/payment panel (AjaxModal.open(uid, panel)).
    private function openBilling(OrganizationModel $org): string
    {
        return "ModalLoader.open('org-settings', '" . $org->uid . "', 'payment')";
    }

    private function fmt(int|false $ts): string
    {
        if ($ts === false) {
            return 'soon';
        }
        return date('M j, Y', $ts);
    }
}

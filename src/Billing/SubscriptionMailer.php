<?php

declare(strict_types=1);

namespace Framework\Billing;

use Framework\Accounts\Model\OrganizationModel;
use Framework\Accounts\Service\AdminLog;
use Framework\Accounts\Service\EmailBlocks;
use Framework\Host;
use Framework\Mail\AppMailer;

// Every email an org gets on the way from a failed payment to losing access, so no step of that
// journey is ever silent:
//
//   day 0   sendPaymentFailed   payment failed, access continues
//   day 11  sendGraceEnding     access ends in 3 days
//   day 14  sendSubscriptionEnded   access has ended
//
// sendPaymentFailed fires once per dunning episode from StripeService::onPaymentFailed (see the
// past_due_since guard there) rather than on each of Stripe's ~4 retries. The other two are for a
// scheduled job to drive off OrganizationModel::graceEndsAt() -- Keel ships the messages, not the
// cron, because how often an application sweeps is its own decision.
//
// Sent through AppMailer, so these carry the same shell as the rest of the system mail and go out
// multipart. In development they land in storage/mail/YYYY-MM-DD.log via LogMailProvider.
//
// Deliberately independent of Stripe's own dunning emails (a Dashboard setting): these reference
// the organization by name and state the dates OUR grace window closes, which Stripe cannot know.
class SubscriptionMailer
{
    public function __construct(private AppMailer $mailer) {}

    // Never throws: this runs inside the Stripe webhook and the status change is already
    // committed -- a mail hiccup must not fail the webhook, or Stripe retries the whole event and
    // the customer gets the email twice.
    public function sendPaymentFailed(OrganizationModel $org): void
    {
        $this->deliver($org, 'email.payment_failed_sent', "Action needed: payment failed for {$org->displayName()}", (new EmailBlocks())
            ->heading('Payment failed')
            ->paragraph("We couldn't process the payment for {$org->displayName()}'s subscription.")
            ->paragraph("We'll keep retrying the card, and your access stays on until "
                . date('F j, Y', $org->graceEndsAt())
                . '. To avoid any interruption, update your payment method in Organization'
                . ' settings, under Billing.')
            ->button('Open your dashboard', $this->dashboardUrl($org))
            ->note("If one of the retries goes through before then, you don't need to do anything."));
    }

    // Last call before access actually ends. The only notice until now was sendPaymentFailed on
    // day one of the episode -- easy to miss two weeks earlier, and the consequence otherwise
    // lands with no further warning.
    public function sendGraceEnding(OrganizationModel $org): void
    {
        $this->deliver($org, 'email.grace_ending_sent', "Your account goes on hold in a few days ({$org->displayName()})", (new EmailBlocks())
            ->heading('Your account goes on hold soon')
            ->paragraph("We still haven't been able to process the payment for {$org->displayName()}.")
            ->paragraph('On ' . date('F j, Y', $org->graceEndsAt())
                . ' paid features will switch off. Nothing is deleted -- everything comes straight'
                . ' back as soon as a payment goes through.')
            ->paragraph('Update your payment method in Organization settings, under Billing.')
            ->button('Open your dashboard', $this->dashboardUrl($org)));
    }

    public function sendSubscriptionEnded(OrganizationModel $org): void
    {
        $this->deliver($org, 'email.subscription_canceled_sent', "{$org->displayName()}'s subscription has ended", (new EmailBlocks())
            ->heading('Your subscription has ended')
            ->paragraph("{$org->displayName()}'s subscription is no longer active, so paid features are switched off.")
            ->paragraph('Your data is still here and nothing has been deleted. Starting a new'
                . ' subscription turns everything back on exactly as you left it.')
            ->button('Open your dashboard', $this->dashboardUrl($org)));
    }

    /**
     * Send, and record that we sent it.
     *
     * The activity-log row is half the point. "We emailed you about this on the 3rd" answers a
     * large share of billing support calls, and the address it actually went to is recorded with
     * it -- which is often the real problem, since it is the ORGANIZATION's billing address rather
     * than the address of whoever is on the phone.
     */
    private function deliver(OrganizationModel $org, string $event, string $subject, EmailBlocks $email): void
    {
        try {
            $to = trim($org->email);
            if ($to === '') {
                AdminLog::record($event, "Could not send \"{$subject}\" -- {$org->displayName()} has no billing email on file", [
                    'org' => $org,
                    'system' => true,
                    'meta' => ['subject' => $subject, 'sent' => false, 'reason' => 'no billing email'],
                ]);
                return;
            }

            $sent = $this->mailer->send($to, $subject, $email,
                "You're receiving this because you manage billing for {$org->displayName()}.");

            AdminLog::record($event, "Emailed {$to}: {$subject}", [
                'org' => $org,
                'system' => true,
                'meta' => ['to' => $to, 'subject' => $subject, 'sent' => $sent],
            ]);
        } catch (\Throwable $e) {
            error_log('Subscription lifecycle email failed for org ' . $org->uid . ': ' . $e->getMessage());
        }
    }

    // Billing lives in the org settings MODAL, not on a route of its own, so there is nothing
    // deeper to link to -- every stage lands on the dashboard and the copy says where to go from
    // there.
    private function dashboardUrl(OrganizationModel $org): string
    {
        return Host::appUrl('/organizations/' . $org->uid . '/dashboard');
    }
}

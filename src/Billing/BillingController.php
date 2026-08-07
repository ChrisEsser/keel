<?php

declare(strict_types=1);

namespace Framework\Billing;

use Framework\Accounts\Model\OrganizationModel;
use Framework\Accounts\OrgGuard;
use Framework\Auth;
use Framework\Http\Request;
use Framework\Http\Response;

/**
 * The HTTP surface of the subscription: JSON endpoints for the billing panel, the redirect-return
 * route Stripe sends 3-D Secure back to, and the webhook.
 *
 * Every endpoint here is owner/admin only (OrgGuard::canManageBilling) except the webhook, which
 * has no session at all and is authenticated by Stripe's signature instead.
 */
class BillingController
{
    public function __construct(
        private StripeService $stripe,
    ) {}

    // ── Starting a subscription ───────────────────────────────────────────────────────────────

    /** Step one: hand the browser a SetupIntent client secret to collect a card into. */
    public function setupForSubscription(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        try {
            return Response::json(['success' => true, 'clientSecret' => $this->stripe->setupForSubscription($org)]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** Step two: the browser confirmed the card, so create the subscription with it. */
    public function activateSubscription(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        $input = $this->input($request);
        $paymentMethodId = (string) ($input['payment_method_id'] ?? '');
        if ($paymentMethodId === '') {
            return Response::json(['success' => false, 'message' => 'No payment method.'], 422);
        }

        try {
            $this->stripe->activateSubscription($org, $paymentMethodId, (int) ($input['quantity'] ?? 1));
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return Response::json(['success' => true, 'data' => $org->toArray()]);
    }

    public function updateSubscription(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        $quantity = (int) ($this->input($request)['quantity'] ?? 0);
        if ($quantity < 1) {
            return Response::json(['success' => false, 'message' => 'Quantity must be at least 1.'], 422);
        }

        try {
            $this->stripe->changeQuantity($org, $quantity);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return Response::json(['success' => true, 'data' => $org->toArray()]);
    }

    public function cancelSubscription(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        try {
            $this->stripe->cancelSubscription($org);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return Response::json(['success' => true, 'data' => $org->toArray()]);
    }

    // ── The card ──────────────────────────────────────────────────────────────────────────────

    public function setupCard(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        try {
            return Response::json(['success' => true, 'clientSecret' => $this->stripe->createSetupIntent($org)]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function confirmCard(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        $paymentMethodId = (string) ($this->input($request)['payment_method_id'] ?? '');
        if ($paymentMethodId === '') {
            return Response::json(['success' => false, 'message' => 'No payment method.'], 422);
        }

        try {
            $this->stripe->updateDefaultCard($org, $paymentMethodId);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return Response::json(['success' => true]);
    }

    public function invoices(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request, fromQuery: true);
        if ($error !== null) return $error;

        try {
            $info = $this->stripe->getBillingInfo($org);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return Response::json(['success' => true, 'card' => $info['card'], 'invoices' => $info['invoices']]);
    }

    public function portal(Request $request): Response
    {
        [$org, $error] = $this->resolveOrg($request);
        if ($error !== null) return $error;

        try {
            $url = $this->stripe->createPortalSession(
                $org,
                \Framework\Host::appUrl('/organizations/' . $org->uid . '/dashboard')
            );
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return Response::json(['success' => true, 'url' => $url]);
    }

    // ── Coming back from Stripe ───────────────────────────────────────────────────────────────

    /**
     * Where a redirect-based authentication (3-D Secure) lands.
     *
     * Most cards finish inside the page and never come here. The ones that don't are redirected
     * away and back, at which point the browser's in-flight fetch is long gone -- so this route
     * has to finish the job the JavaScript would have, from a GET, with only query parameters.
     *
     * Everything here is best-effort: on any failure it redirects to the dashboard rather than
     * showing an error, because the webhook is the authority and will settle the true state
     * moments later. A visible error here would describe a state that is about to stop being true.
     */
    public function return(Request $request): Response
    {
        return match ($request->query('mode', '')) {
            'setup_subscribe' => $this->finishReturn($request, subscribe: true),
            'update_card' => $this->finishReturn($request, subscribe: false),
            default => Response::redirect('/dashboard'),
        };
    }

    private function finishReturn(Request $request, bool $subscribe): Response
    {
        $orgUid = (string) $request->query('org_uid', '');
        $setupIntentId = (string) $request->query('setup_intent', '');

        if ($orgUid === '' || $setupIntentId === '') return Response::redirect('/dashboard');

        $org = OrganizationModel::findByUid($orgUid);
        if ($org === null) return Response::redirect('/dashboard');

        try {
            $stripe = new \Stripe\StripeClient($_ENV['STRIPE_SECRET_KEY'] ?? '');
            $intent = $stripe->setupIntents->retrieve($setupIntentId);

            if ($intent->status === 'succeeded' && $intent->payment_method) {
                if ($subscribe) {
                    $this->stripe->activateSubscription($org, (string) $intent->payment_method, (int) $request->query('quantity', 1));
                } else {
                    $this->stripe->updateDefaultCard($org, (string) $intent->payment_method);
                }
            }
        } catch (\Throwable $e) {
            error_log('Stripe return handler failed for org ' . $org->uid . ': ' . $e->getMessage());
        }

        return Response::redirect('/organizations/' . $org->uid . '/dashboard');
    }

    /**
     * Stripe's webhook endpoint.
     *
     * No session, no CSRF, no OrgGuard -- the signature IS the authentication, and it is verified
     * inside handleWebhook(). This route must stay reachable without a login: it is the only way
     * a renewal, a failed payment or a cancellation made in Stripe's dashboard ever reaches the
     * application.
     */
    public function webhook(Request $request): Response
    {
        // php://input, not the parsed body: signature verification hashes the exact bytes Stripe
        // sent, so anything that re-encodes the payload invalidates it.
        $payload = (string) file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $this->stripe->handleWebhook($payload, $sigHeader);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return Response::json(['error' => 'Invalid signature.'], 400);
        } catch (\Throwable $e) {
            // 500 rather than swallowing: Stripe retries a failed delivery with backoff, and a 200
            // here would throw away the only chance to get this event right.
            error_log('Stripe webhook failed: ' . $e->getMessage());
            return Response::json(['error' => 'Handler failed.'], 500);
        }

        return Response::json(['received' => true]);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the org from the request and check the caller may bill it.
     *
     * Returns [org, null] on success and [null, Response] on failure, so every endpoint above is
     * two lines of guard instead of twelve. The order matters: 404 before 403, so a caller who
     * can't bill an org still can't use the error code to tell whether it exists.
     *
     * @return array{0: OrganizationModel|null, 1: Response|null}
     */
    private function resolveOrg(Request $request, bool $fromQuery = false): array
    {
        if (!Auth::check()) {
            return [null, Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401)];
        }

        $orgUid = $fromQuery
            ? (string) $request->query('org_uid', '')
            : (string) ($this->input($request)['org_uid'] ?? '');

        $org = $orgUid !== '' ? OrganizationModel::findByUid($orgUid) : null;
        if ($org === null) {
            return [null, Response::json(['success' => false, 'message' => 'Organization not found.'], 404)];
        }

        if (!OrgGuard::canManageBilling($org)) {
            return [null, Response::json(['success' => false, 'message' => 'Forbidden.'], 403)];
        }

        return [$org, null];
    }

    private function input(Request $request): array
    {
        return $request->isJson() ? $request->jsonBody() : $request->getBody();
    }
}

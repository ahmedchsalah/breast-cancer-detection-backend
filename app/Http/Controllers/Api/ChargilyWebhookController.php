<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * Receives Chargily Pay webhook callbacks.
 * This route is PUBLIC (no Sanctum) — security is done via HMAC signature.
 */
class ChargilyWebhookController extends Controller
{
    #[OA\Post(
        path: "/payment/webhook",
        tags: ["Webhooks"],
        summary: "Handle Chargily Pay Webhook",
        description: "Public endpoint for Chargily to push payment status updates. Secured by HMAC signature.",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent()
        ),
        responses: [
            new OA\Response(response: 200, description: "Webhook received and processed"),
            new OA\Response(response: 400, description: "Missing signature or malformed payload"),
            new OA\Response(response: 403, description: "Invalid HMAC signature"),
        ]
    )]
    public function handle(Request $request): Response
    {
        // ── 1. Verify HMAC signature ────────────────────────────────────────
        $signature = $request->header('signature');
        $payload   = $request->getContent(); // raw body — must not be parsed yet

        if (empty($signature)) {
            Log::warning('Chargily webhook: missing signature header.');
            return response('Missing signature.', 400);
        }

        $secretKey         = trim(env('CHARGILY_SECRET_KEY'), " \"'");
        $computedSignature = hash_hmac('sha256', $payload, $secretKey);

        if (!hash_equals($signature, $computedSignature)) {
            Log::warning('Chargily webhook: invalid signature.', [
                'received' => $signature,
                'computed' => $computedSignature,
            ]);
            return response('Invalid signature.', 403);
        }

        // ── 2. Parse the event ───────────────────────────────────────────────
        $event    = json_decode($payload, true);
        $type     = $event['type'] ?? null;
        $checkout = $event['data'] ?? null;

        if (!$type || !$checkout) {
            return response('Malformed payload.', 400);
        }

        Log::info("Chargily webhook received: {$type}", ['checkout_id' => $checkout['id'] ?? null]);

        // ── 3. Dispatch based on event type ─────────────────────────────────
        match ($type) {
            'checkout.paid'     => $this->handlePaid($checkout),
            'checkout.failed',
            'checkout.canceled' => $this->handleFailed($checkout),
            default             => Log::info("Chargily webhook: unhandled event type '{$type}'."),
        };

        // ── 4. Always respond 200 to acknowledge receipt ─────────────────────
        return response('OK', 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function handlePaid(array $checkout): void
    {
        $chargilyCheckoutId = $checkout['id'];
        
        // Metadata is a nested array in V2: [ { "org_id": "...", ... } ]
        $rawMetadata = $checkout['metadata'] ?? [];
        $metadata = (isset($rawMetadata[0]) && is_array($rawMetadata[0])) ? $rawMetadata[0] : $rawMetadata;

        $payment = Payment::where('chargily_checkout_id', $chargilyCheckoutId)->first();

        if (!$payment) {
            Log::error("Chargily webhook: no payment found for checkout_id {$chargilyCheckoutId}");
            return;
        }

        if ($payment->status === 'completed') {
            Log::info("Chargily webhook: payment {$payment->id} already completed — skipping.");
            return;
        }

        // Extract info from metadata with correct keys
        $organizationId = $metadata['org_id']  ?? $payment->organization_id;
        $planId         = $metadata['plan_id'] ?? $payment->plan_id;
        $durationMonths = $metadata['months']  ?? $payment->duration_months ?? 1;

        $startsAt = Carbon::now();
        $endsAt   = $startsAt->copy()->addMonths((int) $durationMonths);

        // Create an active subscription record
        $subscription = Subscription::create([
            'organization_id' => $organizationId,
            'plan_id'         => $planId,
            'status'          => 'active',
            'starts_at'       => $startsAt,
            'ends_at'         => $endsAt,
        ]);

        // Mark payment as completed and link to subscription
        $payment->update([
            'status'          => 'completed',
            'transaction_id'  => $checkout['invoice_id'] ?? $chargilyCheckoutId,
            'payment_method'  => $checkout['payment_method'] ?? null,
            'subscription_id' => $subscription->id,
        ]);

        // Update the organization's subscription cache fields
        Organization::where('id', $organizationId)->update([
            'plan_id'               => $planId,
            'subscription_status'   => 'active',
            'subscription_ends_at'  => $endsAt->toDateString(),
        ]);

        Log::info("Chargily webhook: subscription activated for org {$organizationId}, ends {$endsAt}.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function handleFailed(array $checkout): void
    {
        $chargilyCheckoutId = $checkout['id'];

        $payment = Payment::where('chargily_checkout_id', $chargilyCheckoutId)->first();

        if (!$payment) {
            Log::error("Chargily webhook: no payment found for failed checkout_id {$chargilyCheckoutId}");
            return;
        }

        $payment->update(['status' => 'failed']);

        Log::info("Chargily webhook: payment {$payment->id} marked as failed.");
    }
}

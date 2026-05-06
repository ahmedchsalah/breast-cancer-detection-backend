<?php

namespace App\Http\Controllers\Api\OrgManager;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private const CHARGILY_API = 'https://pay.chargily.net/api/v2';

    private function org()
    {
        return auth()->user()->organization()->with('plan')->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/org/plans  — Public list of available plans
    // ─────────────────────────────────────────────────────────────────────────
    public function plans(): JsonResponse
    {
        $plans = Plan::orderBy('price')->get();

        return response()->json($plans);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/org/subscribe  — Initiate a Chargily checkout for a plan
    //
    // Body: { plan_id: int, duration_months: int (1|3|6|12) }
    //
    // Flow:
    //   1. Validate plan & duration
    //   2. Compute total amount (plan.price × months)
    //   3. Call Chargily API → get checkout_url
    //   4. Create a pending Payment record
    //   5. Return checkout_url to frontend  →  frontend redirects user there
    // ─────────────────────────────────────────────────────────────────────────
    public function subscribe(Request $request): JsonResponse
    {
        $user = auth()->user();
        $org  = $this->org();

        $validated = $request->validate([
            'plan_id'         => 'required|integer|exists:plans,id',
            'duration_months' => 'required|integer|in:1,3,6,12',
        ]);

        $plan     = Plan::findOrFail($validated['plan_id']);
        $months   = $validated['duration_months'];
        $amount   = (int) round($plan->price * $months); // Chargily wants integer (fils/centimes) — DZD is already integer

        // Build callback URLs
        $successUrl = config('app.frontend_url') . '/payment/success';
        $failureUrl = config('app.frontend_url') . '/payment/failure';
        $webhookUrl = url('/api/payment/webhook'); // Our public webhook endpoint

        // Call Chargily Pay v2 API
        $response = Http::withToken(config('services.chargily.secret_key'))
            ->post(self::CHARGILY_API . '/checkouts', [
                'amount'          => $amount,
                'currency'        => 'dzd',
                'success_url'     => $successUrl,
                'failure_url'     => $failureUrl,
                'webhook_endpoint'=> $webhookUrl,
                'locale'          => 'ar',
                'description'     => "Subscription: {$plan->name} — {$months} month(s) — {$org->name}",
                'metadata'        => [
                    'organization_id' => $org->id,
                    'plan_id'         => $plan->id,
                    'duration_months' => $months,
                    'user_id'         => $user->id,
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Chargily checkout creation failed', [
                'status'   => $response->status(),
                'body'     => $response->body(),
                'org_id'   => $org->id,
                'plan_id'  => $plan->id,
            ]);

            return response()->json([
                'message' => 'Failed to initiate payment. Please try again later.',
                'error'   => $response->json('message') ?? 'Unknown error from payment gateway.',
            ], 502);
        }

        $checkout = $response->json();

        // Persist a pending payment record immediately
        $payment = Payment::create([
            'organization_id'     => $org->id,
            'plan_id'             => $plan->id,
            'amount'              => $amount,
            'currency'            => 'DZD',
            'status'              => 'pending',
            'chargily_checkout_id'=> $checkout['id'],
            'checkout_url'        => $checkout['checkout_url'],
            'duration_months'     => $months,
        ]);

        return response()->json([
            'message'      => 'Checkout created. Redirect the user to the checkout URL.',
            'checkout_url' => $checkout['checkout_url'],
            'payment_id'   => $payment->id,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/org/payments  — List payment history for this org
    // ─────────────────────────────────────────────────────────────────────────
    public function history(): JsonResponse
    {
        $payments = Payment::where('organization_id', auth()->user()->organization_id)
            ->with('plan:id,name', 'subscription:id,status,starts_at,ends_at')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($payments);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/org/subscription  — Current subscription status
    // ─────────────────────────────────────────────────────────────────────────
    public function currentSubscription(): JsonResponse
    {
        $org = auth()->user()->organization()->with('plan')->first();

        $subscription = Subscription::where('organization_id', $org->id)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        return response()->json([
            'organization'  => [
                'id'                   => $org->id,
                'name'                 => $org->name,
                'subscription_status'  => $org->subscription_status,
                'subscription_ends_at' => $org->subscription_ends_at,
            ],
            'plan'         => $org->plan,
            'subscription' => $subscription,
        ]);
    }
}

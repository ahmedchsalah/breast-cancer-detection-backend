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
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PlanObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "name", type: "string"),
        new OA\Property(property: "description", type: "string", nullable: true),
        new OA\Property(property: "price", type: "number", format: "float"),
        new OA\Property(property: "max_patients", type: "integer", nullable: true),
        new OA\Property(property: "max_predictions_per_month", type: "integer", nullable: true),
        new OA\Property(property: "is_active", type: "boolean"),
    ]
 )]
#[OA\Schema(
    schema: "PaymentObject",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer"),
        new OA\Property(property: "organization_id", type: "integer"),
        new OA\Property(property: "plan_id", type: "integer"),
        new OA\Property(property: "amount", type: "number", format: "float"),
        new OA\Property(property: "currency", type: "string"),
        new OA\Property(property: "status", type: "string", enum: ["pending", "completed", "failed", "refunded"]),
        new OA\Property(property: "chargily_checkout_id", type: "string", nullable: true),
        new OA\Property(property: "checkout_url", type: "string", nullable: true),
        new OA\Property(property: "duration_months", type: "integer", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
    ]
 )]
class PaymentController extends Controller
{
    private function getChargilyApiUrl()
    {
        return config('services.chargily.mode') === 'test' 
            ? 'https://pay.chargily.net/test/api/v2' 
            : 'https://pay.chargily.net/api/v2';
    }
    private function org()
    {
        return auth()->user()->organization()->with('plan')->first();
    }

    // ============================================================
    //  PLANS
    // ============================================================

    #[OA\Get(
        path: "/org-manager/plans",
        tags: ["OrgManager — Billing"],
        summary: "List of available subscription plans",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of plans",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/PlanObject")
                )
            )
        ]
    )]
    public function plans(): JsonResponse
    {
        $plans = Plan::orderBy('price')->get();

        return response()->json($plans);
    }

    // ============================================================
    //  SUBSCRIBE
    // ============================================================

    #[OA\Post(
        path: "/org-manager/subscribe",
        tags: ["OrgManager — Billing"],
        summary: "Initiate a Chargily checkout for a plan",
        description: "Creates a checkout session via Chargily API and returns the checkout URL.",
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["plan_id", "duration_months"],
                properties: [
                    new OA\Property(property: "plan_id", type: "integer"),
                    new OA\Property(property: "duration_months", type: "integer", enum: [1, 3, 6, 12]),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Checkout created"),
            new OA\Response(response: 502, description: "Failed to initiate payment gateway"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
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
        
        $discountPercent = 0;
        if ($months === 3) {
            $discountPercent = 0.05;
        } elseif ($months === 6) {
            $discountPercent = 0.10;
        } elseif ($months === 12) {
            $discountPercent = 0.15;
        }

        $discountedPrice = $plan->price * $months * (1 - $discountPercent);
        $amount   = (int) round($discountedPrice * 100); // Chargily V2 expects centimes (amount * 100)

        // Build callback URLs
        $frontendUrl = rtrim(config('app.frontend_url', 'https://brecai-tester.vercel.app'), '/');
        $successUrl  = $frontendUrl . '/payment/success';
        $failureUrl  = $frontendUrl . '/payment/failure';
        
        // Ensure webhook URL is absolute and not localhost if possible
        $appUrl = config('app.url');
        $webhookUrl = (str_contains($appUrl, 'localhost') || !str_starts_with($appUrl, 'http'))
            ? null // If URL is invalid, let Chargily use the dashboard default
            : rtrim($appUrl, '/') . '/api/payment/webhook';

        try {
            // Call Chargily Pay v2 API
            $response = Http::withToken(config('services.chargily.secret_key'))
                ->timeout(10) // Prevent hanging
                ->post($this->getChargilyApiUrl() . '/checkouts', [
                    'amount'          => $amount,
                    'currency'        => 'dzd',
                    'success_url'     => $successUrl,
                    'failure_url'     => $failureUrl,
                    'webhook_endpoint'=> $webhookUrl,
                    'locale'          => 'ar',
                    'description'     => "Subscription: {$plan->name} — {$months} month(s) — {$org->name}",
                    'metadata'        => [
                        'organization_id' => (string) $org->id, // MUST be strings for Chargily V2
                        'plan_id'         => (string) $plan->id,
                        'duration_months' => (string) $months,
                        'user_id'         => (string) $user->id,
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Chargily checkout creation failed', [
                    'http_status'     => $response->status(),
                    'chargily_body'   => $response->body(),
                    'webhook_url'     => $webhookUrl,
                    'metadata'        => [
                        'org_id' => $org->id,
                        'plan_id' => $plan->id,
                    ]
                ]);

                return response()->json([
                    'message' => 'Failed to initiate payment. Please try again later.',
                    'error'   => $response->json('message') ?? $response->json('error') ?? $response->body() ?? 'Gateway rejection.',
                ], 502);
            }
        } catch (\Exception $e) {
            Log::error('Critical failure in PaymentController', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Internal server error while connecting to payment gateway.',
                'error'   => $e->getMessage()
            ], 500);
        }

        $checkout = $response->json();

        // Persist a pending payment record immediately
        // Store amount in DZD (human-readable) — NOT in centimes
        $payment = Payment::create([
            'organization_id'      => $org->id,
            'plan_id'              => $plan->id,
            'amount'               => $discountedPrice, // DZD, not centimes
            'currency'             => 'DZD',
            'status'               => 'pending',
            'chargily_checkout_id' => $checkout['id'],
            'checkout_url'         => $checkout['checkout_url'],
            'duration_months'      => $months,
        ]);

        Log::info('Chargily checkout created', [
            'payment_id'    => $payment->id,
            'checkout_id'   => $checkout['id'],
            'checkout_url'  => $checkout['checkout_url'],
            'amount_dzd'    => $discountedPrice,
            'amount_centimes' => $amount,
        ]);

        return response()->json([
            'message'      => 'Checkout created. Redirect the user to the checkout URL.',
            'checkout_url' => $checkout['checkout_url'],
            'payment_id'   => $payment->id,
        ], 201);
    }

    // ============================================================
    //  HISTORY
    // ============================================================

    #[OA\Get(
        path: "/org-manager/payments",
        tags: ["OrgManager — Billing"],
        summary: "List payment history for this organization",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Paginated list of payments",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/PaymentObject")),
                        new OA\Property(property: "current_page", type: "integer"),
                        new OA\Property(property: "total", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function history(): JsonResponse
    {
        $payments = Payment::where('organization_id', auth()->user()->organization_id)
            ->with('plan:id,name', 'subscription:id,status,starts_at,ends_at')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($payments);
    }

    // ============================================================
    //  CURRENT SUBSCRIPTION
    // ============================================================

    #[OA\Get(
        path: "/org-manager/subscription",
        tags: ["OrgManager — Billing"],
        summary: "Current subscription status",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Current subscription details",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "organization", type: "object", properties: [
                            new OA\Property(property: "id", type: "integer"),
                            new OA\Property(property: "name", type: "string"),
                            new OA\Property(property: "subscription_status", type: "string"),
                            new OA\Property(property: "subscription_ends_at", type: "string", format: "date", nullable: true),
                        ]),
                        new OA\Property(property: "plan", ref: "#/components/schemas/PlanObject"),
                        new OA\Property(property: "subscription", type: "object", nullable: true),
                    ]
                )
            )
        ]
    )]
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

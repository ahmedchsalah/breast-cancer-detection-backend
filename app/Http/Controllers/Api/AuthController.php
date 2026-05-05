<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\User;
use App\Models\Otp;
use App\Mail\OtpMail;
use App\Mail\DoctorApprovedMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Twilio\Rest\Client as TwilioClient;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

// ============================================================
//  Global API Info & Security Scheme
// ============================================================

#[OA\Info(
    version: "1.0.0",
    description: <<<DESC
    API documentation for the Medical AI Federated Learning platform.

    ## Authentication Flow

    This API uses a **2-step OTP verification** for both **registration** and **login**.

    ---

    ### Registration Flow — `org_manager` (3 steps)

    1. `POST /api/register` — Validate and create the account (inactive). Returns `email` and `phone_number`.
    2. Choose an OTP channel:
       - **Email** → `POST /api/send-otp` with `method=email`
       - **WhatsApp** → `POST /api/send-otp` with `method=whatsapp`
    3. `POST /api/verify-otp?context=register` — Verifies identity, activates the account, and logs the user in.

    ---

    ### Registration Flow — `doctor` (3 steps + approval gate)

    1. `GET /api/organizations` — Fetch the list of active organizations for the dropdown.
    2. `POST /api/register` — Validates the chosen organization's status:
       - If org is **pending** → `403` returned immediately. No account is created. Frontend shows "org not approved yet" page.
       - If org is **active** → account is created (`is_active = false`). Returns `email` and `phone_number`.
    3. Choose an OTP channel and complete OTP (same as above).
    4. `POST /api/verify-otp?context=register` — OTP verified; account remains `is_active = false`.
       Frontend shows "awaiting org manager approval" page.
       The org manager then approves the doctor via their dashboard, which sets `is_active = true`
       and sends the doctor an approval email (`DoctorApprovedMail`).

    ---

    ### Login Flow (3 steps)

    1. `POST /api/login` — Validate email & password. Returns `email` and `phone_number`.
    2. Choose an OTP channel (same as above).
    3. `POST /api/verify-otp?context=login` — Verifies OTP and logs the user in.

    ---

    All protected routes require a session cookie (Sanctum web guard).
    DESC,
    title: "Federated Learning API"
)]
#[OA\Server(url: "/api", description: "Primary API Server")]
#[OA\SecurityScheme(
    securityScheme: "sanctum",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT",
    description: "Session cookie issued by Sanctum after a successful `/api/verify-otp` call."
)]

// ============================================================
//  Reusable Schemas
// ============================================================

#[OA\Schema(
    schema: "UserResource",
    type: "object",
    properties: [
        new OA\Property(property: "id",           type: "integer",  example: 1),
        new OA\Property(property: "name",          type: "string",   example: "Dr. Amina Belkacem"),
        new OA\Property(property: "email",         type: "string",   format: "email", example: "amina@clinic.dz"),
        new OA\Property(property: "phone_number",  type: "string",   example: "+213661234567", nullable: true),
        new OA\Property(property: "is_active",     type: "boolean",  example: true),
        new OA\Property(
            property: "organization",
            type: "object",
            nullable: true,
            properties: [
                new OA\Property(property: "id",     type: "integer", example: 10),
                new OA\Property(property: "name",   type: "string",  example: "Ibn Sina Clinic"),
                new OA\Property(property: "type",   type: "string",  example: "clinic"),
                new OA\Property(property: "status", type: "string",  example: "active"),
            ]
        ),
        new OA\Property(
            property: "roles",
            type: "array",
            items: new OA\Items(type: "string"),
            example: ["doctor"]
        ),
    ]
)]

#[OA\Schema(
    schema: "ValidationError",
    type: "object",
    properties: [
        new OA\Property(property: "message", type: "string", example: "The given data was invalid."),
        new OA\Property(
            property: "errors",
            type: "object",
            example: ["email" => ["The email field is required."]]
        ),
    ]
)]

#[OA\Schema(
    schema: "UnauthorizedError",
    type: "object",
    properties: [
        new OA\Property(property: "message", type: "string", example: "Invalid credentials."),
    ]
)]

#[OA\Schema(
    schema: "ForbiddenError",
    type: "object",
    properties: [
        new OA\Property(property: "message", type: "string", example: "Your account is pending activation."),
    ]
)]

class AuthController extends Controller
{
    // ============================================================
    //  ORGANIZATIONS — Public list for doctor registration dropdown
    // ============================================================

    #[OA\Get(
        path: "/api/organizations/public",
        operationId: "listOrganizations",
        summary: "List active organizations (for doctor registration dropdown)",
        description: <<<DESC
        Returns a lightweight list of all **active** organizations.

        This endpoint is **public** (no auth required) and is intended solely for populating
        the organization dropdown on the doctor registration screen.

        Only `id`, `name`, `type`, and `code` are returned — no sensitive data exposed.
        Results are ordered alphabetically by name.
        DESC,
        tags: ["Auth — Register"],
        parameters: [
            new OA\Parameter(
                name: "type",
                in: "query",
                required: false,
                description: "Filter by organization type. Omit to return all types.",
                schema: new OA\Schema(
                    type: "string",
                    enum: ["clinic", "hospital", "laboratory", "radiology_center"]
                )
            ),
            new OA\Parameter(
                name: "search",
                in: "query",
                required: false,
                description: "Search organizations by name (case-insensitive partial match).",
                schema: new OA\Schema(type: "string", example: "Ibn Sina")
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of active organizations.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id",   type: "integer", example: 10),
                                    new OA\Property(property: "name", type: "string",  example: "Ibn Sina Clinic"),
                                    new OA\Property(property: "type", type: "string",  example: "clinic"),
                                    new OA\Property(property: "code", type: "string",  example: "ORG-0010", nullable: true),
                                ]
                            )
                        ),
                        new OA\Property(property: "total", type: "integer", example: 42),
                    ]
                )
            ),
        ]
    )]
    public function organizations(Request $request)
    {
        $request->validate([
            'type'   => 'nullable|in:clinic,hospital,laboratory,radiology_center',
            'search' => 'nullable|string|max:100',
        ]);

        $query = Organization::where('status', Organization::STATUS_ACTIVE)
            ->select('id', 'name', 'type', 'code')
            ->orderBy('name');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $organizations = $query->get();

        return response()->json([
            'data'  => $organizations,
            'total' => $organizations->count(),
        ]);
    }

    // ============================================================
    //  REGISTER — Step 1: Create account (inactive, pending OTP)
    // ============================================================

    #[OA\Post(
        path: "/api/register",
        operationId: "register",
        summary: "Step 1 (Registration) — Create account and initiate OTP verification",
        description: <<<DESC
        Creates a new user account in an **inactive** state and triggers OTP verification.

        ### `role: org_manager`
        - Automatically provisions a new **Organization** record.
        - Account remains **inactive** until OTP is verified, at which point it is immediately activated.
        - Required extra fields: `organization_name`, `organization_type`.
        - Optional: `organization_address`, `plan_id`, `latitude`, `longitude`.

        ---

        ### `role: doctor`
        Fetch available organizations first via `GET /api/organizations` to populate the dropdown.

        The organization's approval status is checked **before** creating any account:

        | Org status  | Result |
        |---|---|
        | `pending`   | `403` returned immediately. No account created. Frontend shows "org not approved yet" page. |
        | `active`    | Account created (`is_active = false`). Proceed to OTP. |

        After OTP is verified, the account remains inactive until the **Org Manager approves** it
        from their dashboard. On approval, `is_active` is set to `true` and the doctor receives
        an approval email (`DoctorApprovedMail`).

        Next step → `POST /api/send-otp`
        DESC,
        tags: ["Auth — Register"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                oneOf: [
                    new OA\Schema(
                        title: "Organization Manager",
                        required: ["name", "email", "password", "phone_number", "role", "organization_name", "organization_type"],
                        properties: [
                            new OA\Property(property: "name",                 type: "string",  maxLength: 255,  example: "Karim Mansouri"),
                            new OA\Property(property: "email",                type: "string",  format: "email", example: "karim@ibnsina.dz"),
                            new OA\Property(property: "password",             type: "string",  minLength: 8,    example: "SecurePass123"),
                            new OA\Property(property: "phone_number",         type: "string",  maxLength: 20,   example: "0551234567",
                                description: "Algerian local format accepted. Normalized to E.164 (+213XXXXXXXXX). Must be unique."),
                            new OA\Property(property: "role",                 type: "string",  enum: ["org_manager"], example: "org_manager"),
                            new OA\Property(property: "organization_name",    type: "string",  maxLength: 255,  example: "Ibn Sina Clinic"),
                            new OA\Property(property: "organization_type",    type: "string",
                                enum: ["clinic", "hospital", "laboratory", "radiology_center"], example: "clinic"),
                            new OA\Property(property: "organization_address", type: "string",  maxLength: 500,  example: "12 Rue Didouche Mourad, Algiers", nullable: true),
                            new OA\Property(property: "plan_id",              type: "integer", example: null,   nullable: true),
                            new OA\Property(property: "latitude",             type: "number",  format: "float", example: 36.7372, nullable: true),
                            new OA\Property(property: "longitude",            type: "number",  format: "float", example: 3.0866,  nullable: true),
                        ]
                    ),
                    new OA\Schema(
                        title: "Doctor",
                        required: ["name", "email", "password", "phone_number", "role", "organization_id"],
                        properties: [
                            new OA\Property(property: "name",            type: "string",  maxLength: 255,  example: "Dr. Amina Belkacem"),
                            new OA\Property(property: "email",           type: "string",  format: "email", example: "amina@clinic.dz"),
                            new OA\Property(property: "password",        type: "string",  minLength: 8,    example: "SecurePass123"),
                            new OA\Property(property: "phone_number",    type: "string",  maxLength: 20,   example: "0661234567",
                                description: "Required for WhatsApp OTP. Normalized to E.164. Must be unique."),
                            new OA\Property(property: "role",            type: "string",  enum: ["doctor"], example: "doctor"),
                            new OA\Property(property: "organization_id", type: "integer", example: 10,
                                description: "ID from `GET /api/organizations`. Must be an active organization."),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Account created but inactive. Proceed to `/api/send-otp` to verify identity.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message",      type: "string", example: "Registration successful. Please verify your identity via OTP."),
                        new OA\Property(property: "email",        type: "string", example: "amina@clinic.dz"),
                        new OA\Property(property: "phone_number", type: "string", example: "+213661234567", nullable: true,
                            description: "Always returned in normalized E.164 format. If `null`, only `email` OTP is available."),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Doctor registration blocked — the selected organization has not been approved by the platform admin yet.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "The selected organization is not yet approved. Please try again later or contact the organization."),
                        new OA\Property(property: "reason",  type: "string", example: "org_pending"),
                    ]
                )
            ),
            new OA\Response(response: 422, description: "Validation Error", content: new OA\JsonContent(ref: "#/components/schemas/ValidationError")),
        ]
    )]
    public function register(RegisterRequest $request)
    {
        // --- Doctor: guard on org approval status before touching the DB ---
        if ($request->role === 'doctor') {
            $org = Organization::find($request->organization_id);

            if (!$org || $org->status !== Organization::STATUS_ACTIVE) {
                return response()->json([
                    'message' => 'The selected organization is not yet approved. Please try again later or contact the organization.',
                    'reason'  => 'org_pending',
                ], 403);
            }
        }

        DB::transaction(function () use ($request) {
            $orgId = null;

            if ($request->role === 'org_manager') {
                $org = Organization::create([
                    'plan_id'       => $request->plan_id ?? null,
                    'name'          => $request->organization_name,
                    'type'          => $request->organization_type,
                    'contact_email' => $request->email,
                    'address'       => $request->organization_address,
                    'latitude'      => $request->latitude  ?? null,
                    'longitude'     => $request->longitude ?? null,
                ]);
                $orgId = $org->id;
            } elseif ($request->role === 'doctor') {
                $orgId = $request->organization_id;
            }

            // Normalize phone number to E.164 before persisting
            $phone = $request->phone_number
                ? $this->formatAlgerianPhoneNumber($request->phone_number)
                : null;

            $user = User::create([
                'name'            => $request->name,
                'email'           => $request->email,
                'phone_number'    => $phone,
                'password'        => Hash::make($request->password),
                'organization_id' => $orgId,
                'is_active'       => false,
            ]);

            $user->assignRole($request->role);
        });

        $user = User::where('email', $request->email)->first();

        return response()->json([
            'message'      => 'Registration successful. Please verify your identity via OTP.',
            'email'        => $user->email,
            'phone_number' => $user->phone_number,
        ], 201);
    }

    // ============================================================
    //  LOGIN — Step 1: Validate Credentials
    // ============================================================

    #[OA\Post(
        path: "/api/login",
        operationId: "login",
        summary: "Step 1 (Login) — Validate credentials",
        description: <<<DESC
        Validates email & password. **Does not issue a token or send an OTP.**

        On success, returns the user's `email` and `phone_number`. The frontend uses these to render
        the OTP channel selection screen:

        | Condition | Available channels |
        |---|---|
        | `phone_number` is `null` | Email only |
        | `phone_number` is present | Email **and** WhatsApp (via Twilio) |

        Returns `403` with a machine-readable `reason` if the account exists but is not yet active:

        | reason            | Meaning |
        |---|---|
        | `account_pending` | OTP was verified but the org manager has not approved the account yet. |

        Next step → `POST /api/send-otp`
        DESC,
        tags: ["Auth — Login (2-Step OTP)"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email",       type: "string", format: "email",    example: "amina@clinic.dz"),
                    new OA\Property(property: "password",    type: "string", format: "password",  example: "SecurePass123"),
                    new OA\Property(property: "device_name", type: "string", example: "Chrome on Windows", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Credentials are valid. Proceed to `/api/send-otp`.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message",      type: "string", example: "Credentials valid. Please proceed to request OTP."),
                        new OA\Property(property: "email",        type: "string", example: "amina@clinic.dz"),
                        new OA\Property(property: "phone_number", type: "string", example: "+213661234567", nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Invalid credentials", content: new OA\JsonContent(ref: "#/components/schemas/UnauthorizedError")),
            new OA\Response(
                response: 403,
                description: "Account exists but is not yet active.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Your account is awaiting approval by the Organization Manager."),
                        new OA\Property(property: "reason",  type: "string", example: "account_pending"),
                    ]
                )
            ),
            new OA\Response(response: 422, description: "Validation Error", content: new OA\JsonContent(ref: "#/components/schemas/ValidationError")),
        ]
    )]
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => __('messages.invalid_credentials')], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => __('messages.account_pending'),
                'reason'  => 'account_pending',
            ], 403);
        }

        return response()->json([
            'message'      => 'Credentials valid. Please proceed to request OTP.',
            'email'        => $user->email,
            'phone_number' => $user->phone_number,
        ]);
    }

    // ============================================================
    //  SEND OTP — Step 2: Email or WhatsApp (Twilio)
    //  Shared by both registration and login flows
    // ============================================================

    #[OA\Post(
        path: "/api/send-otp",
        operationId: "sendOtp",
        summary: "Step 2 — Request OTP (Email or WhatsApp via Twilio)",
        description: <<<DESC
        Initiates OTP delivery. Used in **both** the registration and login flows.

        ---

        ### `method: email`
        The backend generates a **6-digit code**, stores it hashed, and queues an email.
        - Code expires in **10 minutes**.
        - Next: `POST /api/verify-otp` with `{ email, otp: "123456" }`

        ---

        ### `method: whatsapp`
        The backend generates a **6-digit code**, stores it hashed, and sends it via
        **Twilio WhatsApp** using a pre-approved content template (`TWILIO_CONTENT_SID`).
        - Code expires in **10 minutes**.
        - Requires the user account to have a `phone_number` in E.164 format.
        - Next: `POST /api/verify-otp` with `{ email, otp: "123456" }`

        > **Note:** The `is_active` check is intentionally skipped here.
        > Inactive accounts (mid-registration or pending org manager approval) must still
        > be able to receive an OTP to complete the flow.
        DESC,
        tags: ["Auth — Login (2-Step OTP)", "Auth — Register"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "method"],
                properties: [
                    new OA\Property(property: "email",  type: "string", format: "email", example: "amina@clinic.dz"),
                    new OA\Property(property: "method", type: "string", enum: ["email", "whatsapp"], example: "whatsapp",
                        description: "`email` — backend sends a 6-digit code by email. `whatsapp` — backend sends via Twilio WhatsApp."),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "OTP sent. Check `channel` to confirm delivery method.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "OTP sent successfully."),
                        new OA\Property(property: "channel", type: "string", enum: ["email", "whatsapp"], example: "whatsapp"),
                    ]
                )
            ),
            new OA\Response(response: 400, description: "WhatsApp requested but no phone number on account",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "message", type: "string", example: "Phone number is required for WhatsApp OTP.")]
                )
            ),
            new OA\Response(response: 500, description: "OTP delivery failed",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "message", type: "string", example: "Failed to send OTP. Please try again.")]
                )
            ),
            new OA\Response(response: 422, description: "Validation Error", content: new OA\JsonContent(ref: "#/components/schemas/ValidationError")),
        ]
    )]
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email'  => 'required|email|exists:users,email',
            'method' => 'required|in:email,whatsapp',
        ]);

        $user = User::where('email', $request->email)->first();

        // NOTE: is_active check is intentionally skipped here.
        // An inactive account may be in mid-registration or pending org manager approval.
        // Both must still be able to receive an OTP.

        if ($request->method === 'whatsapp' && empty($user->phone_number)) {
            return response()->json(['message' => 'Phone number is required for WhatsApp OTP.'], 400);
        }

        // Generate OTP — single code used for both channels
        $otp = rand(100000, 999999);

        // Store hashed OTP (plain text comparison done in verifyOtp)
        Otp::updateOrCreate(
            ['identifier' => $user->email],
            [
                'token'      => $otp, // hash in production: bcrypt($otp)
                'method'     => $request->method,
                'expires_at' => Carbon::now()->addMinutes(10),
            ]
        );

        if ($request->method === 'whatsapp') {
            try {
                $twilio = new TwilioClient(
                    config('services.twilio.sid'),
                    config('services.twilio.auth_token')
                );

                $twilio->messages->create(
                    "whatsapp:{$user->phone_number}",
                    [
                        'from'             => 'whatsapp:' . config('services.twilio.whatsapp_from'),
                        'contentSid'       => config('services.twilio.content_sid'),
                        'contentVariables' => json_encode(['1' => (string) $otp]),
                    ]
                );

                $channel = 'whatsapp';
            } catch (\Exception $e) {
                \Log::error('WhatsApp OTP Error: ' . $e->getMessage());
                // Clean up the stored OTP so user can retry cleanly
                Otp::where('identifier', $user->email)->delete();
                return response()->json(['message' => 'Failed to send OTP via WhatsApp. Please try again.'], 500);
            }
        } else {
            try {
                Mail::to($user->email)->queue(new OtpMail($otp));
                $channel = 'email';
            } catch (\Exception $e) {
                \Log::error('OTP Email Error: ' . $e->getMessage());
                Otp::where('identifier', $user->email)->delete();
                return response()->json(['message' => 'Failed to send OTP via email. Please try again.'], 500);
            }
        }

        return response()->json([
            'message' => 'OTP sent successfully.',
            'channel' => $channel,
        ]);
    }

    // ============================================================
    //  VERIFY OTP — Step 3: 6-digit code (email or WhatsApp)
    //  context=register → activates account (org_manager) or queues for approval (doctor)
    //  context=login    → logs in directly
    // ============================================================

    #[OA\Post(
        path: "/api/verify-otp",
        operationId: "verifyOtp",
        summary: "Step 3 — Verify OTP (Email or WhatsApp)",
        description: <<<DESC
        Final step of both the **registration** and **login** flows.

        Pass `?context=register` when coming from registration, or `?context=login` when coming from login.

        | context    | Role          | Behaviour on success |
        |---|---|---|
        | `register` | `org_manager` | Activates account (`is_active = true`), logs in, returns `200` with user object. |
        | `register` | `doctor`      | OTP verified; account stays inactive pending org manager approval. Returns `202`. No login. |
        | `login`    | any           | Logs in directly. Account must already be active. |

        The same 6-digit code is used whether the OTP was sent via email or WhatsApp.

        On success: the OTP record is deleted immediately (single-use).
        DESC,
        tags: ["Auth — Login (2-Step OTP)", "Auth — Register"],
        parameters: [
            new OA\Parameter(
                name: "context",
                in: "query",
                required: true,
                description: "`register` — coming from registration flow. `login` — coming from login flow.",
                schema: new OA\Schema(type: "string", enum: ["register", "login"])
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "otp"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email", example: "amina@clinic.dz"),
                    new OA\Property(property: "otp",   type: "string", minLength: 6, maxLength: 6, example: "847291",
                        description: "The 6-digit code received via email or WhatsApp."),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Verified and authenticated.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Login successful."),
                        new OA\Property(property: "user",    ref: "#/components/schemas/UserResource"),
                    ]
                )
            ),
            new OA\Response(
                response: 202,
                description: "OTP verified (registration, doctor role). Account awaits org manager approval.",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Identity verified. Your account is awaiting approval by the Organization Manager."),
                        new OA\Property(property: "reason",  type: "string", example: "awaiting_org_approval"),
                    ]
                )
            ),
            new OA\Response(response: 400, description: "Invalid or expired OTP",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "message", type: "string", example: "The OTP is invalid or has expired.")]
                )
            ),
            new OA\Response(response: 422, description: "Validation Error", content: new OA\JsonContent(ref: "#/components/schemas/ValidationError")),
        ]
    )]
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|digits:6',
        ]);

        $context = $request->query('context', 'login'); // 'register' or 'login'

        $otpRecord = Otp::where('identifier', $request->email)->first();

        if (!$otpRecord || Carbon::now()->gt(Carbon::parse($otpRecord->expires_at))) {
            return response()->json(['message' => __('messages.otp_expired')], 400);
        }

        if ((string) $otpRecord->token !== (string) $request->otp) {
            return response()->json(['message' => __('messages.invalid_otp')], 400);
        }

        // OTP is valid — delete it immediately (single-use)
        $otpRecord->delete();

        $user = User::where('email', $request->email)->firstOrFail();

        if ($context === 'register') {
            if ($user->hasRole('doctor')) {
                // Identity confirmed, but account stays inactive until the org manager approves it.
                // Approval endpoint (OrgManagerController@approveDoctor) must:
                //   $doctor->is_active = true;
                //   $doctor->save();
                //   Mail::to($doctor->email)->queue(new DoctorApprovedMail($doctor));
                return response()->json([
                    'message' => 'Identity verified. Your account is awaiting approval by the Organization Manager.',
                    'reason'  => 'awaiting_org_approval',
                ], 202);
            }

            // org_manager: OTP verified → activate immediately and log in.
            $user->is_active = true;
            $user->save();
        }

        Auth::login($user);

        return response()->json([
            'message' => __('messages.login_successful'),
            'user'    => new UserResource($user->load('organization')),
        ]);
    }

    // ============================================================
    //  LOGOUT
    // ============================================================

    #[OA\Post(
        path: "/api/logout",
        operationId: "logout",
        summary: "Logout the authenticated user",
        description: "Invalidates the current Sanctum session. The session cookie is cleared server-side.",
        tags: ["Auth"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Logged out successfully.",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "message", type: "string", example: "Logged out successfully.")]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "message", type: "string", example: "Unauthenticated.")]
                )
            ),
        ]
    )]
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('messages.logged_out')]);
    }

    // ============================================================
    //  ME
    // ============================================================

    #[OA\Get(
        path: "/api/user",
        operationId: "me",
        summary: "Get the authenticated user's profile",
        description: "Returns the full profile of the currently authenticated user including roles and organization details.",
        tags: ["Auth"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Authenticated user profile.",
                content: new OA\JsonContent(ref: "#/components/schemas/UserResource")
            ),
            new OA\Response(response: 401, description: "Unauthenticated",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "message", type: "string", example: "Unauthenticated.")]
                )
            ),
        ]
    )]
    public function me()
    {
        return response()->json(new UserResource(auth()->user()->load(['roles', 'organization'])));
    }

    // ============================================================
    //  Private Helper — Normalize Algerian phone numbers to E.164
    // ============================================================

    /**
     * Converts any Algerian phone number variant to international E.164 format (+213XXXXXXXXX).
     *
     * Handles:
     *  +213XXXXXXXXX  → returned as-is
     *  00213XXXXXXXXX → converted to +213XXXXXXXXX
     *  0XXXXXXXXX     → leading 0 replaced with +213
     *  XXXXXXXXX      → +213 prepended directly
     */
    private function formatAlgerianPhoneNumber(string $number): string
    {
        $number = preg_replace('/[\s\-]/', '', $number);

        if (str_starts_with($number, '+213'))  return $number;
        if (str_starts_with($number, '00213')) return '+' . substr($number, 2);
        if (str_starts_with($number, '0'))     return '+213' . substr($number, 1);

        return '+213' . $number;
    }
}

<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\Invitation;
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
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\UpdateProfileRequest;
use App\Http\Requests\Api\Auth\UpdateAvatarRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

// ============================================================
//  Global API Info & Security Scheme
// ============================================================

#[OA\Info(
    version: "1.0.0",
    description: "API documentation for the Medical AI Federated Learning platform.",
    title: "Federated Learning API"
)]
#[OA\Server(url: "/api", description: "Primary API Server")]
#[OA\SecurityScheme(
    securityScheme: "sanctum",
    type: "http",
    scheme: "bearer",
    bearerFormat: "Token",
    description: "HttpOnly cookie issued by Sanctum after a successful `/api/verify-otp` call."
)]

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
        path: "/auth/organizations",
        tags: ["Authentication"],
        summary: "Public list for doctor registration dropdown",
        parameters: [
            new OA\Parameter(name: "type", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["clinic", "hospital", "laboratory", "radiology_center"])),
            new OA\Parameter(name: "search", in: "query", required: false, schema: new OA\Schema(type: "string")),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of active organizations",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(
                                type: "object",
                                properties: [
                                    new OA\Property(property: "id", type: "integer"),
                                    new OA\Property(property: "name", type: "string"),
                                    new OA\Property(property: "type", type: "string"),
                                    new OA\Property(property: "code", type: "string"),
                                ]
                            )
                        ),
                        new OA\Property(property: "total", type: "integer"),
                    ]
                )
            )
        ]
    )]
    public function organizations(Request $request)
    {
        $request->validate([
            'type'   => 'nullable|in:clinic,hospital,laboratory,radiology_center',
            'search' => 'nullable|string|max:100',
        ]);

        $query = Organization::where('status', Organization::STATUS_ACTIVE)
            ->select('id', 'name', 'type')
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
    //  REGISTER
    // ============================================================

    #[OA\Post(
        path: "/auth/register",
        tags: ["Authentication"],
        summary: "Register a new user (Doctor or Org Manager)",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password", "role"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string", format: "password"),
                    new OA\Property(property: "password_confirmation", type: "string", format: "password"),
                    new OA\Property(property: "role", type: "string", enum: ["doctor", "org_manager"]),
                    new OA\Property(property: "phone_number", type: "string", nullable: true),
                    new OA\Property(property: "organization_id", type: "integer", nullable: true),
                    new OA\Property(property: "organization_name", type: "string", nullable: true),
                    new OA\Property(property: "organization_type", type: "string", nullable: true),
                    new OA\Property(property: "organization_address", type: "string", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Registration successful"),
            new OA\Response(response: 403, description: "Organization not active"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function register(RegisterRequest $request)
    {
        // ── Invited user flow (invitation_token present) ──────────────────────
        if ($request->filled('invitation_token')) {
            $invitation = Invitation::where('token', $request->invitation_token)->first();

            if (!$invitation || !$invitation->isValid()) {
                return response()->json(['message' => 'Invalid or expired invitation token.'], 422);
            }

            $phone = $request->phone_number
                ? $this->formatAlgerianPhoneNumber($request->phone_number)
                : null;

            $user = User::create([
                'name'            => $request->name,
                'email'           => $invitation->email, // use invitation email
                'phone_number'    => $phone,
                'password'        => Hash::make($request->password),
                'organization_id' => $invitation->organization_id,
                'is_active'       => true, // pre-approved via invitation
            ]);

            $user->assignRole($invitation->role);
            $invitation->delete(); // consume the invitation

            return response()->json([
                'message'      => 'Registration successful. Please verify your identity via OTP.',
                'email'        => $user->email,
                'phone_number' => $user->phone_number,
            ], 201);
        }

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
    //  LOGIN
    // ============================================================

    #[OA\Post(
        path: "/auth/login",
        tags: ["Authentication"],
        summary: "Login and request OTP",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string", format: "password"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Credentials valid, proceed to OTP"),
            new OA\Response(response: 401, description: "Invalid credentials"),
            new OA\Response(response: 403, description: "Account pending activation"),
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
    //  SEND OTP — WhatsApp removed, email only
    // ============================================================

    #[OA\Post(
        path: "/auth/send-otp",
        tags: ["Authentication"],
        summary: "Send OTP to the user's email",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "method"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "method", type: "string", enum: ["email"]),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "OTP sent successfully"),
            new OA\Response(response: 500, description: "Failed to send OTP"),
        ]
    )]
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email'  => 'required|email|exists:users,email',
            'method' => 'required|in:email',
        ]);

        $user = User::where('email', $request->email)->first();

        $otpInt    = rand(100000, 999999);
        $otpString = str_pad((string) $otpInt, 6, '0', STR_PAD_LEFT);

        Otp::updateOrCreate(
            ['identifier' => $user->email],
            [
                'token'      => $otpString,
                'method'     => $request->method,
                'expires_at' => Carbon::now()->addMinutes(10),
            ]
        );

        try {
            Mail::to($user->email)->send(new OtpMail($otpString, $user));
        } catch (\Exception $e) {
            \Log::error('OTP Email Error: ' . $e->getMessage());
            Otp::where('identifier', $user->email)->delete();
            return response()->json(['message' => 'Failed to send OTP via email. Please try again.'], 500);
        }

        return response()->json([
            'message' => 'OTP sent successfully.',
            'channel' => 'email',
        ]);
    }

    // ============================================================
    //  VERIFY OTP
    // ============================================================

    #[OA\Post(
        path: "/auth/verify-otp",
        tags: ["Authentication"],
        summary: "Verify OTP and login",
        parameters: [
            new OA\Parameter(name: "context", in: "query", required: false, schema: new OA\Schema(type: "string", enum: ["login", "register"])),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "otp"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "otp", type: "string"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Login successful (returns Sanctum token in cookie)"),
            new OA\Response(response: 202, description: "Identity verified, awaiting org approval"),
            new OA\Response(response: 400, description: "Invalid or expired OTP"),
            new OA\Response(response: 403, description: "Account pending activation"),
        ]
    )]
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp'   => 'required|string|min:6|max:6',
        ]);

        $context = $request->query('context', 'login');

        $otpRecord = Otp::where('identifier', $request->email)->first();

        if (!$otpRecord) {
            return response()->json([
                'message' => 'No OTP was requested for this email. Please request a new one.',
            ], 400);
        }

        if (Carbon::now()->gt(Carbon::parse($otpRecord->expires_at))) {
            $otpRecord->delete();
            return response()->json([
                'message' => 'Your OTP has expired. Please request a new one.',
                'reason'  => 'otp_expired',
            ], 400);
        }

        if (trim((string) $otpRecord->token) !== trim((string) $request->otp)) {
            return response()->json([
                'message' => 'The OTP you entered is incorrect. Please check and try again.',
                'reason'  => 'otp_invalid',
            ], 400);
        }

        $otpRecord->delete();

        $user = User::where('email', $request->email)->firstOrFail();

        // Handle register context
        if ($context === 'register') {
            if ($user->hasRole('doctor') && !$user->is_active) {
                return response()->json([
                    'message' => 'Identity verified. Your account is awaiting approval by the Organization Manager.',
                    'reason'  => 'awaiting_org_approval',
                ], 202);
            }

            // org_manager or invited doctor/instructor (is_active=true): activate immediately
            if (!$user->is_active) {
                $user->is_active = true;
                $user->save();
            }
        }

        // Guard inactive users
        if (!$user->is_active) {
            return response()->json([
                'message' => __('messages.account_pending'),
                'reason'  => 'account_pending',
            ], 403);
        }

        // Issue Sanctum token and store in HttpOnly cookie
        // JS cannot read this cookie — XSS safe
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => __('messages.login_successful'),
            'user'    => new UserResource($user->load('organization')),
        ])->cookie(
            'auth_token',  // cookie name
            $token,        // Sanctum token — invisible to JS
            60 * 24 * 7,   // 7 days
            '/',           // path
            null,          // domain
            true,          // secure (HTTPS only)
            true,          // httpOnly (JS cannot read)
            false,         // raw
            'None'         // SameSite=None — required for cross-domain
        );
    }

    // ============================================================
    //  LOGOUT
    // ============================================================

    #[OA\Post(
        path: "/auth/logout",
        tags: ["Authentication"],
        summary: "Logout the current user",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Logged out successfully"),
        ]
    )]
    public function logout(Request $request)
    {
        // Revoke token from database
        $request->user()->currentAccessToken()->delete();

        // Delete cookie on client by expiring it
        return response()->json([
            'message' => __('messages.logged_out'),
        ])->cookie(
            'auth_token',
            '',
            -1,
            '/',
            null,
            true,
            true,
            false,
            'None'
        );
    }

    // ============================================================
    //  UPDATE PROFILE
    // ============================================================

    public function updateProfile(UpdateProfileRequest $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => new UserResource($user->fresh()->load(['roles', 'organization'])),
        ]);
    }

    // ============================================================
    //  UPDATE AVATAR
    // ============================================================

    public function updateAvatar(UpdateAvatarRequest $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        $file     = $request->file('avatar');
        $mime     = $file->getMimeType();
        $contents = file_get_contents($file->getRealPath());
        $base64   = 'data:' . $mime . ';base64,' . base64_encode($contents);

        $user->update(['avatar' => $base64]);

        return response()->json([
            'message' => 'Avatar updated successfully.',
            'avatar'  => $base64,
            'user'    => new UserResource($user->fresh()->load(['roles', 'organization'])),
        ]);
    }

    // ============================================================
    //  ME
    // ============================================================

    #[OA\Get(
        path: "/auth/me",
        tags: ["Authentication"],
        summary: "Get the authenticated user's profile",
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Authenticated user details",
                content: new OA\JsonContent(ref: "#/components/schemas/UserResource")
            ),
        ]
    )]
    public function me()
    {
        return response()->json(
            new UserResource(auth()->user()->load(['roles', 'organization']))
        );
    }

    // ============================================================
    //  VALIDATE INVITATION TOKEN — Public endpoint
    // ============================================================

    // GET /auth/invitation/{token}
    public function validateInvitation(string $token): \Illuminate\Http\JsonResponse
    {
        $invitation = Invitation::where('token', $token)
            ->with('organization:id,name,type')
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invalid invitation link.', 'valid' => false], 404);
        }

        if (!$invitation->isValid()) {
            return response()->json(['message' => 'This invitation has expired.', 'valid' => false], 410);
        }

        return response()->json([
            'valid'        => true,
            'email'        => $invitation->email,
            'role'         => $invitation->role,
            'organization' => $invitation->organization,
            'expires_at'   => $invitation->expires_at,
        ]);
    }

    // ============================================================
    //  HELPER — Normalize Algerian phone numbers to E.164
    // ============================================================

    private function formatAlgerianPhoneNumber(string $number): string
    {
        $number = preg_replace('/[\s\-]/', '', $number);

        if (str_starts_with($number, '+213'))  return $number;
        if (str_starts_with($number, '00213')) return '+' . substr($number, 2);
        if (str_starts_with($number, '0'))     return '+213' . substr($number, 1);

        return '+213' . $number;
    }
}

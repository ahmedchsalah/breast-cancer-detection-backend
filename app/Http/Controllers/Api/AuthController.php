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
    description: "API documentation for the Medical AI Federated Learning platform.",
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
    //  REGISTER
    // ============================================================

    public function register(RegisterRequest $request)
    {
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
    //  SEND OTP
    // ============================================================

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email'  => 'required|email|exists:users,email',
            'method' => 'required|in:email,whatsapp',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($request->method === 'whatsapp' && empty($user->phone_number)) {
            return response()->json(['message' => 'Phone number is required for WhatsApp OTP.'], 400);
        }

        // Generate OTP as a zero-padded 6-digit STRING to avoid leading zero issues
        $otpInt    = rand(100000, 999999);
        $otpString = str_pad((string) $otpInt, 6, '0', STR_PAD_LEFT);

        // Store as string explicitly
        Otp::updateOrCreate(
            ['identifier' => $user->email],
            [
                'token'      => $otpString,
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
                        'contentVariables' => json_encode(['1' => $otpString]),
                    ]
                );

                $channel = 'whatsapp';
            } catch (\Exception $e) {
                \Log::error('WhatsApp OTP Error: ' . $e->getMessage());
                Otp::where('identifier', $user->email)->delete();
                return response()->json(['message' => 'Failed to send OTP via WhatsApp. Please try again.'], 500);
            }
        } else {
            try {
                Mail::to($user->email)->send(new OtpMail($otpString));
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
    //  VERIFY OTP — Fixed: strict string comparison + better errors
    // ============================================================

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp'   => 'required|string|min:6|max:6',
        ]);

        $context = $request->query('context', 'login');

        $otpRecord = Otp::where('identifier', $request->email)->first();

        // No OTP record found at all
        if (!$otpRecord) {
            return response()->json([
                'message' => 'No OTP was requested for this email. Please request a new one.',
            ], 400);
        }

        // OTP expired — give a specific message so frontend can prompt resend
        if (Carbon::now()->gt(Carbon::parse($otpRecord->expires_at))) {
            $otpRecord->delete(); // clean up expired record
            return response()->json([
                'message' => 'Your OTP has expired. Please request a new one.',
                'reason'  => 'otp_expired',
            ], 400);
        }

        // Strict string comparison — fixes integer casting / leading zero issues
        if (trim((string) $otpRecord->token) !== trim((string) $request->otp)) {
            return response()->json([
                'message' => 'The OTP you entered is incorrect. Please check and try again.',
                'reason'  => 'otp_invalid',
            ], 400);
        }

        // Valid — delete immediately (single-use)
        $otpRecord->delete();

        $user = User::where('email', $request->email)->firstOrFail();

        if ($context === 'register') {
            if ($user->hasRole('doctor')) {
                return response()->json([
                    'message' => 'Identity verified. Your account is awaiting approval by the Organization Manager.',
                    'reason'  => 'awaiting_org_approval',
                ], 202);
            }

            // org_manager: activate immediately
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

    public function me()
    {
        return response()->json(new UserResource(auth()->user()->load(['roles', 'organization'])));
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

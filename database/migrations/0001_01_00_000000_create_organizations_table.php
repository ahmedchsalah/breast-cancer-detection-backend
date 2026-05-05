<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\User;
use App\Models\Otp;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase; // <-- Swapped Twilio for Firebase
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    description: "API documentation for the Medical AI platform",
    title: "Federated Learning API"
)]
#[OA\Server(url: "/api", description: "Primary API Server")]
#[OA\SecurityScheme(securityScheme: "sanctum", type: "http", scheme: "bearer", bearerFormat: "JWT")]
class AuthController extends Controller
{
    #[OA\Post(path: "/api/register", tags: ["Auth"])]
    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $orgId = null;
            $isActive = false;

            if ($request->role === 'org_manager') {
                $org = Organization::create([
                    'plan_id' => $request->plan_id ?? null,
                    'name' => $request->organization_name,
                    'type' => $request->organization_type,
                    'contact_email' => $request->email,
                    'address' => $request->organization_address,
                    'latitude' => $request->latitude ?? null,
                    'longitude' => $request->longitude ?? null,
                ]);
                $orgId = $org->id;
                $isActive = true;
            } elseif ($request->role === 'doctor') {
                $orgId = $request->organization_id;
                $isActive = false;
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number ?? null,
                'password' => Hash::make($request->password),
                'organization_id' => $orgId,
                'is_active' => $isActive,
            ]);

            $user->assignRole($request->role);
            return $user;
        });

        Auth::login($user); // Cookies/Session handled here

        return response()->json([
            'message' => __('messages.registration_successful'),
            'user' => new UserResource($user->load('organization')),
        ], 201);
    }

    #[OA\Post(path: "/api/login", tags: ["Auth"])]
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => __('messages.invalid_credentials')], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => __('messages.account_pending')], 403);
        }

        return response()->json([
            'message' => 'Credentials valid. Please proceed to request OTP.',
            'email' => $user->email,
            'phone_number' => $user->phone_number
        ]);
    }

    #[OA\Post(path: "/api/send-otp", tags: ["Auth"])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["email", "method"],
            properties: [
                new OA\Property(property: "email", type: "string", format: "email"),
                new OA\Property(property: "method", type: "string", enum: ["email", "sms"], example: "sms")
            ]
        )
    )]
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'method' => 'required|in:email,sms' // Changed whatsapp to sms for Firebase
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user->is_active) {
            return response()->json(['message' => __('messages.account_pending')], 403);
        }

        if ($request->method === 'sms' && empty($user->phone_number)) {
            return response()->json(['message' => 'Phone number is required for SMS OTP.'], 400);
        }

        $method = $request->method;

        if ($method === 'sms') {
            // Firebase sends the SMS from the frontend, but we track the intent here
            Otp::updateOrCreate(
                ['identifier' => $user->email],
                ['token' => 'FIREBASE_PENDING', 'method' => 'sms', 'expires_at' => Carbon::now()->addMinutes(10)]
            );
            $channel = 'firebase_sms';
        } else {
            $code = rand(100000, 999999);
            Otp::updateOrCreate(
                ['identifier' => $user->email],
                ['token' => $code, 'method' => 'email', 'expires_at' => Carbon::now()->addMinutes(10)]
            );

            try {
                Mail::to($user->email)->queue(new OtpMail($code));
                $channel = 'email';
            } catch (\Exception $e) {
                \Log::error('OTP Email Error: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to send OTP via email.'], 500);
            }
        }

        return response()->json([
            'message' => 'OTP request initiated.',
            'channel' => $channel
        ]);
    }

    #[OA\Post(path: "/api/verify-otp", tags: ["Auth"])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["email"],
            properties: [
                new OA\Property(property: "email", type: "string", format: "email"),
                new OA\Property(property: "otp", type: "string", example: "123456", description: "Required for Email method"),
                new OA\Property(property: "firebase_token", type: "string", description: "Required for SMS method")
            ]
        )
    )]
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required_without:firebase_token',
            'firebase_token' => 'required_without:otp',
        ]);

        $otpRecord = Otp::where('identifier', $request->email)->first();

        if (!$otpRecord || Carbon::now()->gt(Carbon::parse($otpRecord->expires_at))) {
            return response()->json(['message' => __('messages.otp_expired')], 400);
        }

        // --- Logic Selection: Firebase Token or Manual Code ---
        if ($request->has('firebase_token')) {
            try {
                $auth = Firebase::auth();
                $auth->verifyIdToken($request->firebase_token);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Invalid or expired Firebase token.'], 401);
            }
        } else {
            if ((string) $otpRecord->token !== (string) $request->otp) {
                return response()->json(['message' => __('messages.invalid_otp')], 400);
            }
        }

        $otpRecord->delete();
        $user = User::where('email', $request->email)->firstOrFail();

        // 🛡️ CRITICAL: Cookie/Session Login
        Auth::login($user);

        return response()->json([
            'message' => __('messages.login_successful'),
            'user' => new UserResource($user->load('organization')),
        ]);
    }

    #[OA\Post(path: "/api/logout", tags: ["Auth"], security: [["sanctum" => []]])]
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('messages.logged_out')]);
    }

    #[OA\Get(path: "/api/user", tags: ["Auth"], security: [["sanctum" => []]])]
    public function me()
    {
        return response()->json(new UserResource(auth()->user()->load(['roles', 'organization'])));
    }

    private function formatAlgerianPhoneNumber($number)
    {
        $number = preg_replace('/\s+|-/', '', $number);
        if (str_starts_with($number, '+213')) return $number;
        if (str_starts_with($number, '00213')) return '+' . substr($number, 2);
        if (str_starts_with($number, '0')) return '+213' . substr($number, 1);
        return '+213' . $number;
    }
}

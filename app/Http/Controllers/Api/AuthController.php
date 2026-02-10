<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\User;
use App\Models\Otp; // <--- Don't forget this
use App\Mail\OtpMail; // <--- And this
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request; // Needed for verifyOtp validation

class AuthController extends Controller
{
    // ... (Your Register Function stays exactly the same) ...
    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $orgId = null;
            $isActive = false;

            if ($request->role === 'org_manager') {
                $org = Organization::create([
                    'plan_id' => $request->plan_id,
                    'name' => $request->organization_name,
                    'type' => $request->organization_type,
                    'code' => strtoupper(Str::random(8)),
                    'contact_email' => $request->email,
                    'address' => $request->organization_address,
                    'subscription_status' => 'trial',
                ]);
                $orgId = $org->id;
                $isActive = true;
            } elseif ($request->role === 'doctor') {
                $org = Organization::where('code', $request->organization_code)->first();
                $orgId = $org->id;
                $isActive = false;
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'organization_id' => $orgId,
                'is_active' => $isActive,
            ]);

            $user->assignRole($request->role);
            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user' => new UserResource($user->load('organization')),
            'token' => $token,
        ], 201);
    }

    // === 1. LOGIN (Step 1: Validate User & Send OTP) ===
    public function login(LoginRequest $request)
    {
        // We find the user manually to check status before verifying password
        $user = User::where('email', $request->email)->first();

        // Check Credentials
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        // Security Check: Is the doctor approved?
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account is pending approval by your Clinic Manager.'
            ], 403);
        }

        // Credentials are good. Send OTP.
        $this->sendOtpEmail($user);

        return response()->json([
            'message' => 'OTP sent to your email. Please verify to complete login.',
            'email' => $user->email // Helper for frontend
        ]);
    }

    // === 2. VERIFY OTP (Step 2: Check Code & Issue Token) ===
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required', // Removed 'digits:6' to avoid validation errors on format
            'device_name' => 'nullable|string'
        ]);

        // 1. Find the OTP record
        $otpRecord = Otp::where('identifier', $request->email)->first();

        // 2. Check if record exists
        if (!$otpRecord) {
            return response()->json(['message' => 'No OTP found for this email.'], 400);
        }

        // 3. Check if OTP matches (Cast both to strings to be 100% sure)
        if ((string) $otpRecord->token !== (string) $request->otp) {
            return response()->json(['message' => 'Invalid OTP provided.'], 400);
        }

        // 4. Check Expiry (Handle Timezone Safely)
        // We ensure the DB timestamp is treated as a Carbon date before comparing
        $expiresAt = Carbon::parse($otpRecord->expires_at);

        if (Carbon::now()->gt($expiresAt)) {
            return response()->json(['message' => 'OTP has expired. Please login again.'], 400);
        }

        // 5. Success! Delete the OTP so it cannot be used twice
        $otpRecord->delete();

        // 6. Login the User
        $user = User::where('email', $request->email)->firstOrFail();

        // Generate Token
        $token = $user->createToken($request->device_name ?? 'web')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => new UserResource($user->load('organization')),
            'token' => $token,
        ]);
    }

    // === 3. RESEND OTP (Utility) ===
    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $user = User::where('email', $request->email)->first();

        // Don't send if user is banned/inactive
        if (!$user->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $this->sendOtpEmail($user);

        return response()->json(['message' => 'OTP resent successfully.']);
    }

    //Helper Function to avoid code duplication
    private function sendOtpEmail($user)
    {
        $code = rand(100000, 999999);

        Otp::updateOrCreate(
            ['identifier' => $user->email],
            [
                'token' => $code,
                'expires_at' => Carbon::now()->addMinutes(10)
            ]
        );

        // Send Email (Wrap in try/catch to prevent crashing if internet is down)
        try {
            Mail::to($user->email)->send(new OtpMail($code));
        } catch (\Exception $e) {
            // Log error or ignore in dev
        }
    }

    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me()
    {
        return response()->json(auth()->user()->load(['roles', 'organization']));
    }
}

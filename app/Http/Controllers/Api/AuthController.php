<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\User;
use App\Models\Otp;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth; // <--- Critical for Cookie Auth
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AuthController extends Controller
{
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

        // 👇 CHANGE 1: Login via Cookie instead of Token
        Auth::login($user);

        // Return User (No token needed anymore!)
        return response()->json([
            'message' => 'Registration successful.',
            'user' => new UserResource($user->load('organization')),
        ], 201);
    }

    // === 1. LOGIN (Step 1: Validate User & Send OTP) ===
    public function login(LoginRequest $request)
    {
        // ⚠️ NOTE: We do NOT use Auth::attempt() here because
        // that would start a session immediately. We want to wait for OTP.

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account is pending approval by your Clinic Manager.'
            ], 403);
        }

        $this->sendOtpEmail($user);

        return response()->json([
            'message' => 'OTP sent to your email. Please verify to complete login.',
            'email' => $user->email
        ]);
    }

    // === 2. VERIFY OTP (Step 2: Check Code & Start Session) ===
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required',
        ]);

        $otpRecord = Otp::where('identifier', $request->email)->first();

        if (!$otpRecord || (string) $otpRecord->token !== (string) $request->otp) {
            return response()->json(['message' => 'Invalid OTP provided.'], 400);
        }

        if (Carbon::now()->gt(Carbon::parse($otpRecord->expires_at))) {
            return response()->json(['message' => 'OTP has expired.'], 400);
        }

        $otpRecord->delete();
        $user = User::where('email', $request->email)->firstOrFail();

        // 👇 CHANGE 2: The Magic Line. This creates the HttpOnly Cookie.
        Auth::login($user);
        $request->session()->regenerate(); // Prevents Session Fixation attacks

        return response()->json([
            'message' => 'Login successful',
            'user' => new UserResource($user->load('organization')),
            // No 'token' returned here. The browser has the cookie now.
        ]);
    }

    // === 3. RESEND OTP ===
    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);
        $user = User::where('email', $request->email)->first();

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $this->sendOtpEmail($user);
        return response()->json(['message' => 'OTP resent successfully.']);
    }

    private function sendOtpEmail($user)
    {
        // ... (Same as before) ...
        $code = rand(100000, 999999);
        Otp::updateOrCreate(
            ['identifier' => $user->email],
            ['token' => $code, 'expires_at' => Carbon::now()->addMinutes(10)]
        );

        try {
            Mail::to($user->email)->send(new OtpMail($code));
        } catch (\Exception $e) {}
    }

    // === 4. LOGOUT ===
    public function logout(Request $request)
    {
        // 👇 CHANGE 3: Destroy the Session Cookie
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me()
    {
        return response()->json(auth()->user()->load(['roles', 'organization']));
    }
}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Verification Code</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #f0f4f8;
            padding: 40px 20px;
            color: #2d3748;
        }

        .wrapper {
            max-width: 560px;
            margin: 0 auto;
        }

        .card {
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        /* Header — color changes per role */
        .header {
            padding: 32px 40px;
            text-align: center;
        }

        .header.role-doctor       { background: linear-gradient(135deg, #1a56db, #1e429f); }
        .header.role-org_manager  { background: linear-gradient(135deg, #0694a2, #047481); }
        .header.role-admin        { background: linear-gradient(135deg, #7e3af2, #6c2bd9); }
        .header.role-user         { background: linear-gradient(135deg, #1a56db, #1e429f); }

        .header .logo {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            margin-bottom: 12px;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
        }

        .header .badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 14px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            font-size: 12px;
            color: rgba(255,255,255,0.9);
            letter-spacing: 1px;
            text-transform: capitalize;
        }

        /* Body */
        .body {
            padding: 36px 40px;
        }

        .greeting {
            font-size: 16px;
            color: #4a5568;
            margin-bottom: 8px;
        }

        .greeting strong {
            color: #1a202c;
        }

        .description {
            font-size: 14px;
            color: #718096;
            margin-bottom: 28px;
            line-height: 1.6;
        }

        /* OTP Box */
        .otp-box {
            text-align: center;
            margin: 0 auto 28px;
            padding: 24px;
            background: #f7fafc;
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
        }

        .otp-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #a0aec0;
            margin-bottom: 12px;
        }

        .otp-code {
            font-size: 42px;
            font-weight: 800;
            letter-spacing: 10px;
            color: #1a202c;
            font-family: 'Courier New', monospace;
        }

        .otp-expiry {
            margin-top: 10px;
            font-size: 12px;
            color: #e53e3e;
            font-weight: 600;
        }

        /* Info note */
        .note {
            font-size: 13px;
            color: #a0aec0;
            line-height: 1.6;
            padding: 16px;
            background: #fffaf0;
            border-left: 3px solid #f6ad55;
            border-radius: 4px;
            margin-bottom: 24px;
        }

        /* Footer */
        .footer {
            padding: 20px 40px;
            background: #f7fafc;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }

        .footer p {
            font-size: 12px;
            color: #a0aec0;
            line-height: 1.8;
        }

        .footer strong {
            color: #718096;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">

        {{-- Header — role-aware color --}}
        <div class="header role-{{ $userRole }}">
            <div class="logo">Federated Medical AI</div>
            <h1>Verification Code</h1>
            <span class="badge">
                @if($userRole === 'doctor')
                    🩺 Doctor
                @elseif($userRole === 'org_manager')
                    🏥 Organization Manager
                @elseif($userRole === 'admin')
                    ⚙️ Administrator
                @else
                    👤 User
                @endif
            </span>
        </div>

        {{-- Body --}}
        <div class="body">

            <p class="greeting">
                Hello, <strong>{{ $userName }}</strong>
            </p>

            <p class="description">
                @if($userRole === 'doctor')
                    Use the code below to verify your identity and access your medical dashboard.
                @elseif($userRole === 'org_manager')
                    Use the code below to verify your identity and access your organization's management panel.
                @elseif($userRole === 'admin')
                    Use the code below to verify your administrator access.
                @else
                    Use the code below to verify your identity.
                @endif
            </p>

            {{-- OTP Code --}}
            <div class="otp-box">
                <div class="otp-label">Your one-time code</div>
                <div class="otp-code">{{ $token }}</div>
                <div class="otp-expiry">⏱ Expires in 10 minutes</div>
            </div>

            {{-- Security note --}}
            <div class="note">
                🔒 If you did not request this code, please ignore this email.
                Do not share this code with anyone — our team will never ask for it.
            </div>

        </div>

        {{-- Footer --}}
        <div class="footer">
            <p>
                <strong>Federated Medical AI Platform</strong><br/>
                This is an automated message, please do not reply.<br/>
                &copy; {{ date('Y') }} All rights reserved.
            </p>
        </div>

    </div>
</div>
</body>
</html>

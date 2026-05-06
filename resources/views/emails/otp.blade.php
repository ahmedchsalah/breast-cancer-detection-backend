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

        /* Header — role-aware, all using brand palette */
        .header {
            padding: 32px 40px;
            text-align: center;
        }

        .header.role-doctor      { background: linear-gradient(135deg, #1a3a8f, #2db8a8); }
        .header.role-org_manager { background: linear-gradient(135deg, #2db8a8, #1a3a8f); }
        .header.role-admin       { background: linear-gradient(135deg, #e8457a, #1a3a8f); }
        .header.role-user        { background: linear-gradient(135deg, #1a3a8f, #2db8a8); }

        .header .logo-text {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.65);
            margin-bottom: 10px;
        }

        .header h1 {
            font-size: 21px;
            font-weight: 700;
            color: #ffffff;
        }

        .header .badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 16px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            font-size: 12px;
            color: rgba(255,255,255,0.92);
            letter-spacing: 1px;
        }

        /* Accent bar below header */
        .accent-bar {
            height: 4px;
            background: linear-gradient(90deg, #1a3a8f, #2db8a8, #e8457a);
        }

        /* Body */
        .body {
            padding: 32px 40px;
        }

        .greeting {
            font-size: 16px;
            color: #4a5568;
            margin-bottom: 6px;
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
            padding: 22px 16px;
            background: #f7fafc;
            border: 2px dashed #2db8a8;
            border-radius: 10px;
        }

        .otp-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #a0aec0;
            margin-bottom: 10px;
        }

        /* letter-spacing reduced so 6 digits stay on one line */
        .otp-code {
            font-size: 40px;
            font-weight: 800;
            letter-spacing: 6px;
            color: #1a3a8f;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }

        .otp-expiry {
            margin-top: 10px;
            font-size: 12px;
            color: #e8457a;
            font-weight: 600;
        }

        /* Info note */
        .note {
            font-size: 13px;
            color: #718096;
            line-height: 1.6;
            padding: 14px 16px;
            background: #fff5f7;
            border-left: 3px solid #e8457a;
            border-radius: 4px;
            margin-bottom: 8px;
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
            color: #2db8a8;
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">

        {{-- Header --}}
        <div class="header role-{{ $userRole }}">
            <div class="logo-text">BRECAI-FED</div>
            <h1>Identity Verification</h1>
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

        {{-- Accent bar --}}
        <div class="accent-bar"></div>

        {{-- Body --}}
        <div class="body">

            <p class="greeting">Hello, <strong>{{ $userName }}</strong></p>

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

            {{-- OTP --}}
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
                <strong>BRECAI-FED</strong> · Federated Medical AI Platform<br/>
                This is an automated message, please do not reply.<br/>
                &copy; {{ date('Y') }} All rights reserved.
            </p>
        </div>

    </div>
</div>
</body>
</html>

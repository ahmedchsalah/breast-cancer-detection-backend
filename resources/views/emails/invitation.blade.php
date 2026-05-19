<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>You're Invited — BRECAI-FED</title>
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

        /* Header — role-aware gradient */
        .header {
            padding: 36px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header.role-doctor {
            background: linear-gradient(135deg, #1a3a8f 0%, #0572B2 50%, #2db8a8 100%);
        }

        .header.role-instructor {
            background: linear-gradient(135deg, #1e1b4b 0%, #4f46e5 50%, #7c3aed 100%);
        }

        /* Subtle dot pattern overlay */
        .header::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.06;
            background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0);
            background-size: 20px 20px;
            pointer-events: none;
        }

        .header .logo-text {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.65);
            margin-bottom: 14px;
            position: relative;
        }

        .header .invite-icon {
            font-size: 40px;
            margin-bottom: 12px;
            display: block;
            position: relative;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.3;
            position: relative;
        }

        .header .org-name {
            font-size: 26px;
            font-weight: 900;
            color: #ffffff;
            margin-top: 4px;
            position: relative;
        }

        .header .badge {
            display: inline-block;
            margin-top: 12px;
            padding: 5px 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            font-size: 12px;
            color: rgba(255,255,255,0.95);
            letter-spacing: 1px;
            font-weight: 700;
            position: relative;
        }

        /* Accent bar */
        .accent-bar {
            height: 4px;
        }

        .accent-bar.role-doctor {
            background: linear-gradient(90deg, #1a3a8f, #0572B2, #2db8a8);
        }

        .accent-bar.role-instructor {
            background: linear-gradient(90deg, #1e1b4b, #4f46e5, #7c3aed, #e8457a);
        }

        /* Body */
        .body {
            padding: 32px 40px;
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
            line-height: 1.7;
        }

        /* CTA Button */
        .cta-wrapper {
            text-align: center;
            margin: 28px 0;
        }

        .cta-btn {
            display: inline-block;
            padding: 16px 36px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 800;
            color: #ffffff !important;
            text-decoration: none;
            letter-spacing: 0.5px;
            -webkit-text-fill-color: #ffffff;
        }

        .cta-btn.role-doctor {
            background: linear-gradient(135deg, #0572B2, #0BB592);
            box-shadow: 0 6px 20px rgba(5, 114, 178, 0.4);
        }

        .cta-btn.role-instructor {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
        }

        /* Info box */
        .info-box {
            border-radius: 8px;
            padding: 16px 18px;
            margin-bottom: 16px;
        }

        .info-box.expiry {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
        }

        .info-box .info-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #a0aec0;
            margin-bottom: 4px;
        }

        .info-box .info-value {
            font-size: 14px;
            font-weight: 700;
            color: #2d3748;
        }

        /* Warning note */
        .note {
            font-size: 13px;
            color: #744210;
            line-height: 1.6;
            padding: 14px 16px;
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            border-radius: 4px;
            margin-bottom: 8px;
        }

        /* Fallback URL */
        .fallback-url {
            font-size: 11px;
            color: #a0aec0;
            word-break: break-all;
            text-align: center;
            margin-top: 12px;
            line-height: 1.5;
        }

        .fallback-url a {
            color: #718096;
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
        <div class="header role-{{ $role }}">
            <div class="logo-text">BRECAI-FED</div>
            <span class="invite-icon">🎉</span>
            <h1>You've been invited to join</h1>
            <div class="org-name">{{ $orgName }}</div>
            <span class="badge">
                @if($role === 'instructor')
                    🎓 Data Scientist / Instructor
                @else
                    🩺 Doctor / Clinician
                @endif
            </span>
        </div>

        {{-- Accent bar --}}
        <div class="accent-bar role-{{ $role }}"></div>

        {{-- Body --}}
        <div class="body">

            <p class="greeting">Hello, <strong>{{ $recipientEmail }}</strong></p>

            <p class="description">
                @if($role === 'instructor')
                    <strong>{{ $orgName }}</strong> has invited you to join their organization on BRECAI-FED as a
                    <strong>Data Scientist / Instructor</strong>. You'll have access to federated learning management,
                    model training rounds, and contribution tracking.
                @else
                    <strong>{{ $orgName }}</strong> has invited you to join their organization on BRECAI-FED as a
                    <strong>Doctor / Clinician</strong>. You'll have access to AI-powered breast cancer detection,
                    patient management, and clinical reporting tools.
                @endif
            </p>

            {{-- CTA Button --}}
            <div class="cta-wrapper">
                <a href="{{ $registerUrl }}" class="cta-btn role-{{ $role }}">
                    Accept Invitation &amp; Register →
                </a>
            </div>

            {{-- Expiry info --}}
            <div class="info-box expiry">
                <div class="info-label">⏱ Invitation expires</div>
                <div class="info-value">{{ $expiresAt }}</div>
            </div>

            {{-- Warning --}}
            <div class="note">
                ⚠️ This invitation link expires in <strong>48 hours</strong>. After that, you'll need to request a new invitation from your organization manager.
                If you did not expect this invitation, you can safely ignore this email.
            </div>

            {{-- Fallback URL --}}
            <p class="fallback-url">
                If the button above doesn't work, copy and paste this link into your browser:<br/>
                <a href="{{ $registerUrl }}">{{ $registerUrl }}</a>
            </p>

        </div>

        {{-- Footer --}}
        <div class="footer">
            <p>
                <strong>BRECAI-FED</strong> · Federated Medical AI Platform<br/>
                This invitation was sent to <strong>{{ $recipientEmail }}</strong>.<br/>
                This is an automated message, please do not reply.<br/>
                &copy; {{ date('Y') }} All rights reserved.
            </p>
        </div>

    </div>
</div>
</body>
</html>

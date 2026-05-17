<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Organization Approved</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f4f8; padding: 40px 20px; color: #2d3748; }
        .wrapper { max-width: 560px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { padding: 32px 40px; text-align: center; background: linear-gradient(135deg, #d97706, #ea580c); }
        .header .logo-text { font-size: 11px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: rgba(255,255,255,0.65); margin-bottom: 10px; }
        .header h1 { font-size: 22px; font-weight: 700; color: #ffffff; }
        .header .badge { display: inline-block; margin-top: 10px; padding: 4px 16px; border-radius: 20px; background: rgba(255,255,255,0.15); font-size: 12px; color: rgba(255,255,255,0.92); letter-spacing: 1px; }
        .accent-bar { height: 4px; background: linear-gradient(90deg, #d97706, #0BB592, #0572B2); }
        .body { padding: 32px 40px; }
        .greeting { font-size: 16px; color: #4a5568; margin-bottom: 6px; }
        .greeting strong { color: #1a202c; }
        .description { font-size: 14px; color: #718096; margin-bottom: 28px; line-height: 1.6; }
        .success-box { text-align: center; margin: 0 auto 28px; padding: 22px 16px; background: #f0fdf4; border: 2px solid #0BB592; border-radius: 10px; }
        .success-icon { font-size: 48px; margin-bottom: 12px; }
        .success-title { font-size: 20px; font-weight: 800; color: #065f46; margin-bottom: 6px; }
        .success-org { font-size: 15px; font-weight: 600; color: #0BB592; }
        .steps { margin-bottom: 24px; }
        .step { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
        .step:last-child { border-bottom: none; }
        .step-num { width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #d97706, #ea580c); color: white; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
        .step-text { font-size: 13px; color: #4a5568; line-height: 1.5; }
        .step-text strong { color: #1a202c; }
        .cta-btn { display: block; text-align: center; padding: 14px 32px; background: linear-gradient(135deg, #d97706, #ea580c); color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 700; margin: 24px 0; }
        .note { font-size: 13px; color: #718096; line-height: 1.6; padding: 14px 16px; background: #fffbeb; border-left: 3px solid #d97706; border-radius: 4px; margin-bottom: 8px; }
        .footer { padding: 20px 40px; background: #f7fafc; border-top: 1px solid #e2e8f0; text-align: center; }
        .footer p { font-size: 12px; color: #a0aec0; line-height: 1.8; }
        .footer strong { color: #0BB592; font-weight: 700; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="logo-text">BRECAI-FED</div>
            <h1>Organization Approved!</h1>
            <span class="badge">🏥 Site Admin</span>
        </div>
        <div class="accent-bar"></div>
        <div class="body">
            <p class="greeting">Hello, <strong>{{ $managerName }}</strong></p>
            <p class="description">
                Great news! Your organization has been reviewed and approved by the BRECAI-FED platform administrators.
                Your account is now active and you can access the full management dashboard.
            </p>

            <div class="success-box">
                <div class="success-icon">✅</div>
                <div class="success-title">Application Approved</div>
                <div class="success-org">{{ $orgName }}</div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 4px; text-transform: capitalize;">{{ str_replace('_', ' ', $orgType) }}</div>
            </div>

            <div class="steps">
                <p style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; color: #a0aec0; margin-bottom: 12px;">What to do next</p>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-text"><strong>Sign in</strong> to your account using your registered email and password.</div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-text"><strong>Choose a subscription plan</strong> that fits your organization's needs to unlock full platform access.</div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-text"><strong>Invite your team</strong> — add doctors and instructors to your organization from the Invitations panel.</div>
                </div>
                <div class="step">
                    <div class="step-num">4</div>
                    <div class="step-text"><strong>Start using AI</strong> — your doctors can now run federated AI predictions for breast cancer subtyping.</div>
                </div>
            </div>

            <a href="{{ $frontendUrl }}/auth" class="cta-btn">Sign In to Your Dashboard →</a>

            <div class="note">
                🔒 If you did not register for BRECAI-FED, please ignore this email or contact our support team.
            </div>
        </div>
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

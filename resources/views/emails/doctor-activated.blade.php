<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Account Activated</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f4f8; padding: 40px 20px; color: #2d3748; }
        .wrapper { max-width: 560px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { padding: 32px 40px; text-align: center; background: linear-gradient(135deg, #0572B2, #0BB592); }
        .header .logo-text { font-size: 11px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: rgba(255,255,255,0.65); margin-bottom: 10px; }
        .header h1 { font-size: 22px; font-weight: 700; color: #ffffff; }
        .header .badge { display: inline-block; margin-top: 10px; padding: 4px 16px; border-radius: 20px; background: rgba(255,255,255,0.15); font-size: 12px; color: rgba(255,255,255,0.92); letter-spacing: 1px; }
        .accent-bar { height: 4px; background: linear-gradient(90deg, #0572B2, #0BB592, #F55486); }
        .body { padding: 32px 40px; }
        .greeting { font-size: 16px; color: #4a5568; margin-bottom: 6px; }
        .greeting strong { color: #1a202c; }
        .description { font-size: 14px; color: #718096; margin-bottom: 28px; line-height: 1.6; }
        .success-box { text-align: center; margin: 0 auto 28px; padding: 22px 16px; background: #f0fdf4; border: 2px solid #0BB592; border-radius: 10px; }
        .success-icon { font-size: 48px; margin-bottom: 12px; }
        .success-title { font-size: 20px; font-weight: 800; color: #065f46; margin-bottom: 6px; }
        .success-org { font-size: 14px; font-weight: 600; color: #0BB592; }
        .cta-btn { display: block; text-align: center; padding: 14px 32px; background: linear-gradient(135deg, #0572B2, #0BB592); color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 700; margin: 24px 0; }
        .note { font-size: 13px; color: #718096; line-height: 1.6; padding: 14px 16px; background: #eff6ff; border-left: 3px solid #0572B2; border-radius: 4px; margin-bottom: 8px; }
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
            <h1>Account Activated!</h1>
            <span class="badge">🩺 Doctor / Clinician</span>
        </div>
        <div class="accent-bar"></div>
        <div class="body">
            <p class="greeting">Hello, <strong>{{ $doctorName }}</strong></p>
            <p class="description">
                Great news! Your account has been reviewed and approved by the Organization Manager of <strong>{{ $orgName }}</strong>.
                You can now sign in and start using the BRECAI-FED clinical AI platform.
            </p>

            <div class="success-box">
                <div class="success-icon">✅</div>
                <div class="success-title">Account Activated</div>
                <div class="success-org">{{ $orgName }}</div>
            </div>

            <a href="{{ $frontendUrl }}/auth" class="cta-btn">Sign In to Your Dashboard →</a>

            <div class="note">
                🔒 If you did not register for BRECAI-FED, please contact our support team immediately.
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

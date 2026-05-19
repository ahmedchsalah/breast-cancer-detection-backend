<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Clinical Report — BRECAI-FED</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; padding: 40px 20px; color: #2d3748; }
        .wrapper { max-width: 560px; margin: 0 auto; }
        .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { padding: 36px 40px; background: linear-gradient(135deg, #072a5e 0%, #0572B2 60%, #0BB592 100%); text-align: center; }
        .header .logo { font-size: 22px; font-weight: 900; color: #fff; letter-spacing: -0.5px; margin-bottom: 8px; }
        .header .logo span { color: #0BB592; }
        .header h1 { font-size: 18px; font-weight: 700; color: rgba(255,255,255,0.9); }
        .body { padding: 32px 40px; }
        .greeting { font-size: 16px; color: #4a5568; margin-bottom: 16px; }
        .greeting strong { color: #1a202c; }
        .info-box { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 18px; margin-bottom: 20px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .info-row:last-child { margin-bottom: 0; }
        .info-label { color: #718096; font-weight: 600; }
        .info-value { color: #2d3748; font-weight: 700; }
        .result-badge { display: inline-block; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 800; margin-bottom: 20px; }
        .result-luma { background: #f0fdf4; color: #0BB592; border: 1px solid #86efac; }
        .result-nonluma { background: #fff1f2; color: #F55486; border: 1px solid #fda4af; }
        .note { font-size: 13px; color: #718096; line-height: 1.6; padding: 14px 16px; background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 4px; margin-bottom: 20px; }
        .footer { padding: 20px 40px; background: #f7fafc; border-top: 1px solid #e2e8f0; text-align: center; }
        .footer p { font-size: 12px; color: #a0aec0; line-height: 1.8; }
        .footer strong { color: #0BB592; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="logo">BRECAI<span>FED</span></div>
            <h1>Clinical Diagnostic Report Ready</h1>
        </div>
        <div class="body">
            <p class="greeting">Hello, <strong>Dr. {{ $doctor->name }}</strong></p>
            <p style="font-size:14px;color:#718096;margin-bottom:20px;line-height:1.7;">
                Your clinical report has been generated and is attached to this email as an HTML file.
                Please find the details below.
            </p>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Patient ID</span>
                    <span class="info-value">{{ $report->patient?->patient_identifier ?? '—' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Report Status</span>
                    <span class="info-value">{{ ucfirst($report->status) }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Generated</span>
                    <span class="info-value">{{ $report->created_at->format('d M Y, H:i') }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Organization</span>
                    <span class="info-value">{{ $doctor->organization?->name ?? '—' }}</span>
                </div>
            </div>

            @if($report->prediction)
                <span class="result-badge {{ $report->prediction->is_lum_a ? 'result-luma' : 'result-nonluma' }}">
                    {{ $report->prediction->is_lum_a ? '✓ Luminal A' : '⚠ Non-Luminal A' }}
                    — {{ number_format(($report->prediction->confidence_lum_a ?? 0) * 100, 1) }}% confidence
                </span>
            @endif

            <div class="note">
                📎 The full diagnostic report is attached as an HTML file. Open it in any browser to view
                the complete analysis including biomarker panel, AI prediction details, and therapy recommendations.
            </div>
        </div>
        <div class="footer">
            <p>
                <strong>BRECAI-FED</strong> · Federated Medical AI Platform<br/>
                This report was sent to <strong>{{ $doctor->email }}</strong>.<br/>
                This is an automated message — please do not reply.<br/>
                &copy; {{ date('Y') }} All rights reserved.
            </p>
        </div>
    </div>
</div>
</body>
</html>

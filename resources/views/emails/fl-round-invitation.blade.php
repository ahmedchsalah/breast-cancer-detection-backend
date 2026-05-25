<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<title>FL Round Invitation</title>
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; background:#f1f5f9; margin:0; padding:32px; color:#1e293b; }
.wrap { max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #e2e8f0; }
.header { background: linear-gradient(135deg, #093A7A, #0572B2); color:#fff; padding:32px 28px; }
.header .eyebrow { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:2px; opacity:0.7; margin-bottom:6px; }
.header h1 { margin:0; font-size:22px; font-weight:900; }
.body { padding:28px; }
.card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px; margin:18px 0; }
.row { display:flex; justify-content:space-between; padding:6px 0; font-size:13px; }
.row .lbl { color:#64748b; font-weight:700; }
.row .val { color:#1e293b; font-weight:800; }
.btn { display:inline-block; padding:14px 28px; border-radius:12px; background: linear-gradient(135deg, #093A7A, #0572B2); color:#fff; text-decoration:none; font-weight:900; font-size:14px; letter-spacing:0.5px; }
.note { font-size:12px; color:#64748b; line-height:1.6; margin-top:16px; }
.footer { padding:18px 28px; border-top:1px solid #e2e8f0; font-size:11px; color:#94a3b8; text-align:center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div class="eyebrow">BReCAI · Federated Learning</div>
    <h1>FL Round Invitation</h1>
  </div>
  <div class="body">
    <p>Hello, <strong>Dr. {{ $instructor->name }}</strong></p>
    <p>The platform coordinator has opened a new federated learning round and invites your hospital to contribute. Your participation helps improve the global breast cancer classification model while keeping patient data fully private to your site.</p>

    <div class="card">
      <div class="row"><span class="lbl">Round Number</span><span class="val">#{{ $invitation->flRound->round_number }}</span></div>
      <div class="row"><span class="lbl">Model</span><span class="val">{{ $invitation->flRound->aiModel?->name ?? 'BReCAI A6' }}</span></div>
      <div class="row"><span class="lbl">Started</span><span class="val">{{ $invitation->flRound->started_at?->format('d M Y, H:i') }}</span></div>
      <div class="row"><span class="lbl">Your hospital</span><span class="val">{{ $instructor->organization?->name ?? '—' }}</span></div>
    </div>

    <p style="text-align:center; margin:24px 0;">
      <a href="{{ $approveUrl }}" class="btn">Review &amp; Respond</a>
    </p>

    <p class="note">
      Click the button above to open the invitation page and decide whether to participate. You can accept or decline before training begins. Once accepted, you can configure your local training in the BReCAI dashboard.
    </p>
  </div>
  <div class="footer">
    BReCAI-FED · Federated Medical AI Platform · This is an automated message.
  </div>
</div>
</body>
</html>

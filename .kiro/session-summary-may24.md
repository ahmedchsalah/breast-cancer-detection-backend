# Session Summary — May 24, 2026

## 1. Modal GPU Deployment (SVS/PNG processing) — DROPPED
- Fixed `allow_concurrent_inputs` deprecation → `@modal.concurrent`
- Built R2 multipart upload flow for SVS files (chunked, resilient)
- Added `/extract-r2`, `/extract-image-from-url`, `/predict-a6-from-r2` endpoints
- Moved full A6 fusion pipeline to Modal (tile + CONCH + ensemble)
- Fixed file upload issues (Modal's ASGI doesn't support `UploadFile` param)
- **Ultimately dropped Modal** — user decided to use HF Space for everything

## 2. Checkpoint Fusion
- Created notebook to fuse 15 checkpoints per approach into 1 averaged checkpoint
- Fused: A4_conch, A6_conch, A2_resnet50, A2_efficientnet_b3, A3_resnet50, A3_efficientnet_b3
- Uploaded all fused checkpoints to HF repo `ahmedchikhsalah/brecai-v12-checkpoints`
- Updated `app/model.py` to prefer `*_fused.pt` files (1 load instead of 7)
- Populated Modal volume with all weights (can be used later if needed)

## 3. Admin Dashboard (Full Spec → Implementation)
- Created requirements, design, tasks documents at `.kiro/specs/admin-dashboard/`
- Built 7 new Laravel controllers (patients, predictions, examinations, payments, subscriptions, plans, federated-rounds)
- Extended React admin API client with 7 new resource namespaces
- Rewired 8 admin pages to use admin-scoped endpoints
- Removed unauthorized delete buttons from UserManagement/OrgRegistry
- Added error handling (`src/lib/handleApiError.js`), enhanced AuditLogs with filters + detail modal
- Fixed AI model registry (multiple active models allowed, proper metrics)

## 4. Prediction Wizard Fixes
- Fixed progress bar calibration (was too slow)
- Added resilient polling (silent retry on 503/network errors)
- Fixed PNG handling: sends directly to HF `/extract/image`
- Added `/extract/image` endpoint to HF Space (tiles + CONCH for any image)
- Clinical-only predictions run synchronously via HF `/predict/clinical`

## 5. Final Architecture (Current State)
- **Everything through HF Space** — no Modal dependency
- **React** → sends PNG/JPG to HF `/extract/image` → gets `pt_b64` → stores in Laravel → prediction job calls HF `/predict/a6`
- **SVS** → R2 multipart upload → Laravel job calls HF `/predict/a6/from-r2`
- **Clinical-only** → Laravel calls HF `/predict/clinical` directly
- **Keep-alive ping** every 30s to prevent HF sleeping

## 6. Files Changed (need HF Space upload)
- `app/model.py` — fused checkpoint preference
- `app/main.py` — `/extract/image` endpoint added

## 7. Pending / Next Steps
- Upload `app/model.py` and `app/main.py` to HF Space
- Remove `MODAL_URL` from Laravel Cloud env vars (not needed anymore)
- User plans to move to AWS Free Tier for production later
- Currency is DZD (Algerian Dinar), not USD
- Chargily payment system is untouched

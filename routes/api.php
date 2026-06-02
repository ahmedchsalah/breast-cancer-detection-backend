<?php

use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// Namespace aliases for readability
// ─────────────────────────────────────────────────────────────────────────────
use App\Http\Controllers\Api\Auth\AuthController;

use App\Http\Controllers\Api\Admin\InsightsController    as AdminInsights;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Admin\AiModelController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\AdminPaymentController;
use App\Http\Controllers\Api\Admin\AdminExaminationController;
use App\Http\Controllers\Api\Admin\AdminPatientController;
use App\Http\Controllers\Api\Admin\AdminPredictionController;
use App\Http\Controllers\Api\Admin\AdminFederatedRoundController;
use App\Http\Controllers\Api\Admin\AdminPlanController;

use App\Http\Controllers\Api\OrgManager\DashboardController  as OrgDashboard;
use App\Http\Controllers\Api\OrgManager\InsightsController   as OrgInsights;
use App\Http\Controllers\Api\OrgManager\MemberController;
use App\Http\Controllers\Api\OrgManager\PatientController    as OrgPatientController;
use App\Http\Controllers\Api\OrgManager\ReportController     as OrgReportController;

use App\Http\Controllers\Api\Doctor\InsightsController       as DoctorInsights;
use App\Http\Controllers\Api\Doctor\PatientController        as DoctorPatientController;
use App\Http\Controllers\Api\Doctor\ExaminationController;
use App\Http\Controllers\Api\Doctor\WsiUploadController;
use App\Http\Controllers\Api\Doctor\PredictionController;
use App\Http\Controllers\Api\Doctor\XaiResultController;
use App\Http\Controllers\Api\Doctor\ReportController         as DoctorReportController;

use App\Http\Controllers\Api\Instructor\InsightsController      as InstructorInsights;
use App\Http\Controllers\Api\Instructor\ModelController         as InstructorModelController;
use App\Http\Controllers\Api\Instructor\FederatedRoundController;
use App\Http\Controllers\Api\Instructor\ContributionController;

use App\Http\Controllers\Api\Internal\PredictionWebhookController;
use App\Http\Controllers\Api\ChargilyWebhookController;
use App\Http\Controllers\Api\OrgManager\PaymentController;
use App\Http\Controllers\Api\OrgManager\InvitationController;

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC — No authentication required
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::get('/organizations',  [AuthController::class, 'organizations']);   // Registration dropdown
    Route::post('/register',      [AuthController::class, 'register']);
    Route::post('/login',         [AuthController::class, 'login']);
    Route::post('/send-otp',      [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp',    [AuthController::class, 'verifyOtp']);
    Route::get('/invitation/{token}', [AuthController::class, 'validateInvitation']); // Validate invitation token
});

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL — FastAPI webhook (secret-key protected, not Sanctum)
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('internal')->name('internal.')->group(function () {
    Route::post('/predictions/{jobId}/result', [PredictionWebhookController::class, 'handle'])
         ->name('predictions.result');

    // FL aggregation result webhook (called by FL aggregation space after aggregation)
    Route::post('/fl/aggregation-result', function (\Illuminate\Http\Request $request) {
        $secret = $request->header('X-Internal-Secret');
        if ($secret !== config('services.fl_aggregation.secret')) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->json()->all();
        $roundId = $data['round_id'] ?? null;

        if (!$roundId) {
            return response()->json(['message' => 'Missing round_id.'], 422);
        }

        $round = \App\Models\FlRound::find($roundId);
        if (!$round) {
            return response()->json(['message' => 'Round not found.'], 404);
        }

        // Record blockchain aggregation block
        if (!empty($data['aggregated_weights_hash'])) {
            $contributionHashes = \App\Models\FlRoundInvitation::where('fl_round_id', $roundId)
                ->where('status', 'submitted')
                ->pluck('weights_hash')
                ->filter()
                ->toArray();

            \App\Services\BlockchainHashingService::createAggregationBlock(
                $roundId,
                $data['aggregated_weights_hash'],
                $contributionHashes,
                $round->aggregation_method,
                [
                    'n_contributions' => $data['n_contributions'] ?? 0,
                    'avg_accuracy'    => $data['global_accuracy'] ?? null,
                ]
            );
        }

        $round->update([
            'status'                    => 'completed',
            'global_accuracy'           => $data['global_accuracy'] ?? null,
            'global_loss'               => $data['global_loss'] ?? null,
            'aggregated_weights_r2_key' => $data['aggregated_weights_r2_key'] ?? null,
            'aggregated_weights_hash'   => $data['aggregated_weights_hash'] ?? null,
            'ended_at'                  => now(),
        ]);

        \Illuminate\Support\Facades\Log::info("[FL Webhook] Round #{$round->round_number} aggregation result received. Accuracy: " . ($data['global_accuracy'] ?? 'N/A'));

        return response()->json(['message' => 'Aggregation result recorded.']);
    })->name('fl.aggregation-result');

    // Manual wake trigger — admin only, protected by internal secret header
    Route::post('/brecai/wake', function (\Illuminate\Http\Request $request) {
        $secret = $request->header('X-Internal-Secret');
        if ($secret !== config('app.internal_webhook_secret')) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        \Illuminate\Support\Facades\Artisan::queue('brecai:wake');
        return response()->json(['message' => 'Wake command queued.']);
    })->name('brecai.wake');
});

// ─────────────────────────────────────────────────────────────────────────────
// CHARGILY WEBHOOK — public, HMAC-verified (no Sanctum)
// Register this URL in your Chargily Dashboard → Developers Corner
// URL: POST /api/payment/webhook
// ─────────────────────────────────────────────────────────────────────────────
Route::post('/payment/webhook', [ChargilyWebhookController::class, 'handle']);

// ─────────────────────────────────────────────────────────────────────────────
// FL ROUND INVITATIONS — public, token-based (no auth)
// ─────────────────────────────────────────────────────────────────────────────
Route::get('/public/fl-invite/{token}',      [\App\Http\Controllers\Api\Public\FlInvitationController::class, 'show']);
Route::post('/public/fl-invite/{token}/respond', [\App\Http\Controllers\Api\Public\FlInvitationController::class, 'respond']);

// ─────────────────────────────────────────────────────────────────────────────
// PROTECTED — Requires valid Sanctum token (via HttpOnly cookie or Bearer)
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Shared Auth ──────────────────────────────────────────────────────────
    Route::post('/auth/logout',          [AuthController::class, 'logout']);
    Route::get('/auth/me',               [AuthController::class, 'me']);
    Route::put('/auth/profile',          [AuthController::class, 'updateProfile']);
    Route::post('/auth/avatar',          [AuthController::class, 'updateAvatar']);

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN — Full platform management
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {

        // Dashboard insights
        Route::prefix('insights')->group(function () {
            Route::get('/kpis',                          [AdminInsights::class, 'kpis']);
            Route::get('/user-growth',                   [AdminInsights::class, 'userGrowth']);
            Route::get('/organization-distribution',     [AdminInsights::class, 'organizationDistribution']);
            Route::get('/organization-status-breakdown', [AdminInsights::class, 'organizationStatusBreakdown']);
            Route::get('/predictions-over-time',         [AdminInsights::class, 'predictionsOverTime']);
            Route::get('/prediction-results',            [AdminInsights::class, 'predictionResultsDistribution']);
            Route::get('/patient-age-distribution',      [AdminInsights::class, 'patientAgeDistribution']);
            Route::get('/receptor-status',               [AdminInsights::class, 'receptorStatusDistribution']);
            Route::get('/model-performance',             [AdminInsights::class, 'modelPerformanceOverRounds']);
            Route::get('/top-organizations',             [AdminInsights::class, 'topOrganizationsByActivity']);
        });

        // Organization management
        Route::apiResource('organizations', AdminOrganizationController::class);
        Route::post('organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
        Route::post('organizations/{organization}/reject',  [AdminOrganizationController::class, 'reject']);
        Route::post('organizations/{organization}/suspend', [AdminOrganizationController::class, 'suspend']);

        // User management
        Route::apiResource('users', UserManagementController::class);
        Route::post('users/{user}/activate',   [UserManagementController::class, 'activate']);
        Route::post('users/{user}/deactivate', [UserManagementController::class, 'deactivate']);

        // AI model management
        Route::apiResource('ai-models', AiModelController::class);
        Route::post('ai-models/{aiModel}/activate',   [AiModelController::class, 'activate']);
        Route::post('ai-models/{aiModel}/deactivate', [AiModelController::class, 'deactivate']);

        // Plan management
        Route::apiResource('plans', AdminPlanController::class);
        Route::post('plans/{plan}/activate',   [AdminPlanController::class, 'activate']);
        Route::post('plans/{plan}/deactivate', [AdminPlanController::class, 'deactivate']);

        // Subscriptions (read-only)
        Route::get('subscriptions',                  [AdminSubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}',   [AdminSubscriptionController::class, 'show']);

        // Audit logs (read-only)
        Route::get('audit-logs',        [AuditLogController::class, 'index']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);

        // Payment management (read-only)
        Route::get('payments',           [AdminPaymentController::class, 'index']);
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show']);

        // Examinations (read-only, platform-wide)
        Route::get('examinations',                [AdminExaminationController::class, 'index']);
        Route::get('examinations/{examination}',  [AdminExaminationController::class, 'show']);

        // Patients (read-only, platform-wide)
        Route::get('patients',           [AdminPatientController::class, 'index']);
        Route::get('patients/{patient}', [AdminPatientController::class, 'show']);

        // Predictions (read-only, platform-wide)
        Route::get('predictions',              [AdminPredictionController::class, 'index']);
        Route::get('predictions/{prediction}', [AdminPredictionController::class, 'show']);

        // Federated learning rounds
        Route::get('federated-rounds',                          [AdminFederatedRoundController::class, 'index']);
        Route::post('federated-rounds',                         [AdminFederatedRoundController::class, 'store']);
        Route::get('federated-rounds/{flRound}',                [AdminFederatedRoundController::class, 'show']);
        Route::post('federated-rounds/{flRound}/aggregate',     [AdminFederatedRoundController::class, 'triggerAggregation']);
        Route::post('federated-rounds/{flRound}/complete',      [AdminFederatedRoundController::class, 'complete']);
        Route::post('federated-rounds/{flRound}/cancel',        [AdminFederatedRoundController::class, 'cancel']);
        Route::delete('federated-rounds/{flRound}',             [AdminFederatedRoundController::class, 'destroy']);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // ORG MANAGER — Organization-scoped management
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware('role:org_manager')->prefix('org')->group(function () {

        // Main dashboard (combined summary card)
        Route::get('/dashboard', [OrgDashboard::class, 'summary']);

        // Insights & charts
        Route::prefix('insights')->group(function () {
            Route::get('/kpis',                     [OrgInsights::class, 'kpis']);
            Route::get('/patient-growth',           [OrgInsights::class, 'patientGrowth']);
            Route::get('/predictions-over-time',    [OrgInsights::class, 'predictionsOverTime']);
            Route::get('/prediction-results',       [OrgInsights::class, 'predictionResultsDistribution']);
            Route::get('/patient-age-distribution', [OrgInsights::class, 'patientAgeDistribution']);
            Route::get('/receptor-status',          [OrgInsights::class, 'receptorStatusDistribution']);
            Route::get('/doctor-leaderboard',       [OrgInsights::class, 'doctorActivityLeaderboard']);
            Route::get('/model-performance',        [OrgInsights::class, 'modelPerformance']);
        });

        // Member management (doctors in this org)
        Route::get('members',                  [MemberController::class, 'index']);
        Route::get('members/{user}',           [MemberController::class, 'show']);
        Route::post('members/{user}/approve',  [MemberController::class, 'approve']);
        Route::post('members/{user}/deactivate', [MemberController::class, 'deactivate']);
        Route::delete('members/{user}',        [MemberController::class, 'destroy']);

        // Patients (read-only view)
        Route::get('patients',          [OrgPatientController::class, 'index']);
        Route::get('patients/{patient}',[OrgPatientController::class, 'show']);

        // Reports (read-only view)
        Route::get('reports',          [OrgReportController::class, 'index']);
        Route::get('reports/{report}', [OrgReportController::class, 'show']);

        // AI Models (read-only — active models visible to org)
        Route::get('ai-models',         [AiModelController::class, 'indexPublic']);

        // ── Plans & Payments ─────────────────────────────────────────────────
        Route::get('plans',                [PaymentController::class, 'plans']);             // Available plans
        Route::post('subscribe',           [PaymentController::class, 'subscribe']);         // Initiate Chargily checkout
        Route::get('payments',             [PaymentController::class, 'history']);           // Payment history
        Route::get('subscription',         [PaymentController::class, 'currentSubscription']); 
        Route::get('subscription-status',  [PaymentController::class, 'status']);

        // ── Invitations ───────────────────────────────────────────────────────
        Route::get('invitations',                    [InvitationController::class, 'index']);
        Route::post('invitations',                   [InvitationController::class, 'store']);
        Route::delete('invitations/{invitation}',    [InvitationController::class, 'destroy']);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // DOCTOR — Core clinical workflow
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware('role:doctor')->prefix('doctor')->group(function () {

        // Active AI models (read-only — for prediction wizard)
        Route::get('ai-models', [AiModelController::class, 'indexPublic']);

        // R2 presigned URL for direct browser → R2 upload
        Route::post('wsi/presign',              [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'presign']);
        Route::post('wsi/presign-get',          [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'presignGet']);
        Route::delete('wsi/r2',                 [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'deleteSlide']);
        // Multipart upload (large SVS files)
        Route::post('wsi/multipart/init',       [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'multipartInit']);
        Route::post('wsi/multipart/parts',      [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'multipartParts']);
        Route::post('wsi/multipart/complete',   [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'multipartComplete']);
        Route::post('wsi/multipart/abort',      [\App\Http\Controllers\Api\Doctor\WsiPresignController::class, 'multipartAbort']);

        // Personal dashboard insights
        Route::prefix('insights')->group(function () {
            Route::get('/kpis',                      [DoctorInsights::class, 'kpis']);
            Route::get('/examinations-over-time',    [DoctorInsights::class, 'examinationsOverTime']);
            Route::get('/prediction-results',        [DoctorInsights::class, 'myPredictionResultsDistribution']);
            Route::get('/average-confidence',        [DoctorInsights::class, 'averageConfidence']);
            Route::get('/patient-age-distribution',  [DoctorInsights::class, 'patientAgeDistribution']);
            Route::get('/recent-activity',           [DoctorInsights::class, 'recentActivity']);
        });

        // Patient management (full CRUD)
        Route::apiResource('patients', DoctorPatientController::class);

        // Examination lifecycle
        Route::apiResource('examinations', ExaminationController::class)->except(['update']);
        Route::put('examinations/{examination}',          [ExaminationController::class, 'update']);
        Route::post('examinations/{examination}/submit',  [ExaminationController::class, 'submit']);
        Route::post('examinations/{examination}/conclude',[ExaminationController::class, 'conclude']);
        Route::delete('examinations/{examination}/force', [ExaminationController::class, 'forceDestroy']);

        // WSI uploads — register named route BEFORE apiResource to avoid conflict
        Route::post('wsi-uploads/from-features',  [WsiUploadController::class, 'storeFromFeatures']);
        Route::post('wsi-uploads/from-r2-key',    [WsiUploadController::class, 'storeFromR2Key']);
        Route::apiResource('wsi-uploads', WsiUploadController::class)->except(['update']);
        Route::post('wsi-uploads/{wsiUpload}/extract-features', [WsiUploadController::class, 'extractFeatures']);

        // Predictions
        Route::get('predictions',                               [PredictionController::class, 'index']);
        Route::get('predictions/{prediction}',                  [PredictionController::class, 'show']);
        Route::get('predictions/{prediction}/status',           [PredictionController::class, 'status']);
        Route::post('predictions/predict',                      [PredictionController::class, 'predict']);
        Route::post('predictions/{prediction}/retry',           [PredictionController::class, 'retry']);

        // XAI results (linked to prediction)
        Route::get('predictions/{prediction}/xai', [XaiResultController::class, 'show']);

        // Reports lifecycle
        Route::apiResource('reports', DoctorReportController::class)->except(['update']);
        Route::put('reports/{report}',                  [DoctorReportController::class, 'update']);
        Route::post('reports/{report}/finalize',        [DoctorReportController::class, 'finalize']);
        Route::post('reports/{report}/attach-file',     [DoctorReportController::class, 'attachFile']);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // INSTRUCTOR (FL Admin) — Federated learning management
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware('role:admin|instructor')->prefix('fl')->group(function () {

        // FL insights
        Route::prefix('insights')->group(function () {
            Route::get('/kpis',                   [InstructorInsights::class, 'kpis']);
            Route::get('/accuracy-over-rounds',   [InstructorInsights::class, 'accuracyOverRounds']);
            Route::get('/contributions-per-round',[InstructorInsights::class, 'contributionsPerRound']);
        });

        // Model management
        Route::apiResource('models', InstructorModelController::class)->only(['index', 'show', 'store', 'update']);

        // FL rounds
        Route::get('rounds',                            [FederatedRoundController::class, 'index']);
        Route::get('rounds/current',                    [FederatedRoundController::class, 'current']);
        Route::post('rounds/inspect-data',              [FederatedRoundController::class, 'inspectData']);
        Route::post('rounds/start-training',            [FederatedRoundController::class, 'startTraining']);
        Route::post('rounds/submit-contribution',       [FederatedRoundController::class, 'submitContribution']);
        Route::post('rounds/suggest-hyperparams',       [FederatedRoundController::class, 'suggestHyperparams']);
        Route::get('rounds/{flRound}',                  [FederatedRoundController::class, 'show']);
        Route::post('rounds',                           [FederatedRoundController::class, 'store']);
        Route::post('rounds/{flRound}/complete',        [FederatedRoundController::class, 'complete']);

        // Contributions
        Route::get('contributions',  [ContributionController::class, 'index']);
        Route::post('contributions', [ContributionController::class, 'store']);
    });
});

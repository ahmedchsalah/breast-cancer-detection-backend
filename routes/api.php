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

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC — No authentication required
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::get('/organizations',  [AuthController::class, 'organizations']);   // Registration dropdown
    Route::post('/register',      [AuthController::class, 'register']);
    Route::post('/login',         [AuthController::class, 'login']);
    Route::post('/send-otp',      [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp',    [AuthController::class, 'verifyOtp']);
});

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL — FastAPI webhook (secret-key protected, not Sanctum)
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('internal')->name('internal.')->group(function () {
    Route::post('/predictions/{jobId}/result', [PredictionWebhookController::class, 'handle'])
         ->name('predictions.result');
});

// ─────────────────────────────────────────────────────────────────────────────
// CHARGILY WEBHOOK — public, HMAC-verified (no Sanctum)
// Register this URL in your Chargily Dashboard → Developers Corner
// URL: POST /api/payment/webhook
// ─────────────────────────────────────────────────────────────────────────────
Route::post('/payment/webhook', [ChargilyWebhookController::class, 'handle']);

// ─────────────────────────────────────────────────────────────────────────────
// PROTECTED — Requires valid Sanctum token (via HttpOnly cookie or Bearer)
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Shared Auth ──────────────────────────────────────────────────────────
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

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

        // Audit logs (read-only)
        Route::get('audit-logs',        [AuditLogController::class, 'index']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
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
    });

    // ─────────────────────────────────────────────────────────────────────────
    // DOCTOR — Core clinical workflow
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware('role:doctor')->prefix('doctor')->group(function () {

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

        // WSI uploads
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
        Route::get('rounds/{flRound}',                  [FederatedRoundController::class, 'show']);
        Route::post('rounds',                           [FederatedRoundController::class, 'store']);
        Route::post('rounds/{flRound}/complete',        [FederatedRoundController::class, 'complete']);

        // Contributions
        Route::get('contributions',  [ContributionController::class, 'index']);
        Route::post('contributions', [ContributionController::class, 'store']);
    });
});

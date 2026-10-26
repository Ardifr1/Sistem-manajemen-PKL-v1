<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Admin\AssessmentComponentController;
use App\Http\Controllers\Admin\PklPeriodController;
use App\Http\Controllers\Admin\PklPlacementController;
use App\Http\Controllers\Admin\SchoolController;
use App\Http\Controllers\Admin\CompanyMembershipController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Company\ApplicationReviewController;
use App\Http\Controllers\Company\CompanyController;
use App\Http\Controllers\Company\CompanySupervisorController;
use App\Http\Controllers\Company\InterviewController;
use App\Http\Controllers\Student\ApplicationDocumentController;
use App\Http\Controllers\Student\CompanyProposalController;
use App\Http\Controllers\Student\AttendanceController;
use App\Http\Controllers\Student\JournalAssistantController;
use App\Http\Controllers\Student\JournalController;
use App\Http\Controllers\Student\JournalRecommendationController;
use App\Http\Controllers\Student\ProgressRecordController;
use App\Http\Controllers\Student\FeedbackController;
use App\Http\Controllers\Student\PklApplicationController;
use App\Http\Controllers\Student\StudentProfileController;
use App\Http\Controllers\Teacher\FinalAssessmentController;
use App\Http\Controllers\Teacher\TeacherProfileController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

// Endpoint publik dengan pembatasan laju (anti brute force / spam).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
Route::post('/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:set-password');

Route::middleware(['auth:sanctum', 'account.active', 'password.changed'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->withoutMiddleware('password.changed');
    Route::post('/change-password', [AuthController::class, 'changePassword'])
        ->withoutMiddleware('password.changed')
        ->middleware('throttle:change-password');

    // Company
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('company-supervisors', CompanySupervisorController::class);

    // Company Membership: keanggotaan perusahaan eksplisit + kapabilitas.
    // Pemberian/pencabutan akses hanya oleh admin (dijaga di policy).
    Route::apiResource('company-memberships', CompanyMembershipController::class);

    // Pengajuan perusahaan NONMITRA oleh siswa + verifikasi Admin Sekolah.
    // Disetujui -> perusahaan menjadi mitra dan diproses via alur pengajuan
    // pkl-applications yang sudah ada (tidak ada alur paralel).
    Route::get('company-proposals', [CompanyProposalController::class, 'index']);
    Route::post('company-proposals', [CompanyProposalController::class, 'store']);
    Route::get('company-proposals/{company}', [CompanyProposalController::class, 'show']);
    Route::patch('company-proposals/{company}/verify', [CompanyProposalController::class, 'verify']);

    // PKL Period
    Route::apiResource('pkl-periods', PklPeriodController::class);

    // PKL Application
    Route::apiResource('pkl-applications', PklApplicationController::class);
    // Pilihan final siswa (bila diterima beberapa perusahaan, pilih satu).
    Route::post('/pkl-applications/{pklApplication}/choose', [PklApplicationController::class, 'choose']);
    Route::apiResource('application-documents', ApplicationDocumentController::class);
    Route::get('application-documents/{applicationDocument}/download', [ApplicationDocumentController::class, 'download']);
    Route::apiResource('application-reviews', ApplicationReviewController::class);
    Route::apiResource('interviews', InterviewController::class);

    // PKL Placement
    Route::apiResource('pkl-placements', PklPlacementController::class);
    // Penugasan pembimbing industri oleh PERUSAHAAN penempatan (hanya
    // supervisor_id; field lain tetap kewenangan admin lewat PUT).
    Route::patch('/pkl-placements/{pklPlacement}/supervisor', [PklPlacementController::class, 'assignSupervisor']);

    // Schools (pengelompokan per sekolah, fondasi multi-sekolah)
    Route::apiResource('schools', SchoolController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Journal
    Route::apiResource('journals', JournalController::class);
    Route::post('/journals/{journal}/submit', [JournalController::class, 'submit']);
    Route::post('/journals/{journal}/verify', [JournalController::class, 'verify']);
    // AI Journal Assistant: saran untuk draf jurnal siswa. Dibatasi laju dan
    // TIDAK mengubah status jurnal (verifikasi tetap oleh pembimbing industri).
    Route::post('/journals/ai-assistant', [JournalAssistantController::class, 'review'])
        ->middleware('throttle:ai-journal');

    Route::apiResource(
        'journal-recommendations',
        JournalRecommendationController::class
    );

    // Attendance
    Route::apiResource('attendances', AttendanceController::class);

    // Progress
    Route::apiResource('progress-records', ProgressRecordController::class);

    // Feedback (PRD seksi 27, BR-19)
    Route::apiResource('feedbacks', FeedbackController::class);
    Route::post('/feedbacks/{feedback}/review', [FeedbackController::class, 'review']);

    // Assessment
    Route::apiResource(
        'assessment-components',
        AssessmentComponentController::class
    );

    Route::apiResource('assessments', AssessmentController::class);
    Route::post('/final-assessments/calculate', [FinalAssessmentController::class, 'calculate']);
    Route::post('/final-assessments/{finalAssessment}/publish', [FinalAssessmentController::class, 'publish']);
    Route::post('/final-assessments/{finalAssessment}/unpublish', [FinalAssessmentController::class, 'unpublish']);
    // Tanpa 'store': final_score hanya dari perhitungan otomatis (calculate),
    // tidak dari nilai yang dikirim frontend.
    Route::apiResource('final-assessments', FinalAssessmentController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
    // Profile
    Route::apiResource('student-profiles', StudentProfileController::class);
    // User
    Route::middleware('role:admin,company,supervisor,teacher')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::post('/users/{user}/resend-account', [UserController::class, 'resendAccountInfo'])
        ->middleware('throttle:resend-credentials');
    Route::get('/password-reset-requests', [UserController::class, 'resetRequests']);
    Route::patch('/password-reset-requests/{resetRequest}', [UserController::class, 'resolveResetRequest']);
});
});
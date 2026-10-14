<?php

namespace App\Providers;

use App\Models\ApplicationDocument;
use App\Models\ApplicationReview;
use App\Models\Assessment;
use App\Models\AssessmentComponent;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\FinalAssessment;
use App\Models\Journal;
use App\Models\PklApplication;
use App\Models\PklPeriod;
use App\Models\PklPlacement;
use App\Models\ProgressRecord;
use App\Policies\ApplicationDocumentPolicy;
use App\Policies\ApplicationReviewPolicy;
use App\Policies\AssessmentComponentPolicy;
use App\Policies\AssessmentPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\FinalAssessmentPolicy;
use App\Policies\JournalPolicy;
use App\Policies\PklApplicationPolicy;
use App\Policies\PklPeriodPolicy;
use App\Policies\PklPlacementPolicy;
use App\Policies\ProgressRecordPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ApplicationDocument::class, ApplicationDocumentPolicy::class);
        Gate::policy(ApplicationReview::class, ApplicationReviewPolicy::class);
        Gate::policy(Assessment::class, AssessmentPolicy::class);
        Gate::policy(AssessmentComponent::class, AssessmentComponentPolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(FinalAssessment::class, FinalAssessmentPolicy::class);
        Gate::policy(Journal::class, JournalPolicy::class);
        Gate::policy(PklApplication::class, PklApplicationPolicy::class);
        Gate::policy(PklPeriod::class, PklPeriodPolicy::class);
        Gate::policy(PklPlacement::class, PklPlacementPolicy::class);
        Gate::policy(ProgressRecord::class, ProgressRecordPolicy::class);
    }
}
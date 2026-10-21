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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        $this->configureRateLimiting();
    }

    /**
     * Pembatasan laju endpoint sensitif (login, reset password, ganti password,
     * kirim ulang kredensial) untuk mencegah brute force dan penyalahgunaan.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $username = Str::lower(trim((string) $request->input('username')));

            return [
                // Per akun: menahan tebakan password pada satu akun.
                Limit::perMinute(5)->by('login-user:'.$username.'|'.$request->ip()),
                // Per IP: menahan penyebaran tebakan ke banyak akun.
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return [
                Limit::perMinutes(10, 3)->by('forgot-ip:'.$request->ip()),
                Limit::perMinutes(10, 3)->by('forgot-user:'.Str::lower(trim((string) $request->input('username')))),
            ];
        });

        RateLimiter::for('set-password', function (Request $request) {
            return Limit::perMinutes(10, 10)->by('set-pw:'.$request->ip());
        });

        RateLimiter::for('change-password', function (Request $request) {
            return Limit::perMinute(5)->by('change-pw:'.($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('resend-credentials', function (Request $request) {
            $target = $request->route('user');

            return [
                // Per admin: mencegah pemakaian endpoint berulang.
                Limit::perMinutes(10, 5)->by('resend-actor:'.($request->user()?->id ?? $request->ip())),
                // Per akun tujuan: mencegah satu akun dibanjiri kredensial baru.
                Limit::perMinutes(10, 3)->by('resend-target:'.($target?->id ?? 'unknown')),
            ];
        });
    }
}

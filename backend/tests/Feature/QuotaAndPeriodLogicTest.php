<?php

namespace Tests\Feature;

use App\Models\Journal;
use App\Models\PklApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class QuotaAndPeriodLogicTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    // ===================== 1. COMPANY QUOTA =====================

    public function test_acceptance_beyond_quota_is_rejected(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        // Kuota 1: acceptance pertama berhasil.
        Sanctum::actingAs($companyUser);
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        // Acceptance kedua harus ditolak karena kuota penuh.
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertStatus(422);

        // Application B tetap pending dan tidak ada review kedua.
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('application_reviews', [
            'application_id' => $applicationB->id,
        ]);
    }

    public function test_acceptance_allowed_while_quota_available(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 2]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertCreated();

        $this->assertSame(2, PklApplication::query()
            ->where('company_id', $company->id)
            ->where('status', 'accepted')
            ->count());
    }

    public function test_quota_counts_only_accepted_applications(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $studentC = $this->createUser('student', 'student_c');

        // Kuota 1, tetapi ada 1 pending dan 1 rejected: keduanya tidak menghabiskan kuota.
        $this->makeApplicationFor($studentA, $company, $period);
        $rejected = $this->makeApplicationFor($studentB, $company, $period, 2);
        $rejected->update(['status' => 'rejected']);

        $target = $this->makeApplicationFor($studentC, $company, $period, 3);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $target->id,
            'status' => 'approved',
        ])->assertCreated();
    }

    public function test_rejecting_application_does_not_consume_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        // Tolak A (tidak memakai kuota), lalu terima B (kuota tersedia).
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'rejected',
        ])->assertCreated();

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertCreated();
    }

    public function test_quota_is_scoped_per_period(): void
    {
        $periodA = $this->createPeriod('PKL 2026');
        $periodB = $this->createPeriod('PKL 2027');
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $periodA);
        $applicationB = $this->makeApplicationFor($studentB, $company, $periodB);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        // Kuota per periode: acceptance di periode berbeda tidak saling mengganggu.
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertCreated();
    }

    public function test_review_update_to_approved_respects_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        $reviewB = $this->createReview($applicationB, $companyUser);
        $reviewB->update(['status' => 'rejected']);
        $applicationB->update(['status' => 'rejected']);

        // Update review B dari rejected menjadi approved harus ditolak (kuota penuh).
        $this->putJson("/api/application-reviews/{$reviewB->id}", [
            'status' => 'approved',
        ])->assertStatus(422);

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'rejected',
        ]);
    }

    public function test_zero_quota_company_cannot_accept(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 0]);

        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $application->id,
            'status' => 'approved',
        ])->assertStatus(422);
    }

    // ===================== 2. JOURNAL & ATTENDANCE DATE =====================

    public function test_journal_date_outside_period_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        // Sebelum periode.
        $this->postJson('/api/journals', [
            'placement_id' => $placement->id,
            'journal_date' => now()->subDays(2)->toDateString(),
            'activity' => 'Sebelum PKL.',
        ])->assertStatus(422);

        // Sesudah periode.
        $this->postJson('/api/journals', [
            'placement_id' => $placement->id,
            'journal_date' => now()->addMonths(4)->toDateString(),
            'activity' => 'Sesudah PKL.',
        ])->assertStatus(422);

        $this->assertSame(0, Journal::query()
            ->where('placement_id', $placement->id)
            ->count());
    }

    public function test_journal_date_inside_period_succeeds(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/journals', [
            'placement_id' => $placement->id,
            'journal_date' => now()->toDateString(),
            'activity' => 'Aktivitas hari ini.',
        ])->assertCreated();
    }

    public function test_journal_update_date_outside_period_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $journal = $this->createJournal($placement);

        Sanctum::actingAs($student);

        $this->putJson("/api/journals/{$journal->id}", [
            'journal_date' => now()->addMonths(4)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_attendance_date_outside_period_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        // Sebelum periode.
        $this->postJson('/api/attendances', [
            'placement_id' => $placement->id,
            'attendance_date' => now()->subDays(2)->toDateString(),
            'status' => 'present',
        ])->assertStatus(422);

        // Sesudah periode.
        $this->postJson('/api/attendances', [
            'placement_id' => $placement->id,
            'attendance_date' => now()->addMonths(4)->toDateString(),
            'status' => 'present',
        ])->assertStatus(422);
    }

    public function test_attendance_date_inside_period_succeeds(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/attendances', [
            'placement_id' => $placement->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
        ])->assertCreated();
    }

    public function test_late_submission_with_activity_date_inside_period_succeeds(): void
    {
        // Input terlambat tetap boleh selama tanggal aktivitas dalam periode:
        // journal_date/attendance_date "kemarin" (masih dalam rentang placement
        // yang dibuat dari periode aktif) harus diterima.
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $insideDate = now()->subDays(0)->toDateString();

        $this->postJson('/api/attendances', [
            'placement_id' => $placement->id,
            'attendance_date' => $insideDate,
            'status' => 'present',
        ])->assertCreated();

        $this->postJson('/api/journals', [
            'placement_id' => $placement->id,
            'journal_date' => $insideDate,
            'activity' => 'Dibuat terlambat.',
        ])->assertCreated();
    }

    // ===================== 3. APPLICATION LOCKING & RE-APPLICATION =====================

    public function test_submitted_application_cannot_change_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        Sanctum::actingAs($student);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'company_id' => $otherCompany->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_submitted_application_cannot_change_choice_order(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        Sanctum::actingAs($student);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'choice_order' => 3,
        ])->assertStatus(422);

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'choice_order' => 1,
        ]);
    }

    public function test_student_can_create_new_application_after_all_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $companyA = $this->createCompany('PT Satu');
        $companyB = $this->createCompany('PT Dua');
        $period = $this->createPeriod();

        $this->makeApplicationFor($student, $companyA, $period)
            ->update(['status' => 'rejected']);
        $this->makeApplicationFor($student, $companyB, $period, 2)
            ->update(['status' => 'rejected']);

        $companyC = $this->createCompany('PT Tiga');

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $companyC->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertCreated();

        // Application lama tetap menjadi riwayat.
        $this->assertDatabaseHas('pkl_applications', [
            'student_id' => $student->id,
            'company_id' => $companyA->id,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('pkl_applications', [
            'student_id' => $student->id,
            'company_id' => $companyB->id,
            'status' => 'rejected',
        ]);
    }

    public function test_student_cannot_create_new_application_while_active_exists(): void
    {
        $student = $this->createUser('student', 'student_a');
        $companyA = $this->createCompany('PT Satu');
        $companyB = $this->createCompany('PT Dua');
        $period = $this->createPeriod();

        // Satu pilihan ditolak, satu masih pending -> masih ada yang aktif.
        $this->makeApplicationFor($student, $companyA, $period)
            ->update(['status' => 'rejected']);
        $this->makeApplicationFor($student, $companyB, $period, 2);

        $companyC = $this->createCompany('PT Tiga');

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $companyC->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 3,
        ])->assertStatus(422);
    }

    public function test_new_application_still_respects_partner_and_duplicate_rules(): void
    {
        $student = $this->createUser('student', 'student_a');
        $companyA = $this->createCompany('PT Satu');
        $period = $this->createPeriod();

        $this->makeApplicationFor($student, $companyA, $period)
            ->update(['status' => 'rejected']);

        Sanctum::actingAs($student);

        // Perusahaan lama yang sudah pernah dipilih tidak boleh dipilih lagi (duplikat).
        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $companyA->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertStatus(422);
    }

    public function test_old_application_history_remains_unchanged_after_reapplication(): void
    {
        $student = $this->createUser('student', 'student_a');
        $companyA = $this->createCompany('PT Satu');
        $companyB = $this->createCompany('PT Dua');
        $period = $this->createPeriod();

        $oldApplication = $this->makeApplicationFor($student, $companyA, $period);
        $oldApplication->update(['status' => 'rejected']);

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $companyB->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertCreated();

        $oldApplication->refresh();

        $this->assertSame('rejected', $oldApplication->status);
        $this->assertSame($companyA->id, $oldApplication->company_id);
        $this->assertSame(1, $oldApplication->choice_order);
    }

    // ===================== 4. QUOTA CONSISTENCY CHECKS =====================

    public function test_company_cannot_accept_when_quota_full_via_approved(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        // Company accepts A (status accepted).
        Sanctum::actingAs($companyUser);
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        // Now quota should be 1/1 (accepted). B's acceptance must be rejected.
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertStatus(422);

        // B remains pending, no review created.
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'pending',
        ]);
    }

    public function test_company_cannot_accept_when_quota_full_via_accepted_and_approved(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        // A's application becomes accepted (via company review).
        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        // Now A = accepted (1 slot used). B tries to become accepted = over quota.
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        Sanctum::actingAs($companyUser);
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertStatus(422);

        // B remains pending.
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'pending',
        ]);
    }

    public function test_company_can_accept_when_quota_available(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 2]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationA->id,
            'status' => 'approved',
        ])->assertCreated();

        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertCreated();

        $this->assertSame(2, PklApplication::query()
            ->where('company_id', $company->id)
            ->where('status', 'accepted')
            ->count());
    }

    public function test_company_cannot_accept_when_placement_consumes_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        // Admin manually creates placement without application link (consumes quota via placement).
        $this->createPlacement($studentA, $company, $period);

        $applicationB = $this->makeApplicationFor($studentB, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        // Accepting B's application must fail because placement already consumed the quota.
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertStatus(422);
    }

    public function test_quota_deduplicates_student_with_both_placement_and_application(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $admin = $this->createUser('admin', 'admin_a');

        // Admin creates placement for A AND the application stays accepted.
        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $applicationA->update(['status' => 'accepted']);
        $this->createPlacement($studentA, $company, $period, $applicationA);

        $applicationB = $this->makeApplicationFor($studentB, $company, $period, 2);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        // B's acceptance must be rejected — A is counted once (via placement or accepted
        // application, not both), so quota (1) is already exhausted.
        $this->postJson('/api/application-reviews', [
            'application_id' => $applicationB->id,
            'status' => 'approved',
        ])->assertStatus(422);

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'pending',
        ]);
    }

    public function test_cancelled_placement_releases_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $admin = $this->createUser('admin', 'admin_a');

        // Create placement for A, then cancel it.
        $placement = $this->createPlacement($studentA, $company, $period);
        $placement->update(['status' => 'cancelled']);

        Sanctum::actingAs($admin);

        // B should be able to create a placement now — cancelled placement doesn't consume quota.
        $this->postJson('/api/pkl-placements', [
            'student_id' => $studentB->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertCreated();
    }
}

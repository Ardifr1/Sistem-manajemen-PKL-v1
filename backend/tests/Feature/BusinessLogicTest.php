<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class BusinessLogicTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    // ===================== PENDAFTARAN PKL =====================

    public function test_student_cannot_apply_to_non_partner_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $company->update(['is_partner' => false]);
        $period = $this->createPeriod();

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertStatus(422);
    }

    public function test_student_cannot_apply_to_inactive_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $company->update(['is_active' => false]);
        $period = $this->createPeriod();

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertStatus(422);
    }

    public function test_student_cannot_apply_on_inactive_period(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod('PKL Nonaktif');
        $period->update(['is_active' => false]);

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertStatus(422);
    }

    public function test_student_cannot_apply_more_than_three_companies(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();

        for ($i = 1; $i <= 3; $i++) {
            $this->createCompany("PT Pilihan {$i}");
            $company = \App\Models\Company::query()
                ->where('name', "PT Pilihan {$i}")
                ->firstOrFail();

            $this->makeApplicationFor($student, $company, $period, $i);
        }

        $fourthCompany = $this->createCompany('PT Keempat');

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $fourthCompany->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 4,
        ])->assertStatus(422);

        $this->assertSame(
            3,
            \App\Models\PklApplication::query()
                ->where('student_id', $student->id)
                ->count()
        );
    }

    // ===================== REVIEW =====================

    public function test_company_review_updates_application_status(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $application->id,
            'status' => 'approved',
            'note' => 'Diterima.',
        ])->assertCreated();

        // Review approved oleh perusahaan = application diterima (accepted).
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_accepted_application_cannot_be_reviewed_again(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $application->id,
            'status' => 'rejected',
        ])->assertStatus(422);

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    // ===================== PLACEMENT =====================

    public function test_placement_cannot_be_created_from_pending_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'application_id' => $application->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('pkl_placements', [
            'application_id' => $application->id,
        ]);
    }

    public function test_placement_cannot_be_created_from_rejected_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'rejected']);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'application_id' => $application->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertStatus(422);
    }

    public function test_placement_can_be_created_from_accepted_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'application_id' => $application->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertCreated();

        $this->assertDatabaseHas('pkl_placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
        ]);
    }

    // ===================== PROFIL =====================

    public function test_student_cannot_list_all_student_profiles(): void
    {
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->getJson('/api/student-profiles')->assertForbidden();
    }

    public function test_admin_can_list_all_student_profiles(): void
    {
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->getJson('/api/student-profiles')->assertOk();
    }

    public function test_student_cannot_view_other_student_profile(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $profileB = \App\Models\StudentProfile::create([
            'user_id' => $studentB->id,
            'student_number' => '2026-0002',
            'class' => 'XI RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
        ]);

        Sanctum::actingAs($studentA);

        $this->getJson("/api/student-profiles/{$profileB->id}")
            ->assertForbidden();
    }

    public function test_student_can_create_own_profile(): void
    {
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/student-profiles', [
            'user_id' => $student->id,
            'student_number' => '2026-0001',
            'class' => 'XI RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
        ])->assertCreated();
    }

    public function test_student_cannot_create_profile_for_other_student(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        Sanctum::actingAs($studentA);

        $this->postJson('/api/student-profiles', [
            'user_id' => $studentB->id,
            'student_number' => '2026-0002',
            'class' => 'XI RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
        ])->assertForbidden();
    }

    public function test_company_cannot_manage_companies(): void
    {
        $companyUser = $this->createUser('company', 'company_a');
        $company = $this->createCompany();

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/companies', [
            'name' => 'PT Baru',
            'industry' => 'Teknologi',
            'student_quota' => 5,
        ])->assertForbidden();

        $this->putJson("/api/companies/{$company->id}", [
            'name' => 'PT Diubah',
        ])->assertForbidden();

        $this->deleteJson("/api/companies/{$company->id}")->assertForbidden();
    }

    public function test_company_supervisor_assignment_scoping(): void
    {
        // Aturan aktif (sesuai UI perusahaan, PembimbingKelola): perusahaan
        // membuat akun pembimbing untuk perusahaannya sendiri; role supervisor
        // tidak boleh membuat; admin bisa untuk perusahaan mana pun.
        $companyUser = $this->createUser('company', 'company_a');
        $company = $this->createCompany();
        $this->attachCompanySupervisor($companyUser, $company);
        $otherCompany = $this->createCompany('PT Lain');

        Sanctum::actingAs($companyUser);

        // Perusahaan boleh membuat pembimbing untuk perusahaannya sendiri.
        $this->postJson('/api/company-supervisors', [
            'name' => 'Pembimbing Baru',
            'email' => 'pembimbing-baru@pt.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertCreated();

        // List dibatasi company-nya sendiri.
        $this->getJson('/api/company-supervisors')->assertOk();

        // Role supervisor tidak boleh membuat akun pembimbing.
        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $this->attachCompanySupervisor($supervisorUser, $otherCompany);

        Sanctum::actingAs($supervisorUser);

        $this->postJson('/api/company-supervisors', [
            'name' => 'Pembimbing Lain',
            'email' => 'pembimbing-lain@pt.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertForbidden();
    }

    // ===================== JOURNAL RECOMMENDATION =====================

    public function test_student_cannot_list_recommendation_of_other_student_journal(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placementB = $this->createPlacement($studentB, $company, $period);
        $journalB = $this->createJournal($placementB);

        Sanctum::actingAs($studentA);

        $this->getJson('/api/journal-recommendations?journal_id='.$journalB->id)
            ->assertForbidden();
    }

    public function test_student_can_list_recommendation_of_own_journal(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $journal = $this->createJournal($placement);

        Sanctum::actingAs($student);

        $this->getJson('/api/journal-recommendations?journal_id='.$journal->id)
            ->assertOk();
    }
}

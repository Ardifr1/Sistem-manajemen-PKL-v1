<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Review perusahaan lewat PATCH /pkl-applications (jalur yang dipakai UI
 * perusahaan: PengajuanMagang & SeleksiInterview).
 *
 * Perusahaan hanya boleh mereview lamaran ke perusahaannya sendiri, dengan
 * transisi status yang sah dan pemeriksaan kuota saat menerima.
 */
class CompanyApplicationReviewTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_company_can_accept_application_to_own_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'accepted',
        ])->assertOk();

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application.reviewed',
            'subject_id' => $application->id,
            'new_status' => 'accepted',
        ]);
    }

    public function test_company_can_move_application_through_selection_stages(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'seleksi',
        ])->assertOk();
        $this->assertSame('seleksi', $application->fresh()->status);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'interview',
        ])->assertOk();
        $this->assertSame('interview', $application->fresh()->status);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'rejected',
        ])->assertOk();
        $this->assertSame('rejected', $application->fresh()->status);
    }

    public function test_company_cannot_review_application_of_other_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        $otherUser = $this->createUser('company', 'company_b');
        $this->attachCompanySupervisor($otherUser, $otherCompany);

        Sanctum::actingAs($otherUser);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'accepted',
        ])->assertForbidden();

        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_company_cannot_accept_when_quota_is_full(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);
        $period = $this->createPeriod();

        $appA = $this->makeApplicationFor($studentA, $company, $period, 1);
        $appA->update(['status' => 'accepted']);
        $appB = $this->makeApplicationFor($studentB, $company, $period, 1);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/pkl-applications/{$appB->id}", [
            'status' => 'accepted',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Kuota siswa untuk perusahaan pada periode PKL ini sudah penuh.');

        $this->assertSame('pending', $appB->fresh()->status);
    }

    public function test_company_cannot_review_decided_application_again(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'rejected',
        ])->assertStatus(422);

        $this->assertSame('accepted', $application->fresh()->status);
    }

    public function test_student_cannot_set_status_on_own_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        Sanctum::actingAs($student);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'accepted',
        ])->assertStatus(422);

        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_company_cannot_set_approved_status(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        // 'approved' bukan status review perusahaan (validasi menolak).
        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'approved',
        ])->assertStatus(422);

        $this->assertSame('pending', $application->fresh()->status);
    }
}

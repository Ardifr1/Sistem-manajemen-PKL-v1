<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\CompanyMembership;
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

    public function test_student_can_choose_one_offer_and_other_offers_are_closed(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $applicationA = $this->makeApplicationFor($student, $companyA, $period, 1);
        $applicationA->update(['status' => 'accepted']);
        $applicationB = $this->makeApplicationFor($student, $companyB, $period, 2);
        $applicationB->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/pkl-applications/{$applicationA->id}/choose")
            ->assertOk()
            ->assertJsonPath('closed_offers', 1);

        // Lamaran terpilih selesai diproses; tawaran lain ditutup.
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationA->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application_offers_closed']);

        // Penempatan resmi dibuat OTOMATIS tanpa persetujuan admin.
        $this->assertDatabaseHas('pkl_placements', [
            'student_id' => $student->id,
            'company_id' => $companyA->id,
            'pkl_period_id' => $period->id,
            'application_id' => $applicationA->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'placement.auto_created']);
        $this->assertNotNull($response->json('placement.id'));
    }

    public function test_student_cannot_choose_application_of_another_student(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $period = $this->createPeriod();
        $company = $this->createCompany();

        $applicationB = $this->makeApplicationFor($studentB, $company, $period);
        $applicationB->update(['status' => 'accepted']);

        Sanctum::actingAs($studentA);

        $this->postJson("/api/pkl-applications/{$applicationB->id}/choose")
            ->assertForbidden();
    }

    public function test_student_cannot_choose_application_not_yet_accepted(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $application = $this->makeApplicationFor($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson("/api/pkl-applications/{$application->id}/choose")
            ->assertStatus(422);
    }

    public function test_choose_creates_scheduled_placement_when_period_not_started(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod('PKL Masa Depan');
        $period->update([
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
        ]);
        $company = $this->createCompany();
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/pkl-applications/{$application->id}/choose")->assertOk();

        // Tercatat resmi, tetapi belum aktif sebelum tanggal mulai.
        $this->assertSame('scheduled', $response->json('placement.status'));
        $this->assertDatabaseHas('pkl_placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_choose_twice_does_not_create_duplicate_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $this->postJson("/api/pkl-applications/{$application->id}/choose")->assertOk();
        // Klik kedua: lamaran sudah approved (bukan accepted) -> ditolak.
        $this->postJson("/api/pkl-applications/{$application->id}/choose")->assertStatus(422);

        $this->assertSame(1, \App\Models\PklPlacement::query()
            ->where('student_id', $student->id)
            ->where('pkl_period_id', $period->id)
            ->count());
    }

    public function test_choose_fails_when_company_quota_is_full(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        // Kuota sudah terpakai siswa lain.
        $this->createPlacement($studentB, $company, $period);

        $application = $this->makeApplicationFor($studentA, $company, $period);
        $application->update(['status' => 'accepted']);

        Sanctum::actingAs($studentA);

        $this->postJson("/api/pkl-applications/{$application->id}/choose")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kuota siswa untuk perusahaan pada periode PKL ini sudah penuh.');

        // Tidak ada penempatan; lamaran tetap accepted (bisa dicoba lagi nanti).
        $this->assertDatabaseMissing('pkl_placements', [
            'student_id' => $studentA->id,
            'pkl_period_id' => $period->id,
        ]);
        $this->assertSame('accepted', $application->fresh()->status);
    }

    public function test_choose_fails_for_non_partner_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['verification_status' => \App\Models\Company::VERIFICATION_PENDING]);
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $this->postJson("/api/pkl-applications/{$application->id}/choose")->assertStatus(422);

        $this->assertDatabaseMissing('pkl_placements', [
            'student_id' => $student->id,
            'pkl_period_id' => $period->id,
        ]);
    }

    /**
     * Placement yang dibuat otomatis via choose() tidak mengandung
     * teacher_id dan supervisor_id secara otomatis. Penugasan
     * pembimbing harus dilakukan secara eksplisit (mis. oleh admin).
     */
    public function test_choose_creates_placement_without_auto_assigned_supervisors(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $application = $this->makeApplicationFor($student, $company, $period, 1);
        $application->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/pkl-applications/{$application->id}/choose")->assertOk();

        $placement = \App\Models\PklPlacement::query()
            ->where('student_id', $student->id)
            ->where('pkl_period_id', $period->id)
            ->firstOrFail();

        $this->assertNull($placement->teacher_id, 'teacher_id tidak boleh diisi otomatis');
        $this->assertNull($placement->supervisor_id, 'supervisor_id tidak boleh diisi otomatis');
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNotNull($response->json('placement.id'));
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

    // ===================== COMPANY AUTHORIZATION CONSISTENCY (P1 #5) =====================

    public function test_company_membership_gives_placement_access(): void
    {
        // Company dengan keanggotaan aktif (company_memberships) dapat
        // mengakses data penempatan perusahaan mereka melalui Journal/Attendance.
        $companyUser = $this->createUser('company', 'company_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        // Grant company membership (active)
        CompanyMembership::create([
            'user_id' => $companyUser->id,
            'company_id' => $company->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
            'capabilities' => ['company_admin'],
        ]);

        Sanctum::actingAs($companyUser);

        // Company user dapat melihat journal penempatan perusahaan mereka
        $this->getJson("/api/journals?placement_id={$placement->id}")
            ->assertOk();

        // Company user dapat melihat attendance penempatan perusahaan mereka
        $this->getJson("/api/attendances?placement_id={$placement->id}")
            ->assertOk();
    }

    public function test_inactive_company_membership_no_placement_access(): void
    {
        // Company dengan keanggotaan nonaktif tidak boleh mengakses
        // data penempatan perusahaan mereka melalui Journal/Attendance.
        $companyUser = $this->createUser('company', 'company_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        // Grant inactive company membership
        CompanyMembership::create([
            'user_id' => $companyUser->id,
            'company_id' => $company->id,
            'status' => CompanyMembership::STATUS_INACTIVE,
            'capabilities' => ['company_admin'],
        ]);

        Sanctum::actingAs($companyUser);

        // Company user TIDAK boleh melihat journal penempatan (keanggotaan tidak aktif)
        $this->getJson("/api/journals?placement_id={$placement->id}")
            ->assertStatus(403);

        // Company user TIDAK boleh melihat attendance penempatan (keanggotaan tidak aktif)
        $this->getJson("/api/attendances?placement_id={$placement->id}")
            ->assertStatus(403);
    }

    public function test_company_supervisor_gives_placement_access(): void
    {
        // Company dengan CompanySupervisor (jalur lama) dapat mengakses
        // data penempatan perusahaan mereka.
        $companyUser = $this->createUser('company', 'company_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        // Grant CompanySupervisor (not CompanyMembership)
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        // Company user dapat mengakses journals via authorizedCompanyIds
        $this->getJson("/api/journals?placement_id={$placement->id}")
            ->assertOk();

        // Company user dapat mengakses attendance via authorizedCompanyIds
        $this->getJson("/api/attendances?placement_id={$placement->id}")
            ->assertOk();
    }

    public function test_supervisor_cannot_access_placement_outside_assignment(): void
    {
        // Pembimbing industri hanya boleh mengakses placement yang spesifik
        // ditugaskan kepadanya (via supervisor_id, references company_supervisors),
        // bukan semua placement perusahaan.
        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $company1 = $this->createCompany('PT Satu');
        $company2 = $this->createCompany('PT Dua');
        $period = $this->createPeriod();
        $student1 = $this->createUser('student', 'student_a');
        $student2 = $this->createUser('student', 'student_b');
        $placement1 = $this->createPlacement($student1, $company1, $period);
        $placement2 = $this->createPlacement($student2, $company2, $period);
        // Assign CompanySupervisor to only placement1
        $supervisorRecord = $this->attachCompanySupervisor($supervisorUser, $company1);
        $placement1->update(['supervisor_id' => $supervisorRecord->id]);

        Sanctum::actingAs($supervisorUser);

        // Supervisor boleh mengakses placement1 (yang ditugaskan kepadanya)
        $this->getJson("/api/journals?placement_id={$placement1->id}")
            ->assertOk();

        // Supervisor tidak boleh mengakses placement2 (diluar penugasan)
        $this->getJson("/api/journals?placement_id={$placement2->id}")
            ->assertStatus(403);
    }

    public function test_company_user_cannot_access_other_company_placement(): void
    {
        // Company user hanya dapat mengakses placement di perusahaannya sendiri.
        $companyUser = $this->createUser('company', 'company_a');
        $company1 = $this->createCompany('PT Satu');
        $company2 = $this->createCompany('PT Dua');
        $period = $this->createPeriod();
        $student1 = $this->createUser('student', 'student_a');
        $student2 = $this->createUser('student', 'student_b');

        // Company membership only for company1
        $this->attachCompanyMembership($companyUser, $company1);
        $placement1 = $this->createPlacement($student1, $company1, $period);
        $placement2 = $this->createPlacement($student2, $company2, $period);

        Sanctum::actingAs($companyUser);

        // Boleh akses placement perusahaan miliknya (company1)
        $this->getJson("/api/journals?placement_id={$placement1->id}")
            ->assertOk();

        // Tidak boleh akses placement perusahaan lain (company2)
        $this->getJson("/api/journals?placement_id={$placement2->id}")
            ->assertStatus(403);
    }

    public function test_interview_scoped_to_authorized_company(): void
    {
        // Interview hanya terlihat oleh company yang berwenang atas
        // lamaran yang ada di interview tersebut.
        $companyUser = $this->createUser('company', 'company_a');
        $company1 = $this->createCompany('PT Satu');
        $company2 = $this->createCompany('PT Dua');
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');

        // Company user memiliki keanggotaan hanya untuk company1
        $this->attachCompanyMembership($companyUser, $company1);

        // Buat lamaran ke company1 dan company2
        $app1 = $this->makeApplicationFor($student, $company1, $period, 1);
        $app2 = $this->makeApplicationFor($student, $company2, $period, 2);
        $app1->update(['status' => 'accepted']);
        $app2->update(['status' => 'accepted']);

        // Buat interview untuk kedua lamaran
        $interview1 = \App\Models\Interview::create([
            'application_id' => $app1->id,
            'scheduled_at' => now()->addDay(),
            'place' => 'Online',
            'mode' => 'online',
            'status' => 'scheduled',
        ]);
        $interview2 = \App\Models\Interview::create([
            'application_id' => $app2->id,
            'scheduled_at' => now()->addDay(),
            'place' => 'Online',
            'mode' => 'online',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($companyUser);

        // Company user dapat lihat interview untuk lamaran company1
        $this->getJson("/api/interviews/{$interview1->id}")->assertOk();

        // Company user tidak boleh lihat interview untuk lamaran company2
        $this->getJson("/api/interviews/{$interview2->id}")->assertNotFound();
    }

    public function test_application_review_scoped_to_authorized_company(): void
    {
        // Review hanya terlihat oleh company yang berwenang.
        $companyUser = $this->createUser('company', 'company_a');
        $company1 = $this->createCompany('PT Satu');
        $company2 = $this->createCompany('PT Dua');
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');

        // Company user memiliki keanggotaan hanya untuk company1
        $this->attachCompanyMembership($companyUser, $company1);

        // Buat lamaran ke company1 dan company2
        $app1 = $this->makeApplicationFor($student, $company1, $period, 1);
        $app2 = $this->makeApplicationFor($student, $company2, $period, 2);

        // Buat review untuk kedua lamaran
        $review1 = $this->createReview($app1, $companyUser);
        $review2 = $this->createReview($app2, $this->createUser('company', 'company_b'));

        Sanctum::actingAs($companyUser);

        // Company user dapat lihat review untuk lamaran company1
        $this->getJson("/api/application-reviews/{$review1->id}")->assertOk();

        // Company user tidak boleh lihat review untuk lamaran company2
        $this->getJson("/api/application-reviews/{$review2->id}")->assertForbidden();
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

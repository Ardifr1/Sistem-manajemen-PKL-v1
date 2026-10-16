<?php

namespace Tests\Feature;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * PRD bagian C — Hard Block konflik kepentingan.
 *
 * User dengan keanggotaan AKTIF pada Perusahaan X tidak boleh melakukan
 * persetujuan/penilaian dari SISI SEKOLAH yang berkaitan dengan X — termasuk
 * bila ia berrole Admin. Tindakan sah Pembimbing Industri tidak diblokir.
 */
class ConflictOfInterestTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_admin_with_active_membership_cannot_approve_application_for_that_company(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $admin = $this->createUser('admin', 'admin_a');
        $this->grantMembership($admin, $company);

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'approved',
        ])->assertForbidden();

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_admin_without_membership_can_still_approve(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'approved',
        ])->assertOk();

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'status' => 'approved',
        ]);
    }

    /**
     * Keanggotaan nonaktif tidak boleh memblokir (Hard Block hanya untuk
     * keanggotaan AKTIF).
     */
    public function test_inactive_membership_does_not_block_school_approval(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $admin = $this->createUser('admin', 'admin_a');
        $this->grantMembership($admin, $company, CompanyMembership::STATUS_INACTIVE);

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-applications/{$application->id}", [
            'status' => 'approved',
        ])->assertOk();
    }

    public function test_admin_with_active_membership_cannot_create_placement_for_that_company(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $admin = $this->createUser('admin', 'admin_a');
        $this->grantMembership($admin, $company);

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'application_id' => $application->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseMissing('pkl_placements', [
            'application_id' => $application->id,
        ]);
    }

    public function test_teacher_with_active_membership_cannot_grade_from_school_side(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('guru');

        $teacher = $this->createUser('teacher', 'teacher_a');
        $this->assignTeacher($placement, $teacher);
        $this->grantMembership($teacher, $company);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();

        $this->assertDatabaseMissing('assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
        ]);
    }

    public function test_teacher_without_conflict_can_grade(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('guru');

        $teacher = $this->createUser('teacher', 'teacher_a');
        $this->assignTeacher($placement, $teacher);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertCreated();
    }

    /**
     * Tindakan SAH Pembimbing Industri tidak boleh diblokir hanya karena ia
     * juga memiliki keanggotaan perusahaan tersebut.
     */
    public function test_industry_supervisor_actions_are_not_blocked(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $this->grantMembership($supervisorUser, $company, CompanyMembership::STATUS_ACTIVE, [
            CompanyMembership::CAPABILITY_VERIFY_JOURNALS,
        ]);
        $placement->update(['supervisor_id' => $supervisor->id]);

        Sanctum::actingAs($supervisorUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 88,
        ])->assertCreated();
    }

    /**
     * Keputusan perusahaan (terima/tolak lamaran) yang dicatat oleh admin
     * untuk perusahaan tempatnya menjadi anggota aktif juga diblokir.
     */
    public function test_admin_with_active_membership_cannot_record_company_review(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);

        $admin = $this->createUser('admin', 'admin_a');
        $this->grantMembership($admin, $company);

        Sanctum::actingAs($admin);

        $this->postJson('/api/application-reviews', [
            'application_id' => $application->id,
            'status' => 'approved',
        ])->assertForbidden();

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $application->id,
            'status' => 'pending',
        ]);
    }

    public function test_company_can_still_review_its_own_applications(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->grantMembership($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/application-reviews', [
            'application_id' => $application->id,
            'status' => 'approved',
        ])->assertCreated();
    }

    public function test_admin_with_active_membership_cannot_publish_final_score(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        $placement->update(['status' => 'completed']);

        $admin = $this->createUser('admin', 'admin_a');
        $finalAssessment = $this->createFinalAssessment($placement, $admin);

        $this->grantMembership($admin, $company);

        Sanctum::actingAs($admin);

        $this->postJson("/api/final-assessments/{$finalAssessment->id}/publish")
            ->assertForbidden();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $finalAssessment->id,
            'status' => 'draft',
        ]);
    }

    public function test_admin_without_conflict_can_publish_final_score(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);
        $placement->update(['status' => 'completed']);

        $admin = $this->createUser('admin', 'admin_a');
        $finalAssessment = $this->createFinalAssessment($placement, $admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/final-assessments/{$finalAssessment->id}/publish")
            ->assertOk();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $finalAssessment->id,
            'status' => 'published',
        ]);
    }
}

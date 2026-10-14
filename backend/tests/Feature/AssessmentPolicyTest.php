<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class AssessmentPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_company_cannot_create_assessment_even_for_its_placement(): void
    {
        // Model penilaian baru: hanya guru & pembimbing industri yang menilai.
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();
    }

    public function test_supervisor_can_create_assessment_for_assigned_student(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent();

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $placement->update(['supervisor_id' => $supervisor->id]);

        Sanctum::actingAs($supervisorUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 85,
        ])->assertCreated();
    }

    public function test_supervisor_cannot_create_assessment_for_unassigned_student(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent();

        $supervisorUser = $this->createUser('supervisor', 'supervisor_b');
        $this->attachCompanySupervisor($supervisorUser, $otherCompany);

        Sanctum::actingAs($supervisorUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 85,
        ])->assertForbidden();
    }

    public function test_company_cannot_create_assessment_for_other_company_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_other');
        $this->attachCompanySupervisor($companyUser, $otherCompany);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();
    }

    public function test_student_cannot_create_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        Sanctum::actingAs($student);

        $this->postJson('/api/assessments', [
            'placement_id' => 1,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();
    }

    public function test_teacher_cannot_fill_industry_component(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();
    }

    public function test_teacher_can_fill_own_component(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('guru');

        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertCreated();
    }

    public function test_company_cannot_fill_teacher_component(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('guru');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();
    }

    public function test_supervisor_assessor_role_cannot_be_spoofed(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $placement->update(['supervisor_id' => $supervisor->id]);

        Sanctum::actingAs($supervisorUser);

        $response = $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
            'assessor_role' => 'teacher',
            'assessed_by' => 9999,
        ]);

        $response->assertCreated();
        $this->assertSame('supervisor', $response->json('data.assessor_role'));
        $this->assertSame($supervisorUser->id, $response->json('data.assessed_by'));
    }

    public function test_supervisor_can_only_assess_assigned_student(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $supervisorUserA = $this->createUser('supervisor', 'supervisor_a');
        $supervisorA = $this->attachCompanySupervisor($supervisorUserA, $company);
        $supervisorUserB = $this->createUser('supervisor', 'supervisor_b');
        $supervisorB = $this->attachCompanySupervisor($supervisorUserB, $company);

        $placementA = $this->createPlacement($studentA, $company, $period);
        $placementA->update(['supervisor_id' => $supervisorA->id]);
        $placementB = $this->createPlacement($studentB, $company, $period);
        $placementB->update(['supervisor_id' => $supervisorB->id]);

        $component = $this->createComponent('industri');

        Sanctum::actingAs($supervisorUserA);

        $this->postJson('/api/assessments', [
            'placement_id' => $placementA->id,
            'component_id' => $component->id,
            'score' => 85,
        ])->assertCreated();

        $this->postJson('/api/assessments', [
            'placement_id' => $placementB->id,
            'component_id' => $component->id,
            'score' => 85,
        ])->assertForbidden();
    }

    public function test_inactive_component_cannot_be_scored(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');
        $component->update(['is_active' => false]);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $placement->update(['supervisor_id' => $supervisor->id]);

        Sanctum::actingAs($supervisorUser);

        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertStatus(422);
    }

    public function test_company_can_list_assessments_scoped_to_own_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $studentOther = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $placementOther = $this->createPlacement($studentOther, $otherCompany, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->createAssessment($placement, $component, $companyUser);

        $otherUser = $this->createUser('company', 'company_other');
        $this->attachCompanySupervisor($otherUser, $otherCompany);
        $this->createAssessment($placementOther, $component, $otherUser);

        Sanctum::actingAs($companyUser);

        // Index tanpa filter: hanya baris perusahaannya sendiri.
        $response = $this->getJson('/api/assessments')->assertOk();
        $ids = collect($response->json('data'))->pluck('placement_id')->all();
        $this->assertContains($placement->id, $ids);
        $this->assertNotContains($placementOther->id, $ids);

        // Index dengan placement_id milik sendiri: boleh.
        $this->getJson("/api/assessments?placement_id={$placement->id}")->assertOk();

        // Index dengan placement_id perusahaan lain: tetap 403.
        $this->getJson("/api/assessments?placement_id={$placementOther->id}")->assertForbidden();
    }

    public function test_supervisor_can_read_active_components(): void
    {
        $this->createComponent('industri');

        $company = $this->createCompany();
        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $this->attachCompanySupervisor($supervisorUser, $company);

        Sanctum::actingAs($supervisorUser);

        $this->getJson('/api/assessment-components')->assertOk();
    }

    public function test_student_can_view_assessment_of_own_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $assessment = $this->createAssessment($placement, $component, $companyUser);

        Sanctum::actingAs($student);

        $this->getJson("/api/assessments/{$assessment->id}")
            ->assertOk();
    }

    public function test_student_cannot_view_assessment_of_other_student(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placementB = $this->createPlacement($studentB, $company, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $assessment = $this->createAssessment($placementB, $component, $companyUser);

        Sanctum::actingAs($studentA);

        $this->getJson("/api/assessments/{$assessment->id}")
            ->assertForbidden();
    }

    public function test_other_company_cannot_update_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $assessment = $this->createAssessment($placement, $component, $companyUser);

        $companyOtherUser = $this->createUser('company', 'company_other');
        $this->attachCompanySupervisor($companyOtherUser, $otherCompany);

        Sanctum::actingAs($companyOtherUser);

        $this->putJson("/api/assessments/{$assessment->id}", [
            'score' => 100,
        ])->assertForbidden();
    }

    public function test_assessor_can_update_own_assessment(): void
    {
        // Model baru: penilai adalah guru / pembimbing industri.
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $placement->update(['supervisor_id' => $supervisor->id]);
        $assessment = $this->createAssessment($placement, $component, $supervisorUser);

        Sanctum::actingAs($supervisorUser);

        $this->putJson("/api/assessments/{$assessment->id}", [
            'score' => 95,
        ])->assertOk();

        $this->assertDatabaseHas('assessments', [
            'id' => $assessment->id,
            'score' => 95,
        ]);
    }

    public function test_teacher_cannot_delete_assessment_of_other_assessor(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent('industri');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $assessment = $this->createAssessment($placement, $component, $companyUser);

        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->deleteJson("/api/assessments/{$assessment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('assessments', [
            'id' => $assessment->id,
        ]);
    }
}

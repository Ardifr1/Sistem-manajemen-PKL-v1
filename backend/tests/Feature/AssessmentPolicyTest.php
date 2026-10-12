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
        $component = $this->createComponent();

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
        $component = $this->createComponent();

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
        $component = $this->createComponent();

        Sanctum::actingAs($student);

        $this->postJson('/api/assessments', [
            'placement_id' => 1,
            'component_id' => $component->id,
            'score' => 90,
        ])->assertForbidden();
    }

    public function test_student_can_view_assessment_of_own_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $component = $this->createComponent();

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
        $component = $this->createComponent();

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
        $component = $this->createComponent();

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
        $component = $this->createComponent();

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
        $component = $this->createComponent();

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

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class PklPlacementPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_student_can_view_own_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertOk();
    }

    public function test_student_cannot_view_other_student_placement(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($studentB, $company, $period);

        Sanctum::actingAs($studentA);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertForbidden();
    }

    public function test_company_can_view_placement_at_its_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertOk();
    }

    public function test_company_cannot_view_placement_of_other_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_other');
        $this->attachCompanySupervisor($companyUser, $otherCompany);

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertForbidden();
    }

    public function test_teacher_can_view_placement_of_assigned_student(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $this->assignTeacher($placement, $teacher);

        Sanctum::actingAs($teacher);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertOk();
    }

    public function test_teacher_cannot_view_placement_of_unassigned_student(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        // Guru yang belum ditugaskan ke penempatan ini.
        $otherTeacher = $this->createUser('teacher', 'teacher_b');

        Sanctum::actingAs($otherTeacher);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertForbidden();
    }

    public function test_student_cannot_create_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_admin_can_update_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'status' => 'completed',
        ]);
    }

    public function test_teacher_cannot_delete_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->deleteJson("/api/pkl-placements/{$placement->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
        ]);
    }
}

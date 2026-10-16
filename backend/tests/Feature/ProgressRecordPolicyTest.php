<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class ProgressRecordPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_teacher_can_create_progress_record(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $this->assignTeacher($placement, $teacher);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/progress-records', [
            'placement_id' => $placement->id,
            'development' => 'Perkembangan sangat baik.',
            'recorded_date' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_student_cannot_create_progress_record(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/progress-records', [
            'placement_id' => 1,
            'development' => 'Coba membuat catatan.',
            'recorded_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_teacher_cannot_create_record_for_placement_of_other_company(): void
    {
        // Guru boleh memantau, namun tetap tidak boleh dicatat atas
        // penempatan yang tidak terkait (konteks endpoint by placement).
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();

        $studentOther = $this->createUser('student', 'student_other');
        $placementOther = $this->createPlacement($studentOther, $otherCompany, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/progress-records', [
            'placement_id' => $placementOther->id,
            'development' => 'Catatan ilegal.',
            'recorded_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_student_can_view_record_of_own_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $record = $this->createProgressRecord($placement, $teacher);

        Sanctum::actingAs($student);

        $this->getJson("/api/progress-records/{$record->id}")
            ->assertOk();
    }

    public function test_student_cannot_view_record_of_other_student(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placementB = $this->createPlacement($studentB, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $record = $this->createProgressRecord($placementB, $teacher);

        Sanctum::actingAs($studentA);

        $this->getJson("/api/progress-records/{$record->id}")
            ->assertForbidden();
    }

    public function test_company_cannot_update_record_recorded_by_teacher(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $record = $this->createProgressRecord($placement, $teacher);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/progress-records/{$record->id}", [
            'development' => 'Diubah tanpa hak.',
        ])->assertForbidden();
    }

    public function test_recorder_can_update_own_record(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $record = $this->createProgressRecord($placement, $companyUser);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/progress-records/{$record->id}", [
            'development' => 'Diperbarui oleh pencatat.',
        ])->assertOk();
    }

    public function test_other_teacher_cannot_delete_record_of_another_teacher(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacherA = $this->createUser('teacher', 'teacher_a');
        $teacherB = $this->createUser('teacher', 'teacher_b');
        $record = $this->createProgressRecord($placement, $teacherA);

        Sanctum::actingAs($teacherB);

        $this->deleteJson("/api/progress-records/{$record->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('progress_records', [
            'id' => $record->id,
        ]);
    }
}

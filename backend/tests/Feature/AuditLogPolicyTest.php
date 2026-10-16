<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class AuditLogPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_only_admin_can_view_audit_logs(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $placement->update(['status' => 'completed']);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->scorePlacementFully($placement, $companyUser, $teacher);

        Sanctum::actingAs($teacher);
        $created = $this->postJson('/api/final-assessments/calculate', [
            'placement_id' => $placement->id,
        ])->assertOk();
        $id = $created->json('data.id');

        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$id}/publish")->assertOk();

        // Admin boleh melihat audit log.
        $this->getJson('/api/audit-logs')->assertOk();

        // Guru tidak boleh.
        Sanctum::actingAs($teacher);
        $this->getJson('/api/audit-logs')->assertForbidden();

        // Siswa tidak boleh.
        Sanctum::actingAs($student);
        $this->getJson('/api/audit-logs')->assertForbidden();
    }
}

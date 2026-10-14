<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class FinalAssessmentPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_teacher_can_calculate_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        // Nilai backend: nilai dihitung server, bukan dikirim frontend.
        $this->scorePlacementFully($placement, $companyUser, $teacher);

        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
        ]);

        $response->assertCreated();
        $this->assertEquals(83.0, (float) $response->json('data.final_score'));

        // Upaya memalsukan final_score dari frontend harus diabaikan/ditolak.
        $this->assertDatabaseHas('final_assessments', [
            'placement_id' => $placement->id,
            'status' => 'draft',
        ]);
    }

    public function test_final_score_from_request_is_ignored(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->scorePlacementFully($placement, $companyUser, $teacher);

        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
            'final_score' => 100,
            'status' => 'published',
        ]);

        $response->assertCreated();
        $this->assertEquals(83.0, (float) $response->json('data.final_score'));
        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_calculation_fails_when_components_incomplete(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $components = $this->createDefaultComponents();

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->createAssessment($placement, $components['industri'], $companyUser, 80);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('final_assessments', [
            'placement_id' => $placement->id,
        ]);
    }

    public function test_calculation_fails_when_weight_not_100(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        // Total 90 — tidak valid.
        $industri = $this->createComponent('industri', 60);
        $guru = $this->createComponent('guru', 30);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->createAssessment($placement, $industri, $companyUser, 80);
        $this->createAssessment($placement, $guru, $teacher, 90);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
        ])->assertStatus(422);
    }

    public function test_student_cannot_create_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/final-assessments', [
            'placement_id' => 1,
        ])->assertForbidden();
    }

    public function test_company_cannot_create_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/final-assessments', [
            'placement_id' => 1,
        ])->assertForbidden();
    }

    public function test_teacher_cannot_finalize_for_placement_of_other_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placementOther = $this->createPlacement($student, $company, $period);

        // Placement milik perusahaan lain tidak boleh dinilai akhir oleh
        // company user dari perusahaan berbeda (guest teacher path).
        $companyUser = $this->createUser('company', 'company_other');
        $this->attachCompanySupervisor($companyUser, $otherCompany);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/final-assessments', [
            'placement_id' => $placementOther->id,
        ])->assertForbidden();
    }

    public function test_student_can_view_own_published_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placement, $teacher, 'published');

        Sanctum::actingAs($student);

        $this->getJson("/api/final-assessments/{$finalAssessment->id}")
            ->assertOk();
    }

    public function test_student_cannot_view_own_draft_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placement, $teacher, 'draft');

        Sanctum::actingAs($student);

        $this->getJson("/api/final-assessments/{$finalAssessment->id}")
            ->assertForbidden();
    }

    public function test_student_cannot_view_final_assessment_of_other_student(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placementB = $this->createPlacement($studentB, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placementB, $teacher, 'published');

        Sanctum::actingAs($studentA);

        $this->getJson("/api/final-assessments/{$finalAssessment->id}")
            ->assertForbidden();
    }

    public function test_company_can_view_final_assessment_at_its_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placement, $teacher);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/final-assessments/{$finalAssessment->id}")
            ->assertOk();
    }

    public function test_company_cannot_view_final_assessment_of_other_company(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placement, $teacher);

        $companyUser = $this->createUser('company', 'company_other');
        $this->attachCompanySupervisor($companyUser, $otherCompany);

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/final-assessments/{$finalAssessment->id}")
            ->assertForbidden();
    }

    public function test_company_cannot_update_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placement, $teacher);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/final-assessments/{$finalAssessment->id}", [
            'final_score' => 100,
        ])->assertForbidden();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $finalAssessment->id,
            'final_score' => '88.00',
        ]);
    }

    public function test_student_cannot_update_final_assessment(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $finalAssessment = $this->createFinalAssessment($placement, $teacher);

        Sanctum::actingAs($student);

        $this->putJson("/api/final-assessments/{$finalAssessment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $finalAssessment->id,
            'final_score' => '88.00',
        ]);
    }

    // ===================== PUBLISH / UNPUBLISH (Admin only) =====================

    public function test_only_admin_can_publish(): void
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
        $created = $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
        ])->assertCreated();
        $id = $created->json('data.id');

        // Guru tidak boleh publish.
        $this->postJson("/api/final-assessments/{$id}/publish")
            ->assertForbidden();

        // Perusahaan tidak boleh publish.
        Sanctum::actingAs($companyUser);
        $this->postJson("/api/final-assessments/{$id}/publish")
            ->assertForbidden();

        // Admin boleh publish.
        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$id}/publish")
            ->assertOk();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'final_assessment_id' => $id,
            'action' => 'publish',
            'new_status' => 'published',
        ]);
    }

    public function test_publish_requires_completed_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        // status masih active — belum selesai.

        $teacher = $this->createUser('teacher', 'teacher_a');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);
        $this->scorePlacementFully($placement, $companyUser, $teacher);

        Sanctum::actingAs($teacher);
        $created = $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
        ])->assertCreated();
        $id = $created->json('data.id');

        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);

        $this->postJson("/api/final-assessments/{$id}/publish")
            ->assertStatus(422);
    }

    public function test_unpublish_requires_reason_and_writes_audit_log(): void
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
        $created = $this->postJson('/api/final-assessments', [
            'placement_id' => $placement->id,
        ])->assertCreated();
        $id = $created->json('data.id');

        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$id}/publish")->assertOk();

        // Tanpa alasan → 422.
        $this->postJson("/api/final-assessments/{$id}/unpublish", [])
            ->assertStatus(422);

        // Dengan alasan → kembali draft + audit log.
        $this->postJson("/api/final-assessments/{$id}/unpublish", [
            'reason' => 'Ada kesalahan input nilai industri.',
        ])->assertOk();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'final_assessment_id' => $id,
            'action' => 'unpublish',
            'new_status' => 'draft',
        ]);

        // Siswa tidak bisa lagi melihat nilai yang dibatalkan.
        Sanctum::actingAs($student);
        $this->getJson("/api/final-assessments/{$id}")->assertForbidden();
    }
}

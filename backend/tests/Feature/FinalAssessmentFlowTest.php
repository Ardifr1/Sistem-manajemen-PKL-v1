<?php

namespace Tests\Feature;

use App\Models\AssessmentComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Alur penilaian akhir: komponen & bobot (admin), penilaian per peran,
 * perhitungan otomatis, publikasi/pembatalan (admin), dan akses siswa.
 *
 * CATATAN: ditulis mengikuti keputusan bisnis terbaru; dijalankan manual
 * di mesin dengan PHP terpasang (php artisan test).
 */
class FinalAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    private function seedComponents(array $weights = [60, 40], array $roles = ['supervisor', 'teacher']): array
    {
        $names = ['Kedisiplinan', 'Kualitas Jurnal'];
        $out = [];
        foreach ($weights as $i => $w) {
            $out[] = AssessmentComponent::create([
                'name' => $names[$i] . '_' . uniqid(),
                'weight' => $w,
                'assessor_role' => $roles[$i],
                'is_active' => true,
            ]);
        }
        return $out;
    }

    private function makePlacement(string $status = 'active')
    {
        $student = $this->createUser('student', 'student_' . uniqid());
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);
        $placement->update(['status' => $status]);
        return [$placement, $student, $company];
    }

    public function test_admin_can_crud_components(): void
    {
        $admin = $this->createUser('admin', 'admin_' . uniqid());
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/assessment-components', [
            'name' => 'Komunikasi_' . uniqid(),
            'weight' => 25,
            'assessor_role' => 'supervisor',
        ])->assertCreated();
        $id = $res->json('data.id');

        $this->putJson("/api/assessment-components/{$id}", ['weight' => 30])->assertOk();
        $this->deleteJson("/api/assessment-components/{$id}")->assertOk();
    }

    public function test_non_admin_cannot_create_component(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_' . uniqid());
        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessment-components', [
            'name' => 'X_' . uniqid(),
            'weight' => 10,
            'assessor_role' => 'teacher',
        ])->assertForbidden();
    }

    public function test_calculate_rejects_when_total_weight_not_100(): void
    {
        [$placement] = $this->makePlacement();
        $this->seedComponents([60, 30]); // total 90

        $teacher = $this->createUser('teacher', 'teacher_' . uniqid());
        Sanctum::actingAs($teacher);

        $res = $this->postJson('/api/final-assessments/calculate', [
            'placement_id' => $placement->id,
        ])->assertStatus(422);
        $this->assertStringContainsString('100', $res->json('message'));
    }

    public function test_calculate_rejects_when_components_incomplete(): void
    {
        [$placement] = $this->makePlacement();
        [$c1, $c2] = $this->seedComponents([60, 40]);

        $teacher = $this->createUser('teacher', 'teacher_' . uniqid());
        // hanya komponen guru yang dinilai
        \App\Models\Assessment::create([
            'placement_id' => $placement->id,
            'component_id' => $c2->id,
            'assessed_by' => $teacher->id,
            'assessor_role' => 'teacher',
            'score' => 80,
        ]);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/final-assessments/calculate', [
            'placement_id' => $placement->id,
        ])->assertStatus(422)->assertJsonStructure(['missing']);
    }

    public function test_calculate_weighted_average(): void
    {
        [$placement] = $this->makePlacement();
        [$c1, $c2] = $this->seedComponents([60, 40]); // total 100

        $supervisor = $this->createUser('supervisor', 'sup_' . uniqid());
        $teacher = $this->createUser('teacher', 'tch_' . uniqid());

        // supervisor: skala 1-5 -> 80 ; guru: 0-100 -> 90
        \App\Models\Assessment::create([
            'placement_id' => $placement->id, 'component_id' => $c1->id,
            'assessed_by' => $supervisor->id, 'assessor_role' => 'supervisor', 'score' => 80,
        ]);
        \App\Models\Assessment::create([
            'placement_id' => $placement->id, 'component_id' => $c2->id,
            'assessed_by' => $teacher->id, 'assessor_role' => 'teacher', 'score' => 90,
        ]);

        Sanctum::actingAs($teacher);

        // (80*60 + 90*40) / 100 = 84
        $this->postJson('/api/final-assessments/calculate', [
            'placement_id' => $placement->id,
        ])->assertOk()->assertJsonPath('data.final_score', 84.0);

        $this->assertDatabaseHas('final_assessments', [
            'placement_id' => $placement->id,
            'final_score' => 84.0,
            'status' => 'draft',
        ]);
    }

    public function test_publish_rejected_when_placement_not_completed(): void
    {
        [$placement] = $this->makePlacement('active');
        $admin = $this->createUser('admin', 'admin_' . uniqid());
        $fa = $this->createFinalAssessment($placement, $admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/final-assessments/{$fa->id}/publish")
            ->assertStatus(422);
    }

    public function test_only_admin_can_publish_and_unpublish(): void
    {
        [$placement] = $this->makePlacement('completed');
        $admin = $this->createUser('admin', 'admin_' . uniqid());
        $teacher = $this->createUser('teacher', 'teacher_' . uniqid());
        $fa = $this->createFinalAssessment($placement, $admin);

        // guru tidak boleh publish
        Sanctum::actingAs($teacher);
        $this->postJson("/api/final-assessments/{$fa->id}/publish")->assertForbidden();

        // admin boleh
        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$fa->id}/publish")->assertOk();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $fa->id, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('final_assessment_status_logs', [
            'final_assessment_id' => $fa->id, 'action' => 'published', 'changed_by' => $admin->id,
        ]);

        // unpublish oleh guru ditolak, oleh admin diterima + tercatat
        Sanctum::actingAs($teacher);
        $this->postJson("/api/final-assessments/{$fa->id}/unpublish")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$fa->id}/unpublish", ['note' => 'revisi nilai'])
            ->assertOk();

        $this->assertDatabaseHas('final_assessments', [
            'id' => $fa->id, 'status' => 'draft',
        ]);
        $this->assertDatabaseHas('final_assessment_status_logs', [
            'final_assessment_id' => $fa->id, 'action' => 'unpublished', 'note' => 'revisi nilai',
        ]);
    }

    public function test_student_cannot_see_unpublished_score(): void
    {
        [$placement, $student] = $this->makePlacement('completed');
        $admin = $this->createUser('admin', 'admin_' . uniqid());
        $fa = $this->createFinalAssessment($placement, $admin);

        Sanctum::actingAs($student);

        // index terfilter miliknya tapi show ditolak karena belum published
        $this->getJson("/api/final-assessments/{$fa->id}")->assertForbidden();

        // setelah publish, siswa bisa lihat
        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$fa->id}/publish")->assertOk();

        Sanctum::actingAs($student);
        $this->getJson("/api/final-assessments/{$fa->id}")->assertOk();

        // setelah unpublish, siswa tidak bisa lihat lagi
        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$fa->id}/unpublish")->assertOk();

        Sanctum::actingAs($student);
        $this->getJson("/api/final-assessments/{$fa->id}")->assertForbidden();
    }

    public function test_published_score_cannot_be_changed_silently(): void
    {
        [$placement] = $this->makePlacement('completed');
        $admin = $this->createUser('admin', 'admin_' . uniqid());
        $teacher = $this->createUser('teacher', 'teacher_' . uniqid());
        $fa = $this->createFinalAssessment($placement, $admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/final-assessments/{$fa->id}/publish")->assertOk();

        // update saat published ditolak
        Sanctum::actingAs($teacher);
        $this->putJson("/api/final-assessments/{$fa->id}", ['status' => 'draft'])
            ->assertStatus(422);

        // calculate ulang saat published ditolak
        $this->postJson('/api/final-assessments/calculate', ['placement_id' => $placement->id])
            ->assertStatus(422);
    }

    public function test_company_cannot_assess(): void
    {
        [$placement, , $company] = $this->makePlacement();
        [$c1] = $this->seedComponents([100], ['supervisor']);
        $companyUser = $this->createUser('company', 'comp_' . uniqid());
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);
        $this->postJson('/api/assessments', [
            'placement_id' => $placement->id,
            'component_id' => $c1->id,
            'score' => 90,
        ])->assertForbidden();
    }
}

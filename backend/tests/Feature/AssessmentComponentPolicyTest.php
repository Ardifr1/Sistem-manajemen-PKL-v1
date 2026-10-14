<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class AssessmentComponentPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_only_admin_can_manage_components(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');
        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessment-components', [
            'name' => 'Baru',
            'owner_type' => 'guru',
            'weight' => 10,
        ])->assertForbidden();
    }

    public function test_total_active_weight_may_not_exceed_100(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);

        $this->postJson('/api/assessment-components', [
            'name' => 'Komponen A',
            'owner_type' => 'industri',
            'weight' => 70,
        ])->assertCreated();

        $this->postJson('/api/assessment-components', [
            'name' => 'Komponen B',
            'owner_type' => 'guru',
            'weight' => 30,
        ])->assertCreated();

        // Total sudah 100 — tambahan berbobot akan ditolak.
        $this->postJson('/api/assessment-components', [
            'name' => 'Komponen C',
            'owner_type' => 'guru',
            'weight' => 10,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('assessment_components', [
            'name' => 'Komponen C',
        ]);
    }

    public function test_admin_can_change_default_70_30_weights(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);

        // Bobot awal hanyalah nilai awal: admin boleh mengubah selama
        // total komponen aktif tidak melebihi 100.
        $a = $this->postJson('/api/assessment-components', [
            'name' => 'Industri X',
            'owner_type' => 'industri',
            'weight' => 60,
        ])->assertCreated()->json('data.id');

        $b = $this->postJson('/api/assessment-components', [
            'name' => 'Guru X',
            'owner_type' => 'guru',
            'weight' => 40,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/assessment-components/{$a}", ['weight' => 50])
            ->assertOk();
        $this->putJson("/api/assessment-components/{$b}", ['weight' => 40])
            ->assertOk();

        $this->assertDatabaseHas('assessment_components', [
            'id' => $a,
            'weight' => '50.00',
        ]);
    }

    public function test_seeded_default_weights_are_70_30(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AssessmentComponentSeeder']);

        $total = \App\Models\AssessmentComponent::where('is_active', true)->sum('weight');
        $industri = \App\Models\AssessmentComponent::where('is_active', true)->where('owner_type', 'industri')->sum('weight');
        $guru = \App\Models\AssessmentComponent::where('is_active', true)->where('owner_type', 'guru')->sum('weight');

        $this->assertEquals(100.0, (float) $total);
        $this->assertEquals(70.0, (float) $industri);
        $this->assertEquals(30.0, (float) $guru);
    }
}

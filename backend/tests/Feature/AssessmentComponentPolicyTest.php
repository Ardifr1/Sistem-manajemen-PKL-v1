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

    public function test_owner_type_and_assessor_role_stay_in_sync(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);

        // UI baru mengirim assessor_role saja (default owner_type = industri,
        // jadi assert 'guru' membuktikan penurunan otomatis, bukan nilai default).
        $this->postJson('/api/assessment-components', [
            'name' => 'Komponen Guru ' . uniqid(),
            'weight' => 10,
            'assessor_role' => 'teacher',
        ])->assertCreated()->assertJsonPath('data.owner_type', 'guru')
            ->assertJsonPath('data.assessor_role', 'teacher');

        // UI lama mengirim owner_type saja (default assessor_role = teacher,
        // jadi assert 'supervisor' membuktikan penurunan otomatis).
        $this->postJson('/api/assessment-components', [
            'name' => 'Komponen Industri ' . uniqid(),
            'weight' => 10,
            'owner_type' => 'industri',
        ])->assertCreated()->assertJsonPath('data.assessor_role', 'supervisor')
            ->assertJsonPath('data.owner_type', 'industri');
    }

    public function test_component_sync_survives_update(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/assessment-components', [
            'name' => 'Komponen Ubah ' . uniqid(),
            'weight' => 10,
            'assessor_role' => 'teacher',
        ])->assertCreated()->assertJsonPath('data.owner_type', 'guru')->json('data.id');

        // Ubah peran penilai → owner_type ikut berubah.
        $this->putJson("/api/assessment-components/{$id}", [
            'assessor_role' => 'supervisor',
        ])->assertOk()->assertJsonPath('data.owner_type', 'industri')
            ->assertJsonPath('data.assessor_role', 'supervisor');
    }

    public function test_database_seeder_produces_valid_single_component_set(): void
    {
        // Regresi: dulu DatabaseSeeder menanam komponen ganda → total 175%
        // sehingga nilai akhir tidak pernah bisa dihitung.
        $this->artisan('db:seed');

        $components = \App\Models\AssessmentComponent::where('is_active', true)->get();
        $total = (float) $components->sum('weight');

        $this->assertEquals(100.0, $total);
        $this->assertEqualsCanonicalizing(
            ['supervisor', 'teacher'],
            $components->pluck('assessor_role')->unique()->values()->all()
        );

        // owner_type dan assessor_role harus konsisten untuk setiap baris.
        foreach ($components as $component) {
            $expected = $component->owner_type === 'industri' ? 'supervisor' : 'teacher';
            $this->assertSame($expected, $component->assessor_role, "Komponen {$component->name} tidak sinkron.");
        }
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

<?php

namespace Tests\Feature;

use App\Models\PklPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Tahap 3 — alur operasional end-to-end satu siklus PKL:
 * pengajuan siswa → penerimaan perusahaan → pilihan final siswa → penempatan
 * otomatis → penugasan guru (admin) & pembimbing (perusahaan) → jurnal siswa →
 * verifikasi pembimbing → penilaian industri & guru → nilai akhir → publikasi.
 */
class EndToEndFlowTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_full_pkl_lifecycle_from_application_to_published_final_score(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();

        $admin = $this->createUser('admin', 'admin_e2e');
        $teacher = $this->createUser('teacher', 'teacher_e2e');
        $student = $this->createUser('student', 'student_e2e');
        $companyUser = $this->createUser('company', 'company_e2e');
        $this->attachCompanyMembership($companyUser, $company);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_e2e');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        // 1. Siswa mengajukan PKL.
        Sanctum::actingAs($student);

        $applicationId = $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertCreated()->json('data.id');

        // 2. Perusahaan menerima lamaran (review perusahaan).
        Sanctum::actingAs($companyUser);

        $this->putJson("/api/pkl-applications/{$applicationId}", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        // Daftar lamaran menyertakan identitas pelamar (dipakai UI review).
        $this->getJson('/api/pkl-applications')
            ->assertOk()
            ->assertJsonPath('data.0.student.name', $student->name);

        // 3. Siswa memilih tawaran final → penempatan resmi dibuat otomatis.
        Sanctum::actingAs($student);

        $placementId = $this->postJson("/api/pkl-applications/{$applicationId}/choose")
            ->assertOk()
            ->json('placement.id');

        $placement = PklPlacement::findOrFail($placementId);

        $this->assertSame($company->id, (int) $placement->company_id);
        $this->assertNull($placement->teacher_id);
        $this->assertNull($placement->supervisor_id);

        // 4. Admin menetapkan guru pembimbing.
        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placementId}", [
            'teacher_id' => $teacher->id,
        ])->assertOk()->assertJsonPath('data.teacher_id', $teacher->id);

        // 5. Perusahaan menetapkan pembimbing industri (endpoint khusus).
        Sanctum::actingAs($companyUser);

        $this->patchJson("/api/pkl-placements/{$placementId}/supervisor", [
            'supervisor_id' => $supervisor->id,
        ])->assertOk()->assertJsonPath('data.supervisor_id', $supervisor->id);

        // 6. Siswa mengisi jurnal dan mengirimkannya.
        Sanctum::actingAs($student);

        $journalId = $this->postJson('/api/journals', [
            'placement_id' => $placementId,
            'journal_date' => now()->toDateString(),
            'activity' => 'Membuat halaman login aplikasi dan menguji validasi form.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/journals/{$journalId}/submit")->assertOk();

        // Siswa juga dapat mencatat absensi dalam rentang penempatan.
        $this->postJson('/api/attendances', [
            'placement_id' => $placementId,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'check_in' => '08:00',
            'check_out' => '16:00',
        ])->assertCreated();

        // 7. Pembimbing industri memverifikasi jurnal.
        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/journals/{$journalId}")->assertOk();

        $this->postJson("/api/journals/{$journalId}/verify", [
            'action' => 'verified',
            'company_note' => 'Jurnal jelas dan sesuai aktivitas.',
        ])->assertOk()->assertJsonPath('data.status', 'verified');

        // 8. Guru pembimbing melihat penempatan & jurnal siswa bimbingannya.
        Sanctum::actingAs($teacher);

        $this->getJson("/api/pkl-placements/{$placementId}")->assertOk();
        $this->getJson("/api/pkl-placements")
            ->assertOk()
            ->assertJsonPath('data.0.id', $placementId);
        $this->getJson("/api/journals/{$journalId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        // 9. Penilaian industri (pembimbing) dan guru.
        $components = $this->createDefaultComponents();

        Sanctum::actingAs($supervisorUser);
        $this->postJson('/api/assessments', [
            'placement_id' => $placementId,
            'component_id' => $components['industri']->id,
            'score' => 80,
        ])->assertCreated();

        Sanctum::actingAs($teacher);
        $this->postJson('/api/assessments', [
            'placement_id' => $placementId,
            'component_id' => $components['guru']->id,
            'score' => 90,
        ])->assertCreated();

        // 10. Guru menghitung nilai akhir (bobot 70/30 → 83).
        $finalId = $this->postJson('/api/final-assessments/calculate', [
            'placement_id' => $placementId,
        ])->assertOk()->json('data.id');

        $this->assertSame(
            83.0,
            (float) $this->getJson("/api/final-assessments/{$finalId}")
                ->assertOk()
                ->json('data.final_score')
        );

        // 11. Admin menandai PKL selesai lalu mempublikasikan nilai.
        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placementId}", ['status' => 'completed'])
            ->assertOk();

        $this->postJson("/api/final-assessments/{$finalId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // 12. Siswa melihat nilai akhir yang sudah dipublikasikan.
        Sanctum::actingAs($student);

        $this->getJson('/api/final-assessments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $finalId)
            ->assertJsonPath('data.0.status', 'published');
    }

    public function test_student_cannot_access_journal_or_attendance_outside_placement_period(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_dates');
        $placement = $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/journals', [
            'placement_id' => $placement->id,
            'journal_date' => now()->addMonths(4)->toDateString(),
            'activity' => 'Di luar periode PKL.',
        ])->assertUnprocessable();

        $this->postJson('/api/attendances', [
            'placement_id' => $placement->id,
            'attendance_date' => now()->addMonths(4)->toDateString(),
            'status' => 'present',
        ])->assertUnprocessable();
    }
}

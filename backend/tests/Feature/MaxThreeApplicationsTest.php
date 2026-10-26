<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Aturan maksimal 3 lamaran aktif per periode PKL.
 *
 * Menggantikan aturan lama yang memblokir lamaran baru selama siswa masih
 * memiliki satu lamaran apa pun yang belum ditolak: kini lamaran berstatus
 * Diajukan, Dalam Seleksi, Interview, atau Diterima tidak menghalangi
 * pengajuan berikutnya selama slot (maksimal 3) masih tersedia; lamaran
 * Ditolak tidak dihitung dan riwayatnya tetap tersimpan. Status Diterima
 * adalah tawaran, bukan penempatan — penempatan final hanya lahir dari
 * Pilih Final (choose) dan setelah itu lamaran baru ditolak.
 */
class MaxThreeApplicationsTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    private function applyAs($student, $company, $period, int $order)
    {
        return $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => $order,
        ]);
    }

    public function test_student_can_apply_to_three_companies_in_same_period(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();

        Sanctum::actingAs($student);

        for ($i = 1; $i <= 3; $i++) {
            $company = $this->createCompany("PT Pilihan {$i}");
            $this->applyAs($student, $company, $period, $i)->assertCreated();
        }

        $this->assertSame(
            3,
            \App\Models\PklApplication::query()
                ->where('student_id', $student->id)
                ->where('pkl_period_id', $period->id)
                ->count()
        );
    }

    public function test_fourth_application_is_rejected_when_slots_are_full(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();

        for ($i = 1; $i <= 3; $i++) {
            $company = $this->createCompany("PT Pilihan {$i}");
            $this->makeApplicationFor($student, $company, $period, $i);
        }

        $fourthCompany = $this->createCompany('PT Keempat');

        Sanctum::actingAs($student);

        // choice_order 1 sengaja bertabrakan: pemeriksaan batas 3 harus
        // berjalan lebih dulu sehingga pesannya adalah pesan batas, bukan
        // pesan urutan pilihan.
        $this->applyAs($student, $fourthCompany, $period, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Maksimal 3 pilihan perusahaan untuk satu periode PKL.');

        $this->assertSame(
            3,
            \App\Models\PklApplication::query()
                ->where('student_id', $student->id)
                ->where('pkl_period_id', $period->id)
                ->count()
        );
    }

    public function test_active_statuses_do_not_block_next_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $companyC = $this->createCompany('PT C');
        $companyD = $this->createCompany('PT D');

        Sanctum::actingAs($student);

        // Diajukan tidak menghalangi lamaran berikutnya.
        $this->applyAs($student, $companyA, $period, 1)->assertCreated();
        $appA = \App\Models\PklApplication::query()
            ->where('student_id', $student->id)
            ->where('company_id', $companyA->id)
            ->firstOrFail();
        $appA->update(['status' => 'seleksi']);

        // Dalam Seleksi tidak menghalangi lamaran berikutnya.
        $this->applyAs($student, $companyB, $period, 2)->assertCreated();
        $appB = \App\Models\PklApplication::query()
            ->where('student_id', $student->id)
            ->where('company_id', $companyB->id)
            ->firstOrFail();
        $appB->update(['status' => 'interview']);

        // Interview tidak menghalangi lamaran berikutnya.
        $this->applyAs($student, $companyC, $period, 3)->assertCreated();
        $appC = \App\Models\PklApplication::query()
            ->where('student_id', $student->id)
            ->where('company_id', $companyC->id)
            ->firstOrFail();
        $appC->update(['status' => 'accepted']);

        // Diterima pun dihitung sebagai slot aktif: slot sudah penuh (3),
        // sehingga lamaran keempat ditolak — bukan karena statusnya.
        $this->applyAs($student, $companyD, $period, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Maksimal 3 pilihan perusahaan untuk satu periode PKL.');
    }

    public function test_rejected_applications_free_slots_and_history_is_kept(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $this->makeApplicationFor($student, $companyA, $period, 1)
            ->update(['status' => 'rejected']);
        $this->makeApplicationFor($student, $companyB, $period, 2)
            ->update(['status' => 'rejected']);

        Sanctum::actingAs($student);

        // Slot nomor pilihan yang ditolak boleh dipakai lagi.
        $this->applyAs($student, $this->createCompany('PT C'), $period, 1)->assertCreated();
        $this->applyAs($student, $this->createCompany('PT D'), $period, 2)->assertCreated();
        $this->applyAs($student, $this->createCompany('PT E'), $period, 3)->assertCreated();
        $this->applyAs($student, $this->createCompany('PT F'), $period, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Maksimal 3 pilihan perusahaan untuk satu periode PKL.');

        // Riwayat lamaran yang ditolak tetap tersimpan, tidak dihapus.
        $this->assertDatabaseHas('pkl_applications', [
            'student_id' => $student->id,
            'company_id' => $companyA->id,
            'status' => 'rejected',
            'choice_order' => 1,
        ]);
        $this->assertDatabaseHas('pkl_applications', [
            'student_id' => $student->id,
            'company_id' => $companyB->id,
            'status' => 'rejected',
            'choice_order' => 2,
        ]);
    }

    public function test_duplicate_active_application_to_same_company_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');

        Sanctum::actingAs($student);

        $this->applyAs($student, $company, $period, 1)->assertCreated();
        $this->applyAs($student, $company, $period, 2)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Perusahaan tersebut sudah dipilih.');

        // Perusahaan yang sama tetap tidak bisa dipilih ulang meski
        // lamaran sebelumnya sudah ditolak (unique student-company-period).
        $app = \App\Models\PklApplication::query()
            ->where('student_id', $student->id)
            ->where('company_id', $company->id)
            ->firstOrFail();
        $app->update(['status' => 'rejected']);

        $this->applyAs($student, $company, $period, 2)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Perusahaan tersebut sudah dipilih.');

        $this->assertSame(
            1,
            \App\Models\PklApplication::query()
                ->where('student_id', $student->id)
                ->where('company_id', $company->id)
                ->count()
        );
    }

    public function test_student_can_receive_multiple_offers_via_company_review(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        Sanctum::actingAs($student);
        $this->applyAs($student, $companyA, $period, 1)->assertCreated();
        $this->applyAs($student, $companyB, $period, 2)->assertCreated();

        $appA = \App\Models\PklApplication::query()
            ->where('student_id', $student->id)
            ->where('company_id', $companyA->id)
            ->firstOrFail();
        $appB = \App\Models\PklApplication::query()
            ->where('student_id', $student->id)
            ->where('company_id', $companyB->id)
            ->firstOrFail();

        $companyUserA = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUserA, $companyA);
        $companyUserB = $this->createUser('company', 'company_b');
        $this->attachCompanySupervisor($companyUserB, $companyB);

        Sanctum::actingAs($companyUserA);
        $this->putJson("/api/pkl-applications/{$appA->id}", ['status' => 'accepted'])->assertOk();

        Sanctum::actingAs($companyUserB);
        $this->putJson("/api/pkl-applications/{$appB->id}", ['status' => 'accepted'])->assertOk();

        $this->assertSame('accepted', $appA->fresh()->status);
        $this->assertSame('accepted', $appB->fresh()->status);
    }

    public function test_review_accept_respects_company_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT Kuota');
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $appA = $this->makeApplicationFor($studentA, $company, $period, 1);
        $appB = $this->makeApplicationFor($studentB, $company, $period, 1);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);
        $this->putJson("/api/pkl-applications/{$appA->id}", ['status' => 'accepted'])->assertOk();

        // Slot kuota sudah terpakai tawaran pertama.
        $this->putJson("/api/pkl-applications/{$appB->id}", ['status' => 'accepted'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kuota siswa untuk perusahaan pada periode PKL ini sudah penuh.');
        $this->assertSame('pending', $appB->fresh()->status);
    }

    public function test_choose_requires_accepted_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');
        $application = $this->makeApplicationFor($student, $company, $period, 1);

        Sanctum::actingAs($student);

        $this->postJson("/api/pkl-applications/{$application->id}/choose")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya pengajuan yang sudah diterima perusahaan yang dapat dipilih sebagai tujuan PKL.');
    }

    public function test_choose_creates_one_placement_and_closes_other_offers(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $companyC = $this->createCompany('PT C');

        $appA = $this->makeApplicationFor($student, $companyA, $period, 1);
        $appA->update(['status' => 'accepted']);
        $appB = $this->makeApplicationFor($student, $companyB, $period, 2);
        $appB->update(['status' => 'accepted']);
        $appC = $this->makeApplicationFor($student, $companyC, $period, 3);
        $appC->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $this->postJson("/api/pkl-applications/{$appB->id}/choose")
            ->assertOk()
            ->assertJsonPath('closed_offers', 2);

        $this->assertDatabaseHas('pkl_applications', ['id' => $appB->id, 'status' => 'approved']);
        $this->assertDatabaseHas('pkl_applications', ['id' => $appA->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('pkl_applications', ['id' => $appC->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('pkl_placements', [
            'student_id' => $student->id,
            'company_id' => $companyB->id,
            'pkl_period_id' => $period->id,
            'application_id' => $appB->id,
        ]);
        $this->assertSame(
            1,
            \App\Models\PklPlacement::query()
                ->where('student_id', $student->id)
                ->where('pkl_period_id', $period->id)
                ->count()
        );
    }

    public function test_failed_choose_leaves_no_partial_changes(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $period = $this->createPeriod();
        $companyX = $this->createCompany('PT X');
        $companyX->update(['student_quota' => 1]);
        $companyY = $this->createCompany('PT Y');

        // Kuota PT X sudah terpakai penempatan siswa lain.
        $this->createPlacement($studentB, $companyX, $period);

        $appX = $this->makeApplicationFor($studentA, $companyX, $period, 1);
        $appX->update(['status' => 'accepted']);
        $appY = $this->makeApplicationFor($studentA, $companyY, $period, 2);
        $appY->update(['status' => 'accepted']);

        Sanctum::actingAs($studentA);

        $this->postJson("/api/pkl-applications/{$appX->id}/choose")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kuota siswa untuk perusahaan pada periode PKL ini sudah penuh.');

        // Tidak ada penempatan; kedua tawaran tetap accepted (tidak ada
        // yang tertutup sebagian dan tidak ada status yang berubah).
        $this->assertDatabaseMissing('pkl_placements', [
            'student_id' => $studentA->id,
            'pkl_period_id' => $period->id,
        ]);
        $this->assertSame('accepted', $appX->fresh()->status);
        $this->assertSame('accepted', $appY->fresh()->status);
    }

    public function test_second_choose_and_new_application_after_placement_are_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $appA = $this->makeApplicationFor($student, $companyA, $period, 1);
        $appA->update(['status' => 'accepted']);
        $appB = $this->makeApplicationFor($student, $companyB, $period, 2);
        $appB->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        $this->postJson("/api/pkl-applications/{$appA->id}/choose")->assertOk();

        // Tawaran lain sudah ditutup; tawaran terpilih sudah approved.
        $this->postJson("/api/pkl-applications/{$appB->id}/choose")->assertStatus(422);
        $this->postJson("/api/pkl-applications/{$appA->id}/choose")->assertStatus(422);

        // Lamaran baru setelah penempatan final ditolak.
        $this->applyAs($student, $this->createCompany('PT C'), $period, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Siswa sudah memiliki penempatan final pada periode ini, sehingga tidak dapat mengajukan lamaran baru.');

        $this->assertSame(
            1,
            \App\Models\PklPlacement::query()
                ->where('student_id', $student->id)
                ->where('pkl_period_id', $period->id)
                ->count()
        );
    }

    public function test_application_after_final_placement_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');
        $this->createPlacement($student, $company, $period);

        Sanctum::actingAs($student);

        $this->applyAs($student, $this->createCompany('PT B'), $period, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Siswa sudah memiliki penempatan final pada periode ini, sehingga tidak dapat mengajukan lamaran baru.');
    }

    public function test_cancelled_placement_does_not_block_new_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');
        $placement = $this->createPlacement($student, $company, $period);
        $placement->update(['status' => 'cancelled']);

        Sanctum::actingAs($student);

        $this->applyAs($student, $this->createCompany('PT B'), $period, 1)->assertCreated();
    }

    public function test_application_access_rules(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');

        // Siswa tidak boleh mengajukan untuk siswa lain.
        Sanctum::actingAs($studentA);
        $this->postJson('/api/pkl-applications', [
            'student_id' => $studentB->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertForbidden();

        // Perusahaan tidak boleh membuat lamaran (policy create: student).
        $companyUser = $this->createUser('company', 'company_a');
        Sanctum::actingAs($companyUser);
        $this->postJson('/api/pkl-applications', [
            'student_id' => $studentA->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertForbidden();
    }
}

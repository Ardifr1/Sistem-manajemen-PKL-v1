<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PklApplication;
use App\Models\PklPlacement;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Regression test untuk perbaikan keamanan: pembatasan laju, status akun,
 * pencabutan token, rollback kredensial, dan pencegahan IDOR lintas peran.
 *
 * Semua pengiriman WhatsApp di-mock di level HTTP (Http::fake) dan email
 * memakai Mail::fake — tidak ada pesan sungguhan yang terkirim.
 */
class SecurityHardeningTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->createUser('admin', 'admin_sec');
    }

    private function fakeFonnteSuccess(): void
    {
        Http::fake(fn () => Http::response([
            'detail' => 'success! message in queue',
            'status' => true,
            'target' => ['6281234567890'],
        ], 200));
    }

    private function fakeFonnteRejected(): void
    {
        Http::fake(fn () => Http::response([
            'status' => false,
            'reason' => 'device disconnected',
        ], 200));
    }

    private function tempPasswordFromWhatsApp(): ?string
    {
        $passwords = [];

        foreach (Http::recorded() as [$request]) {
            $message = (string) ($request['message'] ?? '');
            if (preg_match('/Password sementara: (\S+)/', $message, $m)) {
                $passwords[] = $m[1];
            }
        }

        return $passwords === [] ? null : end($passwords);
    }

    private function targetWithTemporaryPassword(): User
    {
        $target = $this->createUser('student', 'student_sec');
        $target->update([
            'email' => 'kredensial@smk.sch.id',
            'phone' => '081234567890',
            'password' => Hash::make('TempAsli123'),
            'must_change_password' => true,
        ]);

        return $target->fresh();
    }

    // ================= KIRIM ULANG KREDENSIAL =================

    public function test_resend_rolls_back_when_all_channels_fail(): void
    {
        Mail::fake();
        // Mailer array = email tidak dikonfigurasi (tidak terkirim).
        config(['mail.default' => 'array']);
        config(['services.fonnte.token' => 'test-fonnte-token']);
        $this->fakeFonnteRejected();

        $target = $this->targetWithTemporaryPassword();
        $previousHash = $target->password;

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/users/{$target->id}/resend-account")
            ->assertStatus(422)
            ->assertJsonPath('data.delivery.email.status', 'not_configured')
            ->assertJsonPath('data.delivery.whatsapp.status', 'failed');

        // Akun TIDAK boleh tertinggal memakai password baru yang tidak diketahui.
        $fresh = $target->fresh();
        $this->assertSame($previousHash, $fresh->password);
        $this->assertTrue(Hash::check('TempAsli123', $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.credentials_resend_failed']);
    }

    public function test_resend_with_one_successful_channel_issues_valid_credentials_and_revokes_tokens(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        config(['services.fonnte.token' => 'test-fonnte-token']);
        $this->fakeFonnteSuccess();

        $target = $this->targetWithTemporaryPassword();
        $oldToken = $target->createToken('perangkat-lama');
        $oldTokenId = $oldToken->accessToken->id;

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/users/{$target->id}/resend-account")
            ->assertOk()
            ->assertJsonPath('data.delivery.whatsapp.status', 'sent');

        $newPassword = $this->tempPasswordFromWhatsApp();
        $this->assertNotEmpty($newPassword);

        $fresh = $target->fresh();

        // Password baru sesuai hash yang berlaku; password lama tidak berlaku.
        $this->assertTrue(Hash::check($newPassword, $fresh->password));
        $this->assertFalse(Hash::check('TempAsli123', $fresh->password));
        $this->assertStringStartsWith('$2y$', $fresh->password);

        // Sesi lama dicabut saat password baru diterbitkan.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);

        // Password tidak pernah tersimpan sebagai teks biasa / di audit log.
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString(
                $newPassword,
                (string) json_encode($log->metadata).(string) $log->reason
            );
        }

        // Log lama tidak berlaku, password baru bisa dipakai login.
        $this->postJson('/api/login', [
            'username' => 'kredensial@smk.sch.id',
            'password' => 'TempAsli123',
        ])->assertStatus(401);

        $this->postJson('/api/login', [
            'username' => 'kredensial@smk.sch.id',
            'password' => $newPassword,
        ])->assertOk();
    }

    // ================= GANTI PASSWORD =================

    public function test_change_password_requires_confirmation_and_revokes_other_sessions(): void
    {
        $user = $this->createUser('teacher', 'teacher_pw');
        $user->update(['must_change_password' => true]);

        $otherTokenId = $user->createToken('perangkat-lain')->accessToken->id;

        Sanctum::actingAs($user);

        // Tanpa konfirmasi password → ditolak.
        $this->postJson('/api/change-password', [
            'current_password' => 'password123',
            'password' => 'PasswordBaru123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Password baru tidak boleh sama dengan password saat ini.
        $this->postJson('/api/change-password', [
            'current_password' => 'password123',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);

        // Password saat ini salah → ditolak.
        $this->postJson('/api/change-password', [
            'current_password' => 'password-salah',
            'password' => 'PasswordBaru123',
            'password_confirmation' => 'PasswordBaru123',
        ])->assertStatus(422);

        $this->postJson('/api/change-password', [
            'current_password' => 'password123',
            'password' => 'PasswordBaru123',
            'password_confirmation' => 'PasswordBaru123',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->must_change_password);
        $this->assertTrue(Hash::check('PasswordBaru123', $fresh->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_changed']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherTokenId]);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->createUser('student', 'student_brute');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'username' => 'student_brute',
                'password' => 'password-salah',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'username' => 'student_brute',
            'password' => 'password-salah',
        ])->assertStatus(429);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['username' => 'tidak-ada'])
                ->assertOk();
        }

        $this->postJson('/api/forgot-password', ['username' => 'tidak-ada'])
            ->assertStatus(429);
    }

    // ================= STATUS AKUN =================

    public function test_deactivated_account_is_blocked_and_tokens_are_revoked(): void
    {
        $admin = $this->admin();
        $user = $this->createUser('teacher', 'teacher_inactive');
        $tokenId = $user->createToken('sesi-lama')->accessToken->id;

        // Penonaktifan oleh admin mencabut token yang sudah terbit.
        Sanctum::actingAs($admin);
        $this->putJson("/api/users/{$user->id}", ['is_active' => false])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // Toko token lama tidak bisa dipakai lagi.
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/pkl-applications')->assertForbidden();
    }

    public function test_non_admin_cannot_set_password_through_user_endpoint(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_self');

        Sanctum::actingAs($teacher);

        $this->putJson("/api/users/{$teacher->id}", [
            'password' => 'AmbilAlih123',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('password123', $teacher->fresh()->password));
    }

    public function test_admin_set_password_forces_change_and_revokes_sessions(): void
    {
        $admin = $this->admin();
        $target = $this->createUser('student', 'student_admin_reset');
        $target->update(['must_change_password' => false]);
        $tokenId = $target->createToken('sesi-siswa')->accessToken->id;

        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$target->id}", [
            'password' => 'ResetOlehAdmin123',
        ])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('ResetOlehAdmin123', $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.updated']);
    }

    // ================= IDOR / PEMISAHAN PERAN =================

    public function test_teacher_cannot_read_applications_of_student_outside_their_supervision(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_scope');
        $studentA = $this->createUser('student', 'student_scope_a');
        $studentB = $this->createUser('student', 'student_scope_b');

        $company = $this->createCompany();
        $period = $this->createPeriod();

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $this->createPlacement($studentA, $company, $period, $applicationA, $teacher);
        $this->makeApplicationFor($studentB, $company, $period);

        Sanctum::actingAs($teacher);

        // Hanya siswa bimbingannya yang tampil pada daftar.
        $response = $this->getJson('/api/pkl-applications')->assertOk();
        $this->assertSame(
            [$applicationA->id],
            collect($response->json('data'))->pluck('id')->all()
        );

        // Filter student_id tidak boleh memperluas cakupan akses.
        $this->getJson('/api/pkl-applications?student_id='.$studentB->id)
            ->assertForbidden();
    }

    public function test_company_only_lists_applications_for_its_own_company(): void
    {
        $companyA = $this->createCompany('PT Alfa');
        $companyB = $this->createCompany('PT Beta');
        $period = $this->createPeriod();

        $studentA = $this->createUser('student', 'student_comp_a');
        $studentB = $this->createUser('student', 'student_comp_b');

        $applicationA = $this->makeApplicationFor($studentA, $companyA, $period);
        $this->makeApplicationFor($studentB, $companyB, $period);

        $companyUser = $this->createUser('company', 'company_scope');
        $this->attachCompanySupervisor($companyUser, $companyA);

        Sanctum::actingAs($companyUser);

        $response = $this->getJson('/api/pkl-applications')->assertOk();
        $this->assertSame(
            [$applicationA->id],
            collect($response->json('data'))->pluck('id')->all()
        );

        // Filter siswa dari perusahaan lain tidak membocorkan data.
        $this->getJson('/api/pkl-applications?student_id='.$studentB->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_supervisor_cannot_read_journals_of_placement_outside_their_assignment(): void
    {
        $company = $this->createCompany('PT Gamma');
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_journal');
        $application = $this->makeApplicationFor($student, $company, $period);
        $placement = $this->createPlacement($student, $company, $period, $application);

        $this->createJournal($placement);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_scope');
        $this->attachCompanySupervisor($supervisorUser, $company);

        Sanctum::actingAs($supervisorUser);

        // Bukan siswa yang ditugaskan kepadanya (placement.supervisor_id null).
        $this->getJson('/api/journals?placement_id='.$placement->id)->assertForbidden();

        // Setelah ditugaskan, akses diizinkan.
        $link = $supervisorUser->companySupervisor;
        $placement->update(['supervisor_id' => $link->id]);

        $this->getJson('/api/journals?placement_id='.$placement->id)->assertOk();
    }

    public function test_company_cannot_schedule_interview_for_another_companys_application(): void
    {
        $companyA = $this->createCompany('PT Delta');
        $companyB = $this->createCompany('PT Epsilon');
        $period = $this->createPeriod();

        $student = $this->createUser('student', 'student_interview');
        $applicationB = $this->makeApplicationFor($student, $companyB, $period);

        $companyUser = $this->createUser('company', 'company_interview');
        $this->attachCompanySupervisor($companyUser, $companyA);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/interviews', [
            'application_id' => $applicationB->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'mode' => 'online',
        ])->assertForbidden();

        // Status lamaran perusahaan lain tidak ikut berubah.
        $this->assertSame('pending', $applicationB->fresh()->status);
        $this->assertDatabaseCount('interviews', 0);
    }

    public function test_company_can_schedule_interview_for_its_own_application(): void
    {
        $company = $this->createCompany('PT Zeta');
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_interview_own');
        $application = $this->makeApplicationFor($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_interview_own');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->postJson('/api/interviews', [
            'application_id' => $application->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'mode' => 'online',
        ])->assertCreated();

        $this->assertSame('interview', $application->fresh()->status);
    }

    public function test_company_cannot_read_student_profile_outside_its_company(): void
    {
        $companyA = $this->createCompany('PT Eta');
        $companyB = $this->createCompany('PT Theta');
        $period = $this->createPeriod();

        $studentA = $this->createUser('student', 'student_profile_a');
        $studentB = $this->createUser('student', 'student_profile_b');

        $applicationA = $this->makeApplicationFor($studentA, $companyA, $period);
        $this->createPlacement($studentA, $companyA, $period, $applicationA);

        $applicationB = $this->makeApplicationFor($studentB, $companyB, $period);
        $this->createPlacement($studentB, $companyB, $period, $applicationB);

        $profileA = StudentProfile::create([
            'user_id' => $studentA->id,
            'student_number' => '2026-1001',
            'class' => 'XII RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
            'address' => 'Jl. Rahasia A',
        ]);

        $profileB = StudentProfile::create([
            'user_id' => $studentB->id,
            'student_number' => '2026-1002',
            'class' => 'XII RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
            'address' => 'Jl. Rahasia B',
        ]);

        $companyUser = $this->createUser('company', 'company_profile');
        $this->attachCompanySupervisor($companyUser, $companyA);

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/student-profiles/{$profileB->id}")->assertForbidden();
        $this->getJson("/api/student-profiles/{$profileA->id}")->assertOk();
    }

    public function test_teacher_document_list_works_and_is_scoped_by_assignment(): void
    {
        $company = $this->createCompany('PT Iota');
        $period = $this->createPeriod();

        $teacher = $this->createUser('teacher', 'teacher_docs');
        $studentA = $this->createUser('student', 'student_doc_a');
        $studentB = $this->createUser('student', 'student_doc_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $this->createPlacement($studentA, $company, $period, $applicationA, $teacher);

        $applicationB = $this->makeApplicationFor($studentB, $company, $period);

        $documentA = $this->createDocument($applicationA);
        $this->createDocument($applicationB);

        Sanctum::actingAs($teacher);

        // Sebelumnya query memakai kolom users.teacher_id yang tidak ada (500).
        $response = $this->getJson('/api/application-documents')->assertOk();

        $this->assertSame(
            [$documentA->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    // ================= PENEMPATAN OTOMATIS (REGRESI) =================

    public function test_choosing_offer_creates_placement_without_admin_approval(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT Kappa');
        $student = $this->createUser('student', 'student_auto_place');
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        Sanctum::actingAs($student);

        // Tidak ada langkah persetujuan admin: siswa memilih → penempatan dibuat.
        $this->postJson("/api/pkl-applications/{$application->id}/choose")
            ->assertOk()
            ->assertJsonPath('placement.status', 'active');

        $placement = PklPlacement::where('student_id', $student->id)->first();
        $this->assertNotNull($placement);
        $this->assertSame($company->id, (int) $placement->company_id);
        $this->assertSame('approved', PklApplication::find($application->id)->status);

        // Klik ulang tidak membuat penempatan ganda.
        $this->postJson("/api/pkl-applications/{$application->id}/choose")->assertStatus(422);
        $this->assertSame(1, PklPlacement::where('student_id', $student->id)->count());
    }
}

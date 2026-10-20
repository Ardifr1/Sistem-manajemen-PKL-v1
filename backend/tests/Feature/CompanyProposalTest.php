<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * PRD bagian D — perusahaan nonmitra.
 *
 * Siswa mengajukan perusahaan lewat halaman pemilihan yang sudah ada;
 * pengajuan berstatus pending_school_verification; Admin Sekolah menyetujui
 * (perusahaan menjadi mitra dan diproses via alur pengajuan yang ada) atau
 * menolak dengan alasan.
 */
class CompanyProposalTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'PT Usaha Baru',
            'industry' => 'Manufaktur',
            'address' => 'Jl. Industri No. 5, Bandung',
            'contact_person' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'hrd@usahabaru.test',
            'description' => 'Perusahaan manufaktur yang belum menjadi mitra.',
        ], $overrides);
    }

    public function test_student_can_submit_non_partner_company_proposal(): void
    {
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/company-proposals', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('companies', [
            'name' => 'PT Usaha Baru',
            'verification_status' => Company::VERIFICATION_PENDING,
            'is_partner' => false,
            'is_active' => false,
            'student_quota' => 0,
            'submitted_by' => $student->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company_proposal.submitted',
            'user_id' => $student->id,
        ]);
    }

    /**
     * Manipulasi request API tidak boleh menaikkan status perusahaan.
     */
    public function test_student_cannot_manipulate_verification_fields(): void
    {
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/company-proposals', $this->payload([
            'is_partner' => true,
            'is_active' => true,
            'student_quota' => 99,
            'verification_status' => Company::VERIFICATION_VERIFIED,
            'verified_by' => 1,
        ]))->assertCreated();

        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        $this->assertFalse((bool) $company->is_partner);
        $this->assertFalse((bool) $company->is_active);
        $this->assertSame(0, (int) $company->student_quota);
        $this->assertSame(Company::VERIFICATION_PENDING, $company->verification_status);
        $this->assertNull($company->verified_by);
    }

    public function test_duplicate_company_name_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');
        $this->createCompany('PT Usaha Baru');

        Sanctum::actingAs($student);

        $this->postJson('/api/company-proposals', $this->payload())->assertStatus(422);
    }

    public function test_duplicate_pending_proposal_by_same_student_is_rejected(): void
    {
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $this->postJson('/api/company-proposals', $this->payload())->assertStatus(422);

        $this->assertSame(1, Company::query()->where('name', 'PT Usaha Baru')->count());
    }

    public function test_non_student_cannot_submit_proposal(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->postJson('/api/company-proposals', $this->payload())->assertForbidden();
    }

    public function test_admin_can_approve_proposal_and_company_becomes_verified_not_partner(): void
    {
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
            'reason' => 'Dokumen lengkap dan perusahaan valid.',
        ])->assertOk();

        $company->refresh();

        // Terverifikasi (boleh dipakai PKL), tetapi BUKAN mitra resmi.
        $this->assertSame(Company::VERIFICATION_VERIFIED, $company->verification_status);
        $this->assertFalse((bool) $company->is_partner);
        $this->assertTrue((bool) $company->is_active);
        $this->assertSame($admin->id, $company->verified_by);
        $this->assertTrue($company->isUsableForPlacement());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company_proposal.approved',
            'old_status' => Company::VERIFICATION_PENDING,
            'new_status' => Company::VERIFICATION_VERIFIED,
        ]);
    }

    public function test_rejection_requires_reason(): void
    {
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'reject',
        ])->assertStatus(422);

        $this->assertSame(
            Company::VERIFICATION_PENDING,
            $company->fresh()->verification_status
        );

        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'reject',
            'reason' => 'Alamat perusahaan tidak dapat diverifikasi.',
        ])->assertOk();

        $company->refresh();
        $this->assertSame(Company::VERIFICATION_REJECTED, $company->verification_status);
        $this->assertFalse((bool) $company->is_partner);
    }

    public function test_non_admin_cannot_verify_proposal(): void
    {
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
        ])->assertForbidden();
    }

    public function test_proposal_can_only_be_verified_once(): void
    {
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
        ])->assertOk();

        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'reject',
            'reason' => 'Batal karena alasan lain.',
        ])->assertStatus(422);
    }

    /**
     * Perusahaan yang belum diverifikasi tidak boleh dipakai untuk pengajuan
     * maupun penempatan resmi.
     */
    public function test_unverified_company_cannot_be_used_for_application(): void
    {
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertStatus(422);
    }

    public function test_unverified_company_cannot_be_used_for_placement(): void
    {
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod();

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('pkl_placements', [
            'company_id' => $company->id,
        ]);
    }

    /**
     * Setelah disetujui, perusahaan diproses lewat alur pengajuan yang sudah
     * ada — tidak ada alur paralel.
     */
    public function test_approved_company_flows_through_existing_application_api(): void
    {
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod();

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
        ])->assertOk();

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertCreated();

        $this->assertDatabaseHas('pkl_applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);
    }

    public function test_optional_proof_is_stored(): void
    {
        Storage::fake('local');

        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/company-proposals', $this->payload([
            'proof' => UploadedFile::fake()->create('proposal.pdf', 200, 'application/pdf'),
        ]))->assertCreated();

        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        $this->assertNotNull($company->proof_path);
        Storage::disk('local')->assertExists($company->proof_path);
    }

    public function test_student_only_sees_own_proposals_and_admin_sees_all(): void
    {
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($studentA);
        $this->postJson('/api/company-proposals', $this->payload(['name' => 'PT A']))->assertCreated();

        Sanctum::actingAs($studentB);
        $this->postJson('/api/company-proposals', $this->payload([
            'name' => 'PT B',
            'email' => 'hrd@ptb.test',
        ]))->assertCreated();

        Sanctum::actingAs($studentA);
        $mine = $this->getJson('/api/company-proposals')->assertOk();
        $this->assertSame(['PT A'], collect($mine->json('data'))->pluck('name')->all());

        Sanctum::actingAs($admin);
        $all = $this->getJson('/api/company-proposals')->assertOk();
        $this->assertCount(2, $all->json('data'));

        $pending = $this->getJson('/api/company-proposals?verification_status=pending_school_verification')
            ->assertOk();
        $this->assertCount(2, $pending->json('data'));
    }

    public function test_approve_creates_company_account_with_access_and_sends_credentials(): void
    {
        Mail::fake();
        Http::fake(fn () => Http::response(['status' => true, 'detail' => 'success! message in queue'], 200));
        config(['services.fonnte.token' => 'test-fonnte-token']);

        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
        ])->assertOk();

        // Akun perusahaan dibuat otomatis.
        $user = User::where('email', 'hrd@usahabaru.test')->firstOrFail();
        $this->assertSame('company', $user->role);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertStringStartsWith('$2y$', $user->password);

        // Akun terhubung ke perusahaannya.
        $this->assertDatabaseHas('company_supervisors', [
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        // Akses (kapabilitas) default: penuh.
        $this->assertDatabaseHas('company_memberships', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'active',
        ]);
        $membership = \App\Models\CompanyMembership::where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(
            \App\Models\CompanyMembership::CAPABILITIES,
            $membership->capabilities
        );

        // Kredensial dikirim via WhatsApp berisi email + password sementara.
        $this->assertSame('sent', $response->json('delivery.whatsapp.status'));
        Http::assertSent(function ($request) {
            $message = (string) ($request['message'] ?? '');

            return str_contains($message, 'Email login: hrd@usahabaru.test')
                && str_contains($message, 'Password sementara: ');
        });

        $this->assertSame($user->id, $response->json('account.id'));
    }

    public function test_approve_with_custom_capabilities(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
            'capabilities' => ['review_applications'],
        ])->assertOk();

        $user = User::where('email', 'hrd@usahabaru.test')->firstOrFail();
        $membership = \App\Models\CompanyMembership::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['review_applications'], $membership->capabilities);
    }

    public function test_approve_rejected_when_company_email_already_registered(): void
    {
        Mail::fake();
        Http::fake();

        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');
        $this->createUser('company', 'existing_co');
        User::where('username', 'existing_co')->update(['email' => 'hrd@usahabaru.test']);

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'approve',
        ])->assertStatus(422);

        // Perusahaan tetap pending; tidak ada akun ganda.
        $this->assertSame(
            Company::VERIFICATION_PENDING,
            $company->fresh()->verification_status
        );
        $this->assertSame(1, User::where('email', 'hrd@usahabaru.test')->count());
    }

    public function test_reject_does_not_create_company_account(): void
    {
        Mail::fake();
        Http::fake();

        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($student);
        $this->postJson('/api/company-proposals', $this->payload())->assertCreated();
        $company = Company::query()->where('name', 'PT Usaha Baru')->firstOrFail();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/company-proposals/{$company->id}/verify", [
            'action' => 'reject',
            'reason' => 'Dokumen tidak lengkap.',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'hrd@usahabaru.test']);
    }
}

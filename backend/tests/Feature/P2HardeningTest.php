<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Company;
use App\Models\PasswordResetRequest;
use App\Models\PklApplication;
use App\Models\PklPeriod;
use App\Models\PklPlacement;
use App\Models\User;
use Database\Seeders\TestAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * P2 — regresi temuan audit keamanan:
 * jalur dokumen, CORS, masa berlaku permintaan reset password, akun demo,
 * dan scoping dokumen guru.
 */
class P2HardeningTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function createApplicationFor(User $student, Company $company, PklPeriod $period): PklApplication
    {
        return PklApplication::create([
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
            'status' => 'pending',
        ]);
    }

    private function createDocument(PklApplication $application, string $filePath): ApplicationDocument
    {
        return ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type' => 'ktp',
            'document_name' => 'KTP',
            'file_path' => $filePath,
            'status' => 'pending',
        ]);
    }

    // ===================== 1. DOKUMEN =====================

    public function test_document_download_rejects_non_http_url(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_doc');
        $application = $this->createApplicationFor($student, $company, $period);

        $document = $this->createDocument($application, 'javascript:alert(1)');

        Sanctum::actingAs($student);

        $this->getJson("/api/application-documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_document_download_rejects_path_traversal(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_doc');
        $application = $this->createApplicationFor($student, $company, $period);

        $traversal = $this->createDocument($application, 'application-documents/../../.env');
        $outside = $this->createDocument($application, 'private/secret.pdf');

        Sanctum::actingAs($student);

        $this->getJson("/api/application-documents/{$traversal->id}/download")->assertNotFound();
        $this->getJson("/api/application-documents/{$outside->id}/download")->assertNotFound();
    }

    public function test_document_store_rejects_unknown_type_and_non_http_url(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_doc');
        $application = $this->createApplicationFor($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/application-documents', [
            'application_id' => $application->id,
            'document_type' => 'bebas_apa_saja',
            'document_name' => 'Berkas',
            'file_url' => 'https://example.com/berkas.pdf',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_type');

        $this->postJson('/api/application-documents', [
            'application_id' => $application->id,
            'document_type' => 'ktp',
            'document_name' => 'Berkas',
            'file_url' => 'ftp://example.com/berkas.pdf',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_url');

        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_document_store_accepts_https_url(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_doc');
        $application = $this->createApplicationFor($student, $company, $period);

        Sanctum::actingAs($student);

        $this->postJson('/api/application-documents', [
            'application_id' => $application->id,
            'document_type' => 'ktp',
            'document_name' => 'Berkas',
            'file_url' => 'https://example.com/berkas.pdf',
        ])->assertCreated();
    }

    public function test_teacher_can_only_view_documents_of_supervised_student(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $teacher = $this->createUser('teacher', 'teacher_doc');
        $otherTeacher = $this->createUser('teacher', 'teacher_other');

        $ownStudent = $this->createUser('student', 'student_own');
        $otherStudent = $this->createUser('student', 'student_other');

        $this->createPlacement($ownStudent, $company, $period, null, $teacher);
        $this->createPlacement($otherStudent, $company, $period, null, $otherTeacher);

        $ownDocument = $this->createDocument(
            $this->createApplicationFor($ownStudent, $company, $period),
            'application-documents/own.pdf'
        );
        $otherDocument = $this->createDocument(
            $this->createApplicationFor($otherStudent, $company, $period),
            'application-documents/other.pdf'
        );

        Sanctum::actingAs($teacher);

        $this->getJson("/api/application-documents/{$ownDocument->id}")->assertOk();
        $this->getJson("/api/application-documents/{$otherDocument->id}")->assertForbidden();
    }

    public function test_supervisor_document_list_only_covers_assigned_students(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();

        $assignedStudent = $this->createUser('student', 'student_a');
        $otherStudent = $this->createUser('student', 'student_b');

        $assignedPlacement = $this->createPlacement($assignedStudent, $company, $period);
        $this->createPlacement($otherStudent, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_doc');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $assignedPlacement->update(['supervisor_id' => $supervisor->id]);

        $assignedDocument = $this->createDocument(
            $this->createApplicationFor($assignedStudent, $company, $period),
            'application-documents/a.pdf'
        );
        $this->createDocument(
            $this->createApplicationFor($otherStudent, $company, $period, 2),
            'application-documents/b.pdf'
        );

        Sanctum::actingAs($supervisorUser);

        $response = $this->getJson('/api/application-documents')->assertOk();

        $this->assertSame(
            [$assignedDocument->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    // ===================== 2. CORS =====================

    public function test_cors_does_not_trust_wildcard_public_tunnel_domain(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.trycloudflare.com',
        ])->postJson('/api/login', [
            'login' => 'nobody',
            'password' => 'wrong-password',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_allows_localhost_development_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->postJson('/api/login', [
            'login' => 'nobody',
            'password' => 'wrong-password',
        ]);

        $this->assertSame(
            'http://localhost:5173',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    // ===================== 3. PERMINTAAN RESET PASSWORD =====================

    public function test_expired_password_reset_request_is_not_listed_and_cannot_be_resolved(): void
    {
        $admin = $this->createUser('admin', 'admin_reset');
        $student = $this->createUser('student', 'student_reset');

        $reset = PasswordResetRequest::create(['user_id' => $student->id]);
        $reset->forceFill([
            'created_at' => now()->subHours(
                (int) config('simagang.password_reset.expires_hours', 72) + 1
            ),
        ])->save();

        Sanctum::actingAs($admin);

        $this->getJson('/api/password-reset-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->patchJson("/api/password-reset-requests/{$reset->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('password_reset_requests', [
            'id' => $reset->id,
            'status' => 'expired',
        ]);
    }

    public function test_pending_password_reset_request_within_ttl_can_be_resolved(): void
    {
        $admin = $this->createUser('admin', 'admin_reset');
        $student = $this->createUser('student', 'student_reset');

        $reset = PasswordResetRequest::create(['user_id' => $student->id]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/password-reset-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/password-reset-requests/{$reset->id}")->assertOk();

        $this->assertDatabaseHas('password_reset_requests', [
            'id' => $reset->id,
            'status' => 'done',
        ]);
    }

    public function test_forgot_password_replaces_expired_request(): void
    {
        $student = $this->createUser('student', 'student_reset');

        $stale = PasswordResetRequest::create(['user_id' => $student->id]);
        $stale->forceFill([
            'created_at' => now()->subHours(
                (int) config('simagang.password_reset.expires_hours', 72) + 1
            ),
        ])->save();

        $this->postJson('/api/forgot-password', ['username' => 'student_reset'])
            ->assertOk();

        $this->assertDatabaseHas('password_reset_requests', [
            'id' => $stale->id,
            'status' => 'expired',
        ]);

        $this->assertSame(
            1,
            PasswordResetRequest::where('user_id', $student->id)
                ->where('status', 'pending')
                ->count()
        );
    }

    // ===================== 4. AKUN DEMO =====================

    public function test_demo_account_seeder_is_skipped_in_production(): void
    {
        $original = app()->environment();

        app()->detectEnvironment(fn () => 'production');

        try {
            // Dipanggil langsung (bukan lewat db:seed) agar tidak memicu
            // konfirmasi interaktif artisan untuk lingkungan production.
            (new TestAccountsSeeder())->run();
        } finally {
            app()->detectEnvironment(fn () => $original);
        }

        $this->assertDatabaseMissing('users', ['email' => 'admin@smk.sch.id']);
    }

    public function test_demo_account_seeder_runs_outside_production(): void
    {
        (new TestAccountsSeeder())->run();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@smk.sch.id',
            'role' => 'admin',
        ]);
    }
}

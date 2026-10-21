<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Kontrak payload pengajuan siswa (POST /api/pkl-applications):
 * pkl_period_id wajib ada, harus merujuk periode nyata, dan periode
 * harus aktif — agar regresi frontend (periode tidak terkirim) tertangkap.
 */
class StudentApplicationPayloadTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_store_requires_pkl_period_id(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT Mitra');
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'choice_order' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('pkl_period_id');

        $this->assertDatabaseCount('pkl_applications', 0);
    }

    public function test_store_rejects_unknown_period(): void
    {
        $this->createPeriod();
        $company = $this->createCompany('PT Mitra');
        $student = $this->createUser('student', 'student_a');

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => 999999,
            'choice_order' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('pkl_period_id');
    }

    public function test_store_accepts_active_period_payload(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT Mitra');
        $student = $this->createUser('student', 'student_a');

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
            'pkl_period_id' => $period->id,
            'status' => 'pending',
        ]);
    }
}

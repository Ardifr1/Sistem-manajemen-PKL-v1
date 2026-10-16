<?php

namespace Tests\Feature;

use App\Models\PklApplication;
use App\Models\PklPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * PRD bagian B — integritas penempatan.
 */
class PlacementIntegrityTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_placement_cannot_be_active_before_start_date(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('pkl_placements', [
            'student_id' => $student->id,
        ]);
    }

    public function test_placement_can_be_created_with_future_start_date_when_not_active_yet(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'completed',
        ])->assertCreated();
    }

    public function test_placement_cannot_be_activated_before_start_date(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        $placement = $this->createPlacement($student, $company, $period);
        $placement->update([
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'status' => 'active',
        ])->assertStatus(422);

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_placement_at_non_partner_company_is_rejected(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['is_partner' => false]);
        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertStatus(422);
    }

    public function test_placement_respects_company_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $admin = $this->createUser('admin', 'admin_a');

        $this->createPlacement($studentA, $company, $period);

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $studentB->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertStatus(422);

        $this->assertSame(1, PklPlacement::query()
            ->where('company_id', $company->id)
            ->count());
    }

    public function test_cancelled_placement_does_not_consume_quota(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $company->update(['student_quota' => 1]);

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $admin = $this->createUser('admin', 'admin_a');

        $first = $this->createPlacement($studentA, $company, $period);
        $first->update(['status' => 'cancelled']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $studentB->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertCreated();
    }

    /**
     * Bila siswa diterima beberapa perusahaan, penempatan resmi menutup
     * tawaran lain sehingga siswa hanya berada di satu perusahaan.
     */
    public function test_creating_placement_closes_other_accepted_offers(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $student = $this->createUser('student', 'student_a');
        $admin = $this->createUser('admin', 'admin_a');

        $applicationA = $this->makeApplicationFor($student, $companyA, $period, 1);
        $applicationA->update(['status' => 'accepted']);
        $applicationB = $this->makeApplicationFor($student, $companyB, $period, 2);
        $applicationB->update(['status' => 'accepted']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $companyA->id,
            'pkl_period_id' => $period->id,
            'application_id' => $applicationA->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertCreated();

        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationA->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('pkl_applications', [
            'id' => $applicationB->id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application_offers_closed',
        ]);

        // Tawaran yang ditutup membebaskan kuota perusahaan lain.
        $this->assertSame(0, PklApplication::query()
            ->where('company_id', $companyB->id)
            ->where('status', 'accepted')
            ->count());
    }

    public function test_placement_from_accepted_application_still_works(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $application = $this->makeApplicationFor($student, $company, $period);
        $application->update(['status' => 'accepted']);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'application_id' => $application->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])->assertCreated();

        $this->assertDatabaseHas('pkl_placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
        ]);
    }
}

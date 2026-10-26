<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PklPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class PklPeriodAuditTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function createExpiredActivePeriod(string $name = 'PKL Kedaluwarsa'): PklPeriod
    {
        $period = $this->createPeriod($name);

        $period->update([
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        return $period->fresh();
    }

    public function test_index_auto_closes_expired_period_and_writes_audit_log(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createExpiredActivePeriod();

        Sanctum::actingAs($admin);

        $this->getJson('/api/pkl-periods')->assertOk();

        $fresh = $period->fresh();

        $this->assertFalse((bool) $fresh->is_active);
        $this->assertNotNull($fresh->closed_at);

        $this->assertTrue(
            Carbon::parse($fresh->closed_at)->between(now()->subMinute(), now()->addMinute())
        );

        $log = AuditLog::query()
            ->where('action', 'pkl_period.auto_closed')
            ->where('subject_id', $period->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
        $this->assertSame(PklPeriod::class, $log->subject_type);
        $this->assertSame('active', $log->old_status);
        $this->assertSame('inactive', $log->new_status);
        $this->assertSame('auto_expiry', $log->metadata['trigger']);
        $this->assertSame($period->end_date->toDateString(), $log->metadata['end_date']);
    }

    public function test_auto_close_does_not_duplicate_audit_log_on_repeated_index_calls(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createExpiredActivePeriod();

        Sanctum::actingAs($admin);

        $this->getJson('/api/pkl-periods')->assertOk();
        $firstClosedAt = $period->fresh()->closed_at;

        $this->getJson('/api/pkl-periods')->assertOk();
        $this->getJson('/api/pkl-periods')->assertOk();

        $this->assertSame($firstClosedAt, $period->fresh()->closed_at);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'pkl_period.auto_closed')
                ->where('subject_id', $period->id)
                ->count()
        );
    }

    public function test_index_does_not_close_unexpired_period(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod();

        Sanctum::actingAs($admin);

        $this->getJson('/api/pkl-periods')->assertOk();

        $fresh = $period->fresh();

        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->closed_at);
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', 'pkl_period.auto_closed')
                ->where('subject_id', $period->id)
                ->count()
        );
    }

    public function test_manual_close_stores_closed_at_and_writes_audit_log(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod('PKL Manual');
        $closedAt = '2026-10-20 10:30:00';

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-periods/{$period->id}", [
            'is_active' => false,
            'closed_at' => $closedAt,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $fresh = $period->fresh();

        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame(
            $closedAt,
            Carbon::parse($fresh->closed_at)->toDateTimeString()
        );

        $log = AuditLog::query()
            ->where('action', 'pkl_period.closed')
            ->where('subject_id', $period->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('active', $log->old_status);
        $this->assertSame('inactive', $log->new_status);
        $this->assertSame('manual', $log->metadata['trigger']);
        $this->assertSame(
            Carbon::parse($closedAt)->toIso8601String(),
            $log->metadata['closed_at']
        );
    }

    public function test_manual_close_without_client_timestamp_uses_current_time(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod('PKL Manual');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-periods/{$period->id}", [
            'is_active' => false,
        ])->assertOk();

        $fresh = $period->fresh();

        $this->assertFalse((bool) $fresh->is_active);
        $this->assertNotNull($fresh->closed_at);
        $this->assertTrue(
            Carbon::parse($fresh->closed_at)->between(now()->subMinute(), now()->addMinute())
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pkl_period.closed',
            'subject_type' => PklPeriod::class,
            'subject_id' => $period->id,
            'old_status' => 'active',
            'new_status' => 'inactive',
        ]);
    }

    public function test_manual_close_of_draft_period_stores_closed_at_and_writes_audit_log(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod('PKL Draft');
        $period->update(['is_active' => false]);
        $closedAt = '2026-10-22 09:00:00';

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-periods/{$period->id}", [
            'is_active' => false,
            'closed_at' => $closedAt,
        ])->assertOk();

        $fresh = $period->fresh();

        $this->assertSame(
            $closedAt,
            Carbon::parse($fresh->closed_at)->toDateTimeString()
        );

        $log = AuditLog::query()
            ->where('action', 'pkl_period.closed')
            ->where('subject_id', $period->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('draft', $log->old_status);
        $this->assertSame('inactive', $log->new_status);
        $this->assertSame('manual', $log->metadata['trigger']);
    }

    public function test_repeated_manual_close_does_not_duplicate_audit_log(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $period = $this->createPeriod('PKL Manual');
        $firstClosedAt = '2026-10-20 10:30:00';

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-periods/{$period->id}", [
            'is_active' => false,
            'closed_at' => $firstClosedAt,
        ])->assertOk();

        $this->putJson("/api/pkl-periods/{$period->id}", [
            'is_active' => false,
            'closed_at' => '2026-10-21 11:45:00',
        ])->assertOk();

        $this->assertSame(
            $firstClosedAt,
            Carbon::parse($period->fresh()->closed_at)->toDateTimeString()
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'pkl_period.closed')
                ->where('subject_id', $period->id)
                ->count()
        );
    }

    public function test_expired_period_cannot_receive_new_application_after_auto_close(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createExpiredActivePeriod();

        Sanctum::actingAs($admin);
        $this->getJson('/api/pkl-periods')->assertOk();

        Sanctum::actingAs($student);

        $this->postJson('/api/pkl-applications', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Periode PKL tersebut tidak sedang aktif.');

        $this->assertDatabaseMissing('pkl_applications', [
            'student_id' => $student->id,
            'pkl_period_id' => $period->id,
        ]);
    }
}

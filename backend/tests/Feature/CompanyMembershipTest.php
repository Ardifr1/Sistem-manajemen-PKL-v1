<?php

namespace Tests\Feature;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * PRD bagian C — Company Membership.
 * Hubungan user<->perusahaan eksplisit, kapabilitas eksplisit, status
 * aktif/nonaktif, pemberian/pencabutan hanya oleh pihak berwenang, dan
 * setiap perubahan tercatat pada audit log.
 */
class CompanyMembershipTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_admin_can_grant_membership_with_capabilities(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $user = $this->createUser('company', 'company_a');
        $company = $this->createCompany();

        Sanctum::actingAs($admin);

        $this->postJson('/api/company-memberships', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'capabilities' => [
                CompanyMembership::CAPABILITY_COMPANY_ADMIN,
                CompanyMembership::CAPABILITY_REVIEW_APPLICATIONS,
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('company_memberships', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        $membership = CompanyMembership::query()->firstOrFail();
        $this->assertTrue($membership->hasCapability(CompanyMembership::CAPABILITY_COMPANY_ADMIN));
        $this->assertTrue($membership->hasCapability(CompanyMembership::CAPABILITY_REVIEW_APPLICATIONS));
        // Kapabilitas yang tidak diberikan tetap tertutup.
        $this->assertFalse($membership->hasCapability(CompanyMembership::CAPABILITY_VERIFY_JOURNALS));
    }

    public function test_granting_membership_is_audited(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $user = $this->createUser('company', 'company_a');
        $company = $this->createCompany();

        Sanctum::actingAs($admin);

        $this->postJson('/api/company-memberships', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'capabilities' => [CompanyMembership::CAPABILITY_REVIEW_APPLICATIONS],
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'company_membership.granted',
            'new_status' => 'active',
        ]);
    }

    public function test_revoking_membership_is_audited_and_disables_capabilities(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $user = $this->createUser('supervisor', 'supervisor_a');
        $company = $this->createCompany();
        $membership = $this->grantMembership(
            $user,
            $company,
            CompanyMembership::STATUS_ACTIVE,
            [CompanyMembership::CAPABILITY_VERIFY_JOURNALS]
        );

        Sanctum::actingAs($admin);

        $this->patchJson("/api/company-memberships/{$membership->id}", [
            'status' => CompanyMembership::STATUS_INACTIVE,
            'reason' => 'Pembimbing mengundurkan diri.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company_membership.revoked',
            'old_status' => 'active',
            'new_status' => 'inactive',
        ]);

        $fresh = $membership->fresh();
        $this->assertFalse($fresh->isActive());
        // Keanggotaan nonaktif tidak membawa kapabilitas apa pun.
        $this->assertFalse($fresh->hasCapability(CompanyMembership::CAPABILITY_VERIFY_JOURNALS));
    }

    public function test_non_admin_cannot_grant_or_revoke_membership(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($user, $company);

        $membership = $this->grantMembership($user, $company);

        Sanctum::actingAs($user);

        $this->postJson('/api/company-memberships', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'capabilities' => [CompanyMembership::CAPABILITY_COMPANY_ADMIN],
        ])->assertForbidden();

        $this->patchJson("/api/company-memberships/{$membership->id}", [
            'status' => CompanyMembership::STATUS_INACTIVE,
        ])->assertForbidden();

        $this->deleteJson("/api/company-memberships/{$membership->id}")->assertForbidden();
    }

    public function test_non_admin_only_sees_own_memberships(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');

        $me = $this->createUser('company', 'company_a');
        $this->grantMembership($me, $company);

        $other = $this->createUser('company', 'company_b');
        $this->grantMembership($other, $otherCompany);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/company-memberships')->assertOk();

        $this->assertSame([$company->id], collect($response->json('data'))->pluck('company_id')->all());
    }

    /**
     * Keanggotaan AKTIF saja yang memberi kewenangan; keanggotaan nonaktif
     * tidak lagi memberi akses data perusahaan.
     */
    public function test_inactive_membership_does_not_grant_company_access(): void
    {
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->grantMembership(
            $companyUser,
            $company,
            CompanyMembership::STATUS_INACTIVE,
            [CompanyMembership::CAPABILITY_COMPANY_ADMIN]
        );

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/pkl-placements/{$placement->id}")->assertForbidden();
    }

    /**
     * Keanggotaan aktif memberi kewenangan perusahaan tanpa perlu baris
     * company_supervisors (skema lama tetap didukung, bukan satu-satunya cara).
     */
    public function test_active_membership_grants_company_access_without_legacy_link(): void
    {
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->grantMembership(
            $companyUser,
            $company,
            CompanyMembership::STATUS_ACTIVE,
            [CompanyMembership::CAPABILITY_COMPANY_ADMIN]
        );

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/pkl-placements/{$placement->id}")->assertOk();
    }

    public function test_unknown_capability_is_rejected(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $user = $this->createUser('company', 'company_a');
        $company = $this->createCompany();

        Sanctum::actingAs($admin);

        $this->postJson('/api/company-memberships', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'capabilities' => ['root_access'],
        ])->assertStatus(422);
    }
}

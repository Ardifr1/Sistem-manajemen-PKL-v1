<?php

namespace Tests\Feature;

use App\Models\CompanyMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Otorisasi pembaruan profil perusahaan (PUT /api/companies/{id}):
 * perusahaan yang berwenang boleh mengubah data operasional miliknya
 * sendiri; status kemitraan/aktivasi/verifikasi khusus Admin Sekolah.
 */
class CompanyProfileUpdateTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_company_admin_can_update_own_company_operational_fields(): void
    {
        $company = $this->createCompany('PT Sendiri');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/companies/{$company->id}", [
            'industry' => 'Manufaktur',
            'student_quota' => 12,
            'required_skills' => 'Backend, QA',
            'required_documents' => '["CV","Portofolio"]',
        ])->assertOk();

        $company->refresh();
        $this->assertSame('Manufaktur', $company->industry);
        $this->assertSame(12, $company->student_quota);
        $this->assertSame('Backend, QA', $company->required_skills);
        $this->assertSame('["CV","Portofolio"]', $company->required_documents);
    }

    public function test_company_with_active_membership_can_update_own_company(): void
    {
        $company = $this->createCompany('PT Member');
        $companyUser = $this->createUser('company', 'company_member');
        $this->grantMembership($companyUser, $company, CompanyMembership::STATUS_ACTIVE, [CompanyMembership::CAPABILITY_COMPANY_ADMIN]);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/companies/{$company->id}", [
            'student_quota' => 7,
        ])->assertOk();

        $this->assertSame(7, $company->refresh()->student_quota);
    }

    public function test_company_cannot_update_other_company(): void
    {
        $own = $this->createCompany('PT Sendiri');
        $other = $this->createCompany('PT Lain');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $own);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/companies/{$other->id}", [
            'student_quota' => 99,
        ])->assertForbidden();

        $this->assertSame(5, $other->refresh()->student_quota);
    }

    public function test_company_cannot_change_partner_status(): void
    {
        $company = $this->createCompany('PT Sendiri');
        $company->update(['is_partner' => false, 'verification_status' => 'verified']);
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/companies/{$company->id}", [
            'is_partner' => true,
        ])->assertForbidden();

        $this->assertFalse((bool) $company->refresh()->is_partner);
    }

    public function test_company_cannot_change_active_or_verification_status(): void
    {
        $company = $this->createCompany('PT Sendiri');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->putJson("/api/companies/{$company->id}", [
            'is_active' => false,
        ])->assertForbidden();

        $this->putJson("/api/companies/{$company->id}", [
            'verification_status' => 'verified',
        ])->assertForbidden();

        $company->refresh();
        $this->assertTrue((bool) $company->is_active);
        $this->assertNull($company->verification_status);
    }

    public function test_admin_can_still_update_status_fields(): void
    {
        $company = $this->createCompany('PT Target');

        Sanctum::actingAs($this->createUser('admin', 'admin_a'));

        $this->putJson("/api/companies/{$company->id}", [
            'is_partner' => false,
            'is_active' => false,
        ])->assertOk();

        $company->refresh();
        $this->assertFalse((bool) $company->is_partner);
        $this->assertFalse((bool) $company->is_active);
    }

    public function test_student_cannot_update_company(): void
    {
        $company = $this->createCompany('PT Target');

        Sanctum::actingAs($this->createUser('student', 'student_a'));

        $this->putJson("/api/companies/{$company->id}", [
            'student_quota' => 1,
        ])->assertForbidden();
    }
}

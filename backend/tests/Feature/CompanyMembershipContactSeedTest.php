<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Tautan akun ↔ perusahaan lewat keanggotaan (POST /company-memberships):
 * - Kontak perusahaan yang kosong terisi dari akun tertaut (telepon/email
 *   yang diisi admin saat membuat pengguna), tanpa menimpa data yang ada.
 * - Akun yang HANYA punya keanggotaan (tanpa company_supervisors) tetap
 *   dikenal sebagai akun perusahaan itu oleh endpoint supervisors.
 */
class CompanyMembershipContactSeedTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function companyAccountWithPhone(): User
    {
        return User::create([
            'name' => 'PT Akun Manual',
            'username' => 'akun_manual',
            'email' => 'manual@industri.test',
            'phone' => '081234567890',
            'password' => Hash::make('password123'),
            'role' => 'company',
            'is_active' => true,
        ]);
    }

    public function test_granting_membership_seeds_empty_company_contact_from_account(): void
    {
        $admin = $this->createUser('admin', 'admin_seed');
        $account = $this->companyAccountWithPhone();
        $company = $this->createCompany('PT Kontak Kosong');
        $company->update(['phone' => null, 'email' => null]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/company-memberships', [
            'user_id' => $account->id,
            'company_id' => $company->id,
            'capabilities' => ['company_admin'],
        ])->assertCreated();

        $company->refresh();
        $this->assertSame('081234567890', $company->phone);
        $this->assertSame('manual@industri.test', $company->email);
    }

    public function test_granting_membership_does_not_overwrite_existing_company_contact(): void
    {
        $admin = $this->createUser('admin', 'admin_seed2');
        $account = $this->companyAccountWithPhone();
        $company = $this->createCompany('PT Kontak Terisi');
        $company->update(['phone' => '0215551234', 'email' => 'cs@perusahaan.test']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/company-memberships', [
            'user_id' => $account->id,
            'company_id' => $company->id,
        ])->assertCreated();

        $company->refresh();
        $this->assertSame('0215551234', $company->phone);
        $this->assertSame('cs@perusahaan.test', $company->email);
    }

    public function test_reactivating_membership_seeds_company_contact(): void
    {
        $admin = $this->createUser('admin', 'admin_seed3');
        $account = $this->companyAccountWithPhone();
        $company = $this->createCompany('PT Reaktivasi');
        $company->update(['phone' => null, 'email' => null]);

        $membership = $this->grantMembership(
            $account,
            $company,
            CompanyMembership::STATUS_INACTIVE
        );
        $this->assertNull($company->refresh()->phone);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/company-memberships/{$membership->id}", [
            'status' => CompanyMembership::STATUS_ACTIVE,
        ])->assertOk();

        $company->refresh();
        $this->assertSame('081234567890', $company->phone);
        $this->assertSame('manual@industri.test', $company->email);
    }

    public function test_membership_only_company_account_resolves_its_company(): void
    {
        $account = $this->companyAccountWithPhone();
        $company = $this->createCompany('PT Resolusi');
        $pembimbing = $this->createUser('supervisor', 'pembimbing_resolusi');
        $this->attachCompanySupervisor($pembimbing, $company);
        $this->grantMembership($account, $company, CompanyMembership::STATUS_ACTIVE, ['company_admin']);

        // Tanpa baris company_supervisors untuk akun ini, daftar pembimbing
        // perusahaannya tetap terjangkau (bukan "belum ditautkan").
        Sanctum::actingAs($account);
        $response = $this->getJson('/api/company-supervisors')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($company->id, $response->json('data.0.company_id'));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Filter daftar perusahaan (GET /api/companies) sebagaimana dipakai
 * halaman siswa: boolean tiba sebagai query string ("true"/"false"),
 * dan siswa hanya boleh melihat perusahaan aktif.
 */
class CompanyListFilterTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_partner_filter_accepts_true_query_string(): void
    {
        $partner = $this->createCompany('PT Mitra');
        $verified = $this->createCompany('PT Terverifikasi');
        $verified->update(['is_partner' => false, 'verification_status' => 'verified']);

        Sanctum::actingAs($this->createUser('student', 'student_a'));

        $response = $this->getJson('/api/companies?is_partner=true')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$partner->id], $ids);
    }

    public function test_verified_filter_accepts_false_query_string(): void
    {
        $this->createCompany('PT Mitra');
        $verified = $this->createCompany('PT Terverifikasi');
        $verified->update(['is_partner' => false, 'verification_status' => 'verified']);
        $pending = $this->createCompany('PT Menunggu');
        $pending->update(['is_partner' => false, 'verification_status' => 'pending_school_verification']);

        Sanctum::actingAs($this->createUser('student', 'student_a'));

        $response = $this->getJson('/api/companies?is_partner=false&verification_status=verified')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$verified->id], $ids);
    }

    public function test_partner_filter_accepts_numeric_query_string(): void
    {
        $partner = $this->createCompany('PT Mitra');
        $nonPartner = $this->createCompany('PT Nonmitra');
        $nonPartner->update(['is_partner' => false, 'verification_status' => 'verified']);

        Sanctum::actingAs($this->createUser('student', 'student_a'));

        $response = $this->getJson('/api/companies?is_partner=1')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$partner->id], $ids);
    }

    public function test_student_list_hides_inactive_companies(): void
    {
        $active = $this->createCompany('PT Aktif');
        $inactive = $this->createCompany('PT Nonaktif');
        $inactive->update(['is_active' => false]);

        Sanctum::actingAs($this->createUser('student', 'student_a'));

        $response = $this->getJson('/api/companies')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
    }

    public function test_admin_list_includes_inactive_companies(): void
    {
        $this->createCompany('PT Aktif');
        $inactive = $this->createCompany('PT Nonaktif');
        $inactive->update(['is_active' => false]);

        Sanctum::actingAs($this->createUser('admin', 'admin_a'));

        $response = $this->getJson('/api/companies')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($inactive->id, $ids);
        $this->assertCount(2, $ids);
    }
}

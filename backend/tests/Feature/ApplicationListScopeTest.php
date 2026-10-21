<?php

namespace Tests\Feature;

use App\Models\CompanyMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Cakupan daftar lamaran (GET /api/pkl-applications) per peran:
 * perusahaan/pembimbing hanya melihat lamaran ke perusahaannya, guru
 * hanya lamaran siswa bimbingannya (lewat pkl_placements.teacher_id).
 */
class ApplicationListScopeTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_company_only_lists_applications_for_its_own_company(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $student = $this->createUser('student', 'student_a');
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $companyA);

        $applicationA = $this->makeApplicationFor($student, $companyA, $period);
        $this->makeApplicationFor($student, $companyB, $period, 2);

        Sanctum::actingAs($companyUser);

        $response = $this->getJson('/api/pkl-applications')->assertOk();
        $this->assertSame(
            [$applicationA->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_company_with_active_membership_lists_applications_without_legacy_link(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT Member');
        $student = $this->createUser('student', 'student_a');
        $companyUser = $this->createUser('company', 'company_member');
        $this->grantMembership($companyUser, $company, CompanyMembership::STATUS_ACTIVE, [CompanyMembership::CAPABILITY_REVIEW_APPLICATIONS]);

        $application = $this->makeApplicationFor($student, $company, $period);

        Sanctum::actingAs($companyUser);

        $response = $this->getJson('/api/pkl-applications')->assertOk();
        $this->assertSame(
            [$application->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_company_without_any_company_access_gets_empty_list(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');
        $student = $this->createUser('student', 'student_a');
        $companyUser = $this->createUser('company', 'company_unlinked');

        $this->makeApplicationFor($student, $company, $period);

        Sanctum::actingAs($companyUser);

        $response = $this->getJson('/api/pkl-applications')->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_teacher_only_lists_applications_of_supervised_students(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany('PT A');
        $teacher = $this->createUser('teacher', 'teacher_a');
        $otherTeacher = $this->createUser('teacher', 'teacher_b');
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $applicationA = $this->makeApplicationFor($studentA, $company, $period);
        $this->makeApplicationFor($studentB, $company, $period);
        $this->createPlacement($studentA, $company, $period, null, $teacher);
        $this->createPlacement($studentB, $company, $period, null, $otherTeacher);

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/pkl-applications')->assertOk();
        $this->assertSame(
            [$applicationA->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }
}

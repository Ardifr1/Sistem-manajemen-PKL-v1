<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class StudentProfileAccessTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function createStudentProfile(int $userId, string $studentNumber): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => $userId,
            'student_number' => $studentNumber,
            'class' => 'XII RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
        ]);
    }

    public function test_teacher_only_lists_supervised_student_profiles(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $teacher = $this->createUser('teacher', 'teacher_a');
        $otherTeacher = $this->createUser('teacher', 'teacher_b');
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $this->createPlacement($studentA, $company, $period, null, $teacher);
        $this->createPlacement($studentB, $company, $period, null, $otherTeacher);

        $profileA = $this->createStudentProfile($studentA->id, '2026-0001');
        $this->createStudentProfile($studentB->id, '2026-0002');

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/student-profiles')->assertOk();

        $this->assertSame(
            [$profileA->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_teacher_can_view_supervised_student_profile(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $teacher = $this->createUser('teacher', 'teacher_a');
        $student = $this->createUser('student', 'student_a');

        $this->createPlacement($student, $company, $period, null, $teacher);
        $profile = $this->createStudentProfile($student->id, '2026-0001');

        Sanctum::actingAs($teacher);

        $this->getJson("/api/student-profiles/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $student->id);
    }

    public function test_teacher_cannot_view_unsupervised_student_profile(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $teacher = $this->createUser('teacher', 'teacher_a');
        $otherTeacher = $this->createUser('teacher', 'teacher_b');
        $student = $this->createUser('student', 'student_b');

        $this->createPlacement($student, $company, $period, null, $otherTeacher);
        $profile = $this->createStudentProfile($student->id, '2026-0002');

        Sanctum::actingAs($teacher);

        $this->getJson("/api/student-profiles/{$profile->id}")->assertForbidden();
    }

    public function test_teacher_cannot_filter_list_by_unsupervised_student(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $teacher = $this->createUser('teacher', 'teacher_a');
        $otherTeacher = $this->createUser('teacher', 'teacher_b');
        $student = $this->createUser('student', 'student_b');

        $this->createPlacement($student, $company, $period, null, $otherTeacher);
        $this->createStudentProfile($student->id, '2026-0002');

        Sanctum::actingAs($teacher);

        $this->getJson('/api/student-profiles?user_id='.$student->id)
            ->assertForbidden();
    }

    public function test_supervisor_can_view_profile_of_assigned_student_only(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        $otherSupervisorUser = $this->createUser('supervisor', 'supervisor_b');
        $otherSupervisor = $this->attachCompanySupervisor($otherSupervisorUser, $company);

        $assignedStudent = $this->createUser('student', 'student_a');
        $otherStudent = $this->createUser('student', 'student_b');

        $assignedPlacement = $this->createPlacement($assignedStudent, $company, $period);
        $assignedPlacement->update(['supervisor_id' => $supervisor->id]);

        $otherPlacement = $this->createPlacement($otherStudent, $company, $period);
        $otherPlacement->update(['supervisor_id' => $otherSupervisor->id]);

        $assignedProfile = $this->createStudentProfile($assignedStudent->id, '2026-0001');
        $otherProfile = $this->createStudentProfile($otherStudent->id, '2026-0002');

        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/student-profiles/{$assignedProfile->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $assignedStudent->id);

        // Siswa lain pada perusahaan yang sama tetapi bukan bimbingannya.
        $this->getJson("/api/student-profiles/{$otherProfile->id}")
            ->assertForbidden();
    }

    public function test_supervisor_cannot_view_profile_of_unassigned_placement(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_b');

        // Penempatan belum memiliki pembimbing industri.
        $this->createPlacement($student, $company, $period);
        $profile = $this->createStudentProfile($student->id, '2026-0002');

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $this->attachCompanySupervisor($supervisorUser, $company);

        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/student-profiles/{$profile->id}")->assertForbidden();
    }

    public function test_supervisor_without_company_link_cannot_view_any_profile(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');
        $this->createPlacement($student, $company, $period);
        $profile = $this->createStudentProfile($student->id, '2026-0001');

        // Akun supervisor yang tidak tertaut ke perusahaan mana pun.
        $supervisorUser = $this->createUser('supervisor', 'supervisor_orphan');

        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/student-profiles/{$profile->id}")->assertForbidden();
        $this->getJson('/api/student-profiles')->assertForbidden();
    }

    public function test_company_can_view_only_profiles_of_its_own_placements(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $this->createPlacement($studentA, $companyA, $period);
        $this->createPlacement($studentB, $companyB, $period);

        $profileA = $this->createStudentProfile($studentA->id, '2026-0001');
        $profileB = $this->createStudentProfile($studentB->id, '2026-0002');

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $companyA);

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/student-profiles/{$profileA->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $studentA->id);

        $this->getJson("/api/student-profiles/{$profileB->id}")->assertForbidden();
    }

    public function test_company_without_membership_cannot_view_profiles(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $student = $this->createUser('student', 'student_a');
        $this->createPlacement($student, $company, $period);
        $profile = $this->createStudentProfile($student->id, '2026-0001');

        $companyUser = $this->createUser('company', 'company_orphan');

        Sanctum::actingAs($companyUser);

        $this->getJson("/api/student-profiles/{$profile->id}")->assertForbidden();
    }

    public function test_admin_can_list_and_view_all_student_profiles(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $profileA = $this->createStudentProfile($studentA->id, '2026-0001');
        $profileB = $this->createStudentProfile($studentB->id, '2026-0002');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/student-profiles')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$profileA->id, $profileB->id],
            collect($response->json('data'))->pluck('id')->all()
        );

        $this->getJson("/api/student-profiles/{$profileA->id}")->assertOk();
        $this->getJson("/api/student-profiles/{$profileB->id}")->assertOk();
    }
}

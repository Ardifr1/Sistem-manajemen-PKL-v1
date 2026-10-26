<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * P1 #3 — penugasan guru pembimbing (teacher_id) dan pembimbing industri
 * (supervisor_id) melalui endpoint admin, beserta pembatasan aksesnya.
 */
class PlacementAssignmentTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function createStudentProfileFor(int $studentId, string $studentNumber): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => $studentId,
            'student_number' => $studentNumber,
            'class' => 'XII RPL 1',
            'major' => 'Rekayasa Perangkat Lunak',
        ]);
    }

    public function test_admin_can_assign_teacher_and_supervisor_via_update_endpoint(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'teacher_id' => $teacher->id,
            'supervisor_id' => $supervisor->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.supervisor_id', $supervisor->id);

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'teacher_id' => $teacher->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $placement->refresh();

        $this->assertSame($teacher->id, (int) $placement->teacher_id);
        $this->assertSame($supervisor->id, (int) $placement->supervisor_id);
        $this->assertSame($student->id, (int) $placement->student_id);
        $this->assertSame($company->id, (int) $placement->company_id);
    }

    public function test_assigned_teacher_can_access_placement_and_student_profile(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        // Penempatan siswa lain dengan guru pembimbing berbeda.
        $otherStudent = $this->createUser('student', 'student_b');
        $otherTeacher = $this->createUser('teacher', 'teacher_b');
        $this->createPlacement($otherStudent, $company, $period, null, $otherTeacher);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'teacher_id' => $teacher->id,
        ])->assertOk();

        $profile = $this->createStudentProfileFor($student->id, '2026-0001');

        Sanctum::actingAs($teacher);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $placement->id);

        $this->getJson('/api/pkl-placements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $placement->id);

        $this->getJson("/api/student-profiles/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $student->id);
    }

    public function test_unassigned_teacher_cannot_access_placement_or_student_profile(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $otherTeacher = $this->createUser('teacher', 'teacher_a');
        $placement = $this->createPlacement($student, $company, $period, null, $otherTeacher);

        $profile = $this->createStudentProfileFor($student->id, '2026-0001');

        $unassignedTeacher = $this->createUser('teacher', 'teacher_b');

        Sanctum::actingAs($unassignedTeacher);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertForbidden();

        $this->getJson('/api/pkl-placements')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/student-profiles/{$profile->id}")
            ->assertForbidden();
    }

    public function test_assigned_supervisor_can_access_only_assigned_placement(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $placementA = $this->createPlacement($studentA, $company, $period);
        $placementB = $this->createPlacement($studentB, $company, $period);

        $supervisorUserA = $this->createUser('supervisor', 'supervisor_a');
        $supervisorA = $this->attachCompanySupervisor($supervisorUserA, $company);
        $supervisorUserB = $this->createUser('supervisor', 'supervisor_b');
        $supervisorB = $this->attachCompanySupervisor($supervisorUserB, $company);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placementA->id}", [
            'supervisor_id' => $supervisorA->id,
        ])->assertOk();

        $this->putJson("/api/pkl-placements/{$placementB->id}", [
            'supervisor_id' => $supervisorB->id,
        ])->assertOk();

        Sanctum::actingAs($supervisorUserA);

        $this->getJson("/api/pkl-placements/{$placementA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $placementA->id);

        // Penempatan milik pembimbing lain pada perusahaan yang sama.
        $this->getJson("/api/pkl-placements/{$placementB->id}")
            ->assertForbidden();

        $this->getJson('/api/pkl-placements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $placementA->id);
    }

    public function test_supervisor_cannot_access_unassigned_placement_at_own_company(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');

        // Penempatan belum memiliki pembimbing industri.
        $placement = $this->createPlacement($student, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $this->attachCompanySupervisor($supervisorUser, $company);

        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/pkl-placements/{$placement->id}")
            ->assertForbidden();

        $this->getJson('/api/pkl-placements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_cannot_assign_user_with_non_teacher_role_as_teacher(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'teacher_id' => $student->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->assertNull($placement->fresh()->teacher_id);
    }

    public function test_admin_cannot_assign_supervisor_from_another_company(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_other');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $otherCompany);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'supervisor_id' => $supervisor->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertNull($placement->fresh()->supervisor_id);
    }

    public function test_admin_cannot_assign_company_supervisor_record_whose_user_is_not_supervisor(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        // Akun perusahaan memang dapat memiliki baris company_supervisors
        // (jalur lama/keanggotaan), tetapi bukan pembimbing industri.
        $companyUser = $this->createUser('company', 'company_a');
        $supervisor = $this->attachCompanySupervisor($companyUser, $company);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'supervisor_id' => $supervisor->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertNull($placement->fresh()->supervisor_id);
    }

    public function test_admin_can_assign_supervisor_from_new_company_when_moving_placement(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $companyA, $period);

        $supervisorUserB = $this->createUser('supervisor', 'supervisor_b');
        $supervisorB = $this->attachCompanySupervisor($supervisorUserB, $companyB);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'company_id' => $companyB->id,
            'supervisor_id' => $supervisorB->id,
        ])->assertOk();

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'company_id' => $companyB->id,
            'supervisor_id' => $supervisorB->id,
        ]);
    }

    public function test_admin_cannot_assign_supervisor_from_old_company_when_moving_placement(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $companyA, $period);

        $supervisorUserA = $this->createUser('supervisor', 'supervisor_a');
        $supervisorA = $this->attachCompanySupervisor($supervisorUserA, $companyA);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'company_id' => $companyB->id,
            'supervisor_id' => $supervisorA->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'company_id' => $companyA->id,
            'supervisor_id' => null,
        ]);
    }

    public function test_admin_must_reassign_or_clear_supervisor_when_moving_placement(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $companyA, $period);

        $supervisorUserA = $this->createUser('supervisor', 'supervisor_a');
        $supervisorA = $this->attachCompanySupervisor($supervisorUserA, $companyA);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'supervisor_id' => $supervisorA->id,
        ])->assertOk();

        // Pindah perusahaan tanpa pembimbing pengganti harus ditolak agar
        // supervisor lama tidak tetap melekat pada perusahaan baru.
        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'company_id' => $companyB->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'company_id' => $companyA->id,
            'supervisor_id' => $supervisorA->id,
        ]);

        // Mengosongkan supervisor_id saat pindah perusahaan tetap diizinkan.
        $this->putJson("/api/pkl-placements/{$placement->id}", [
            'company_id' => $companyB->id,
            'supervisor_id' => null,
        ])->assertOk();

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'company_id' => $companyB->id,
            'supervisor_id' => null,
        ]);
    }

    public function test_company_user_index_is_scoped_to_its_company(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $placementA = $this->createPlacement($studentA, $companyA, $period);
        $this->createPlacement($studentB, $companyB, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $companyA);

        Sanctum::actingAs($companyUser);

        $this->getJson('/api/pkl-placements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $placementA->id);
    }

    public function test_company_can_assign_supervisor_to_its_own_placement(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->patchJson("/api/pkl-placements/{$placement->id}/supervisor", [
            'supervisor_id' => $supervisor->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.supervisor_id', $supervisor->id);

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'placement.supervisor_assigned',
            'placement_id' => $placement->id,
            'user_id' => $companyUser->id,
        ]);

        // Pembimbing yang ditugaskan langsung memperoleh akses ke penempatan
        // dan profil siswa tersebut.
        $profile = $this->createStudentProfileFor($student->id, '2026-0001');

        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/pkl-placements/{$placement->id}")->assertOk();
        $this->getJson("/api/student-profiles/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $student->id);
    }

    public function test_company_cannot_assign_supervisor_to_placement_of_other_company(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $companyB, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $companyB);

        $companyUserA = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUserA, $companyA);

        Sanctum::actingAs($companyUserA);

        $this->patchJson("/api/pkl-placements/{$placement->id}/supervisor", [
            'supervisor_id' => $supervisor->id,
        ])->assertForbidden();

        $this->assertNull($placement->fresh()->supervisor_id);
    }

    public function test_company_cannot_assign_supervisor_from_another_company(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_other');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $otherCompany);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->patchJson("/api/pkl-placements/{$placement->id}/supervisor", [
            'supervisor_id' => $supervisor->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertNull($placement->fresh()->supervisor_id);
    }

    public function test_company_cannot_assign_non_supervisor_user(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $teacherUser = $this->createUser('teacher', 'teacher_a');
        $supervisorRow = $this->attachCompanySupervisor($teacherUser, $company);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->patchJson("/api/pkl-placements/{$placement->id}/supervisor", [
            'supervisor_id' => $supervisorRow->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertNull($placement->fresh()->supervisor_id);
    }

    public function test_company_endpoint_only_changes_supervisor_id(): void
    {
        $period = $this->createPeriod();
        $otherPeriod = $this->createPeriod('PKL Lain');
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $student = $this->createUser('student', 'student_a');
        $otherStudent = $this->createUser('student', 'student_b');
        $teacher = $this->createUser('teacher', 'teacher_a');

        $placement = $this->createPlacement($student, $company, $period, null, $teacher);
        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->patchJson("/api/pkl-placements/{$placement->id}/supervisor", [
            'supervisor_id' => $supervisor->id,
            // Field di luar kewenangan perusahaan harus diabaikan.
            'teacher_id' => $companyUser->id,
            'company_id' => $otherCompany->id,
            'student_id' => $otherStudent->id,
            'pkl_period_id' => $otherPeriod->id,
            'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('pkl_placements', [
            'id' => $placement->id,
            'supervisor_id' => $supervisor->id,
            'teacher_id' => $teacher->id,
            'company_id' => $company->id,
            'student_id' => $student->id,
            'pkl_period_id' => $period->id,
            'status' => 'active',
        ]);
    }

    public function test_non_company_roles_cannot_use_supervisor_endpoint(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $placement = $this->createPlacement($student, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        foreach (['admin', 'teacher', 'student', 'supervisor'] as $role) {
            $actor = $role === 'student'
                ? $student
                : ($role === 'supervisor' ? $supervisorUser : $this->createUser($role, $role.'_actor'));

            Sanctum::actingAs($actor);

            $this->patchJson("/api/pkl-placements/{$placement->id}/supervisor", [
                'supervisor_id' => $role === 'supervisor' ? null : $supervisor->id,
            ])->assertForbidden();
        }

        $this->assertNull($placement->fresh()->supervisor_id);
    }

    public function test_store_validates_teacher_role_and_supervisor_company(): void
    {
        $period = $this->createPeriod();
        $company = $this->createCompany();
        $otherCompany = $this->createCompany('PT Lain');
        $student = $this->createUser('student', 'student_a');

        $supervisorUser = $this->createUser('supervisor', 'supervisor_other');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $otherCompany);

        $admin = $this->createUser('admin', 'admin_a');

        Sanctum::actingAs($admin);

        $this->postJson('/api/pkl-placements', [
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'teacher_id' => $student->id,
            'supervisor_id' => $supervisor->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id', 'supervisor_id']);

        $this->assertDatabaseMissing('pkl_placements', [
            'student_id' => $student->id,
            'pkl_period_id' => $period->id,
        ]);
    }
}

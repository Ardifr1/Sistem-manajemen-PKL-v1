<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * P1 audit ulang — batas akses berdasarkan PENUGASAN, bukan hanya perusahaan.
 *
 * Pembimbing industri hanya boleh mengakses siswa yang placement-nya memiliki
 * supervisor_id miliknya; guru hanya siswa bimbingannya; perusahaan tetap
 * memakai cakupan perusahaan (keanggotaan aktif).
 */
class SupervisorScopeTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    public function test_teacher_cannot_read_journal_of_unsupervised_student(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $teacher = $this->createUser('teacher', 'teacher_a');
        $otherTeacher = $this->createUser('teacher', 'teacher_b');

        $ownStudent = $this->createUser('student', 'student_a');
        $otherStudent = $this->createUser('student', 'student_b');

        $ownPlacement = $this->createPlacement($ownStudent, $company, $period, null, $teacher);
        $otherPlacement = $this->createPlacement($otherStudent, $company, $period, null, $otherTeacher);

        $ownJournal = $this->createJournal($ownPlacement);
        $otherJournal = $this->createJournal($otherPlacement);

        Sanctum::actingAs($teacher);

        $this->getJson("/api/journals/{$ownJournal->id}")->assertOk();
        $this->getJson("/api/journals/{$otherJournal->id}")->assertForbidden();
    }

    public function test_supervisor_journal_and_attendance_index_only_cover_assigned_placement(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $placementA = $this->createPlacement($studentA, $company, $period);
        $placementB = $this->createPlacement($studentB, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);

        $placementA->update(['supervisor_id' => $supervisor->id]);

        $journalA = $this->createJournal($placementA);
        $this->createJournal($placementB);
        $attendanceA = $this->createAttendance($placementA);
        $this->createAttendance($placementB);

        Sanctum::actingAs($supervisorUser);

        $journals = $this->getJson('/api/journals')->assertOk();
        $this->assertSame(
            [$journalA->id],
            collect($journals->json('data'))->pluck('id')->all()
        );

        $attendances = $this->getJson('/api/attendances')->assertOk();
        $this->assertSame(
            [$attendanceA->id],
            collect($attendances->json('data'))->pluck('id')->all()
        );
    }

    public function test_supervisor_can_view_only_assigned_attendance_detail(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $placementA = $this->createPlacement($studentA, $company, $period);
        $placementB = $this->createPlacement($studentB, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $placementA->update(['supervisor_id' => $supervisor->id]);

        $attendanceA = $this->createAttendance($placementA);
        $attendanceB = $this->createAttendance($placementB);

        Sanctum::actingAs($supervisorUser);

        $this->getJson("/api/attendances/{$attendanceA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $attendanceA->id);

        $this->getJson("/api/attendances/{$attendanceB->id}")
            ->assertForbidden();
    }

    public function test_supervisor_assessment_index_excludes_unassigned_placement(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $assignedStudent = $this->createUser('student', 'student_a');
        $unassignedStudent = $this->createUser('student', 'student_b');

        $assignedPlacement = $this->createPlacement($assignedStudent, $company, $period);
        $unassignedPlacement = $this->createPlacement($unassignedStudent, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $assignedPlacement->update(['supervisor_id' => $supervisor->id]);

        $component = $this->createComponent('industri');

        // Penilaian pada penempatan yang TIDAK ditugaskan ke pembimbing ini
        // (supervisor_id masih null) tidak boleh terlihat olehnya.
        $this->createAssessment($unassignedPlacement, $component, $companyUser = $this->createUser('company', 'company_a'));
        $assignedAssessment = $this->createAssessment($assignedPlacement, $component, $supervisorUser);

        Sanctum::actingAs($supervisorUser);

        $response = $this->getJson('/api/assessments')->assertOk();

        $this->assertSame(
            [$assignedAssessment->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_supervisor_user_list_excludes_students_at_same_company_not_assigned(): void
    {
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $assignedStudent = $this->createUser('student', 'student_a');
        $otherStudent = $this->createUser('student', 'student_b');

        $assignedPlacement = $this->createPlacement($assignedStudent, $company, $period);
        $this->createPlacement($otherStudent, $company, $period);

        $supervisorUser = $this->createUser('supervisor', 'supervisor_a');
        $supervisor = $this->attachCompanySupervisor($supervisorUser, $company);
        $assignedPlacement->update(['supervisor_id' => $supervisor->id]);

        Sanctum::actingAs($supervisorUser);

        $response = $this->getJson('/api/users?role=student')->assertOk();

        $this->assertSame(
            [$assignedStudent->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_company_membership_user_list_covers_its_company_students(): void
    {
        $period = $this->createPeriod();
        $companyA = $this->createCompany('PT A');
        $companyB = $this->createCompany('PT B');

        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');

        $this->createPlacement($studentA, $companyA, $period);
        $this->createPlacement($studentB, $companyB, $period);

        // Perusahaan yang ditautkan lewat company_memberships (bukan tautan
        // lama company_supervisors) tetap harus melihat siswanya sendiri.
        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanyMembership($companyUser, $companyA);

        Sanctum::actingAs($companyUser);

        $response = $this->getJson('/api/users?role=student')->assertOk();

        $this->assertSame(
            [$studentA->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }
}

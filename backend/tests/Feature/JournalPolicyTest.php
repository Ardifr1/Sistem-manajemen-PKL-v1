<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Journal;
use App\Models\PklApplication;
use App\Models\PklPeriod;
use App\Models\PklPlacement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

class JournalPolicyTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    public function test_student_can_view_own_journal(): void
    {
        $student = $this->createStudent('student_a', 'Siswa A');

        $journal = $this->createJournalForStudent($student);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/journals/{$journal->id}");

        $response->assertOk();
    }

    public function test_student_cannot_view_other_student_journal(): void
    {
        $studentA = $this->createStudent('student_a', 'Siswa A');
        $studentB = $this->createStudent('student_b', 'Siswa B');

        $journal = $this->createJournalForStudent($studentB);

        Sanctum::actingAs($studentA);

        $response = $this->getJson("/api/journals/{$journal->id}");

        $response->assertForbidden();
    }

    public function test_student_cannot_list_journal_of_other_placement(): void
    {
        $studentA = $this->createStudent('student_a', 'Siswa A');
        $studentB = $this->createStudent('student_b', 'Siswa B');

        $journal = $this->createJournalForStudent($studentB);
        $placementB = $journal->placement;

        Sanctum::actingAs($studentA);

        $this->getJson('/api/journals?placement_id='.$placementB->id)
            ->assertForbidden();
    }

    public function test_student_can_create_journal_on_own_placement(): void
    {
        $student = $this->createStudent('student_a', 'Siswa A');
        $journal = $this->createJournalForStudent($student);
        $placement = $journal->placement;

        Sanctum::actingAs($student);

        $this->postJson('/api/journals', [
            'placement_id' => $placement->id,
            'journal_date' => now()->addDay()->toDateString(),
            'activity' => 'Aktivitas hari ini.',
        ])->assertCreated();
    }

    public function test_student_cannot_create_journal_on_other_placement(): void
    {
        $studentA = $this->createStudent('student_a', 'Siswa A');
        $studentB = $this->createStudent('student_b', 'Siswa B');

        $journalB = $this->createJournalForStudent($studentB);

        Sanctum::actingAs($studentA);

        $this->postJson('/api/journals', [
            'placement_id' => $journalB->placement_id,
            'journal_date' => now()->addDay()->toDateString(),
            'activity' => 'Aktivitas hari ini.',
        ])->assertForbidden();
    }

    public function test_student_cannot_update_journal_of_other_student(): void
    {
        $studentA = $this->createStudent('student_a', 'Siswa A');
        $studentB = $this->createStudent('student_b', 'Siswa B');

        $journal = $this->createJournalForStudent($studentB);

        Sanctum::actingAs($studentA);

        $this->putJson("/api/journals/{$journal->id}", [
            'activity' => 'Diubah tanpa hak.',
        ])->assertForbidden();
    }

    public function test_student_cannot_submit_journal_of_other_student(): void
    {
        $studentA = $this->createStudent('student_a', 'Siswa A');
        $studentB = $this->createStudent('student_b', 'Siswa B');

        $journal = $this->createJournalForStudent($studentB);

        Sanctum::actingAs($studentA);

        $this->postJson("/api/journals/{$journal->id}/submit")
            ->assertForbidden();

        $this->assertDatabaseHas('journals', [
            'id' => $journal->id,
            'status' => 'draft',
        ]);
    }

    public function test_student_cannot_delete_journal_of_other_student(): void
    {
        $studentA = $this->createStudent('student_a', 'Siswa A');
        $studentB = $this->createStudent('student_b', 'Siswa B');

        $journal = $this->createJournalForStudent($studentB);

        Sanctum::actingAs($studentA);

        $this->deleteJson("/api/journals/{$journal->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('journals', [
            'id' => $journal->id,
        ]);
    }

    public function test_teacher_can_view_journal_of_assigned_student(): void
    {
        $student = $this->createStudent('student_a', 'Siswa A');
        $journal = $this->createJournalForStudent($student);

        $teacher = $this->createUser('teacher', 'teacher_a');
        $journal->placement->update(['teacher_id' => $teacher->id]);

        Sanctum::actingAs($teacher);

        $this->getJson("/api/journals/{$journal->id}")
            ->assertOk();
    }

    public function test_teacher_cannot_view_journal_of_unsupervised_student(): void
    {
        $student = $this->createStudent('student_a', 'Siswa A');
        $journal = $this->createJournalForStudent($student);

        // Guru lain yang tidak ditugaskan pada siswa tersebut.
        $otherTeacher = $this->createUser('teacher', 'teacher_b');

        Sanctum::actingAs($otherTeacher);

        $this->getJson("/api/journals/{$journal->id}")
            ->assertForbidden();
    }

    private function createUser(string $role, string $username): User
    {
        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => "{$username}@test.com",
            'password' => Hash::make('password123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createStudent(string $username, string $name): User
    {
        return User::create([
            'name' => $name,
            'username' => $username,
            'email' => "{$username}@test.com",
            'password' => Hash::make('password123'),
            'role' => 'student',
            'is_active' => true,
        ]);
    }

    private function createJournalForStudent(User $student): Journal
    {
        $company = Company::create([
            'name' => 'PT Test Company',
            'industry' => 'Teknologi',
            'student_quota' => 5,
            'is_partner' => true,
            'is_active' => true,
        ]);

        $period = PklPeriod::create([
            'name' => 'PKL Test 2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'is_active' => true,
        ]);

        $application = PklApplication::create([
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'choice_order' => 1,
            'status' => 'approved',
        ]);

        $placement = PklPlacement::create([
            'student_id' => $student->id,
            'company_id' => $company->id,
            'pkl_period_id' => $period->id,
            'application_id' => $application->id,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => 'active',
        ]);

        return Journal::create([
            'placement_id' => $placement->id,
            'journal_date' => now()->toDateString(),
            'activity' => 'Mengerjakan testing aplikasi',
            'status' => 'draft',
        ]);
    }
}
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Keamanan pengelolaan pengguna: hanya admin yang boleh membuat/mengubah/
 * menghapus akun; non-admin hanya boleh memperbarui profilnya sendiri.
 */
class UserManagementPolicyTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function payload(string $username = 'baru'): array
    {
        return [
            'name' => 'Pengguna Baru',
            'username' => $username,
            'email' => $username.'@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'is_active' => true,
        ];
    }

    public function test_non_admin_cannot_create_user(): void
    {
        foreach (['teacher', 'company', 'supervisor'] as $role) {
            $actor = $this->createUser($role, $role.'_actor');
            Sanctum::actingAs($actor);

            $this->postJson('/api/users', $this->payload('baru_'.$role))
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['username' => 'baru_teacher']);
    }

    public function test_non_admin_cannot_update_or_delete_other_user(): void
    {
        $victim = $this->createUser('admin', 'victim_admin');
        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->putJson("/api/users/{$victim->id}", ['name' => 'Dikuasai'])
            ->assertForbidden();
        $this->deleteJson("/api/users/{$victim->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Victim_admin']);
    }

    public function test_non_admin_cannot_view_other_user_detail(): void
    {
        $victim = $this->createUser('student', 'student_a');
        $teacher = $this->createUser('teacher', 'teacher_b');

        Sanctum::actingAs($teacher);

        $this->getJson("/api/users/{$victim->id}")->assertForbidden();
        $this->getJson("/api/users/{$teacher->id}")->assertOk();
    }

    public function test_self_update_cannot_escalate_role(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->putJson("/api/users/{$teacher->id}", [
            'name' => 'Nama Baru',
            'role' => 'admin',
            'is_active' => false,
        ])->assertOk();

        // Nama berubah, tetapi role & status TIDAK boleh berubah.
        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'name' => 'Nama Baru',
            'role' => 'teacher',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_manage_users(): void
    {
        $admin = $this->createUser('admin', 'admin_a');
        $target = $this->createUser('student', 'student_a');

        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/users', $this->payload('dibuat_admin'))
            ->assertCreated();
        $newId = $created->json('data.id');

        $this->putJson("/api/users/{$newId}", ['name' => 'Diubah Admin'])->assertOk();
        $this->deleteJson("/api/users/{$target->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_teacher_lists_only_own_students(): void
    {
        $teacherA = $this->createUser('teacher', 'teacher_a');
        $teacherB = $this->createUser('teacher', 'teacher_b');
        $studentA = $this->createUser('student', 'student_a');
        $studentB = $this->createUser('student', 'student_b');
        $company = $this->createCompany();
        $period = $this->createPeriod();

        $this->createPlacement($studentA, $company, $period, null, $teacherA);
        $this->createPlacement($studentB, $company, $period, null, $teacherB);

        Sanctum::actingAs($teacherA);

        $response = $this->getJson('/api/users?role=student')->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('Student_a', $names);
        $this->assertNotContains('Student_b', $names);
    }

    public function test_company_can_list_users_scoped_without_error(): void
    {
        // Regresi: relation User::placements() dulu tidak ada -> 500.
        $company = $this->createCompany();
        $student = $this->createUser('student', 'student_a');
        $period = $this->createPeriod();
        $this->createPlacement($student, $company, $period);

        $companyUser = $this->createUser('company', 'company_a');
        $this->attachCompanySupervisor($companyUser, $company);

        Sanctum::actingAs($companyUser);

        $this->getJson('/api/users')->assertOk();
    }
}

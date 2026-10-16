<?php

namespace Tests\Feature;

use App\Models\Feedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Regresi: model Feedback dulu resolve ke tabel 'feedback' (kata tak
 * terhitung) sementara migrasinya 'feedbacks', sehingga GET /feedbacks -> 500.
 */
class FeedbackResourceTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private function makeFeedback(): Feedback
    {
        $student = $this->createUser('student', 'student_a');
        $company = $this->createCompany();
        $period = $this->createPeriod();
        $placement = $this->createPlacement($student, $company, $period);

        return Feedback::create([
            'placement_id' => $placement->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'lingkungan_kerja' => 4,
            'pembimbing_industri' => 5,
            'kesesuaian_bidang' => 4,
            'pengalaman_belajar' => 5,
            'kenyamanan' => 4,
            'kesempatan_belajar' => 4,
            'komentar' => 'Pengalaman yang baik.',
            'status' => 'pending',
        ]);
    }

    public function test_feedback_model_uses_feedbacks_table(): void
    {
        $this->assertSame('feedbacks', (new Feedback)->getTable());
    }

    public function test_admin_can_list_feedbacks(): void
    {
        $this->makeFeedback();

        $admin = $this->createUser('admin', 'admin_a');
        Sanctum::actingAs($admin);

        $this->getJson('/api/feedbacks')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_student_only_sees_own_feedback(): void
    {
        $this->makeFeedback();

        $otherStudent = $this->createUser('student', 'student_b');
        Sanctum::actingAs($otherStudent);

        $this->getJson('/api/feedbacks')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_supervisor_cannot_list_feedbacks(): void
    {
        $this->makeFeedback();

        $company = $this->createCompany();
        $supervisor = $this->createUser('supervisor', 'supervisor_a');
        $this->attachCompanySupervisor($supervisor, $company);

        Sanctum::actingAs($supervisor);

        $this->getJson('/api/feedbacks')->assertForbidden();
    }
}

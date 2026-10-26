<?php

namespace Tests\Feature;

use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Tahap 4 — AI Journal Assistant.
 *
 * Semua panggilan provider di-fake: tidak ada biaya/permintaan jaringan nyata.
 * Yang diuji: otorisasi, validasi input, pemetaan kegagalan provider, sanitasi
 * keluaran, rate limit, dan jaminan AI tidak pernah menyetujui jurnal.
 */
class JournalAiAssistantTest extends TestCase
{
    use ManagesPklTestData;
    use RefreshDatabase;

    private const ENDPOINT = '/api/journals/ai-assistant';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simagang.ai.api_key' => 'test-ai-key',
            'simagang.ai.base_url' => 'https://api.z.ai/api/paas/v4',
            'simagang.ai.model' => 'glm-5.3',
            'simagang.ai.timeout_seconds' => 5,
            'simagang.ai.max_draft_chars' => 1500,
            'simagang.ai.rate_limit_per_minute' => 8,
        ]);
    }

    private function actingAsStudent(): \App\Models\User
    {
        $student = $this->createUser('student', 'student_ai');
        Sanctum::actingAs($student);

        return $student;
    }

    private function payload(string $draft = 'Hari ini saya membuat endpoint API data siswa menggunakan Laravel dan mengujinya dengan Postman.'): array
    {
        return ['draft' => $draft];
    }

    private function providerResponse(array $payload): array
    {
        return [
            'choices' => [
                ['message' => ['content' => json_encode($payload)]],
            ],
        ];
    }

    public function test_guest_cannot_use_ai_assistant(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
    }

    public function test_non_student_roles_cannot_use_ai_assistant(): void
    {
        foreach (['admin', 'teacher', 'company', 'supervisor'] as $role) {
            Sanctum::actingAs($this->createUser($role, $role.'_ai'));

            $this->postJson(self::ENDPOINT, $this->payload())->assertForbidden();
        }
    }

    public function test_draft_is_required_and_bounded(): void
    {
        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft');

        $this->postJson(self::ENDPOINT, ['draft' => 'terlalu pendek'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft');

        config(['simagang.ai.max_draft_chars' => 50]);

        $this->postJson(self::ENDPOINT, ['draft' => str_repeat('a', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft');
    }

    public function test_missing_api_key_returns_service_unavailable_without_calling_provider(): void
    {
        config(['simagang.ai.api_key' => null]);
        Http::fake();

        $this->actingAsStudent();

        $response = $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'not_configured');

        $this->assertStringNotContainsString('test-ai-key', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_successful_review_is_normalized_and_never_changes_journal_status(): void
    {
        $student = $this->actingAsStudent();
        $placement = $this->createPlacement(
            $student,
            $this->createCompany(),
            $this->createPeriod()
        );
        $journal = $this->createJournal($placement);

        Http::fake([
            'api.z.ai/*' => Http::response($this->providerResponse([
                'summary' => '  Membuat   endpoint API dan mengujinya.  ',
                'missing' => ['alat pengujian', str_repeat('x', 400), 123],
                'questions' => [
                    [
                        'id' => 'Alat Uji',
                        'question' => 'Alat apa yang dipakai untuk menguji endpoint?',
                        'hint' => 'Pilih semua yang sesuai',
                        'options' => ['Postman', 'Postman', 'curl'],
                    ],
                    ['question' => ''],
                ],
                'revised_activity' => '<b>Membuat endpoint API data siswa</b> menggunakan Laravel; diuji dengan Postman dan seluruh endpoint mengembalikan 200 OK.',
            ]), 200),
        ]);

        $response = $this->postJson(self::ENDPOINT, $this->payload())
            ->assertOk()
            ->assertJsonPath('data.summary', 'Membuat endpoint API dan mengujinya.')
            ->assertJsonPath('data.model', 'glm-5.3')
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.id', 'alat-uji')
            ->assertJsonPath('data.questions.0.options', ['Postman', 'curl'])
            ->assertJsonPath('data.missing.0', 'alat pengujian');

        $this->assertStringNotContainsString('<b>', $response->getContent());
        $this->assertLessThanOrEqual(900, mb_strlen($response->json('data.revised_activity')));

        // AI tidak boleh mengubah status atau isi jurnal.
        $this->assertSame('draft', $journal->fresh()->status);
        $this->assertSame(
            'Mengerjakan testing aplikasi',
            $journal->fresh()->activity
        );
    }

    public function test_provider_error_is_reported_as_unavailable(): void
    {
        Http::fake([
            'api.z.ai/*' => Http::response(['error' => ['message' => 'internal']], 500),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'unavailable');
    }

    public function test_provider_timeout_or_connection_failure_is_reported_as_unavailable(): void
    {
        Http::fake([
            'api.z.ai/*' => fn () => throw new ConnectionException('Timeout'),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'unavailable');
    }

    public function test_provider_quota_exhaustion_is_reported_clearly(): void
    {
        Http::fake([
            'api.z.ai/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'unavailable')
            ->assertJsonPath('message', 'Kuota layanan AI sedang habis. Coba lagi nanti atau lanjutkan jurnal secara manual.');
    }

    public function test_invalid_provider_output_is_rejected_with_bad_gateway(): void
    {
        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'bukan json']]],
            ], 200),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('code', 'invalid_response');
    }

    public function test_provider_json_without_revision_is_rejected(): void
    {
        Http::fake([
            'api.z.ai/*' => Http::response($this->providerResponse([
                'summary' => 'Ringkasan',
                'missing' => [],
                'questions' => [],
                'revised_activity' => '',
            ]), 200),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('code', 'invalid_response');
    }

    public function test_code_fenced_json_from_provider_is_accepted(): void
    {
        $content = "```json\n".json_encode([
            'summary' => 'Ringkasan jurnal.',
            'missing' => [],
            'questions' => [],
            'revised_activity' => 'Membuat endpoint API dengan Laravel lalu mengujinya menggunakan Postman.',
        ])."\n```";

        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
            ], 200),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertOk()
            ->assertJsonPath('data.summary', 'Ringkasan jurnal.');
    }

    public function test_rate_limit_applies_per_student(): void
    {
        config(['simagang.ai.rate_limit_per_minute' => 2]);

        Http::fake([
            'api.z.ai/*' => Http::response($this->providerResponse([
                'summary' => 'Ringkasan.',
                'missing' => [],
                'questions' => [],
                'revised_activity' => 'Membuat endpoint API dengan Laravel dan menguji hasilnya.',
            ]), 200),
        ]);

        $this->actingAsStudent();

        $this->postJson(self::ENDPOINT, $this->payload())->assertOk();
        $this->postJson(self::ENDPOINT, $this->payload())->assertOk();
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(429);
    }

    public function test_api_key_is_never_included_in_response_or_journal_payload(): void
    {
        $student = $this->actingAsStudent();
        $placement = $this->createPlacement(
            $student,
            $this->createCompany(),
            $this->createPeriod()
        );
        $this->createJournal($placement);

        Http::fake([
            'api.z.ai/*' => Http::response($this->providerResponse([
                'summary' => 'Ringkasan.',
                'missing' => [],
                'questions' => [],
                'revised_activity' => 'Membuat endpoint API dengan Laravel dan menguji hasilnya.',
            ]), 200),
        ]);

        $response = $this->postJson(self::ENDPOINT, $this->payload())->assertOk();

        $this->assertStringNotContainsString('test-ai-key', $response->getContent());

        Http::assertSent(function ($request) {
            // Kunci hanya ada di header Authorization, tidak di body.
            return $request->hasHeader('Authorization', 'Bearer test-ai-key')
                && ! str_contains($request->body(), 'test-ai-key');
        });
    }
}

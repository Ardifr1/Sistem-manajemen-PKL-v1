<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Pembuatan akun oleh admin: tanpa username & password manual, nomor HP
 * tervalidasi, informasi akun dikirim via email + WhatsApp berupa tautan
 * aktivasi (bukan password), dan pengaturan password lewat tautan tersebut.
 */
class UserAccountCreationTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    private function admin(): User
    {
        return $this->createUser('admin', 'admin_a');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Dewi Lestari',
            'email' => 'dewi@smk.sch.id',
            'phone' => '081234567890',
            'role' => 'student',
            'is_active' => true,
        ], $overrides);
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['token'] ?? '');
    }

    /** Respons sukses sesuai dokumentasi Fonnte. */
    private function fakeFonnteSuccess(): void
    {
        Http::fake(fn () => Http::response([
            'detail' => 'success! message in queue',
            'id' => ['80367170'],
            'process' => 'pending',
            'requestid' => 2937124,
            'status' => true,
            'target' => ['6281234567890'],
        ], 200));
    }

    /** Fonnte sering membalas HTTP 200 meski gagal. */
    private function fakeFonnteRejected(string $reason = 'target invalid', int $httpStatus = 200): void
    {
        Http::fake(fn () => Http::response([
            'status' => false,
            'reason' => $reason,
            'requestid' => 2937124,
        ], $httpStatus));
    }

    public function test_admin_creates_user_without_username_or_password(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        $user = User::where('email', 'dewi@smk.sch.id')->firstOrFail();

        // Username dibuat otomatis & password tidak pernah dikembalikan.
        $this->assertNotEmpty($user->username);
        $this->assertNull($response->json('data.password'));

        // Token aktivasi disimpan sebagai HASH (bukan teks biasa).
        $this->assertNotNull($user->password_setup_token);
        $this->assertNotNull($user->password_setup_expires_at);

        // Status pengiriman dilaporkan per kanal dan benar-benar sukses.
        $this->assertSame('sent', $response->json('delivery.email.status'));
        $this->assertSame('sent', $response->json('delivery.whatsapp.status'));

        Mail::assertSent(AccountActivationMail::class, function (AccountActivationMail $mail) {
            return $mail->hasTo('dewi@smk.sch.id')
                && str_contains($mail->activationUrl, '/aktivasi')
                && str_contains($mail->activationUrl, 'token=');
        });

        Http::assertSent(function ($request) {
            return $request->url() === config('services.fonnte.url')
                && $request->hasHeader('Authorization', 'test-fonnte-token')
                && (string) ($request['target'] ?? '') === '6281234567890'
                && str_contains((string) ($request['message'] ?? ''), 'aktivasi');
        });
    }

    public function test_phone_with_separators_is_normalized_before_storing(): void
    {
        Mail::fake();
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->payload([
            'phone' => '+62 812-3456-7890',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'dewi@smk.sch.id',
            'phone' => '6281234567890',
        ]);

        Http::assertSent(fn ($request) => (string) ($request['target'] ?? '') === '6281234567890');
    }

    public function test_activation_token_is_hashed_and_contains_no_plaintext_password(): void
    {
        Mail::fake();
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        /** @var AccountActivationMail $mail */
        $mail = null;
        Mail::assertSent(AccountActivationMail::class, function (AccountActivationMail $m) use (&$mail) {
            $mail = $m;

            return true;
        });

        $rawToken = $this->tokenFromUrl($mail->activationUrl);
        $user = User::where('email', 'dewi@smk.sch.id')->firstOrFail();

        // Token mentah TIDAK disimpan; yang tersimpan adalah hash-nya.
        $this->assertNotEmpty($rawToken);
        $this->assertNotSame($rawToken, $user->password_setup_token);
        $this->assertSame(hash('sha256', $rawToken), $user->password_setup_token);

        // Password di DB selalu berupa hash bcrypt.
        $this->assertStringStartsWith('$2y$', $user->password);

        // Pesan WhatsApp memuat tautan aktivasi, bukan password.
        Http::assertSent(function ($request) {
            $message = (string) ($request['message'] ?? '');

            return str_contains($message, '/aktivasi')
                && str_contains($message, 'password tidak pernah dikirim');
        });
    }

    public function test_whatsapp_reports_not_configured_without_token(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        $this->assertSame('not_configured', $response->json('delivery.whatsapp.status'));
        Http::assertNothingSent();
    }

    public function test_log_mailer_is_skipped_so_activation_token_is_not_written_to_log(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        config(['services.fonnte.token' => null]);

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        $this->assertSame('not_configured', $response->json('delivery.email.status'));
        $this->assertStringContainsString('MAIL_MAILER=log', $response->json('delivery.email.message'));
        Mail::assertNothingSent();
    }

    public function test_user_list_includes_phone_so_edit_form_does_not_erase_it(): void
    {
        Mail::fake();
        config(['services.fonnte.token' => null]);

        $target = $this->createUser('student', 'student_a');
        $target->update(['phone' => '6281234567890']);

        Sanctum::actingAs($this->admin());

        $rows = $this->getJson('/api/users')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $target->id);

        // Tanpa kolom phone di respons index, form edit akan mengirim null dan
        // menghapus nomor WhatsApp pengguna saat admin menyimpan perubahan.
        $this->assertSame('6281234567890', $row['phone']);
    }

    public function test_non_admin_user_list_does_not_expose_phone(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');
        $teacher->update(['phone' => '6281234567890']);

        Sanctum::actingAs($teacher);

        $rows = $this->getJson('/api/users')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $teacher->id);

        $this->assertArrayNotHasKey('phone', $row);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->payload([
            'email' => 'invalid-phone@smk.sch.id',
            'phone' => '12345',
        ]))->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('users', ['email' => 'invalid-phone@smk.sch.id']);
    }

    public function test_phone_is_optional(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/users', $this->payload([
            'email' => 'no-phone@smk.sch.id',
            'phone' => null,
        ]))->assertCreated();

        $this->assertSame('failed', $response->json('delivery.whatsapp.status'));
        $this->assertStringContainsString('Nomor HP', $response->json('delivery.whatsapp.message'));
        Http::assertNothingSent();
        $this->assertDatabaseHas('users', ['email' => 'no-phone@smk.sch.id', 'phone' => null]);
    }

    public function test_fonnte_rejection_is_reported_as_failed_and_user_is_kept(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        config(['services.fonnte.token' => 'test-fonnte-token']);
        $this->fakeFonnteRejected('target invalid');

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        // HTTP 200 dari Fonnte tidak boleh dianggap sukses.
        $this->assertSame('failed', $response->json('delivery.whatsapp.status'));
        $this->assertStringContainsString('target invalid', $response->json('delivery.whatsapp.message'));

        // Email tetap berhasil dan akun tidak hilang.
        $this->assertSame('sent', $response->json('delivery.email.status'));
        $this->assertDatabaseHas('users', ['email' => 'dewi@smk.sch.id']);
    }

    public function test_fonnte_http_error_is_reported_and_user_is_kept(): void
    {
        Mail::fake();
        config(['services.fonnte.token' => 'test-fonnte-token']);
        Http::fake(fn () => Http::response([
            'Status' => false,
            'reason' => 'token invalid',
        ], 401));

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        $this->assertSame('failed', $response->json('delivery.whatsapp.status'));
        $this->assertStringContainsString('401', $response->json('delivery.whatsapp.message'));
        $this->assertDatabaseHas('users', ['email' => 'dewi@smk.sch.id']);
    }

    public function test_fonnte_connection_failure_keeps_user_and_does_not_log_secrets(): void
    {
        Mail::fake();
        config(['services.fonnte.token' => 'test-fonnte-token']);
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        $this->assertSame('failed', $response->json('delivery.whatsapp.status'));
        $this->assertDatabaseHas('users', ['email' => 'dewi@smk.sch.id']);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            $logged = (string) json_encode($context);

            return ! str_contains($logged, 'test-fonnte-token')
                && ! str_contains($logged, '/aktivasi')
                && ! str_contains($logged, 'token=');
        });
    }

    public function test_fonnte_rejection_reason_is_redacted_before_logging(): void
    {
        Mail::fake();
        config(['services.fonnte.token' => 'test-fonnte-token']);
        Log::spy();

        $leakedUrl = 'https://app.test/aktivasi?token='.str_repeat('a', 64);
        $this->fakeFonnteRejected('invalid request for test-fonnte-token '.$leakedUrl);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            $logged = (string) json_encode($context);

            return ! str_contains($logged, 'test-fonnte-token')
                && ! str_contains($logged, 'https://')
                && ! str_contains($logged, str_repeat('a', 64));
        });
    }

    public function test_user_can_set_password_via_activation_link_and_login(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        /** @var AccountActivationMail $mail */
        $mail = null;
        Mail::assertSent(AccountActivationMail::class, function (AccountActivationMail $m) use (&$mail) {
            $mail = $m;

            return true;
        });
        $token = $this->tokenFromUrl($mail->activationUrl);

        // Token salah ditolak.
        $this->postJson('/api/set-password', [
            'email' => 'dewi@smk.sch.id',
            'token' => 'token-palsu',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertStatus(422);

        // Token benar → password diatur.
        $this->postJson('/api/set-password', [
            'email' => 'dewi@smk.sch.id',
            'token' => $token,
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertOk();

        $user = User::where('email', 'dewi@smk.sch.id')->firstOrFail();
        $this->assertNull($user->password_setup_token);
        $this->assertTrue(Hash::check('rahasia123', $user->password));

        // Login memakai email + password baru berhasil.
        $this->postJson('/api/login', [
            'username' => 'dewi@smk.sch.id',
            'password' => 'rahasia123',
        ])->assertOk();

        // Token sekali pakai: tidak bisa dipakai ulang.
        $this->postJson('/api/set-password', [
            'email' => 'dewi@smk.sch.id',
            'token' => $token,
            'password' => 'lain12345',
            'password_confirmation' => 'lain12345',
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');

        Sanctum::actingAs($teacher);

        $this->postJson('/api/users', $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'dewi@smk.sch.id']);
    }

    public function test_non_admin_cannot_resend_account_info(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');
        $target = $this->createUser('student', 'student_a');

        Sanctum::actingAs($teacher);

        $this->postJson("/api/users/{$target->id}/resend-account")->assertForbidden();
    }

    public function test_admin_can_resend_account_info_without_creating_duplicate(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        config(['services.fonnte.token' => 'test-fonnte-token']);
        $this->fakeFonnteSuccess();

        $target = $this->createUser('student', 'student_a');
        $oldHash = hash('sha256', 'token-lama');
        $target->update([
            'phone' => '081234567890',
            'password_setup_token' => $oldHash,
            'password_setup_expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->admin());
        $response = $this->postJson("/api/users/{$target->id}/resend-account")->assertOk();

        $this->assertSame('sent', $response->json('data.delivery.email.status'));
        $this->assertSame('sent', $response->json('data.delivery.whatsapp.status'));

        $fresh = $target->fresh();
        $this->assertSame(1, User::where('email', $target->email)->count());
        $this->assertNotSame($oldHash, $fresh->password_setup_token);
        $this->assertNotNull($fresh->password_setup_token);
        $this->assertTrue($fresh->password_setup_expires_at->isFuture());
    }

    public function test_resend_restores_previous_token_when_no_channel_succeeds(): void
    {
        Mail::fake();
        // Mailer array = email dilaporkan not_configured.
        config(['mail.default' => 'array']);
        config(['services.fonnte.token' => 'test-fonnte-token']);
        $this->fakeFonnteRejected('token invalid');

        $target = $this->createUser('student', 'student_a');
        $oldHash = hash('sha256', 'token-lama');
        $oldExpiry = now()->addDay();
        $target->update([
            'phone' => '081234567890',
            'password_setup_token' => $oldHash,
            'password_setup_expires_at' => $oldExpiry,
        ]);

        Sanctum::actingAs($this->admin());
        $response = $this->postJson("/api/users/{$target->id}/resend-account")->assertOk();

        $this->assertSame('not_configured', $response->json('data.delivery.email.status'));
        $this->assertSame('failed', $response->json('data.delivery.whatsapp.status'));

        $fresh = $target->fresh();
        $this->assertSame(1, User::where('email', $target->email)->count());
        $this->assertSame($oldHash, $fresh->password_setup_token);
        $this->assertSame($oldExpiry->timestamp, $fresh->password_setup_expires_at->timestamp);
    }
}

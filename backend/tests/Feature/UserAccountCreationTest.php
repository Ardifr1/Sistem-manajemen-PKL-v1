<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_admin_creates_user_without_username_or_password(): void
    {
        Mail::fake();
        Http::fake();
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

        // Status pengiriman dilaporkan per kanal.
        $this->assertNotNull($response->json('delivery.email'));
        $this->assertNotNull($response->json('delivery.whatsapp'));

        Mail::assertSent(AccountActivationMail::class, function (AccountActivationMail $mail) {
            return $mail->hasTo('dewi@smk.sch.id')
                && str_contains($mail->activationUrl, '/aktivasi')
                && str_contains($mail->activationUrl, 'token=');
        });

        Http::assertSent(function ($request) {
            return $request->url() === config('services.fonnte.url')
                && $request->hasHeader('Authorization', 'test-fonnte-token')
                && str_contains((string) ($request['message'] ?? ''), 'aktivasi');
        });
    }

    public function test_activation_token_is_hashed_and_contains_no_plaintext_password(): void
    {
        Mail::fake();
        Http::fake();
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
        $this->assertTrue(Hash::needsRehash($user->password) === false || str_starts_with($user->password, '$2y$'));
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

    public function test_invalid_phone_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->payload([
            'email' => 'invalid-phone@smk.sch.id',
            'phone' => '12345',
        ]))->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_phone_is_optional(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->payload([
            'email' => 'no-phone@smk.sch.id',
            'phone' => null,
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'no-phone@smk.sch.id', 'phone' => null]);
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

    public function test_non_admin_cannot_resend_account_info(): void
    {
        $teacher = $this->createUser('teacher', 'teacher_a');
        $target = $this->createUser('student', 'student_a');

        Sanctum::actingAs($teacher);

        $this->postJson("/api/users/{$target->id}/resend-account")->assertForbidden();
    }

    public function test_admin_can_resend_account_info(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        $target = $this->createUser('student', 'student_a');

        Sanctum::actingAs($this->admin());
        $response = $this->postJson("/api/users/{$target->id}/resend-account")->assertOk();

        $this->assertNotNull($response->json('data.delivery.email'));
        $this->assertNotNull($target->fresh()->password_setup_token);
    }
}

<?php

namespace Tests\Feature;

use App\Mail\AccountCredentialsMail;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ManagesPklTestData;
use Tests\TestCase;

/**
 * Alur password sementara: dibuat di backend saat kolom password dikosongkan,
 * disimpan sebagai hash, dikirim via WhatsApp/email, dan wajib diganti saat
 * login pertama — kewajiban yang ditegakkan di backend (middleware), bukan
 * hanya redirect frontend.
 *
 * Seluruh pengiriman WhatsApp di-mock di level HTTP (Http::fake); tidak ada
 * pesan sungguhan yang dikirim dari test otomatis.
 */
class TemporaryPasswordFlowTest extends TestCase
{
    use RefreshDatabase;
    use ManagesPklTestData;

    private function admin(): User
    {
        return $this->createUser('admin', 'admin_tp');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'email' => 'budi@smk.sch.id',
            'phone' => '081234567890',
            'role' => 'student',
            'is_active' => true,
        ], $overrides);
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

    /** Ambil password sementara TERAKHIR dari pesan WhatsApp yang terkirim. */
    private function tempPasswordFromWhatsApp(): ?string
    {
        $passwords = [];

        foreach (Http::recorded() as [$request]) {
            $message = (string) ($request['message'] ?? '');
            if (preg_match('/Password sementara: (\S+)/', $message, $m)) {
                $passwords[] = $m[1];
            }
        }

        return $passwords === [] ? null : end($passwords);
    }

    public function test_empty_password_generates_temporary_password(): void
    {
        Mail::fake();
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        $user = User::where('email', 'budi@smk.sch.id')->firstOrFail();

        // 1. Password kosong → password sementara dibuat di backend.
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertStringStartsWith('$2y$', $user->password);

        // 2. Hash tersimpan dengan benar: password yang dikirim cocok.
        $temp = $this->tempPasswordFromWhatsApp();
        $this->assertNotEmpty($temp);
        $this->assertTrue(Hash::check($temp, $user->password));
        $this->assertNotSame($temp, $user->password);
    }

    public function test_manual_password_is_not_overwritten(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        Sanctum::actingAs($this->admin());

        // Password manual admin dipakai apa adanya (tidak ada notifikasi).
        $response = $this->postJson('/api/users', $this->payload([
            'password' => 'manualSaya123',
        ]))->assertCreated();

        $this->assertNull($response->json('delivery'));

        $user = User::where('email', 'budi@smk.sch.id')->firstOrFail();
        $this->assertTrue(Hash::check('manualSaya123', $user->password));
        $this->assertTrue((bool) $user->must_change_password);

        // Login dengan password manual berhasil.
        $this->postJson('/api/login', [
            'username' => 'budi@smk.sch.id',
            'password' => 'manualSaya123',
        ])->assertOk();
    }

    public function test_first_login_requires_password_change_enforced_by_backend(): void
    {
        Mail::fake();
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        $temp = $this->tempPasswordFromWhatsApp();
        $this->assertNotEmpty($temp);

        // Login pertama berhasil, flag wajib ganti ikut dikembalikan.
        $login = $this->postJson('/api/login', [
            'username' => 'budi@smk.sch.id',
            'password' => $temp,
        ])->assertOk();
        $this->assertTrue((bool) $login->json('data.user.must_change_password'));

        // Endpoint lain TIDAK bisa diakses sebelum ganti password (403).
        Sanctum::actingAs(User::where('email', 'budi@smk.sch.id')->firstOrFail());
        $this->getJson('/api/company-proposals')->assertForbidden();

        // Ganti password → flag bersih.
        $this->postJson('/api/change-password', [
            'current_password' => $temp,
            'password' => 'passwordBaru123',
            'password_confirmation' => 'passwordBaru123',
        ])->assertOk();

        $this->assertFalse((bool) User::where('email', 'budi@smk.sch.id')->firstOrFail()->must_change_password);

        // Setelah ganti, endpoint lain bisa diakses.
        $this->getJson('/api/company-proposals')->assertOk();

        // Logout tetap bisa dilakukan kapan pun.
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_forced_change_password_works_without_current_password(): void
    {
        Mail::fake();
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        $user = User::where('email', 'budi@smk.sch.id')->firstOrFail();
        Sanctum::actingAs($user);

        // Tanpa current_password: sesi baru saja diautentikasi memakai
        // password sementara itu, jadi tidak perlu diketik ulang.
        $this->postJson('/api/change-password', [
            'password' => 'passwordBaru123',
        ])->assertOk();

        $user->refresh();
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue(Hash::check('passwordBaru123', $user->password));
    }

    public function test_forced_change_with_wrong_current_password_is_rejected(): void
    {
        Mail::fake();
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        $user = User::where('email', 'budi@smk.sch.id')->firstOrFail();
        Sanctum::actingAs($user);

        // Bila current_password DIKIRIM, nilainya tetap diverifikasi.
        $this->postJson('/api/change-password', [
            'current_password' => 'bukanPasswordnya1',
            'password' => 'passwordBaru123',
        ])->assertUnprocessable();

        $this->assertTrue((bool) $user->refresh()->must_change_password);
    }

    public function test_voluntary_change_password_still_requires_current_password(): void
    {
        $user = User::create([
            'name' => 'Pengguna Lama',
            'username' => 'pengguna_lama',
            'email' => 'lama@smk.sch.id',
            'password' => 'passwordLama123',
            'role' => 'student',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($user);

        // Tanpa current_password → ditolak (bukan alur paksa).
        $this->postJson('/api/change-password', [
            'password' => 'passwordBaru123',
        ])->assertUnprocessable();

        // Current password salah → ditolak.
        $this->postJson('/api/change-password', [
            'current_password' => 'salahTotal123',
            'password' => 'passwordBaru123',
        ])->assertUnprocessable();

        // Current password benar → berhasil.
        $this->postJson('/api/change-password', [
            'current_password' => 'passwordLama123',
            'password' => 'passwordBaru123',
        ])->assertOk();

        $this->assertTrue(Hash::check('passwordBaru123', $user->refresh()->password));
    }

    public function test_notification_failure_does_not_delete_created_account(): void
    {
        Mail::fake();
        // Fonnte menolak di level HTTP.
        Http::fake(fn () => Http::response([
            'status' => false,
            'reason' => 'device disconnected',
            'requestid' => 2937124,
        ], 200));
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/users', $this->payload())->assertCreated();

        // 6. Akun tetap tersimpan walau pengiriman gagal.
        $this->assertDatabaseHas('users', ['email' => 'budi@smk.sch.id']);
        $this->assertSame('failed', $response->json('delivery.whatsapp.status'));

        $user = User::where('email', 'budi@smk.sch.id')->firstOrFail();
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    public function test_resend_issues_new_temporary_password_without_duplicate(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->fakeFonnteSuccess();
        config(['services.fonnte.token' => 'test-fonnte-token']);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/users', $this->payload())->assertCreated();

        $first = $this->tempPasswordFromWhatsApp();
        $this->assertNotEmpty($first);

        Http::fake(fn () => Http::response([
            'status' => true,
            'detail' => 'success! message in queue',
        ], 200));

        $target = User::where('email', 'budi@smk.sch.id')->firstOrFail();

        // 7. Kirim ulang: tidak ada akun duplikat, password lama tidak berlaku.
        $this->postJson("/api/users/{$target->id}/resend-account")
            ->assertOk()
            ->assertJsonPath('data.delivery.whatsapp.status', 'sent');

        $this->assertSame(1, User::where('email', 'budi@smk.sch.id')->count());

        $second = $this->tempPasswordFromWhatsApp();
        $this->assertNotEmpty($second);
        $this->assertNotSame($first, $second);

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check($second, $fresh->password));
        $this->assertFalse(Hash::check($first, $fresh->password));

        // Login hanya bisa dengan password baru.
        $this->postJson('/api/login', [
            'username' => 'budi@smk.sch.id',
            'password' => $first,
        ])->assertStatus(401);
        $this->postJson('/api/login', [
            'username' => 'budi@smk.sch.id',
            'password' => $second,
        ])->assertOk();
    }

    public function test_resend_refused_when_account_already_has_own_password(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.fonnte.token' => null]);

        $target = $this->createUser('student', 'student_tp');
        $target->update([
            'phone' => '081234567890',
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($this->admin());

        // Akun yang sudah punya password sendiri: tidak dikirim ulang,
        // password yang ada tidak ikut ditimpa.
        $this->postJson("/api/users/{$target->id}/resend-account")->assertStatus(422);
        $this->assertTrue(Hash::check('password123', $target->fresh()->password));
    }

    public function test_temporary_password_generator_is_strong_and_unique(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $pwd = TemporaryPassword::generate();
            $this->assertSame(12, strlen($pwd));
            $this->assertMatchesRegularExpression('/[a-z]/', $pwd);
            $this->assertMatchesRegularExpression('/[A-Z]/', $pwd);
            $this->assertMatchesRegularExpression('/[0-9]/', $pwd);
            $this->assertMatchesRegularExpression('/[!@#$%&*?]/', $pwd);
            $this->assertArrayNotHasKey($pwd, $seen, 'Password sementara tidak boleh berulang.');
            $seen[$pwd] = true;
        }
    }

    public function test_legacy_login_and_logout_still_work(): void
    {
        $user = $this->createUser('teacher', 'teacher_tp');
        $user->update(['must_change_password' => false]);

        $login = $this->postJson('/api/login', [
            'username' => 'teacher_tp',
            'password' => 'password123',
        ])->assertOk();

        $this->assertNotEmpty($login->json('data.token'));

        Sanctum::actingAs($user);
        $this->postJson('/api/logout')->assertOk();
    }
}

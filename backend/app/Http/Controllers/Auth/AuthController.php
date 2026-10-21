<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Hash bcrypt dummy untuk menyamakan waktu respons saat akun tidak ada,
     * sehingga keberadaan akun tidak bisa ditebak lewat timing.
     */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        // Selalu jalankan pemeriksaan hash (walau akun tidak ada) agar waktu
        // respons tidak membocorkan apakah akun terdaftar.
        $passwordMatches = Hash::check(
            (string) $request->password,
            $user?->password ?? self::DUMMY_HASH
        );

        if (! $user || ! $passwordMatches) {
            return response()->json([
                'message' => 'Email/username atau password salah.'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun tidak aktif.'
            ], 403);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'must_change_password' => (bool) $user->must_change_password,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logout berhasil.'
        ]);
    }

    /**
     * Mengatur password awal lewat tautan aktivasi (email/WhatsApp).
     * Token bersifat sekali pakai dan disimpan sebagai hash.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $validated['email'])->first();
        $hashedToken = hash('sha256', $validated['token']);

        if (
            ! $user
            || ! $user->password_setup_token
            || ! hash_equals($user->password_setup_token, $hashedToken)
            || ! $user->password_setup_expires_at
            || $user->password_setup_expires_at->isPast()
        ) {
            return response()->json([
                'message' => 'Tautan aktivasi tidak valid atau sudah kadaluarsa.',
            ], 422);
        }

        // Cast 'hashed' pada model akan meng-hash password.
        $user->update([
            'password' => $validated['password'],
            'password_setup_token' => null,
            'password_setup_expires_at' => null,
            'must_change_password' => false,
            'activated_at' => now(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Password berhasil diatur. Silakan masuk menggunakan email Anda.',
        ]);
    }

    /**
     * Ganti password oleh pengguna yang sedang login.
     * Dipakai untuk kewajiban ganti password setelah login pertama
     * (akun yang password awalnya ditetapkan admin).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Password saat ini salah.',
            ], 422);
        }

        $user->update([
            'password' => $validated['password'],
            'must_change_password' => false,
            'activated_at' => $user->activated_at ?? now(),
        ]);

        // Password berubah: sesi/token lain harus berakhir. Token yang sedang
        // dipakai tetap dipertahankan agar pengguna tidak ter-logout sendiri.
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null;

        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        } else {
            $user->tokens()->delete();
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'account.password_changed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => ['via' => 'self_service'],
        ]);

        return response()->json([
            'message' => 'Password berhasil diganti.',
        ]);
    }

    /**
     * Permintaan reset password — publik. Admin menindaklanjuti
     * lewat halaman Pengguna (reset manual).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['username' => 'required|string']);

        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        // Selalu balas sukses agar tidak membocorkan akun yang terdaftar.
        if ($user && $user->is_active) {
            $exists = PasswordResetRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();
            if (!$exists) {
                PasswordResetRequest::create(['user_id' => $user->id]);
            }
        }

        return response()->json([
            'message' => 'Permintaan terkirim. Hubungi admin sekolah untuk mendapatkan password baru.',
        ]);
    }
}
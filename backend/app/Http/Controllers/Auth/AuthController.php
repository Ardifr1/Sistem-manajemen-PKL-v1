<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
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
        $request->user()->currentAccessToken()->delete();

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
        $user = $request->user();

        $validated = $request->validate([
            // Ganti password paksa (login pertama): password saat ini BOLEH
            // dikosongkan — sesi ini baru saja diautentikasi memakainya,
            // jadi mengetik ulang password acak dari WhatsApp hanya jadi
            // sumber kegagalan. Bila field dikirim, nilainya tetap
            // diverifikasi. Ganti password sukarela tetap mewajibkannya.
            'current_password' => [
                $user->must_change_password ? 'nullable' : 'required',
                'string',
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ]);

        if (
            ! empty($validated['current_password'])
            && ! Hash::check($validated['current_password'], $user->password)
        ) {
            return response()->json([
                'message' => 'Password saat ini salah.',
            ], 422);
        }

        if (
            ! empty($validated['current_password'])
            && Hash::check($validated['password'], $user->password)
        ) {
            return response()->json([
                'message' => 'Password baru tidak boleh sama dengan password saat ini.',
            ], 422);
        }

        $user->update([
            'password' => $validated['password'],
            'must_change_password' => false,
            'activated_at' => $user->activated_at ?? now(),
        ]);

        $user->tokens()->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'account.password_changed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => [
                'changed_by' => $user->id,
            ],
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
            $cutoff = now()->subHours(
                (int) config('simagang.password_reset.expires_hours', 72)
            );

            // Permintaan yang sudah lewat masa berlaku tidak boleh menahan
            // pengajuan baru; tandai kedaluwarsa lebih dulu.
            PasswordResetRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('created_at', '<', $cutoff)
                ->update(['status' => 'expired']);

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
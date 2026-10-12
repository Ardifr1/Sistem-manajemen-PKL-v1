<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
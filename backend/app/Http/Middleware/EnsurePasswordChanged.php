<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memaksa penggantian password sementara di sisi backend.
 *
 * Pengguna dengan flag must_change_password tidak dapat mengakses endpoint
 * terotentikasi apa pun (kecuali ganti password & logout) — kewajiban ini
 * tidak bisa dilewati hanya dengan mengakses URL lain secara langsung.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (bool) $user->must_change_password) {
            return response()->json([
                'message' => 'Anda wajib mengganti password sementara sebelum melanjutkan.',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}

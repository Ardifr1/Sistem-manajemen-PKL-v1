<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak request dari akun yang sudah dinonaktifkan.
 *
 * Pemeriksaan `is_active` di login saja tidak cukup: token Sanctum yang sudah
 * terbit tetap berlaku sampai dicabut. Middleware ini memastikan penonaktifan
 * akun langsung berlaku pada request berikutnya.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Token milik akun nonaktif tidak boleh dipakai lagi.
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Akun tidak aktif. Hubungi admin sekolah.',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Student;

use App\Exceptions\JournalAiException;
use App\Http\Controllers\Controller;
use App\Services\JournalAiAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalAssistantController extends Controller
{
    /**
     * Periksa draf jurnal siswa dengan AI.
     *
     * Endpoint ini hanya mengembalikan saran. Status jurnal TIDAK diubah dan
     * verifikasi tetap dilakukan pembimbing industri melalui alur yang ada.
     */
    public function review(Request $request, JournalAiAssistant $assistant): JsonResponse
    {
        // Siswa adalah satu-satunya pengguna asisten ini. Guru/pembimbing
        // memverifikasi jurnal lewat endpoint verifikasi, bukan asisten.
        if ($request->user()->role !== 'student') {
            abort(403);
        }

        $maxChars = (int) config('simagang.ai.max_draft_chars', 1500);

        $validated = $request->validate([
            'draft' => ['required', 'string', 'min:20', 'max:'.$maxChars],
        ]);

        try {
            $result = $assistant->review($validated['draft']);
        } catch (JournalAiException $e) {
            $status = $e->reason === JournalAiException::INVALID_RESPONSE ? 502 : 503;

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
                'data' => null,
            ], $status);
        }

        return response()->json([
            'message' => 'Saran AI berhasil dibuat. Periksa kembali sebelum diterapkan.',
            'data' => $result,
        ]);
    }
}

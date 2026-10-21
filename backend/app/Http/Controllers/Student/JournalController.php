<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\PklPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Journal::class);

        $validated = $request->validate([
            'placement_id' => [
                'nullable',
                'integer',
                'exists:pkl_placements,id',
            ],
        ]);

        $user = $request->user();
        $query = Journal::query()->orderByDesc('journal_date');

        if (! empty($validated['placement_id'])) {
            $placement = PklPlacement::findOrFail($validated['placement_id']);

            // Otorisasi per-penempatan mengikuti policy yang sama dengan
            // endpoint detail: siswa (pemilik), guru (bimbingan), perusahaan,
            // dan pembimbing industri (BR-09a). Sebelumnya pembimbing industri
            // dapat membaca jurnal penempatan mana pun lewat placement_id.
            if (! Gate::forUser($user)->check('view', $placement)) {
                abort(403);
            }

            $query->where('placement_id', $validated['placement_id']);
        } else {
            // Tanpa placement_id: batasi sesuai role
            $query->whereHas('placement', function ($q) use ($user) {
                if ($user->role === 'student') {
                    $q->where('student_id', $user->id);
                } elseif ($user->role === 'teacher') {
                    $q->where('teacher_id', $user->id);
                } elseif (in_array($user->role, ['company', 'supervisor'], true)) {
                    $companyId = $user->companySupervisor?->company_id;
                    if ($companyId) {
                        $q->where('company_id', $companyId);
                    } else {
                        $q->whereRaw('1 = 0'); // belum ditautkan: kosong
                    }
                }
                // admin: semua
            });
        }

        $journals = $query->get();

        return response()->json([
            'message' => 'Data jurnal berhasil diambil.',
            'data' => $journals,
        ]);
    }

    public function show(Journal $journal): JsonResponse
    {
        Gate::authorize('view', $journal);

        $journal->load('placement');

        return response()->json([
            'message' => 'Data jurnal berhasil diambil.',
            'data' => $journal,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Journal::class);

        $validated = $request->validate([
            'placement_id' => [
                'required',
                'integer',
                'exists:pkl_placements,id',
            ],
            'journal_date' => [
                'required',
                'date',
            ],
            'activity' => [
                'required',
                'string',
            ],
        ]);

        $placement = PklPlacement::findOrFail($validated['placement_id']);

        if (
            $request->user()->role === 'student'
            && $placement->student_id !== $request->user()->id
        ) {
            abort(403);
        }

        // Tanggal aktivitas jurnal harus berada dalam periode PKL placement.
        $journalDate = strtotime((string) $validated['journal_date']);

        if (
            $journalDate < strtotime((string) $placement->start_date)
            || $journalDate > strtotime((string) $placement->end_date)
        ) {
            return response()->json([
                'message' => 'Tanggal jurnal harus berada dalam periode PKL penempatan.',
            ], 422);
        }

        $duplicateJournal = Journal::query()
            ->where('placement_id', $validated['placement_id'])
            ->whereDate('journal_date', $validated['journal_date'])
            ->exists();

        if ($duplicateJournal) {
            return response()->json([
                'message' => 'Jurnal untuk tanggal tersebut sudah tersedia pada penempatan ini.',
            ], 422);
        }

        $journal = Journal::create([
            'placement_id' => $validated['placement_id'],
            'journal_date' => $validated['journal_date'],
            'activity' => $validated['activity'],
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Jurnal berhasil dibuat.',
            'data' => $journal,
        ], 201);
    }

    public function update(
        Request $request,
        Journal $journal
    ): JsonResponse {
        Gate::authorize('update', $journal);

        if ($journal->status !== 'draft') {
            return response()->json([
                'message' => 'Jurnal yang sudah dikirim/diverifikasi tidak dapat diubah.',
            ], 422);
        }

        $validated = $request->validate([
            'journal_date' => [
                'sometimes',
                'date',
            ],
            'activity' => [
                'sometimes',
                'string',
            ],
            'ai_suggestion' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'revised_activity' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ]);

        if (isset($validated['journal_date'])) {
            // Tanggal aktivitas jurnal harus berada dalam periode PKL placement.
            $placement = $journal->placement;
            $journalDate = strtotime((string) $validated['journal_date']);

            if (
                $journalDate < strtotime((string) $placement->start_date)
                || $journalDate > strtotime((string) $placement->end_date)
            ) {
                return response()->json([
                    'message' => 'Tanggal jurnal harus berada dalam periode PKL penempatan.',
                ], 422);
            }

            $duplicateJournal = Journal::query()
                ->where('placement_id', $journal->placement_id)
                ->whereDate('journal_date', $validated['journal_date'])
                ->where('id', '!=', $journal->id)
                ->exists();

            if ($duplicateJournal) {
                return response()->json([
                    'message' => 'Jurnal untuk tanggal tersebut sudah tersedia pada penempatan ini.',
                ], 422);
            }
        }

        $journal->update($validated);

        return response()->json([
            'message' => 'Jurnal berhasil diperbarui.',
            'data' => $journal->fresh(),
        ]);
    }

    public function submit(Journal $journal): JsonResponse
    {
        Gate::authorize('update', $journal);

        if ($journal->status !== 'draft') {
            return response()->json([
                'message' => 'Jurnal hanya dapat dikirim dari status draft.',
            ], 422);
        }

        $journal->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Jurnal berhasil dikirim untuk diverifikasi.',
            'data' => $journal->fresh(),
        ]);
    }

    /**
     * Verifikasi jurnal oleh pembimbing industri:
     * setujui (verified) atau minta revisi (needs_revision) + catatan.
     */
    public function verify(Request $request, Journal $journal): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'supervisor') {
            abort(403, 'Hanya pembimbing industri yang dapat memverifikasi jurnal.');
        }

        Gate::authorize('view', $journal);

        if ($journal->status !== 'submitted') {
            return response()->json([
                'message' => 'Hanya jurnal berstatus menunggu verifikasi yang dapat diproses.',
            ], 422);
        }

        $validated = $request->validate([
            'action' => [
                'required',
                'string',
                Rule::in(['verified', 'needs_revision']),
            ],
            'company_note' => [
                'nullable',
                'string',
            ],
        ]);

        $journal->update([
            'status' => $validated['action'],
            'company_note' => $validated['company_note'] ?? null,
            'verified_at' => $validated['action'] === 'verified' ? now() : null,
        ]);

        return response()->json([
            'message' => $validated['action'] === 'verified'
                ? 'Jurnal disetujui.'
                : 'Jurnal dikembalikan untuk direvisi.',
            'data' => $journal->fresh(),
        ]);
    }

    public function destroy(Journal $journal): JsonResponse
    {
        Gate::authorize('delete', $journal);

        if ($journal->status !== 'draft') {
            return response()->json([
                'message' => 'Jurnal yang sudah dikirim/diverifikasi tidak dapat dihapus.',
            ], 422);
        }

        $journal->delete();

        return response()->json([
            'message' => 'Jurnal berhasil dihapus.',
        ]);
    }
}
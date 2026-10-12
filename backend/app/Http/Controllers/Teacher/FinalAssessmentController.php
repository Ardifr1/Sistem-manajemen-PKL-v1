<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentComponent;
use App\Models\FinalAssessment;
use App\Models\FinalAssessmentStatusLog;
use App\Models\PklPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FinalAssessmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', FinalAssessment::class);

        $validated = $request->validate([
            'placement_id' => [
                'nullable',
                'integer',
                'exists:pkl_placements,id',
            ],
        ]);

        $query = FinalAssessment::query()
            ->with([
                'placement.student',
                'placement.company',
                'publisher',
            ])
            ->orderByDesc('finalized_at');

        if (isset($validated['placement_id'])) {
            $query->where(
                'placement_id',
                $validated['placement_id']
            );
        }

        // Batasi ke penempatan yang boleh dilihat user.
        $user = $request->user();
        $query->whereHas('placement', function ($q) use ($user) {
            if ($user->role === 'student') {
                $q->where('student_id', $user->id);
            } elseif ($user->role === 'teacher') {
                $q->where('teacher_id', $user->id);
            } elseif ($user->role === 'supervisor') {
                $q->where('supervisor_id', $user->companySupervisor?->id);
            } elseif ($user->role === 'company') {
                $q->where('company_id', $user->companySupervisor?->company_id);
            }
            // admin: semua
        });

        return response()->json([
            'message' => 'Data penilaian akhir berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(FinalAssessment $finalAssessment): JsonResponse
    {
        Gate::authorize('view', $finalAssessment);

        $finalAssessment->load([
            'placement.student',
            'placement.company',
            'publisher',
            'statusLogs.changer',
        ]);

        return response()->json([
            'message' => 'Data penilaian akhir berhasil diambil.',
            'data' => $finalAssessment,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', FinalAssessment::class);

        $validated = $request->validate([
            'placement_id' => [
                'required',
                'integer',
                'exists:pkl_placements,id',
            ],
            'final_score' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,2',
            ],
            'status' => [
                'required',
                'string',
                'max:50',
                'in:draft,published',
            ],
            'finalized_by' => [
                'sometimes',
                'integer',
                'exists:users,id',
            ],
        ]);

        // Penilaian akhir selalu tercatat atas nama user yang sedang login.
        $validated['finalized_by'] = $request->user()->id;

        $placement = \App\Models\PklPlacement::findOrFail($validated['placement_id']);

        if (! Gate::forUser($request->user())->check('view', $placement)) {
            abort(403);
        }

        $existingAssessment = FinalAssessment::query()
            ->where(
                'placement_id',
                $validated['placement_id']
            )
            ->exists();

        if ($existingAssessment) {
            return response()->json([
                'message' => 'Penilaian akhir untuk penempatan tersebut sudah tersedia.',
            ], 422);
        }

        $finalAssessment = FinalAssessment::create([
            ...$validated,
            'finalized_at' => now(),
        ]);

        $finalAssessment->load([
            'placement',
        ]);

        return response()->json([
            'message' => 'Penilaian akhir berhasil disimpan.',
            'data' => $finalAssessment,
        ], 201);
    }

    public function update(
        Request $request,
        FinalAssessment $finalAssessment
    ): JsonResponse {
        Gate::authorize('update', $finalAssessment);

        // Nilai yang sudah dipublikasikan tidak boleh diubah diam-diam.
        if ($finalAssessment->status === 'published') {
            return response()->json([
                'message' => 'Nilai sudah dipublikasikan. Batalkan publikasi dulu untuk mengubah.',
            ], 422);
        }

        // final_score hanya boleh diubah lewat perhitungan otomatis (calculate),
        // bukan dari nilai yang dikirim frontend.
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'string',
                'max:50',
                'in:draft',
            ],
        ]);

        $finalAssessment->update($validated);

        $finalAssessment->load([
            'placement',
        ]);

        return response()->json([
            'message' => 'Penilaian akhir berhasil diperbarui.',
            'data' => $finalAssessment,
        ]);
    }

    /**
     * Hitung nilai akhir otomatis dari bobot komponen admin.
     * final = Σ(nilai × bobot) / Σ(bobot komponen yang sudah dinilai).
     * Hanya bila semua komponen aktif sudah ada nilainya.
     */
    public function calculate(Request $request): JsonResponse
    {
        Gate::authorize('create', FinalAssessment::class);

        $validated = $request->validate([
            'placement_id' => ['required', 'integer', 'exists:pkl_placements,id'],
        ]);

        $placement = PklPlacement::findOrFail($validated['placement_id']);

        if (! Gate::forUser($request->user())->check('view', $placement)) {
            abort(403);
        }

        $existing = FinalAssessment::where('placement_id', $placement->id)->first();
        if ($existing && $existing->status === 'published') {
            return response()->json([
                'message' => 'Nilai akhir sudah dipublikasikan dan tidak dapat dihitung ulang.',
            ], 422);
        }

        $components = AssessmentComponent::where('is_active', true)->orderBy('id')->get();
        if ($components->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada komponen penilaian aktif yang diatur admin.',
            ], 422);
        }

        // Validasi bobot: total bobot komponen aktif harus 100.
        $totalWeight = (float) $components->sum('weight');
        if (abs($totalWeight - 100) > 0.01) {
            return response()->json([
                'message' => "Total bobot komponen aktif harus 100% (saat ini {$totalWeight}%). Minta admin memperbaiki di menu Komponen Penilaian.",
            ], 422);
        }

        $assessments = Assessment::where('placement_id', $placement->id)->get();

        $breakdown = [];
        $missing = [];
        $weightedSum = 0;
        $weightSum = 0;

        foreach ($components as $component) {
            $score = $assessments->firstWhere('component_id', $component->id);
            if (! $score) {
                $missing[] = $component->name;
                continue;
            }
            $w = (float) $component->weight;
            $s = (float) $score->score;
            $weightedSum += $s * $w;
            $weightSum += $w;
            $breakdown[] = [
                'component_id' => $component->id,
                'component_name' => $component->name,
                'assessor_role' => $component->assessor_role,
                'score' => $s,
                'weight' => $w,
            ];
        }

        if (! empty($missing)) {
            return response()->json([
                'message' => 'Belum semua komponen dinilai.',
                'missing' => $missing,
            ], 422);
        }

        // Normalisasi bobot: bila total bobot != 100, tetap jadi rata-rata tertimbang yang benar.
        $finalScore = $weightSum > 0 ? round($weightedSum / $weightSum, 2) : 0;
        foreach ($breakdown as &$row) {
            $row['contribution'] = $weightSum > 0 ? round($row['score'] * $row['weight'] / $weightSum, 2) : 0;
        }
        unset($row);

        $final = FinalAssessment::updateOrCreate(
            ['placement_id' => $placement->id],
            [
                'final_score' => $finalScore,
                'status' => 'draft',
                'finalized_by' => $request->user()->id,
                'finalized_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Nilai akhir berhasil dihitung otomatis.',
            'data' => [
                'final_assessment' => $final->fresh(),
                'final_score' => $finalScore,
                'breakdown' => $breakdown,
            ],
        ]);
    }

    /**
     * Publikasikan nilai akhir — siswa baru bisa melihat setelah ini
     * dan status penempatan sudah selesai (completed).
     */
    public function publish(Request $request, FinalAssessment $finalAssessment): JsonResponse
    {
        Gate::authorize('publish', FinalAssessment::class);

        $placement = $finalAssessment->placement;
        if (! $placement || $placement->status !== 'completed') {
            return response()->json([
                'message' => 'Nilai hanya dapat dipublikasikan setelah PKL dinyatakan selesai.',
            ], 422);
        }

        if ($finalAssessment->status === 'published') {
            return response()->json([
                'message' => 'Nilai akhir sudah dipublikasikan.',
            ], 422);
        }

        // Pastikan perhitungan masih valid (semua komponen aktif terisi).
        $components = AssessmentComponent::where('is_active', true)->get();
        $assessedIds = Assessment::where('placement_id', $finalAssessment->placement_id)
            ->pluck('component_id')
            ->unique();
        $missing = $components->reject(fn ($c) => $assessedIds->contains($c->id))->pluck('name');
        if ($missing->isNotEmpty()) {
            return response()->json([
                'message' => 'Perhitungan belum valid.',
                'missing' => $missing->values(),
            ], 422);
        }

        $finalAssessment->update([
            'status' => 'published',
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ]);

        FinalAssessmentStatusLog::create([
            'final_assessment_id' => $finalAssessment->id,
            'action' => 'published',
            'changed_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Nilai akhir dipublikasikan. Siswa kini dapat melihat nilainya.',
            'data' => $finalAssessment->fresh(['publisher']),
        ]);
    }

    /**
     * Batalkan publikasi — nilai kembali draf dan tidak tampil ke siswa.
     * Tercatat di log audit.
     */
    public function unpublish(Request $request, FinalAssessment $finalAssessment): JsonResponse
    {
        Gate::authorize('unpublish', $finalAssessment);

        if ($finalAssessment->status !== 'published') {
            return response()->json([
                'message' => 'Nilai akhir belum dipublikasikan.',
            ], 422);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $finalAssessment->update([
            'status' => 'draft',
            'published_by' => null,
            'published_at' => null,
        ]);

        FinalAssessmentStatusLog::create([
            'final_assessment_id' => $finalAssessment->id,
            'action' => 'unpublished',
            'changed_by' => $request->user()->id,
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'Publikasi dibatalkan. Nilai tidak lagi ditampilkan ke siswa.',
            'data' => $finalAssessment->fresh(),
        ]);
    }

    public function destroy(FinalAssessment $finalAssessment): JsonResponse
    {
        Gate::authorize('delete', $finalAssessment);

        $finalAssessment->delete();

        return response()->json([
            'message' => 'Penilaian akhir berhasil dihapus.',
        ]);
    }
}
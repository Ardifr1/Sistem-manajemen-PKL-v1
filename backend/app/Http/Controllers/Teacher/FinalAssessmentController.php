<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FinalAssessment;
use App\Models\AssessmentComponent;
use App\Models\FinalAssessmentStatusLog;
use App\Models\PklPlacement;
use App\Services\FinalScoreService;
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
            $placement = PklPlacement::findOrFail($validated['placement_id']);

            if (! Gate::forUser($request->user())->check('view', $placement)) {
                abort(403);
            }

            $query->where(
                'placement_id',
                $validated['placement_id']
            );
        } else {
            $user = $request->user();
            $query->whereHas('placement', function ($q) use ($user) {
                if ($user->role === 'student') {
                    // Siswa hanya melihat nilai yang sudah dipublikasikan.
                    $q->where('student_id', $user->id)
                        ->whereHas('finalAssessment', function ($f) {
                            $f->where('status', 'published');
                        });
                } elseif ($user->role === 'teacher') {
                    $q->where('teacher_id', $user->id);
                }
                // admin: semua
            });
        }

        // Batasi ke penempatan yang boleh dilihat user.
        $user = $request->user();
        $query->whereHas('placement', function ($q) use ($user) {
            if ($user->role === 'student') {
                $q->where('student_id', $user->id);
            } elseif ($user->role === 'teacher') {
                $q->where('teacher_id', $user->id);
            } elseif ($user->role === 'supervisor') {
                $supervisor = $user->companySupervisor;
                if ($supervisor) {
                    $q->where(function ($placementQuery) use ($supervisor) {
                        $placementQuery->where('supervisor_id', $supervisor->id)
                            ->orWhere(function ($fallbackQuery) use ($supervisor) {
                                $fallbackQuery->whereNull('supervisor_id')
                                    ->where('company_id', $supervisor->company_id);
                            });
                    });
                } else {
                    $q->whereRaw('1 = 0');
                }
            } elseif ($user->role === 'company') {
                $companyId = $user->companySupervisor?->company_id;
                $companyId
                    ? $q->where('company_id', $companyId)
                    : $q->whereRaw('1 = 0');
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

    /**
     * Hitung nilai akhir oleh backend. Frontend TIDAK boleh mengirim
     * final_score bebas — nilai selalu dihitung dari komponen aktif.
     */
    public function store(Request $request, FinalScoreService $calculator): JsonResponse
    {
        Gate::authorize('create', FinalAssessment::class);

        $validated = $request->validate([
            'placement_id' => [
                'required',
                'integer',
                'exists:pkl_placements,id',
            ],
        ]);

        $placement = PklPlacement::findOrFail($validated['placement_id']);

        if (! Gate::forUser($request->user())->check('view', $placement)) {
            abort(403);
        }

        $result = $calculator->recalculate($placement, $request->user()->id);

        if (! ($result['ok'] ?? false)) {
            $response = [
                'message' => $result['message'] ?? 'Perhitungan nilai akhir gagal.',
            ];
            if (array_key_exists('missing', $result)) {
                $response['missing'] = $result['missing'];
            }

            return response()->json($response, 422);
        }

        /** @var FinalAssessment $finalAssessment */
        $finalAssessment = $result['final_assessment'];
        $finalAssessment->load(['placement']);
        $data = $finalAssessment->toArray();
        $data['final_score'] = (float) $finalAssessment->final_score;

        return response()->json([
            'message' => 'Nilai akhir berhasil dihitung oleh sistem.',
            'data' => $data,
            'detail' => $result['detail'] ?? [],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Hitung ulang nilai akhir (draft). Nilai published tidak boleh diubah.
     */
    public function update(
        Request $request,
        FinalAssessment $finalAssessment,
        FinalScoreService $calculator
    ): JsonResponse {
        Gate::authorize('update', $finalAssessment);

        if ($finalAssessment->status === 'published') {
            return response()->json([
                'message' => 'Nilai akhir yang sudah dipublikasikan tidak dapat diubah. Batalkan publikasi terlebih dahulu.',
            ], 422);
        }

        $request->validate([
            'recalculate' => ['sometimes', 'boolean'],
        ]);

        $placement = $finalAssessment->placement ?? PklPlacement::findOrFail($finalAssessment->placement_id);
        $result = $calculator->recalculate($placement, $request->user()->id);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'Perhitungan ulang nilai akhir gagal.',
            ], 422);
        }

        $finalAssessment->refresh()->load(['placement']);

        return response()->json([
            'message' => 'Nilai akhir berhasil dihitung ulang.',
            'data' => $finalAssessment,
            'detail' => $result['detail'] ?? [],
        ]);
    }

    public function calculate(Request $request, FinalScoreService $calculator): JsonResponse
    {
        return $this->store($request, $calculator);
    }

    public function destroy(FinalAssessment $finalAssessment): JsonResponse
    {
        Gate::authorize('delete', $finalAssessment);

        if ($finalAssessment->status === 'published') {
            return response()->json([
                'message' => 'Nilai akhir yang sudah dipublikasikan tidak dapat dihapus. Batalkan publikasi terlebih dahulu.',
            ], 422);
        }

        $finalAssessment->delete();

        return response()->json([
            'message' => 'Penilaian akhir berhasil dihapus.',
        ]);
    }

    /**
     * Publikasi nilai akhir — hanya Admin. Syarat: PKL siswa berstatus
     * selesai (completed) dan perhitungan valid (bobot 100%, komponen lengkap).
     */
    public function publish(Request $request, FinalAssessment $finalAssessment, FinalScoreService $calculator): JsonResponse
    {
        Gate::authorize('publish', FinalAssessment::class);

        $placement = $finalAssessment->placement ?? PklPlacement::findOrFail($finalAssessment->placement_id);

        if ($placement->status !== 'completed') {
            return response()->json([
                'message' => 'Nilai hanya dapat dipublikasikan jika PKL siswa sudah berstatus selesai.',
            ], 422);
        }

        if ($finalAssessment->status === 'published') {
            return response()->json([
                'message' => 'Nilai akhir sudah dipublikasikan.',
            ], 422);
        }

        // Validate the calculation whenever components are configured. Legacy
        // records can still be published when the system has no active components.
        $hasActiveComponents = AssessmentComponent::query()
            ->where('is_active', true)
            ->exists();
        if ($hasActiveComponents) {
            $check = $calculator->calculate($placement);

            if (! ($check['ok'] ?? false)) {
                return response()->json([
                    'message' => $check['message'] ?? 'Perhitungan nilai akhir tidak valid.',
                    'missing' => $check['missing'] ?? [],
                ], 422);
            }
        } else {
            $check = ['final_score' => $finalAssessment->final_score];
        }

        $oldStatus = $finalAssessment->status;

        $finalAssessment->update([
            'final_score' => $check['final_score'],
            'status' => 'published',
            'finalized_at' => $finalAssessment->finalized_at ?? now(),
            'published_by' => $request->user()->id,
            'published_at' => now(),
            'unpublish_reason' => null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'publish',
            'placement_id' => $placement->id,
            'final_assessment_id' => $finalAssessment->id,
            'old_status' => $oldStatus,
            'new_status' => 'published',
            'reason' => null,
        ]);

        FinalAssessmentStatusLog::create([
            'final_assessment_id' => $finalAssessment->id,
            'action' => 'published',
            'changed_by' => $request->user()->id,
        ]);

        $finalAssessment->refresh()->load(['placement']);

        return response()->json([
            'message' => 'Nilai akhir berhasil dipublikasikan.',
            'data' => $finalAssessment,
        ]);
    }

    /**
     * Batalkan publikasi — hanya Admin, wajib alasan. Status kembali ke
     * draft, audit log dicatat, nilai disembunyikan dari siswa.
     */
    public function unpublish(Request $request, FinalAssessment $finalAssessment): JsonResponse
    {
        Gate::authorize('unpublish', $finalAssessment);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'],
            'note' => ['nullable', 'string', 'min:5', 'max:1000'],
        ]);

        if ($finalAssessment->status !== 'published') {
            return response()->json([
                'message' => 'Hanya nilai yang sudah dipublikasikan yang dapat dibatalkan.',
            ], 422);
        }

        $reason = $validated['reason'] ?? $validated['note'] ?? null;
        $oldStatus = $finalAssessment->status;

        $finalAssessment->update([
            'status' => 'draft',
            'published_by' => null,
            'published_at' => null,
            'unpublish_reason' => $reason,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'unpublish',
            'placement_id' => $finalAssessment->placement_id,
            'final_assessment_id' => $finalAssessment->id,
            'old_status' => $oldStatus,
            'new_status' => 'draft',
            'reason' => $reason,
        ]);

        FinalAssessmentStatusLog::create([
            'final_assessment_id' => $finalAssessment->id,
            'action' => 'unpublished',
            'changed_by' => $request->user()->id,
            'note' => $reason,
        ]);

        $finalAssessment->refresh()->load(['placement']);

        return response()->json([
            'message' => 'Publikasi nilai akhir berhasil dibatalkan.',
            'data' => $finalAssessment,
        ]);
    }
}

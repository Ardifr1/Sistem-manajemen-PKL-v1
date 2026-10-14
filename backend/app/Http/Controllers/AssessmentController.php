<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentComponent;
use App\Models\PklPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssessmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Assessment::class);

        $validated = $request->validate([
            'placement_id' => [
                'nullable',
                'integer',
                'exists:pkl_placements,id',
            ],
        ]);

        $query = Assessment::query()
            ->with([
                'placement',
                'component',
            ]);

        if (! empty($validated['placement_id'])) {
            $placement = PklPlacement::findOrFail($validated['placement_id']);

            if (! Gate::forUser($request->user())->check('view', $placement)) {
                abort(403);
            }

            $query->where('placement_id', $validated['placement_id']);
        } else {
            // Tanpa placement_id: batasi ke placement yang boleh dilihat user
            $user = $request->user();
            $query->whereHas('placement', function ($q) use ($user) {
                if ($user->role === 'student') {
                    $q->where('student_id', $user->id);
                } elseif ($user->role === 'teacher') {
                    $q->where('teacher_id', $user->id);
                } elseif ($user->role === 'supervisor') {
                    $supervisor = $user->companySupervisor;
                    if ($supervisor) {
                        $q->where(function ($w) use ($supervisor) {
                            $w->where('supervisor_id', $supervisor->id)
                                ->orWhere(function ($x) use ($supervisor) {
                                    $x->whereNull('supervisor_id')
                                        ->where('company_id', $supervisor->company_id);
                                });
                        });
                    } else {
                        $q->whereRaw('1 = 0');
                    }
                } elseif ($user->role === 'company') {
                    // Scope index company ke perusahaannya sendiri, konsisten
                    // dengan AssessmentPolicy::view (per-placement).
                    $companyId = $user->companySupervisor?->company_id;
                    if ($companyId) {
                        $q->where('company_id', $companyId);
                    } else {
                        $q->whereRaw('1 = 0');
                    }
                }
                // admin: semua
            });
        }

        $assessments = $query->orderBy('component_id')->get();

        return response()->json([
            'message' => 'Data penilaian PKL berhasil diambil.',
            'data' => $assessments,
        ]);
    }

    public function show(Assessment $assessment): JsonResponse
    {
        Gate::authorize('view', $assessment);

        $assessment->load([
            'placement',
            'component',
        ]);

        return response()->json([
            'message' => 'Data penilaian PKL berhasil diambil.',
            'data' => $assessment,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Assessment::class);

        $validated = $request->validate([
            'placement_id' => [
                'required',
                'integer',
                'exists:pkl_placements,id',
            ],
            'component_id' => [
                'required',
                'integer',
                'exists:assessment_components,id',
            ],
            'score' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,2',
            ],
            'note' => [
                'nullable',
                'string',
            ],
        ]);

        $user = $request->user();
        $placement = PklPlacement::findOrFail($validated['placement_id']);
        $component = AssessmentComponent::findOrFail($validated['component_id']);

        // Komponen nonaktif tidak boleh dinilai.
        if (! $component->is_active) {
            return response()->json([
                'message' => 'Komponen penilaian sudah tidak aktif.',
            ], 422);
        }

        // Guru Pembimbing hanya boleh mengisi komponen milik guru.
        if ($user->role === 'teacher' && $component->owner_type !== 'guru') {
            return response()->json([
                'message' => 'Guru hanya boleh mengisi komponen penilaian milik Guru Pembimbing.',
            ], 403);
        }

        // Pembimbing Industri (role company/supervisor) hanya boleh mengisi
        // komponen milik industri.
        if (in_array($user->role, ['company', 'supervisor'], true) && $component->owner_type !== 'industri') {
            return response()->json([
                'message' => 'Pembimbing Industri hanya boleh mengisi komponen penilaian milik Industri.',
            ], 403);
        }

        // Pembimbing Industri (role supervisor) hanya boleh menilai siswa
        // yang ditugaskan kepadanya.
        if ($user->role === 'supervisor') {
            $supervisor = $user->companySupervisor;

            if (! $supervisor) {
                abort(403);
            }

            if ($placement->supervisor_id !== null) {
                if ((int) $placement->supervisor_id !== (int) $supervisor->id) {
                    abort(403);
                }
            } elseif ((int) $placement->company_id !== (int) $supervisor->company_id) {
                abort(403);
            }
        } elseif (! Gate::forUser($user)->check('view', $placement)) {
            abort(403);
        }

        // Penilaian selalu tercatat atas nama penilai yang sedang login.
        // assessor_role dikunci dari role login — request tidak boleh
        // memalsukannya.
        $validated['assessed_by'] = $user->id;
        $validated['assessor_role'] = $user->role;

        $existingAssessment = Assessment::query()
            ->where('placement_id', $validated['placement_id'])
            ->where('component_id', $validated['component_id'])
            ->where('assessed_by', $validated['assessed_by'])
            ->exists();

        if ($existingAssessment) {
            return response()->json([
                'message' => 'Penilaian untuk komponen tersebut sudah diberikan oleh penilai ini.',
            ], 422);
        }

        $assessment = Assessment::create($validated);

        $assessment->load([
            'placement',
            'component',
        ]);

        return response()->json([
            'message' => 'Penilaian PKL berhasil disimpan.',
            'data' => $assessment,
        ], 201);
    }

    public function update(
        Request $request,
        Assessment $assessment
    ): JsonResponse {
        Gate::authorize('update', $assessment);

        $validated = $request->validate([
            'score' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,2',
            ],
            'note' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ]);

        $assessment->update($validated);

        $assessment->load([
            'placement',
            'component',
        ]);

        return response()->json([
            'message' => 'Penilaian PKL berhasil diperbarui.',
            'data' => $assessment,
        ]);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        Gate::authorize('delete', $assessment);

        $assessment->delete();

        return response()->json([
            'message' => 'Penilaian PKL berhasil dihapus.',
        ]);
    }
}

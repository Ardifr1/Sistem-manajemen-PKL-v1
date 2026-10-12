<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
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
                    $supervisorId = $user->companySupervisor?->id;
                    $q->where('supervisor_id', $supervisorId);
                } elseif ($user->role === 'company') {
                    $companyId = $user->companySupervisor?->company_id;
                    $q->where('company_id', $companyId);
                }
                // admin: semua (Gate viewAny sudah mengatur akses)
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

        // Penilaian selalu tercatat atas nama penilai yang sedang login.
        $validated['assessed_by'] = $request->user()->id;
        $validated['assessor_role'] = $request->user()->role;

        $placement = PklPlacement::findOrFail($validated['placement_id']);

        if (! Gate::forUser($request->user())->check('view', $placement)) {
            abort(403);
        }

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
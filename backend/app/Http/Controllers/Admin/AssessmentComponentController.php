<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssessmentComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssessmentComponentController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AssessmentComponent::class);

        $components = AssessmentComponent::query()
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Data komponen penilaian berhasil diambil.',
            'data' => $components,
        ]);
    }

    public function show(
        AssessmentComponent $assessmentComponent
    ): JsonResponse {
        Gate::authorize('view', $assessmentComponent);

        return response()->json([
            'message' => 'Data komponen penilaian berhasil diambil.',
            'data' => $assessmentComponent,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', AssessmentComponent::class);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:assessment_components,name',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'weight' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'assessor_role' => [
                'required',
                'string',
                Rule::in(['teacher', 'supervisor']),
            ],
            'is_active' => [
                'boolean',
            ],
        ]);

        $component = AssessmentComponent::create($validated);

        return response()->json([
            'message' => 'Komponen penilaian berhasil ditambahkan.',
            'data' => $component,
        ], 201);
    }

    public function update(
        Request $request,
        AssessmentComponent $assessmentComponent
    ): JsonResponse {
        Gate::authorize('update', $assessmentComponent);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('assessment_components', 'name')
                    ->ignore($assessmentComponent->id),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'weight' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:100',
            ],
            'assessor_role' => [
                'sometimes',
                'string',
                Rule::in(['teacher', 'supervisor']),
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $assessmentComponent->update($validated);

        return response()->json([
            'message' => 'Komponen penilaian berhasil diperbarui.',
            'data' => $assessmentComponent->fresh(),
        ]);
    }

    public function destroy(
        AssessmentComponent $assessmentComponent
    ): JsonResponse {
        Gate::authorize('delete', $assessmentComponent);

        $assessmentComponent->delete();

        return response()->json([
            'message' => 'Komponen penilaian berhasil dihapus.',
        ]);
    }
}
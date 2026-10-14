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
            'owner_type' => [
                'sometimes',
                'string',
                'in:industri,guru',
            ],
            'weight' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'is_active' => [
                'boolean',
            ],
        ]);

        $component = AssessmentComponent::create($validated);

        $weightError = $this->validateTotalWeight();

        if ($weightError !== null) {
            // Perubahan dibatalkan agar total bobot tidak rusak.
            $component->delete();

            return response()->json([
                'message' => $weightError,
            ], 422);
        }

        return response()->json([
            'message' => 'Komponen penilaian berhasil ditambahkan.',
            'data' => $component->fresh(),
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
            'owner_type' => [
                'sometimes',
                'string',
                'in:industri,guru',
            ],
            'weight' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:100',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $original = $assessmentComponent->getAttributes();

        $assessmentComponent->update($validated);

        $weightError = $this->validateTotalWeight();

        if ($weightError !== null) {
            $assessmentComponent->fill($original);
            $assessmentComponent->save();

            return response()->json([
                'message' => $weightError,
            ], 422);
        }

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

    /**
     * Total bobot komponen AKTIF yang dipakai perhitungan harus tepat 100%.
     * Pengecualian: boleh belum 100 selama belum ada komponen aktif sama
     * sekali? Tidak — aturan bisnis tegas: total aktif harus 100. Namun saat
     * admin sedang menyusun bertahap, izinkan state transien 0 komponen
     * aktif atau total <= 100; tolak total > 100 dan tolak finalisasi
     * perhitungan bila bukan tepat 100 (dicek di FinalScoreService).
     */
    private function validateTotalWeight(): ?string
    {
        $total = (float) AssessmentComponent::query()
            ->where('is_active', true)
            ->sum('weight');

        if ($total - 100.0 > 0.01) {
            return 'Total bobot komponen aktif melebihi 100%. Saat ini '.$this->formatWeight($total).'%.';
        }

        return null;
    }

    private function formatWeight(float $weight): string
    {
        return rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');
    }
}

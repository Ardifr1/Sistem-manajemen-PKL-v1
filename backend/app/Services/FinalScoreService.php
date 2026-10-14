<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentComponent;
use App\Models\FinalAssessment;
use App\Models\PklPlacement;
use Illuminate\Support\Collection;

class FinalScoreService
{
    /**
     * Hitung nilai akhir placement dari komponen aktif.
     *
     * Aturan bisnis:
     * - Hanya komponen aktif (is_active = true) yang dihitung.
     * - Total bobot komponen aktif harus tepat 100 (toleransi 0.01).
     * - Setiap komponen aktif wajib memiliki minimal satu penilaian.
     * - Skor per komponen dirata-rata dari semua penilaian, lalu
     *   dinormalisasi dengan bobot: sum(rata-rata * bobot / total_bobot).
     *
     * @return array{ok: bool, final_score?: float, message?: string, detail?: array}
     */
    public function calculate(PklPlacement $placement): array
    {
        /** @var Collection<int, AssessmentComponent> $components */
        $components = AssessmentComponent::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($components->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'Belum ada komponen penilaian aktif.',
            ];
        }

        $totalWeight = (float) $components->sum('weight');

        if (abs($totalWeight - 100.0) > 0.01) {
            return [
                'ok' => false,
                'message' => 'Total bobot komponen aktif harus tepat 100%. Saat ini '.$this->formatWeight($totalWeight).'%.',
            ];
        }

        $assessments = Assessment::query()
            ->where('placement_id', $placement->id)
            ->whereIn('component_id', $components->pluck('id'))
            ->get()
            ->groupBy('component_id');

        $missing = [];
        $detail = [];
        $finalScore = 0.0;

        foreach ($components as $component) {
            $rows = $assessments->get($component->id, collect());

            if ($rows->isEmpty()) {
                $missing[] = $component->name;
                continue;
            }

            $average = (float) $rows->avg('score');
            $weight = (float) $component->weight;
            $finalScore += $average * $weight / $totalWeight;

            $detail[] = [
                'component_id' => $component->id,
                'component_name' => $component->name,
                'owner_type' => $component->owner_type,
                'weight' => $weight,
                'average_score' => round($average, 2),
            ];
        }

        if (! empty($missing)) {
            return [
                'ok' => false,
                'message' => 'Komponen berikut belum dinilai: '.implode(', ', $missing).'.',
            ];
        }

        return [
            'ok' => true,
            'final_score' => round($finalScore, 2),
            'detail' => $detail,
        ];
    }

    /**
     * Hitung ulang dan simpan nilai akhir (draft) untuk satu placement.
     */
    public function recalculate(PklPlacement $placement, ?int $actorId = null): array
    {
        $result = $this->calculate($placement);

        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        $final = FinalAssessment::query()->firstOrNew([
            'placement_id' => $placement->id,
        ]);

        // Nilai yang sudah dipublikasikan tidak boleh tertimpa otomatis.
        if ($final->exists && $final->status === 'published') {
            return [
                'ok' => false,
                'message' => 'Nilai akhir sudah dipublikasikan dan tidak dapat dihitung ulang.',
            ];
        }

        $final->fill([
            'final_score' => $result['final_score'],
            'status' => 'draft',
            'finalized_by' => $actorId ?? $final->finalized_by,
            'finalized_at' => now(),
        ]);
        $final->save();

        $result['final_assessment'] = $final->fresh();

        return $result;
    }

    private function formatWeight(float $weight): string
    {
        return rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');
    }
}

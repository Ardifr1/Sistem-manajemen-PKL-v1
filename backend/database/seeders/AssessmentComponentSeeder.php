<?php

namespace Database\Seeders;

use App\Models\AssessmentComponent;
use Illuminate\Database\Seeder;

class AssessmentComponentSeeder extends Seeder
{
    /**
     * Bobot awal: 70% Pembimbing Industri, 30% Guru Pembimbing.
     * Nilai ini hanya nilai awal — Admin dapat mengubahnya, dengan syarat
     * total bobot komponen aktif tetap tepat 100%.
     */
    public function run(): void
    {
        $components = [
            // Industri: total 70
            ['name' => 'Kedisiplinan', 'description' => 'Ketepatan waktu dan kepatuhan pada aturan kerja.', 'owner_type' => 'industri', 'weight' => 15],
            ['name' => 'Sikap Kerja', 'description' => 'Etika, kesopanan, dan tanggung jawab dalam bekerja.', 'owner_type' => 'industri', 'weight' => 15],
            ['name' => 'Komunikasi', 'description' => 'Kemampuan berkomunikasi dan bekerja dengan tim.', 'owner_type' => 'industri', 'weight' => 10],
            ['name' => 'Kemampuan Kerja', 'description' => 'Kemampuan mengerjakan tugas sesuai bidang PKL.', 'owner_type' => 'industri', 'weight' => 20],
            ['name' => 'Perkembangan Kompetensi', 'description' => 'Peningkatan kemampuan dari waktu ke waktu.', 'owner_type' => 'industri', 'weight' => 10],
            // Guru: total 30
            ['name' => 'Laporan & Jurnal', 'description' => 'Kelengkapan dan kualitas laporan serta jurnal PKL.', 'owner_type' => 'guru', 'weight' => 15],
            ['name' => 'Presentasi & Pemahaman', 'description' => 'Pemahaman materi dan kemampuan presentasi hasil PKL.', 'owner_type' => 'guru', 'weight' => 15],
        ];

        foreach ($components as $component) {
            AssessmentComponent::updateOrCreate(
                ['name' => $component['name']],
                [...$component, 'is_active' => true]
            );
        }
    }
}

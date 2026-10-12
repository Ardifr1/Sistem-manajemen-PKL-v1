<?php

namespace Database\Seeders;

use App\Models\AssessmentComponent;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            TestAccountsSeeder::class,
        ]);

        // Komponen penilaian default (admin bisa ubah via menu Komponen Penilaian).
        // Aman di-seed ulang: updateOrCreate berdasarkan nama.
        $components = [
            ['name' => 'Kedisiplinan', 'description' => 'Ketepatan waktu dan kepatuhan pada aturan kerja', 'weight' => 15, 'assessor_role' => 'supervisor'],
            ['name' => 'Sikap & Tanggung Jawab', 'description' => 'Etika, kesopanan, dan tanggung jawab terhadap tugas', 'weight' => 15, 'assessor_role' => 'supervisor'],
            ['name' => 'Komunikasi & Kerja Sama', 'description' => 'Kemampuan berkomunikasi dan bekerja dalam tim', 'weight' => 10, 'assessor_role' => 'supervisor'],
            ['name' => 'Kompetensi Teknis', 'description' => 'Kemampuan mengerjakan tugas sesuai bidang PKL', 'weight' => 20, 'assessor_role' => 'supervisor'],
            ['name' => 'Perkembangan Kompetensi', 'description' => 'Peningkatan kemampuan dari waktu ke waktu', 'weight' => 10, 'assessor_role' => 'supervisor'],
            ['name' => 'Kualitas Jurnal & Laporan', 'description' => 'Kelengkapan dan kualitas jurnal kegiatan', 'weight' => 15, 'assessor_role' => 'teacher'],
            ['name' => 'Penguasaan Kompetensi', 'description' => 'Penilaian guru atas penguasaan kompetensi siswa', 'weight' => 15, 'assessor_role' => 'teacher'],
        ];
        foreach ($components as $c) {
            AssessmentComponent::updateOrCreate(
                ['name' => $c['name']],
                $c + ['is_active' => true]
            );
        }
    }
}

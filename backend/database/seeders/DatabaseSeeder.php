<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Komponen penilaian hanya di-seed SEKALI lewat AssessmentComponentSeeder
     * (total aktif tepat 100%: 70 industri + 30 guru). Jangan menambahkan set
     * komponen kedua di sini — duplikasi sebelumnya membuat total bobot aktif
     * menjadi 175% sehingga nilai akhir tidak pernah bisa dihitung.
     */
    public function run(): void
    {
        $this->call([
            TestAccountsSeeder::class,
            AssessmentComponentSeeder::class,
        ]);
    }
}

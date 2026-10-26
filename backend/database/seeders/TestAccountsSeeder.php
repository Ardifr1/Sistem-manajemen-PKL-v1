<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\PklApplication;
use App\Models\PklPeriod;
use App\Models\PklPlacement;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestAccountsSeeder extends Seeder
{
    /**
     * Akun untuk testing manual. Aman dijalankan berulang (tidak duplikat).
     * Sekalian bikin data contoh: perusahaan, periode aktif, dan satu
     * penempatan agar halaman guru & industri bisa langsung dites.
     */
    public function run(): void
    {
        // Akun demo memakai password bawaan yang diketahui publik. Di
        // production seeder ini harus dinyalakan secara eksplisit agar tidak
        // meninggalkan akun berisiko pada instalasi nyata.
        if (
            app()->environment('production')
            && ! config('simagang.demo.seed_accounts', false)
        ) {
            $this->command?->warn(
                'TestAccountsSeeder dilewati: akun demo tidak diizinkan di production tanpa SEED_DEMO_ACCOUNTS=true.'
            );

            return;
        }

        $accounts = [
            [
                'name' => 'Admin Sekolah',
                'username' => 'admin',
                'email' => 'admin@smk.sch.id',
                'password' => 'admin123',
                'role' => 'admin',
            ],
            [
                'name' => 'Guru Pembimbing',
                'username' => 'guru',
                'email' => 'guru@smk.sch.id',
                'password' => 'guru123',
                'role' => 'teacher',
            ],
            [
                'name' => 'Siswa Test',
                'username' => 'siswa',
                'email' => 'siswa@smk.sch.id',
                'password' => 'siswa123',
                'role' => 'student',
            ],
            [
                'name' => 'Admin Perusahaan',
                'username' => 'industri',
                'email' => 'industri@smk.sch.id',
                'password' => 'industri123',
                'role' => 'company',
            ],
            [
                'name' => 'Pembimbing Industri',
                'username' => 'pembimbing',
                'email' => 'pembimbing@smk.sch.id',
                'password' => 'pembimbing123',
                'role' => 'supervisor',
            ],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [...$account, 'is_active' => true]
            );
        }

        // Sekolah contoh (fondasi pengelompokan per sekolah).
        $school = \App\Models\School::updateOrCreate(
            ['name' => 'SMKN 1'],
            ['npsn' => '20100101', 'is_active' => true]
        );
        User::whereIn('role', ['student', 'teacher'])->update(['school_id' => $school->id]);

        // Perusahaan contoh + tautkan akun industri sebagai admin perusahaan,
        // dan akun pembimbing sebagai pembimbing industri.
        $company = Company::updateOrCreate(
            ['email' => 'hrd@sdn.test'],
            [
                'name' => 'PT Solusi Digital Nusantara',
                'industry' => 'Teknologi Informasi',
                'description' => 'Perusahaan mitra contoh untuk testing.',
                'address' => 'Jl. Merdeka No. 10, Jakarta',
                'phone' => '021-5550100',
                'student_quota' => 6,
                'is_partner' => true,
                'is_active' => true,
            ]
        );

        $industriUser = User::where('email', 'industri@smk.sch.id')->first();

        if ($industriUser) {
            CompanySupervisor::updateOrCreate(
                ['user_id' => $industriUser->id],
                [
                    'company_id' => $company->id,
                    'position' => 'Admin Perusahaan',
                ]
            );
        }

        $pembimbingUser = User::where('email', 'pembimbing@smk.sch.id')->first();

        if ($pembimbingUser) {
            CompanySupervisor::updateOrCreate(
                ['user_id' => $pembimbingUser->id],
                [
                    'company_id' => $company->id,
                    'position' => 'Pembimbing Industri',
                ]
            );
        }

        // Periode PKL aktif contoh.
        $period = PklPeriod::updateOrCreate(
            ['name' => 'PKL 2026/2027'],
            [
                'start_date' => '2026-07-01',
                'end_date' => '2026-12-31',
                'is_active' => true,
            ]
        );

        // Satu penempatan contoh: siswa test @ PT Solusi Digital Nusantara.
        // teacher_id & supervisor_id wajib diisi agar guru pembimbing dan
        // pembimbing industri benar-benar ditugaskan (BR-09a) sehingga halaman
        // penilaian mereka bisa langsung dites.
        $siswaUser = User::where('email', 'siswa@smk.sch.id')->first();
        $guruUser = User::where('email', 'guru@smk.sch.id')->first();
        $pembimbingProfile = $pembimbingUser
            ? CompanySupervisor::where('user_id', $pembimbingUser->id)->first()
            : null;

        if ($siswaUser) {
            $application = PklApplication::updateOrCreate(
                [
                    'student_id' => $siswaUser->id,
                    'company_id' => $company->id,
                    'pkl_period_id' => $period->id,
                ],
                [
                    'choice_order' => 1,
                    'status' => 'accepted',
                ]
            );

            PklPlacement::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'student_id' => $siswaUser->id,
                    'company_id' => $company->id,
                    'teacher_id' => $guruUser?->id,
                    'supervisor_id' => $pembimbingProfile?->id,
                    'pkl_period_id' => $period->id,
                    'start_date' => '2026-08-01',
                    'end_date' => '2026-10-31',
                    'status' => 'active',
                ]
            );
        }
    }
}

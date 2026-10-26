<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PklApplication;
use App\Models\PklPeriod;
use App\Models\PklPlacement;
use App\Models\User;
use App\Policies\Concerns\ResolvesCompanyAccess;
use App\Support\PlacementQuota;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PklApplicationController extends Controller
{
    use ResolvesCompanyAccess;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PklApplication::class);
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:users,id'],
            'pkl_period_id' => ['nullable', 'integer', 'exists:pkl_periods,id'],
        ]);

        $user = $request->user();
        // `student` di-eager-load agar daftar lamaran menampilkan identitas
        // pelamar (dipakai halaman review perusahaan & monitoring admin).
        $query = PklApplication::with(['company', 'pklPeriod', 'student'])
            ->orderBy('choice_order');

        if (! empty($validated['student_id'])) {
            $studentId = (int) $validated['student_id'];

            // Filter student_id yang eksplisit tidak boleh memperluas
            // cakupan akses: meminta data siswa di luar kewenangan sendiri
            // ditolak 403, bukan sekadar menghasilkan daftar kosong.
            if ($user->role === 'student' && $studentId !== $user->id) {
                abort(403);
            }

            if ($user->role === 'teacher') {
                $supervised = PklPlacement::query()
                    ->where('student_id', $studentId)
                    ->where('teacher_id', $user->id)
                    ->exists();
                if (! $supervised) {
                    abort(403);
                }
            }
            // Company/supervisor tidak ditolak di sini: pembatasan cakupan
            // di bawah membuat filter student_id perusahaan lain menghasilkan
            // daftar kosong (200) tanpa membocorkan data.

            $query->where('student_id', $studentId);
        } elseif ($user->role === 'student') {
            $query->where('student_id', $user->id);
        }

        // Batasan per peran selalu berlaku, juga bila student_id diberikan.
        if ($user->role === 'teacher') {
            // Siswa bimbingan guru ditentukan lewat pkl_placements.teacher_id
            // (tabel users tidak memiliki kolom teacher_id).
            $query->whereHas('student.placements', fn ($p) => $p->where('teacher_id', $user->id));
        } elseif (in_array($user->role, ['company', 'supervisor'], true)) {
            // Keanggotaan aktif + tautan pembimbing lama; tanpa kewenangan
            // perusahaan mana pun, daftar harus kosong (bukan semua data).
            $companyIds = $this->authorizedCompanyIds($user);
            if ($companyIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('company_id', $companyIds);
            }
        }
        // admin: semua

        if (isset($validated['pkl_period_id'])) {
            $query->where('pkl_period_id', $validated['pkl_period_id']);
        }

        return response()->json([
            'message' => 'Data pendaftaran PKL berhasil diambil.',
            'data' => $query->get(),
        ]);
        
    }

    public function show(PklApplication $pklApplication): JsonResponse
    {
        Gate::authorize('view', $pklApplication);
        $pklApplication->load(['student', 'company', 'pklPeriod']);

        return response()->json([
            'message' => 'Data pendaftaran PKL berhasil diambil.',
            'data' => $pklApplication,
        ]);
        
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', PklApplication::class);
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'pkl_period_id' => ['required', 'integer', 'exists:pkl_periods,id'],
            'choice_order' => ['required', 'integer', 'between:1,3'],
            'student_note' => ['nullable', 'string'],
        ]);

        if (
            $request->user()->role === 'student'
            && (int) $validated['student_id'] !== $request->user()->id
        ) {
            abort(403);
        }

        $company = Company::findOrFail($validated['company_id']);
        $period = PklPeriod::findOrFail($validated['pkl_period_id']);

        // Siswa hanya dapat melamar ke perusahaan mitra atau nonmitra yang
        // sudah terverifikasi sekolah.
        if (! $company->is_partner && ! $company->isVerified()) {
            return response()->json([
                'message' => 'Perusahaan harus berstatus mitra atau terverifikasi sekolah.',
            ], 422);
        }

        if (! $company->is_active) {
            return response()->json([
                'message' => 'Perusahaan sedang tidak aktif.',
            ], 422);
        }

        // Pilihan perusahaan harus berada pada periode PKL yang aktif.
        if (! $period->is_active) {
            return response()->json([
                'message' => 'Periode PKL tersebut tidak sedang aktif.',
            ], 422);
        }

        // Aturan maksimal 3 lamaran aktif: lamaran berstatus Diajukan,
        // Dalam Seleksi, Interview, atau Diterima TIDAK memblokir pengajuan
        // berikutnya selama slot masih tersedia; lamaran Ditolak tidak
        // dihitung sebagai lamaran aktif dan riwayatnya tetap tersimpan.
        // Pemeriksaan jumlah, duplikasi, dan pembuatan lamaran berjalan
        // dalam satu transaksi dengan kunci pada baris siswa — dua request
        // bersamaan untuk siswa yang sama diproses berurutan sehingga
        // batas 3 tidak dapat dilampaui. Baris users dipilih sebagai kunci
        // karena selalu ada (student_id sudah tervalidasi) dan stabil
        // untuk pasangan siswa-periode; pemilihan final mengunci baris
        // yang sama terlebih dahulu sehingga pengajuan dan pemilihan
        // final untuk siswa yang sama juga saling berurutan.
        try {
            $result = DB::transaction(function () use ($validated) {
                User::query()
                    ->whereKey($validated['student_id'])
                    ->lockForUpdate()
                    ->first();

                // Setelah placement final dibuat untuk periode ini, siswa
                // tidak boleh mengajukan lamaran baru. Penempatan yang
                // dibatalkan tidak menghalangi (konsisten dengan hitungan
                // kuota yang mengabaikan placement cancelled).
                $hasFinalPlacement = PklPlacement::query()
                    ->where('student_id', $validated['student_id'])
                    ->where('pkl_period_id', $validated['pkl_period_id'])
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if ($hasFinalPlacement) {
                    return ['error' => 'Siswa sudah memiliki penempatan final pada periode ini, sehingga tidak dapat mengajukan lamaran baru.'];
                }

                $activeCount = PklApplication::query()
                    ->where('student_id', $validated['student_id'])
                    ->where('pkl_period_id', $validated['pkl_period_id'])
                    ->whereNot('status', 'rejected')
                    ->count();

                if ($activeCount >= 3) {
                    return ['error' => 'Maksimal 3 pilihan perusahaan untuk satu periode PKL.'];
                }

                // Perusahaan yang pernah dipilih tidak dapat dipilih ulang
                // pada periode yang sama: application rejected tetap menjadi
                // riwayat (juga dipaksa oleh unique constraint
                // student-company-period pada level database).
                $duplicateCompany = PklApplication::query()
                    ->where('student_id', $validated['student_id'])
                    ->where('pkl_period_id', $validated['pkl_period_id'])
                    ->where('company_id', $validated['company_id'])
                    ->exists();

                if ($duplicateCompany) {
                    return ['error' => 'Perusahaan tersebut sudah dipilih.'];
                }

                // choice_order hanya bertabrakan dengan lamaran aktif lain;
                // nomor pilihan dari lamaran yang ditolak boleh dipakai lagi
                // (tidak ada unique constraint student-period-choice_order
                // di database).
                $duplicateChoiceOrder = PklApplication::query()
                    ->where('student_id', $validated['student_id'])
                    ->where('pkl_period_id', $validated['pkl_period_id'])
                    ->whereNot('status', 'rejected')
                    ->where('choice_order', $validated['choice_order'])
                    ->exists();

                if ($duplicateChoiceOrder) {
                    return ['error' => 'Urutan pilihan tersebut sudah digunakan.'];
                }

                $application = PklApplication::create([
                    ...$validated,
                    'status' => 'pending',
                ]);

                return ['application' => $application];
            });
        } catch (QueryException $e) {
            // Balapan penulisan yang lolos dari kunci (mis. unique
            // student-company-period): perlakukan sebagai duplikasi.
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Perusahaan tersebut sudah dipilih.',
                ], 422);
            }

            throw $e;
        }

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        /** @var PklApplication $application */
        $application = $result['application'];
        $application->load(['company', 'pklPeriod']);

        return response()->json([
            'message' => 'Pendaftaran PKL berhasil diajukan.',
            'data' => $application,
        ], 201);
    }

    /**
     * Pilihan final siswa: bila diterima beberapa perusahaan, siswa memilih
     * SATU tujuan PKL. Saat pilihan valid, sistem OTOMATIS membuat penempatan
     * resmi (tanpa persetujuan admin) dan menutup tawaran lain pada periode
     * yang sama agar siswa hanya berada pada satu perusahaan.
     *
     * Seluruh operasi berjalan dalam satu transaksi: penutupan tawaran,
     * pembuatan penempatan, dan pemeriksaan kuota (dengan baris perusahaan
     * terkunci) konsisten sekaligus.
     */
    public function choose(
        Request $request,
        PklApplication $pklApplication
    ): JsonResponse {
        Gate::authorize('choose', $pklApplication);

        if ($pklApplication->status !== 'accepted') {
            return response()->json([
                'message' => 'Hanya pengajuan yang sudah diterima perusahaan yang dapat dipilih sebagai tujuan PKL.',
            ], 422);
        }

        $company = Company::findOrFail($pklApplication->company_id);
        $period = PklPeriod::findOrFail($pklApplication->pkl_period_id);

        // Perusahaan nonmitra / belum diverifikasi / nonaktif tidak boleh
        // dipakai untuk penempatan resmi (PRD bagian D).
        if (! $company->isUsableForPlacement()) {
            return response()->json([
                'message' => 'Perusahaan belum terverifikasi sebagai mitra aktif sehingga tidak dapat dipakai untuk penempatan.',
            ], 422);
        }

        if (! $period->is_active) {
            return response()->json([
                'message' => 'Periode PKL tersebut tidak sedang aktif.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($pklApplication, $company, $period, $request) {
                // Kunci baris siswa terlebih dahulu agar dua pemilihan final
                // bersamaan untuk siswa yang sama (dari tawaran berbeda)
                // diproses berurutan. Pengajuan baru mengunci baris yang sama
                // sehingga pengajuan dan pemilihan final juga saling
                // berurutan. Unique constraint student-period pada placements
                // tetap menjadi pengaman terakhir.
                User::query()
                    ->whereKey($pklApplication->student_id)
                    ->lockForUpdate()
                    ->first();

                // Idempotensi: siswa sudah punya penempatan di periode ini
                // (mis. klik ganda) — jangan buat lagi.
                $alreadyPlaced = PklPlacement::query()
                    ->where('student_id', $pklApplication->student_id)
                    ->where('pkl_period_id', $pklApplication->pkl_period_id)
                    ->exists();

                if ($alreadyPlaced) {
                    return ['error' => 'Penempatan sudah dibuat sebelumnya.'];
                }

                // Kunci baris perusahaan + cek kuota sebelum mengubah apa pun.
                $lockedCompany = Company::query()
                    ->whereKey($company->id)
                    ->lockForUpdate()
                    ->first();

                $quotaError = PlacementQuota::errorMessage(
                    $lockedCompany,
                    (int) $pklApplication->pkl_period_id
                );

                if ($quotaError !== null) {
                    return ['error' => $quotaError];
                }

                // Tutup tawaran diterima lainnya pada periode yang sama.
                $closedOffers = PklApplication::query()
                    ->where('student_id', $pklApplication->student_id)
                    ->where('pkl_period_id', $pklApplication->pkl_period_id)
                    ->where('status', 'accepted')
                    ->where('id', '!=', $pklApplication->id)
                    ->update(['status' => 'rejected']);

                // Buat penempatan resmi otomatis. Status `scheduled` bila
                // tanggal mulai belum tiba; aktif otomatis saat tanggal mulai
                // tiba (dievaluasi lazy saat daftar penempatan dibuka).
                //
                // teacher_id dan supervisor_id sengaja dibiarkan NULL pada
                // tahap ini. Penugasan guru pembimbing (teacher_id) dan
                // pembimbing industri (supervisor_id) harus dilakukan
                // secara eksplisit oleh admin melalui endpoint
                // /pkl-placements/{id} (PUT) atau melalui alur bisnis
                // lain yang ada. Tidak ada mekanisme otomatis untuk
                // menetapkan pembimbing saat penempatan dibuat, karena:
                //   - teacher_id harus berasal dari guru yang ditugaskan
                //     kepada siswa tersebut (tidak ada sumber data otomatis
                //     yang menghubungkan guru ke siswa tanpa penugasan
                //     eksplisit).
                //   - supervisor_id harus berasal dari CompanySupervisor
                //     yang benar-benar terkait dengan perusahaan dan
                //     penempatan tersebut (tidak ada sumber data otomatis
                //     yang menghubungkan supervisor ke placement).
                // Menetapkan pembimbing secara acak atau mengambil
                // pengguna pertama akan menghasilkan data yang tidak
                // memiliki hubungan sah dengan siswa/perusahaan.
                $today = now()->toDateString();
                $placement = PklPlacement::create([
                    'student_id' => $pklApplication->student_id,
                    'company_id' => $pklApplication->company_id,
                    'pkl_period_id' => $pklApplication->pkl_period_id,
                    'application_id' => $pklApplication->id,
                    'start_date' => $period->start_date,
                    'end_date' => $period->end_date,
                    'status' => $period->start_date->toDateString() <= $today ? 'active' : 'scheduled',
                ]);

                // Tandai lamaran terpilih selesai diproses.
                $pklApplication->update(['status' => 'approved']);

                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'placement.auto_created',
                    'subject_type' => PklPlacement::class,
                    'subject_id' => $placement->id,
                    'placement_id' => $placement->id,
                    'new_status' => $placement->status,
                    'reason' => 'Siswa memilih tawaran; penempatan resmi dibuat otomatis.',
                    'metadata' => [
                        'student_id' => (int) $pklApplication->student_id,
                        'company_id' => (int) $pklApplication->company_id,
                        'pkl_period_id' => (int) $pklApplication->pkl_period_id,
                        'application_id' => (int) $pklApplication->id,
                    ],
                ]);

                if ($closedOffers > 0) {
                    AuditLog::create([
                        'user_id' => $request->user()->id,
                        'action' => 'application_offers_closed',
                        'subject_type' => PklApplication::class,
                        'subject_id' => $pklApplication->id,
                        'new_status' => 'rejected',
                        'reason' => 'Siswa memilih satu perusahaan; tawaran lain ditutup.',
                        'metadata' => [
                            'student_id' => (int) $pklApplication->student_id,
                            'pkl_period_id' => (int) $pklApplication->pkl_period_id,
                            'chosen_application_id' => (int) $pklApplication->id,
                            'closed_count' => $closedOffers,
                        ],
                    ]);
                }

                return [
                    'placement' => $placement,
                    'closed_offers' => $closedOffers,
                ];
            });
        } catch (QueryException $e) {
            // Unique constraint (student_id, pkl_period_id): request bersamaan
            // atau klik ganda — anggap sudah ditempatkan.
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Penempatan sudah dibuat sebelumnya.',
                ], 422);
            }

            throw $e;
        }

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        /** @var PklPlacement $placement */
        $placement = $result['placement'];
        $closedOffers = $result['closed_offers'];

        $placement->load(['company', 'pklPeriod']);
        $pklApplication->load(['company', 'pklPeriod']);

        $scheduled = $placement->status === 'scheduled';

        return response()->json([
            'message' => $scheduled
                ? 'Pilihan final tersimpan dan penempatan resmi dibuat. PKL akan aktif pada tanggal mulai periode.'
                : 'Pilihan final tersimpan dan penempatan resmi dibuat. PKL kamu sudah aktif.',
            'data' => $pklApplication,
            'placement' => $placement,
            'closed_offers' => $closedOffers,
        ]);
    }

    public function update(
        Request $request,
        PklApplication $pklApplication
    ): JsonResponse {
        // Perubahan STATUS lamaran hanya boleh lewat review perusahaan
        // (cabang di bawah). Status 'approved' TIDAK boleh disetel langsung
        // oleh siapa pun — status itu hanya lahir dari pilihan final siswa
        // (choose) yang membuat penempatan resmi secara atomik. Menyetelnya
        // langsung menghasilkan lamaran "resmi" tanpa penempatan terkait
        // (data tidak konsisten) dan siswa tidak bisa lagi memilih tawaran.
        if ($request->has('status')) {
            if ($request->user()->role === 'company') {
                return $this->reviewByCompany($request, $pklApplication);
            }

            return response()->json([
                'message' => 'Status lamaran tidak dapat diubah langsung. Penempatan resmi dibuat otomatis saat siswa memilih tawaran.',
            ], 422);
        }

        Gate::authorize('update', $pklApplication);

        if (! in_array($pklApplication->status, ['pending', 'rejected'], true)) {
            return response()->json([
                'message' => 'Pendaftaran yang sudah diterima/diproses tidak dapat diubah.',
            ], 422);
        }

        $validated = $request->validate([
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'choice_order' => ['sometimes', 'integer', 'between:1,3'],
            'student_note' => ['sometimes', 'nullable', 'string'],
        ]);

        // Setelah pendaftaran dikirim, pilihan perusahaan dan urutan pilihan
        // terkunci dan tidak dapat diganti oleh siswa (status pending
        // tidak membuka kembali pengeditan pilihan).
        if (
            $request->user()->role === 'student'
            && (isset($validated['company_id']) || isset($validated['choice_order']))
        ) {
            return response()->json([
                'message' => 'Pilihan perusahaan dan urutan pilihan sudah terkunci setelah pendaftaran dikirim.',
            ], 422);
        }

        if (isset($validated['company_id'])) {
            $duplicateCompany = PklApplication::query()
                ->where('student_id', $pklApplication->student_id)
                ->where('pkl_period_id', $pklApplication->pkl_period_id)
                ->where('company_id', $validated['company_id'])
                ->where('id', '!=', $pklApplication->id)
                ->exists();

            if ($duplicateCompany) {
                return response()->json([
                    'message' => 'Perusahaan tersebut sudah dipilih.',
                ], 422);
            }
        }

        if (isset($validated['choice_order'])) {
            $duplicateChoiceOrder = PklApplication::query()
                ->where('student_id', $pklApplication->student_id)
                ->where('pkl_period_id', $pklApplication->pkl_period_id)
                ->where('choice_order', $validated['choice_order'])
                ->where('id', '!=', $pklApplication->id)
                ->exists();

            if ($duplicateChoiceOrder) {
                return response()->json([
                    'message' => 'Urutan pilihan tersebut sudah digunakan.',
                ], 422);
            }
        }

        $pklApplication->update($validated);

        $pklApplication->load(['company', 'pklPeriod']);

        return response()->json([
            'message' => 'Pendaftaran PKL berhasil diperbarui.',
            'data' => $pklApplication,
        ]);
    }

    /**
     * Review perusahaan atas lamaran yang ditujukan ke perusahaannya:
     * terima / tolak / masuk seleksi / interview.
     *
     * Transisi yang sah: dari pending/seleksi/interview menuju
     * seleksi/interview/accepted/rejected. Status yang sudah diputuskan
     * (accepted/approved/rejected) tidak dapat direview ulang.
     *
     * Penerimaan (accepted) memeriksa kuota di dalam transaksi dengan
     * baris perusahaan terkunci: siswa berstatus accepted maupun approved
     * (sudah ditempatkan) sama-sama memakai kuota perusahaan.
     */
    private function reviewByCompany(
        Request $request,
        PklApplication $pklApplication
    ): JsonResponse {
        Gate::authorize('review', $pklApplication);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:seleksi,interview,accepted,rejected'],
        ]);

        if (! in_array($pklApplication->status, ['pending', 'seleksi', 'interview'], true)) {
            return response()->json([
                'message' => 'Lamaran yang sudah diputuskan tidak dapat direview ulang.',
            ], 422);
        }

        $target = $validated['status'];

        $result = DB::transaction(function () use ($request, $pklApplication, $target) {
            if ($target === 'accepted') {
                $company = Company::query()
                    ->whereKey($pklApplication->company_id)
                    ->lockForUpdate()
                    ->first();

                $quotaError = PlacementQuota::errorMessage(
                    $company,
                    (int) $pklApplication->pkl_period_id
                );

                if ($quotaError !== null) {
                    return ['error' => $quotaError];
                }
            }

            $oldStatus = $pklApplication->status;
            $pklApplication->update(['status' => $target]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'application.reviewed',
                'subject_type' => PklApplication::class,
                'subject_id' => $pklApplication->id,
                'old_status' => $oldStatus,
                'new_status' => $target,
                'metadata' => [
                    'student_id' => (int) $pklApplication->student_id,
                    'company_id' => (int) $pklApplication->company_id,
                    'pkl_period_id' => (int) $pklApplication->pkl_period_id,
                ],
            ]);

            return ['ok' => true];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        $pklApplication->load(['company', 'pklPeriod']);

        return response()->json([
            'message' => 'Status lamaran berhasil diperbarui.',
            'data' => $pklApplication,
        ]);
    }

    public function destroy(
        PklApplication $pklApplication
    ): JsonResponse {
        Gate::authorize('delete', $pklApplication);

        if (! in_array($pklApplication->status, ['pending', 'rejected'], true)) {
            return response()->json([
                'message' => 'Pendaftaran yang sudah diterima/diproses tidak dapat dibatalkan.',
            ], 422);
        }

        $pklApplication->delete();

        return response()->json([
            'message' => 'Pendaftaran PKL berhasil dibatalkan.',
        ]);
    }
}
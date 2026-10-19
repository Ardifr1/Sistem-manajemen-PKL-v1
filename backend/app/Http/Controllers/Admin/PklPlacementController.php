<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PklApplication;
use App\Models\PklPlacement;
use App\Support\PlacementQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PklPlacementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PklPlacement::class);

        // Aktivasi otomatis: penempatan berstatus `scheduled` yang tanggal
        // mulainya telah tiba menjadi `active`. Pola lazy seperti penutupan
        // periode otomatis — dievaluasi setiap daftar dibuka.
        PklPlacement::query()
            ->where('status', 'scheduled')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->update(['status' => 'active']);

        $validated = $request->validate([
            'pkl_period_id' => [
                'nullable',
                'integer',
                'exists:pkl_periods,id',
            ],
            'status' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $query = PklPlacement::with([
            'student',
            'company',
            'pklPeriod',
            'application',
        ])->orderByDesc('id');

        if (isset($validated['pkl_period_id'])) {
            $query->where(
                'pkl_period_id',
                $validated['pkl_period_id']
            );
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Batasi sesuai role
        $user = $request->user();
        if ($user->role === 'student') {
            $query->where('student_id', $user->id);
        } elseif ($user->role === 'teacher') {
            $query->where('teacher_id', $user->id);
        } elseif (in_array($user->role, ['company', 'supervisor'], true)) {
            $companyId = $user->companySupervisor?->company_id;
            if ($companyId) {
                $query->where('company_id', $companyId);
            } else {
                // Belum ditautkan: kembalikan kosong
                return response()->json([
                    'message' => 'Data penempatan PKL berhasil diambil.',
                    'data' => [],
                ]);
            }
        }

        return response()->json([
            'message' => 'Data penempatan PKL berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(PklPlacement $pklPlacement): JsonResponse
    {
        Gate::authorize('view', $pklPlacement);

        $pklPlacement->load([
            'student',
            'company',
            'pklPeriod',
            'application',
        ]);

        return response()->json([
            'message' => 'Data penempatan PKL berhasil diambil.',
            'data' => $pklPlacement,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', PklPlacement::class);

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'supervisor_id' => [
                'nullable',
                'integer',
                'exists:company_supervisors,id',
            ],
            'pkl_period_id' => [
                'required',
                'integer',
                'exists:pkl_periods,id',
            ],
            'application_id' => [
                'nullable',
                'integer',
                'exists:pkl_applications,id',
            ],
            'start_date' => [
                'required',
                'date',
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],
            'status' => [
                'required',
                'string',
                'max:50',
                'in:active,completed,cancelled,scheduled',
            ],
        ]);

        $existingPlacement = PklPlacement::query()
            ->where('student_id', $validated['student_id'])
            ->where('pkl_period_id', $validated['pkl_period_id'])
            ->exists();

        if ($existingPlacement) {
            return response()->json([
                'message' => 'Siswa sudah memiliki penempatan pada periode PKL tersebut.',
            ], 422);
        }

        $company = Company::findOrFail($validated['company_id']);

        // Hard Block konflik kepentingan (sisi sekolah): admin yang menjadi
        // anggota aktif perusahaan tujuan tidak boleh membuat penempatan.
        Gate::authorize('createForCompany', [PklPlacement::class, (int) $company->id]);

        // Perusahaan nonmitra / belum diverifikasi / nonaktif tidak boleh
        // dipakai untuk penempatan resmi (PRD bagian D).
        if (! $company->isUsableForPlacement()) {
            return response()->json([
                'message' => 'Perusahaan belum terverifikasi sebagai mitra aktif sehingga tidak dapat dipakai untuk penempatan.',
            ], 422);
        }

        // Penempatan tidak boleh aktif sebelum tanggal mulai PKL.
        if ($validated['status'] === 'active'
            && strtotime((string) $validated['start_date']) > strtotime(now()->toDateString())) {
            return response()->json([
                'message' => 'Penempatan belum dapat diaktifkan sebelum tanggal mulai PKL.',
            ], 422);
        }

        if (! empty($validated['application_id'])) {
            $application = PklApplication::query()->find($validated['application_id']);

            if (
                $application->student_id !== (int) $validated['student_id']
                || $application->pkl_period_id !== (int) $validated['pkl_period_id']
                || $application->company_id !== (int) $validated['company_id']
            ) {
                return response()->json([
                    'message' => 'Data pendaftaran tidak sesuai dengan siswa, perusahaan, atau periode penempatan.',
                ], 422);
            }

            // Penempatan hanya boleh dibuat dari pendaftaran yang sudah diterima,
            // bukan pendaftaran pending/diproses/ditolak.
            if ($application->status !== 'accepted') {
                return response()->json([
                    'message' => 'Penempatan hanya dapat dibuat dari pendaftaran yang sudah diterima.',
                ], 422);
            }
        }

        // Pembuatan penempatan + pemeriksaan kuota dijalankan dalam satu
        // transaksi dengan mengunci baris perusahaan agar kuota aman dari
        // kondisi balapan (dua admin menempatkan siswa bersamaan).
        $result = DB::transaction(function () use ($validated, $company, $request) {
            $lockedCompany = Company::query()
                ->whereKey($company->id)
                ->lockForUpdate()
                ->first();

            $quotaError = PlacementQuota::errorMessage($lockedCompany, (int) $validated['pkl_period_id'], null);

            if ($quotaError !== null) {
                return ['error' => $quotaError];
            }

            $placement = PklPlacement::create($validated);

            if (! empty($validated['application_id'])) {
                PklApplication::query()
                    ->whereKey($validated['application_id'])
                    ->update(['status' => 'accepted']);
            }

            // "Bila diterima beberapa perusahaan, siswa harus memilih satu":
            // begitu penempatan resmi dibuat, tawaran lain siswa pada periode
            // yang sama ditutup agar siswa hanya berada pada satu perusahaan.
            $closedOffers = PklApplication::query()
                ->where('student_id', $validated['student_id'])
                ->where('pkl_period_id', $validated['pkl_period_id'])
                ->where('status', 'accepted')
                ->when(
                    ! empty($validated['application_id']),
                    fn ($q) => $q->where('id', '!=', $validated['application_id'])
                )
                ->update(['status' => 'rejected']);

            if ($closedOffers > 0) {
                AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'action' => 'application_offers_closed',
                    'subject_type' => PklPlacement::class,
                    'subject_id' => $placement->id,
                    'placement_id' => $placement->id,
                    'new_status' => 'rejected',
                    'reason' => 'Siswa telah ditempatkan pada perusahaan lain; tawaran lain ditutup.',
                    'metadata' => [
                        'student_id' => (int) $validated['student_id'],
                        'pkl_period_id' => (int) $validated['pkl_period_id'],
                        'closed_count' => $closedOffers,
                    ],
                ]);
            }

            return ['placement' => $placement];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        /** @var PklPlacement $placement */
        $placement = $result['placement'];

        $placement->load([
            'student',
            'company',
            'pklPeriod',
            'application',
        ]);

        return response()->json([
            'message' => 'Penempatan PKL berhasil dibuat.',
            'data' => $placement,
        ], 201);
    }

    public function update(
        Request $request,
        PklPlacement $pklPlacement
    ): JsonResponse {
        Gate::authorize('update', $pklPlacement);

        $validated = $request->validate([
            'company_id' => [
                'sometimes',
                'integer',
                'exists:companies,id',
            ],
            'teacher_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'supervisor_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:company_supervisors,id',
            ],
            'start_date' => [
                'sometimes',
                'date',
            ],
            'end_date' => [
                'sometimes',
                'date',
            ],
            'status' => [
                'sometimes',
                'string',
                'max:50',
                'in:active,completed,cancelled,scheduled',
            ],
        ]);

        // Cek duplikat tanggal jika start_date/end_date diubah: validasi after_or_equal:start_date
        // tidak bekerja saat hanya salah satu tanggal dikirim, jadi validasi manual di sini.
        $startDate = $validated['start_date'] ?? $pklPlacement->start_date;
        $endDate = $validated['end_date'] ?? $pklPlacement->end_date;

        if (strtotime((string) $endDate) < strtotime((string) $startDate)) {
            return response()->json([
                'message' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
            ], 422);
        }

        // Perpindahan penempatan ke perusahaan lain wajib melewati pemeriksaan
        // konflik kepentingan, status mitra, dan kuota perusahaan baru.
        if (isset($validated['company_id'])
            && (int) $validated['company_id'] !== (int) $pklPlacement->company_id) {
            $newCompany = Company::findOrFail($validated['company_id']);

            Gate::authorize('createForCompany', [PklPlacement::class, (int) $newCompany->id]);

            if (! $newCompany->isUsableForPlacement()) {
                return response()->json([
                    'message' => 'Perusahaan belum terverifikasi sebagai mitra aktif sehingga tidak dapat dipakai untuk penempatan.',
                ], 422);
            }

            $quotaError = PlacementQuota::errorMessage(
                $newCompany,
                (int) ($validated['pkl_period_id'] ?? $pklPlacement->pkl_period_id),
                (int) $pklPlacement->id
            );

            if ($quotaError !== null) {
                return response()->json(['message' => $quotaError], 422);
            }
        }

        // Penempatan tidak boleh DIAKTIFKAN sebelum tanggal mulai PKL.
        if (($validated['status'] ?? null) === 'active'
            && strtotime((string) $startDate) > strtotime(now()->toDateString())) {
            return response()->json([
                'message' => 'Penempatan belum dapat diaktifkan sebelum tanggal mulai PKL.',
            ], 422);
        }

        $pklPlacement->update($validated);

        $pklPlacement->load([
            'student',
            'company',
            'pklPeriod',
            'application',
        ]);

        return response()->json([
            'message' => 'Penempatan PKL berhasil diperbarui.',
            'data' => $pklPlacement,
        ]);
    }

    public function destroy(
        PklPlacement $pklPlacement
    ): JsonResponse {
        Gate::authorize('delete', $pklPlacement);

        $pklPlacement->delete();

        return response()->json([
            'message' => 'Penempatan PKL berhasil dihapus.',
        ]);
    }
}
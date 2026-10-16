<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PklApplication;
use App\Models\PklPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PklApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PklApplication::class);
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:users,id'],
            'pkl_period_id' => ['nullable', 'integer', 'exists:pkl_periods,id'],
        ]);

        $user = $request->user();
        $query = PklApplication::with(['company', 'pklPeriod'])
            ->orderBy('choice_order');

        if (! empty($validated['student_id'])) {
            if ($user->role === 'student' && (int) $validated['student_id'] !== $user->id) {
                abort(403);
            }
            $query->where('student_id', $validated['student_id']);
        } else {
            // Tanpa student_id: batasi sesuai role
            if ($user->role === 'student') {
                $query->where('student_id', $user->id);
            } elseif ($user->role === 'teacher') {
                $query->whereHas('student', fn ($q) => $q->where('teacher_id', $user->id));
            } elseif (in_array($user->role, ['company', 'supervisor'], true)) {
                $companyId = $user->companySupervisor?->company_id;
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            }
            // admin: semua
        }

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

        // Student hanya dapat memilih perusahaan partner yang aktif.
        if (! $company->is_partner) {
            return response()->json([
                'message' => 'Perusahaan harus berstatus partner.',
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

        // Setelah seluruh pilihan ditolak, siswa boleh mengajukan pendaftaran baru.
        // Selama masih ada pendaftaran yang aktif/relevan (pending/approved/accepted),
        // pendaftaran baru tidak diizinkan agar aturan maksimal 3 pilihan
        // dan riwayat pendaftaran tetap konsisten.
        $hasActiveApplication = PklApplication::query()
            ->where('student_id', $validated['student_id'])
            ->where('pkl_period_id', $validated['pkl_period_id'])
            ->whereNot('status', 'rejected')
            ->exists();

        if ($hasActiveApplication) {
            return response()->json([
                'message' => 'Siswa masih memiliki pendaftaran PKL yang aktif pada periode ini.',
            ], 422);
        }

        $existingCount = PklApplication::query()
            ->where('student_id', $validated['student_id'])
            ->where('pkl_period_id', $validated['pkl_period_id'])
            ->whereNot('status', 'rejected')
            ->count();

        if ($existingCount >= 3) {
            return response()->json([
                'message' => 'Maksimal 3 pilihan perusahaan untuk satu periode PKL.',
            ], 422);
        }

        // Company yang pernah dipilih tidak dapat dipilih ulang pada periode yang
        // sama: application rejected tetap menjadi riwayat (juga dipaksa oleh
        // unique constraint student-company-period pada level database).
        $duplicateCompany = PklApplication::query()
            ->where('student_id', $validated['student_id'])
            ->where('pkl_period_id', $validated['pkl_period_id'])
            ->where('company_id', $validated['company_id'])
            ->exists();

        if ($duplicateCompany) {
            return response()->json([
                'message' => 'Perusahaan tersebut sudah dipilih.',
            ], 422);
        }

        $duplicateChoiceOrder = PklApplication::query()
            ->where('student_id', $validated['student_id'])
            ->where('pkl_period_id', $validated['pkl_period_id'])
            ->whereNot('status', 'rejected')
            ->where('choice_order', $validated['choice_order'])
            ->exists();

        if ($duplicateChoiceOrder) {
            return response()->json([
                'message' => 'Urutan pilihan tersebut sudah digunakan.',
            ], 422);
        }

        $application = PklApplication::create([
            ...$validated,
            'status' => 'pending',
        ]);

        $application->load(['company', 'pklPeriod']);

        return response()->json([
            'message' => 'Pendaftaran PKL berhasil diajukan.',
            'data' => $application,
        ], 201);
    }

    /**
     * Pilihan final siswa: bila diterima beberapa perusahaan, siswa memilih
     * SATU tujuan PKL. Tawaran lain pada periode yang sama ditutup (rejected)
     * agar siswa hanya berada pada satu perusahaan dan kuota perusahaan lain
     * terbebas kembali.
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

        $closedOffers = DB::transaction(function () use ($pklApplication, $request) {
            $closedOffers = PklApplication::query()
                ->where('student_id', $pklApplication->student_id)
                ->where('pkl_period_id', $pklApplication->pkl_period_id)
                ->where('status', 'accepted')
                ->where('id', '!=', $pklApplication->id)
                ->update(['status' => 'rejected']);

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

            return $closedOffers;
        });

        $pklApplication->load(['company', 'pklPeriod']);

        return response()->json([
            'message' => $closedOffers > 0
                ? 'Pilihan final tersimpan. Tawaran lain ditutup dan pengajuan menunggu proses penempatan sekolah.'
                : 'Pilihan final tersimpan. Pengajuan menunggu proses penempatan sekolah.',
            'data' => $pklApplication,
            'closed_offers' => $closedOffers,
        ]);
    }

    public function update(
        Request $request,
        PklApplication $pklApplication
    ): JsonResponse {
        Gate::authorize('update', $pklApplication);

        // Persetujuan sekolah: admin dapat mengubah status lamaran yang sudah
        // diterima perusahaan (accepted) menjadi approved (resmi) / rejected.
        // Hanya admin (dan bukan admin yang berkonflik kepentingan dengan
        // perusahaan tujuan) yang boleh melakukannya — diperiksa di policy.
        if ($request->has('status')) {
            Gate::authorize('approve', $pklApplication);

            if ($pklApplication->status !== 'accepted') {
                return response()->json([
                    'message' => 'Hanya lamaran berstatus diterima perusahaan yang dapat disetujui/ditolak sekolah.',
                ], 422);
            }
            $validated = $request->validate([
                'status' => ['required', 'string', 'in:approved,rejected'],
            ]);
            $pklApplication->update($validated);
            $pklApplication->load(['company', 'pklPeriod']);

            return response()->json([
                'message' => $validated['status'] === 'approved'
                    ? 'Lamaran disetujui sekolah dan resmi.'
                    : 'Lamaran ditolak sekolah.',
                'data' => $pklApplication,
            ]);
        }

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
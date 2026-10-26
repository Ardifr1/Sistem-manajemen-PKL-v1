<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\ApplicationReview;
use App\Models\Company;
use App\Models\PklApplication;
use App\Support\PlacementQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Policies\Concerns\ResolvesCompanyAccess;

class ApplicationReviewController extends Controller
{
    use ResolvesCompanyAccess;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ApplicationReview::class);

        $validated = $request->validate([
            'application_id' => [
                'nullable',
                'integer',
                'exists:pkl_applications,id',
            ],
        ]);

        $query = ApplicationReview::with([
            'application.company',
            'application.student',
            'reviewer',
        ])->orderByDesc('reviewed_at');

        if (isset($validated['application_id'])) {
            $query->where(
                'application_id',
                $validated['application_id']
            );
        }

        // Company hanya melihat review atas lamaran ke perusahaannya.
        if ($request->user()->role === 'company') {
            $companyIds = $this->authorizedCompanyIds($request->user());
            $query->whereHas('application', function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds);
            });
        }

        // Student hanya melihat review atas lamarannya sendiri.
        if ($request->user()->role === 'student') {
            $query->whereHas('application', function ($q) use ($request) {
                $q->where('student_id', $request->user()->id);
            });
        }

        return response()->json([
            'message' => 'Data review pendaftaran berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(ApplicationReview $applicationReview): JsonResponse
    {
        Gate::authorize('view', $applicationReview);

        $applicationReview->load([
            'application.company',
            'application.student',
            'reviewer',
        ]);

        return response()->json([
            'message' => 'Data review pendaftaran berhasil diambil.',
            'data' => $applicationReview,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ApplicationReview::class);

        $validated = $request->validate([
            'application_id' => [
                'required',
                'integer',
                'exists:pkl_applications,id',
            ],
            'reviewer_id' => [
                'sometimes',
                'integer',
                'exists:users,id',
            ],
            'reviewer_role' => [
                'sometimes',
                'string',
                'max:50',
                'in:company,teacher,admin',
            ],
            'status' => [
                'required',
                'string',
                'in:approved,rejected',
            ],
            'note' => [
                'nullable',
                'string',
            ],
        ]);

        // Review selalu tercatat atas nama user yang sedang login.
        $validated['reviewer_id'] = $request->user()->id;
        $validated['reviewer_role'] = $request->user()->role === 'admin'
            ? 'admin'
            : ($request->user()->role === 'company' ? 'company' : $request->user()->role);

        $application = PklApplication::findOrFail($validated['application_id']);

        // Hard Block konflik kepentingan diperiksa di policy (butuh company_id
        // dari lamaran): admin yang menjadi anggota aktif perusahaan tujuan
        // tidak boleh mengambil keputusan atas nama perusahaan tersebut.
        Gate::authorize('createForApplication', [ApplicationReview::class, (int) $application->company_id]);

        // Company hanya boleh mereview lamaran yang ditujukan ke perusahaannya.
        if (
            $request->user()->role === 'company'
            && ! $this->isAuthorizedForCompany($request->user(), $application->company_id)
        ) {
            abort(403);
        }

        // Lamaran yang sudah diterima (accepted) tidak dapat direview ulang.
        if ($application->status === 'accepted') {
            return response()->json([
                'message' => 'Pendaftaran yang sudah diterima tidak dapat direview ulang.',
            ], 422);
        }

        $duplicateReview = ApplicationReview::query()
            ->where('application_id', $validated['application_id'])
            ->where('reviewer_id', $validated['reviewer_id'])
            ->where('reviewer_role', $validated['reviewer_role'])
            ->exists();

        if ($duplicateReview) {
            return response()->json([
                'message' => 'Review oleh penilai tersebut sudah tersedia untuk pendaftaran ini.',
            ], 422);
        }

        // Acceptance (approved) dan pembuatan review dilakukan dalam satu transaksi
        // dengan mengunci baris perusahaan agar jumlah student berstatus accepted
        // tidak dapat melebihi student_quota akibat review bersamaan (race sederhana).
        $result = DB::transaction(function () use ($validated, $application) {
            $company = Company::query()
                ->whereKey($application->company_id)
                ->lockForUpdate()
                ->first();

            if ($validated['status'] === 'approved') {
                $quotaError = $this->quotaExceededMessage($company, $application);

                if ($quotaError !== null) {
                    return ['error' => $quotaError];
                }
            }

            $review = ApplicationReview::create([
                ...$validated,
                'reviewed_at' => now(),
            ]);

            // Approval perusahaan berarti application diterima (accepted);
            // penolakan tetap rejected. Status accepted yang digunakan
            // untuk perhitungan kuota dan syarat pembuatan placement.
            $review->application()->update([
                'status' => $validated['status'] === 'approved' ? 'accepted' : 'rejected',
            ]);

            return ['review' => $review];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        $review = $result['review'];

        $review->load([
            'application.company',
            'application.student',
            'reviewer',
        ]);

        return response()->json([
            'message' => 'Review pendaftaran berhasil disimpan.',
            'data' => $review,
        ], 201);
    }

    /**
     * Mengembalikan pesan kesalahan bila kuota perusahaan untuk periode PKL
     * terkait sudah penuh, atau null bila acceptance masih diperbolehkan.
     *
     * Harus dipanggil di dalam transaksi setelah baris company dikunci
     * (lockForUpdate). Application pending/rejected tidak dihitung sebagai
     * penggunaan kuota.
     */
    private function quotaExceededMessage(Company $company, PklApplication $application): ?string
    {
        $quotaError = PlacementQuota::errorMessage($company, (int) $application->pkl_period_id);

        if ($quotaError !== null) {
            return $quotaError;
        }

        return null;
    }

    public function update(
        Request $request,
        ApplicationReview $applicationReview
    ): JsonResponse {
        Gate::authorize('update', $applicationReview);

        $validated = $request->validate([
            'status' => [
                'sometimes',
                'string',
                'in:approved,rejected',
            ],
            'note' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ]);

        $validated['reviewed_at'] = now();

        // Perubahan status menjadi approved juga wajib melewati pemeriksaan
        // kuota dalam transaksi yang sama (baris company dikunci).
        $statusChanged = isset($validated['status'])
            && $validated['status'] !== $applicationReview->status;

        if ($statusChanged && $validated['status'] === 'approved') {
            $result = DB::transaction(function () use ($request, $validated, $applicationReview) {
                $lockedReview = ApplicationReview::query()
                    ->whereKey($applicationReview->id)
                    ->lockForUpdate()
                    ->first();

                $lockedApplication = PklApplication::query()
                    ->whereKey($lockedReview->application_id)
                    ->lockForUpdate()
                    ->first();

                $company = Company::query()
                    ->whereKey($lockedApplication->company_id)
                    ->lockForUpdate()
                    ->first();

                $quotaError = $this->quotaExceededMessage($company, $lockedApplication);

                if ($quotaError !== null) {
                    return ['error' => $quotaError];
                }

                $lockedReview->update($validated);
                $lockedReview->application()->update([
                    'status' => $validated['status'] === 'approved' ? 'accepted' : 'rejected',
                ]);

                return ['review' => $lockedReview->fresh()];
            });

            if (isset($result['error'])) {
                return response()->json([
                    'message' => $result['error'],
                ], 422);
            }

            $applicationReview = $result['review'];
        } else {
            $applicationReview->update($validated);

            if (isset($validated['status'])) {
                $applicationReview->application()->update([
                    'status' => $validated['status'] === 'approved' ? 'accepted' : 'rejected',
                ]);
            }
        }

        $applicationReview->load([
            'application.company',
            'application.student',
            'reviewer',
        ]);

        return response()->json([
            'message' => 'Review pendaftaran berhasil diperbarui.',
            'data' => $applicationReview,
        ]);
    }

    public function destroy(
        ApplicationReview $applicationReview
    ): JsonResponse {
        Gate::authorize('delete', $applicationReview);

        $applicationReview->delete();

        return response()->json([
            'message' => 'Review pendaftaran berhasil dihapus.',
        ]);
    }
}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Pengelolaan KEANGGOTAAN PERUSAHAAN (PRD bagian C).
 *
 * Menyatakan hubungan user <-> perusahaan secara eksplisit beserta
 * kapabilitasnya (company_admin, review_applications, verify_journals) dan
 * status aktif/nonaktif. Pemberian/pencabutan akses hanya oleh Admin dan
 * setiap perubahan dicatat pada audit_logs.
 */
class CompanyMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CompanyMembership::class);

        $me = $request->user();
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in([CompanyMembership::STATUS_ACTIVE, CompanyMembership::STATUS_INACTIVE])],
        ]);

        $query = CompanyMembership::query()
            ->with(['user:id,name,username,email,role', 'company:id,name,industry'])
            ->orderByDesc('id');

        // Non-admin hanya boleh melihat keanggotaannya sendiri.
        if ($me->role !== 'admin') {
            $query->where('user_id', $me->id);
        } elseif (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (isset($validated['company_id'])) {
            $query->where('company_id', $validated['company_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'message' => 'Data keanggotaan perusahaan berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(CompanyMembership $companyMembership): JsonResponse
    {
        Gate::authorize('view', $companyMembership);

        $companyMembership->load([
            'user:id,name,username,email,role',
            'company:id,name,industry',
            'grantedBy:id,name',
        ]);

        return response()->json([
            'message' => 'Data keanggotaan perusahaan berhasil diambil.',
            'data' => $companyMembership,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CompanyMembership::class);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'status' => ['sometimes', Rule::in([CompanyMembership::STATUS_ACTIVE, CompanyMembership::STATUS_INACTIVE])],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => [Rule::in(CompanyMembership::CAPABILITIES)],
        ]);

        $status = $validated['status'] ?? CompanyMembership::STATUS_ACTIVE;
        $capabilities = array_values(array_unique($validated['capabilities'] ?? []));

        $existing = CompanyMembership::query()
            ->where('user_id', $validated['user_id'])
            ->where('company_id', $validated['company_id'])
            ->first();

        $membership = CompanyMembership::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'company_id' => $validated['company_id'],
            ],
            [
                'status' => $status,
                'capabilities' => $capabilities,
                'granted_by' => $request->user()->id,
                'granted_at' => now(),
                'revoked_at' => $status === CompanyMembership::STATUS_ACTIVE ? null : now(),
            ]
        );

        $this->log(
            $request,
            $membership,
            $existing === null ? 'company_membership.granted' : 'company_membership.updated',
            $existing?->status,
            $status,
            null,
            $capabilities
        );

        $this->seedCompanyContact($membership);

        $membership->load(['user:id,name,username,email,role', 'company:id,name,industry']);

        return response()->json([
            'message' => 'Keanggotaan perusahaan berhasil disimpan.',
            'data' => $membership,
        ], $existing === null ? 201 : 200);
    }

    /**
     * Begitu sebuah akun diberi akses ke perusahaan, kontak perusahaan
     * yang masih kosong diisi dari data akun itu (telepon & email yang
     * diisi admin saat membuat pengguna) — profil perusahaan langsung
     * terisi tanpa perusahaan mengetik ulang, dan tetap bisa diubah
     * perusahaan sendiri setelahnya. Nilai yang sudah ada tidak ditimpa.
     */
    private function seedCompanyContact(CompanyMembership $membership): void
    {
        if ($membership->status !== CompanyMembership::STATUS_ACTIVE) {
            return;
        }

        $membership->loadMissing(['user', 'company']);
        $user = $membership->user;
        $company = $membership->company;

        if (! $user || ! $company) {
            return;
        }

        $fill = [];
        if (empty($company->phone) && ! empty($user->phone)) {
            $fill['phone'] = $user->phone;
        }
        if (empty($company->email) && ! empty($user->email)) {
            $fill['email'] = $user->email;
        }

        if ($fill !== []) {
            $company->fill($fill)->save();
        }
    }

    public function update(Request $request, CompanyMembership $companyMembership): JsonResponse
    {
        Gate::authorize('update', $companyMembership);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([CompanyMembership::STATUS_ACTIVE, CompanyMembership::STATUS_INACTIVE])],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => [Rule::in(CompanyMembership::CAPABILITIES)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $oldStatus = $companyMembership->status;
        $newStatus = $validated['status'] ?? $oldStatus;

        $companyMembership->update([
            'status' => $newStatus,
            'capabilities' => array_key_exists('capabilities', $validated)
                ? array_values(array_unique($validated['capabilities']))
                : $companyMembership->capabilities,
            'granted_by' => $request->user()->id,
            'revoked_at' => $newStatus === CompanyMembership::STATUS_ACTIVE ? null : ($companyMembership->revoked_at ?? now()),
        ]);

        $this->log(
            $request,
            $companyMembership,
            $newStatus === CompanyMembership::STATUS_ACTIVE ? 'company_membership.updated' : 'company_membership.revoked',
            $oldStatus,
            $newStatus,
            $validated['reason'] ?? null,
            $companyMembership->capabilities ?? []
        );

        $this->seedCompanyContact($companyMembership);

        $companyMembership->load(['user:id,name,username,email,role', 'company:id,name,industry']);

        return response()->json([
            'message' => 'Keanggotaan perusahaan berhasil diperbarui.',
            'data' => $companyMembership,
        ]);
    }

    public function destroy(Request $request, CompanyMembership $companyMembership): JsonResponse
    {
        Gate::authorize('delete', $companyMembership);

        $oldStatus = $companyMembership->status;
        $this->log(
            $request,
            $companyMembership,
            'company_membership.revoked',
            $oldStatus,
            'removed',
            null,
            $companyMembership->capabilities ?? []
        );

        $companyMembership->delete();

        return response()->json([
            'message' => 'Keanggotaan perusahaan berhasil dihapus.',
        ]);
    }

    /**
     * Catat perubahan akses pada audit log.
     *
     * @param  array<int, string>  $capabilities
     */
    private function log(
        Request $request,
        CompanyMembership $membership,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $reason,
        array $capabilities
    ): void {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => CompanyMembership::class,
            'subject_id' => $membership->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'metadata' => [
                'membership_id' => $membership->id,
                'target_user_id' => $membership->user_id,
                'company_id' => $membership->company_id,
                'capabilities' => $capabilities,
            ],
        ]);
    }
}

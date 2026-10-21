<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanyMembership;
use App\Models\CompanySupervisor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompanySupervisorController extends Controller
{
    /** Ambil company_id milik user company/supervisor yang login. */
    private function myCompanyId(User $user): ?int
    {
        if ($user->companySupervisor?->company_id) {
            return $user->companySupervisor->company_id;
        }

        // Akun yang ditautkan admin lewat keanggotaan (company_memberships)
        // tidak memiliki baris company_supervisors — kenali juga tautan
        // itu, sama seperti akun dari jalur pengajuan perusahaan.
        return $user->companyMemberships()
            ->where('status', CompanyMembership::STATUS_ACTIVE)
            ->orderBy('id')
            ->value('company_id');
    }

    private function canManage(User $user, CompanySupervisor $supervisor): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        return $user->role === 'company'
            && $supervisor->company_id === $this->myCompanyId($user);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = CompanySupervisor::with(['user', 'company'])
            ->withCount(['placements as students_count'])
            ->orderBy('id', 'desc');

        if ($user->role === 'company') {
            $companyId = $this->myCompanyId($user);
            if (! $companyId) {
                return response()->json([
                    'message' => 'Akun perusahaan belum ditautkan ke perusahaan.',
                    'data' => [],
                ]);
            }
            $query->where('company_id', $companyId);
        } elseif (! in_array($user->role, ['admin', 'supervisor'], true)) {
            abort(403);
        }

        return response()->json([
            'message' => 'Data pembimbing industri berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(Request $request, CompanySupervisor $companySupervisor): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $companySupervisor)) {
            abort(403);
        }

        $companySupervisor->load(['user', 'company', 'placements.student']);

        return response()->json([
            'message' => 'Data pembimbing industri berhasil diambil.',
            'data' => $companySupervisor,
        ]);
    }

    /**
     * Perusahaan membuat akun pembimbing industri untuk perusahaannya sendiri.
     * Admin dapat membuat untuk perusahaan mana pun.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $companyId = $request->input('company_id');
        if ($user->role === 'company') {
            $companyId = $this->myCompanyId($user);
            if (! $companyId) {
                abort(403, 'Akun perusahaan belum terhubung ke data perusahaan.');
            }
        } elseif ($user->role !== 'admin') {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $supervisor = DB::transaction(function () use ($validated, $companyId) {
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['username'] ?? explode('@', $validated['email'])[0],
                'password' => Hash::make($validated['password']),
                'role' => 'supervisor',
                'is_active' => true,
            ]);

            return CompanySupervisor::create([
                'user_id' => $newUser->id,
                'company_id' => $companyId,
                'position' => $validated['position'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ]);
        });

        $supervisor->load(['user', 'company']);

        return response()->json([
            'message' => 'Akun pembimbing industri berhasil dibuat.',
            'data' => $supervisor,
        ], 201);
    }

    public function update(Request $request, CompanySupervisor $companySupervisor): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $companySupervisor)) {
            abort(403);
        }

        $validated = $request->validate([
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($companySupervisor, $validated) {
            $companySupervisor->update([
                'position' => $validated['position'] ?? $companySupervisor->position,
                'phone' => $validated['phone'] ?? $companySupervisor->phone,
            ]);
            if (array_key_exists('is_active', $validated)) {
                $companySupervisor->user->update(['is_active' => $validated['is_active']]);
            }
        });

        $companySupervisor->load(['user', 'company']);

        return response()->json([
            'message' => 'Data pembimbing industri berhasil diperbarui.',
            'data' => $companySupervisor,
        ]);
    }

    public function destroy(Request $request, CompanySupervisor $companySupervisor): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $companySupervisor)) {
            abort(403);
        }

        DB::transaction(function () use ($companySupervisor) {
            $companySupervisor->user?->delete();
            $companySupervisor->delete();
        });

        return response()->json([
            'message' => 'Pembimbing industri berhasil dihapus.',
        ]);
    }
}

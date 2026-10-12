<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $query = User::query()
            ->select([
                'id',
                'name',
                'username',
                'email',
                'role',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('email', 'like', $s)->orWhere('username', 'like', $s));
            });

        // Batasi akses non-admin: hanya user yang relevan
        if ($me->role === 'teacher') {
            $query->where(function ($q) use ($me) {
                $q->where('id', $me->id)
                  ->orWhere(function ($w) use ($me) {
                      $w->where('role', 'student')->where('teacher_id', $me->id);
                  });
            });
        } elseif (in_array($me->role, ['company', 'supervisor'], true)) {
            $companyId = $me->companySupervisor?->company_id;
            $query->where(function ($q) use ($me, $companyId) {
                $q->where('id', $me->id);
                if ($companyId) {
                    $q->orWhere(function ($w) use ($companyId) {
                        $w->where('role', 'student')->whereHas('placements', fn ($p) => $p->where('company_id', $companyId));
                    });
                }
            });
        }

        $users = $query->orderBy('name')->get();

        return response()->json([
            'message' => 'Data pengguna berhasil diambil.',
            'data' => $users,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->makeHidden('password');

        return response()->json([
            'message' => 'Data pengguna berhasil diambil.',
            'data' => $user,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
            'role' => [
                'required',
                'string',
                'max:50',
                'in:admin,teacher,student,company,supervisor',
            ],
            'is_active' => [
                'boolean',
            ],
            'nis' => [
                'nullable',
                'string',
                'max:50',
            ],
            'nip' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $nis = $validated['nis'] ?? null;
        $nip = $validated['nip'] ?? null;
        unset($validated['nis'], $validated['nip']);

        $validated['password'] = Hash::make(
            $validated['password']
        );

        $user = User::create($validated);

        // Simpan NIS ke profil siswa / NIP ke user
        if ($user->role === 'student' && $nis) {
            \App\Models\StudentProfile::updateOrCreate(
                ['user_id' => $user->id],
                ['student_number' => $nis]
            );
        }
        if ($user->role === 'teacher' && $nip) {
            $user->update(['nip' => $nip]);
        }

        $user->makeHidden('password');

        return response()->json([
            'message' => 'Pengguna berhasil ditambahkan.',
            'data' => $user,
        ], 201);
    }

    public function update(
        Request $request,
        User $user
    ): JsonResponse {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('users', 'username')
                    ->ignore($user->id),
            ],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($user->id),
            ],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:8',
                'confirmed',
            ],
            'role' => [
                'sometimes',
                'string',
                'max:50',
                'in:admin,teacher,student,company,supervisor',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (
            isset($validated['password']) &&
            $validated['password'] !== null
        ) {
            $validated['password'] = Hash::make(
                $validated['password']
            );
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        $user->makeHidden('password');

        return response()->json([
            'message' => 'Data pengguna berhasil diperbarui.',
            'data' => $user->fresh(),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'message' => 'Pengguna berhasil dihapus.',
        ]);
    }

    /** Daftar permintaan reset password (khusus admin). */
    public function resetRequests(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $rows = PasswordResetRequest::with('user:id,name,username,email,role')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Data permintaan reset berhasil diambil.',
            'data' => $rows,
        ]);
    }

    /** Tandai permintaan reset selesai (khusus admin). */
    public function resolveResetRequest(Request $request, PasswordResetRequest $resetRequest): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $resetRequest->update(['status' => 'done']);

        return response()->json([
            'message' => 'Permintaan ditandai selesai.',
        ]);
    }
}
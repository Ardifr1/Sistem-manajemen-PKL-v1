<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\AccountInfoNotifier;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $me = $request->user();

        // Nomor WhatsApp hanya perlu untuk admin (form edit/detail). Jangan
        // perluas data pribadi ke peran lain yang hanya butuh nama/email.
        $columns = [
            'id',
            'name',
            'username',
            'email',
            'role',
            'is_active',
            'created_at',
            'updated_at',
        ];

        if ($me->role === 'admin') {
            $columns[] = 'phone';
        }

        $query = User::query()
            ->select($columns)
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('email', 'like', $s)->orWhere('username', 'like', $s));
            });

        // Batasi akses non-admin: hanya user yang relevan
        if ($me->role === 'teacher') {
            // Siswa bimbingan guru ditentukan lewat pkl_placements.teacher_id
            // (tabel users tidak memiliki kolom teacher_id).
            $query->where(function ($q) use ($me) {
                $q->where('id', $me->id)
                  ->orWhere(function ($w) use ($me) {
                      $w->where('role', 'student')
                        ->whereHas('placements', fn ($p) => $p->where('teacher_id', $me->id));
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
        Gate::authorize('view', $user);

        $user->makeHidden('password');

        return response()->json([
            'message' => 'Data pengguna berhasil diambil.',
            'data' => $user,
        ]);
    }

    public function store(Request $request, AccountInfoNotifier $notifier): JsonResponse
    {
        Gate::authorize('create', User::class);

        $this->normalizePhoneInput($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                'regex:'.PhoneNumber::REGEX,
            ],
            // Username tidak lagi diisi admin — dibuat otomatis bila kosong,
            // namun tetap boleh dikirim eksplisit (mis. dari impor).
            'username' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'unique:users,username',
            ],
            // Password opsional. Bila kosong, sistem TIDAK membuat password
            // yang diketahui admin; pengguna mengatur sendiri via tautan
            // aktivasi (email + WhatsApp).
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:8',
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
        $plainPassword = $validated['password'] ?? null;
        unset($validated['nis'], $validated['nip'], $validated['password']);

        // Username otomatis & unik bila admin tidak mengisinya.
        $validated['username'] = $validated['username']
            ?? $this->uniqueUsername($validated['name'], $validated['email']);

        $hasExplicitPassword = ! empty($plainPassword);
        $rawToken = null;

        if ($hasExplicitPassword) {
            // Cast 'hashed' pada model akan meng-hash nilainya.
            $validated['password'] = $plainPassword;
        } else {
            // Password acak yang tidak diketahui siapa pun + tautan aktivasi.
            $rawToken = Str::random(64);
            $validated['password'] = Str::random(48);
            $validated['password_setup_token'] = hash('sha256', $rawToken);
            $validated['password_setup_expires_at'] = now()->addHours(
                (int) config('simagang.activation.expires_hours', 72)
            );
        }

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

        // Kirim informasi akun (tautan aktivasi) via email + WhatsApp.
        // Kegagalan notifikasi TIDAK boleh membatalkan akun yang sudah dibuat.
        $delivery = $rawToken !== null
            ? $this->notifySafely($notifier, $user, $rawToken)
            : null;

        $user->makeHidden('password');

        return response()->json([
            'message' => 'Pengguna berhasil ditambahkan.',
            'data' => $user,
            'delivery' => $delivery,
        ], 201);
    }

    /**
     * Kirim ulang informasi akun (tautan aktivasi) ke email & WhatsApp.
     */
    public function resendAccountInfo(
        Request $request,
        User $user,
        AccountInfoNotifier $notifier
    ): JsonResponse {
        // Hanya admin (policy create) yang boleh mengirim ulang info akun.
        Gate::authorize('create', User::class);

        // Token mentah lama tidak bisa dipulihkan dari hash. Simpan nilai lama
        // agar tautan yang sudah beredar tetap valid bila TIDAK ada kanal yang
        // berhasil mengirim token baru.
        $previousToken = $user->password_setup_token;
        $previousExpiry = $user->password_setup_expires_at;

        $rawToken = Str::random(64);
        $user->update([
            'password_setup_token' => hash('sha256', $rawToken),
            'password_setup_expires_at' => now()->addHours(
                (int) config('simagang.activation.expires_hours', 72)
            ),
        ]);

        $delivery = $this->notifySafely($notifier, $user->fresh(), $rawToken);

        if ($previousToken !== null && ! $this->anyChannelSent($delivery)) {
            // Tidak ada penerima yang mendapatkan token baru: kembalikan token
            // lama agar tautan sebelumnya tidak ikut rusak. Tidak ada akun
            // baru yang dibuat oleh operasi ini.
            $user->update([
                'password_setup_token' => $previousToken,
                'password_setup_expires_at' => $previousExpiry,
            ]);
        }

        return response()->json([
            'message' => 'Informasi akun dikirim ulang.',
            'data' => ['delivery' => $delivery],
        ]);
    }

    /**
     * Nomor yang diketik bebas (spasi/strip) dinormalisasi sebelum validasi,
     * lalu disimpan sebagai 628xxxxxxxxxx.
     */
    private function normalizePhoneInput(Request $request): void
    {
        if (! $request->exists('phone')) {
            return;
        }

        $phone = $request->input('phone');

        if (is_string($phone) || $phone === null) {
            $request->merge(['phone' => PhoneNumber::normalize($phone)]);
        }
    }

    /**
     * @return array{email: array{status:string,message:string,driver?:string}, whatsapp: array{status:string,message:string}}
     */
    private function notifySafely(AccountInfoNotifier $notifier, User $user, string $rawToken): array
    {
        try {
            return $notifier->send($user, $rawToken);
        } catch (Throwable $e) {
            // Jangan log pesan exception mentah: bisa memuat detail internal.
            Log::error('Gagal mengirim informasi akun.', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);

            return [
                'email' => [
                    'status' => 'failed',
                    'message' => 'Email gagal dikirim.',
                ],
                'whatsapp' => [
                    'status' => 'failed',
                    'message' => 'WhatsApp gagal dikirim.',
                ],
            ];
        }
    }

    /** @param array<string, array{status?:string}> $delivery */
    private function anyChannelSent(array $delivery): bool
    {
        return ($delivery['email']['status'] ?? null) === 'sent'
            || ($delivery['whatsapp']['status'] ?? null) === 'sent';
    }

    /**
     * Username otomatis unik dari nama (fallback: email).
     */
    private function uniqueUsername(string $name, string $email): string
    {
        $base = Str::slug($name, '.');

        if ($base === '') {
            $base = Str::slug(Str::before($email, '@'), '.');
        }

        if ($base === '') {
            $base = 'pengguna';
        }

        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public function update(
        Request $request,
        User $user
    ): JsonResponse {
        Gate::authorize('update', $user);

        $this->normalizePhoneInput($request);

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
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
                'regex:'.PhoneNumber::REGEX,
            ],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:8',
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

        // Non-admin hanya boleh memperbarui profilnya sendiri — jangan biarkan
        // mereka mengubah role/is_active (pencegahan eskalasi hak akses).
        if ($request->user()->role !== 'admin') {
            unset($validated['role'], $validated['is_active']);
        }

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
        Gate::authorize('delete', $user);

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
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\AccountInfoNotifier;
use App\Policies\Concerns\ResolvesCompanyAccess;
use App\Support\PhoneNumber;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    use ResolvesCompanyAccess;

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
        } elseif ($me->role === 'supervisor') {
            // BR-09a: pembimbing industri hanya melihat siswa yang secara
            // spesifik ditugaskan kepadanya, bukan seluruh siswa perusahaannya.
            $supervisor = $me->companySupervisor;
            $query->where(function ($q) use ($me, $supervisor) {
                $q->where('id', $me->id);
                if ($supervisor) {
                    $q->orWhere(function ($w) use ($supervisor) {
                        $w->where('role', 'student')
                          ->whereHas('placements', fn ($p) => $p->where('supervisor_id', $supervisor->id));
                    });
                }
            });
        } elseif ($me->role === 'company') {
            // Kewenangan perusahaan mengikuti keanggotaan aktif (dan tautan
            // pembimbing lama), bukan hanya satu baris company_supervisors.
            $companyIds = $this->authorizedCompanyIds($me);
            $query->where(function ($q) use ($me, $companyIds) {
                $q->where('id', $me->id);
                if ($companyIds !== []) {
                    $q->orWhere(function ($w) use ($companyIds) {
                        $w->where('role', 'student')
                          ->whereHas('placements', fn ($p) => $p->whereIn('company_id', $companyIds));
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
        $temporaryPassword = null;

        if ($hasExplicitPassword) {
            // Password manual admin dipakai apa adanya (tidak diganti diam-diam).
            // Cast 'hashed' pada model akan meng-hash nilainya.
            // Pengguna wajib mengganti password ini setelah login pertama.
            $validated['password'] = $plainPassword;
            $validated['must_change_password'] = true;
            $validated['activated_at'] = now();
        } else {
            // Password sementara: dibuat di backend (acak, kuat, unik),
            // disimpan sebagai hash, lalu dikirim via WhatsApp/email.
            // Tidak ada token aktivasi untuk akun baru.
            $temporaryPassword = TemporaryPassword::generate();
            $validated['password'] = Hash::make($temporaryPassword);
            $validated['must_change_password'] = true;
            $validated['password_setup_token'] = null;
            $validated['password_setup_expires_at'] = null;
        }

        // Akun + profil wajib tersimpan dulu dalam satu transaksi;
        // notifikasi baru dikirim setelah transaksi berhasil.
        $user = DB::transaction(function () use ($validated, $nis, $nip) {
            $created = User::create($validated);

            if ($created->role === 'student' && $nis) {
                \App\Models\StudentProfile::updateOrCreate(
                    ['user_id' => $created->id],
                    ['student_number' => $nis]
                );
            }
            if ($created->role === 'teacher' && $nip) {
                $created->update(['nip' => $nip]);
            }

            return $created->fresh();
        });

        // Kirim informasi login via email + WhatsApp.
        // Kegagalan notifikasi TIDAK boleh membatalkan akun yang sudah dibuat.
        $delivery = $temporaryPassword !== null
            ? $this->notifyCredentialsSafely($notifier, $user, $temporaryPassword)
            : null;

        // Jejak audit pembuatan akun — tanpa password/rahasia apa pun.
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'account.created',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => [
                'target_user_id' => (int) $user->id,
                'role' => $user->role,
                'credential_mode' => $hasExplicitPassword ? 'manual' : 'temporary',
                'email_status' => $delivery['email']['status'] ?? null,
                'whatsapp_status' => $delivery['whatsapp']['status'] ?? null,
            ],
        ]);

        $user->makeHidden('password');

        return response()->json([
            'message' => 'Pengguna berhasil ditambahkan.',
            'data' => $user,
            'delivery' => $delivery,
        ], 201);
    }

    /**
     * Kirim ulang kredensial/informasi akun ke email & WhatsApp.
     *
     * - Akun legacy dengan token aktivasi yang masih berlaku: kirim ulang
     *   tautan aktivasi (token diregenerasi seperti sebelumnya).
     * - Akun dengan password sementara yang belum diganti: buat password
     *   sementara BARU, simpan hash-nya, kirim yang baru. Password lama
     *   otomatis tidak berlaku lagi.
     * - Akun yang sudah punya password sendiri: tidak ada yang dikirim ulang.
     *
     * Tidak pernah membuat akun duplikat.
     */
    public function resendAccountInfo(
        Request $request,
        User $user,
        AccountInfoNotifier $notifier
    ): JsonResponse {
        // Hanya admin (policy create) yang boleh mengirim ulang info akun.
        Gate::authorize('create', User::class);

        // 1. Legacy: token aktivasi masih berlaku → kirim ulang tautan.
        if (
            $user->password_setup_token
            && $user->password_setup_expires_at
            && $user->password_setup_expires_at->isFuture()
        ) {
            // Token mentah lama tidak bisa dipulihkan dari hash. Simpan nilai
            // lama agar tautan yang sudah beredar tetap valid bila TIDAK ada
            // kanal yang berhasil mengirim token baru.
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
                $user->update([
                    'password_setup_token' => $previousToken,
                    'password_setup_expires_at' => $previousExpiry,
                ]);
            }

            $this->auditResend($request, $user, 'account.activation_resent', $delivery);

            return response()->json([
                'message' => 'Tautan aktivasi dikirim ulang.',
                'data' => ['delivery' => $delivery],
            ]);
        }

        // 2. Password sementara belum diganti → terbitkan yang baru.
        //
        // Hash lama disimpan hanya di memori (bukan plaintext) agar dapat
        // dipulihkan bila SEMUA kanal gagal: akun tidak boleh tertinggal
        // memakai password baru yang tidak diketahui siapa pun.
        if ($user->must_change_password) {
            $temporaryPassword = TemporaryPassword::generate();

            // Kunci baris agar dua permintaan kirim ulang tidak saling menimpa.
            // Hash lama dibaca di dalam kunci sehingga nilai rollback akurat.
            [$locked, $previousHash, $previousMustChange] = DB::transaction(function () use ($user, $temporaryPassword) {
                $fresh = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $previousHash = $fresh->password;
                $previousMustChange = (bool) $fresh->must_change_password;

                $fresh->update(['password' => Hash::make($temporaryPassword)]);

                return [$fresh, $previousHash, $previousMustChange];
            });

            $delivery = $this->notifyCredentialsSafely($notifier, $locked, $temporaryPassword);

            if (! $this->anyChannelSent($delivery)) {
                // Rollback atomik: hanya pulihkan bila hash belum diubah
                // permintaan lain sejak kita menulisnya.
                User::query()
                    ->whereKey($locked->id)
                    ->where('password', $locked->password)
                    ->update([
                        'password' => $previousHash,
                        'must_change_password' => $previousMustChange,
                    ]);

                $this->auditResend($request, $locked, 'account.credentials_resend_failed', $delivery);

                return response()->json([
                    'message' => 'Kredensial baru gagal dikirim melalui semua kanal. Password akun tidak diubah.',
                    'data' => ['delivery' => $delivery],
                ], 422);
            }

            // Password benar-benar berubah dan sudah dikirim ke pengguna:
            // token/sesi lama tidak boleh tetap berlaku.
            $locked->tokens()->delete();

            $this->auditResend($request, $locked, 'account.credentials_resent', $delivery);

            return response()->json([
                'message' => 'Password sementara baru telah dibuat dan dikirim. Password lama tidak berlaku lagi.',
                'data' => ['delivery' => $delivery],
            ]);
        }

        // 3. Akun sudah memiliki password sendiri → tidak ada yang dikirim.
        return response()->json([
            'message' => 'Akun sudah memiliki password sendiri. Tidak ada kredensial yang dikirim ulang.',
            'data' => ['delivery' => null],
        ], 422);
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
    private function notifyCredentialsSafely(
        AccountInfoNotifier $notifier,
        User $user,
        string $temporaryPassword
    ): array {
        try {
            return $notifier->sendCredentials($user, $temporaryPassword);
        } catch (Throwable $e) {
            // Jangan log password: hanya kelas exception yang dicatat.
            Log::error('Gagal mengirim kredensial akun.', [
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

    /**
     * Catat tindakan kirim ulang pada audit log.
     *
     * Hanya status kanal yang dicatat — password, token, dan tautan aktivasi
     * tidak pernah masuk log.
     *
     * @param  array<string, array{status?:string}>  $delivery
     */
    private function auditResend(Request $request, User $user, string $action, array $delivery): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => [
                'target_user_id' => (int) $user->id,
                'email_status' => $delivery['email']['status'] ?? null,
                'whatsapp_status' => $delivery['whatsapp']['status'] ?? null,
            ],
        ]);
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

        $actor = $request->user();
        $isAdmin = $actor->role === 'admin';
        $targetIsSelf = (int) $actor->id === (int) $user->id;

        // Non-admin hanya boleh memperbarui profilnya sendiri — jangan biarkan
        // mereka mengubah role/is_active (pencegahan eskalasi hak akses).
        if (! $isAdmin) {
            unset($validated['role'], $validated['is_active']);

            // Password tidak boleh ditetapkan lewat endpoint ini: jalur yang sah
            // adalah /change-password (wajib memverifikasi password saat ini).
            if (array_key_exists('password', $validated)) {
                return response()->json([
                    'message' => 'Password hanya dapat diubah melalui fitur ganti password.',
                ], 403);
            }
        }

        $adminSetPassword = false;

        if (
            isset($validated['password']) &&
            $validated['password'] !== null
        ) {
            // Admin yang mengatur password pengguna lain: pengguna wajib
            // menggantinya saat login berikutnya.
            $adminSetPassword = $isAdmin && ! $targetIsSelf;

            $validated['password'] = Hash::make(
                $validated['password']
            );

            if ($adminSetPassword) {
                $validated['must_change_password'] = true;
            }
        } else {
            unset($validated['password']);
        }

        $previousRole = $user->role;
        $previousActive = (bool) $user->is_active;

        $user->update($validated);

        if ($adminSetPassword) {
            // Password diganti admin → sesi lama pengguna tidak boleh tetap aktif.
            $user->tokens()->delete();
        }

        // Jejak audit untuk perubahan sensitif (tanpa nilai password).
        if ($isAdmin) {
            $changed = [];

            if (array_key_exists('role', $validated) && $validated['role'] !== $previousRole) {
                $changed[] = 'role';
            }

            if (array_key_exists('is_active', $validated) && (bool) $validated['is_active'] !== $previousActive) {
                $changed[] = 'is_active';
            }

            if ($adminSetPassword) {
                $changed[] = 'password';
            }

            if ($changed !== []) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => 'account.updated',
                    'subject_type' => User::class,
                    'subject_id' => $user->id,
                    'old_status' => $previousActive ? 'active' : 'inactive',
                    'new_status' => $user->is_active ? 'active' : 'inactive',
                    'metadata' => [
                        'target_user_id' => (int) $user->id,
                        'changed' => $changed,
                        'old_role' => $previousRole,
                        'new_role' => $user->role,
                    ],
                ]);
            }
        }

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

        // Permintaan yang melewati masa berlaku tidak lagi ditampilkan sebagai
        // pending agar admin tidak membuat password baru atas permintaan lama.
        $cutoff = now()->subHours(
            (int) config('simagang.password_reset.expires_hours', 72)
        );

        PasswordResetRequest::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 'expired']);

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

        if ($resetRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Permintaan reset sudah diproses atau kedaluwarsa.',
            ], 422);
        }

        $cutoff = now()->subHours(
            (int) config('simagang.password_reset.expires_hours', 72)
        );

        if ($resetRequest->created_at !== null && $resetRequest->created_at->lt($cutoff)) {
            $resetRequest->update(['status' => 'expired']);

            return response()->json([
                'message' => 'Permintaan reset sudah kedaluwarsa. Pengguna harus mengajukan permintaan baru.',
            ], 422);
        }

        $resetRequest->update(['status' => 'done']);

        return response()->json([
            'message' => 'Permintaan ditandai selesai.',
        ]);
    }
}
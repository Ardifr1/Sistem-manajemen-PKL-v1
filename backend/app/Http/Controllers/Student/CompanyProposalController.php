<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySupervisor;
use App\Models\User;
use App\Services\AccountInfoNotifier;
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

/**
 * Alur perusahaan NONMITRA (PRD bagian D).
 *
 * Siswa mengajukan perusahaan lewat halaman pemilihan perusahaan yang sudah
 * ada. Pengajuan masuk sebagai data perusahaan dengan status
 * `pending_school_verification`; Admin Sekolah menyetujui (perusahaan menjadi
 * mitra dan langsung dapat dipakai alur pengajuan yang sudah ada) atau
 * menolak dengan alasan.
 *
 * Tidak ada alur paralel: begitu disetujui, perusahaan diproses melalui
 * PklApplicationController yang sudah ada.
 */
class CompanyProposalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $query = Company::query()
            ->with(['submitter:id,name,username,email,role', 'verifier:id,name'])
            ->orderByDesc('id');

        if ($me->role === 'admin') {
            $validated = $request->validate([
                'verification_status' => [
                    'nullable',
                    Rule::in([
                        Company::VERIFICATION_PENDING,
                        Company::VERIFICATION_VERIFIED,
                        Company::VERIFICATION_REJECTED,
                    ]),
                ],
            ]);

            // Hanya perusahaan yang berasal dari pengajuan siswa.
            $query->whereNotNull('submitted_by');

            if (isset($validated['verification_status'])) {
                $query->where('verification_status', $validated['verification_status']);
            }
        } elseif ($me->role === 'student') {
            $query->where('submitted_by', $me->id);
        } else {
            abort(403);
        }

        return response()->json([
            'message' => 'Data pengajuan perusahaan berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $me = $request->user();

        if ($me->role !== 'admin' && (int) $company->submitted_by !== (int) $me->id) {
            abort(403);
        }

        $company->load(['submitter:id,name,username,email,role', 'verifier:id,name']);

        return response()->json([
            'message' => 'Data pengajuan perusahaan berhasil diambil.',
            'data' => $company,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('submitProposal', Company::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'contact_person' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Bukti opsional (proposal/legalitas). Maksimal 5 MB.
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        // Cegah pengajuan ganda: nama perusahaan yang sudah ada (mitra,
        // pending, maupun ditolak) tidak boleh diajukan lagi.
        $duplicate = Company::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Perusahaan dengan nama tersebut sudah terdaftar pada sistem.',
            ], 422);
        }

        $proofPath = $request->hasFile('proof')
            ? $request->file('proof')->store('company-proposals')
            : null;

        $company = DB::transaction(function () use ($validated, $proofPath, $request) {
            $company = Company::create([
                'name' => trim($validated['name']),
                'industry' => $validated['industry'],
                'description' => $validated['description'] ?? null,
                'address' => $validated['address'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'contact_person' => $validated['contact_person'],
                // Nilai berikut TIDAK boleh berasal dari request siswa.
                'student_quota' => 0,
                'is_partner' => false,
                'is_active' => false,
                'verification_status' => Company::VERIFICATION_PENDING,
                'submitted_by' => $request->user()->id,
                'proof_path' => $proofPath,
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'company_proposal.submitted',
                'subject_type' => Company::class,
                'subject_id' => $company->id,
                'new_status' => Company::VERIFICATION_PENDING,
                'metadata' => [
                    'company_id' => $company->id,
                    'name' => $company->name,
                ],
            ]);

            return $company;
        });

        $company->load(['submitter:id,name,username,email,role']);

        return response()->json([
            'message' => 'Pengajuan perusahaan berhasil dikirim dan menunggu verifikasi sekolah.',
            'data' => $company,
        ], 201);
    }

    /**
     * Verifikasi pengajuan perusahaan oleh Admin Sekolah.
     * approve -> perusahaan menjadi mitra & aktif (dipakai alur pengajuan biasa)
     * reject  -> wajib alasan.
     */
    public function verify(Request $request, Company $company, AccountInfoNotifier $notifier): JsonResponse
    {
        Gate::authorize('verifyProposal', Company::class);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'],
            // Akses (kapabilitas) untuk akun perusahaan yang dibuat otomatis
            // saat persetujuan. Default: akses penuh.
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => [Rule::in(CompanyMembership::CAPABILITIES)],
        ]);

        if (! $company->isPendingSchoolVerification()) {
            return response()->json([
                'message' => 'Hanya perusahaan berstatus menunggu verifikasi yang dapat diproses.',
            ], 422);
        }

        if ($validated['action'] === 'reject' && empty($validated['reason'])) {
            return response()->json([
                'message' => 'Alasan penolakan wajib diisi.',
            ], 422);
        }

        $approved = $validated['action'] === 'approve';
        $oldStatus = $company->verification_status;
        $newStatus = $approved ? Company::VERIFICATION_VERIFIED : Company::VERIFICATION_REJECTED;

        // Akun perusahaan dibuat otomatis saat disetujui — email harus unik.
        if ($approved && User::where('email', $company->email)->exists()) {
            return response()->json([
                'message' => 'Email perusahaan sudah terdaftar sebagai pengguna. Hubungkan manual lewat Tambah Pengguna.',
            ], 422);
        }

        $capabilities = $approved
            ? array_values($validated['capabilities'] ?? CompanyMembership::CAPABILITIES)
            : [];

        [$user, $temporaryPassword] = DB::transaction(function () use (
            $request, $company, $approved, $oldStatus, $newStatus, $validated, $capabilities
        ) {
            $company->update([
                'verification_status' => $newStatus,
                'is_partner' => $approved,
                'is_active' => $approved,
                'verification_note' => $validated['reason'] ?? null,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => $approved ? 'company_proposal.approved' : 'company_proposal.rejected',
                'subject_type' => Company::class,
                'subject_id' => $company->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $validated['reason'] ?? null,
                'metadata' => [
                    'company_id' => $company->id,
                    'name' => $company->name,
                ],
            ]);

            $createdUser = null;
            $tempPassword = null;

            if ($approved) {
                // Buatkan akun perusahaan otomatis + hubungkan ke perusahaannya.
                $tempPassword = TemporaryPassword::generate();
                $name = $company->contact_person ?: $company->name;

                $createdUser = User::create([
                    'name' => $name,
                    'username' => $this->uniqueUsername($name, $company->email),
                    'email' => $company->email,
                    'phone' => PhoneNumber::normalize($company->phone),
                    'password' => Hash::make($tempPassword),
                    'role' => 'company',
                    'is_active' => true,
                    'must_change_password' => true,
                ]);

                CompanySupervisor::create([
                    'user_id' => $createdUser->id,
                    'company_id' => $company->id,
                    'position' => 'Admin Perusahaan',
                ]);

                CompanyMembership::create([
                    'user_id' => $createdUser->id,
                    'company_id' => $company->id,
                    'status' => CompanyMembership::STATUS_ACTIVE,
                    'capabilities' => $capabilities,
                    'granted_by' => $request->user()->id,
                    'granted_at' => now(),
                ]);
            }

            return [$createdUser, $tempPassword];
        });

        // Kirim kredensial setelah transaksi berhasil. Kegagalan notifikasi
        // tidak membatalkan persetujuan maupun akun yang sudah dibuat.
        $delivery = null;
        if ($user && $temporaryPassword) {
            $delivery = $this->notifySafely($notifier, $user, $temporaryPassword);
        }

        $company->load(['submitter:id,name,username,email,role', 'verifier:id,name']);

        return response()->json([
            'message' => $approved
                ? 'Perusahaan disetujui, akun perusahaan dibuat dan info login dikirim.'
                : 'Pengajuan perusahaan ditolak.',
            'data' => $company,
            'account' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'capabilities' => $capabilities,
            ] : null,
            'delivery' => $delivery,
        ]);
    }

    /**
     * @return array{email: array{status:string,message:string,driver?:string}, whatsapp: array{status:string,message:string}}
     */
    private function notifySafely(AccountInfoNotifier $notifier, User $user, string $temporaryPassword): array
    {
        try {
            return $notifier->sendCredentials($user, $temporaryPassword);
        } catch (Throwable $e) {
            // Jangan log password: hanya kelas exception yang dicatat.
            Log::error('Gagal mengirim kredensial akun perusahaan.', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);

            return [
                'email' => ['status' => 'failed', 'message' => 'Email gagal dikirim.'],
                'whatsapp' => ['status' => 'failed', 'message' => 'WhatsApp gagal dikirim.'],
            ];
        }
    }

    private function uniqueUsername(string $name, string $email): string
    {
        $base = Str::slug($name, '.');

        if ($base === '') {
            $base = Str::slug(Str::before($email, '@'), '.');
        }

        if ($base === '') {
            $base = 'perusahaan';
        }

        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = "{$base}.{$suffix}";
        }

        return $candidate;
    }
}

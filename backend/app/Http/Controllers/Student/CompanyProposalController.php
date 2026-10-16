<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
    public function verify(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('verifyProposal', Company::class);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'],
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

        $company->load(['submitter:id,name,username,email,role', 'verifier:id,name']);

        return response()->json([
            'message' => $approved
                ? 'Perusahaan disetujui dan didaftarkan sebagai mitra.'
                : 'Pengajuan perusahaan ditolak.',
            'data' => $company,
        ]);
    }
}

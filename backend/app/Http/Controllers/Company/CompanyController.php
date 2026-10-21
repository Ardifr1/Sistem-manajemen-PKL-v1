<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Policies\Concerns\ResolvesCompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use ResolvesCompanyAccess;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'verification_status' => ['nullable', 'string', 'max:40'],
        ]);

        $query = Company::query()->orderBy('name');

        // Query string selalu tiba sebagai string ("true"/"false"/"1"/"0").
        // Request::boolean() menafsirkan bentuk-bentuk itu dengan benar,
        // sedangkan aturan validasi 'boolean' menolak string "true"/"false"
        // sehingga daftar perusahaan untuk siswa selalu kosong (422).
        if ($request->filled('is_partner')) {
            $query->where('is_partner', $request->boolean('is_partner'));
        }

        if (! empty($validated['verification_status'])) {
            $query->where('verification_status', $validated['verification_status']);
        }

        // Daftar yang dilihat siswa hanya memuat perusahaan aktif. Peran
        // pengelola (admin dan lainnya) tetap melihat semua perusahaan.
        if ($request->user()?->role === 'student') {
            $query->where('is_active', true);
        }

        $companies = $query->get();

        return response()->json([
            'message' => 'Data perusahaan berhasil diambil.',
            'data' => $companies,
        ]);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'message' => 'Data perusahaan berhasil diambil.',
            'data' => $company,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // Perusahaan (data master partner) hanya dapat ditambahkan admin.
        if ($request->user()->role !== 'admin') {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'student_quota' => ['required', 'integer', 'min:0'],
            'required_skills' => ['nullable', 'string'],
            'required_documents' => ['nullable', 'string'],
            'is_partner' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $company = Company::create($validated);

        return response()->json([
            'message' => 'Perusahaan berhasil ditambahkan.',
            'data' => $company,
        ], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        if (! $isAdmin) {
            // Admin Perusahaan / pembimbing yang berwenang atas perusahaan
            // INI boleh memperbarui data operasional perusahaannya sendiri
            // (bidang, kontak, kuota, keahlian, dokumen). Manipulasi ID ke
            // perusahaan lain ditolak lewat cek kewenangan.
            if (
                ! in_array($user->role, ['company', 'supervisor'], true)
                || ! $this->isAuthorizedForCompany($user, $company->id)
            ) {
                abort(403);
            }

            // Status kemitraan, aktivasi, dan verifikasi menentukan aturan
            // bisnis pemilihan PKL — hanya Admin Sekolah yang boleh mengubah.
            if ($request->hasAny(['is_partner', 'is_active', 'verification_status'])) {
                abort(403, 'Status kemitraan, aktivasi, dan verifikasi hanya dapat diubah Admin Sekolah.');
            }
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'address' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'student_quota' => ['sometimes', 'integer', 'min:0'],
            'required_skills' => ['sometimes', 'nullable', 'string'],
            'required_documents' => ['sometimes', 'nullable', 'string'],
        ];

        if ($isAdmin) {
            $rules['is_partner'] = ['sometimes', 'boolean'];
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules);

        $company->update($validated);

        return response()->json([
            'message' => 'Perusahaan berhasil diperbarui.',
            'data' => $company->fresh(),
        ]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            abort(403);
        }

        $company->delete();

        return response()->json([
            'message' => 'Perusahaan berhasil dihapus.',
        ]);
    }
}
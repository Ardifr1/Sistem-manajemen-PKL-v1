<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ApplicationDocument;
use App\Models\PklApplication;
use App\Models\PklPlacement;
use App\Policies\Concerns\ResolvesCompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ApplicationDocumentController extends Controller
{
    use ResolvesCompanyAccess;
    public function index(Request $request): JsonResponse
    {
        // Policy viewAny sengaja tidak dipakai di sini: perusahaan perlu
        // meninjau dokumen lamaran masuk, sementara cakupan query di bawah
        // sudah membatasi per peran (perusahaan, guru, pembimbing industri).
        $validated = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:pkl_applications,id'],
        ]);

        $query = ApplicationDocument::query()->orderBy('id');

        if (! empty($validated['application_id'])) {
            $application = PklApplication::findOrFail($validated['application_id']);

            if (! Gate::forUser($request->user())->check('view', $application)) {
                abort(403);
            }

            $query->where('application_id', $validated['application_id']);
        } else {
            $user = $request->user();
            $query->whereHas('application', function ($q) use ($user) {
                if ($user->role === 'student') {
                    $q->where('student_id', $user->id);
                } elseif ($user->role === 'teacher') {
                    // Tabel users tidak punya kolom teacher_id; penugasan guru
                    // ada pada pkl_placements.teacher_id.
                    $q->whereIn(
                        'student_id',
                        PklPlacement::query()
                            ->where('teacher_id', $user->id)
                            ->select('student_id')
                    );
                } elseif ($user->role === 'company') {
                    $companyIds = $this->authorizedCompanyIds($user);
                    if ($companyIds === []) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('company_id', $companyIds);
                    }
                } elseif ($user->role === 'supervisor') {
                    // BR-09a: pembimbing industri hanya melihat dokumen
                    // lamaran siswa yang ditugaskan kepadanya.
                    $supervisor = $user->companySupervisor;
                    if (! $supervisor) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn(
                            'student_id',
                            PklPlacement::query()
                                ->where('supervisor_id', $supervisor->id)
                                ->select('student_id')
                        );
                    }
                }
            });
        }

        $documents = $query->get();

        return response()->json([
            'message' => 'Dokumen pendaftaran berhasil diambil.',
            'data' => $documents,
        ]);
    }

    public function show(ApplicationDocument $applicationDocument): JsonResponse
    {
        Gate::authorize('view', $applicationDocument);

        $applicationDocument->load('application');

        return response()->json([
            'message' => 'Dokumen pendaftaran berhasil diambil.',
            'data' => $applicationDocument,
        ]);
    }

    public function download(ApplicationDocument $applicationDocument)
    {
        Gate::authorize('view', $applicationDocument);

        $path = (string) $applicationDocument->file_path;

        // Tautan eksternal hanya boleh http/https. Skema lain (mis. file://,
        // javascript:) ditolak agar dokumen tidak menjadi jalur redirect/
        // eksekusi yang berbahaya saat diunduh.
        if (str_contains($path, '://')) {
            $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https'], true)) {
                abort(404, 'Berkas tidak ditemukan.');
            }

            return redirect()->away($path);
        }

        // Berkas lokal wajib berada di direktori dokumen pengajuan dan tidak
        // boleh memakai path traversal (../../) untuk keluar dari folder itu.
        $normalized = str_replace('\\', '/', $path);

        if (
            str_contains($normalized, '..')
            || ! str_starts_with($normalized, 'application-documents/')
        ) {
            abort(404, 'Berkas tidak ditemukan.');
        }

        if (! Storage::disk('public')->exists($normalized)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($normalized, $applicationDocument->document_name);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:pkl_applications,id'],
            // Jenis dokumen dibatasi pada daftar yang dikenal (sama dengan
            // aturan update) agar nilai bebas tidak masuk ke basis data.
            'document_type' => ['required', 'string', 'in:ktp,ijazah,skck,surat_rekomendasi,others'],
            'document_name' => ['required', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'file_url' => [
                'nullable',
                'url',
                'max:2048',
                // Hanya tautan http/https yang boleh disimpan; skema lain
                // (ftp, file, javascript) ditolak.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                    if (! in_array($scheme, ['http', 'https'], true)) {
                        $fail('Tautan dokumen harus menggunakan http atau https.');
                    }
                },
            ],
        ]);

        // Harus ada file ATAU url
        if (!$request->hasFile('file') && empty($validated['file_url'])) {
            return response()->json([
                'message' => 'File atau link URL harus diisi.',
            ], 422);
        }

        $application = PklApplication::findOrFail($validated['application_id']);

        if (! Gate::forUser($request->user())->check('update', $application)) {
            abort(403);
        }

        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store(
                'application-documents',
                'public'
            );
        } else {
            $filePath = $validated['file_url'];
        }

        $document = ApplicationDocument::create([
            'application_id' => $validated['application_id'],
            'document_type' => $validated['document_type'],
            'document_name' => $validated['document_name'],
            'file_path' => $filePath,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Dokumen berhasil diunggah.',
            'data' => $document,
        ], 201);
    }

    public function update(
        Request $request,
        ApplicationDocument $applicationDocument
    ): JsonResponse {
        Gate::authorize('update', $applicationDocument);

        $validated = $request->validate([
            'document_type' => ['sometimes', 'string', 'max:100', 'in:ktp,ijazah,skck,surat_rekomendasi,others'],
            'document_name' => ['sometimes', 'string', 'max:255'],
            'file' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

        if ($request->hasFile('file')) {
            if (
                $applicationDocument->file_path &&
                Storage::disk('public')->exists($applicationDocument->file_path)
            ) {
                Storage::disk('public')->delete(
                    $applicationDocument->file_path
                );
            }

            $validated['file_path'] = $request->file('file')->store(
                'application-documents',
                'public'
            );
        }

        unset($validated['file']);

        $applicationDocument->update($validated);

        return response()->json([
            'message' => 'Dokumen berhasil diperbarui.',
            'data' => $applicationDocument->fresh(),
        ]);
    }

    public function destroy(
        ApplicationDocument $applicationDocument
    ): JsonResponse {
        Gate::authorize('delete', $applicationDocument);

        if (
            $applicationDocument->file_path &&
            Storage::disk('public')->exists($applicationDocument->file_path)
        ) {
            Storage::disk('public')->delete(
                $applicationDocument->file_path
            );
        }

        $applicationDocument->delete();

        return response()->json([
            'message' => 'Dokumen berhasil dihapus.',
        ]);
    }
}
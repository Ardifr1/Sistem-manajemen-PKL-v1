<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ApplicationDocument;
use App\Models\PklApplication;
use App\Models\PklPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ApplicationDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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
                } elseif (in_array($user->role, ['company', 'supervisor'], true)) {
                    $companyId = $user->companySupervisor?->company_id;
                    if ($companyId) {
                        $q->where('company_id', $companyId);
                    } else {
                        $q->whereRaw('1 = 0');
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

        $path = $applicationDocument->file_path;

        // Kalau URL, redirect ke URL-nya
        if (preg_match('#^https?://#i', $path)) {
            return redirect()->away($path);
        }

        if (! Storage::disk('public')->exists($path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($path, $applicationDocument->document_name);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:pkl_applications,id'],
            'document_type' => ['required', 'string', 'max:100'],
            'document_name' => ['required', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'file_url' => ['nullable', 'url', 'max:2048'],
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
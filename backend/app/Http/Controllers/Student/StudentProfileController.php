<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\PklPlacement;
use App\Models\StudentProfile;
use App\Policies\Concerns\ResolvesCompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StudentProfileController extends Controller
{
    use ResolvesCompanyAccess;

    public function index(Request $request): JsonResponse
    {
        // Hanya admin/guru yang dapat menelusuri seluruh profil siswa.
        if (! in_array($request->user()->role, ['admin', 'teacher'], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
        ]);

        $query = StudentProfile::query()
            ->with('user')
            ->orderBy('id');

        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        return response()->json([
            'message' => 'Data profil siswa berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(StudentProfile $studentProfile): JsonResponse
    {
        $user = request()->user();

        // Student hanya dapat melihat profil miliknya sendiri.
        if (
            $user->role === 'student'
            && (int) $studentProfile->user_id !== (int) $user->id
        ) {
            abort(403);
        }

        // Perusahaan/pembimbing industri hanya boleh melihat profil siswa yang
        // ditempatkan di perusahaannya (data pribadi tidak boleh lintas
        // perusahaan/penempatan).
        if (in_array($user->role, ['company', 'supervisor'], true)) {
            $companyIds = $this->authorizedCompanyIds($user);

            $related = $companyIds !== [] && PklPlacement::query()
                ->where('student_id', $studentProfile->user_id)
                ->whereIn('company_id', $companyIds)
                ->exists();

            if (! $related) {
                abort(403);
            }
        }

        if (! in_array($user->role, ['admin', 'teacher', 'student', 'company', 'supervisor'], true)) {
            abort(403);
        }

        $studentProfile->load('user');

        return response()->json([
            'message' => 'Data profil siswa berhasil diambil.',
            'data' => $studentProfile,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // Hanya admin yang dapat membuat profil siswa untuk user mana pun;
        // student hanya boleh membuat profil miliknya sendiri.
        $isSelfCreation =
            $request->user()->role === 'student'
            && (int) $request->input('user_id') === $request->user()->id;

        if ($request->user()->role !== 'admin' && ! $isSelfCreation) {
            abort(403);
        }

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                'unique:student_profiles,user_id',
            ],
            'student_number' => [
                'required',
                'string',
                'max:50',
                'unique:student_profiles,student_number',
            ],
            'class' => [
                'required',
                'string',
                'max:100',
            ],
            'major' => [
                'required',
                'string',
                'max:255',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],
            'address' => [
                'nullable',
                'string',
            ],
            'pengalaman' => [
                'nullable',
                'string',
            ],
            'keahlian' => [
                'nullable',
                'string',
            ],
            'cv' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx',
                'max:5120',
            ],
            'portfolio' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,zip',
                'max:10240',
            ],
            'certificate' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        foreach (['cv' => 'cv_path', 'portfolio' => 'portfolio_path', 'certificate' => 'certificate_path'] as $input => $column) {
            if ($request->hasFile($input)) {
                $validated[$column] = $request->file($input)->store('student-documents', 'public');
            }
            unset($validated[$input]);
        }

        $existingProfile = StudentProfile::query()
            ->where('user_id', $validated['user_id'])
            ->exists();

        if ($existingProfile) {
            return response()->json([
                'message' => 'Profil siswa untuk pengguna tersebut sudah tersedia.',
            ], 422);
        }

        $profile = StudentProfile::create($validated);

        $profile->load('user');

        return response()->json([
            'message' => 'Profil siswa berhasil dibuat.',
            'data' => $profile,
        ], 201);
    }

    public function update(
        Request $request,
        StudentProfile $studentProfile
    ): JsonResponse {
        // Student hanya dapat memperbarui profil miliknya sendiri.
        if (
            $request->user()->role === 'student'
            && $studentProfile->user_id !== $request->user()->id
        ) {
            abort(403);
        }

        if (! in_array($request->user()->role, ['admin', 'student'], true)) {
            abort(403);
        }
        $validated = $request->validate([
            'student_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('student_profiles', 'student_number')
                    ->ignore($studentProfile->id),
            ],
            'class' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'major' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],
            'address' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'pengalaman' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'keahlian' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'cv' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:pdf,doc,docx',
                'max:5120',
            ],
            'portfolio' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:pdf,doc,docx,zip',
                'max:10240',
            ],
            'certificate' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        foreach (['cv' => 'cv_path', 'portfolio' => 'portfolio_path', 'certificate' => 'certificate_path'] as $input => $column) {
            if ($request->hasFile($input)) {
                $validated[$column] = $request->file($input)->store('student-documents', 'public');
            }
            unset($validated[$input]);
        }

        $studentProfile->update($validated);

        $studentProfile->load('user');

        return response()->json([
            'message' => 'Profil siswa berhasil diperbarui.',
            'data' => $studentProfile,
        ]);
    }

    public function destroy(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            abort(403);
        }

        $studentProfile->delete();

        return response()->json([
            'message' => 'Profil siswa berhasil dihapus.',
        ]);
    }
}
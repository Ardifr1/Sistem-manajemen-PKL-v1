<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Interview;
use App\Models\PklApplication;
use App\Policies\Concerns\ResolvesCompanyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterviewController extends Controller
{
    use ResolvesCompanyAccess;

    /**
     * Interview dijadwalkan oleh perusahaan/pembimbing.
     * Siswa hanya boleh melihat interview atas lamarannya sendiri.
     */
    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'company', 'supervisor'], true);
    }

    private function scopeForUser(Request $request, $query)
    {
        $user = $request->user();

        // Company/supervisor hanya melihat interview atas lamaran ke perusahaannya.
        if (in_array($user->role, ['company', 'supervisor'], true)) {
            $companyIds = $this->authorizedCompanyIds($user);
            if ($companyIds === []) {
                // Belum ditautkan ke perusahaan mana pun: kosong, bukan semua.
                return $query->whereRaw('1 = 0');
            }
            $query->whereHas('application', function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds);
            });
        }

        // Siswa hanya melihat interview atas lamarannya sendiri.
        if ($user->role === 'student') {
            $query->whereHas('application', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:pkl_applications,id'],
            'status' => ['nullable', 'string', 'in:scheduled,done,cancelled'],
        ]);

        $query = $this->scopeForUser($request, Interview::with([
            'application.company',
            'application.student',
        ])->orderByDesc('scheduled_at'));

        if (!empty($validated['application_id'])) {
            $query->where('application_id', $validated['application_id']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'message' => 'Data interview berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function show(Request $request, Interview $interview): JsonResponse
    {
        $this->scopeForUser($request, Interview::where('id', $interview->id))->firstOrFail();

        $interview->load(['application.company', 'application.student']);

        return response()->json([
            'message' => 'Data interview berhasil diambil.',
            'data' => $interview,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            abort(403);
        }

        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:pkl_applications,id'],
            'scheduled_at' => ['nullable', 'date'],
            'place' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'in:offline,online'],
            'status' => ['nullable', 'string', 'in:scheduled,done,cancelled'],
            'result' => ['nullable', 'string', 'in:passed,failed'],
            'note' => ['nullable', 'string'],
        ]);

        $application = PklApplication::findOrFail($validated['application_id']);

        // Perusahaan/pembimbing industri hanya boleh menjadwalkan interview
        // untuk lamaran yang ditujukan ke perusahaannya. Tanpa pemeriksaan ini
        // siapa pun dengan role company/supervisor dapat membuat interview
        // sekaligus mengubah status lamaran milik perusahaan lain.
        if (
            in_array($request->user()->role, ['company', 'supervisor'], true)
            && ! $this->isAuthorizedForCompany($request->user(), (int) $application->company_id)
        ) {
            abort(403, 'Lamaran ini bukan milik perusahaan Anda.');
        }

        $interview = Interview::create($validated);
        $interview->load(['application.company', 'application.student']);

        // Otomatis set status lamaran jadi "interview" saat dijadwalkan.
        if ($application->status === 'pending') {
            $application->update(['status' => 'interview']);
        }

        return response()->json([
            'message' => 'Jadwal interview berhasil disimpan.',
            'data' => $interview,
        ], 201);
    }

    public function update(Request $request, Interview $interview): JsonResponse
    {
        if (!$this->canManage($request)) {
            abort(403);
        }

        $this->scopeForUser($request, Interview::where('id', $interview->id))->firstOrFail();

        $validated = $request->validate([
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mode' => ['sometimes', 'nullable', 'string', 'in:offline,online'],
            'status' => ['sometimes', 'nullable', 'string', 'in:scheduled,done,cancelled'],
            'result' => ['sometimes', 'nullable', 'string', 'in:passed,failed'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]);

        $interview->update($validated);

        $interview->load(['application.company', 'application.student']);

        return response()->json([
            'message' => 'Jadwal interview berhasil diperbarui.',
            'data' => $interview,
        ]);
    }

    public function destroy(Request $request, Interview $interview): JsonResponse
    {
        if (!$this->canManage($request)) {
            abort(403);
        }

        $this->scopeForUser($request, Interview::where('id', $interview->id))->firstOrFail();

        $interview->delete();

        return response()->json([
            'message' => 'Jadwal interview berhasil dihapus.',
        ]);
    }
}

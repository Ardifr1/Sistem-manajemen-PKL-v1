<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PklPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PklPeriodController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', PklPeriod::class);

        // Otomatis nonaktifkan periode yang sudah lewat tanggal selesai.
        // Baris dikunci agar pemanggilan berulang/bersamaan tidak menulis
        // audit log ganda.
        DB::transaction(function () {
            $expiredPeriods = PklPeriod::query()
                ->where('is_active', true)
                ->where('end_date', '<', now()->toDateString())
                ->lockForUpdate()
                ->get();

            $closedAt = now();

            foreach ($expiredPeriods as $period) {
                $period->update([
                    'is_active' => false,
                    'closed_at' => $closedAt,
                ]);

                AuditLog::create([
                    'user_id' => null,
                    'action' => 'pkl_period.auto_closed',
                    'subject_type' => PklPeriod::class,
                    'subject_id' => $period->id,
                    'old_status' => 'active',
                    'new_status' => 'inactive',
                    'reason' => 'Periode PKL otomatis ditutup karena tanggal selesai telah lewat.',
                    'metadata' => [
                        'period_name' => $period->name,
                        'start_date' => $period->start_date?->toDateString(),
                        'end_date' => $period->end_date?->toDateString(),
                        'closed_at' => $closedAt->toIso8601String(),
                        'trigger' => 'auto_expiry',
                    ],
                ]);
            }
        });

        $periods = PklPeriod::query()
            ->orderByDesc('start_date')
            ->get();

        return response()->json([
            'message' => 'Data periode PKL berhasil diambil.',
            'data' => $periods,
        ]);
    }

    public function show(PklPeriod $pklPeriod): JsonResponse
    {
        Gate::authorize('view', $pklPeriod);

        return response()->json([
            'message' => 'Data periode PKL berhasil diambil.',
            'data' => $pklPeriod,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', PklPeriod::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);

        $period = PklPeriod::create($validated);

        return response()->json([
            'message' => 'Periode PKL berhasil ditambahkan.',
            'data' => $period,
        ], 201);
    }

    public function update(
        Request $request,
        PklPeriod $pklPeriod
    ): JsonResponse {
        Gate::authorize('update', $pklPeriod);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
            'closed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $startDate = $validated['start_date'] ?? $pklPeriod->start_date;
        $endDate = $validated['end_date'] ?? $pklPeriod->end_date;

        if (strtotime((string) $endDate) < strtotime((string) $startDate)) {
            return response()->json([
                'message' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
            ], 422);
        }

        $oldIsActive = (bool) $pklPeriod->is_active;
        $wasClosed = $pklPeriod->closed_at !== null;
        $newIsActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : $oldIsActive;
        $requestedClosedAt = ! empty($validated['closed_at'])
            ? Carbon::parse($validated['closed_at'])
            : null;

        // Penutupan manual terjadi saat periode aktif dinonaktifkan, atau
        // saat tombol tutup mengirim closed_at untuk periode draft. Periode
        // yang sudah punya closed_at tidak ditutup ulang agar waktu penutupan
        // pertama dan audit log tidak berubah.
        $isManualClose = ! $newIsActive
            && ($oldIsActive || ($requestedClosedAt !== null && ! $wasClosed));

        $closedAt = null;

        if ($isManualClose) {
            $closedAt = $requestedClosedAt ?? now();
            $validated['closed_at'] = $closedAt;
        } else {
            unset($validated['closed_at']);
        }

        DB::transaction(function () use ($request, $pklPeriod, $validated, $closedAt, $oldIsActive) {
            $pklPeriod->update($validated);

            if ($closedAt === null) {
                return;
            }

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'pkl_period.closed',
                'subject_type' => PklPeriod::class,
                'subject_id' => $pklPeriod->id,
                'old_status' => $oldIsActive ? 'active' : 'draft',
                'new_status' => 'inactive',
                'reason' => 'Periode PKL ditutup oleh admin.',
                'metadata' => [
                    'period_name' => $pklPeriod->name,
                    'start_date' => $pklPeriod->start_date?->toDateString(),
                    'end_date' => $pklPeriod->end_date?->toDateString(),
                    'closed_at' => $closedAt->toIso8601String(),
                    'trigger' => 'manual',
                ],
            ]);
        });

        return response()->json([
            'message' => 'Periode PKL berhasil diperbarui.',
            'data' => $pklPeriod->fresh(),
        ]);
    }

    public function destroy(PklPeriod $pklPeriod): JsonResponse
    {
        Gate::authorize('delete', $pklPeriod);

        $pklPeriod->delete();

        return response()->json([
            'message' => 'Periode PKL berhasil dihapus.',
        ]);
    }
}
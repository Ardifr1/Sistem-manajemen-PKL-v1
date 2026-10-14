<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'placement_id' => [
                'nullable',
                'integer',
                'exists:pkl_placements,id',
            ],
        ]);

        $query = AuditLog::query()
            ->with(['user', 'placement'])
            ->orderByDesc('id');

        if (! empty($validated['placement_id'])) {
            $query->where('placement_id', $validated['placement_id']);
        }

        return response()->json([
            'message' => 'Data audit log berhasil diambil.',
            'data' => $query->limit(200)->get(),
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        Gate::authorize('view', $auditLog);

        $auditLog->load(['user', 'placement', 'finalAssessment']);

        return response()->json([
            'message' => 'Data audit log berhasil diambil.',
            'data' => $auditLog,
        ]);
    }
}

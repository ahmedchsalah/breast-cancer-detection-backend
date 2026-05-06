<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * List audit logs with filtering by user, org, and action type.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'         => 'nullable|integer|exists:users,id',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'action'          => 'nullable|string|max:100',
            'auditable_type'  => 'nullable|string|max:100',
            'from'            => 'nullable|date',
            'to'              => 'nullable|date|after_or_equal:from',
        ]);

        $query = AuditLog::with('user:id,name,email', 'organization:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }
        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->auditable_type);
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }

        return response()->json($query->paginate(25));
    }

    /**
     * Show details of a single audit log entry.
     */
    public function show(AuditLog $auditLog): JsonResponse
    {
        return response()->json($auditLog->load('user:id,name,email', 'organization:id,name'));
    }
}

<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/audit-logs
 * Admin only — expose tous les logs (globaux + par projet) pour audit.
 */
class AdminAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'global'); // global | projects | all
        $action = $request->query('action');
        $actor = $request->query('actor');
        $perPage = min((int) $request->query('per_page', 100), 500);

        $query = ActivityLog::with(['actor:id,email,name,avatar_path', 'project:id,name,slug,color'])
            ->orderByDesc('created_at');

        if ($scope === 'global') {
            $query->whereNull('project_id');
        } elseif ($scope === 'projects') {
            $query->whereNotNull('project_id');
        }

        if ($action) {
            $query->where('action', 'like', $action.'%');
        }
        if ($actor) {
            $query->where('actor_id', $actor);
        }

        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }
}

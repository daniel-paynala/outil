<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Models\ActivityLog;
use App\Modules\Core\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $userId = $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');

        if (! $project->hasMember($userId)) {
            abort(403, 'Not a member of this project');
        }

        $query = ActivityLog::where('project_id', $project->id)
            ->with('actor:id,email,name')
            ->orderByDesc('created_at')
            ->limit(500);

        if ($request->has('type')) {
            $query->where('subject_type', $request->string('type'));
        }
        if ($request->has('actor')) {
            $query->where('actor_id', $request->string('actor'));
        }

        return response()->json($query->get());
    }
}

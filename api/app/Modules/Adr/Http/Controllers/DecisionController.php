<?php

namespace App\Modules\Adr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Adr\Models\Decision;
use App\Modules\Core\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DecisionController extends Controller
{
    private const STATUSES = ['proposed', 'accepted', 'deprecated', 'superseded'];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $decisions = Decision::where('project_id', $project->id)
            ->with('creator:id,email,name', 'updater:id,email,name')
            ->orderByDesc('number')
            ->get();

        return response()->json($decisions);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'context' => ['nullable', 'string'],
            'decision' => ['nullable', 'string'],
            'consequences' => ['nullable', 'string'],
            'alternatives' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
            'decided_at' => ['nullable', 'date'],
        ]);

        $userId = $this->userId($request);

        $nextNumber = ((int) Decision::where('project_id', $project->id)
            ->max('number')) + 1;

        $decision = Decision::create([
            ...$data,
            'project_id' => $project->id,
            'number' => $nextNumber,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $decision->load('creator:id,email,name');

        $this->activity->log(
            $project->id,
            $userId,
            'decision.created',
            $decision,
            "ADR-{$decision->number} · {$decision->title}",
        );

        return response()->json($decision, 201);
    }

    public function show(Request $request, Decision $decision): JsonResponse
    {
        $this->ensureMember($request, $decision->project);

        $decision->load('creator:id,email,name', 'updater:id,email,name');

        return response()->json($decision);
    }

    public function update(Request $request, Decision $decision): JsonResponse
    {
        $this->ensureMember($request, $decision->project);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'context' => ['nullable', 'string'],
            'decision' => ['nullable', 'string'],
            'consequences' => ['nullable', 'string'],
            'alternatives' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
            'decided_at' => ['nullable', 'date'],
        ]);

        $userId = $this->userId($request);
        $prevStatus = $decision->status;
        $decision->update([
            ...$data,
            'updated_by' => $userId,
        ]);

        $statusChanged = isset($data['status']) && $data['status'] !== $prevStatus;

        $this->activity->log(
            $decision->project_id,
            $userId,
            $statusChanged ? 'decision.status_changed' : 'decision.updated',
            $decision,
            "ADR-{$decision->number} · {$decision->title}",
            $statusChanged ? ['from' => $prevStatus, 'to' => $decision->status] : [],
        );

        $decision->load('updater:id,email,name');

        return response()->json($decision);
    }

    public function destroy(Request $request, Decision $decision): JsonResponse
    {
        $this->ensureMember($request, $decision->project);
        $label = "ADR-{$decision->number} · {$decision->title}";
        $projectId = $decision->project_id;
        $decision->delete();

        $this->activity->log($projectId, $this->userId($request), 'decision.deleted', null, $label);

        return response()->json(null, 204);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function ensureMember(Request $request, Project $project): void
    {
        if (! $project->hasMember($this->userId($request))) {
            abort(403, 'Not a member of this project');
        }
    }
}

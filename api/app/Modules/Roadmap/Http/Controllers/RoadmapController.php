<?php

namespace App\Modules\Roadmap\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Roadmap\Models\Release;
use App\Modules\Roadmap\Models\RoadmapItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoadmapController extends Controller
{
    private const HORIZONS = ['now', 'next', 'later', 'done'];

    private const EFFORTS = ['S', 'M', 'L', 'XL'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notify,
    ) {}

    /**
     * Returns everything needed to render any of the 6 views in one shot.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $items = RoadmapItem::where('project_id', $project->id)
            ->with(['owners:id,email,name,avatar_path', 'release:id,name,color,shipped_at'])
            ->withCount('cards')
            ->orderBy('horizon')
            ->orderBy('position')
            ->get();

        $releases = Release::where('project_id', $project->id)
            ->withCount('items')
            ->orderByDesc('shipped_at')
            ->orderBy('name')
            ->get();

        return response()->json([
            'items' => $items,
            'releases' => $releases,
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'horizon' => ['nullable', 'string', 'in:'.implode(',', self::HORIZONS)],
            'effort' => ['nullable', 'string', 'in:'.implode(',', self::EFFORTS)],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'release_id' => ['nullable', 'uuid', 'exists:releases,id'],
            'owner_ids' => ['nullable', 'array'],
            'owner_ids.*' => ['uuid', 'exists:users,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);

        $ownerIds = $data['owner_ids'] ?? [];
        unset($data['owner_ids']);

        $userId = $this->userId($request);
        $horizon = $data['horizon'] ?? 'later';

        $position = (int) RoadmapItem::where('project_id', $project->id)
            ->where('horizon', $horizon)
            ->max('position') + 1;

        $item = RoadmapItem::create([
            ...$data,
            'horizon' => $horizon,
            'position' => $position,
            'project_id' => $project->id,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        if (! empty($ownerIds)) {
            $item->owners()->sync($ownerIds);
        }

        $item->load(['owners:id,email,name,avatar_path', 'release:id,name,color']);
        $item->loadCount('cards');

        $this->activity->log($project->id, $userId, 'roadmap.created', $item, $item->title);

        return response()->json($item, 201);
    }

    public function show(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->ensureMember($request, $item->project);

        $item->load([
            'owners:id,email,name,avatar_path',
            'creator:id,email,name,avatar_path',
            'release:id,name,color,shipped_at',
            'cards:id,title,column_id,project_id',
        ]);

        return response()->json($item);
    }

    public function update(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->ensureMember($request, $item->project);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'horizon' => ['sometimes', 'string', 'in:'.implode(',', self::HORIZONS)],
            'effort' => ['nullable', 'string', 'in:'.implode(',', self::EFFORTS)],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'release_id' => ['nullable', 'uuid', 'exists:releases,id'],
            'owner_ids' => ['sometimes', 'nullable', 'array'],
            'owner_ids.*' => ['uuid', 'exists:users,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);

        $ownerIds = $data['owner_ids'] ?? null;
        unset($data['owner_ids']);

        $userId = $this->userId($request);
        $item->update([...$data, 'updated_by' => $userId]);

        // Sync owners only if explicitly provided in the payload
        if ($request->has('owner_ids')) {
            $item->owners()->sync($ownerIds ?? []);
        }

        $item->load(['owners:id,email,name,avatar_path', 'release:id,name,color']);
        $item->loadCount('cards');

        $this->activity->log($item->project_id, $userId, 'roadmap.updated', $item, $item->title);

        return response()->json($item);
    }

    public function destroy(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->ensureMember($request, $item->project);

        $title = $item->title;
        $projectId = $item->project_id;
        $item->delete();

        $this->activity->log($projectId, $this->userId($request), 'roadmap.deleted', null, $title);

        return response()->json(null, 204);
    }

    /**
     * Atomically move an item between horizons (or reorder within a horizon).
     */
    public function move(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'item_id' => ['required', 'uuid', 'exists:roadmap_items,id'],
            'to_horizon' => ['required', 'string', 'in:'.implode(',', self::HORIZONS)],
            'to_position' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $project) {
            $item = RoadmapItem::where('project_id', $project->id)
                ->where('id', $data['item_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $fromHorizon = $item->horizon;
            $toHorizon = $data['to_horizon'];
            $toPos = $data['to_position'];

            if ($fromHorizon === $toHorizon) {
                $items = RoadmapItem::where('project_id', $project->id)
                    ->where('horizon', $fromHorizon)
                    ->where('id', '!=', $item->id)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get()
                    ->values();
                $items->splice($toPos, 0, [$item]);
                foreach ($items as $i => $it) {
                    $it->update(['position' => $i]);
                }
            } else {
                $item->update(['horizon' => $toHorizon, 'position' => $toPos]);

                $sourceItems = RoadmapItem::where('project_id', $project->id)
                    ->where('horizon', $fromHorizon)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get();
                foreach ($sourceItems as $i => $it) {
                    $it->update(['position' => $i]);
                }

                $targetItems = RoadmapItem::where('project_id', $project->id)
                    ->where('horizon', $toHorizon)
                    ->where('id', '!=', $item->id)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get()
                    ->values();
                $targetItems->splice($toPos, 0, [$item]);
                foreach ($targetItems as $i => $it) {
                    $it->update(['position' => $i]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    public function attachCard(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->ensureMember($request, $item->project);

        $data = $request->validate([
            'card_id' => ['required', 'uuid', 'exists:cards,id'],
        ]);

        $item->cards()->syncWithoutDetaching([$data['card_id']]);

        return response()->json($item->cards()->get(['id', 'title', 'column_id']));
    }

    public function detachCard(Request $request, RoadmapItem $item, string $cardId): JsonResponse
    {
        $this->ensureMember($request, $item->project);
        $item->cards()->detach($cardId);

        return response()->json(null, 204);
    }

    // ── Releases ─────────────────────────────────────────────────────────────

    public function storeRelease(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'shipped_at' => ['nullable', 'date'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $userId = $this->userId($request);
        $release = Release::create([
            ...$data,
            'project_id' => $project->id,
            'created_by' => $userId,
        ]);

        $release->loadCount('items');

        $this->notify->forProjectMembers(
            projectId: $project->id,
            type: 'release.created',
            title: 'Nouvelle release',
            body: $release->name,
            link: "/projects/{$project->id}/roadmap",
            actorId: $userId,
            exceptUserId: $userId,
        );

        return response()->json($release, 201);
    }

    public function updateRelease(Request $request, Release $release): JsonResponse
    {
        $this->ensureMember($request, $release->project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'shipped_at' => ['nullable', 'date'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $release->update($data);
        $release->loadCount('items');

        return response()->json($release);
    }

    public function destroyRelease(Request $request, Release $release): JsonResponse
    {
        $this->ensureMember($request, $release->project);
        $release->delete();

        return response()->json(null, 204);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

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

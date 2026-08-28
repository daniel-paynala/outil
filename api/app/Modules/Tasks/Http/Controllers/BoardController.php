<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\TaskAssigned;
use App\Models\User;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Column;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BoardController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notify,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        if ($project->columns()->count() === 0) {
            $this->seedDefaultColumns($project->id);
        }

        $columns = $project->columns()
            ->with([
                'cards' => fn ($q) => $q->orderBy('position'),
                'cards.labels:id,name,color',
                'cards.assignee:id,email,name,avatar_path',
            ])
            ->withCount(['cards'])
            ->orderBy('position')
            ->get();

        // add counts per card
        foreach ($columns as $col) {
            $col->cards->loadCount(['dependencies', 'children', 'comments']);
        }

        return response()->json($columns);
    }

    /**
     * GET /api/projects/{project}/cards-timeline
     * Vue plate des cartes pour le rendu d'une timeline groupée par responsable.
     */
    public function timeline(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $cards = Card::where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->with(['assignee:id,email,name,avatar_path', 'column:id,name,is_done'])
            ->orderBy('created_at')
            ->get([
                'id', 'title', 'priority', 'due_date', 'completed_at',
                'created_at', 'assigned_to', 'column_id', 'project_id',
            ]);

        return response()->json($cards);
    }

    public function showCard(Request $request, Card $card): JsonResponse
    {
        $this->ensureMemberOfCard($request, $card);

        $card->load([
            'labels:id,name,color',
            'assignee:id,email,name,avatar_path',
            'creator:id,email,name,avatar_path',
            'children:id,title,column_id,position,priority,due_date,assigned_to',
            'children.assignee:id,email,name,avatar_path',
            'dependencies:id,title,column_id',
            'dependents:id,title,column_id',
            'parent:id,title',
        ]);
        $card->loadCount('comments');

        return response()->json($card);
    }

    public function storeColumn(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $position = (int) ($project->columns()->max('position') ?? -1) + 1;

        $column = Column::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'position' => $position,
            'color' => $data['color'] ?? null,
        ]);

        return response()->json($column, 201);
    }

    public function updateColumn(Request $request, Column $column): JsonResponse
    {
        $this->ensureMemberOfColumn($request, $column);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_done' => ['sometimes', 'boolean'],
        ]);

        $previousIsDone = (bool) $column->is_done;

        $column->update($data);

        if (array_key_exists('is_done', $data) && $previousIsDone !== (bool) $data['is_done']) {
            if ($data['is_done']) {
                Card::where('column_id', $column->id)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => now()]);
            } else {
                Card::where('column_id', $column->id)
                    ->whereNotNull('completed_at')
                    ->update(['completed_at' => null]);
            }
        }

        return response()->json($column);
    }

    public function destroyColumn(Request $request, Column $column): JsonResponse
    {
        $this->ensureMemberOfColumn($request, $column);
        $column->delete();

        return response()->json(null, 204);
    }

    public function storeCard(Request $request, Column $column): JsonResponse
    {
        $this->ensureMemberOfColumn($request, $column);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_card_id' => ['nullable', 'uuid', 'exists:cards,id'],
        ]);

        $userId = $this->userId($request);

        $position = (int) ($column->cards()->max('position') ?? -1) + 1;

        $card = Card::create([
            'project_id' => $column->project_id,
            'column_id' => $column->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'parent_card_id' => $data['parent_card_id'] ?? null,
            'position' => $position,
            'created_by' => $userId,
        ]);

        $this->activity->log($card->project_id, $userId, 'card.created', $card, $card->title, [
            'column' => $column->name,
        ]);

        // Notifs : tous les membres du projet (sauf l'auteur)
        $link = "/projects/{$card->project_id}/tasks?card={$card->id}";
        $this->notify->forProjectMembers(
            projectId: $card->project_id,
            type: 'task.created',
            title: 'Nouvelle tâche',
            body: $card->title,
            link: $link,
            actorId: $userId,
            exceptUserId: $userId,
        );

        return response()->json($card, 201);
    }

    public function updateCard(Request $request, Card $card): JsonResponse
    {
        $this->ensureMemberOfCard($request, $card);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'due_date' => ['nullable', 'date'],
            'estimate' => ['nullable', 'string', 'max:40'],
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
            'parent_card_id' => ['nullable', 'uuid', 'exists:cards,id'],
        ]);

        $previousAssignee = $card->assigned_to;
        $changed = array_keys(array_diff_assoc($data, $card->only(array_keys($data))));
        $card->update($data);
        $userId = $this->userId($request);

        if (! empty($changed)) {
            $this->activity->log(
                $card->project_id,
                $userId,
                'card.updated',
                $card,
                $card->title,
                ['fields' => $changed],
            );
        }

        // Notifs sur changement d'assignation
        if (in_array('assigned_to', $changed, true)) {
            $link = "/projects/{$card->project_id}/tasks?card={$card->id}";
            // Nouveau assigné
            if ($card->assigned_to) {
                $this->sendTaskAssignedEmail($card, $request);
                $this->notify->forUser(
                    userId: $card->assigned_to,
                    type: 'task.assigned',
                    title: 'Une tâche t\'a été attribuée',
                    body: $card->title,
                    link: $link,
                    projectId: $card->project_id,
                    actorId: $userId,
                );
            }
            // Ancien assigné (si différent)
            if ($previousAssignee && $previousAssignee !== $card->assigned_to) {
                $this->notify->forUser(
                    userId: $previousAssignee,
                    type: 'task.unassigned',
                    title: 'Tâche retirée de tes attributions',
                    body: $card->title,
                    link: $link,
                    projectId: $card->project_id,
                    actorId: $userId,
                );
            }
        }

        $card->load(['assignee:id,email,name,avatar_path', 'labels:id,name,color']);

        return response()->json($card);
    }

    public function addDependency(Request $request, Card $card): JsonResponse
    {
        $this->ensureMemberOfCard($request, $card);

        $data = $request->validate([
            'depends_on_card_id' => ['required', 'uuid', 'different:card_id', 'exists:cards,id'],
        ]);

        $other = Card::findOrFail($data['depends_on_card_id']);
        abort_if($other->project_id !== $card->project_id, 422, 'Cards from different projects');
        abort_if($other->id === $card->id, 422, 'A card cannot depend on itself');

        $card->dependencies()->syncWithoutDetaching([$other->id]);

        return response()->json($card->dependencies()->get(['id', 'title', 'column_id']));
    }

    public function removeDependency(Request $request, Card $card, Card $dep): JsonResponse
    {
        $this->ensureMemberOfCard($request, $card);
        $card->dependencies()->detach($dep->id);

        return response()->json(null, 204);
    }

    public function destroyCard(Request $request, Card $card): JsonResponse
    {
        $this->ensureMemberOfCard($request, $card);
        $title = $card->title;
        $projectId = $card->project_id;
        $card->delete();

        $this->activity->log($projectId, $this->userId($request), 'card.deleted', null, $title);

        return response()->json(null, 204);
    }

    /**
     * Atomically move a card to a (possibly different) column at a given position.
     * Body: { card_id, to_column_id, to_position }
     */
    public function moveCard(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'card_id' => ['required', 'uuid', 'exists:cards,id'],
            'to_column_id' => ['required', 'uuid', 'exists:columns,id'],
            'to_position' => ['required', 'integer', 'min:0'],
        ]);

        $logMeta = null;

        DB::transaction(function () use ($data, $project, &$logMeta) {
            $card = Card::where('project_id', $project->id)
                ->where('id', $data['card_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $toColumn = Column::where('project_id', $project->id)
                ->where('id', $data['to_column_id'])
                ->firstOrFail();

            $fromColumnId = $card->column_id;
            $toColumnId = $toColumn->id;
            $toPos = $data['to_position'];

            if ($fromColumnId !== $toColumnId) {
                $fromColumn = Column::find($fromColumnId);
                $logMeta = [
                    'card' => $card,
                    'from' => $fromColumn?->name,
                    'to' => $toColumn->name,
                ];
            }

            if ($fromColumnId === $toColumnId) {
                $cards = Card::where('column_id', $fromColumnId)
                    ->where('id', '!=', $card->id)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get();

                $reordered = $cards->values();
                $reordered->splice($toPos, 0, [$card]);

                foreach ($reordered as $i => $c) {
                    $c->update(['position' => $i]);
                }
            } else {
                // Sync completed_at en fonction du is_done de la colonne destination.
                $patch = [
                    'column_id' => $toColumnId,
                    'position' => $toPos,
                ];
                if ($toColumn->is_done && ! $card->completed_at) {
                    $patch['completed_at'] = now();
                } elseif (! $toColumn->is_done && $card->completed_at) {
                    $patch['completed_at'] = null;
                }
                $card->update($patch);

                $sourceCards = Card::where('column_id', $fromColumnId)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get();
                foreach ($sourceCards as $i => $c) {
                    $c->update(['position' => $i]);
                }

                $targetCards = Card::where('column_id', $toColumnId)
                    ->where('id', '!=', $card->id)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get()
                    ->values();
                $targetCards->splice($toPos, 0, [$card]);
                foreach ($targetCards as $i => $c) {
                    $c->update(['position' => $i]);
                }
            }
        });

        if ($logMeta) {
            $userId = $this->userId($request);
            // Notif si la carte vient d'être complétée (passage vers colonne is_done)
            $movedCard = $logMeta['card'];
            $movedCard->refresh();
            if ($movedCard->completed_at && $movedCard->wasChanged('completed_at') === false) {
                // wasChanged ne marche pas après refresh ; on compare avec le snapshot
                // On utilise une heuristique : si la colonne est is_done, on notifie
                $toCol = Column::find($movedCard->column_id);
                if ($toCol && $toCol->is_done) {
                    $this->notify->forProjectMembers(
                        projectId: $project->id,
                        type: 'task.completed',
                        title: 'Tâche terminée',
                        body: $movedCard->title,
                        link: "/projects/{$project->id}/tasks?card={$movedCard->id}",
                        actorId: $userId,
                        exceptUserId: $userId,
                    );
                }
            }

            $this->activity->log(
                $project->id,
                $userId,
                'card.moved',
                $logMeta['card'],
                $logMeta['card']->title,
                ['from' => $logMeta['from'], 'to' => $logMeta['to']],
            );
        }

        return response()->json(['ok' => true]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

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

    private function ensureMemberOfColumn(Request $request, Column $column): void
    {
        $this->ensureMember($request, $column->project);
    }

    private function ensureMemberOfCard(Request $request, Card $card): void
    {
        $this->ensureMember($request, $card->project);
    }

    private function seedDefaultColumns(string $projectId): void
    {
        $defaults = [
            ['name' => 'À faire', 'position' => 0, 'is_done' => false],
            ['name' => 'En cours', 'position' => 1, 'is_done' => false],
            ['name' => 'Terminé', 'position' => 2, 'is_done' => true],
        ];
        foreach ($defaults as $c) {
            Column::create([
                'project_id' => $projectId,
                'name' => $c['name'],
                'position' => $c['position'],
                'is_done' => $c['is_done'],
            ]);
        }
    }

    /**
     * Envoie un email d'attribution si l'assigné a activé la préférence.
     * Échec d'envoi non bloquant — on log et on continue.
     */
    private function sendTaskAssignedEmail(Card $card, Request $request): void
    {
        $assignee = User::find($card->assigned_to);
        if (! $assignee || ! $assignee->email || ! $assignee->notify_task_assignment_email) {
            return;
        }

        // Pas d'email à soi-même
        $actorId = $this->userId($request);
        if ($assignee->id === $actorId) {
            return;
        }

        $project = $card->project;
        $column = $card->column;
        $assigner = $request->attributes->get('user');

        $taskUrl = rtrim((string) config('app.url'), '/')
            ."/projects/{$card->project_id}/tasks?card={$card->id}";

        try {
            Mail::to($assignee->email)->send(new TaskAssigned(
                assigneeName: $assignee->name ?? $assignee->email,
                task: [
                    'title' => $card->title,
                    'description' => $card->description,
                    'priority' => $card->priority,
                    'due_date' => $card->due_date?->toDateString(),
                    'project_name' => $project?->name ?? 'Projet',
                    'project_color' => $project?->color ?? '#dc2626',
                    'column_name' => $column?->name,
                ],
                taskUrl: $taskUrl,
                assignerName: $assigner?->name ?? $assigner?->email,
            ));
        } catch (\Throwable $e) {
            Log::warning('TaskAssigned email failed', [
                'card_id' => $card->id,
                'assignee' => $assignee->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

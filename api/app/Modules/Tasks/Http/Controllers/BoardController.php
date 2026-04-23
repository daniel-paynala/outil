<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Column;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        if ($project->columns()->count() === 0) {
            $this->seedDefaultColumns($project->id);
        }

        $columns = $project->columns()
            ->with(['cards' => fn ($q) => $q->orderBy('position')])
            ->orderBy('position')
            ->get();

        return response()->json($columns);
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
        ]);

        $column->update($data);

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
        ]);

        $userId = $this->userId($request);

        $position = (int) ($column->cards()->max('position') ?? -1) + 1;

        $card = Card::create([
            'project_id' => $column->project_id,
            'column_id' => $column->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'position' => $position,
            'created_by' => $userId,
        ]);

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
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
        ]);

        $card->update($data);

        return response()->json($card);
    }

    public function destroyCard(Request $request, Card $card): JsonResponse
    {
        $this->ensureMemberOfCard($request, $card);
        $card->delete();

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

        DB::transaction(function () use ($data, $project) {
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
                $card->update([
                    'column_id' => $toColumnId,
                    'position' => $toPos,
                ]);

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
            ['name' => 'À faire', 'position' => 0],
            ['name' => 'En cours', 'position' => 1],
            ['name' => 'Terminé', 'position' => 2],
        ];
        foreach ($defaults as $c) {
            Column::create([
                'project_id' => $projectId,
                'name' => $c['name'],
                'position' => $c['position'],
            ]);
        }
    }
}

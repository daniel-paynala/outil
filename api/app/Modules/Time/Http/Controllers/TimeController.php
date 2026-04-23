<?php

namespace App\Modules\Time\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Time\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $entries = TimeEntry::where('project_id', $project->id)
            ->with([
                'user:id,email,name',
                'card:id,title,column_id',
            ])
            ->orderByDesc('started_at')
            ->limit(500)
            ->get();

        return response()->json($entries);
    }

    public function running(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $entry = TimeEntry::whereNull('ended_at')
            ->where('user_id', $userId)
            ->with('project:id,name,slug,color', 'card:id,title,column_id')
            ->first();

        return response()->json($entry);
    }

    public function start(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'card_id' => ['nullable', 'uuid', 'exists:cards,id'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = $this->userId($request);

        if (! empty($data['card_id'])) {
            $card = Card::findOrFail($data['card_id']);
            abort_if($card->project_id !== $project->id, 422, 'Card from different project');
        }

        // Stop any existing running timer for this user
        TimeEntry::whereNull('ended_at')
            ->where('user_id', $userId)
            ->get()
            ->each(fn ($e) => $this->stopEntry($e));

        $entry = TimeEntry::create([
            'project_id' => $project->id,
            'card_id' => $data['card_id'] ?? null,
            'user_id' => $userId,
            'description' => $data['description'] ?? null,
            'started_at' => now(),
        ]);

        $entry->load('user:id,email,name', 'card:id,title,column_id', 'project:id,name,slug,color');

        return response()->json($entry, 201);
    }

    public function stop(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        abort_if(
            $entry->user_id !== $this->userId($request),
            403,
            "Can only stop your own timer",
        );

        $this->stopEntry($entry);
        $entry->load('user:id,email,name', 'card:id,title,column_id');

        return response()->json($entry);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'card_id' => ['nullable', 'uuid', 'exists:cards,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'started_at' => ['required', 'date'],
            'seconds' => ['required', 'integer', 'min:1', 'max:86400'], // cap at 24h
        ]);

        $userId = $this->userId($request);

        $startedAt = new \DateTimeImmutable($data['started_at']);
        $endedAt = $startedAt->modify("+{$data['seconds']} seconds");

        $entry = TimeEntry::create([
            'project_id' => $project->id,
            'card_id' => $data['card_id'] ?? null,
            'user_id' => $userId,
            'description' => $data['description'] ?? null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'seconds' => $data['seconds'],
        ]);

        $entry->load('user:id,email,name', 'card:id,title,column_id');

        return response()->json($entry, 201);
    }

    public function update(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'card_id' => ['nullable', 'uuid', 'exists:cards,id'],
            'seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ]);

        if (array_key_exists('seconds', $data) && $data['seconds']) {
            $data['ended_at'] = $entry->started_at->copy()->addSeconds($data['seconds']);
        }

        $entry->update($data);
        $entry->load('user:id,email,name', 'card:id,title,column_id');

        return response()->json($entry);
    }

    public function destroy(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);
        abort_if(
            $entry->user_id !== $this->userId($request),
            403,
            "Can only delete your own time entry",
        );
        $entry->delete();

        return response()->json(null, 204);
    }

    private function stopEntry(TimeEntry $entry): void
    {
        $endedAt = now();
        $entry->update([
            'ended_at' => $endedAt,
            'seconds' => $endedAt->diffInSeconds($entry->started_at),
        ]);
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

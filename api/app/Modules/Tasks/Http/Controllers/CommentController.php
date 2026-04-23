<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\CardComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request, Card $card): JsonResponse
    {
        $this->ensureMember($request, $card->project);

        $comments = $card->comments()
            ->with('user:id,email,name')
            ->get();

        return response()->json($comments);
    }

    public function store(Request $request, Card $card): JsonResponse
    {
        $this->ensureMember($request, $card->project);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $userId = $this->userId($request);

        $comment = CardComment::create([
            'card_id' => $card->id,
            'user_id' => $userId,
            'content' => $data['content'],
        ]);

        $comment->load('user:id,email,name');

        $this->activity->log(
            $card->project_id,
            $userId,
            'card.commented',
            $card,
            $card->title,
        );

        return response()->json($comment, 201);
    }

    public function update(Request $request, CardComment $comment): JsonResponse
    {
        $userId = $this->userId($request);
        abort_if($comment->user_id !== $userId, 403, "Can only edit own comment");

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update($data);
        $comment->load('user:id,email,name');

        return response()->json($comment);
    }

    public function destroy(Request $request, CardComment $comment): JsonResponse
    {
        $userId = $this->userId($request);
        abort_if($comment->user_id !== $userId, 403, "Can only delete own comment");

        $comment->delete();

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

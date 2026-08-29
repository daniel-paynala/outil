<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\CardComment;
use App\Support\Mentions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommentController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notify,
    ) {}

    public function index(Request $request, Card $card): JsonResponse
    {
        $this->ensureMember($request, $card->project);

        $comments = $card->comments()
            ->with('user:id,email,name,avatar_path')
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

        $comment->load('user:id,email,name,avatar_path');

        $this->notifierMentions($card, $comment, $userId, deja: []);

        $this->activity->log(
            $card->project_id,
            $userId,
            'card.commented',
            $card,
            $card->title,
        );

        return response()->json($comment, 201);
    }

    /**
     * Prévient les personnes nommées dans un commentaire.
     *
     * ## Ce que la mention doit garantir
     *
     * Un commentaire sans mention est une bouteille à la mer : personne ne sait
     * qu'il existe tant qu'on n'ouvre pas la carte. Une mention en fait une
     * demande adressée — c'est tout son objet, et la notification n'est donc
     * pas un accessoire.
     *
     * ## Seulement les membres du projet
     *
     * L'identifiant vient du client et ne peut pas être cru sur parole. On
     * n'écarte pas seulement les inconnus : notifier quelqu'un à propos d'une
     * carte qu'il ne peut pas ouvrir lui apprendrait son existence et son
     * titre, tout en le laissant devant une porte fermée.
     *
     * ## [$deja] — les mentions déjà notifiées
     *
     * À la modification d'un commentaire, seules les nouvelles mentions
     * partent. Corriger une faute de frappe ne doit pas re-sonner chez tous
     * ceux qui étaient déjà nommés.
     *
     * @param  array<int, string>  $deja
     */
    private function notifierMentions(
        Card $card,
        CardComment $comment,
        string $auteurId,
        array $deja,
    ): void {
        $nouvelles = array_diff(Mentions::ids($comment->content), $deja);
        if ($nouvelles === []) {
            return;
        }

        $membres = $card->project->projectMembers()
            ->whereIn('user_id', $nouvelles)
            ->pluck('user_id');

        $extrait = Str::limit(Mentions::enClair($comment->content), 140);

        foreach ($membres as $destinataire) {
            // `forUser` écarte déjà l'auteur : se mentionner soi-même est un
            // geste courant quand on se laisse une note.
            $this->notify->forUser(
                userId: $destinataire,
                type: 'card.mentioned',
                title: $card->title,
                body: $extrait,
                link: "/projects/{$card->project_id}/tasks?card={$card->id}",
                projectId: $card->project_id,
                actorId: $auteurId,
            );
        }
    }

    public function update(Request $request, CardComment $comment): JsonResponse
    {
        $userId = $this->userId($request);
        abort_if($comment->user_id !== $userId, 403, 'Can only edit own comment');

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        // Les mentions d'avant, pour ne prévenir que les nouvelles : corriger
        // une faute de frappe ne doit pas re-sonner chez ceux qui étaient déjà
        // nommés.
        $avant = Mentions::ids($comment->content);

        $comment->update($data);
        $comment->load('user:id,email,name,avatar_path');

        $this->notifierMentions($comment->card, $comment, $userId, deja: $avant);

        return response()->json($comment);
    }

    public function destroy(Request $request, CardComment $comment): JsonResponse
    {
        $userId = $this->userId($request);
        abort_if($comment->user_id !== $userId, 403, 'Can only delete own comment');

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

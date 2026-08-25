<?php

namespace App\Modules\Messagerie\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messagerie\Models\Conversation;
use App\Modules\Messagerie\Models\ConversationMember;
use App\Modules\Messagerie\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /** Plafond de page, pour qu'un client ne puisse pas demander tout le fil. */
    private const MAX_LIMIT = 100;

    /**
     * Messages d'une conversation, par pagination à curseur.
     *
     * Curseur plutôt qu'offset : un fil reçoit des messages pendant qu'on le
     * remonte, et un `offset` décalerait alors la fenêtre — on reverrait des
     * messages déjà lus, ou on en sauterait.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureMember($request, $conversation);

        $before = $request->query('before');
        $limit = min((int) $request->query('limit', 50), self::MAX_LIMIT);

        $messages = $conversation->messages()
            ->with('author:id,email,name,avatar_path')
            ->when($before, fn ($q) => $q->where('created_at', '<', $before))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            // Récupérés du plus récent au plus ancien pour que le curseur ait
            // un sens ; renvoyés dans l'ordre de lecture.
            ->reverse()
            ->values();

        return response()->json([
            'messages' => $messages,
            // Curseur de la page suivante, null quand on a atteint le début.
            'next_before' => $messages->count() === $limit
                ? $messages->first()?->created_at?->toIso8601String()
                : null,
        ]);
    }

    /**
     * Publie un message et remonte la conversation.
     *
     * `last_message_at` est mis à jour dans la même transaction : sans ça,
     * un échec entre les deux écritures laisserait un message invisible en
     * tête de liste, donc jamais remarqué.
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $membership = $this->membership($request, $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = DB::transaction(function () use ($conversation, $membership, $data) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $membership->user_id,
                'body' => $data['body'],
            ]);

            $conversation->update(['last_message_at' => $message->created_at]);

            // Écrire un message vaut lecture de ce qui précède : sans ça,
            // l'auteur verrait un compteur de non-lus sur son propre fil.
            $membership->update(['last_read_at' => $message->created_at]);

            return $message;
        });

        $message->load('author:id,email,name,avatar_path');

        return response()->json($message, 201);
    }

    /** Suppression logique, réservée à l'auteur. */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $conversation = $message->conversation;
        $membership = $this->membership($request, $conversation);

        abort_if(
            $message->user_id !== $membership->user_id,
            403,
            'Can only delete own message',
        );

        $message->delete();

        return response()->json(null, 204);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function membership(Request $request, Conversation $conversation): ConversationMember
    {
        $membership = ConversationMember::where('conversation_id', $conversation->id)
            ->where('user_id', $this->userId($request))
            ->first();

        return $membership ?? abort(403, 'Not a member of this conversation');
    }

    private function ensureMember(Request $request, Conversation $conversation): void
    {
        $this->membership($request, $conversation);
    }
}

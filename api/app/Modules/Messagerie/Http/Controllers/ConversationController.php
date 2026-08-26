<?php

namespace App\Modules\Messagerie\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Messagerie\Models\Conversation;
use App\Modules\Messagerie\Models\ConversationMember;
use App\Modules\Messagerie\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * Conversations de l'utilisateur, la plus récemment active en tête.
     *
     * Les compteurs de non-lus sont calculés par une seule requête agrégée
     * pour toutes les conversations : une sous-requête par ligne serait un
     * N+1 qui se dégraderait avec l'usage, précisément quand la liste
     * s'allonge.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $conversations = Conversation::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->with(['members.user:id,email,name,avatar_path'])
            ->orderByRaw('last_message_at desc nulls last')
            ->get();

        $unread = $this->unreadCounts($userId);
        $latest = $this->latestMessages($conversations->pluck('id')->all());

        foreach ($conversations as $conversation) {
            $conversation->unread_count = (int) ($unread[$conversation->id] ?? 0);
            $conversation->setRelation('latest_message', $latest[$conversation->id] ?? null);
        }

        return response()->json($conversations);
    }

    /**
     * Nombre de messages non lus par conversation, pour un utilisateur.
     *
     * @return array<string, int>
     */
    private function unreadCounts(string $userId): array
    {
        return DB::table('messages as m')
            ->join('conversation_members as cm', function ($join) use ($userId) {
                $join->on('cm.conversation_id', '=', 'm.conversation_id')
                    ->where('cm.user_id', '=', $userId);
            })
            ->whereNull('m.deleted_at')
            // Ses propres messages ne sont jamais « non lus ».
            ->where(fn ($q) => $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $userId))
            ->where(fn ($q) => $q->whereNull('cm.last_read_at')
                ->orWhereColumn('m.created_at', '>', 'cm.last_read_at'))
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id as conversation_id, count(*) as total')
            ->pluck('total', 'conversation_id')
            ->all();
    }

    /**
     * Discussion directe déjà ouverte entre deux personnes, ou null.
     *
     * Les trois conditions sont nécessaires : le drapeau `is_group`, la
     * présence des deux intéressés, et un effectif de deux exactement. Sans
     * la dernière, un ancien groupe réduit à ces deux membres serait
     * confondu avec une discussion directe.
     */
    private function findDirectConversation(string $userId, string $otherId): ?Conversation
    {
        return Conversation::query()
            ->where('is_group', false)
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('members', fn ($q) => $q->where('user_id', $otherId))
            ->has('members', '=', 2)
            ->first();
    }

    /**
     * Dernier message de chaque conversation, en une seule requête.
     *
     * `distinct on` est propre à Postgres, mais l'app n'a pas d'autre base et
     * c'est la formulation la plus directe : une ligne par conversation, prise
     * dans l'ordre du tri. L'ordre porte aussi sur `id` pour rester
     * déterministe quand deux messages partagent la même seconde — les
     * timestamps Laravel n'ont pas plus de précision.
     *
     * @param  array<int, string>  $conversationIds
     * @return array<string, Message>
     */
    private function latestMessages(array $conversationIds): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        return Message::query()
            ->selectRaw('distinct on (conversation_id) *')
            ->whereIn('conversation_id', $conversationIds)
            ->orderByRaw('conversation_id, created_at desc, id desc')
            ->with(['author:id,email,name,avatar_path', 'attachments'])
            ->get()
            ->keyBy('conversation_id')
            ->all();
    }

    /**
     * Crée une conversation. Le créateur en est membre d'office : une
     * conversation dont l'auteur ne fait pas partie n'aurait aucun sens et
     * deviendrait invisible pour lui.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'topic' => ['nullable', 'string', 'max:255'],
            'is_group' => ['sometimes', 'boolean'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['uuid', 'exists:users,id'],
        ]);

        $userId = $this->userId($request);
        $isGroup = $data['is_group'] ?? true;

        // Un membre ne peut pas se désigner lui-même : il est ajouté d'office.
        $others = collect($data['member_ids'])->reject(fn ($id) => $id === $userId)->unique()->values();

        if ($isGroup && empty($data['name'])) {
            abort(422, 'Un groupe doit avoir un nom.');
        }

        if (! $isGroup) {
            abort_if(
                $others->count() !== 1,
                422,
                'Une discussion directe se tient à deux exactement.',
            );

            // Rouvrir plutôt que dupliquer : sans ça, chaque appel créerait un
            // fil neuf et l'historique se disperserait entre plusieurs
            // conversations avec la même personne.
            $existing = $this->findDirectConversation($userId, $others->first());
            if ($existing !== null) {
                $existing->load('members.user:id,email,name,avatar_path');
                $existing->unread_count = 0;

                return response()->json($existing, 200);
            }
        }

        // Rattacher une conversation à un projet dont on n'est pas membre
        // exposerait son existence à des non-membres.
        if (! empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);
            abort_if(! $project->hasMember($userId), 403, 'Not a member of this project');
        }

        $conversation = DB::transaction(function () use ($data, $userId, $isGroup, $others) {
            $conversation = Conversation::create([
                'project_id' => $data['project_id'] ?? null,
                'name' => $data['name'] ?? null,
                'topic' => $data['topic'] ?? null,
                'is_group' => $isGroup,
                'created_by' => $userId,
            ]);

            foreach ($others->push($userId) as $memberId) {
                ConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $memberId,
                    'role' => $memberId === $userId ? 'owner' : 'member',
                ]);
            }

            return $conversation;
        });

        $conversation->load('members.user:id,email,name,avatar_path');
        $conversation->unread_count = 0;
        $conversation->setRelation('latest_message', null);

        return response()->json($conversation, 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureMember($request, $conversation);

        $conversation->load([
            'members.user:id,email,name,avatar_path',
            'creator:id,email,name,avatar_path',
        ]);

        return response()->json($conversation);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureMember($request, $conversation);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'topic' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation->update($data);

        return response()->json($conversation);
    }

    /** Marque la conversation comme lue jusqu'à maintenant. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $membership = $this->membership($request, $conversation);
        $membership->update(['last_read_at' => now()]);

        return response()->json(['read_at' => $membership->last_read_at]);
    }

    public function addMembers(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureMember($request, $conversation);

        $data = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['uuid', 'exists:users,id'],
        ]);

        foreach ($data['member_ids'] as $memberId) {
            // Idempotent : réinviter quelqu'un déjà présent ne doit ni
            // échouer sur la contrainte d'unicité, ni réinitialiser sa
            // position de lecture.
            ConversationMember::firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $memberId],
                ['role' => 'member'],
            );
        }

        $conversation->load('members.user:id,email,name,avatar_path');

        return response()->json($conversation->members);
    }

    /**
     * Retire un membre. Chacun peut se retirer lui-même ; retirer quelqu'un
     * d'autre demande le rôle `owner`.
     */
    public function removeMember(
        Request $request,
        Conversation $conversation,
        string $userId,
    ): JsonResponse {
        $membership = $this->membership($request, $conversation);

        abort_if(
            $membership->user_id !== $userId && $membership->role !== 'owner',
            403,
            'Owner role required to remove another member',
        );

        ConversationMember::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->delete();

        $this->ensureAnOwnerRemains($conversation);

        return response()->json(null, 204);
    }

    /**
     * Promeut le plus ancien membre si le départ a laissé le groupe sans
     * `owner`.
     *
     * Sans ça, un groupe dont le créateur s'en va devient ingérable : plus
     * personne ne peut retirer un membre, et le seul recours est de quitter
     * un à un.
     */
    private function ensureAnOwnerRemains(Conversation $conversation): void
    {
        $members = ConversationMember::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        if ($members->isEmpty() || $members->contains('role', 'owner')) {
            return;
        }

        $members->first()->update(['role' => 'owner']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    /**
     * Appartenance de l'appelant, ou 403. Toute lecture comme toute écriture
     * passe par là : une conversation n'est visible que de ses membres.
     */
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

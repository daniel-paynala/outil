<?php

namespace App\Modules\Messagerie\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messagerie\Models\Conversation;
use App\Modules\Messagerie\Models\ConversationMember;
use App\Modules\Messagerie\Models\Message;
use App\Modules\Messagerie\Models\MessageAttachment;
use App\Modules\Files\Services\SupabaseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function __construct(private readonly SupabaseStorage $storage) {}

    /** Plafond de page, pour qu'un client ne puisse pas demander tout le fil. */
    private const MAX_LIMIT = 100;

    /** Au-delà, un message devient un dossier partagé — ce n'est plus une
     *  conversation. */
    private const MAX_ATTACHMENTS = 5;

    /**
     * Extensions acceptées. La vidéo en est absente volontairement : c'est le
     * seul type qui remplirait le quota de stockage, et l'usage n'est pas
     * demandé. Le bucket porte la même liste blanche côté Supabase, pour
     * qu'un client contournant l'API se heurte quand même au mur.
     */
    private const ALLOWED = 'jpg,jpeg,png,webp,gif,heic,pdf,txt,csv,md,doc,docx,xls,xlsx,ppt,pptx,zip';

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
            ->with([
                'author:id,email,name,avatar_path',
                'attachments',
                // Une seule profondeur : sans cette limite, une chaîne de
                // réponses chargerait tout le fil de proche en proche.
                'replyTo:id,user_id,body,deleted_at',
                'replyTo.author:id,email,name',
            ])
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
            // Un message peut n'être qu'une image : le corps devient
            // facultatif dès qu'il y a une pièce jointe, et inversement.
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:10240', 'mimes:'.self::ALLOWED],
            'reply_to_id' => ['nullable', 'uuid', 'exists:messages,id'],
        ]);

        // Citer un message d'une autre conversation exposerait son contenu à
        // qui n'y a pas accès.
        if (! empty($data['reply_to_id'])) {
            $quoted = Message::withTrashed()->findOrFail($data['reply_to_id']);
            abort_if(
                $quoted->conversation_id !== $conversation->id,
                422,
                'Message cité hors de cette conversation',
            );
        }

        // Téléversé avant la transaction : un envoi vers Supabase Storage dure
        // le temps d'un réseau mobile, et tenir une transaction Postgres
        // ouverte pendant ce temps bloquerait une connexion du pooler.
        $uploaded = $this->uploadAll(
            $request->file('attachments') ?? [],
            $conversation->id,
        );

        $message = DB::transaction(function () use ($conversation, $membership, $data, $uploaded) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $membership->user_id,
                // Colonne non nulle : une chaîne vide pour un message qui ne
                // porte que des fichiers.
                'body' => $data['body'] ?? '',
                'reply_to_id' => $data['reply_to_id'] ?? null,
            ]);

            foreach ($uploaded as $file) {
                MessageAttachment::create([
                    ...$file,
                    'message_id' => $message->id,
                    'uploaded_by' => $membership->user_id,
                    'created_at' => now(),
                ]);
            }

            $conversation->update(['last_message_at' => $message->created_at]);

            // Écrire un message vaut lecture de ce qui précède : sans ça,
            // l'auteur verrait un compteur de non-lus sur son propre fil.
            $membership->update(['last_read_at' => $message->created_at]);

            return $message;
        });

        $message->load([
            'author:id,email,name,avatar_path',
            'attachments',
            'replyTo:id,user_id,body,deleted_at',
            'replyTo.author:id,email,name',
        ]);

        return response()->json($message, 201);
    }

    /**
     * Modifie le texte d'un message. Réservé à l'auteur.
     *
     * Les pièces jointes ne sont pas modifiables : les remplacer laisserait
     * des fichiers orphelins dans le stockage, et rien n'indique que ce soit
     * un besoin. Pour changer un fichier, on supprime et on renvoie.
     *
     * `edited_at` est posé explicitement plutôt que déduit de `updated_at` :
     * ce dernier bouge aussi à la suppression logique, ce qui afficherait
     * « modifié » sur un message qu'on vient d'effacer.
     */
    public function update(Request $request, Message $message): JsonResponse
    {
        $membership = $this->membership($request, $message->conversation);

        abort_if(
            $message->user_id !== $membership->user_id,
            403,
            'Can only edit own message',
        );

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // Un message qui ne portait que des fichiers n'a pas de texte à
        // modifier : lui en ajouter changerait sa nature.
        abort_if(
            $message->body === '' && $message->attachments()->exists(),
            422,
            'Ce message ne contient que des pièces jointes.',
        );

        $message->update([
            'body' => $data['body'],
            'edited_at' => now(),
        ]);

        $message->load(['author:id,email,name,avatar_path', 'attachments']);

        return response()->json($message);
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

    /**
     * Téléverse les fichiers et renvoie de quoi créer les lignes.
     *
     * Le chemin est préfixé par la conversation : ça garde le bucket lisible,
     * et un jour où l'on voudra purger un fil, tout est au même endroit.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    private function uploadAll(array $files, string $conversationId): array
    {
        $uploaded = [];

        foreach ($files as $file) {
            $original = $file->getClientOriginalName();
            $safe = Str::slug(pathinfo($original, PATHINFO_FILENAME))
                .'.'.strtolower($file->getClientOriginalExtension());
            $path = "{$conversationId}/".Str::uuid().'-'.$safe;

            $this->storage->upload(
                $path,
                (string) $file->get(),
                $file->getMimeType() ?: 'application/octet-stream',
                MessageAttachment::BUCKET,
            );

            $uploaded[] = [
                'path' => $path,
                'name' => $original,
                'size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];
        }

        return $uploaded;
    }

    /**
     * URL signée d'une pièce jointe, valable une heure.
     *
     * Le bucket est privé : rien n'est accessible sans passer par ici, et
     * l'appartenance à la conversation est vérifiée à chaque demande.
     */
    public function attachmentUrl(Request $request, MessageAttachment $attachment): JsonResponse
    {
        $this->ensureMember($request, $attachment->message->conversation);

        return response()->json([
            'url' => $this->storage->signedUrl(
                $attachment->path,
                3600,
                MessageAttachment::BUCKET,
            ),
            'expires_in' => 3600,
        ]);
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

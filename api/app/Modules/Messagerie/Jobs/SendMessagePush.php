<?php

namespace App\Modules\Messagerie\Jobs;

use App\Modules\Messagerie\Models\Conversation;
use App\Modules\Messagerie\Models\Message;
use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Notifie les autres membres d'une conversation.
 *
 * Mis en file plutôt qu'exécuté en ligne : l'appel à OneSignal coûte un
 * aller-retour réseau, qui s'ajouterait à la latence de l'envoi du message —
 * celle qu'on a passé du temps à réduire. Le worker s'en charge après coup.
 *
 * Volontairement, **aucune ligne n'est écrite dans `notifications`**. Le
 * compteur de non-lus se déduit déjà de `conversation_members.last_read_at`,
 * et une ligne par message et par destinataire ferait de cette table la plus
 * grosse de la base : cinq personnes à cent messages par jour, c'est
 * ~90 Mo par an pour dupliquer ce que `messages` contient déjà.
 */
class SendMessagePush implements ShouldQueue
{
    use Queueable;

    /** Trois essais, comme le worker systemd le prévoit. */
    public int $tries = 3;

    public function __construct(private readonly string $messageId) {}

    public function handle(PushSender $push): void
    {
        $message = Message::with(['author:id,email,name', 'attachments'])
            ->find($this->messageId);

        // Le message a pu être supprimé entre l'envoi et le traitement.
        if ($message === null) {
            return;
        }

        $conversation = Conversation::with('members')->find($message->conversation_id);
        if ($conversation === null) {
            return;
        }

        $recipients = $conversation->members
            ->pluck('user_id')
            ->reject(fn ($id) => $id === $message->user_id)
            ->values()
            ->all();

        if (empty($recipients)) {
            return;
        }

        $push->send(
            $recipients,
            $this->title($conversation, $message),
            $this->body($message),
            [
                'type' => 'message.received',
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ],
        );
    }

    /**
     * Dans un groupe, le nom du groupe porte plus d'information que celui de
     * l'auteur — qui apparaît de toute façon dans le corps. Dans un échange
     * direct, c'est l'inverse.
     */
    private function title(Conversation $conversation, Message $message): string
    {
        if ($conversation->is_group && ! empty($conversation->name)) {
            return $conversation->name;
        }

        return $message->author?->name
            ?? $message->author?->email
            ?? 'Nouveau message';
    }

    private function body(Message $message): string
    {
        $text = trim($message->body);

        if ($text === '') {
            $count = $message->attachments->count();

            return $count > 1 ? "{$count} pièces jointes" : 'Pièce jointe';
        }

        // Une notification ne se lit pas en entier : deux lignes au plus sur
        // un écran verrouillé.
        $flat = preg_replace('/\s+/', ' ', $text) ?? $text;
        $prefix = '';

        if ($message->conversation?->is_group && $message->author !== null) {
            $prefix = Str::of($message->author->name ?? $message->author->email)
                ->before(' ')
                ->append(' : ')
                ->toString();
        }

        return $prefix.Str::limit($flat, 140);
    }
}

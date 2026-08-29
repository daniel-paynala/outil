<?php

namespace App\Modules\Messagerie\Jobs;

use App\Models\User;
use App\Modules\Messagerie\Models\Conversation;
use App\Modules\Messagerie\Models\Message;
use App\Modules\Messagerie\Services\PushSender;
use App\Support\Mentions;
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
        $message = Message::with(['author:id,email,name,avatar_path', 'attachments'])
            ->find($this->messageId);

        // Le message a pu être supprimé entre l'envoi et le traitement.
        if ($message === null) {
            return;
        }

        $conversation = Conversation::with('members')->find($message->conversation_id);
        if ($conversation === null) {
            return;
        }

        $others = $conversation->members
            ->pluck('user_id')
            ->reject(fn ($id) => $id === $message->user_id);

        // Les personnes nommées dans le message, et membres de la
        // conversation. L'appartenance est vérifiée : un identifiant vient du
        // client, et notifier quelqu'un à propos d'un fil qu'il ne peut pas
        // ouvrir lui en apprendrait le contenu tout en le laissant dehors.
        $nommes = $others
            ->intersect(Mentions::ids($message->body))
            ->values()
            ->all();

        // Le filtre est fait en base plutôt qu'en mémoire : la préférence
        // peut avoir changé depuis que la conversation a été chargée, et une
        // notification envoyée à quelqu'un qui l'a coupée est exactement le
        // genre de détail qui décrédibilise un réglage.
        //
        // Les personnes nommées en sont exclues : elles reçoivent un envoi
        // distinct, qui ne consulte pas la préférence. Voir plus bas.
        $ordinaires = User::whereIn('id', $others->diff($nommes))
            ->where('notify_messages', true)
            ->pluck('id')
            ->all();

        $charge = [
            'type' => 'message.received',
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ];

        if ($ordinaires !== []) {
            $push->send(
                $ordinaires,
                $this->title($conversation, $message),
                $this->body($message),
                $charge,
            );
        }

        // ## Pourquoi une mention ignore `notify_messages`
        //
        // Dans un groupe actif, on coupe les notifications — c'est le réflexe
        // sain, et c'est précisément ce qui rend la mention utile. La taire
        // parce que la préférence est décochée reviendrait à ne laisser aucun
        // moyen d'appeler quelqu'un, et à ramener chacun à surveiller le fil
        // en permanence.
        //
        // Le titre change aussi : « Fidèle vous a mentionné » se distingue
        // d'un coup d'œil sur un écran verrouillé, là où le nom du groupe
        // ressemble à tous les autres messages de la journée.
        if ($nommes !== []) {
            $push->send(
                $nommes,
                $this->auteur($message).' vous a mentionné',
                $this->body($message),
                [...$charge, 'type' => 'message.mentioned'],
            );
        }
    }

    /** Le nom de l'auteur, ou son adresse à défaut. */
    private function auteur(Message $message): string
    {
        return $message->author?->name
            ?? $message->author?->email
            ?? 'Quelqu\'un';
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
        // un écran verrouillé. Et le balisage des mentions n'a rien à y
        // faire — « @[Fidèle](8c1f…) tu peux voir ? » se lit mal.
        $flat = preg_replace('/\s+/', ' ', Mentions::enClair($text)) ?? $text;
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

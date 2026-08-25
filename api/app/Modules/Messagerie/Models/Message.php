<?php

namespace App\Modules\Messagerie\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'edited_at',
        'reply_to_id',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Message cité.
     *
     * `withTrashed()` volontairement : citer un message qu'on efface ensuite
     * ne doit pas faire disparaître la citation de la réponse. Le client
     * affiche alors « message supprimé », ce qui garde le fil lisible.
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Null si le compte a été supprimé : le message reste, l'auteur devient
     * anonyme. C'est le choix inscrit dans le schéma (user_id en SET NULL)
     * pour ne pas trouer un historique partagé.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

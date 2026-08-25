<?php

namespace App\Modules\Messagerie\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
    ];

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

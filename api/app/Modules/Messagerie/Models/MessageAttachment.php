<?php

namespace App\Modules\Messagerie\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use HasUuids;

    /** Créées une fois, jamais modifiées. */
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'path',
        'name',
        'size_bytes',
        'mime_type',
        'uploaded_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** Bucket dédié : plafond et liste blanche de types y sont posés. */
    public const BUCKET = 'messagerie';

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}

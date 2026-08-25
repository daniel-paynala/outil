<?php

namespace App\Models;

use App\Modules\Core\Models\Project;
use App\Modules\Files\Models\ProjectFile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingDocumentNotification extends Model
{
    use HasUuids;

    protected $table = 'pending_document_notifications';

    protected $fillable = [
        'project_id',
        'user_id',
        'file_ids',
        'last_upload_at',
    ];

    protected function casts(): array
    {
        return [
            'file_ids' => 'array',
            'last_upload_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Charge les ProjectFile correspondant aux file_ids.
     */
    public function files()
    {
        return ProjectFile::whereIn('id', $this->file_ids ?? [])->get();
    }
}

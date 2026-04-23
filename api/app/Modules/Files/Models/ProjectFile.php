<?php

namespace App\Modules\Files\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class ProjectFile extends Model
{
    use HasUuids, Searchable;

    public function searchableAs(): string
    {
        return 'project_files';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    protected $fillable = [
        'project_id',
        'path',
        'name',
        'size_bytes',
        'mime_type',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

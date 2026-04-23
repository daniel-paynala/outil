<?php

namespace App\Modules\Docs\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class DocPage extends Model
{
    use HasUuids, Searchable, SoftDeletes;

    public function searchableAs(): string
    {
        return 'doc_pages';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'content' => $this->content,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    protected $fillable = [
        'project_id',
        'parent_id',
        'title',
        'slug',
        'content',
        'position',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocPage::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(DocPage::class, 'parent_id')->orderBy('position')->orderBy('title');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocRevision::class, 'page_id')->orderByDesc('created_at');
    }
}

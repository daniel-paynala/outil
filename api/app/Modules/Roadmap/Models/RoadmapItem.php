<?php

namespace App\Modules\Roadmap\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoadmapItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'project_id',
        'release_id',
        'title',
        'description',
        'horizon',
        'position',
        'effort',
        'start_date',
        'target_date',
        'tags',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start_date' => 'date',
            'target_date' => 'date',
            'tags' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'roadmap_item_owners')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_roadmap_items')->withTimestamps();
    }
}

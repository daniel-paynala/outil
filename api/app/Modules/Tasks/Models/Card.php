<?php

namespace App\Modules\Tasks\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Card extends Model
{
    use HasUuids, Searchable, SoftDeletes;

    public function searchableAs(): string
    {
        return 'cards';
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
            'description' => $this->description,
            'priority' => $this->priority,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    protected $fillable = [
        'project_id',
        'column_id',
        'parent_card_id',
        'title',
        'description',
        'position',
        'priority',
        'due_date',
        'estimate',
        'assigned_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_date' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(Column::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'parent_card_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Card::class, 'parent_card_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CardComment::class)->orderBy('created_at');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'card_labels')->withTimestamps();
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Card::class,
            'card_dependencies',
            'card_id',
            'depends_on_card_id',
        )->withTimestamps();
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            Card::class,
            'card_dependencies',
            'depends_on_card_id',
            'card_id',
        )->withTimestamps();
    }
}

<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Column extends Model
{
    use HasUuids;

    protected $table = 'columns';

    protected $fillable = [
        'project_id',
        'name',
        'position',
        'color',
        'is_done',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Setter explicite pour `is_done`.
     *
     * Le cast 'boolean' standard de Laravel bind via PDO_PGSQL en PHP 8.4 envoie
     * un entier (0/1), ce que Postgres strict refuse pour une colonne BOOLEAN.
     * On envoie 't'/'f' qui sont les littéraux booléens natifs de Postgres.
     */
    protected function isDone(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (bool) $value,
            set: fn ($value) => $value ? 't' : 'f',
        );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('position');
    }
}

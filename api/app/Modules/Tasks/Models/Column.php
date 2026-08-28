<?php

namespace App\Modules\Tasks\Models;

use App\Models\Concerns\StoresPostgresBoolean;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Column extends Model
{
    use HasUuids;
    use StoresPostgresBoolean;

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

    /** Voir `StoresPostgresBoolean` : le littéral `'t'`/`'f'` n'est posé qu'en Postgres. */
    protected function isDone(): Attribute
    {
        return $this->postgresBoolean();
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

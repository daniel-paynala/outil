<?php

namespace App\Modules\Adr\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Decision extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'project_id',
        'number',
        'title',
        'status',
        'context',
        'decision',
        'consequences',
        'alternatives',
        'references',
        'decided_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'decided_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

namespace App\Modules\Files\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectFolder extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProjectFolder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

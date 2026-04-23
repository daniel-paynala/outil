<?php

namespace App\Modules\Github\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubRepo extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'full_name',
        'platform',
        'default_branch',
        'description',
        'access_token',
        'linked_by',
        'last_synced_at',
    ];

    /**
     * Token is encrypted at rest via Laravel Crypt; hidden from default JSON.
     */
    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function commits(): HasMany
    {
        return $this->hasMany(GithubCommit::class)->orderByDesc('authored_at');
    }
}

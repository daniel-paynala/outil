<?php

namespace App\Modules\Vault\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VaultEntry extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'project_id',
        'name',
        'category',
        'username',
        'secret',
        'notes',
        'url',
        'expires_at',
        'created_by',
        'updated_by',
    ];

    /**
     * The secret is encrypted at rest via Laravel's cast (AES-256-CBC + HMAC,
     * key = APP_KEY). It's also hidden from default serialization so it only
     * leaks to responses when explicitly marked visible.
     */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'expires_at' => 'datetime',
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

    public function accessLogs(): HasMany
    {
        return $this->hasMany(VaultAccessLog::class, 'entry_id')->orderByDesc('created_at');
    }
}

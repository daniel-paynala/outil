<?php

namespace App\Modules\Github\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubCommit extends Model
{
    use HasUuids;

    protected $fillable = [
        'github_repo_id',
        'sha',
        'message',
        'author_name',
        'author_email',
        'author_login',
        'author_avatar_url',
        'html_url',
        'authored_at',
    ];

    protected function casts(): array
    {
        return [
            'authored_at' => 'datetime',
        ];
    }

    public function repo(): BelongsTo
    {
        return $this->belongsTo(GithubRepo::class, 'github_repo_id');
    }
}

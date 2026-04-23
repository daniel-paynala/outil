<?php

namespace App\Modules\Time\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'card_id',
        'user_id',
        'description',
        'started_at',
        'ended_at',
        'seconds',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'seconds' => 'integer',
        ];
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

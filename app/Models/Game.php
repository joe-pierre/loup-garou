<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'status',
        'max_players',
        'round',
        'phase_deadline',
        'winner_team',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'phase_deadline' => 'datetime',
            'started_at'     => 'datetime',
            'finished_at'    => 'datetime',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function alivePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class)->where('is_alive', true);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(Exclusion::class);
    }

    public function phaseRemainingSeconds(): int
    {
        if ($this->phase_deadline === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->phase_deadline, false));
    }
}

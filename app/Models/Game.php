<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Workflow\Registry;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'status',
        'max_players',
        'round',
        'phase_deadline',
        'timers',
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
            'timers'         => 'array',
        ];
    }

    public function timer(string $key): int
    {
        return $this->timers[$key] ?? config("game.timers.{$key}");
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

    /**
     * Accessors requis par MethodMarkingStore (Symfony Workflow) — la marking
     * store en mode "single state" exige une méthode getStatus()/setStatus()
     * publique, les attributs Eloquent dynamiques n'étant pas détectés par
     * réflexion.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status, array $context = []): void
    {
        $this->status = $status;
    }

    public function canTransition(string $transitionName): bool
    {
        $registry = app(Registry::class);

        return $registry->get($this)->can($this, $transitionName);
    }

    public function applyTransition(string $transitionName): void
    {
        $registry = app(Registry::class);
        $registry->get($this)->apply($this, $transitionName);
        $this->save();
    }
}

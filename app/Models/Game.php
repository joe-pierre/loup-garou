<?php

namespace App\Models;

use App\Services\TimerCalculator;
use Illuminate\Database\Eloquent\Builder;
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
        'night_sub_phase',
        'timers',
        'settings',
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
            'settings'       => 'array',
        ];
    }

    public function timer(string $key): int
    {
        return TimerCalculator::get($this, $key);
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

    /**
     * Applique une transition Symfony Workflow et persiste le nouveau statut.
     *
     * ⚠️ INTERDIT si $this->status est un statut intermédiaire hors Workflow.
     *
     * Le Workflow Symfony ne connaît que 5 places canoniques :
     *   waiting · electing_mayor · night · day · finished
     *
     * Les statuts suivants sont gérés MANUELLEMENT hors Workflow (via update()) :
     *   processing_night · processing_day · wolves_turn · role_reveal
     *
     * Appeler applyTransition() depuis l'un de ces statuts intermédiaires lèvera :
     *   Symfony\Component\Workflow\Exception\LogicException
     *   "The marking does not contain a place 'processing_night'."
     * Cette exception est catchée silencieusement par le queue worker Laravel
     * (job marqué 'failed' sans message clair dans les logs applicatifs).
     *
     * ✅ Pattern autorisé pour quitter un statut intermédiaire :
     *   $game->update(['status' => 'night']); // retour au dernier statut canonique
     *   $game->applyTransition('start_day');  // puis transition Workflow
     *
     * ❌ Pattern interdit — provoque une LogicException silencieuse :
     *   // $game->status === 'processing_night'
     *   $game->applyTransition('start_day'); // BOOM
     *
     * Voir RISK_GUARDS.md Guard #5 pour la règle lockForUpdate() associée.
     */
    public function applyTransition(string $transitionName): void
    {
        $registry = app(Registry::class);
        $registry->get($this)->apply($this, $transitionName);
        $this->save();
    }

    /**
     * Retourne true si la partie est en phase nuit ou dans un statut intermédiaire de nuit.
     */
    public function isNightPhase(): bool
    {
        // PhaseGuard ne couvre pas ce cas : méthode d'instance sur le modèle, évite une dépendance service → modèle
        return in_array($this->status, ['night', 'wolves_turn', 'processing_night']);
    }

    /**
     * Retourne true si la partie est en phase jour ou dans un statut intermédiaire de jour.
     */
    public function isDayPhase(): bool
    {
        // PhaseGuard ne couvre pas ce cas : méthode d'instance sur le modèle, évite une dépendance service → modèle
        return in_array($this->status, ['day', 'processing_day']);
    }

    /**
     * Retourne true si la partie est terminée.
     */
    public function isFinished(): bool
    {
        return $this->status === 'finished';
    }

    /**
     * Retourne true si la partie est annulée (terminée sans vainqueur).
     */
    public function isCancelled(): bool
    {
        return $this->status === 'finished' && $this->winner_team === null;
    }

    /**
     * Retourne le nombre de joueurs vivants.
     */
    public function aliveCount(): int
    {
        return $this->alivePlayers()->count();
    }

    /**
     * Retourne le nombre de loups vivants.
     */
    public function aliveWerewolvesCount(): int
    {
        return $this->alivePlayers()
            ->whereIn('role', ['werewolf', 'white_wolf'])
            ->count();
    }

    /**
     * Retourne le nombre de joueurs vivants hors loups.
     */
    public function aliveVillagersCount(): int
    {
        return $this->alivePlayers()
            ->whereNotIn('role', ['werewolf', 'white_wolf'])
            ->count();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['finished']);
    }

    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }
}

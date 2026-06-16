<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamePlayer extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'game_id',
        'user_id',
        'pseudo',
        'role',
        'is_alive',
        'is_host',
        'is_mayor',
        'is_inactive',
        'is_ready',
        'joined_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_alive'    => 'boolean',
            'is_host'     => 'boolean',
            'is_mayor'    => 'boolean',
            'is_inactive' => 'boolean',
            'is_ready'    => 'boolean',
            'joined_at'   => 'datetime',
            'settings'    => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class, 'player_id');
    }

    // v1.2 : white_wolf sera ajouté à cet array
    public function isWerewolf(): bool
    {
        return in_array($this->role, ['werewolf', 'white_wolf']);
    }

    // v1.2 : witch, hunter seront ajoutés à cet array
    public function isVillagerSide(): bool
    {
        return in_array($this->role, ['villager', 'seer', 'witch', 'hunter']);
    }

    public function isWitch(): bool
    {
        return $this->role === 'witch';
    }

    public function isHunter(): bool
    {
        return $this->role === 'hunter';
    }

    /**
     * Retourne true si le joueur est vivant et actif (non inactif).
     */
    public function isActiveAndAlive(): bool
    {
        return $this->is_alive && !$this->is_inactive;
    }

    /**
     * Retourne true si la potion de soin de la sorcière a été utilisée.
     */
    public function witchHealUsed(): bool
    {
        return (bool) ($this->settings['witch_heal_used'] ?? false);
    }

    /**
     * Retourne true si la potion de poison de la sorcière a été utilisée.
     */
    public function witchKillUsed(): bool
    {
        return (bool) ($this->settings['witch_kill_used'] ?? false);
    }

    /**
     * Retourne true si la sorcière a encore au moins une potion disponible.
     */
    public function witchHasPotion(): bool
    {
        return !$this->witchHealUsed() || !$this->witchKillUsed();
    }
}

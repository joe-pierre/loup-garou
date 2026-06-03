<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameAction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'game_id',
        'player_id',
        'type',
        'weight',
        'target_player_id',
        'round',
        'phase',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'player_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'target_player_id');
    }

    // Toutes les lignes sont retournées ; seul player_id est exclu de la projection
    public function scopeAnonymized(Builder $query): Builder
    {
        return $query->select([
            'id',
            'game_id',
            'type',
            'weight',
            'target_player_id',
            'round',
            'phase',
            'created_at',
        ]);
    }
}

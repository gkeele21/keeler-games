<?php

namespace App\Models\Scorekeeper;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoredGameCompetitor extends Model
{
    use HasFactory;

    /**
     * Scorekeeper competitors and trivia teams share the unified `competitors`
     * table. The owning column is `game_id` for both kinds; the kind is implied
     * by the game it belongs to, so no discriminator is needed here.
     */
    protected $table = 'competitors';

    protected $fillable = ['game_id', 'name', 'display_order'];

    public function scoredGame(): BelongsTo
    {
        return $this->belongsTo(ScoredGame::class, 'game_id');
    }

    /**
     * Roster players making up this competitor — one for an individual,
     * many for a team.
     */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(
            Player::class,
            'competitor_player',
            'competitor_id',
            'player_id',
        );
    }

    public function roundScores(): HasMany
    {
        return $this->hasMany(RoundScore::class, 'competitor_id');
    }
}

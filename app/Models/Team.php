<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory;

    /**
     * Teams and Scorekeeper competitors share the unified `competitors` table.
     * The owning column is `game_id` for both kinds; a team's kind is implied
     * by the game it belongs to, so no discriminator is needed here.
     */
    protected $table = 'competitors';

    protected $fillable = [
        'game_id',
        'name',
        'color',
        'display_order',
        'total_score',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'total_score' => 'integer',
    ];

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function answerReveals(): HasMany
    {
        return $this->hasMany(AnswerReveal::class);
    }

    public function controlledQuestions(): HasMany
    {
        return $this->hasMany(SessionQuestion::class, 'controlling_team_id');
    }

    public function addScore(int $points): void
    {
        $this->increment('total_score', $points);
    }
}

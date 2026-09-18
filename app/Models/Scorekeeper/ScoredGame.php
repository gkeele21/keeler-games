<?php

namespace App\Models\Scorekeeper;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoredGame extends Model
{
    use HasFactory;

    /**
     * Both game kinds share the unified `games` table; a global scope keeps
     * this model to the scorekeeper rows and the creating hook stamps the
     * discriminator, so callers keep treating it as its own table.
     */
    protected $table = 'games';

    public const KIND = 'scorekeeper';

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('kind', function ($query) {
            $query->where($query->getModel()->getTable() . '.kind', self::KIND);
        });

        static::creating(function ($game) {
            $game->kind = self::KIND;
        });
    }

    protected $fillable = [
        'household_id',
        'game_template_id',
        'template_name_snapshot',
        'base_game_type',
        'target_score',
        'low_score_wins',
        'max_rounds',
        'score_fields',
        'team_based',
        'allow_self_scoring',
        'started_at',
        'ended_at',
        'is_complete',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'low_score_wins' => 'boolean',
            'is_complete'    => 'boolean',
            'team_based'     => 'boolean',
            'allow_self_scoring' => 'boolean',
            'score_fields'   => 'array',
            'started_at'     => 'datetime',
            'ended_at'       => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GameTemplate::class, 'game_template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(ScoredGameCompetitor::class, 'game_id')->orderBy('display_order');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class)->orderBy('round_number');
    }
}

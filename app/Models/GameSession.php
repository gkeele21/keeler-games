<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Scorekeeper\Player;
use App\Support\GameCode;

class GameSession extends Model
{
    use HasFactory;

    /**
     * Both game kinds share the unified `games` table; a global scope keeps
     * this model to the online rows and the creating hook stamps the
     * discriminator, so callers keep treating it as its own table.
     */
    protected $table = 'games';

    public const KIND = 'online';

    protected $fillable = [
        'game_type_id',
        'host_user_id',
        'name',
        'status',
        'settings',
        'invite_code',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('kind', function ($query) {
            $query->where($query->getModel()->getTable() . '.kind', self::KIND);
        });

        static::creating(function ($session) {
            $session->kind = self::KIND;

            if (empty($session->invite_code)) {
                $session->invite_code = GameCode::generate();
            }
        });

        // Question usage is counted only when a game actually finishes — a setup
        // that's abandoned or cancelled never counts. When the session transitions
        // to "completed", bump times_used once for each distinct question that was
        // part of it. (Selection prefers least-used questions, so this keeps the
        // rotation honest without charging usage for games that never played.)
        static::updated(function ($session) {
            if ($session->wasChanged('status') && $session->status === 'completed') {
                // NB: sessionQuestions() carries a default orderBy('display_order'),
                // which MySQL rejects when combined with SELECT DISTINCT (error 3065).
                // Pull the ids and de-dupe in PHP so the counter always bumps.
                $questionIds = $session->sessionQuestions()
                    ->pluck('question_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($questionIds) {
                    Question::whereIn('id', $questionIds)->increment('times_used');
                }
            }
        });
    }

    public function gameType(): BelongsTo
    {
        return $this->belongsTo(GameType::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'game_id')->orderBy('display_order');
    }

    public function sessionCards(): HasMany
    {
        return $this->hasMany(SessionCard::class)->orderBy('card_number');
    }

    public function sessionQuestions(): HasMany
    {
        return $this->hasMany(SessionQuestion::class)->orderBy('display_order');
    }

    public function gameState(): HasOne
    {
        return $this->hasOne(GameState::class);
    }

    public function sessionPlayers(): HasMany
    {
        return $this->hasMany(SessionPlayer::class);
    }

    /**
     * Household roster players who were present for this game — the attendance
     * roster behind cross-game question history. See game_session_players.
     */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'game_session_players');
    }

    public function unassignedPlayers(): HasMany
    {
        return $this->hasMany(SessionPlayer::class)->whereNull('team_id');
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        // First check session settings, then fall back to game type defaults
        $settings = $this->settings ?? [];
        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        $defaults = $this->gameType?->default_config ?? [];
        return $defaults[$key] ?? $default;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_session_id',
        'user_id',
        'guest_name',
        'team_id',
        'is_captain',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'is_captain' => 'boolean',
    ];

    protected $appends = [
        'display_name',
    ];

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->user?->name ?? $this->guest_name ?? 'Guest';
    }
}

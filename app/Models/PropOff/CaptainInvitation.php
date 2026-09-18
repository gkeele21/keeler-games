<?php

namespace App\Models\PropOff;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CaptainInvitation extends Model
{
    use HasFactory;

    /**
     * Both invitation kinds share the unified `propoff_invitations` table. A
     * captain link is the one with no group — it lets the holder create a group
     * rather than join an existing one. The global scope keeps this model to
     * those rows and the creating hook holds group_id null, so callers keep
     * treating it as its own table.
     */
    protected $table = 'propoff_invitations';

    protected $fillable = [
        'event_id', 'token', 'max_uses', 'times_used', 'expires_at',
        'is_active', 'created_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('captainLink', function ($query) {
            $query->whereNull($query->getModel()->getTable() . '.group_id');
        });

        static::creating(function ($invitation) {
            $invitation->group_id = null;
        });
    }

    protected $attributes = [
        'is_active'  => true,
        'times_used' => 0,
    ];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_active'  => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateToken(): string
    {
        return Str::random(32);
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->expires_at && now()->gt($this->expires_at)) {
            return false;
        }
        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function canBeUsed(): bool
    {
        return $this->isValid();
    }

    public function incrementUsage(): void
    {
        $this->increment('times_used');
    }

    public function getUrl(): string
    {
        return route('propoff.captain.join', $this->token);
    }

    public function getUrlAttribute(): string
    {
        return $this->getUrl();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeValid(Builder $q): Builder
    {
        return $q->active()
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($w) => $w->whereNull('max_uses')->orWhereColumn('times_used', '<', 'max_uses'));
    }
}

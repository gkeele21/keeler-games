<?php

namespace App\Models\PropOff;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventInvitation extends Model
{
    use HasFactory;

    /**
     * Both invitation kinds share the unified `propoff_invitations` table. An
     * event link carries a group — it admits the holder to that specific group.
     * The global scope keeps this model to those rows.
     */
    protected $table = 'propoff_invitations';

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('groupLink', function ($query) {
            $query->whereNotNull($query->getModel()->getTable() . '.group_id');
        });
    }

    protected $fillable = [
        'event_id', 'group_id', 'token', 'max_uses', 'times_used',
        'expires_at', 'is_active',
    ];

    protected $attributes = [
        'is_active'  => true,
        'times_used' => 0,
    ];

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

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
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

    public function incrementUsage(): void
    {
        $this->increment('times_used');
    }

    public function getUrl(): string
    {
        return route('propoff.guest.join', $this->token);
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

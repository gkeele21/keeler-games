<?php

namespace App\Models\PropOff;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory;

    protected $table = 'propoff_groups';

    protected $fillable = [
        'event_id', 'previous_group_id', 'name', 'code', 'grading_source',
        'entry_cutoff', 'description', 'is_public', 'created_by',
    ];

    protected $appends = ['is_locked'];

    protected function casts(): array
    {
        return [
            'entry_cutoff' => 'datetime',
            'is_public'    => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** The group this one continues from — last year's party. */
    public function previousGroup(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_group_id');
    }

    /** Groups that continue from this one. */
    public function nextGroups(): HasMany
    {
        return $this->hasMany(self::class, 'previous_group_id');
    }

    /**
     * This group and every ancestor, oldest last. Depth-capped so a bad
     * previous_group_id cycle can never spin — a real chain is one link a year.
     */
    public function lineage(int $maxDepth = 10): array
    {
        $chain = [$this];
        $seen = [$this->id => true];
        $group = $this;

        while (count($chain) < $maxDepth && $group->previous_group_id) {
            $group = $group->previousGroup;
            if (! $group || isset($seen[$group->id])) {
                break;
            }
            $seen[$group->id] = true;
            $chain[] = $group;
        }

        return $chain;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'propoff_group_user')
            ->withPivot('joined_at', 'is_captain')
            ->withTimestamps();
    }

    public function members(): BelongsToMany
    {
        return $this->users();
    }

    public function captains(): BelongsToMany
    {
        return $this->users()->wherePivot('is_captain', true);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function leaderboards(): HasMany
    {
        return $this->hasMany(Leaderboard::class);
    }

    public function groupQuestions(): HasMany
    {
        return $this->hasMany(GroupQuestion::class)->orderBy('display_order');
    }

    public function groupQuestionAnswers(): HasMany
    {
        return $this->hasMany(GroupQuestionAnswer::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class);
    }

    public function isCaptain($user): bool
    {
        if (! $user) {
            return false;
        }
        $userId = is_object($user) ? $user->id : $user;

        return $this->users()
            ->wherePivot('user_id', $userId)
            ->wherePivot('is_captain', true)
            ->exists();
    }

    public function addCaptain(User $user): void
    {
        if ($this->users()->wherePivot('user_id', $user->id)->exists()) {
            $this->users()->updateExistingPivot($user->id, ['is_captain' => true]);
        } else {
            $this->users()->attach($user->id, ['is_captain' => true, 'joined_at' => now()]);
        }
    }

    public function removeCaptain(User $user): void
    {
        $this->users()->updateExistingPivot($user->id, ['is_captain' => false]);
    }

    public function usesCaptainGrading(): bool
    {
        return $this->grading_source === 'captain';
    }

    public function usesAdminGrading(): bool
    {
        return $this->grading_source === 'admin';
    }

    /**
     * Group cutoff wins; falls back to the event lock date.
     */
    public function acceptingEntries(): bool
    {
        if ($this->entry_cutoff) {
            return now()->lt($this->entry_cutoff);
        }

        return $this->event ? $this->event->acceptingEntries() : true;
    }

    public function getIsLockedAttribute(): bool
    {
        return ! $this->acceptingEntries();
    }
}

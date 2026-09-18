<?php

namespace App\Services\PropOff;

use App\Models\PropOff\Group;
use App\Models\PropOff\Leaderboard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One place that decides whether someone joining a PropOff group is a person we
 * already know or a genuinely new one.
 *
 * Before this existed, four routes created users independently — the invitation
 * flow, the code-join flow, the captain's add-guest, and captain group creation
 * — and only two of them looked for an existing person first. The invitation
 * flow, which carried 140 of the joins for Super Bowl LX, created a fresh row
 * every single time. That is where most of the 18 duplicated names came from:
 * the same human re-registering after losing their magic link.
 *
 * Deliberately NOT silent de-duplication. Matching on name alone would merge two
 * different real people who share one — "Megan" already appears three times in
 * the existing data. A name match is a *candidate*; the caller shows it back to
 * the person and lets them confirm or reject it.
 */
class ParticipantResolver
{
    /**
     * A name match inside this group — the person may be returning. Returns a
     * candidate to confirm, never something to silently reuse.
     */
    public function findCandidateInGroup(string $name, Group $group): ?User
    {
        $split = User::splitName($name);

        return $group->users()
            ->where('first_name', $split['first_name'])
            ->where('last_name', $split['last_name'])
            ->first();
    }

    /**
     * The same search, but walking back through the groups this one continues
     * from. PropOff is annual and groups are per-event, so on the night of a new
     * event the current group is empty and only the lineage can recognise
     * anyone. Nearest year wins, so the freshest entry is the one offered.
     *
     * Returns the matched person together with the group they were found in —
     * the caller needs that to word the prompt honestly ("you played last
     * year") and to summarise the right entry.
     *
     * @return array{user: User, group: Group}|null
     */
    public function findCandidateInLineage(string $name, Group $group): ?array
    {
        foreach ($group->lineage() as $ancestor) {
            $match = $this->findCandidateInGroup($name, $ancestor);

            if ($match) {
                return ['user' => $match, 'group' => $ancestor];
            }
        }

        return null;
    }

    /**
     * An email match is a definite identity — emails are unique on users, and
     * someone typing their own address is asserting who they are. Safe to reuse
     * without confirmation.
     */
    public function findByEmail(?string $email): ?User
    {
        if (! $email) {
            return null;
        }

        return User::where('email', $email)->first();
    }

    /**
     * Attach to the group, tolerating an already-present membership so a
     * double-submitted join never throws or duplicates the pivot row.
     */
    public function attachTo(User $user, Group $group, bool $isCaptain = false): void
    {
        if ($group->users()->where('users.id', $user->id)->exists()) {
            return;
        }

        $group->users()->attach($user->id, [
            'joined_at'  => now(),
            'is_captain' => $isCaptain,
        ]);
    }

    /**
     * Create a new guest identity. A guest_token is issued only when there's no
     * password, since it's the magic-link credential for people who can't log
     * in any other way.
     */
    public function createGuest(string $name, ?string $email = null, ?string $password = null): User
    {
        return User::create([
            ...User::splitName($name),
            'email'       => $email,
            'password'    => $password ? Hash::make($password) : null,
            'role'        => 'guest',
            'guest_token' => $password ? null : Str::random(32),
        ]);
    }

    /**
     * The summary shown on the "is this you?" step: enough for someone to
     * recognise their own entry without exposing anyone else's answers.
     */
    public function candidateSummary(User $user, Group $group, ?Group $current = null): array
    {
        $entry = $group->entries()->where('user_id', $user->id)->first();
        $isPriorYear = $current !== null && $current->id !== $group->id;

        return [
            'name'     => $user->name,
            'answered' => $entry ? $entry->userAnswers()->count() : 0,
            'total'    => $group->groupQuestions()->where('is_active', true)->count(),
            'user_id'  => $user->id,
            // Lets the prompt say "you played X last time" rather than implying
            // they have already joined tonight, which would be a lie.
            'from_previous_group' => $isPriorYear,
            'previous_group_name' => $isPriorYear ? $group->name : null,
            'previous_event_name' => $isPriorYear ? $group->event?->name : null,
        ];
    }

    /**
     * Undo a wrong claim: give this member a fresh identity for THIS group only.
     *
     * The claim step deliberately trades a little risk for a lot of convenience
     * — it offers a name match back and lets the person confirm it. With three
     * different Megans in the data, somebody will eventually press "yes, that's
     * me" on someone else's entry, and until now there was no way back.
     *
     * Splitting is scoped to one group, which is what makes it safe. Entries and
     * leaderboard rows are per (user, group), and answers hang off the entry, so
     * moving this group's rows to a new person leaves every other year's history
     * exactly where it was — including the original person's.
     *
     * Returns the newly separated user.
     */
    public function separateFromGroup(User $user, Group $group): User
    {
        return DB::transaction(function () use ($user, $group) {
            $pivot = $group->users()->where('users.id', $user->id)->first()?->pivot;

            $fresh = $this->createGuest($user->name);

            // Move membership, keeping when they joined.
            $group->users()->detach($user->id);
            $group->users()->attach($fresh->id, [
                'joined_at'  => $pivot?->joined_at ?? now(),
                'is_captain' => false,
            ]);

            // This group's entry — and, through it, every answer they gave.
            $group->entries()->where('user_id', $user->id)->update(['user_id' => $fresh->id]);

            // This group's standing.
            Leaderboard::where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->update(['user_id' => $fresh->id]);

            return $fresh;
        });
    }
}

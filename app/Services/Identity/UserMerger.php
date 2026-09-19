<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Folds a PropOff guest credential into the real person behind it.
 *
 * PropOff creates a `users` row for every party guest, so one human can end up
 * with several: 136 guest rows exist for roughly 120 people, and some of those
 * people also hold a real account. There is no reliable automatic way to tell
 * which guest is which human — the name overlap is mostly first-names-only and
 * was explicitly too weak to act on — so merging is driven by someone who knows
 * them, and this class only carries out a decision already made.
 *
 * Every one of the 22 foreign key columns pointing at `users` is handled. A
 * merge that missed one would either leave the source row undeletable or
 * silently cascade its data away.
 */
class UserMerger
{
    /**
     * Columns that simply move. No uniqueness involves the user column, so a
     * straight UPDATE is safe.
     */
    private const REASSIGN = [
        ['game_templates', 'created_by_user_id'],
        ['games', 'created_by_user_id'],
        ['games', 'host_user_id'],
        ['household_invites', 'accepted_by_user_id'],
        ['household_invites', 'invited_by_user_id'],
        ['households', 'owner_user_id'],
        ['propoff_entries', 'submitted_by_captain_id'],
        ['propoff_event_answers', 'set_by'],
        ['propoff_events', 'created_by'],
        ['propoff_groups', 'created_by'],
        ['propoff_invitations', 'created_by'],
        ['propoff_question_templates', 'created_by'],
        ['questions', 'created_by'],
        ['session_players', 'user_id'],
    ];

    /**
     * Columns under a uniqueness rule that includes the user column. Where the
     * target already holds the equivalent row the source's is dropped rather
     * than moved, since the UPDATE would violate the constraint.
     *
     * [table, user column, remaining columns of the unique key]
     */
    private const DEDUPE = [
        ['propoff_group_user', 'user_id', ['group_id']],
        ['propoff_leaderboards', 'user_id', ['event_id', 'group_id']],
        ['household_user', 'user_id', ['household_id']],
    ];

    /** Merge $source into $target, returning a per-table summary of what moved. */
    public function merge(User $source, User $target): array
    {
        $this->guard($source, $target);

        return DB::transaction(function () use ($source, $target) {
            $moved = [];

            foreach (self::DEDUPE as [$table, $column, $scope]) {
                $moved += $this->moveOrDrop($table, $column, $scope, $source->id, $target->id);
            }

            // Safe to move wholesale: guard() has already refused any case where
            // both people hold an entry in the same group.
            $moved['propoff_entries'] = DB::table('propoff_entries')
                ->where('user_id', $source->id)->update(['user_id' => $target->id]);

            // A roster player links to whoever the person is. If the target
            // already has one in that household, clear the source link instead
            // of creating a second.
            $moved += $this->moveOrDrop(
                'players', 'user_id', ['household_id'], $source->id, $target->id, drop: false,
            );

            foreach (self::REASSIGN as [$table, $column]) {
                $n = DB::table($table)->where($column, $source->id)->update([$column => $target->id]);
                if ($n > 0) {
                    $moved[$table . '.' . $column] = $n;
                }
            }

            // Friendships are symmetric, and merging can produce a self-link.
            DB::table('user_friends')->where('user_id', $source->id)->orWhere('friend_id', $source->id)->delete();
            DB::table('user_friends')->whereColumn('user_id', 'friend_id')->delete();

            $adopted = $this->adoptIdentifyingDetails($source, $target);

            $source->delete();

            return array_filter($moved + $adopted, fn ($n) => $n > 0);
        });
    }

    /**
     * Why a merge may be refused. Each case means a human needs to look rather
     * than the tool guessing.
     */
    private function guard(User $source, User $target): void
    {
        if ($source->id === $target->id) {
            throw new RuntimeException('Cannot merge a person into themselves.');
        }

        if ($source->role !== 'guest') {
            throw new RuntimeException(
                "Refusing to merge {$source->name} (#{$source->id}): only guest credentials can be merged away, "
                . 'and this is a real account. Merge the guest into the account, not the other way round.',
            );
        }

        // Both holding an entry in one group means both actually played it.
        // Either they are not the same person, or one is a duplicate
        // registration whose answers someone has to choose between — not
        // something to settle by silently dropping a row.
        $clash = DB::table('propoff_entries as a')
            ->join('propoff_entries as b', function ($j) {
                $j->on('a.group_id', '=', 'b.group_id')->on('a.event_id', '=', 'b.event_id');
            })
            ->where('a.user_id', $source->id)
            ->where('b.user_id', $target->id)
            ->count();

        if ($clash > 0) {
            throw new RuntimeException(
                "Refusing to merge {$source->name} (#{$source->id}) into {$target->name} (#{$target->id}): "
                . "both have played in {$clash} of the same group(s). Decide which entry is real first.",
            );
        }
    }

    /**
     * Move rows whose uniqueness includes the user column, dropping any the
     * target already covers. With $drop false the source row stays and has its
     * link cleared instead.
     *
     * Returns moved and discarded counts separately: a row the target already
     * covered is DELETED, and reporting that as merely "nothing moved" would
     * hide a deletion from whoever is reviewing the merge.
     *
     * @return array<string,int>
     */
    private function moveOrDrop(
        string $table,
        string $column,
        array $scope,
        int $sourceId,
        int $targetId,
        bool $drop = true,
    ): array {
        $moved = 0;
        $discarded = 0;

        foreach (DB::table($table)->where($column, $sourceId)->get() as $row) {
            $covered = DB::table($table)
                ->where($column, $targetId)
                ->where(function ($q) use ($scope, $row) {
                    foreach ($scope as $col) {
                        $q->where($col, $row->{$col});
                    }
                })
                ->exists();

            // household_user has no id of its own; key it by the composite.
            $key = property_exists($row, 'id')
                ? ['id' => $row->id]
                : array_merge([$column => $sourceId], array_combine($scope, array_map(fn ($c) => $row->{$c}, $scope)));

            if ($covered) {
                $drop
                    ? DB::table($table)->where($key)->delete()
                    : DB::table($table)->where($key)->update([$column => null]);
                $discarded++;

                continue;
            }

            DB::table($table)->where($key)->update([$column => $targetId]);
            $moved++;
        }

        return [
            $table => $moved,
            $table . ' (' . ($drop ? 'already covered, removed' : 'already covered, unlinked') . ')' => $discarded,
        ];
    }

    /**
     * Carry across identifying details the survivor lacks.
     *
     * Which row keeps the data is decided by which one actually played, and
     * that is often not the row with the better name. "Nick Williams" holds a
     * surname and an email but no entry, while a bare "Nick" holds the entry —
     * merging into the latter would keep the answers and silently discard both
     * ways of recognising him.
     *
     * Only ever fills blanks. The survivor's own details always win, so this
     * cannot overwrite a known-good name with a worse one.
     */
    private function adoptIdentifyingDetails(User $source, User $target): array
    {
        $adopted = [];
        $fill = [];

        // Only a surname with letters in it. People disambiguate themselves at
        // the join screen by typing "Megan 2", and splitName puts that "2" in
        // last_name — adopting it would spread the junk rather than the name.
        $sourceLast = trim((string) $source->last_name);

        if (trim((string) $target->last_name) === '' && preg_match('/\p{L}/u', $sourceLast)) {
            $fill['last_name'] = $sourceLast;
            $adopted['adopted surname'] = 1;
        }

        // email is unique, so this has to happen while the source still holds
        // it — the row is deleted immediately after.
        if (! $target->email && $source->email) {
            DB::table('users')->where('id', $source->id)->update(['email' => null]);
            $fill['email'] = $source->email;
            $adopted['adopted email'] = 1;
        }

        if ($fill) {
            DB::table('users')->where('id', $target->id)->update($fill);
        }

        return $adopted;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Identity\UserMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Review and fold PropOff guest credentials into the real people behind them.
 *
 * A one-off cleanup driven by someone who knows these people: name matching
 * alone is too weak to trust (most overlaps are first-name-only, and "Megan"
 * appears three times), so this only ever SUGGESTS and never merges on its own.
 */
class MergePropOffGuests extends Command
{
    protected $signature = 'propoff:merge-guests
        {--candidates : List guest credentials that look like someone already known}
        {--merge= : Perform a merge, given as SOURCE_ID:TARGET_ID}
        {--from= : Apply a decision file (JSON), once per environment}
        {--force : Merge even when the names do not match}
        {--dry-run : Report what would move without writing}';

    protected $description = 'Fold PropOff guest credentials into the real person behind them';

    public function handle(UserMerger $merger): int
    {
        if ($file = $this->option('from')) {
            return $this->applyDecisionFile($merger, $file);
        }

        if ($mergeSpec = $this->option('merge')) {
            return $this->performMerge($merger, $mergeSpec);
        }

        return $this->listCandidates();
    }

    private function performMerge(UserMerger $merger, string $spec): int
    {
        if (! preg_match('/^(\d+):(\d+)$/', $spec, $m)) {
            $this->error('Expected --merge=SOURCE_ID:TARGET_ID, e.g. --merge=57:2');

            return self::FAILURE;
        }

        $source = User::find((int) $m[1]);
        $target = User::find((int) $m[2]);

        if (! $source || ! $target) {
            $this->error('One of those ids does not exist.');

            return self::FAILURE;
        }

        $this->line("Merging <fg=yellow>{$source->name}</> (#{$source->id}, {$source->role})"
            . " into <fg=green>{$target->name}</> (#{$target->id}, {$target->role})");

        // A mistyped id is the likeliest way to merge two unrelated people, and
        // nothing else catches it: the ids are valid, so every other guard
        // passes. Sharing a first name is the weakest thing that makes a pair
        // plausible, so anything below that has to be said out loud.
        if (! $this->namesArePlausible($source, $target) && ! $this->option('force')) {
            $this->error(
                "These names do not look like the same person: \"{$source->name}\" and \"{$target->name}\"."
            );
            $this->line('If that is genuinely one person, re-run with <fg=yellow>--force</>.');

            return self::FAILURE;
        }

        try {
            if ($this->option('dry-run')) {
                DB::beginTransaction();
                $moved = $merger->merge($source, $target);
                DB::rollBack();
                $this->warn('Dry run — rolled back, nothing was written.');
            } else {
                $moved = $merger->merge($source, $target);
            }
        } catch (RuntimeException $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $moved) {
            $this->line('  (the guest had no data to move)');
        }

        foreach ($moved as $what => $n) {
            $this->line("  {$what}: {$n}");
        }

        return self::SUCCESS;
    }

    /**
     * Name clusters among guests, sorted by how safe each pairing looks.
     *
     * A shared name alone means nothing — the live data has two different
     * people called Hazel at two different parties. What separates a duplicate
     * from a namesake is where they played:
     *
     *   same group, one with no entry   the same person registered twice
     *   no group at all                 an abandoned registration, nothing to lose
     *   different groups, both played   two people; leave them alone
     */
    private function listCandidates(): int
    {
        $guests = User::where('role', 'guest')->get();
        $accounts = User::where('role', '!=', 'guest')->get();

        $groupsOf = fn (User $u) => DB::table('propoff_group_user')->where('user_id', $u->id)->pluck('group_id')->all();
        $entriesOf = fn (User $u) => DB::table('propoff_entries')->where('user_id', $u->id)->count();

        $clusters = $guests->groupBy(fn ($u) => mb_strtolower(trim($u->first_name)))->filter(fn ($c, $k) => $k !== '');
        $rows = [];

        foreach ($clusters as $name => $members) {
            // A guest sharing a first name with a real account is the other
            // shape worth surfacing: the same person who later signed up.
            foreach ($accounts as $account) {
                if (mb_strtolower(trim($account->first_name)) !== $name) {
                    continue;
                }
                foreach ($members as $guest) {
                    $rows[] = [$guest->id, $guest->name, $account->id, $account->name,
                        'guest matches an account', 'likely'];
                }
            }

            if ($members->count() < 2) {
                continue;
            }

            // Rows that joined nothing and answered nothing hold no data at
            // all, so pairing them off against each other is noise — four empty
            // "Megan" rows would otherwise produce six identical suggestions.
            // Point them all at whichever sibling actually played.
            $empty = $members->filter(fn ($u) => ! $groupsOf($u) && $entriesOf($u) === 0);
            $active = $members->reject(fn ($u) => $empty->contains('id', $u->id));

            if ($empty->isNotEmpty()) {
                $keeper = $active->sortByDesc(fn ($u) => $entriesOf($u))->first() ?? $empty->sortBy('id')->first();

                foreach ($empty as $u) {
                    if ($u->id === $keeper->id) {
                        continue;
                    }
                    $rows[] = [$u->id, $u->name, $keeper->id, $keeper->name,
                        'joined nothing and never played — holds no data', 'likely'];
                }

                $members = $active;
                if ($members->count() < 2) {
                    continue;
                }
            }

            foreach ($members as $a) {
                foreach ($members as $b) {
                    if ($a->id >= $b->id) {
                        continue;
                    }

                    [$ga, $gb] = [$groupsOf($a), $groupsOf($b)];
                    [$ea, $eb] = [$entriesOf($a), $entriesOf($b)];
                    $shared = array_intersect($ga, $gb);

                    // Point the emptier row at the fuller one — if the call is
                    // wrong, that is the cheaper direction to have taken.
                    [$src, $dst] = ($ea <=> $eb) <= 0 ? [$a, $b] : [$b, $a];
                    [$srcGroups, $srcEntries] = $src->id === $a->id ? [$ga, $ea] : [$gb, $eb];

                    if ($shared && min($ea, $eb) === 0) {
                        $rows[] = [$src->id, $src->name, $dst->id, $dst->name,
                            'same group (' . implode(',', $shared) . '), one never played', 'likely'];
                        continue;
                    }

                    if (! $srcGroups && $srcEntries === 0) {
                        $rows[] = [$src->id, $src->name, $dst->id, $dst->name,
                            'abandoned registration — joined no group', 'likely'];
                        continue;
                    }

                    if ($ea > 0 && $eb > 0 && ! $shared) {
                        $rows[] = [$src->id, $src->name, $dst->id, $dst->name,
                            'different groups, both played — probably two people', 'unlikely'];
                        continue;
                    }

                    $rows[] = [$src->id, $src->name, $dst->id, $dst->name, 'shared name only', 'unclear'];
                }
            }
        }

        if (! $rows) {
            $this->info('No guest credentials look mergeable.');

            return self::SUCCESS;
        }

        $rank = ['likely' => 0, 'unclear' => 1, 'unlikely' => 2];
        usort($rows, fn ($a, $b) => [$rank[$a[5]], $a[1]] <=> [$rank[$b[5]], $b[1]]);

        $this->table(['guest #', 'guest', 'into #', 'into', 'why', 'verdict'], $rows);
        $this->newLine();
        $this->line('<fg=yellow>Suggestions only.</> "unlikely" rows are shown so you can see what was');
        $this->line('considered and rejected — merging one would fuse two different people.');
        $this->line('Then: <fg=green>php artisan propoff:merge-guests --merge=GUEST:INTO --dry-run</>');

        return self::SUCCESS;
    }

    /**
     * Apply a committed decision file, following this repo's convention that
     * schema lives in migrations and data fixes live in a one-off idempotent
     * script run once per environment (see
     * docs/america-says-attendance-note.md).
     *
     * Which guest is which person is a human judgement, so the decisions are
     * recorded once and replayed identically everywhere rather than being
     * re-made per environment. Two properties make that safe:
     *
     *  - Idempotent. A source that no longer exists has already been merged, so
     *    the entry is skipped. Re-running is a no-op.
     *  - Verified. Each entry carries the names as they were when the call was
     *    made, and a mismatch aborts rather than merging strangers. Ids are
     *    only stable because every environment is restored from the same
     *    production data; if that ever stops being true, this is what catches
     *    it.
     */
    private function applyDecisionFile(UserMerger $merger, string $path): int
    {
        if (! is_file($path)) {
            $this->error("No decision file at {$path}");

            return self::FAILURE;
        }

        $decisions = json_decode((string) file_get_contents($path), true);

        if (! is_array($decisions)) {
            $this->error("{$path} is not valid JSON.");

            return self::FAILURE;
        }

        $dry = $this->option('dry-run');
        $applied = 0;
        $skipped = 0;

        if ($dry) {
            DB::beginTransaction();
        }

        foreach ($decisions as $i => $d) {
            $label = "entry {$i}";

            foreach (['source', 'target', 'source_name', 'target_name'] as $required) {
                if (! isset($d[$required])) {
                    $this->error("{$label}: missing \"{$required}\".");
                    $dry and DB::rollBack();

                    return self::FAILURE;
                }
            }

            $source = User::find($d['source']);
            $target = User::find($d['target']);

            if (! $source) {
                $this->line("  <fg=gray>skip</> {$d['source_name']} (#{$d['source']}) — already merged");
                $skipped++;
                continue;
            }

            if (! $target) {
                $this->error("{$label}: target #{$d['target']} ({$d['target_name']}) does not exist.");
                $dry and DB::rollBack();

                return self::FAILURE;
            }

            // The names are the guard against id drift between environments.
            if (! $this->nameMatches($source, $d['source_name']) || ! $this->nameMatches($target, $d['target_name'])) {
                $this->error(
                    "{$label}: refusing — expected \"{$d['source_name']}\" -> \"{$d['target_name']}\", "
                    . "found \"{$source->name}\" -> \"{$target->name}\". Ids have drifted; re-check the decisions.",
                );
                $dry and DB::rollBack();

                return self::FAILURE;
            }

            try {
                $moved = $merger->merge($source, $target);
            } catch (RuntimeException $e) {
                $this->error("{$label}: " . $e->getMessage());
                $dry and DB::rollBack();

                return self::FAILURE;
            }

            $detail = $moved ? implode(', ', array_map(fn ($n, $k) => "{$k}={$n}", $moved, array_keys($moved))) : 'nothing to move';
            $this->line("  <fg=green>merged</> {$d['source_name']} (#{$d['source']}) into {$d['target_name']} (#{$d['target']}) — {$detail}");
            $applied++;
        }

        if ($dry) {
            DB::rollBack();
            $this->warn('Dry run — rolled back, nothing was written.');
        }

        $this->newLine();
        $this->info("{$applied} merged, {$skipped} already done.");

        return self::SUCCESS;
    }

    private function nameMatches(User $user, string $expected): bool
    {
        return strcasecmp(trim($user->name), trim($expected)) === 0;
    }

    /**
     * Whether two records could plausibly be one person. Deliberately generous —
     * it only has to catch a fat-fingered id, not adjudicate identity, and a
     * false refusal costs one --force while a false pass costs a restore.
     */
    private function namesArePlausible(User $a, User $b): bool
    {
        $first = fn (User $u) => mb_strtolower(trim($u->first_name));

        if ($first($a) === '' || $first($b) === '') {
            return false;
        }

        if ($first($a) === $first($b)) {
            return true;
        }

        // Nicknames and shortenings: Bert/Robert, Dan/Daniela, Tiff/Tiffany.
        return str_starts_with($first($a), $first($b)) || str_starts_with($first($b), $first($a));
    }
}

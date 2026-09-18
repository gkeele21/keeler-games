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
     * Guests whose name matches someone else. Ordered so the strongest signal —
     * a full name with a surname — comes first; a bare first name is a weak hint
     * and is labelled as such.
     */
    private function listCandidates(): int
    {
        $guests = User::where('role', 'guest')->get();
        $others = User::all()->keyBy('id');
        $rows = [];

        foreach ($guests as $guest) {
            $first = trim($guest->first_name);
            $last = trim($guest->last_name);

            if ($first === '') {
                continue;
            }

            foreach ($others as $other) {
                if ($other->id === $guest->id) {
                    continue;
                }
                if (strcasecmp(trim($other->first_name), $first) !== 0) {
                    continue;
                }

                $otherLast = trim($other->last_name);
                $strong = $last !== '' && strcasecmp($otherLast, $last) === 0;

                // A guest matching another guest is only worth showing when the
                // surname agrees; otherwise every "Ben" pairs with every "Ben".
                if (! $strong && $other->role === 'guest') {
                    continue;
                }

                $guestEntries = DB::table('propoff_entries')->where('user_id', $guest->id)->count();

                // A guest-to-guest pair is symmetric and would otherwise be
                // listed twice. Show it once, pointing the guest with less to
                // lose at the one with more — merging away the emptier record
                // is the cheaper direction if the call turns out wrong.
                if ($other->role === 'guest') {
                    $otherEntries = DB::table('propoff_entries')->where('user_id', $other->id)->count();
                    if ([$guestEntries, $guest->id] > [$otherEntries, $other->id]) {
                        continue;
                    }
                }

                $rows[] = [
                    $guest->id,
                    $guest->name,
                    $other->id,
                    $other->name,
                    $other->role,
                    $strong ? 'full name' : 'first name only',
                    $guestEntries,
                ];
            }
        }

        if (! $rows) {
            $this->info('No guest credentials look like anyone already known.');

            return self::SUCCESS;
        }

        usort($rows, fn ($a, $b) => [$b[5], $a[1]] <=> [$a[5], $b[1]]);

        $this->table(
            ['guest #', 'guest', 'match #', 'match', 'role', 'confidence', 'entries'],
            $rows,
        );
        $this->newLine();
        $this->line('These are <fg=yellow>suggestions only</> — confirm each against people you know.');
        $this->line('Then: <fg=green>php artisan propoff:merge-guests --merge=GUEST:MATCH --dry-run</>');

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

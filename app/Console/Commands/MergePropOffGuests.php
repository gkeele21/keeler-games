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
        {--dry-run : With --merge, report what would move without writing}';

    protected $description = 'Fold PropOff guest credentials into the real person behind them';

    public function handle(UserMerger $merger): int
    {
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
}

<?php

namespace App\Console\Commands;

use App\Models\Scorekeeper\Player;
use App\Models\User;
use App\Services\Identity\RosterLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Review and connect roster players to the people who log in as them.
 *
 * Same shape as propoff:merge-guests — suggests, never decides — but the stakes
 * are lower: a link writes one foreign key and --unlink takes it back.
 */
class LinkRosterPlayers extends Command
{
    protected $signature = 'players:link-users
        {--candidates : List roster players that look like a known person}
        {--link= : Link, given as PLAYER_ID:USER_ID}
        {--unlink= : Remove the link from a player id}
        {--from= : Apply a decision file (JSON), once per environment}
        {--export= : Write the links that exist HERE to a decision file}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Connect household roster players to the accounts of the same people';

    public function handle(RosterLinker $linker): int
    {
        if ($id = $this->option('unlink')) {
            return $this->unlink($linker, (int) $id);
        }

        if ($file = $this->option('export')) {
            return $this->export($file);
        }

        if ($file = $this->option('from')) {
            return $this->applyDecisionFile($linker, $file);
        }

        if ($spec = $this->option('link')) {
            return $this->linkOne($linker, $spec);
        }

        return $this->listCandidates($linker);
    }

    private function unlink(RosterLinker $linker, int $playerId): int
    {
        $player = Player::find($playerId);

        if (! $player) {
            $this->error("No player #{$playerId}.");

            return self::FAILURE;
        }

        if (! $player->user_id) {
            $this->line("{$player->name} is not linked to anyone.");

            return self::SUCCESS;
        }

        $linker->unlink($player);
        $this->info("Unlinked {$player->name} (#{$player->id}).");

        return self::SUCCESS;
    }

    private function linkOne(RosterLinker $linker, string $spec): int
    {
        if (! preg_match('/^(\d+):(\d+)$/', $spec, $m)) {
            $this->error('Expected --link=PLAYER_ID:USER_ID, e.g. --link=22:2');

            return self::FAILURE;
        }

        $player = Player::find((int) $m[1]);
        $user = User::find((int) $m[2]);

        if (! $player || ! $user) {
            $this->error('One of those ids does not exist.');

            return self::FAILURE;
        }

        try {
            $this->option('dry-run')
                ? DB::transaction(function () use ($linker, $player, $user) {
                    $linker->link($player, $user);
                    throw new RollbackDryRun();
                })
                : $linker->link($player, $user);
        } catch (RollbackDryRun) {
            $this->warn('Dry run — rolled back, nothing was written.');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$player->name} (#{$player->id}) -> {$user->name} (#{$user->id})");

        return self::SUCCESS;
    }

    /**
     * Apply a committed decision file, matching the convention used for guest
     * merges: decisions are made once and replayed per environment, skipped if
     * already applied, and refused if the ids no longer mean what they meant.
     */
    private function applyDecisionFile(RosterLinker $linker, string $path): int
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

        $dry and DB::beginTransaction();

        foreach ($decisions as $i => $d) {
            foreach (['player', 'user', 'player_name', 'user_name'] as $key) {
                if (! isset($d[$key])) {
                    $this->error("entry {$i}: missing \"{$key}\".");
                    $dry and DB::rollBack();

                    return self::FAILURE;
                }
            }

            $player = Player::find($d['player']);
            $user = User::find($d['user']);

            if (! $player || ! $user) {
                $this->error("entry {$i}: player #{$d['player']} or user #{$d['user']} no longer exists.");
                $dry and DB::rollBack();

                return self::FAILURE;
            }

            if (strcasecmp(trim($player->name), trim($d['player_name'])) !== 0
                || strcasecmp(trim($user->name), trim($d['user_name'])) !== 0) {
                $this->error(
                    "entry {$i}: refusing — expected \"{$d['player_name']}\" -> \"{$d['user_name']}\", "
                    . "found \"{$player->name}\" -> \"{$user->name}\". Ids have drifted."
                );
                $dry and DB::rollBack();

                return self::FAILURE;
            }

            if ($player->user_id === $user->id) {
                $this->line("  <fg=gray>skip</> {$player->name} — already linked");
                $skipped++;
                continue;
            }

            try {
                $linker->link($player, $user);
            } catch (RuntimeException $e) {
                $this->error("entry {$i}: " . $e->getMessage());
                $dry and DB::rollBack();

                return self::FAILURE;
            }

            $this->line("  <fg=green>linked</> {$player->name} (#{$player->id}) -> {$user->name} (#{$user->id})");
            $applied++;
        }

        if ($dry) {
            DB::rollBack();
            $this->warn('Dry run — rolled back, nothing was written.');
        }

        $this->newLine();
        $this->info("{$applied} linked, {$skipped} already done.");

        return self::SUCCESS;
    }


    /**
     * Capture the links that exist in THIS database as a decision file.
     *
     * Links made by hand leave no record, so an environment that has been
     * linked directly cannot be replayed anywhere else — and unlike a merge,
     * there is no missing row to notice afterwards. Exporting turns whatever
     * was done into the same replayable file the rest of this work uses.
     *
     * Each household owner's own player is skipped: the app creates that link
     * itself in ensureDefaultHousehold, so replaying it would be noise.
     */
    private function export(string $path): int
    {
        $rows = DB::table('players as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('households as h', 'h.id', '=', 'p.household_id')
            ->whereNotNull('p.user_id')
            ->orderBy('p.id')
            ->selectRaw('p.id as pid, p.name as pname, u.id as uid, h.name as hh, h.owner_user_id as owner')
            ->get();

        $decisions = [];

        foreach ($rows as $r) {
            if ((int) $r->owner === (int) $r->uid) {
                continue;
            }

            $decisions[] = [
                'player' => (int) $r->pid,
                'user' => (int) $r->uid,
                'player_name' => $r->pname,
                'user_name' => User::find($r->uid)?->name,
                'note' => 'Exported from ' . config('app.env') . ' on ' . now()->toDateString()
                    . ($r->hh ? " — {$r->hh} roster." : '.'),
            ];
        }

        if (! $decisions) {
            $this->info('No links to export beyond the household owners\' own players.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($decisions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->info(count($decisions) . " link(s) written to {$path}");
        $this->line('Commit it, then apply elsewhere with <fg=green>--from=' . $path . '</>');

        return self::SUCCESS;
    }

    private function listCandidates(RosterLinker $linker): int
    {
        $candidates = $linker->candidates();

        if (! $candidates) {
            $this->info('No unlinked roster players look like a known person.');

            return self::SUCCESS;
        }

        $this->table(
            ['player #', 'player', 'household', 'user #', 'user', 'why', 'confidence'],
            array_map(fn ($c) => [
                $c['player']->id,
                $c['player']->name,
                $c['player']->household?->name ?? '-',
                $c['user']->id,
                $c['user']->name,
                $c['why'],
                $c['confidence'],
            ], $candidates),
        );

        $this->newLine();
        $this->line('<fg=yellow>Suggestions only.</> A link says "this roster person is that account".');
        $this->line('Then: <fg=green>php artisan players:link-users --link=PLAYER:USER --dry-run</>');
        $this->line('Wrong one? <fg=green>php artisan players:link-users --unlink=PLAYER</>');

        return self::SUCCESS;
    }
}

/** Thrown solely to roll a dry run back. */
class RollbackDryRun extends \RuntimeException
{
}

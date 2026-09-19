<?php

namespace App\Services\Identity;

use App\Models\Scorekeeper\Player;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Connects a household roster player to the person who logs in as them.
 *
 * The two live in different tables for a reason that is easy to misread as a
 * mistake: `users` is the credential record — anyone who authenticates, which
 * includes PropOff party guests holding a magic link — while `players` is a
 * name on one household's roster, entered by the household owner and carrying
 * no login. One human is legitimately both, and can be several players, one per
 * household they play in.
 *
 * What was missing is the join between them, so "Hazel on the Williams roster"
 * and "Hazel who played the Super Bowl" were the same person with nothing
 * saying so. players.user_id has always existed for this; nothing populated it
 * except the owner's own player.
 *
 * Linking is deliberately much weaker than merging: it writes one foreign key,
 * deletes nothing, and unlink puts it back. A wrong link is an inconvenience,
 * not a restore from a dump.
 */
class RosterLinker
{
    public function link(Player $player, User $user): void
    {
        $this->guard($player, $user);

        $player->update(['user_id' => $user->id]);
    }

    public function unlink(Player $player): void
    {
        $player->update(['user_id' => null]);
    }

    private function guard(Player $player, User $user): void
    {
        if ($player->user_id === $user->id) {
            throw new RuntimeException("{$player->name} is already linked to that person.");
        }

        if ($player->user_id) {
            throw new RuntimeException(
                "{$player->name} is already linked to someone else (#{$player->user_id}). Unlink first."
            );
        }

        // A person appears at most once on a given roster. The app enforces this
        // when adding players by hand; linking has to respect the same rule or
        // the household ends up with the same human twice.
        $taken = Player::where('household_id', $player->household_id)
            ->where('user_id', $user->id)
            ->exists();

        if ($taken) {
            throw new RuntimeException(
                "{$user->name} already has a player on that roster — linking would put them on it twice."
            );
        }
    }

    /**
     * Unlinked roster players that look like a known person, ranked by how much
     * corroborates the name.
     *
     * A shared name alone is weak — the merge work turned up two different
     * people called Hazel. What raises confidence here is household membership:
     * if the account is already a member of the household whose roster the
     * player sits on, they are almost certainly that person.
     *
     * @return array<int,array{player: Player, user: User, why: string, confidence: string}>
     */
    public function candidates(): array
    {
        $players = Player::whereNull('user_id')->with('household')->get();
        $users = User::all();
        $out = [];

        foreach ($players as $player) {
            $name = mb_strtolower(trim($player->name));

            if ($name === '') {
                continue;
            }

            foreach ($users as $user) {
                $full = mb_strtolower(trim($user->first_name . ' ' . $user->last_name));
                $first = mb_strtolower(trim($user->first_name));

                $exact = $name === $full;
                $firstOnly = ! $exact && $name === $first;

                // Rosters are written in the shorthand people actually use —
                // Tiff, Dave, Shay — while the account carries the full name.
                // Exact matching alone silently misses every one of those.
                // Three characters minimum, or "Jo" pairs with half the table.
                $nickname = ! $exact && ! $firstOnly
                    && mb_strlen($name) >= 3 && mb_strlen($first) >= 3
                    && (str_starts_with($first, $name) || str_starts_with($name, $first));

                if (! $exact && ! $firstOnly && ! $nickname) {
                    continue;
                }

                // Already on this roster under another player — a link here
                // would duplicate them, so it is not a candidate at all.
                if (Player::where('household_id', $player->household_id)->where('user_id', $user->id)->exists()) {
                    continue;
                }

                $inHousehold = DB::table('household_user')
                    ->where('household_id', $player->household_id)
                    ->where('user_id', $user->id)
                    ->exists();

                [$why, $confidence] = match (true) {
                    $inHousehold && $exact => ['name matches and they are in this household', 'strong'],
                    $inHousehold => ['name matches and they are in this household', 'strong'],
                    $exact => ['full name matches, but not a member of this household', 'possible'],
                    $nickname => ['looks like a short form of "' . $user->name . '"', 'nickname'],
                    default => ['first name only, no other signal', 'weak'],
                };

                $out[] = compact('player', 'user', 'why', 'confidence');
            }
        }

        // A player matching more than one person cannot be linked on the name
        // alone. Sometimes that is two unmerged guest rows for one human, and
        // merging them first makes the choice disappear — but "Shay" matching
        // both Shaylene and Shaylee is two different people who happen to share
        // a short form, so the advice cannot assume either.
        $perPlayer = [];
        foreach ($out as $c) {
            $perPlayer[$c['player']->id] = ($perPlayer[$c['player']->id] ?? 0) + 1;
        }

        foreach ($out as &$c) {
            if ($perPlayer[$c['player']->id] > 1) {
                $c['why'] = 'matches ' . $perPlayer[$c['player']->id] . ' accounts — pick one, or merge them if they are the same person';
                $c['confidence'] = 'ambiguous';
            }
        }
        unset($c);

        $rank = ['strong' => 0, 'possible' => 1, 'nickname' => 2, 'ambiguous' => 3, 'weak' => 4];
        usort($out, fn ($a, $b) => [$rank[$a['confidence']], $a['player']->name] <=> [$rank[$b['confidence']], $b['player']->name]);

        return $out;
    }
}

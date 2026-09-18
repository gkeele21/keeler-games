<?php

namespace App\Http\Controllers\Scorekeeper;

use App\Http\Controllers\Controller;
use App\Models\Scorekeeper\Player;
use App\Models\Scorekeeper\ScoredGame;
use App\Models\Scorekeeper\ScoredGameCompetitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompetitorController extends Controller
{
    /**
     * Add a competitor to an in-progress game: a new player column (individual
     * game) or a new team (team game).
     */
    public function store(Request $request, ScoredGame $scoredGame)
    {
        $this->ensureEditable($request, $scoredGame);

        $order = ($scoredGame->competitors()->max('display_order') ?? 0) + 1;

        if ($scoredGame->team_based) {
            $validated = $request->validate(['name' => 'required|string|max:255']);
            $scoredGame->competitors()->create([
                'name'          => $validated['name'],
                'display_order' => $order,
            ]);

            return back()->with('success', 'Team added.');
        }

        $playerId = $this->resolvePlayerId($request, $scoredGame);
        $player = Player::findOrFail($playerId);
        $scoredGame->competitors()
            ->create(['name' => $player->name, 'display_order' => $order])
            ->players()->attach($playerId);

        return back()->with('success', 'Player added.');
    }

    /**
     * Remove a competitor (player column or team). Its rounds/scores cascade.
     */
    public function destroy(Request $request, ScoredGame $scoredGame, ScoredGameCompetitor $competitor)
    {
        $this->ensureEditable($request, $scoredGame);
        abort_unless($competitor->game_id === $scoredGame->id, 404);
        abort_if(
            $scoredGame->competitors()->count() <= 2,
            422,
            'A game needs at least two players or teams.',
        );

        $competitor->delete();

        return back()->with('success', $scoredGame->team_based ? 'Team removed.' : 'Player removed.');
    }

    /**
     * Add a roster player to an existing team.
     */
    public function addMember(Request $request, ScoredGame $scoredGame, ScoredGameCompetitor $competitor)
    {
        $this->ensureEditable($request, $scoredGame);
        abort_unless($scoredGame->team_based, 422, 'This game is not team-based.');
        abort_unless($competitor->game_id === $scoredGame->id, 404);

        $competitor->players()->attach($this->resolvePlayerId($request, $scoredGame));

        return back()->with('success', 'Player added to team.');
    }

    /**
     * Remove a player from a team (keep at least one member — remove the team instead).
     */
    public function removeMember(Request $request, ScoredGame $scoredGame, ScoredGameCompetitor $competitor, Player $player)
    {
        $this->ensureEditable($request, $scoredGame);
        abort_unless($competitor->game_id === $scoredGame->id, 404);
        abort_if(
            $competitor->players()->count() <= 1,
            422,
            'A team needs at least one player — remove the team instead.',
        );

        $competitor->players()->detach($player->id);

        return back()->with('success', 'Player removed from team.');
    }

    /**
     * Reorder the competitor columns. Accepts the full ordered id list.
     */
    public function reorder(Request $request, ScoredGame $scoredGame)
    {
        $this->ensureEditable($request, $scoredGame);

        $validated = $request->validate([
            'competitor_ids'   => 'required|array',
            'competitor_ids.*' => 'integer',
        ]);
        $ids = array_map('intval', $validated['competitor_ids']);

        $gameIds = $scoredGame->competitors()->pluck('id')->all();
        abort_unless(
            count($ids) === count($gameIds) && empty(array_diff($gameIds, $ids)),
            422,
            'Invalid competitor order.',
        );

        DB::transaction(function () use ($ids, $scoredGame) {
            // Two passes: park in a high range first so the unique
            // (scored_game_id, display_order) constraint never collides.
            foreach ($ids as $i => $id) {
                $scoredGame->competitors()->whereKey($id)->update(['display_order' => 1000 + $i]);
            }
            foreach ($ids as $i => $id) {
                $scoredGame->competitors()->whereKey($id)->update(['display_order' => $i + 1]);
            }
        });

        return back()->with('success', 'Order updated.');
    }

    private function ensureEditable(Request $request, ScoredGame $scoredGame): void
    {
        // Roster/team changes are for scorers (owner/member) — guests view
        // and, at most, enter their own scores.
        abort_unless($scoredGame->household->isScorer($request->user()), 403);
        abort_if($scoredGame->is_complete, 403, 'Game is already complete.');
    }

    /**
     * Resolve the player to add: an existing roster player, or a brand-new
     * player entered by name — saved to the roster or kept as a one-off guest.
     */
    private function resolvePlayerId(Request $request, ScoredGame $scoredGame): int
    {
        $validated = $request->validate([
            'player_id'        => 'nullable|integer',
            'new_player_name'  => 'nullable|string|max:255',
            'user_id'          => 'nullable|integer|exists:users,id',
            'add_to_household' => 'boolean',
        ]);

        // New player typed in (or picked from the other-households/friends
        // suggestions, which carry an account link) — guest unless they chose
        // to save to the roster.
        if (! empty($validated['new_player_name'])) {
            $userId = $validated['user_id'] ?? null;

            if ($userId !== null) {
                abort_unless(
                    $request->user()->canLinkRosterAccount($userId),
                    403,
                    'You can only link people from your households or friends.',
                );
                abort_if(
                    $scoredGame->household->players()->where('user_id', $userId)->exists(),
                    422,
                    'That person is already on this roster.',
                );
            }

            return $scoredGame->household->players()->create([
                'name'     => trim($validated['new_player_name']),
                'user_id'  => $userId,
                'is_guest' => ! ($validated['add_to_household'] ?? false),
            ])->id;
        }

        // Existing roster player.
        $playerId = (int) ($validated['player_id'] ?? 0);
        $inRoster = $scoredGame->household->players()
            ->where('is_guest', false)
            ->whereKey($playerId)
            ->exists();
        abort_unless($inRoster, 422, 'Select a player or enter a new name.');

        $participating = $scoredGame->competitors()
            ->with('players:id')
            ->get()
            ->flatMap(fn ($c) => $c->players->pluck('id'))
            ->contains($playerId);
        abort_if($participating, 422, 'That player is already in this game.');

        return $playerId;
    }
}

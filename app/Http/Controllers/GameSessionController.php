<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\GameState;
use App\Models\GameType;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\GameInitializationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GameSessionController extends Controller
{
    public function index(): Response
    {
        $sessions = GameSession::with(['gameType', 'host', 'teams'])
            ->where('host_user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        $gameTypes = GameType::online()->get();

        return Inertia::render('GameSessions/Index', [
            'sessions' => $sessions,
            'gameTypes' => $gameTypes,
        ]);
    }

    /**
     * "Host a Game" — drop straight onto the unified setup screen. Resume the
     * host's existing lobby draft if there is one (so we don't litter abandoned
     * drafts), otherwise bootstrap a fresh one defaulting to America Says. The
     * game itself is chosen/changed on the setup screen.
     */
    public function create()
    {
        $session = GameSession::where('host_user_id', auth()->id())
            ->where('status', 'lobby')
            ->latest()
            ->first();

        if (!$session) {
            $default = GameType::where('slug', 'america-says')->first() ?? GameType::online()->first();
            abort_if(!$default, 404, 'No games available.');
            $session = $this->bootstrapSession($default);
        }

        return redirect()->route('host.lobby', $session);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'game_type_id' => 'required|exists:game_types,id',
            'name' => 'nullable|string|max:255',
            'settings' => 'nullable|array',
        ]);

        $gameType = GameType::findOrFail($validated['game_type_id']);
        $session = $this->bootstrapSession(
            $gameType,
            $validated['name'] ?? null,
            !empty($validated['settings']) ? $validated['settings'] : null,
        );

        return redirect()->route('host.lobby', $session);
    }

    /**
     * Change the game for a draft session in place. Nothing is materialized
     * until the game starts, so this just swaps the type and resets settings to
     * the new game's defaults (teams are game-agnostic and kept).
     */
    public function changeGameType(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }
        if ($gameSession->status !== 'lobby') {
            return back()->withErrors(['game_type' => 'Cannot change the game after it has started.']);
        }

        $validated = $request->validate([
            'game_type_id' => 'required|exists:game_types,id',
        ]);

        $gameType = GameType::findOrFail($validated['game_type_id']);
        $gameSession->update([
            'game_type_id' => $gameType->id,
            'settings' => $gameType->default_config ?? [],
        ]);
        $gameSession->gameState?->update([
            'timer_duration' => $gameSession->getConfig('control_timer_seconds', 30),
        ]);

        return back()->with('success', 'Game changed.');
    }

    /**
     * Create a lobby session (settings defaulted from the game type), its game
     * state, and the auto-created teams. Shared by create() and store().
     */
    protected function bootstrapSession(GameType $gameType, ?string $name = null, ?array $settings = null): GameSession
    {
        $session = GameSession::create([
            'game_type_id' => $gameType->id,
            'host_user_id' => auth()->id(),
            'name' => $name,
            'settings' => $settings ?? ($gameType->default_config ?? []),
            'status' => 'lobby',
        ]);

        GameState::create([
            'game_session_id' => $session->id,
            'round_number' => 1,
            'timer_duration' => $session->getConfig('control_timer_seconds', 30),
        ]);

        // Auto-create teams (Team A, Team B, …) unless individual play.
        if ((int) $session->getConfig('team_size', 0) !== 1) {
            $numTeams = max(1, min(8, (int) $session->getConfig('number_of_teams', 2)));
            foreach (range(0, $numTeams - 1) as $i) {
                Team::create([
                    'game_id' => $session->id,
                    'name' => 'Team ' . chr(65 + $i),
                    'color' => self::TEAM_COLORS[$i % count(self::TEAM_COLORS)],
                    'display_order' => $i + 1,
                ]);
            }
        }

        return $session;
    }

    /**
     * Default team colors — palette values only (danger, info, primary,
     * warning, gold, propoff-blue, propoff-red). Keep in sync with the
     * teamColors array in Host/Lobby.vue.
     */
    private const TEAM_COLORS = [
        '#EF4444', // danger red
        '#3B82F6', // info blue
        '#57D025', // primary / success green
        '#F47612', // warning orange
        '#EAB308', // gold
        '#1A3490', // propoff blue
        '#AF1919', // propoff red
    ];

    public function addTeam(Request $request, GameSession $gameSession)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $order = $gameSession->teams()->max('display_order') ?? 0;

        $team = Team::create([
            'game_id' => $gameSession->id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#3B82F6',
            'display_order' => $order + 1,
        ]);

        return back()->with('success', 'Team added successfully');
    }

    /**
     * Reconcile the number of teams to a target count. Adds Team letters (with
     * palette colors) to reach the count, or trims teams from the end.
     */
    public function setTeamCount(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }
        if ($gameSession->status !== 'lobby') {
            return back()->withErrors(['teams' => 'Cannot change teams after the game has started.']);
        }

        $validated = $request->validate([
            'count' => 'required|integer|min:1|max:8',
        ]);
        $target = $validated['count'];

        $teams = $gameSession->teams()->orderBy('display_order')->get();
        $current = $teams->count();

        if ($target > $current) {
            $order = (int) ($gameSession->teams()->max('display_order') ?? 0);
            for ($i = $current; $i < $target; $i++) {
                Team::create([
                    'game_id' => $gameSession->id,
                    'name' => 'Team ' . chr(65 + $i),
                    'color' => self::TEAM_COLORS[$i % count(self::TEAM_COLORS)],
                    'display_order' => ++$order,
                ]);
            }
        } elseif ($target < $current) {
            $teams->slice($target)->each(fn (Team $t) => $t->delete());
        }

        return back()->with('success', 'Teams updated');
    }

    public function updateTeam(Request $request, GameSession $gameSession, Team $team)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        if ($team->game_id !== $gameSession->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $team->update([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? $team->color,
        ]);

        return back()->with('success', 'Team updated successfully');
    }

    public function removeTeam(GameSession $gameSession, Team $team)
    {
        if ($team->game_id !== $gameSession->id) {
            abort(403);
        }

        $team->delete();

        return back()->with('success', 'Team removed successfully');
    }

    public function reorderTeams(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'team_ids' => 'required|array',
            'team_ids.*' => 'exists:competitors,id',
        ]);

        // Two passes: park in a high range first so a (game, display_order)
        // uniqueness rule is never momentarily violated mid-swap. Matches
        // Scorekeeper's CompetitorController::reorder, and is what lets the
        // unified `competitors` table keep that constraint for both kinds.
        DB::transaction(function () use ($validated, $gameSession) {
            foreach ($validated['team_ids'] as $index => $teamId) {
                Team::where('id', $teamId)
                    ->where('game_id', $gameSession->id)
                    ->update(['display_order' => 1000 + $index]);
            }
            foreach ($validated['team_ids'] as $index => $teamId) {
                Team::where('id', $teamId)
                    ->where('game_id', $gameSession->id)
                    ->update(['display_order' => $index + 1]);
            }
        });

        return back()->with('success', 'Team order updated');
    }

    public function addTeamMember(Request $request, GameSession $gameSession, Team $team)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        if ($team->game_id !== $gameSession->id) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => 'required|in:guest,friend,session_player',
            'guest_name' => 'required_if:type,guest|nullable|string|max:255',
            'user_id' => 'required_if:type,friend|nullable|exists:users,id',
            'session_player_id' => 'required_if:type,session_player|nullable|exists:session_players,id',
        ]);

        if ($validated['type'] === 'guest') {
            TeamMember::create([
                'team_id' => $team->id,
                'guest_name' => $validated['guest_name'],
            ]);
        } elseif ($validated['type'] === 'friend') {
            // Check if user is already on a team in this session
            $existingMember = TeamMember::whereHas('team', function ($query) use ($gameSession) {
                $query->where('game_id', $gameSession->id);
            })->where('user_id', $validated['user_id'])->first();

            if ($existingMember) {
                return back()->withErrors(['user_id' => 'This user is already on a team.']);
            }

            TeamMember::create([
                'team_id' => $team->id,
                'user_id' => $validated['user_id'],
            ]);
        } elseif ($validated['type'] === 'session_player') {
            $sessionPlayer = $gameSession->sessionPlayers()->find($validated['session_player_id']);

            if (!$sessionPlayer) {
                return back()->withErrors(['session_player_id' => 'Player not found in this session.']);
            }

            // Create team member from session player
            TeamMember::create([
                'team_id' => $team->id,
                'user_id' => $sessionPlayer->user_id,
                'guest_name' => $sessionPlayer->guest_name,
            ]);

            // Update session player with team assignment
            $sessionPlayer->update(['team_id' => $team->id]);
        }

        return back()->with('success', 'Team member added successfully');
    }

    public function removeTeamMember(GameSession $gameSession, Team $team, TeamMember $teamMember)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        if ($team->game_id !== $gameSession->id || $teamMember->team_id !== $team->id) {
            abort(403);
        }

        // If this member was from a session player, unassign them from the team
        if ($teamMember->user_id) {
            $gameSession->sessionPlayers()
                ->where('user_id', $teamMember->user_id)
                ->update(['team_id' => null]);
        }

        $teamMember->delete();

        return back()->with('success', 'Team member removed successfully');
    }

    public function updateSettings(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        if ($gameSession->status !== 'lobby') {
            return back()->withErrors(['settings' => 'Cannot update settings after game has started.']);
        }

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'settings' => 'required|array',
        ]);

        // Merge with existing settings
        $currentSettings = $gameSession->settings ?? [];
        $newSettings = array_merge($currentSettings, $validated['settings']);

        $update = ['settings' => $newSettings];
        if ($request->has('name')) {
            $update['name'] = $validated['name'] ?? null;
        }

        $gameSession->update($update);

        return back()->with('success', 'Settings updated successfully');
    }

    /**
     * Return a started game to its setup lobby. Questions/cards are left as-is —
     * they're cleared and rebuilt the next time the host starts — so this just
     * flips the status back to 'lobby' and sends the host to the setup screen.
     */
    public function backToLobby(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        // A finished game is history — never silently un-complete it back into the
        // "Active Now" list. Send the host to the setup screen read-only instead.
        if ($gameSession->status === 'completed') {
            return redirect()->route('host.lobby', $gameSession);
        }

        $gameSession->update(['status' => 'lobby']);

        return redirect()->route('host.lobby', $gameSession);
    }

    /**
     * Resume an already-started game that was parked back in the lobby (via Back to
     * Setup) — flip it live again so the TV display, which gates on 'playing',
     * follows the host back into the board, then hand off to the game screen. A
     * completed game is never revived here (its history stays intact).
     */
    public function resume(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        if ($gameSession->status === 'lobby' && $gameSession->started_at !== null) {
            $gameSession->update(['status' => 'playing']);
        }

        return redirect()->route('host.game', $gameSession);
    }

    public function startGame(GameSession $gameSession, GameInitializationService $initService)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        if ($gameSession->teams()->count() < 1) {
            return back()->withErrors(['teams' => 'At least one team is required to start the game.']);
        }

        // Initialize questions/cards based on game type
        try {
            $initService->initialize($gameSession);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['questions' => $e->getMessage()]);
        }

        $gameSession->update([
            'status' => 'playing',
            'started_at' => now(),
        ]);

        // Fresh start (including a Restart): zero the scoreboard so a replay never
        // carries the previous game's scores — matches the Restart confirmation's
        // "wipes scores and progress" promise.
        $gameSession->teams()->update(['total_score' => 0]);

        // Initialize game state based on game type
        $state = $gameSession->gameState;
        $teams = $gameSession->teams()->orderBy('display_order')->get();

        $state->update([
            'active_team_id' => $teams->first()?->id,
            // Re-read the round timer from the (possibly host-edited) config at
            // start, so setup changes to control_timer_seconds actually take effect
            // — it was previously fixed at session-creation time and never refreshed.
            'timer_duration' => $gameSession->getConfig('control_timer_seconds', 40),
            'state_data' => [
                'team_order' => $teams->pluck('id')->toArray(),
                'team_rotation_index' => 0,
                // America Says guided flow: game opens on the round intro. The first
                // question is loaded but stays hidden until the host hits "Show
                // Question", then "Start Timer" (see HostController phase actions).
                'phase' => 'intro',
            ],
        ]);

        // Snapshot round 1's starting scores (all zero) so Reset Round can restore
        // them exactly, without recomputing from reveals.
        $state->snapshotRoundScoresIfAbsent(1, $teams->mapWithKeys(fn ($t) => [$t->id => 0])->all());

        return redirect()->route('host.game', $gameSession);
    }

    public function destroy(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        // Only allow deletion of games in lobby status
        if ($gameSession->status !== 'lobby') {
            return back()->withErrors(['delete' => 'Cannot delete a game that has already started.']);
        }

        $gameSession->delete();

        return redirect()->route('dashboard')->with('success', 'Game cancelled successfully.');
    }
}

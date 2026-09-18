<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\SessionPlayer;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function join(Request $request): Response
    {
        return Inertia::render('Player/Join', [
            'initialCode' => $request->query('code', ''),
        ]);
    }

    public function joinByCode(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => 'required|string|size:6',
        ]);

        $session = GameSession::where('invite_code', strtoupper($validated['invite_code']))
            ->whereIn('status', ['lobby', 'playing', 'paused'])
            ->first();

        if (!$session) {
            return back()->withErrors(['invite_code' => 'Invalid or expired game code.']);
        }

        return redirect()->route('player.identify', $session);
    }

    public function showIdentify(GameSession $gameSession): Response
    {
        if (!in_array($gameSession->status, ['lobby', 'playing', 'paused'])) {
            abort(404, 'Game not found.');
        }

        return Inertia::render('Player/JoinIdentify', [
            'gameSession' => [
                'id' => $gameSession->id,
                'name' => $gameSession->name,
                'invite_code' => $gameSession->invite_code,
            ],
        ]);
    }

    public function joinSession(Request $request, GameSession $gameSession)
    {
        if (!in_array($gameSession->status, ['lobby', 'playing', 'paused'])) {
            abort(404, 'Game not found.');
        }

        $user = Auth::user();
        $guestName = null;
        $sessionPlayer = null;

        // Check if user/guest already joined this session
        if ($user) {
            $sessionPlayer = SessionPlayer::where('game_session_id', $gameSession->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$sessionPlayer) {
                $sessionPlayer = SessionPlayer::create([
                    'game_session_id' => $gameSession->id,
                    'user_id' => $user->id,
                    'joined_at' => now(),
                ]);
            }
        } else {
            $validated = $request->validate([
                'guest_name' => 'required|string|max:255',
            ]);

            $guestName = $validated['guest_name'];
            $sessionPlayer = SessionPlayer::create([
                'game_session_id' => $gameSession->id,
                'guest_name' => $guestName,
                'joined_at' => now(),
            ]);
        }

        // Store session player ID in session for guest tracking
        $request->session()->put("session_player_{$gameSession->id}", $sessionPlayer->id);

        // Handle team assignment based on settings
        return $this->handleTeamAssignment($gameSession, $sessionPlayer);
    }

    public function joinWithLogin(Request $request, GameSession $gameSession)
    {
        if (!in_array($gameSession->status, ['lobby', 'playing', 'paused'])) {
            abort(404, 'Game not found.');
        }

        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($credentials)) {
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        // Check if user already joined this session
        $sessionPlayer = SessionPlayer::where('game_session_id', $gameSession->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$sessionPlayer) {
            $sessionPlayer = SessionPlayer::create([
                'game_session_id' => $gameSession->id,
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
        }

        // Store session player ID in session for tracking
        $request->session()->put("session_player_{$gameSession->id}", $sessionPlayer->id);

        // Handle team assignment based on settings
        return $this->handleTeamAssignment($gameSession, $sessionPlayer);
    }

    /**
     * Handle team assignment based on game session settings.
     */
    private function handleTeamAssignment(GameSession $gameSession, SessionPlayer $sessionPlayer)
    {
        // If player is already on a team, go straight to the game
        if ($sessionPlayer->team_id) {
            return redirect()->route('player.game', $gameSession);
        }

        $teamSize = $gameSession->getConfig('team_size', 0);
        $allowTeamSelection = $gameSession->getConfig('allow_team_selection', false);

        // Individual play: create a team for this player
        if ($teamSize === 1) {
            $this->createIndividualTeam($gameSession, $sessionPlayer);
            return redirect()->route('player.game', $gameSession);
        }

        // Allow team selection: redirect to team selection page
        if ($allowTeamSelection) {
            return redirect()->route('player.select-team', $gameSession);
        }

        // Default: go to game (host will assign to team)
        return redirect()->route('player.game', $gameSession);
    }

    /**
     * Create an individual team for a player (when team_size = 1).
     */
    private function createIndividualTeam(GameSession $gameSession, SessionPlayer $sessionPlayer): void
    {
        $teamColors = ['#EF4444', '#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316'];

        $playerName = $sessionPlayer->display_name;
        $order = $gameSession->teams()->max('display_order') ?? 0;
        $colorIndex = $order % count($teamColors);

        // Create team for the player
        $team = Team::create([
            'game_id' => $gameSession->id,
            'name' => $playerName,
            'color' => $teamColors[$colorIndex],
            'display_order' => $order + 1,
        ]);

        // Add player as team member
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $sessionPlayer->user_id,
            'guest_name' => $sessionPlayer->guest_name,
        ]);

        // Update session player with team assignment
        $sessionPlayer->update(['team_id' => $team->id]);
    }

    public function showSelectTeam(GameSession $gameSession): Response
    {
        if (!in_array($gameSession->status, ['lobby', 'playing', 'paused'])) {
            abort(404, 'Game not found.');
        }

        $gameSession->load(['teams.members']);

        $teamSize = $gameSession->getConfig('team_size', 0);

        // Get session player (logged in user or guest via session)
        $sessionPlayer = $this->getSessionPlayer($gameSession);
        $playerName = $sessionPlayer?->display_name ?? 'Guest';

        // If no session player, redirect to identify
        if (!$sessionPlayer) {
            return redirect()->route('player.identify', $gameSession);
        }

        return Inertia::render('Player/SelectTeam', [
            'gameSession' => [
                'id' => $gameSession->id,
                'name' => $gameSession->name,
                'invite_code' => $gameSession->invite_code,
            ],
            'teams' => $gameSession->teams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'members' => $team->members->map(fn ($m) => [
                    'id' => $m->id,
                    'display_name' => $m->display_name,
                ]),
                'member_count' => $team->members->count(),
            ]),
            'teamSize' => $teamSize,
            'playerName' => $playerName,
        ]);
    }

    /**
     * Get the current session player for a game session.
     */
    private function getSessionPlayer(GameSession $gameSession): ?SessionPlayer
    {
        $user = Auth::user();

        // For authenticated users, find by user_id
        if ($user) {
            $sessionPlayer = $gameSession->sessionPlayers()
                ->where('user_id', $user->id)
                ->first();

            if ($sessionPlayer) {
                return $sessionPlayer;
            }
        }

        // Fallback: check for player via session storage (works for guests and as backup for auth users)
        $sessionPlayerId = session("session_player_{$gameSession->id}");
        if ($sessionPlayerId) {
            $sessionPlayer = SessionPlayer::where('id', $sessionPlayerId)
                ->where('game_session_id', $gameSession->id)
                ->first();

            if ($sessionPlayer) {
                return $sessionPlayer;
            }
        }

        return null;
    }

    public function joinTeam(Request $request, GameSession $gameSession)
    {
        if (!in_array($gameSession->status, ['lobby', 'playing', 'paused'])) {
            abort(404, 'Game not found.');
        }

        $validated = $request->validate([
            'team_id' => 'required|exists:competitors,id',
        ]);

        $team = Team::find($validated['team_id']);

        // Verify team belongs to this game session
        if ($team->game_id !== $gameSession->id) {
            abort(403, 'Invalid team.');
        }

        // Check team size limit
        $teamSize = $gameSession->getConfig('team_size', 0);
        if ($teamSize > 0 && $team->members()->count() >= $teamSize) {
            return back()->withErrors(['team_id' => 'This team is full.']);
        }

        // Find the session player using the helper method
        $sessionPlayer = $this->getSessionPlayer($gameSession);

        if (!$sessionPlayer) {
            return redirect()->route('player.identify', $gameSession);
        }

        // Add player as team member
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $sessionPlayer->user_id,
            'guest_name' => $sessionPlayer->guest_name,
        ]);

        // Update session player with team assignment
        $sessionPlayer->update(['team_id' => $team->id]);

        return redirect()->route('player.game', $gameSession);
    }

    public function game(GameSession $gameSession): Response
    {
        // Players can view games in lobby, playing, or paused status
        if (!in_array($gameSession->status, ['lobby', 'playing', 'paused', 'completed'])) {
            abort(404, 'Game not found.');
        }

        $gameSession->load([
            'gameType',
            'teams.members',
            'gameState.activeTeam',
            'gameState.currentCard',
        ]);

        // Get the current player's team
        $sessionPlayer = $this->getSessionPlayer($gameSession);
        $playerTeamId = $sessionPlayer?->team_id;

        return Inertia::render('Player/Game', [
            'gameSession' => [
                'id' => $gameSession->id,
                'name' => $gameSession->name,
                'status' => $gameSession->status,
                'invite_code' => $gameSession->invite_code,
                'game_type' => [
                    'name' => $gameSession->gameType->name,
                    'slug' => $gameSession->gameType->slug,
                ],
            ],
            'teams' => $gameSession->teams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'total_score' => $team->total_score,
                'members' => $team->members->map(fn ($m) => [
                    'id' => $m->id,
                    'display_name' => $m->display_name,
                ]),
            ]),
            'playerTeamId' => $playerTeamId,
        ]);
    }

    public function getState(GameSession $gameSession)
    {
        $gameSession->load([
            'teams.members',
            'gameState.currentQuestion.question.answers',
            'gameState.currentQuestion.answerReveals',
            'gameState.currentCard',
            'gameState.activeTeam',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        // Get the current player's team
        $sessionPlayer = $this->getSessionPlayer($gameSession);
        $playerTeamId = $sessionPlayer?->team_id;

        // For players, we hide unrevealed answers
        $answers = [];
        if ($currentQuestion) {
            $revealedIds = $currentQuestion->revealedAnswerIds();
            $answers = $currentQuestion->question->answers->map(function ($answer) use ($revealedIds) {
                $revealed = in_array($answer->id, $revealedIds);
                return [
                    'id' => $answer->id,
                    'answer_text' => $revealed ? $answer->answer_text : $this->obfuscateAnswer($answer->answer_text),
                    'points' => $revealed ? $answer->points : null,
                    'display_order' => $answer->display_order,
                    'revealed' => $revealed,
                ];
            });
        }

        return response()->json([
            'status' => $gameSession->status,
            'playerTeamId' => $playerTeamId,
            'teams' => $gameSession->teams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'total_score' => $team->total_score,
                'members' => $team->members->map(fn ($m) => [
                    'id' => $m->id,
                    'display_name' => $m->display_name,
                ]),
            ]),
            'gameState' => [
                'round_number' => $state?->round_number,
                'active_team_id' => $state?->active_team_id,
                'active_team_name' => $state?->activeTeam?->name,
                'timer_started_at' => $state?->timer_started_at?->toIso8601String(),
                'timer_duration' => $state?->timer_duration,
                'remaining_seconds' => $state?->getRemainingSeconds(),
            ],
            'currentQuestion' => $currentQuestion ? [
                'id' => $currentQuestion->id,
                'question_text' => $currentQuestion->question->question_text,
                'status' => $currentQuestion->status,
                'control_status' => $currentQuestion->control_status,
                'controlling_team_id' => $currentQuestion->controlling_team_id,
                'answers' => $answers,
            ] : null,
            'currentCard' => $state?->currentCard ? [
                'id' => $state->currentCard->id,
                'card_number' => $state->currentCard->card_number,
                'letter' => $state->currentCard->letter,
            ] : null,
        ]);
    }

    /**
     * Obfuscate answer text for unrevealed answers.
     * First letter + ~1.5x underscores per hyphen-part; hyphens are kept and
     * words are separated by spaces (e.g. "SHOW-ME" -> "S____-M_"). The board
     * squishes the underscores into a continuous line, so the exact character
     * count stays hidden while hyphens still read correctly.
     */
    private function obfuscateAnswer(string $text): string
    {
        $words = array_map(function ($word) {
            $parts = array_map(function ($part) {
                if (mb_strlen($part) <= 1) {
                    return $part;
                }
                $firstLetter = mb_substr($part, 0, 1);
                $underscoreCount = (int) floor((mb_strlen($part) - 1) * 1.5);
                return $firstLetter . str_repeat('_', $underscoreCount);
            }, explode('-', $word));

            return implode('-', $parts);
        }, explode(' ', $text));

        return implode(' ', $words);
    }
}

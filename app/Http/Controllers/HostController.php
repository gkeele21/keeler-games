<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsFastMoneyBoard;
use App\Models\GameSession;
use App\Models\GameState;
use App\Models\Question;
use App\Models\SessionQuestion;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HostController extends Controller
{
    use BuildsFastMoneyBoard;

    public function lobby(GameSession $gameSession): Response
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403, 'You are not the host of this game.');
        }

        $gameSession->load(['gameType', 'teams.members.user', 'gameState', 'sessionPlayers.user']);

        // Get user's friends
        $friends = auth()->user()->friends()->get()->map(fn ($friend) => [
            'id' => $friend->id,
            'name' => $friend->name,
            'first_name' => $friend->first_name,
            'nickname' => $friend->pivot->nickname,
        ]);

        // Get players who joined via invite code but aren't on a team yet
        $waitingPlayers = $gameSession->unassignedPlayers()->with('user')->get();

        // Merge default config with session-specific settings
        $config = array_merge(
            $gameSession->gameType->default_config ?? [],
            $gameSession->settings ?? []
        );

        return Inertia::render('Host/Lobby', [
            'gameSession' => $gameSession,
            'config' => $config,
            'friends' => $friends,
            'waitingPlayers' => $waitingPlayers,
            'gameTypes' => \App\Models\GameType::online()->get(['id', 'name', 'slug']),
            'questionData' => $this->questionSelectionData($gameSession),
            'attendanceData' => $this->attendanceData($gameSession),
            // Whether this game has already been started (and thus has live
            // progress that a fresh Start would wipe). Drives the lobby's
            // "Resume vs. Restart" split button.
            'wasStarted' => $gameSession->started_at !== null,
        ]);
    }

    /**
     * Data backing the "Who's playing?" setup card: the host's household rosters,
     * who's currently marked present for this game, and — the whole point — which
     * questions each roster player has already been served in past completed
     * games. The picker uses that last map to flag/avoid repeats for tonight's
     * group. Only built for the bank-backed games (America Says / Family Feud).
     */
    protected function attendanceData(GameSession $gameSession): ?array
    {
        if (!in_array($gameSession->gameType->slug, ['america-says', 'family-feud'], true)) {
            return null;
        }

        $user = auth()->user();
        $households = $user->households()
            ->with(['players' => fn ($q) => $q->orderBy('name')])
            ->get();

        $present = $gameSession->players()->pluck('players.id');

        if ($households->isEmpty()) {
            return ['households' => [], 'present' => $present->values(), 'seenByPlayer' => (object) [], 'defaultHouseholdId' => null];
        }

        $rosterIds = $households->flatMap->players->pluck('id');

        // player_id => [question_id, ...] seen across completed games they attended
        // (excluding this session, which isn't played yet).
        $seenByPlayer = \DB::table('game_session_players as gsp')
            ->join('games as gs', 'gs.id', '=', 'gsp.game_session_id')
            ->join('session_questions as sq', 'sq.game_session_id', '=', 'gsp.game_session_id')
            ->where('gs.status', 'completed')
            ->where('gs.id', '!=', $gameSession->id)
            ->whereIn('gsp.player_id', $rosterIds)
            ->select('gsp.player_id', 'sq.question_id')
            ->distinct()
            ->get()
            ->groupBy('player_id')
            ->map(fn ($rows) => $rows->pluck('question_id')->values());

        // Default the picker to the household that already has attendees, else
        // the user's remembered household, else their first.
        $default = $present->isNotEmpty()
            ? $households->first(fn ($h) => $h->players->pluck('id')->intersect($present)->isNotEmpty())?->id
            : null;
        $default ??= $households->contains('id', $user->last_household_id)
            ? $user->last_household_id
            : $households->first()->id;

        return [
            'households' => $households->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'players' => $h->players->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            ])->values(),
            'present' => $present->values(),
            'seenByPlayer' => $seenByPlayer->isEmpty() ? (object) [] : $seenByPlayer,
            'defaultHouseholdId' => $default,
        ];
    }

    /**
     * Data backing the "Questions" setup card: the active question bank plus
     * per-category / per-difficulty counts so the host can filter (Random) or
     * hand-pick. Only built for games that pull from the shared bank.
     */
    protected function questionSelectionData(GameSession $gameSession): ?array
    {
        // The per-slot picker backs America Says and Family Feud. Oodles stays
        // random (no picker), so it gets no bank.
        if (!in_array($gameSession->gameType->slug, ['america-says', 'family-feud'], true)) {
            return null;
        }

        // One bank of every active question (Standard + Final). The picker
        // filters it client-side by source / round type; final slots pull the
        // Final questions matching their answer count.
        $bank = \App\Models\Question::where('game_type_id', $gameSession->game_type_id)
            ->where('is_active', true)
            ->withCount('answers')
            ->with('category:id,name')
            ->orderBy('question_text')
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'round_type' => $q->round_type,
                'is_official' => $q->is_official,
                'answers_count' => $q->answers_count,
                'category' => $q->category?->name,
                'category_id' => $q->category_id,
                'difficulty' => $q->difficulty,
                // How many completed games this question has appeared in — surfaced
                // in the picker and used to prefer least-used questions.
                'times_used' => (int) $q->times_used,
            ]);

        return ['bank' => $bank];
    }

    public function game(GameSession $gameSession): Response
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403, 'You are not the host of this game.');
        }

        $gameSession->load([
            'gameType',
            'teams.members',
            'gameState.currentQuestion.question.answers',
            'gameState.currentCard',
            'gameState.activeTeam',
            'sessionQuestions.question.answers',
            'sessionCards',
        ]);

        return Inertia::render('Host/Game', [
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
            'config' => array_merge(
                $gameSession->gameType->default_config ?? [],
                $gameSession->settings ?? []
            ),
        ]);
    }

    public function getState(GameSession $gameSession)
    {
        $gameSession->load([
            'teams',
            'gameState.currentQuestion.question.answers',
            'gameState.currentQuestion.answerReveals',
            'gameState.currentCard.sessionQuestions.question',
            'gameState.currentCard.bonusQuestion.answers',
            'gameState.activeTeam',
            'sessionCards',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;
        $currentCard = $state?->currentCard;

        // Question progress for America Says / Family Feud — counted within the
        // current round (regular play) or the whole final / fast-money segment,
        // so it reads "Question 1 of 2" per round rather than lumping the whole
        // game together next to the "Round 1" label. Also flag whether this is
        // the last question of the session (used to reveal "End Game").
        $currentQuestionNumber = null;
        $totalQuestions = null;
        $isLastQuestion = false;
        if (in_array($gameSession->gameType->slug, ['america-says', 'family-feud'], true)) {
            $allQuestions = $gameSession->sessionQuestions()->orderBy('display_order')->get();
            if ($currentQuestion) {
                $segment = $currentQuestion->segment ?? 'main';
                $group = $segment === 'main'
                    ? $allQuestions->filter(fn ($q) => ($q->segment ?? 'main') === 'main' && $q->round_number === $currentQuestion->round_number)->values()
                    : $allQuestions->filter(fn ($q) => ($q->segment ?? 'main') === $segment)->values();
                $totalQuestions = $group->count();
                $pos = $group->search(fn ($q) => $q->id === $currentQuestion->id);
                $currentQuestionNumber = $pos === false ? null : $pos + 1;
                $isLastQuestion = !$allQuestions
                    ->contains(fn ($q) => $q->display_order > $currentQuestion->display_order);
            } else {
                $totalQuestions = $allQuestions->count();
            }
        }

        // Family Feud regular play is score-driven: the first team to the target
        // (300) ends it and goes to Fast Money. These drive the recap's advance
        // button — "Start Fast Money" once a team's there, else "Next Round".
        $feudTargetReached = false;
        $feudFastMoneyReady = false;
        if ($gameSession->gameType->slug === 'family-feud') {
            $target = (int) $gameSession->getConfig('target_score', 300);
            $feudTargetReached = (int) ($gameSession->teams()->max('total_score') ?? 0) >= $target;
            $feudFastMoneyReady = $gameSession->sessionQuestions()
                ->where('segment', 'fast_money')->exists();
        }

        // Whether the *next* question crosses into an America Says final round —
        // i.e. no regular questions remain but a final one is still to play. Used
        // to label the last regular round's advance button "Start Final Round".
        $finalQueued = false;
        if (in_array($gameSession->gameType->slug, ['america-says', 'family-feud'], true)
            && ($currentQuestion?->segment ?? 'main') !== 'final') {
            $pending = $gameSession->sessionQuestions()->where('status', 'pending');
            $finalQueued = (clone $pending)->where('segment', 'final')->exists()
                && !(clone $pending)->where('segment', '!=', 'final')->exists();
        }

        // The America Says final questions (F1..F4) with per-question progress —
        // backs the host's final navigator/steps and the reveal-the-misses flow.
        $finalQuestions = [];
        if ($gameSession->gameType->slug === 'america-says') {
            $finalQuestions = $gameSession->sessionQuestions()
                ->where('segment', 'final')
                ->with(['question.answers', 'answerReveals'])
                ->orderBy('display_order')
                ->get()
                ->map(fn ($sq) => [
                    'id' => $sq->id,
                    'question_text' => $sq->question->question_text,
                    'answers_needed' => $sq->answers_needed ?? $sq->question->answers->count(),
                    'status' => $sq->status,
                    'total_answers' => $sq->question->answers->count(),
                    'revealed_count' => $sq->answerReveals->count(),
                    'is_current' => $state?->current_question_id === $sq->id,
                ]);
        }

        // Get questions for the current card (Oodles)
        $cardQuestions = [];
        if ($currentCard) {
            $cardQuestions = $currentCard->sessionQuestions->map(fn ($sq) => [
                'id' => $sq->id,
                'question_text' => $sq->question->question_text,
                'display_order' => $sq->display_order,
                'status' => $sq->status,
                'is_current' => $state->current_question_id === $sq->id,
            ]);
        }

        return response()->json([
            'status' => $gameSession->status,
            'teams' => $gameSession->teams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'total_score' => $team->total_score,
            ]),
            'gameState' => [
                'round_number' => $state?->round_number,
                'active_team_id' => $state?->active_team_id,
                'timer_started_at' => $state?->timer_started_at?->toIso8601String(),
                'timer_duration' => $state?->timer_duration,
                'remaining_seconds' => $state?->getRemainingSeconds(),
                'state_data' => $state?->state_data,
            ],
            'currentQuestion' => $currentQuestion ? [
                'id' => $currentQuestion->id,
                'question_text' => $currentQuestion->question->question_text,
                'status' => $currentQuestion->status,
                'round_number' => $currentQuestion->round_number,
                'segment' => $currentQuestion->segment,
                'points_available' => (int) $currentQuestion->points_available,
                'control_status' => $currentQuestion->control_status,
                'controlling_team_id' => $currentQuestion->controlling_team_id,
                'controlling_team_ids' => $currentQuestion->getControllingTeamIdsArray(),
                'bonus_points' => (int) $currentQuestion->bonus_points,
                'bonus_awarded_team_id' => data_get($state?->getStateValue("bonus_q{$currentQuestion->id}"), 'team_id'),
                'answers' => $currentQuestion->question->answers->map(fn ($answer) => [
                    'id' => $answer->id,
                    'answer_text' => $answer->answer_text,
                    'points' => $answer->points,
                    'display_order' => $answer->display_order,
                    'revealed' => $currentQuestion->answerReveals->contains('answer_id', $answer->id),
                    // What this reveal contributes to the Feud pool. Equals the survey
                    // points for face-off/primary reveals, but 0 for a steal reveal
                    // (the stealer wins only the banked pool, not the steal answer).
                    'pool_points' => (int) ($currentQuestion->answerReveals->firstWhere('answer_id', $answer->id)?->points_awarded ?? 0),
                ]),
                'revealed_answer_ids' => $currentQuestion->revealedAnswerIds(),
            ] : null,
            'currentCard' => $currentCard ? [
                'id' => $currentCard->id,
                'card_number' => $currentCard->card_number,
                'letter' => $currentCard->letter,
                'status' => $currentCard->status,
                'questions' => $cardQuestions,
                // "Just for fun" opener — no points, no control.
                'bonus_question' => $currentCard->bonusQuestion ? [
                    'question_text' => $currentCard->bonusQuestion->question_text,
                    'answer_text' => $currentCard->bonusQuestion->answers->first()?->answer_text,
                ] : null,
            ] : null,
            'totalCards' => $gameSession->sessionCards->count(),
            'currentQuestionNumber' => $currentQuestionNumber,
            'totalQuestions' => $totalQuestions,
            'isLastQuestion' => $isLastQuestion,
            'finalQueued' => $finalQueued,
            'feudTargetReached' => $feudTargetReached,
            'feudFastMoneyReady' => $feudFastMoneyReady,
            'finalQuestions' => $finalQuestions,
            // Family Feud Fast Money board (rows + totals + the survey answers the
            // host reveals from). Null unless Fast Money is active.
            'fastMoney' => $this->fastMoneyPayload($gameSession, $state, true),
        ]);
    }

    public function startTimer(GameSession $gameSession)
    {
        $state = $gameSession->gameState;
        // Start ~1s in the future so the board has a beat to reach the (cast) TV
        // before the clock ticks — otherwise casting latency eats the first few
        // seconds and the timer already reads low by the time it's on screen.
        $state->update([
            'timer_started_at' => now()->addSecond(),
        ]);

        return response()->json(['success' => true]);
    }

    public function pauseTimer(GameSession $gameSession)
    {
        $state = $gameSession->gameState;
        $remaining = $state->getRemainingSeconds();

        $state->update([
            'timer_started_at' => null,
            'timer_duration' => $remaining,
        ]);

        return response()->json(['success' => true, 'remaining' => $remaining]);
    }

    public function resetTimer(GameSession $gameSession)
    {
        $state = $gameSession->gameState;
        $defaultDuration = $gameSession->getConfig('control_timer_seconds', 30);

        $state->update([
            'timer_started_at' => null,
            'timer_duration' => $defaultDuration,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Reset the current question's board: un-reveal every answer, reverse the
     * points each reveal awarded, and reverse a manually-awarded sweep bonus,
     * leaving scores as if the question had never been played.
     */
    public function resetBoard(GameSession $gameSession)
    {
        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['success' => true]);
        }

        // Reverse the points from every reveal, then remove the reveals.
        $reveals = $currentQuestion->answerReveals()->get();
        foreach ($reveals->whereNotNull('team_id')->groupBy('team_id') as $teamId => $teamReveals) {
            $team = Team::find($teamId);
            if (!$team) {
                continue;
            }
            $team->update([
                'total_score' => max(0, $team->total_score - (int) $teamReveals->sum('points_awarded')),
            ]);
        }
        $currentQuestion->answerReveals()->delete();

        // Reverse a manually-awarded sweep bonus for this question, if any.
        $bonusKey = "bonus_q{$currentQuestion->id}";
        $bonus = $state->getStateValue($bonusKey, null);
        if (is_array($bonus) && !empty($bonus['team_id'])) {
            $bonusTeam = Team::find($bonus['team_id']);
            if ($bonusTeam) {
                $bonusTeam->update([
                    'total_score' => max(0, $bonusTeam->total_score - (int) ($bonus['amount'] ?? 0)),
                ]);
            }
            $state->setStateValue($bonusKey, null);
        }

        // Reverse a Feud pool award for this question and clear its strikes /
        // face-off so the board replays clean.
        $this->reverseFeudPool($state, "feud_pool_q{$currentQuestion->id}");
        $state->setStateValue('strikes', 0);
        $state->setStateValue('faceoff', null);

        return response()->json(['success' => true]);
    }

    /**
     * Reset the entire current round: un-reveal every answer and reverse the
     * points (and any sweep bonus) for every question in the round, set them all
     * back to pending, and reactivate the round's first question — restoring the
     * scoreboard to exactly where it stood at the end of the previous round.
     *
     * A "round" is every session question sharing the current question's
     * round_number for regular play, or the whole final / fast-money segment.
     */
    public function resetRound(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['success' => true]);
        }

        $segment = $currentQuestion->segment ?? 'main';
        $query = $gameSession->sessionQuestions();
        if (in_array($segment, ['final', 'fast_money', 'tiebreaker'], true)) {
            // Scope by segment, not round_number — final/tiebreaker questions have
            // no round_number, so a round_number match would sweep them all up.
            $query->where('segment', $segment);
        } else {
            $query->where('round_number', $currentQuestion->round_number);
        }
        $roundQuestions = $query->orderBy('display_order')->get()->values();

        // Regular rounds: rebuild who's primary per board from the rotation (the
        // same formula as init) so a reset restores the original "who's up",
        // undoing any steal hand-off or manual control change during play.
        $isRegular = !in_array($segment, ['final', 'fast_money', 'tiebreaker'], true);
        $teamsOrdered = $gameSession->teams()->orderBy('display_order')->orderBy('id')->get();
        $teamCount = max(1, $teamsOrdered->count());
        $roundIndex = ($currentQuestion->round_number ?? 1) - 1;

        // Prefer the round-start snapshot: restore the exact scores from before the
        // round, regardless of reveals, auto-sweeps, or hand-edits during it. Only
        // when there's no snapshot (older sessions) do we fall back to reversing
        // each reveal's points.
        $snapshot = $isRegular ? $state->roundStartScores((int) ($currentQuestion->round_number ?? 1)) : null;

        foreach ($roundQuestions as $slot => $sq) {
            if (!$snapshot) {
                // Fallback: reverse the points from every reveal on this question.
                $reveals = $sq->answerReveals()->get();
                foreach ($reveals->whereNotNull('team_id')->groupBy('team_id') as $teamId => $teamReveals) {
                    $team = Team::find($teamId);
                    if (!$team) {
                        continue;
                    }
                    $team->update([
                        'total_score' => max(0, $team->total_score - (int) $teamReveals->sum('points_awarded')),
                    ]);
                }
            }
            $sq->answerReveals()->delete();

            // Reverse a sweep bonus (manual or auto-awarded); when restoring from a
            // snapshot the points are already covered, but always clear the key so
            // the bonus can be re-earned on replay.
            $bonusKey = "bonus_q{$sq->id}";
            $bonus = $state->getStateValue($bonusKey, null);
            if (!$snapshot && is_array($bonus) && !empty($bonus['team_id'])) {
                $bonusTeam = Team::find($bonus['team_id']);
                if ($bonusTeam) {
                    $bonusTeam->update([
                        'total_score' => max(0, $bonusTeam->total_score - (int) ($bonus['amount'] ?? 0)),
                    ]);
                }
            }
            $state->setStateValue($bonusKey, null);

            // Reverse a Feud pool award for this question. With a snapshot the score
            // is already restored below, but always clear the key so it re-awards.
            if (!$snapshot) {
                $this->reverseFeudPool($state, "feud_pool_q{$sq->id}");
            } else {
                $state->setStateValue("feud_pool_q{$sq->id}", null);
            }

            $update = ['status' => 'pending'];
            if ($isRegular) {
                // Restore the rotation primary and forget the stashed pre-steal primary.
                $primary = $teamsOrdered[($slot + $roundIndex) % $teamCount] ?? $teamsOrdered->first();
                $update['controlling_team_id'] = $primary?->id;
                $update['controlling_team_ids'] = null;
                $update['control_status'] = 'team_control';
                $state->setStateValue("primary_q{$sq->id}", null);
            }
            $sq->update($update);
        }

        // Snapshot restore: set each team's score straight back to the round start.
        if ($snapshot) {
            foreach ($teamsOrdered as $team) {
                if (array_key_exists($team->id, $snapshot)) {
                    $team->update(['total_score' => (int) $snapshot[$team->id]]);
                }
            }
        }

        // Reactivate the round's first question so the round replays from the top.
        $first = $roundQuestions->first();
        if ($first) {
            $first->update(['status' => 'active']);
            $state->update([
                'current_question_id' => $first->id,
                'round_number' => $first->round_number ?? $state->round_number,
                'active_team_id' => $first->controlling_team_id ?? $state->active_team_id,
            ]);

            if ($segment === 'final') {
                // Replay the whole final from its intro, clearing the skip/result
                // and restoring the full time budget.
                $state->update(['timer_started_at' => null, 'timer_duration' => (int) $gameSession->getConfig('final_round_seconds', 60)]);
                $stateData = $state->state_data ?? [];
                $stateData['phase'] = 'final_intro';
                $stateData['final_skip_used'] = false;
                $stateData['final_skipped_question_id'] = null;
                $stateData['final_result'] = null;
                $state->update(['state_data' => $stateData]);
            } elseif ($segment === 'tiebreaker') {
                // Replay the tie-off from its intro; keep the tied teams so the
                // host can re-run and re-declare the winner.
                $state->setStateValue('phase', 'tiebreaker_intro');
            } elseif ($segment === 'fast_money') {
                // Replay Fast Money from the top: clear both passes' answers, the
                // result, and the bring-out flags, park the clock, and land on the
                // intro (Start Player 1).
                $fm = $state->getStateValue('fast_money');
                if (is_array($fm)) {
                    $fm['answers'] = [];
                    $fm['active_player'] = 1;
                    $fm['result'] = null;
                    $fm['show_previous'] = false;
                    $state->setStateValue('fast_money', $fm);
                }
                $state->update(['timer_started_at' => null]);
                $state->setStateValue('phase', 'fast_money_intro');
                // All five questions stay in play together.
                $gameSession->sessionQuestions()->where('segment', 'fast_money')->update(['status' => 'active']);
            } else {
                // Replay from the round intro (question hidden until "Show Question").
                $state->setStateValue('phase', 'intro');
                $state->setStateValue('strikes', 0);
                $state->setStateValue('faceoff', null);
                // Each round arms its face-off fresh (host clicks "Start Face-Off").
                $state->setStateValue('faceoff_armed', false);
            }
        }

        return response()->json(['success' => true]);
    }

    public function revealAnswer(Request $request, GameSession $gameSession)
    {
        $validated = $request->validate([
            'answer_id' => 'required|exists:answers,id',
            'team_id' => 'nullable|exists:competitors,id',
            // When false, reveal the answer on the board but award no points and
            // attach it to no team — used to show answers that were never guessed
            // once a round is over.
            'award_points' => 'nullable|boolean',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        // The final round is pass/fail — reveals award no points and drive the
        // guided final flow (auto-pause + advance when a question is cleared).
        if (($currentQuestion->segment ?? 'main') === 'final') {
            return $this->revealFinalAnswer($state, $currentQuestion, $validated['answer_id']);
        }

        if (($currentQuestion->segment ?? 'main') === 'tiebreaker') {
            return $this->revealTiebreakerAnswer($state, $currentQuestion, $validated['answer_id']);
        }

        // Check if already revealed
        if ($currentQuestion->answerReveals()->where('answer_id', $validated['answer_id'])->exists()) {
            return response()->json(['error' => 'Answer already revealed'], 400);
        }

        $answer = $currentQuestion->question->answers()->find($validated['answer_id']);

        // The answer must belong to the question on screen. Without this, a stale
        // or mismatched answer_id writes an AnswerReveal row and only then hits a
        // null $answer further down — leaving an orphan reveal behind the 500.
        // revealFinalAnswer and the tiebreaker path already guard this way.
        if (!$answer) {
            return response()->json(['error' => 'Invalid answer'], 400);
        }

        // Family Feud regular round: reveals accrue the survey points into the
        // round POOL — no team is scored here. The pool (sum of these reveals) is
        // awarded in full × the round multiplier once, at feudResolve. We record
        // team_id = null so the board/round resets (which reverse per-team reveals)
        // leave these alone; the pool award is tracked/reversed on its own key.
        if ($gameSession->gameType->slug === 'family-feud'
            && ($currentQuestion->segment ?? 'main') === 'main') {
            $phase = $state->getStateValue('phase');

            // A steal reveal — the stealing team's single correct guess — lights up
            // on the board but its survey points do NOT join the pool: the stealer
            // wins only what the primary team banked (real-Feud rule). We store
            // points_awarded = 0 so feudPointPool (and the host's running total)
            // exclude it. Face-off + primary play accrue their points as normal.
            $stealReveal = in_array($phase, ['steal', 'reveal'], true);

            $currentQuestion->answerReveals()->create([
                'answer_id' => $validated['answer_id'],
                'team_id' => null,
                'revealed_at' => now(),
                'points_awarded' => $stealReveal ? 0 : (int) ($answer->points ?? 0),
            ]);
            $answer->recordReveal();

            // Face-off: this reveal is the current team's face-off answer — record
            // it and let the flow decide who plays (top answer / higher points).
            if ($phase === 'faceoff') {
                $fo = $state->getStateValue('faceoff');
                $turn = (int) ($fo['turn'] ?? $fo['buzzed'] ?? 0);
                if ($turn) {
                    $this->feudFaceoffAfterAnswer(
                        $gameSession, $state, $currentQuestion,
                        $turn, (int) ($answer->points ?? 0), (int) $answer->display_order === 1
                    );
                }
            }

            // Steal: the stealing team's ONE guess. Revealing a correct answer during
            // the 'steal' phase is a successful steal → they win the banked pool (this
            // answer contributed 0, per above), which flips us to the 'reveal' beat. A
            // miss resolves via the Strike button instead. Reveals during 'reveal' are
            // just the host putting up the leftovers (no points, no re-resolution).
            $stealResolved = false;
            if ($phase === 'steal') {
                $this->feudResolvePool($gameSession, $state, $currentQuestion, 'steal_success');
                $stealResolved = true;
            }

            return response()->json([
                'success' => true,
                'points' => (int) ($answer->points ?? 0),
                'pool' => $this->feudPointPool($currentQuestion),
                'steal_resolved' => $stealResolved ? 'success' : null,
            ]);
        }

        // Reveal-only (no points): show the answer but credit no team. The host
        // uses this to reveal answers neither team ever said.
        $awardPoints = $validated['award_points'] ?? true;
        if (!$awardPoints) {
            $currentQuestion->answerReveals()->create([
                'answer_id' => $validated['answer_id'],
                'team_id' => null,
                'revealed_at' => now(),
                'points_awarded' => 0,
            ]);
            $answer->recordReveal();

            return response()->json(['success' => true, 'points' => 0]);
        }

        // Per-answer value comes from the round (points_available). Fall back to
        // the stored answer points for older sessions initialized before rounds.
        $points = $currentQuestion->points_available > 0
            ? $currentQuestion->points_available
            : ($answer->points ?? 0);

        // The team in control earns the points for a revealed answer.
        $teamId = $validated['team_id'] ?? $currentQuestion->controlling_team_id ?? $state->active_team_id;

        // Create the reveal
        $currentQuestion->answerReveals()->create([
            'answer_id' => $validated['answer_id'],
            'team_id' => $teamId,
            'revealed_at' => now(),
            'points_awarded' => $points,
        ]);

        // Track answer statistics
        $answer->recordReveal();

        // Award points to team
        $team = $teamId ? Team::find($teamId) : null;
        if ($team) {
            $team->addScore($points);
        }

        // America Says regular round: if the PRIMARY sweeps the whole board during
        // their own timer (phase "question"), bank the round's sweep bonus for them.
        // The host screen handles the 2s "see the last answer" beat and the jump to
        // the scoreboard; a board cleared during the steal earns no bonus.
        $this->maybeAwardSweepBonus($state, $currentQuestion, $teamId);

        return response()->json([
            'success' => true,
            'points' => $points,
        ]);
    }

    /**
     * Award the round's sweep bonus when the primary team clears the entire board
     * during their own turn (phase "question"). Reuses the manual bonus's state key
     * so Reset Round still reverses it. No phase/board changes — the host screen
     * drives the delayed jump to the scoreboard.
     */
    protected function maybeAwardSweepBonus(GameState $state, SessionQuestion $question, ?int $teamId): void
    {
        if (($question->segment ?? 'main') !== 'main' || !$teamId) {
            return;
        }
        if ((int) $question->bonus_points <= 0) {
            return;
        }
        if ($state->getStateValue('phase', 'question') !== 'question') {
            return; // Only a primary-turn sweep earns the bonus.
        }

        $answerCount = $question->question->answers()->count();
        if ($answerCount === 0 || $question->answerReveals()->count() < $answerCount) {
            return; // Board not fully revealed yet.
        }

        $bonusKey = "bonus_q{$question->id}";
        if ($state->getStateValue($bonusKey, null) !== null) {
            return; // Already awarded.
        }

        $team = Team::find($teamId);
        if ($team) {
            $team->addScore((int) $question->bonus_points);
            $state->setStateValue($bonusKey, ['team_id' => $team->id, 'amount' => (int) $question->bonus_points]);
        }
    }

    /**
     * Undo a revealed answer: remove the reveal and reverse the points it
     * awarded to its team.
     */
    public function unrevealAnswer(Request $request, GameSession $gameSession)
    {
        $validated = $request->validate([
            'answer_id' => 'required|exists:answers,id',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        $reveal = $currentQuestion->answerReveals()->where('answer_id', $validated['answer_id'])->first();
        if (!$reveal) {
            return response()->json(['error' => 'Answer is not revealed'], 400);
        }

        $team = $reveal->team_id ? Team::find($reveal->team_id) : null;
        if ($team) {
            $team->update(['total_score' => max(0, $team->total_score - (int) $reveal->points_awarded)]);
        }

        $reveal->delete();

        // Un-revealing an answer on a question that was marked complete (the final
        // round marks a question done once every answer is shown) drops it back
        // below "all revealed", so it's no longer complete — clear the status so
        // its checkmark goes away.
        if ($currentQuestion->status === 'completed'
            && $currentQuestion->answerReveals()->count() < $currentQuestion->question->answers()->count()) {
            $currentQuestion->update(['status' => 'active']);
        }

        // America Says regular round: taking an answer back off while on the
        // scoreboard (the board auto-advances there once every answer is up) means
        // the host mis-scored it — drop back to the board state we came from (the
        // steal, or the primary's turn) so they can re-reveal it correctly (e.g. in
        // Reveal only). The re-reveal auto-advances again.
        if (($currentQuestion->segment ?? 'main') === 'main'
            && $state->getStateValue('phase') === 'recap') {
            $restore = $state->getStateValue('phase_before_recap', 'question');
            $state->setStateValue('phase', in_array($restore, ['question', 'steal', 'reveal'], true) ? $restore : 'question');
        }

        return response()->json(['success' => true]);
    }

    /**
     * Set which team currently has control of the question. Driven by clicking
     * a team on the host scoreboard; this is what used to be "start steal" — it
     * simply hands the turn to another team, with no timer or point changes.
     */
    public function setControllingTeam(Request $request, GameSession $gameSession)
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:competitors,id',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        if (!$gameSession->teams()->whereKey($validated['team_id'])->exists()) {
            return response()->json(['error' => 'Invalid team'], 400);
        }

        $currentQuestion->update([
            'controlling_team_id' => $validated['team_id'],
            'controlling_team_ids' => null,
            'control_status' => 'team_control',
        ]);
        $state->update(['active_team_id' => $validated['team_id']]);

        return response()->json(['success' => true]);
    }

    /**
     * Award the current question's sweep bonus to a team (manual, admin-driven).
     * Recorded per-question in state_data so a Reset Round can reverse it.
     */
    public function awardBonus(Request $request, GameSession $gameSession)
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:competitors,id',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        $bonus = (int) $currentQuestion->bonus_points;
        if ($bonus <= 0) {
            return response()->json(['error' => 'This question has no bonus'], 400);
        }

        $key = "bonus_q{$currentQuestion->id}";
        if ($state->getStateValue($key, null) !== null) {
            return response()->json(['error' => 'Bonus already awarded for this question'], 400);
        }

        $team = Team::find($validated['team_id']);
        if (!$team) {
            return response()->json(['error' => 'Invalid team'], 400);
        }

        $team->addScore($bonus);
        $state->setStateValue($key, ['team_id' => $team->id, 'amount' => $bonus]);

        return response()->json(['success' => true, 'bonus' => $bonus]);
    }

    public function endGame(GameSession $gameSession)
    {
        // Round snapshots exist only to power Reset Round mid-game; drop them now.
        $gameSession->gameState?->clearRoundScores();
        $gameSession->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Record who was present for this game (household roster players). Works at
     * any status so attendance can be fixed after the fact, not only at setup.
     */
    public function setAttendance(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'player_ids' => 'present|array',
            'player_ids.*' => 'integer|exists:players,id',
        ]);

        $gameSession->players()->sync($validated['player_ids']);

        return response()->json(['success' => true]);
    }

    public function setControllingTeams(Request $request, GameSession $gameSession)
    {
        $validated = $request->validate([
            'team_ids' => 'required|array|min:1',
            'team_ids.*' => 'exists:competitors,id',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        // Verify all teams belong to this game session
        $validTeamIds = $gameSession->teams()->pluck('id')->toArray();
        foreach ($validated['team_ids'] as $teamId) {
            if (!in_array($teamId, $validTeamIds)) {
                return response()->json(['error' => 'Invalid team'], 400);
            }
        }

        $currentQuestion->setControllingTeams($validated['team_ids']);

        return response()->json([
            'success' => true,
            'controlling_team_ids' => $currentQuestion->getControllingTeamIdsArray(),
        ]);
    }

    public function selectQuestion(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'session_question_id' => 'required|exists:session_questions,id',
        ]);

        // Verify the question belongs to this game session
        $sessionQuestion = $gameSession->sessionQuestions()
            ->where('id', $validated['session_question_id'])
            ->first();

        if (!$sessionQuestion) {
            return response()->json(['error' => 'Question not found in this game'], 404);
        }

        // Get fresh game state to ensure we have active_team_id
        $gameSession->load('gameState');
        $state = $gameSession->gameState;

        // Update the game state to set this as the current question
        $state->update([
            'current_question_id' => $sessionQuestion->id,
        ]);

        // Check if there are multiple controlling teams from a previous All Play tie
        $stateData = $state->state_data ?? [];
        $nextControllingTeamIds = $stateData['next_controlling_team_ids'] ?? null;

        if ($nextControllingTeamIds && count($nextControllingTeamIds) > 1) {
            // Multiple teams have control (All Play tie situation)
            $sessionQuestion->update([
                'status' => 'active',
                'controlling_team_id' => null,
                'controlling_team_ids' => $nextControllingTeamIds,
                'control_status' => 'team_control',
            ]);

            // Clear the next_controlling_team_ids from state
            unset($stateData['next_controlling_team_ids']);
            $state->update(['state_data' => $stateData]);
        } else {
            // Single team has control (normal case)
            $controllingTeamId = $state->active_team_id;
            if (!$controllingTeamId) {
                $firstTeam = $gameSession->teams()->orderBy('display_order')->first();
                $controllingTeamId = $firstTeam?->id;

                // Also set it on the state for future use
                if ($controllingTeamId) {
                    $state->update(['active_team_id' => $controllingTeamId]);
                }
            }

            // Clear any leftover next_controlling_team_ids
            if (isset($stateData['next_controlling_team_ids'])) {
                unset($stateData['next_controlling_team_ids']);
                $state->update(['state_data' => $stateData]);
            }

            // Mark the question as active and set the controlling team
            $sessionQuestion->update([
                'status' => 'active',
                'controlling_team_id' => $controllingTeamId,
                'controlling_team_ids' => null,
                'control_status' => 'team_control',
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function nextCard(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentCard = $state->currentCard;

        if (!$currentCard) {
            return response()->json(['error' => 'No current card'], 400);
        }

        // Mark current card as completed
        $currentCard->update(['status' => 'completed']);

        // Clear current question
        $state->update(['current_question_id' => null]);

        // Find the next card
        $nextCard = $gameSession->sessionCards()
            ->where('card_number', '>', $currentCard->card_number)
            ->orderBy('card_number')
            ->first();

        if (!$nextCard) {
            // No more cards - game is complete
            $gameSession->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            return response()->json(['success' => true, 'game_complete' => true]);
        }

        // Set the next card as current
        $state->update(['current_card_id' => $nextCard->id]);

        return response()->json(['success' => true, 'game_complete' => false]);
    }

    public function markCorrect(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        // Accept either a single team_id or an array of team_ids
        $validated = $request->validate([
            'team_id' => 'required_without:team_ids|exists:competitors,id',
            'team_ids' => 'required_without:team_id|array|min:1',
            'team_ids.*' => 'exists:competitors,id',
        ]);

        // Load the game state and current question
        $gameSession->load('gameState.currentQuestion');
        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        // Get the team IDs (handle both single and multiple)
        $teamIds = $validated['team_ids'] ?? [$validated['team_id']];

        // Verify all teams belong to this game
        $teams = $gameSession->teams()->whereIn('id', $teamIds)->get();
        if ($teams->count() !== count($teamIds)) {
            return response()->json(['error' => 'Invalid team(s)'], 400);
        }

        // Get points for this question
        // Check points_mode: 'database' uses the answer's points, 'fixed' uses points_per_answer setting
        $pointsMode = $gameSession->getConfig('points_mode', 'fixed');

        if ($pointsMode === 'database') {
            // Get points from the answer in the database (first answer for Oodles questions)
            $answer = $currentQuestion->question->answers()->first();
            $totalPoints = $answer?->points ?? $gameSession->getConfig('points_per_answer', 100);
        } else {
            // Use the fixed points_per_answer setting, or points_available if set on the session question
            $totalPoints = $currentQuestion->points_available ?: $gameSession->getConfig('points_per_answer', 100);
        }

        // Check multi-team scoring setting: 'full' = all get full points, 'split' = divide among teams
        $multiTeamScoring = $gameSession->getConfig('multi_team_scoring', 'full');

        if ($teams->count() > 1 && $multiTeamScoring === 'split') {
            // Split points among winning teams (round down, but ensure at least 1 point each)
            $pointsPerTeam = max(1, (int) floor($totalPoints / $teams->count()));
        } else {
            // All teams get full points
            $pointsPerTeam = $totalPoints;
        }

        // Award points to each winning team
        foreach ($teams as $team) {
            $team->addScore($pointsPerTeam);
        }

        // Mark question as completed
        $currentQuestion->update(['status' => 'completed']);

        // Check if this was the last question on the card (for Oodles)
        // Award bonus points if configured
        $bonusPoints = 0;
        if ($state->current_card_id) {
            $remainingQuestions = $gameSession->sessionQuestions()
                ->where('session_card_id', $state->current_card_id)
                ->where('status', 'pending')
                ->count();

            if ($remainingQuestions === 0) {
                // This was the last question on the card
                $lastQuestionBonus = $gameSession->getConfig('last_question_bonus', 0);
                if ($lastQuestionBonus > 0) {
                    // Award bonus to each winning team
                    foreach ($teams as $team) {
                        $team->addScore($lastQuestionBonus);
                    }
                    $bonusPoints = $lastQuestionBonus;
                }
            }
        }

        // Clear current question from game state
        $state->update(['current_question_id' => null]);

        // Set the winning team(s) as the active/controlling team for the next question
        if ($teams->count() === 1) {
            // Single winner - set as active team
            $state->update(['active_team_id' => $teams->first()->id]);
        } else {
            // Multiple winners - store them for the next question's control
            // They'll all have control on the next question
            $state->update([
                'state_data' => array_merge($state->state_data ?? [], [
                    'next_controlling_team_ids' => $teams->pluck('id')->toArray(),
                ]),
            ]);
        }

        return response()->json([
            'success' => true,
            'team_names' => $teams->pluck('name')->toArray(),
            'points_per_team' => $pointsPerTeam,
            'bonus_points' => $bonusPoints,
            'total_points' => ($pointsPerTeam + $bonusPoints) * $teams->count(),
        ]);
    }

    public function markWrong(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentQuestion = $state->currentQuestion;

        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        // Switch to All Play mode - clear controlling team
        $currentQuestion->update([
            'control_status' => 'all_play',
            'controlling_team_id' => null,
            'controlling_team_ids' => null,
        ]);

        // Reset and start the timer for All Play
        $defaultDuration = $gameSession->getConfig('control_timer_seconds', 30);
        $state->update([
            'timer_duration' => $defaultDuration,
            'timer_started_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function updateTeamScore(Request $request, GameSession $gameSession, Team $team)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        // Verify the team belongs to this game session
        if ($team->game_id !== $gameSession->id) {
            return response()->json(['error' => 'Team does not belong to this game session'], 400);
        }

        $validated = $request->validate([
            'score' => 'required|integer|min:0',
        ]);

        $team->update(['total_score' => $validated['score']]);

        return response()->json([
            'success' => true,
            'team_id' => $team->id,
            'new_score' => $team->total_score,
        ]);
    }

    public function nextQuestion(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentQuestion = $state->currentQuestion;

        if ($currentQuestion) {
            // Mark current question as completed
            $currentQuestion->update(['status' => 'completed']);
        }

        // Determine the controlling team for the next question
        // Use active_team_id from state (the team that had/has control)
        $stateData = $state->state_data ?? [];
        $nextControllingTeamIds = $stateData['next_controlling_team_ids'] ?? null;

        // For card-based games (Oodles), find next question in current card
        if ($state->current_card_id) {
            $currentCard = $state->currentCard;
            $nextQuestion = $currentCard->sessionQuestions()
                ->where('status', 'pending')
                ->orderBy('display_order')
                ->first();

            if ($nextQuestion) {
                $state->update(['current_question_id' => $nextQuestion->id]);

                // Set up controlling team(s) for the next question
                if ($nextControllingTeamIds && count($nextControllingTeamIds) > 1) {
                    // Multiple teams have control (from All Play tie)
                    $nextQuestion->update([
                        'status' => 'active',
                        'controlling_team_id' => null,
                        'controlling_team_ids' => $nextControllingTeamIds,
                        'control_status' => 'team_control',
                    ]);
                    // Clear the next_controlling_team_ids from state
                    unset($stateData['next_controlling_team_ids']);
                    $state->update(['state_data' => $stateData]);
                } else {
                    // Single team has control (use active_team_id)
                    $nextQuestion->update([
                        'status' => 'active',
                        'controlling_team_id' => $state->active_team_id,
                        'controlling_team_ids' => null,
                        'control_status' => 'team_control',
                    ]);
                }

                return response()->json(['success' => true, 'card_complete' => false]);
            }

            // No more questions on this card
            return response()->json(['success' => true, 'card_complete' => true]);
        }

        // Family Feud is score-driven, not a fixed round count: after each main
        // round, the first team to reach the target (300) ends regular play and
        // its two players go to Fast Money. Until someone gets there we keep
        // playing main rounds — pulling a fresh question from the bank (a triple-
        // value round, like the America Says tie-off pull) when the seeded ones
        // run out — so a close game never stops short of a winner.
        if ($gameSession->gameType->slug === 'family-feud'
            && ($currentQuestion?->segment ?? 'main') === 'main') {
            $target = (int) $gameSession->getConfig('target_score', 300);
            $topScore = (int) ($gameSession->teams()->max('total_score') ?? 0);

            if ($topScore >= $target) {
                // A team hit the target — regular play is over. Fast Money if it's
                // enabled (its two players run the bonus), otherwise the regular
                // game itself is decisive and we finish.
                $fastMoneyReady = $gameSession->sessionQuestions()
                    ->where('segment', 'fast_money')
                    ->exists();
                if ($fastMoneyReady) {
                    return $this->enterFastMoney($gameSession, $state);
                }

                $state->clearRoundScores();
                $gameSession->update(['status' => 'completed', 'completed_at' => now()]);

                return response()->json(['success' => true, 'game_complete' => true]);
            }

            // No winner yet → the next main round: a seeded pending main if one's
            // left, otherwise a freshly pulled question so play continues.
            $nextQuestion = $gameSession->sessionQuestions()
                ->where('segment', 'main')
                ->where('status', 'pending')
                ->orderBy('display_order')
                ->first()
                ?? $this->addFeudMainRound($gameSession);

            if (!$nextQuestion) {
                // Bank fully exhausted (no fresh question to pull) — end on the
                // current standings rather than looping.
                $state->clearRoundScores();
                $gameSession->update(['status' => 'completed', 'completed_at' => now()]);

                return response()->json(['success' => true, 'game_complete' => true]);
            }
        } else {
            // For the other non-card game (America Says), walk the seeded list.
            $nextQuestion = $gameSession->sessionQuestions()
                ->where('status', 'pending')
                ->orderBy('display_order')
                ->first();
        }

        if ($nextQuestion) {
            // Crossing from regular play into the America Says final round: only
            // the team leading after the last regular round plays it, against a
            // single time budget. Set up the final and land on its intro beat.
            $enteringFinal = ($nextQuestion->segment ?? 'main') === 'final'
                && ($currentQuestion?->segment ?? 'main') !== 'final';
            if ($enteringFinal) {
                // Who earned the final round? The top scorer — unless there's a
                // tie for the lead, in which case a one-answer tiebreaker decides
                // who plays it first (the host declares the winner of the tie-off).
                $teams = $gameSession->teams()
                    ->orderByDesc('total_score')
                    ->orderBy('display_order')
                    ->get();
                $topScore = (int) ($teams->first()->total_score ?? 0);
                $tied = $teams->filter(fn ($t) => (int) $t->total_score === $topScore)->values();

                if ($tied->count() > 1) {
                    return $this->startTiebreaker($gameSession, $state, $tied->pluck('id')->all());
                }

                return $this->enterFinalRound($gameSession, $state, $teams->first());
            }

            $newRound = $nextQuestion->round_number ?? $state->round_number;
            // Every question opens on its "Get Ready" intro beat — the question and
            // answers stay hidden until the host hits "Show Question", then "Start
            // Timer". This keeps the intro on the 2nd+ question of a round too, and
            // avoids the brief answer-board flash on a same-round transition.
            $stateData['phase'] = 'intro';
            // Family Feud opens each new round on the face-off buildup: clear the
            // prior round's arming (+ strikes / buzz state) so it starts UNARMED on
            // the Round Intro step — the host hits Start Face-Off again (music +
            // numbered board), matching round 1. Otherwise the stale flag skips the
            // buildup and jumps the checklist straight to the Face-Off step.
            if ($gameSession->gameType->slug === 'family-feud') {
                $stateData['faceoff_armed'] = false;
                $stateData['faceoff'] = null;
                $stateData['strikes'] = 0;
                // Drop the prior round's win / decision banners so they can't linger
                // into the new round.
                $stateData['feud_round_winner'] = null;
                $stateData['feud_decision'] = null;
            }

            $state->update([
                'current_question_id' => $nextQuestion->id,
                'round_number' => $newRound,
                // Make the next board's primary (stamped at init by the rotation)
                // the active team, so its reveals score for the right side and the
                // "who's up" flip-flop happens without the host assigning control.
                'active_team_id' => $nextQuestion->controlling_team_id ?? $state->active_team_id,
                'state_data' => $stateData,
            ]);
            $nextQuestion->update(['status' => 'active']);

            // Snapshot this round's starting scores (the first board of a new round
            // is the first time we see it; later boards no-op) so Reset Round can
            // restore them exactly. Regular rounds only — final/tiebreaker excluded.
            if (($nextQuestion->segment ?? 'main') === 'main') {
                $state->snapshotRoundScoresIfAbsent(
                    (int) $newRound,
                    $gameSession->teams->mapWithKeys(fn ($t) => [$t->id => (int) $t->total_score])->all(),
                );
            }

            return response()->json(['success' => true, 'game_complete' => false, 'entering_final' => false]);
        }

        // No more questions - game is complete. Drop the round snapshots we no
        // longer need so the state row doesn't carry them forever.
        $state->clearRoundScores();
        $gameSession->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json(['success' => true, 'game_complete' => true]);
    }

    /**
     * Set up the America Says final round for a given team: point at the first
     * pending final question, land on its intro ("Get Ready") beat, and reset the
     * final's per-run flags. Shared by the normal (sole leader) path and the
     * tiebreaker resolution, so both enter the final identically.
     */
    protected function enterFinalRound(GameSession $gameSession, GameState $state, ?Team $finalTeam)
    {
        $nextQuestion = $gameSession->sessionQuestions()
            ->where('segment', 'final')
            ->where('status', 'pending')
            ->orderBy('display_order')
            ->first();

        if (!$nextQuestion) {
            // No final questions were materialized — nothing to play, so the game
            // is over (the leading/declared team is the winner by score).
            $gameSession->update(['status' => 'completed', 'completed_at' => now()]);

            return response()->json(['success' => true, 'game_complete' => true]);
        }

        $finalSeconds = (int) $gameSession->getConfig('final_round_seconds', 60);

        $stateData = $state->state_data ?? [];
        $stateData['phase'] = 'final_intro';
        $stateData['final_team_id'] = $finalTeam?->id;
        $stateData['final_skip_used'] = false;
        $stateData['final_skipped_question_id'] = null;
        $stateData['final_result'] = null;
        unset($stateData['tiebreaker_team_ids'], $stateData['tiebreaker_winner_id']);

        $state->update([
            'current_question_id' => $nextQuestion->id,
            'timer_started_at' => null,
            'timer_duration' => $finalSeconds,
            'active_team_id' => $finalTeam?->id,
            'state_data' => $stateData,
        ]);
        $nextQuestion->update(['status' => 'active']);

        return response()->json(['success' => true, 'game_complete' => false, 'entering_final' => true]);
    }

    /**
     * Round 3 ended in a tie for the lead. Queue a one-answer tie-off question
     * (the board looks just like Final question 1) and land on its intro so the
     * host can read the rules of the tie-off. The host then reveals the answer if
     * needed and declares which tied team won (tiebreakerResolve), and that team
     * plays the final. If no single-answer question exists, fall back to the first
     * tied team (by display order) so the game can still proceed.
     */
    protected function startTiebreaker(GameSession $gameSession, GameState $state, array $tiedTeamIds)
    {
        $question = $this->pickTiebreakerQuestion($gameSession);

        if (!$question) {
            $fallback = $gameSession->teams()
                ->whereIn('id', $tiedTeamIds)
                ->orderBy('display_order')
                ->first();

            return $this->enterFinalRound($gameSession, $state, $fallback);
        }

        $order = (int) $gameSession->sessionQuestions()->max('display_order') + 1;
        $tieQuestion = SessionQuestion::create([
            'game_session_id' => $gameSession->id,
            'question_id' => $question->id,
            'display_order' => $order,
            'status' => 'active',
            'segment' => 'tiebreaker',
            'answers_needed' => 1,
        ]);

        $stateData = $state->state_data ?? [];
        $stateData['phase'] = 'tiebreaker_intro';
        $stateData['tiebreaker_team_ids'] = array_values($tiedTeamIds);
        $stateData['final_team_id'] = null;

        $state->update([
            'current_question_id' => $tieQuestion->id,
            'timer_started_at' => null,
            'timer_duration' => 0,
            'active_team_id' => null,
            'state_data' => $stateData,
        ]);

        return response()->json(['success' => true, 'game_complete' => false, 'tiebreaker' => true]);
    }

    /**
     * Pick a random single-answer question for the tie-off — prefer a Final-type
     * one (so the board matches Final question 1), else any single-answer question.
     * Excludes questions already used this session (and any explicitly excluded,
     * e.g. the one being swapped out).
     */
    protected function pickTiebreakerQuestion(GameSession $gameSession, array $excludeIds = [])
    {
        $usedIds = array_merge(
            $gameSession->sessionQuestions()->pluck('question_id')->all(),
            $excludeIds
        );

        $base = fn () => \App\Models\Question::where('game_type_id', $gameSession->game_type_id)
            ->where('is_active', true)
            ->whereNotIn('id', $usedIds)
            ->has('answers', '=', 1);

        return $base()->where('round_type', 'final')->orderBy('times_used')->inRandomOrder()->first()
            ?? $base()->orderBy('times_used')->inRandomOrder()->first();
    }

    /**
     * Tie-off — show the question plaque on the board (answers hidden) so the host
     * can read it. Moves "tiebreaker_intro" → "tiebreaker_question".
     */
    public function tiebreakerShow(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('phase', 'tiebreaker_question');

        return response()->json(['success' => true]);
    }

    /**
     * Tie-off — reveal the (blank) answer board, like a regular round's Start
     * Timer. Moves "tiebreaker_question" (question plaque only) → "tiebreaker_play"
     * (the board of blank answer slots). Does NOT reveal the answer text.
     */
    public function tiebreakerRevealBoard(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('phase', 'tiebreaker_play');

        return response()->json(['success' => true]);
    }

    /**
     * Tie-off — advance from "answer revealed" to the declare-winner step. The
     * board doesn't change (answer stays up); this just moves the host's guided
     * step from "Answer Revealed" to "Declare Winner". Mirrors "Show Scores".
     */
    public function tiebreakerToDeclare(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('phase', 'tiebreaker_declare');

        return response()->json(['success' => true]);
    }

    /**
     * Tie-off — swap the queued question for a different random single-answer one
     * (the host didn't like the draw). Re-hides the answer (back to the plaque).
     */
    public function tiebreakerSwap(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $tieQuestion = $state?->currentQuestion;

        if (!$tieQuestion || ($tieQuestion->segment ?? '') !== 'tiebreaker') {
            return response()->json(['error' => 'No active tiebreaker question'], 400);
        }

        $replacement = $this->pickTiebreakerQuestion($gameSession, [$tieQuestion->question_id]);
        if (!$replacement) {
            return response()->json(['error' => 'No other question available to swap in'], 400);
        }

        $tieQuestion->answerReveals()->delete();
        $tieQuestion->update(['question_id' => $replacement->id]);

        // Re-hide the answer: keep the plaque up if the host was mid tie-off,
        // otherwise stay on the intro.
        if ($state->getStateValue('phase') === 'tiebreaker_play') {
            $state->setStateValue('phase', 'tiebreaker_question');
        }

        return response()->json(['success' => true]);
    }

    /**
     * Tie-off — the host declares which tied team won the buzz-off. This does NOT
     * jump straight into the final: it lands on a "tiebreaker_result" beat so the
     * display can crown the winner ("Team X Wins! — On to the Final Round"), just
     * like the regular round's recap. The host then presses "Start Final Round"
     * (tiebreakerToFinal) to actually begin it. The tiebreaker question is done.
     */
    public function tiebreakerResolve(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'team_id' => 'required|exists:competitors,id',
        ]);

        $state = $gameSession->gameState;
        $tiedIds = $state?->getStateValue('tiebreaker_team_ids') ?? [];
        if (!in_array($validated['team_id'], $tiedIds)) {
            return response()->json(['error' => 'That team is not part of the tiebreaker'], 400);
        }

        $tieQuestion = $state?->currentQuestion;
        if ($tieQuestion && ($tieQuestion->segment ?? '') === 'tiebreaker') {
            $tieQuestion->update(['status' => 'completed']);
        }

        $stateData = $state->state_data ?? [];
        $stateData['phase'] = 'tiebreaker_result';
        $stateData['tiebreaker_winner_id'] = $validated['team_id'];
        $state->update(['state_data' => $stateData]);

        return response()->json(['success' => true]);
    }

    /**
     * Tie-off — begin the final round with the team that won the tie-off. Fired by
     * "Start Final Round" on the winner slide (tiebreaker_result), mirroring the
     * regular recap's "Start Final Round" button.
     */
    public function tiebreakerToFinal(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $winnerId = $state?->getStateValue('tiebreaker_winner_id');
        $winner = $winnerId ? $gameSession->teams()->whereKey($winnerId)->first() : null;

        return $this->enterFinalRound($gameSession, $state, $winner);
    }

    /**
     * America Says guided flow — reveal the loaded question on the board.
     * Moves the phase from "intro" (Round N — Get Ready) to "question" so the
     * plaque + blank board appear; the timer stays idle until startTimer.
     */
    public function showQuestion(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;

        // Family Feud opens on the FACE-OFF (both teams buzz on the shown question)
        // before a team takes the board; America Says goes straight to the question.
        if ($gameSession->gameType->slug === 'family-feud'
            && ($state?->currentQuestion?->segment ?? 'main') === 'main') {
            $state?->setStateValue('phase', 'faceoff');
            $state?->setStateValue('strikes', 0);
            // Fresh face-off: no team has buzzed yet.
            $state?->setStateValue('faceoff', ['buzzed' => null, 'turn' => null, 'answers' => [], 'decider' => null]);
            // Snapshot the round's starting scores now (idempotent) so Reset Round
            // can restore them exactly — Feud awards its pool at resolution, which
            // this predates. Round 1 has no next-question snapshot otherwise.
            if ($state) {
                $state->snapshotRoundScoresIfAbsent(
                    (int) ($state->currentQuestion->round_number ?? $state->round_number ?? 1),
                    $gameSession->teams->mapWithKeys(fn ($t) => [$t->id => (int) $t->total_score])->all(),
                );
            }

            return response()->json(['success' => true]);
        }

        $state?->setStateValue('phase', 'question');

        return response()->json(['success' => true]);
    }

    /**
     * America Says guided flow — step back to the round intro ("Get Ready"), e.g.
     * the host clicked the Round Intro step to re-read the setup. Non-destructive:
     * reveals/points are untouched; only the board display returns to the intro.
     */
    public function roundIntro(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('phase', 'intro');
        // Stepping back to the intro re-arms the face-off (Feud): the host starts it
        // again, so the display's buildup + "Show Question" gate reset.
        $gameSession->gameState?->setStateValue('faceoff_armed', false);

        return response()->json(['success' => true]);
    }

    /**
     * America Says — sound the "wrong answer" buzzer on the display. A wrong
     * guess has no board state to change (it simply isn't on the board), so we
     * bump a monotonic counter the display watches, playing its incorrect sound
     * once each time it advances.
     */
    public function buzzWrong(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        if ($state) {
            $current = (int) $state->getStateValue('wrong_buzz', 0);
            $state->setStateValue('wrong_buzz', $current + 1);

            // A wrong steal ends the steal but not the board: move to the untimed
            // "reveal the leftovers" state so the display drops its STEAL banner
            // while the host reveals the rest (in Reveal only, no scoring).
            if ($state->getStateValue('phase') === 'steal') {
                $state->setStateValue('phase', 'reveal');
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * America Says guided flow — end the current round and show the scoreboard.
     * Sets the phase to "recap"; the host then advances with nextQuestion
     * ("Start Round N+1"), which moves to the next round's intro.
     */
    public function endRound(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        // Remember which board state we came from so pulling an answer back off the
        // scoreboard returns there (the steal, or the primary's turn) rather than
        // always to "Question Shown".
        $prev = $state?->getStateValue('phase');
        if (in_array($prev, ['question', 'steal', 'reveal'], true)) {
            $state?->setStateValue('phase_before_recap', $prev);
        }
        $state?->setStateValue('phase', 'recap');

        return response()->json(['success' => true]);
    }

    /**
     * America Says regular round — hand the board to the OTHER team for the steal.
     * Fired automatically when the primary team's timer runs out (or manually by
     * the host). The steal is untimed: the stealing team keeps guessing while
     * correct. A wrong steal flips the host into Reveal-only (client-side) to show
     * the leftovers; the board ends when every answer is revealed. Revealed answers
     * score for the stealing team, since it becomes the active/controlling team.
     */
    public function stealStart(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;
        if (!$currentQuestion || ($currentQuestion->segment ?? 'main') !== 'main') {
            return response()->json(['error' => 'No active regular-round question'], 400);
        }

        // The stealing team is the one that isn't primary (2-team game).
        $primaryId = $currentQuestion->controlling_team_id ?? $state->active_team_id;
        $stealTeam = $gameSession->teams()->where('id', '!=', $primaryId)->orderBy('display_order')->first();

        if ($stealTeam) {
            // Remember who was primary so the display can label the board and a
            // manual correction can hand control back.
            $state->setStateValue("primary_q{$currentQuestion->id}", $primaryId);

            $currentQuestion->update([
                'controlling_team_id' => $stealTeam->id,
                'controlling_team_ids' => null,
                'control_status' => 'team_control',
            ]);
            // Stop the clock (the steal is untimed) and make the stealer active so
            // their reveals score.
            $state->update(['active_team_id' => $stealTeam->id, 'timer_started_at' => null]);
        }

        $state->setStateValue('phase', 'steal');

        return response()->json(['success' => true]);
    }

    /**
     * America Says regular round — sync the board phase to the host's Reveal-only
     * control while a steal is in play. Turning Reveal only ON drops to the untimed
     * "reveal the leftovers" phase (so the display clears its STEAL banner — nobody
     * is stealing anymore); turning it OFF returns to the steal.
     */
    public function setStealReveal(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate(['reveal_only' => 'required|boolean']);

        $state = $gameSession->gameState;
        if ($state && in_array($state->getStateValue('phase'), ['steal', 'reveal'], true)) {
            $state->setStateValue('phase', $validated['reveal_only'] ? 'reveal' : 'steal');
        }

        return response()->json(['success' => true]);
    }

    // ---- Family Feud regular round --------------------------------------------
    // Feud scores a POINT POOL, not per-answer: revealing an answer accrues its
    // survey points into the round's pool without scoring anyone yet. The round is
    // resolved once — the whole pool × the round multiplier goes to a single team:
    // the controlling team if they clear the board or the steal fails, the stealing
    // team if the steal succeeds (see the rules doc, docs/family-feud-game-rules.md).
    //
    // Phases: intro (Get Ready) → faceoff (question shown, both teams buzz) →
    // question (controlling team plays; strikes accrue) → steal (other team's one
    // guess) → recap (pool awarded). Strikes are authoritative state the display
    // flashes; hitting max_strikes auto-hands to the steal.

    /**
     * The round multiplier for the current Feud question. Stored at init as
     * points_available (calculateRoundMultiplier); falls back to the config
     * schedule, then 1.
     */
    protected function feudMultiplier(GameSession $gameSession, SessionQuestion $question): int
    {
        $mult = (int) $question->points_available;
        if ($mult > 0) {
            return $mult;
        }
        return $this->feudRoundMultiplier($gameSession, (int) ($question->round_number ?? 1));
    }

    /**
     * The point multiplier for a Feud round. Rounds 1–2 are single, 3 double, 4
     * triple (the seeded schedule). Because regular play is open-ended — it runs
     * until a team reaches the target — any round past the schedule (5+, only
     * reached in a stubborn tie) stays at the highest defined value (triple).
     */
    protected function feudRoundMultiplier(GameSession $gameSession, int $round): int
    {
        $schedule = $gameSession->getConfig('round_multipliers', []);
        if (isset($schedule[(string) $round])) {
            return (int) $schedule[(string) $round];
        }
        return $schedule ? (int) max($schedule) : 1;
    }

    /**
     * Pull a fresh main-round question into a Feud session when the seeded rounds
     * are used up but no team has reached the target yet. Mirrors the America
     * Says tie-off pull (pickTiebreakerQuestion): a random unused, non-Final
     * question, ordered least-used first. The new round takes the next round
     * number and its multiplier from the schedule (triple, past round 4). Returns
     * null only when the bank is fully exhausted.
     */
    protected function addFeudMainRound(GameSession $gameSession): ?SessionQuestion
    {
        $usedIds = $gameSession->sessionQuestions()->pluck('question_id')->all();

        $question = Question::where('game_type_id', $gameSession->game_type_id)
            ->where('is_active', true)
            ->where('round_type', '!=', 'final')
            ->whereNotIn('id', $usedIds)
            ->orderBy('times_used')
            ->inRandomOrder()
            ->first();

        if (!$question) {
            return null;
        }

        $nextRound = (int) ($gameSession->sessionQuestions()
            ->where('segment', 'main')->max('round_number') ?? 0) + 1;
        $order = (int) $gameSession->sessionQuestions()->max('display_order') + 1;

        return SessionQuestion::create([
            'game_session_id' => $gameSession->id,
            'question_id' => $question->id,
            'display_order' => $order,
            'round_number' => $nextRound,
            'status' => 'pending',
            'segment' => 'main',
            'points_available' => $this->feudRoundMultiplier($gameSession, $nextRound),
        ]);
    }

    /**
     * The current Feud point pool — the sum of the survey points of every revealed
     * answer on the current question (before the round multiplier is applied).
     */
    protected function feudPointPool(SessionQuestion $question): int
    {
        return (int) $question->answerReveals()->sum('points_awarded');
    }

    /**
     * Feud face-off → play. The controlling team (chosen at the face-off) starts
     * guessing the board. Strikes reset for the fresh turn; the pool carries the
     * face-off reveal(s) already on the board.
     */
    public function feudStartPlay(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'decision' => ['nullable', 'in:play,pass'],
            'decision_team_id' => ['nullable', 'integer'],
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;
        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        // The face-off winner's Play/Pass call — published so the board can flash a
        // "TEAM — PLAY / PASS" banner. Monotonic seq so the display shows it once
        // (a reconnect doesn't replay it). Cleared on the next face-off / round.
        if (!empty($validated['decision']) && !empty($validated['decision_team_id'])) {
            $seq = (int) (($state->getStateValue('feud_decision')['seq'] ?? 0)) + 1;
            $state->setStateValue('feud_decision', [
                'team_id' => (int) $validated['decision_team_id'],
                'choice' => $validated['decision'],
                'seq' => $seq,
            ]);
        }

        // Keep the chosen controlling team as the active side so its reveals attach
        // to the right board; the play phase begins with a clean strike count and
        // the face-off state cleared.
        $state->setStateValue('strikes', 0);
        $state->setStateValue('faceoff', null);
        $state->setStateValue('phase', 'question');
        if ($currentQuestion->controlling_team_id) {
            $state->update(['active_team_id' => $currentQuestion->controlling_team_id]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Feud face-off — "arm" the face-off from the round intro. Stays on the intro
     * (matchup) slide but tells the display to fire the face-off music and light the
     * bulbs up; the host's "Show Question" button only appears once this is set. The
     * flag is cleared whenever the round returns to its intro (see roundIntro / round
     * advance), so each round arms fresh.
     */
    public function feudFaceoffStart(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('faceoff_armed', true);

        return response()->json(['success' => true]);
    }

    /**
     * Feud face-off — record which team buzzed in first. That team answers first;
     * the display sounds the buzzer. Sets them as the active/controlling team so
     * their reveals attach to the board.
     */
    public function feudFaceoffBuzz(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate(['team_id' => 'required|integer|exists:competitors,id']);
        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;
        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }
        if (!$gameSession->teams()->whereKey($validated['team_id'])->exists()) {
            return response()->json(['error' => 'Invalid team'], 400);
        }

        $teamId = (int) $validated['team_id'];
        $state->setStateValue('faceoff', ['buzzed' => $teamId, 'turn' => $teamId, 'answers' => [], 'decider' => null]);
        $currentQuestion->update([
            'controlling_team_id' => $teamId,
            'controlling_team_ids' => null,
            'control_status' => 'team_control',
        ]);
        $state->update(['active_team_id' => $teamId]);
        // Sound the buzzer on the display.
        $state->setStateValue('faceoff_buzz', (int) $state->getStateValue('faceoff_buzz', 0) + 1);

        return response()->json(['success' => true]);
    }

    /**
     * Record a team's face-off answer and work out the flow: the first team wins
     * outright if they hit the #1 answer; otherwise the other team gets a turn, and
     * once both have answered the higher points decides Play/Pass (tie → the team
     * that buzzed). The decider is set as the controlling team. Called on a reveal
     * (points, wasTop) or a strike (0, false) during the face-off.
     */
    protected function feudFaceoffAfterAnswer(GameSession $gameSession, GameState $state, SessionQuestion $question, int $teamId, int $points, bool $wasTop): void
    {
        $fo = $state->getStateValue('faceoff');
        if (!is_array($fo) || empty($fo['buzzed'])) {
            return; // Not in a live face-off.
        }

        $fo['answers'] = $fo['answers'] ?? [];
        $fo['answers'][(string) $teamId] = $points;
        $buzzed = (int) $fo['buzzed'];
        $other = (int) $gameSession->teams()->where('id', '!=', $teamId)->orderBy('display_order')->value('id');

        if ($teamId === $buzzed && $wasTop) {
            // First team nailed the #1 answer — they decide, second team is out.
            $fo['decider'] = $buzzed;
        } elseif ($other && !array_key_exists((string) $other, $fo['answers'])) {
            // The first team missed the top answer, so the OTHER team gets a face-off
            // turn — move the "up"/control indicator to them so both scoreboards
            // (host + display) show whose turn it is, not the team that buzzed first.
            $fo['turn'] = $other;
            $question->update([
                'controlling_team_id' => $other,
                'controlling_team_ids' => null,
                'control_status' => 'team_control',
            ]);
            $state->update(['active_team_id' => $other]);
        } else {
            // Both teams have answered — the higher points decides (tie → buzzed).
            $mine = (int) ($fo['answers'][(string) $teamId] ?? 0);
            $theirs = (int) ($fo['answers'][(string) $other] ?? 0);
            $fo['decider'] = $theirs > $mine ? $other : ($mine > $theirs ? $teamId : $buzzed);
        }

        if (!empty($fo['decider'])) {
            $question->update([
                'controlling_team_id' => $fo['decider'],
                'controlling_team_ids' => null,
                'control_status' => 'team_control',
            ]);
            $state->update(['active_team_id' => $fo['decider']]);
        }

        $state->setStateValue('faceoff', $fo);
    }

    /**
     * Feud — add a strike for a wrong guess. Strikes are authoritative state the
     * display flashes (and sounds the strike/buzzer cue off). Reaching max_strikes
     * ends the controlling team's turn and hands the board to the other team for
     * the one-guess steal. During the face-off a strike is a wrong face-off answer
     * (no play strike), handing the turn to the other team / deciding Play or Pass.
     */
    public function feudStrike(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;
        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        // Face-off: a wrong face-off answer, not a play strike.
        if ($state->getStateValue('phase') === 'faceoff') {
            $fo = $state->getStateValue('faceoff');
            $turn = (int) ($fo['turn'] ?? $fo['buzzed'] ?? 0);
            if ($turn) {
                $this->feudFaceoffAfterAnswer($gameSession, $state, $currentQuestion, $turn, 0, false);
            }
            // Flash a strike X + sound on the display.
            $state->setStateValue('faceoff_strike', (int) $state->getStateValue('faceoff_strike', 0) + 1);

            return response()->json(['success' => true, 'faceoff' => true]);
        }

        // Steal: the one guess missed → the steal fails, the original team keeps the
        // pool. (A correct steal resolves on the reveal instead.) Flash the X too.
        // Only during the 'steal' guess itself — once we're on the 'reveal' beat the
        // outcome is settled and the host is just putting up leftovers.
        if ($state->getStateValue('phase') === 'steal') {
            $state->setStateValue('faceoff_strike', (int) $state->getStateValue('faceoff_strike', 0) + 1);
            $amount = $this->feudResolvePool($gameSession, $state, $currentQuestion, 'steal_fail');

            return response()->json(['success' => true, 'steal_resolved' => 'fail', 'awarded' => $amount]);
        }

        $maxStrikes = (int) $gameSession->getConfig('max_strikes', 3);
        $strikes = min($maxStrikes, (int) $state->getStateValue('strikes', 0) + 1);
        $state->setStateValue('strikes', $strikes);

        // Third strike ends the turn → hand to the steal (reuses stealStart, which
        // flips control to the other team and sets the 'steal' phase).
        $handedToSteal = false;
        if ($strikes >= $maxStrikes) {
            $this->feudHandToSteal($gameSession, $state, $currentQuestion);
            $handedToSteal = true;
        }

        return response()->json(['success' => true, 'strikes' => $strikes, 'steal' => $handedToSteal]);
    }

    /**
     * Reset the strike count (host correction).
     */
    public function feudClearStrikes(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('strikes', 0);

        return response()->json(['success' => true]);
    }

    /**
     * Hand the Feud board to the OTHER team for the one-guess steal. Stashes the
     * primary team (so a failed steal can award them the pool) and flips control +
     * phase, exactly like America Says' stealStart but without touching scores.
     */
    protected function feudHandToSteal(GameSession $gameSession, GameState $state, SessionQuestion $currentQuestion): void
    {
        $primaryId = $currentQuestion->controlling_team_id ?? $state->active_team_id;
        $stealTeam = $gameSession->teams()->where('id', '!=', $primaryId)->orderBy('display_order')->first();

        if ($stealTeam) {
            $state->setStateValue("primary_q{$currentQuestion->id}", $primaryId);
            $currentQuestion->update([
                'controlling_team_id' => $stealTeam->id,
                'controlling_team_ids' => null,
                'control_status' => 'team_control',
            ]);
            $state->update(['active_team_id' => $stealTeam->id, 'timer_started_at' => null]);
        }

        $state->setStateValue('phase', 'steal');
    }

    /**
     * Resolve the Feud round: award the whole pool × the round multiplier to one
     * team and drop to the scoreboard. Outcomes:
     *   - 'clear'         the controlling team cleared the board  → they win
     *   - 'steal_success' the stealing team guessed a leftover    → stealer wins
     *   - 'steal_fail'    the steal missed                        → original team wins
     * Idempotent: re-resolving reverses the prior award first (host correction),
     * and the award is recorded per-question so Reset Round/Board reverse it.
     *
     * Mostly driven automatically now — a board clear (front-end), or a steal that's
     * revealed (success) / struck out (fail) resolve on their own — but the endpoint
     * stays for corrections.
     */
    public function feudResolve(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'outcome' => 'required|in:clear,steal_success,steal_fail',
        ]);

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;
        if (!$currentQuestion) {
            return response()->json(['error' => 'No active question'], 400);
        }

        $amount = $this->feudResolvePool($gameSession, $state, $currentQuestion, $validated['outcome']);

        return response()->json(['success' => true, 'awarded' => $amount]);
    }

    /**
     * Award the pool × multiplier for the given outcome and drop to the scoreboard.
     * Idempotent (reverses any prior award first). Returns the amount awarded.
     */
    protected function feudResolvePool(GameSession $gameSession, GameState $state, SessionQuestion $currentQuestion, string $outcome): int
    {
        // Reverse a prior award for this question (re-resolve as a correction).
        $poolKey = "feud_pool_q{$currentQuestion->id}";
        $this->reverseFeudPool($state, $poolKey);

        // Who wins the pool. On a steal, control has flipped to the stealer; the
        // original (primary) team was stashed when the board was handed over.
        $stealTeamId = $currentQuestion->controlling_team_id;
        $primaryId = (int) $state->getStateValue("primary_q{$currentQuestion->id}", $currentQuestion->controlling_team_id);
        $winnerId = match ($outcome) {
            'steal_success' => $stealTeamId,
            'steal_fail' => $primaryId,
            default => $currentQuestion->controlling_team_id,
        };

        $pool = $this->feudPointPool($currentQuestion);
        $amount = $pool * $this->feudMultiplier($gameSession, $currentQuestion);

        $winner = $winnerId ? Team::find($winnerId) : null;
        if ($winner && $amount > 0) {
            $winner->addScore($amount);
            $state->setStateValue($poolKey, ['team_id' => $winner->id, 'amount' => $amount]);
            // Publish the round winner so the board flashes a "TEAM WINS THE ROUND!"
            // banner. Monotonic seq → shown once; cleared when the next turn's play
            // begins (feudStartPlay) or the round advances.
            $seq = (int) (($state->getStateValue('feud_round_winner')['seq'] ?? 0)) + 1;
            $state->setStateValue('feud_round_winner', [
                'team_id' => $winner->id,
                'amount' => $amount,
                'seq' => $seq,
            ]);
        }

        // Where to next? A clear means the board is already full → straight to the
        // scoreboard (the caller held the 2s so the last reveal landed). A steal
        // (success OR fail) leaves un-guessed answers up — pause on a 'reveal' beat
        // so the host can reveal the leftovers (no points) first. Once the board is
        // full the front-end holds 2s, then feudFinishReveal drops to the scores.
        if ($outcome === 'clear') {
            $prev = $state->getStateValue('phase');
            if (in_array($prev, ['question', 'steal', 'reveal'], true)) {
                $state->setStateValue('phase_before_recap', $prev);
            }
            $state->setStateValue('phase', 'recap');
        } else {
            $state->setStateValue('phase', 'reveal');
        }

        return $amount;
    }

    /**
     * Drop from the leftover-reveal beat to the scoreboard once every answer is up
     * (the front-end calls this after its 2s hold). The award already happened at
     * resolution; this only flips the phase — recording where we came from so a
     * mis-score fix can return to the board.
     */
    public function feudFinishReveal(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $prev = $state?->getStateValue('phase');
        if (in_array($prev, ['question', 'steal', 'reveal'], true)) {
            $state->setStateValue('phase_before_recap', $prev);
        }
        $state?->setStateValue('phase', 'recap');

        return response()->json(['success' => true]);
    }

    /**
     * Reverse (and clear) a recorded Feud pool award, if any. Shared by resolve
     * (re-resolution) and the board/round resets.
     */
    protected function reverseFeudPool(GameState $state, string $poolKey): void
    {
        $award = $state->getStateValue($poolKey, null);
        if (is_array($award) && !empty($award['team_id'])) {
            $team = Team::find($award['team_id']);
            if ($team) {
                $team->update(['total_score' => max(0, $team->total_score - (int) ($award['amount'] ?? 0))]);
            }
        }
        $state->setStateValue($poolKey, null);
    }

    // ---- Family Feud Fast Money -----------------------------------------------
    // The winning team's two players play two timed passes over the SAME 5
    // questions: Player 1 (20s), then Player 2 (25s), who can't duplicate Player
    // 1's answers (a duplicate scores 0 and sounds the zero-points cue). The two
    // passes combine toward a target (200) — reaching it is a win. Fast Money does
    // NOT change team scores; it's a bonus round like the America Says final.
    //
    // Real-show flow is capture-then-reveal, per player. Phases:
    //   fast_money_intro
    //   → fast_money_p1_capture  (20s clock; host records what P1 said, hidden)
    //   → fast_money_p1_reveal   (untimed; reveal each answer's text then points)
    //   → fast_money_p2_capture  (25s clock; P1's answers can't be duplicated)
    //   → fast_money_p2_reveal
    //   → fast_money_result      (win at ≥ target; celebratory slide + confetti)
    // All state lives under state_data.fast_money (answers keyed by session-question
    // id then player; each cell carries captured/shown/scored), rebuilt into board
    // rows + totals by fastMoneyPayload().

    /**
     * Enter Fast Money after the last regular round. Sets up the state skeleton and
     * lands on the intro (host presses Start Player 1). The top-scoring team plays.
     */
    protected function enterFastMoney(GameSession $gameSession, GameState $state)
    {
        $first = $gameSession->sessionQuestions()
            ->where('segment', 'fast_money')
            ->orderBy('display_order')
            ->first();

        if (!$first) {
            // Fast Money disabled / not materialized — the game is over.
            $gameSession->update(['status' => 'completed', 'completed_at' => now()]);

            return response()->json(['success' => true, 'game_complete' => true]);
        }

        $winner = $gameSession->teams()->orderByDesc('total_score')->orderBy('display_order')->first();

        $stateData = $state->state_data ?? [];
        $stateData['phase'] = 'fast_money_intro';
        $stateData['final_team_id'] = $winner?->id;
        $stateData['fast_money'] = [
            'target' => (int) $gameSession->getConfig('fast_money_target_score', 200),
            'p1_seconds' => (int) $gameSession->getConfig('fast_money_player1_seconds', 20),
            'p2_seconds' => (int) $gameSession->getConfig('fast_money_player2_seconds', 25),
            'active_player' => 1,
            'timer1_buzz' => 0,
            'timer2_buzz' => 0,
            'duplicate_buzz' => 0,
            // While Player 2 answers, Player 1's board stays hidden on the TV until
            // the host flashes it to the room (they've turned away).
            'show_previous' => false,
            'result' => null,
            'answers' => [],
        ];

        $state->update([
            'current_question_id' => $first->id,
            'timer_started_at' => null,
            'timer_duration' => (int) $gameSession->getConfig('fast_money_player1_seconds', 20),
            'active_team_id' => $winner?->id,
            'state_data' => $stateData,
        ]);
        // All five questions are "in play" at once (the board shows them together).
        $gameSession->sessionQuestions()->where('segment', 'fast_money')->update(['status' => 'active']);

        return response()->json(['success' => true, 'entering_fast_money' => true]);
    }

    /**
     * Start a player's CAPTURE pass: set the active player, start their clock (20s
     * for Player 1, 25s for Player 2) and bump the timer-sound counter the display
     * plays the pass sting off. Capture only records answers (hidden) — the reveal
     * comes later. For Player 2 this is the "Start Timer" after the bring-out.
     */
    public function fmStartPlayer(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate(['player' => 'required|integer|in:1,2']);
        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $player = (int) $validated['player'];
        $seconds = $player === 1 ? (int) ($fm['p1_seconds'] ?? 20) : (int) ($fm['p2_seconds'] ?? 25);
        $fm['active_player'] = $player;
        $fm["timer{$player}_buzz"] = (int) ($fm["timer{$player}_buzz"] ?? 0) + 1;
        $state->setStateValue('fast_money', $fm);
        $state->setStateValue('phase', "fast_money_p{$player}_capture");
        // Start the clock ~1s later (casting-latency grace).
        $state->update(['timer_duration' => $seconds, 'timer_started_at' => now()->addSecond()]);

        return response()->json(['success' => true]);
    }

    /**
     * Capture (record, hidden) the active player's answer for one Fast Money
     * question. Pass an answer_id for a survey match, or nothing for a "no match"
     * (0, still captured). If Player 2 taps an answer Player 1 already used for the
     * SAME question it's a duplicate — NOT captured; we bump duplicate_buzz so the
     * display sounds the buzzer and the player guesses again.
     */
    public function fmCapture(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'session_question_id' => 'required|integer',
            'answer_id' => 'nullable|integer|exists:answers,id',
        ]);

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $sq = $gameSession->sessionQuestions()
            ->where('segment', 'fast_money')
            ->whereKey($validated['session_question_id'])
            ->with('question.answers')
            ->first();
        if (!$sq) {
            return response()->json(['error' => 'Invalid Fast Money question'], 400);
        }

        $player = (int) ($fm['active_player'] ?? 1);
        $answers = $fm['answers'] ?? [];
        $key = (string) $sq->id;

        // Duplicate: Player 2 tapped the exact answer Player 1 used for this question.
        if ($player === 2 && !empty($validated['answer_id'])) {
            $p1 = $answers[$key]['1'] ?? null;
            if (is_array($p1) && (int) ($p1['answer_id'] ?? 0) === (int) $validated['answer_id']) {
                $fm['duplicate_buzz'] = (int) ($fm['duplicate_buzz'] ?? 0) + 1;
                $state->setStateValue('fast_money', $fm);

                return response()->json(['success' => true, 'duplicate' => true]);
            }
        }

        $entry = ['answer_id' => null, 'text' => null, 'points' => 0, 'shown' => false, 'scored' => false];
        if (!empty($validated['answer_id'])) {
            $answer = $sq->question->answers->firstWhere('id', $validated['answer_id']);
            if ($answer) {
                $entry['answer_id'] = $answer->id;
                $entry['text'] = $answer->answer_text;
                $entry['points'] = (int) ($answer->points ?? 0);
                $answer->recordReveal();
            }
        }

        $answers[$key] = $answers[$key] ?? [];
        $answers[$key][(string) $player] = $entry;
        $fm['answers'] = $answers;
        $state->setStateValue('fast_money', $fm);

        return response()->json(['success' => true, 'points' => $entry['points']]);
    }

    /**
     * Clear the active player's captured answer for a Fast Money question (host
     * correction, during capture).
     */
    public function fmClear(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate(['session_question_id' => 'required|integer']);
        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $player = (int) ($fm['active_player'] ?? 1);
        $key = (string) $validated['session_question_id'];
        if (isset($fm['answers'][$key][(string) $player])) {
            unset($fm['answers'][$key][(string) $player]);
            $state->setStateValue('fast_money', $fm);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Set the words shown on the board for a captured Fast Money cell — what the
     * player actually SAID. A matched answer prefills its canonical text (on capture)
     * but the host can override it to the player's own wording here; the matched
     * answer's POINTS are unaffected. A no-match keeps 0 points. Empty clears it back
     * to blank. Applies to any captured cell for the active player.
     */
    public function fmMissText(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'session_question_id' => 'required|integer',
            'text' => 'nullable|string|max:120',
        ]);

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $player = (int) ($fm['active_player'] ?? 1);
        $key = (string) $validated['session_question_id'];
        $cell = $fm['answers'][$key][(string) $player] ?? null;
        if (is_array($cell)) {
            $fm['answers'][$key][(string) $player]['text'] = ($validated['text'] ?? '') !== '' ? $validated['text'] : null;
            $state->setStateValue('fast_money', $fm);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Move the active player from CAPTURE to REVEAL — stops the clock; the host now
     * reveals each answer's text then points, one at a time.
     */
    public function fmToReveal(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $player = (int) ($fm['active_player'] ?? 1);
        $state->setStateValue('phase', "fast_money_p{$player}_reveal");
        $state->update(['timer_started_at' => null]);

        return response()->json(['success' => true]);
    }

    /**
     * Reveal step: `part` = 'answer' types the captured answer's TEXT onto the TV;
     * 'points' flips its POINTS up (and counts them toward the total). One question
     * at a time, for the active player.
     */
    public function fmRevealCell(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'session_question_id' => 'required|integer',
            'part' => 'required|in:answer,points',
        ]);

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $player = (int) ($fm['active_player'] ?? 1);
        $key = (string) $validated['session_question_id'];
        if (!isset($fm['answers'][$key][(string) $player])) {
            return response()->json(['error' => 'Nothing captured for that question'], 400);
        }

        if ($validated['part'] === 'answer') {
            $fm['answers'][$key][(string) $player]['shown'] = true;
        } else {
            // Points imply the answer is up too (keeps state consistent if skipped).
            $fm['answers'][$key][(string) $player]['shown'] = true;
            $fm['answers'][$key][(string) $player]['scored'] = true;
        }
        $state->setStateValue('fast_money', $fm);

        return response()->json(['success' => true]);
    }

    /**
     * Bring out Player 2: flip the active player, land on the (untimed) P2 capture
     * beat WITHOUT starting the clock, and hide Player 1's board on the TV until the
     * host flashes it. Called from the end of Player 1's reveal.
     */
    public function fmNextPlayer(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $fm['active_player'] = 2;
        $fm['show_previous'] = false;
        $state->setStateValue('fast_money', $fm);
        $state->setStateValue('phase', 'fast_money_p2_capture');
        $state->update(['timer_started_at' => null]);

        return response()->json(['success' => true]);
    }

    /**
     * Toggle whether Player 1's board is flashed to the room during Player 2's
     * capture (used when Player 2 has turned away from the TV).
     */
    public function fmShowPrevious(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate(['show' => 'required|boolean']);
        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $fm['show_previous'] = (bool) $validated['show'];
        $state->setStateValue('fast_money', $fm);

        return response()->json(['success' => true]);
    }

    /**
     * Tally the two passes and land on the result (win when the combined total
     * meets the target). Stops the clock. Called after Player 2's last reveal.
     */
    public function fmResult(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $payload = $this->fastMoneyPayload($gameSession, $state);
        $fm['result'] = ($payload['combined_total'] ?? 0) >= (int) ($fm['target'] ?? 200) ? 'win' : 'lose';
        $state->setStateValue('fast_money', $fm);
        $state->setStateValue('phase', 'fast_money_result');
        $state->update(['timer_started_at' => null]);

        return response()->json(['success' => true, 'result' => $fm['result']]);
    }

    /**
     * Pop back from the celebratory result slide to the (active player's) reveal
     * board, so the host can reveal any answers that weren't shown before the win
     * clinched — purely for the crowd. The host returns to the slide via fmResult.
     */
    public function fmBackToReveal(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $fm = $state?->getStateValue('fast_money');
        if (!is_array($fm)) {
            return response()->json(['error' => 'Fast Money is not active'], 400);
        }

        $player = (int) ($fm['active_player'] ?? 2);
        $state->setStateValue('phase', "fast_money_p{$player}_reveal");
        $state->update(['timer_started_at' => null]);

        return response()->json(['success' => true]);
    }

    // ---- America Says final round ---------------------------------------------
    // A single time budget (default 60s) covers all final questions. Each question
    // mirrors a regular round: the plaque is shown first (host reads it) with the
    // clock idle, then the host reveals the answers and the clock runs. The clock
    // banks its remaining time between questions — it auto-pauses when a question
    // is cleared (see revealFinalAnswer), the board keeps the revealed answers up,
    // and the host moves on when ready. The leading team plays for a pass/fail win.
    //
    // Phases: final_intro (Get Ready) → final_question (plaque only, clock idle) →
    // final_play (answers + clock) → final_cleared (revealed board stays, clock
    // banked) → back to final_question for the next one; final_review on timeout;
    // final_result at the end.

    /**
     * Show the current final question's plaque on the board (answers still hidden,
     * clock idle) so the host can read it aloud. Moves "final_intro" (or a jumped
     * state) to "final_question"; the host then hits Start to reveal + run the clock.
     */
    public function finalShowQuestion(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $gameSession->gameState?->setStateValue('phase', 'final_question');

        return response()->json(['success' => true]);
    }

    /**
     * Reveal the answer board and (re)start the clock for the shown final question.
     * Moves "final_question" → "final_play". The time budget was set when the final
     * began (or banked between questions), so this just resumes it from where it
     * stands — full budget on the first question, banked remainder thereafter.
     */
    public function finalStart(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        // Reveal the board now but start the clock ~1s later, so casting latency
        // doesn't eat the opening seconds (see startTimer).
        $state->update(['timer_started_at' => now()->addSecond()]);
        $state->setStateValue('phase', 'final_play');

        return response()->json(['success' => true]);
    }

    /**
     * Advance to the next final question after one is cleared: point at it and show
     * its plaque only (clock stays banked). Moves "final_cleared" → "final_question".
     */
    public function finalNext(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $next = $this->nextFinalQuestion($gameSession, $state);

        if (!$next) {
            // Nothing left — every question was cleared in time, so it's a win.
            $stateData = $state->state_data ?? [];
            $stateData['phase'] = 'final_result';
            $stateData['final_result'] = 'win';
            $state->update(['state_data' => $stateData]);

            return response()->json(['success' => true, 'complete' => true]);
        }

        // Land on a per-question "Get Ready" beat (like a regular round's intro):
        // the host presses Show Question to bring up the plaque, then reveals to
        // start the clock. Keeps every final question from jumping straight in.
        $stateData = $state->state_data ?? [];
        $stateData['phase'] = 'final_ready';
        $state->update(['current_question_id' => $next->id, 'state_data' => $stateData]);

        return response()->json(['success' => true, 'complete' => false]);
    }

    /**
     * Use the one allowed skip: bank the clock, park the current question for a
     * later revisit, and jump to the next unanswered question, showing its plaque
     * only so the host reads it before revealing ("final_question").
     */
    public function finalSkip(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $currentQuestion = $state?->currentQuestion;

        if (!$currentQuestion || ($currentQuestion->segment ?? 'main') !== 'final') {
            return response()->json(['error' => 'No active final question'], 400);
        }
        if ($state->getStateValue('final_skip_used')) {
            return response()->json(['error' => 'Skip already used'], 400);
        }

        // There must be another unanswered question to move to.
        $otherPending = $gameSession->sessionQuestions()
            ->where('segment', 'final')
            ->where('status', '!=', 'completed')
            ->where('id', '!=', $currentQuestion->id)
            ->orderBy('display_order')
            ->first();
        if (!$otherPending) {
            return response()->json(['error' => 'Nothing left to skip to'], 400);
        }

        // Bank the remaining time and record the skip.
        $state->update([
            'timer_started_at' => null,
            'timer_duration' => $state->getRemainingSeconds(),
            'current_question_id' => $otherPending->id,
        ]);
        $stateData = $state->state_data ?? [];
        $stateData['final_skip_used'] = true;
        $stateData['final_skipped_question_id'] = $currentQuestion->id;
        $stateData['phase'] = 'final_ready';
        $state->update(['state_data' => $stateData]);

        return response()->json(['success' => true]);
    }

    /**
     * The clock ran out before every answer was revealed — the team loses. Drops
     * into "review" mode on whatever question they were on: no clock, and the host
     * can reveal the missed answers one at a time and jump between the final
     * questions (finalSelect). The display shows a brief "Out of Time" flash first.
     */
    public function finalTimeout(GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $state = $gameSession->gameState;
        $state->update(['timer_started_at' => null, 'timer_duration' => 0]);

        $stateData = $state->state_data ?? [];
        $stateData['phase'] = 'final_review';
        $stateData['final_result'] = 'lose';
        $state->update(['state_data' => $stateData]);

        return response()->json(['success' => true]);
    }

    /**
     * Review mode — jump to a specific final question so the host can reveal its
     * answers. Only used once the clock has stopped (final_review).
     */
    public function finalSelect(Request $request, GameSession $gameSession)
    {
        if ($gameSession->host_user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'session_question_id' => 'required|exists:session_questions,id',
        ]);

        $sessionQuestion = $gameSession->sessionQuestions()
            ->where('id', $validated['session_question_id'])
            ->where('segment', 'final')
            ->first();

        if (!$sessionQuestion) {
            return response()->json(['error' => 'Not a final question in this game'], 404);
        }

        $gameSession->gameState->update(['current_question_id' => $sessionQuestion->id]);

        return response()->json(['success' => true]);
    }

    /**
     * Reveal one answer during the final round. No points are awarded; when the
     * question's last answer is revealed the clock auto-pauses (banking its time)
     * and the flow advances to the next question, or to a "win" if none remain.
     */
    /**
     * Reveal the tie-off answer on the board (no points, no team) and move to the
     * "tiebreaker_play" beat so the display shows it. Used when the host reveals
     * the answer to check the tied teams' guesses before declaring a winner.
     */
    protected function revealTiebreakerAnswer(GameState $state, SessionQuestion $currentQuestion, int $answerId)
    {
        if (!$currentQuestion->answerReveals()->where('answer_id', $answerId)->exists()) {
            $answer = $currentQuestion->question->answers()->find($answerId);
            if (!$answer) {
                return response()->json(['error' => 'Invalid answer'], 400);
            }
            $currentQuestion->answerReveals()->create([
                'answer_id' => $answerId,
                'team_id' => null,
                'revealed_at' => now(),
                'points_awarded' => 0,
            ]);
            $answer->recordReveal();
        }

        $state->setStateValue('phase', 'tiebreaker_play');

        return response()->json(['success' => true, 'points' => 0]);
    }

    protected function revealFinalAnswer(GameState $state, SessionQuestion $currentQuestion, int $answerId)
    {
        if ($currentQuestion->answerReveals()->where('answer_id', $answerId)->exists()) {
            return response()->json(['error' => 'Answer already revealed'], 400);
        }

        $answer = $currentQuestion->question->answers()->find($answerId);
        if (!$answer) {
            return response()->json(['error' => 'Invalid answer'], 400);
        }

        $currentQuestion->answerReveals()->create([
            'answer_id' => $answerId,
            'team_id' => null,
            'revealed_at' => now(),
            'points_awarded' => 0,
        ]);
        $answer->recordReveal();

        // In review mode (post-timeout) the host is just showing the missed
        // answers — no clock, no auto-advance. Mark the question done once every
        // answer is up, but stay on it so the host controls the navigation.
        if ($state->getStateValue('phase') !== 'final_play') {
            if ($currentQuestion->answerReveals()->count() >= $currentQuestion->question->answers()->count()) {
                $currentQuestion->update(['status' => 'completed']);
            }
            return response()->json(['success' => true, 'points' => 0]);
        }

        // Not all answers in yet — keep playing.
        $total = $currentQuestion->question->answers()->count();
        if ($currentQuestion->answerReveals()->count() < $total) {
            return response()->json(['success' => true, 'points' => 0]);
        }

        // Question cleared: mark it done, then decide where to go next.
        $currentQuestion->update(['status' => 'completed']);

        $next = $this->nextFinalQuestion($state->gameSession, $state);
        $stateData = $state->state_data ?? [];

        // Clearing a board ALWAYS pauses (banks) the clock — the team then gets to
        // re-read the next question before it resumes. This holds for the parked
        // (skipped) question too: looping back to it doesn't keep the clock running
        // or drop straight in; it goes through Get Ready like every other question.
        $state->update([
            'timer_started_at' => null,
            'timer_duration' => $state->getRemainingSeconds(),
        ]);

        if ($next) {
            // Stay on the just-cleared question so its revealed answers remain on
            // the board (no "Get Ready" flash). The host advances with finalNext
            // when ready, which lands on the next question's Get Ready beat.
            $stateData['phase'] = 'final_cleared';
            $state->update(['state_data' => $stateData]);
        } else {
            // Every final question cleared before time ran out — the team wins.
            $stateData['phase'] = 'final_result';
            $stateData['final_result'] = 'win';
            $state->update(['state_data' => $stateData]);
        }

        return response()->json(['success' => true, 'points' => 0]);
    }

    /**
     * The next final question to play: the first unanswered one in order,
     * skipping the parked question until every other question is done, then
     * serving the parked one last (the revisit). Null when the final is complete.
     */
    protected function nextFinalQuestion(GameSession $gameSession, GameState $state): ?SessionQuestion
    {
        $pending = $gameSession->sessionQuestions()
            ->where('segment', 'final')
            ->where('status', '!=', 'completed')
            ->orderBy('display_order')
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        $skippedId = $state->getStateValue('final_skipped_question_id');
        $next = $pending->first(fn ($q) => $q->id !== $skippedId);

        // Only the parked question remains — bring it back for the revisit.
        return $next ?? $pending->first();
    }
}

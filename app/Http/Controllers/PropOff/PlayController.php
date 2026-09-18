<?php

namespace App\Http\Controllers\PropOff;

use App\Http\Controllers\Controller;

use App\Models\PropOff\Entry;
use App\Models\PropOff\EventAnswer;
use App\Models\PropOff\Group;
use App\Models\PropOff\GroupQuestionAnswer;
use App\Models\PropOff\Leaderboard;
use App\Models\User;
use App\Models\PropOff\UserAnswer;
use App\Services\PropOff\EntryService;
use App\Services\PropOff\ParticipantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PlayController extends Controller
{
    protected EntryService $entryService;

    public function __construct(EntryService $entryService)
    {
        $this->entryService = $entryService;
    }
    /**
     * Display the Play Hub for a group.
     */
    public function hub(string $code)
    {
        $group = Group::where('code', $code)->firstOrFail();
        $group->load(['event']);

        // Check if user is recognized (authenticated or has guest cookie)
        $user = auth()->user();

        if (!$user) {
            // Redirect to join form if not recognized
            return redirect()->route('propoff.play.join', ['code' => $code]);
        }

        // Check if user is a member of this group
        $isMember = $group->users()->where('user_id', $user->id)->exists();

        if (!$isMember) {
            // Redirect to join form to join the group
            return redirect()->route('propoff.play.join', ['code' => $code]);
        }

        // Check if user is a captain
        $isCaptain = $user->isCaptainOf($group->id);
        $isGuest = $user->isGuest();

        // Get group stats
        $stats = $this->getGroupStats($group);

        // Get user's entry status
        $myEntry = $this->getMyEntryStatus($group, $user);

        // Get game status
        $gameStatus = [
            'is_locked' => $group->is_locked,
            'close_time' => $group->entry_cutoff ?? $group->event?->lock_date,
            'event_start' => $group->event?->event_date,
        ];

        // Get leaderboard preview (top 5 + user's row)
        $leaderboard = $this->getLeaderboardPreview($group, $user);

        return Inertia::render('PropOff/Play/Hub', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'description' => $group->description,
                'grading_source' => $group->grading_source,
                'entry_cutoff' => $group->entry_cutoff,
                'is_locked' => $group->is_locked,
                'event' => $group->event ? [
                    'id' => $group->event->id,
                    'name' => $group->event->name,
                    'event_date' => $group->event->event_date,
                    'status' => $group->event->status,
                ] : null,
            ],
            'stats' => $stats,
            'myEntry' => $myEntry,
            'gameStatus' => $gameStatus,
            'leaderboard' => $leaderboard,
            'isCaptain' => $isCaptain,
            'isGuest' => $isGuest,
        ]);
    }

    /**
     * Show the join form for guests.
     * Authenticated users are auto-added to the group.
     */
    public function joinForm(string $code)
    {
        $group = Group::where('code', $code)
            ->with('event')
            ->firstOrFail();

        // If user is already logged in
        if (auth()->check()) {
            $user = auth()->user();
            $isMember = $group->users()->where('user_id', $user->id)->exists();

            if ($isMember) {
                // Already a member - go to hub
                return redirect()->route('propoff.play.hub', ['code' => $code]);
            }

            // Not a member - auto-add them to the group
            $group->users()->attach($user->id, [
                'joined_at' => now(),
                'is_captain' => false,
            ]);

            return redirect()->route('propoff.play.hub', ['code' => $code])
                ->with('success', 'You joined ' . $group->name . '!');
        }

        // Guest user - show the join form
        return Inertia::render('PropOff/Play/Join', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'event' => $group->event ? [
                    'id' => $group->event->id,
                    'name' => $group->event->name,
                    'event_date' => $group->event->event_date,
                ] : null,
            ],
            'step' => 'name',
            'existingNames' => [],
            'verifyEntry' => null,
        ]);
    }

    /**
     * Process the join request.
     */
    public function processJoin(Request $request, string $code, ParticipantResolver $resolver)
    {
        $group = Group::where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'verified' => 'nullable|boolean',
            'email' => 'nullable|email|max:255',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $name = trim($validated['name']);
        $email = $validated['email'] ?? null;
        $password = $validated['password'] ?? null;

        // Name match across this group AND the groups it continues from, so a
        // player returning for a new year's event is recognised rather than
        // registered again. Shares its implementation with the invitation flow
        // in GuestController — this used to be a second, narrower copy.
        $match = $resolver->findCandidateInLineage($name, $group);
        $exactMatch = $match['user'] ?? null;

        // If verified, link to existing user immediately
        if ($exactMatch && ($validated['verified'] ?? false)) {
            $resolver->attachTo($exactMatch, $group);

            return $this->linkGuestAndProceed($exactMatch, $group, $code);
        }

        // If exact name match and not verified, show verification
        if ($exactMatch) {
            return back()->with([
                'step' => 'verify',
                'verifyEntry' => $resolver->candidateSummary($exactMatch, $match['group'], $group),
            ]);
        }

        // Check if email already exists (returning user with different name)
        if ($email) {
            $existingUser = $resolver->findByEmail($email);

            if ($existingUser) {
                // Update name if different and add to group
                if ($existingUser->name !== $name) {
                    $existingUser->update(User::splitName($name));
                }

                $resolver->attachTo($existingUser, $group);

                Auth::login($existingUser);

                return redirect()->route('propoff.play.hub', ['code' => $code])
                    ->with('success', 'Welcome back, ' . $existingUser->name . '!');
            }
        }

        // No match - create new user and join group
        return $this->createGuestAndJoin($name, $email, $password, $group, $code);
    }

    /**
     * Display the questions page for answering.
     */
    public function questions(Request $request, string $code)
    {
        $group = Group::where('code', $code)->with('event')->firstOrFail();
        $currentUser = auth()->user();

        if (!$currentUser) {
            return redirect()->route('propoff.play.join', ['code' => $code]);
        }

        // Check if current user is a member
        if (!$group->users()->where('user_id', $currentUser->id)->exists()) {
            return redirect()->route('propoff.play.join', ['code' => $code]);
        }

        // Determine target user (self or someone else if captain)
        $targetUser = $currentUser;
        $submittingFor = null;

        if ($request->has('for_user') && $request->for_user != $currentUser->id) {
            // Check if current user is a captain of this group
            if (!$currentUser->isCaptainOf($group->id)) {
                abort(403, 'Only captains can submit for other users.');
            }

            // Get target user and verify they're in this group
            $targetUser = User::findOrFail($request->for_user);
            if (!$group->users()->where('user_id', $targetUser->id)->exists()) {
                abort(404, 'User is not a member of this group.');
            }

            $submittingFor = [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
            ];
        }

        // Get or create entry for target user
        $entry = Entry::where('user_id', $targetUser->id)
            ->where('group_id', $group->id)
            ->first();

        if (!$entry) {
            // Don't create new entries if group is locked
            if ($group->is_locked) {
                return redirect()->route('propoff.play.hub', ['code' => $code])
                    ->with('error', 'This game is locked. New entries cannot be created.');
            }

            $entry = Entry::create([
                'event_id' => $group->event_id,
                'user_id' => $targetUser->id,
                'group_id' => $group->id,
                'total_score' => 0,
                'possible_points' => $this->entryService->calculateTheoreticalMax($group),
                'percentage' => 0,
            ]);

            // Create leaderboard entry immediately so new members appear on leaderboard
            $this->updateLeaderboard($entry);
        }

        // Get leaderboard entry for rank (for results display)
        $leaderboardEntry = Leaderboard::where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->where('user_id', $targetUser->id)
            ->first();

        $totalParticipants = Leaderboard::where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->count();

        // Pre-fetch answer distribution if group is locked (for showing pick counts)
        $answerDistribution = [];
        if ($group->is_locked) {
            $answerDistribution = $this->getAnswerDistribution($group);
        }

        // Load questions with user's answers and results data
        $questions = $group->groupQuestions()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->map(function ($question) use ($entry, $group, $answerDistribution) {
                $userAnswer = $entry->userAnswers()
                    ->where('group_question_id', $question->id)
                    ->first();

                // Get correct answer based on grading source (for results display)
                $correctAnswer = null;
                $isVoid = false;

                if ($group->grading_source === 'captain') {
                    $groupAnswer = GroupQuestionAnswer::where('group_id', $group->id)
                        ->where('group_question_id', $question->id)
                        ->first();
                    $correctAnswer = $groupAnswer?->correct_answer;
                    $isVoid = $groupAnswer?->is_void ?? false;
                } else {
                    // Admin grading
                    if ($question->event_question_id) {
                        $eventAnswer = \App\Models\PropOff\EventAnswer::where('event_id', $group->event_id)
                            ->where('event_question_id', $question->event_question_id)
                            ->first();
                        $correctAnswer = $eventAnswer?->correct_answer;
                        $isVoid = $eventAnswer?->is_void ?? false;
                    }
                }

                return [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'options' => $question->options,
                    'points' => $question->points,
                    'user_answer' => $userAnswer?->answer_text,
                    'correct_answer' => $correctAnswer,
                    'is_void' => $isVoid,
                    'points_earned' => $userAnswer?->points_earned ?? 0,
                    'is_correct' => $userAnswer?->is_correct ?? false,
                    'answer_distribution' => $answerDistribution[$question->id] ?? [],
                ];
            });

        $answeredCount = $entry->userAnswers()->count();

        return Inertia::render('PropOff/Play/Game', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'is_locked' => $group->is_locked,
            ],
            'event' => $group->event ? [
                'id' => $group->event->id,
                'name' => $group->event->name,
                'description' => $group->event->description,
            ] : null,
            'entry' => [
                'id' => $entry->id,
                'answered_count' => $answeredCount,
                'total_questions' => $questions->count(),
                'total_score' => $entry->total_score,
                'possible_points' => $entry->possible_points,
                'rank' => $leaderboardEntry?->rank,
                'total_participants' => $totalParticipants,
            ],
            'questions' => $questions,
            'submittingFor' => $submittingFor,
            'isGuest' => $currentUser->isGuest(),
        ]);
    }

    /**
     * Save answers for an entry.
     */
    public function saveAnswers(Request $request, string $code)
    {
        $group = Group::where('code', $code)->firstOrFail();
        $currentUser = auth()->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Determine target user (self or someone else if captain)
        $targetUserId = $currentUser->id;

        if ($request->has('for_user') && $request->for_user != $currentUser->id) {
            // Check if current user is a captain of this group
            if (!$currentUser->isCaptainOf($group->id)) {
                abort(403, 'Only captains can submit for other users.');
            }

            // Verify target user is in this group
            if (!$group->users()->where('user_id', $request->for_user)->exists()) {
                abort(404, 'User is not a member of this group.');
            }

            $targetUserId = $request->for_user;
        }

        // Check if group is locked
        if ($group->is_locked) {
            return back()->with('error', 'This game is locked. You can no longer submit answers.');
        }

        $entry = Entry::where('user_id', $targetUserId)
            ->where('group_id', $group->id)
            ->first();

        if (!$entry) {
            return back()->with('error', 'No entry found.');
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*.group_question_id' => 'required|exists:propoff_group_questions,id',
            'answers.*.answer_text' => 'required|string',
        ]);

        DB::transaction(function () use ($entry, $validated, $group) {
            foreach ($validated['answers'] as $answerData) {
                // Get the group question
                $groupQuestion = $group->groupQuestions()
                    ->findOrFail($answerData['group_question_id']);

                $isCorrect = false;
                $pointsEarned = 0;

                // Check if there's a correct answer set
                $groupAnswer = GroupQuestionAnswer::where('group_id', $group->id)
                    ->where('group_question_id', $groupQuestion->id)
                    ->first();

                if ($groupAnswer && !$groupAnswer->is_void) {
                    $isCorrect = strcasecmp(
                        trim($answerData['answer_text']),
                        trim($groupAnswer->correct_answer)
                    ) === 0;

                    $pointsEarned = $isCorrect ? $groupQuestion->points : 0;
                }

                UserAnswer::updateOrCreate(
                    [
                        'entry_id' => $entry->id,
                        'group_question_id' => $answerData['group_question_id'],
                    ],
                    [
                        'answer_text' => $answerData['answer_text'],
                        'points_earned' => $pointsEarned,
                        'is_correct' => $isCorrect,
                    ]
                );
            }

            // Update entry totals
            $totalScore = $entry->userAnswers()->sum('points_earned');
            $percentage = $entry->possible_points > 0
                ? ($totalScore / $entry->possible_points) * 100
                : 0;

            $entry->update([
                'total_score' => $totalScore,
                'percentage' => $percentage,
            ]);
        });

        // Update leaderboard
        $this->updateLeaderboard($entry->fresh());

        return back()->with('success', 'Answers saved!');
    }

    /**
     * Display full leaderboard.
     */
    public function leaderboard(string $code)
    {
        $group = Group::where('code', $code)
            ->with('event')
            ->firstOrFail();

        $user = auth()->user();

        $leaderboard = Leaderboard::with('user')
            ->where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->orderBy('rank')
            ->paginate(50);

        // Get current user's row if logged in
        $userRow = null;
        if ($user) {
            $userLeaderboard = Leaderboard::where('event_id', $group->event_id)
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->first();

            if ($userLeaderboard) {
                $userRow = [
                    'rank' => $userLeaderboard->rank,
                    'name' => $user->name,
                    'total_score' => $userLeaderboard->total_score,
                    'possible_points' => $userLeaderboard->possible_points,
                    'user_id' => $user->id,
                ];
            } else {
                // Fall back to Entry if not on leaderboard yet
                $entry = Entry::where('event_id', $group->event_id)
                    ->where('group_id', $group->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($entry) {
                    // Calculate approximate rank based on score
                    $rank = Leaderboard::where('event_id', $group->event_id)
                        ->where('group_id', $group->id)
                        ->where('total_score', '>', $entry->total_score)
                        ->count() + 1;

                    $userRow = [
                        'rank' => $rank,
                        'name' => $user->name,
                        'total_score' => $entry->total_score,
                        'possible_points' => $entry->possible_points,
                        'user_id' => $user->id,
                    ];
                }
            }
        }

        return Inertia::render('PropOff/Play/Leaderboard', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'is_locked' => $group->is_locked,
                'event' => [
                    'id' => $group->event->id,
                    'name' => $group->event->name,
                ],
            ],
            'leaderboard' => $leaderboard,
            'userRow' => $userRow,
        ]);
    }

    /**
     * Get group statistics.
     */
    protected function getGroupStats(Group $group): array
    {
        $totalQuestions = $group->groupQuestions()->where('is_active', true)->count();
        $totalMembers = $group->users()->count();
        $totalPoints = $this->entryService->calculateTheoreticalMax($group);

        // Count graded questions based on grading source
        if ($group->grading_source === 'captain') {
            $gradedQuestions = $group->groupQuestionAnswers()->count();
        } else {
            // Admin grading: count event answers for questions linked to this group
            $eventQuestionIds = $group->groupQuestions()
                ->where('is_active', true)
                ->whereNotNull('event_question_id')
                ->pluck('event_question_id');

            $gradedQuestions = EventAnswer::where('event_id', $group->event_id)
                ->whereIn('event_question_id', $eventQuestionIds)
                ->count();
        }

        return [
            'total_questions' => $totalQuestions,
            'graded_questions' => $gradedQuestions,
            'total_members' => $totalMembers,
            'total_points' => $totalPoints,
        ];
    }

    /**
     * Get current user's entry status.
     */
    protected function getMyEntryStatus(Group $group, User $user): ?array
    {
        $entry = Entry::where('user_id', $user->id)
            ->where('group_id', $group->id)
            ->first();

        $totalQuestions = $group->groupQuestions()->where('is_active', true)->count();

        if (!$entry) {
            return [
                'id' => null,
                'status' => 'not_started',
                'answered_count' => 0,
                'total_questions' => $totalQuestions,
                'score' => null,
                'rank' => null,
                'total_participants' => 0,
            ];
        }

        $answeredCount = $entry->userAnswers()->count();

        // Get leaderboard entry for rank
        $leaderboardEntry = Leaderboard::where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->first();

        $totalParticipants = Leaderboard::where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->count();

        // Determine status: submitted if complete, in_progress if has answers, otherwise not_started
        $status = $entry->is_complete
            ? 'submitted'
            : ($answeredCount > 0 ? 'in_progress' : 'not_started');

        return [
            'id' => $entry->id,
            'status' => $status,
            'answered_count' => $answeredCount,
            'total_questions' => $totalQuestions,
            'score' => $entry->is_complete ? $entry->total_score : null,
            'rank' => $leaderboardEntry?->rank,
            'total_participants' => $totalParticipants,
        ];
    }

    /**
     * Get leaderboard preview (top 5 + user's row).
     */
    protected function getLeaderboardPreview(Group $group, User $user): array
    {
        $top5 = Leaderboard::with('user:id,first_name,last_name')
            ->where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->orderBy('rank')
            ->limit(5)
            ->get()
            ->map(fn($entry) => [
                'rank' => $entry->rank,
                'name' => $entry->user->name,
                'score' => $entry->total_score,
                'user_id' => $entry->user_id,
            ]);

        // Get user's row if not in top 5
        $userRow = null;
        $userLeaderboard = Leaderboard::where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->first();

        if ($userLeaderboard && !$top5->contains('user_id', $user->id)) {
            $userRow = [
                'rank' => $userLeaderboard->rank,
                'name' => $user->name,
                'score' => $userLeaderboard->total_score,
                'user_id' => $user->id,
            ];
        }

        $totalParticipants = Leaderboard::where('event_id', $group->event_id)
            ->where('group_id', $group->id)
            ->count();

        return [
            'top5' => $top5,
            'userRow' => $userRow,
            'totalParticipants' => $totalParticipants,
            'currentUserId' => $user->id,
        ];
    }

    /**
     * Create a new user and join the group.
     */
    protected function createGuestAndJoin(string $name, ?string $email, ?string $password, Group $group, string $code)
    {
        $user = app(ParticipantResolver::class)->createGuest($name, $email, $password);
        $guestToken = $user->guest_token;

        app(ParticipantResolver::class)->attachTo($user, $group);

        // Login the user
        Auth::login($user);

        // Set cookie and redirect (only for guests without password)
        $redirect = redirect()->route('propoff.play.hub', ['code' => $code])
            ->with('success', 'Welcome to ' . $group->name . '!');

        if ($guestToken) {
            $redirect = $redirect->cookie('keeler_guest', $guestToken, 60 * 24 * 90); // 90 days
        }

        return $redirect;
    }

    /**
     * Link to existing guest user and proceed.
     */
    protected function linkGuestAndProceed(User $user, Group $group, string $code)
    {
        // Login the user
        Auth::login($user);

        // Set cookie if guest
        if ($user->isGuest() && $user->guest_token) {
            return redirect()->route('propoff.play.hub', ['code' => $code])
                ->with('success', 'Welcome back, ' . $user->name . '!')
                ->cookie('keeler_guest', $user->guest_token, 60 * 24 * 90);
        }

        return redirect()->route('propoff.play.hub', ['code' => $code])
            ->with('success', 'Welcome back, ' . $user->name . '!');
    }

    /**
     * Update leaderboard for an entry.
     */
    protected function updateLeaderboard(Entry $entry): void
    {
        $answeredCount = $entry->userAnswers()->count();

        // Calculate rank based only on total points
        $rank = Leaderboard::where('event_id', $entry->event_id)
            ->where('group_id', $entry->group_id)
            ->where('total_score', '>', $entry->total_score)
            ->count() + 1;

        Leaderboard::updateOrCreate(
            [
                'event_id' => $entry->event_id,
                'user_id' => $entry->user_id,
                'group_id' => $entry->group_id,
            ],
            [
                'rank' => $rank,
                'total_score' => $entry->total_score,
                'possible_points' => $entry->possible_points,
                'percentage' => $entry->percentage,
                'answered_count' => $answeredCount,
            ]
        );

        // Recalculate all ranks
        $this->recalculateRanks($entry->event_id, $entry->group_id);
    }

    /**
     * Recalculate ranks for all users in a group.
     * Handles ties: entries with the same total_score get the same rank.
     * Rank is based ONLY on total points.
     */
    protected function recalculateRanks(int $eventId, int $groupId): void
    {
        // Get all leaderboard entries ordered by total score only
        $leaderboards = Leaderboard::where('event_id', $eventId)
            ->where('group_id', $groupId)
            ->orderBy('total_score', 'desc')
            ->get();

        $currentRank = 1;
        $previousScore = null;

        foreach ($leaderboards as $index => $leaderboard) {
            // Check if this entry ties with the previous one (same score = same rank)
            if ($previousScore !== null && $previousScore === $leaderboard->total_score) {
                // Same rank as previous (tie)
            } else {
                // New rank = position + 1
                $currentRank = $index + 1;
            }

            $leaderboard->update(['rank' => $currentRank]);
            $previousScore = $leaderboard->total_score;
        }
    }

    /**
     * Get answer distribution for all questions in a group.
     * Returns an array keyed by question ID, with each option's count and user names.
     */
    protected function getAnswerDistribution(Group $group): array
    {
        // Get all user answers for this group with user names
        $answers = UserAnswer::select(
                'propoff_user_answers.group_question_id',
                'propoff_user_answers.answer_text',
                'users.first_name',
                'users.last_name'
            )
            ->join('propoff_entries', 'propoff_entries.id', '=', 'propoff_user_answers.entry_id')
            ->join('users', 'users.id', '=', 'propoff_entries.user_id')
            ->where('propoff_entries.group_id', $group->id)
            ->whereNotNull('propoff_user_answers.answer_text')
            ->get();

        // Group by question_id -> answer_text -> list of user names
        $distribution = [];
        foreach ($answers as $answer) {
            $questionId = $answer->group_question_id;
            $answerText = $answer->answer_text;

            if (!isset($distribution[$questionId])) {
                $distribution[$questionId] = [];
            }

            if (!isset($distribution[$questionId][$answerText])) {
                $distribution[$questionId][$answerText] = [
                    'count' => 0,
                    'users' => [],
                ];
            }

            $distribution[$questionId][$answerText]['count']++;
            $distribution[$questionId][$answerText]['users'][] = trim($answer->first_name . ' ' . $answer->last_name);
        }

        return $distribution;
    }
}

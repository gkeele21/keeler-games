<?php

namespace Tests\Feature\Host;

use App\Models\Answer;
use App\Models\AnswerReveal;
use App\Models\GameSession;
use App\Models\GameState;
use App\Models\GameType;
use App\Models\Question;
use App\Models\SessionQuestion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * revealAnswer looked the answer up and then used it in three branches without
 * ever checking it was found. A mismatched answer_id — a stale client, a
 * double-tap after the host advanced — wrote the AnswerReveal row first and
 * only then hit the null, so the 500 left an orphan reveal on the board.
 *
 * revealFinalAnswer and the tiebreaker path already guarded this; the main
 * path was the one that did not.
 */
class RevealAnswerTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $host = User::factory()->create();
        $gameType = GameType::create([
            'name' => 'America Says', 'slug' => 'america-says', 'kind' => 'online',
            'display_order' => 1, 'is_global' => false,
        ]);

        $session = GameSession::create([
            'game_type_id' => $gameType->id, 'host_user_id' => $host->id,
            'status' => 'playing', 'settings' => [],
        ]);
        $team = Team::create([
            'game_id' => $session->id, 'name' => 'Team A',
            'color' => '#EF4444', 'display_order' => 1,
        ]);

        $onScreen = Question::create(['game_type_id' => $gameType->id, 'question_text' => 'On screen']);
        $elsewhere = Question::create(['game_type_id' => $gameType->id, 'question_text' => 'A different question']);

        $ownAnswer = Answer::create(['question_id' => $onScreen->id, 'answer_text' => 'Right', 'points' => 10, 'display_order' => 1]);
        $strayAnswer = Answer::create(['question_id' => $elsewhere->id, 'answer_text' => 'Wrong', 'points' => 10, 'display_order' => 1]);

        $sessionQuestion = SessionQuestion::create([
            'game_session_id' => $session->id, 'question_id' => $onScreen->id,
            'display_order' => 1, 'status' => 'active', 'segment' => 'main',
            'points_available' => 10,
        ]);
        GameState::create([
            'game_session_id' => $session->id, 'current_question_id' => $sessionQuestion->id,
            'active_team_id' => $team->id, 'round_number' => 1, 'timer_duration' => 30,
        ]);

        return compact('host', 'session', 'team', 'ownAnswer', 'strayAnswer');
    }

    public function test_revealing_an_answer_from_another_question_is_rejected(): void
    {
        ['host' => $host, 'session' => $session, 'team' => $team, 'strayAnswer' => $stray] = $this->scenario();

        $this->actingAs($host)
            ->postJson(route('host.reveal', $session), ['answer_id' => $stray->id, 'team_id' => $team->id])
            ->assertStatus(400);
    }

    public function test_a_rejected_reveal_leaves_no_orphan_row(): void
    {
        ['host' => $host, 'session' => $session, 'team' => $team, 'strayAnswer' => $stray] = $this->scenario();

        $this->actingAs($host)
            ->postJson(route('host.reveal', $session), ['answer_id' => $stray->id, 'team_id' => $team->id]);

        // The original bug: the row was written before the null was noticed, so
        // a 500 still left the answer showing on the board.
        $this->assertSame(0, AnswerReveal::count());
    }

    public function test_a_valid_reveal_still_works(): void
    {
        ['host' => $host, 'session' => $session, 'team' => $team, 'ownAnswer' => $answer] = $this->scenario();

        $this->actingAs($host)
            ->postJson(route('host.reveal', $session), ['answer_id' => $answer->id, 'team_id' => $team->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, AnswerReveal::count());
        $this->assertSame($team->id, AnswerReveal::first()->team_id);
    }

    public function test_a_valid_reveal_scores_the_team(): void
    {
        ['host' => $host, 'session' => $session, 'team' => $team, 'ownAnswer' => $answer] = $this->scenario();

        $this->actingAs($host)
            ->postJson(route('host.reveal', $session), ['answer_id' => $answer->id, 'team_id' => $team->id]);

        $this->assertSame(10, $team->fresh()->total_score);
    }
}

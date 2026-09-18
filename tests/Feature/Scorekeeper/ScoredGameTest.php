<?php

namespace Tests\Feature\Scorekeeper;

use App\Models\GameType;
use App\Models\Scorekeeper\GameTemplate;
use App\Models\Scorekeeper\Household;
use App\Models\Scorekeeper\ScoredGame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScoredGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_individual_scoring_flow(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $gameType = GameType::create([
            'name' => 'Rummy 500', 'slug' => 'rummy-500', 'kind' => 'scorekeeper',
        ]);
        $template = GameTemplate::create([
            'name' => 'Rummy', 'household_id' => $household->id, 'target_score' => 500,
            'low_score_wins' => false, 'is_system' => false, 'team_based' => false,
            'game_type_id' => $gameType->id,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'player_ids' => [$alice->id, $bob->id]],
        )->assertRedirect();

        $game = ScoredGame::firstOrFail();
        // The game's base_game_type is snapshotted from the game type name.
        $this->assertSame('Rummy 500', $game->base_game_type);
        $competitors = $game->competitors()->orderBy('display_order')->get();
        $this->assertCount(2, $competitors);
        $this->assertSame('Alice', $competitors[0]->name);
        $this->assertDatabaseHas('competitor_player', [
            'competitor_id' => $competitors[0]->id, 'player_id' => $alice->id,
        ]);

        // A new game starts with round 1 already created.
        $this->assertSame(1, $game->rounds()->count());
        $round = $game->rounds()->firstOrFail();

        $this->actingAs($user)->patch(
            route('scorekeeper.games.rounds.update', [$game, $round]),
            ['scores' => [
                $competitors[0]->id => ['score' => 300],
                $competitors[1]->id => ['score' => 120],
            ]],
        )->assertRedirect();
        $this->assertDatabaseHas('round_scores', [
            'round_id' => $round->id, 'competitor_id' => $competitors[0]->id,
        ]);

        $this->actingAs($user)
            ->get(route('scorekeeper.games.show', $game))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scorekeeper/ScoredGames/Show')
                ->where('completionMet', false)
                ->where('totals.'.$competitors[0]->id, 300)
                ->where('standings.0.name', 'Alice'));

        $this->actingAs($user)->post(route('scorekeeper.games.complete', $game))->assertRedirect();
        $this->assertDatabaseHas('games', ['id' => $game->id, 'is_complete' => true]);
    }

    public function test_multi_field_total_sums_only_counting_fields(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $template = GameTemplate::create([
            'name' => 'Pony Tail', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false, 'team_based' => false,
            'score_fields' => [
                ['key' => 'base', 'label' => 'Base', 'counts_toward_total' => false],
                ['key' => 'points', 'label' => 'Points', 'counts_toward_total' => true],
            ],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'player_ids' => [$alice->id, $bob->id]],
        );
        $game = ScoredGame::firstOrFail();
        $competitors = $game->competitors()->orderBy('display_order')->get();

        // A new game starts with round 1 already created.
        $round = $game->rounds()->firstOrFail();
        $this->actingAs($user)->patch(
            route('scorekeeper.games.rounds.update', [$game, $round]),
            ['scores' => [
                $competitors[0]->id => ['base' => 100, 'points' => 10],
                $competitors[1]->id => ['base' => 5, 'points' => 20],
            ]],
        );

        $this->actingAs($user)
            ->get(route('scorekeeper.games.show', $game))
            ->assertInertia(fn (Assert $page) => $page
                // total = points only (base excluded)
                ->where('totals.'.$competitors[0]->id, 10)
                ->where('totals.'.$competitors[1]->id, 20)
                // but base is still tracked as a subtotal
                ->where('fieldSubtotals.'.$competitors[0]->id.'.base', 100)
                ->where('standings.0.name', 'Bob'));
    }

    public function test_team_game_creates_team_competitors_and_scores_per_team(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $p = collect(['Ann', 'Bo', 'Cy', 'Di'])->map(
            fn ($n) => $household->players()->create(['name' => $n]),
        );
        $template = GameTemplate::create([
            'name' => 'Teams', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false, 'team_based' => true,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'teams' => [
                ['name' => 'Reds', 'player_ids' => [$p[0]->id, $p[1]->id]],
                ['name' => 'Blues', 'player_ids' => [$p[2]->id, $p[3]->id]],
            ]],
        )->assertRedirect();

        $game = ScoredGame::firstOrFail();
        $this->assertTrue($game->team_based);
        $competitors = $game->competitors()->orderBy('display_order')->get();
        $this->assertSame(['Reds', 'Blues'], $competitors->pluck('name')->all());
        $this->assertDatabaseHas('competitor_player', [
            'competitor_id' => $competitors[0]->id, 'player_id' => $p[0]->id,
        ]);

        // A new game starts with round 1 already created.
        $round = $game->rounds()->firstOrFail();
        $this->actingAs($user)->patch(
            route('scorekeeper.games.rounds.update', [$game, $round]),
            ['scores' => [
                $competitors[0]->id => ['score' => 30],
                $competitors[1]->id => ['score' => 10],
            ]],
        );

        $this->actingAs($user)
            ->get(route('scorekeeper.games.show', $game))
            ->assertInertia(fn (Assert $page) => $page
                ->where('standings.0.name', 'Reds')
                ->where('totals.'.$competitors[0]->id, 30));
    }

    public function test_team_store_rejects_a_player_on_two_teams(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $a = $household->players()->create(['name' => 'A']);
        $b = $household->players()->create(['name' => 'B']);
        $template = GameTemplate::create([
            'name' => 'Teams', 'household_id' => $household->id, 'is_system' => false,
            'team_based' => true,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'teams' => [
                ['name' => 'Reds', 'player_ids' => [$a->id]],
                ['name' => 'Blues', 'player_ids' => [$a->id, $b->id]],
            ]],
        )->assertStatus(422);
    }

    public function test_game_can_be_deleted_and_cascades(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $template = GameTemplate::create([
            'name' => 'Rummy', 'household_id' => $household->id, 'is_system' => false,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'player_ids' => [$alice->id, $bob->id]],
        );
        $game = ScoredGame::firstOrFail();
        // A new game starts with round 1 already created.
        $round = $game->rounds()->firstOrFail();

        $this->actingAs($user)
            ->delete(route('scorekeeper.games.destroy', $game))
            ->assertRedirect(route('scorekeeper.households.games.index', $household->id));

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
        $this->assertDatabaseMissing('rounds', ['id' => $round->id]);
        $this->assertDatabaseMissing('competitors', ['game_id' => $game->id]);
    }

    public function test_scorecard_shows_first_names_disambiguated_by_last_initials(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        // Unique first name → first name only. Same first name → extend the
        // last name letter by letter until distinct (Keele vs Kane → Ke/Ka).
        $tara = $household->players()->create(['name' => 'Tara Smith']);
        $g1 = $household->players()->create(['name' => 'Grant Keele']);
        $g2 = $household->players()->create(['name' => 'Grant Kane']);
        $template = GameTemplate::create([
            'name' => 'T', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'player_ids' => [$tara->id, $g1->id, $g2->id]],
        );
        $game = ScoredGame::firstOrFail();

        $this->actingAs($user)
            ->get(route('scorekeeper.games.show', $game))
            ->assertInertia(fn (Assert $page) => $page
                ->where('competitors.0.name', 'Tara')
                ->where('competitors.1.name', 'Grant Ke')
                ->where('competitors.2.name', 'Grant Ka'));
    }

    public function test_game_can_be_backdated_with_a_play_date(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $template = GameTemplate::create([
            'name' => 'T', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            [
                'game_template_id' => $template->id,
                'player_ids'       => [$alice->id, $bob->id],
                'played_at'        => '2026-03-14',
            ],
        )->assertRedirect();

        $game = ScoredGame::firstOrFail();
        $this->assertSame('2026-03-14', $game->started_at->toDateString());
    }

    public function test_play_date_can_be_edited_by_scorer_only(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $template = GameTemplate::create([
            'name' => 'T', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);
        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'player_ids' => [$alice->id, $bob->id]],
        );
        $game = ScoredGame::firstOrFail();

        $this->actingAs($user)
            ->patch(route('scorekeeper.games.playdate.update', $game), [
                'played_at' => '2026-01-02',
            ])
            ->assertRedirect();
        $this->assertSame('2026-01-02', $game->fresh()->started_at->toDateString());

        // Guests can't rewrite history.
        $guest = User::factory()->create();
        $household->members()->attach($guest->id, ['role' => 'guest']);
        $this->actingAs($guest)
            ->patch(route('scorekeeper.games.playdate.update', $game), [
                'played_at' => '2026-02-03',
            ])
            ->assertForbidden();
    }

    public function test_save_all_saves_every_round_at_once(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $template = GameTemplate::create([
            'name' => 'T', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false, 'team_based' => false,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs($user)->post(
            route('scorekeeper.households.games.store', $household),
            ['game_template_id' => $template->id, 'player_ids' => [$alice->id, $bob->id]],
        );
        $game = ScoredGame::firstOrFail();
        $competitors = $game->competitors()->orderBy('display_order')->get();

        $this->actingAs($user)->post(route('scorekeeper.games.rounds.add', $game));
        [$round1, $round2] = $game->rounds()->orderBy('round_number')->get();

        $this->actingAs($user)
            ->patch(route('scorekeeper.games.scores.update', $game), [
                'rounds' => [
                    $round1->id => [
                        $competitors[0]->id => ['score' => 10],
                        $competitors[1]->id => ['score' => 20],
                    ],
                    $round2->id => [
                        $competitors[0]->id => ['score' => 30],
                        $competitors[1]->id => ['score' => 40],
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('round_scores', [
            'round_id' => $round1->id, 'competitor_id' => $competitors[0]->id,
        ]);
        $this->assertDatabaseHas('round_scores', [
            'round_id' => $round2->id, 'competitor_id' => $competitors[1]->id,
        ]);
        $this->assertSame(
            40,
            app(\App\Services\Scorekeeper\ScoreGameService::class)
                ->totals($game->fresh())[$competitors[0]->id],
        );
    }

    public function test_save_all_rejects_rounds_from_another_game(): void
    {
        $user = User::factory()->create();
        $household = $this->householdOwnedBy($user);
        $alice = $household->players()->create(['name' => 'Alice']);
        $bob = $household->players()->create(['name' => 'Bob']);
        $template = GameTemplate::create([
            'name' => 'T', 'household_id' => $household->id, 'is_system' => false,
            'low_score_wins' => false, 'team_based' => false,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        foreach ([1, 2] as $i) {
            $this->actingAs($user)->post(
                route('scorekeeper.households.games.store', $household),
                ['game_template_id' => $template->id, 'player_ids' => [$alice->id, $bob->id]],
            );
        }
        [$game, $otherGame] = ScoredGame::orderBy('id')->get();
        $foreignRound = $otherGame->rounds()->firstOrFail();
        $competitorId = $game->competitors()->first()->id;

        $this->actingAs($user)
            ->patch(route('scorekeeper.games.scores.update', $game), [
                'rounds' => [
                    $foreignRound->id => [$competitorId => ['score' => 99]],
                ],
            ])
            ->assertNotFound();
    }

    public function test_non_member_cannot_start_game(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $template = GameTemplate::create([
            'name' => 'Sys', 'is_system' => true,
            'score_fields' => [['key' => 'score', 'label' => 'Score', 'counts_toward_total' => true]],
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('scorekeeper.households.games.store', $household), [
                'game_template_id' => $template->id,
                'player_ids'       => [1, 2],
            ])
            ->assertForbidden();
    }

    private function householdOwnedBy(User $owner): Household
    {
        $household = Household::create(['name' => 'H', 'owner_user_id' => $owner->id]);
        $household->members()->attach($owner->id, ['role' => 'owner']);

        return $household;
    }
}

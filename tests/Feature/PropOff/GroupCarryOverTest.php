<?php

namespace Tests\Feature\PropOff;

use App\Models\PropOff\Event;
use App\Models\PropOff\EventInvitation;
use App\Models\PropOff\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PropOff runs once a year and its groups are per-event, so a fresh February
 * group starts empty. Without a lineage link the "is this you?" step can never
 * recognise a returning player, and everyone registers again — which is how
 * 136 users accumulated for roughly 120 people.
 *
 * These cover recognition across the year boundary, and the deliberate choice
 * NOT to pre-attach last year's roster.
 */
class GroupCarryOverTest extends TestCase
{
    use RefreshDatabase;

    /** Last year's group with one returning player, and this year's successor. */
    private function twoYears(): array
    {
        // Names are pinned on every factory-made user here: UserFactory uses
        // fake()->firstName() and will occasionally produce "Hazel" by itself,
        // which would break the assertions that count by name.
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);

        $lastYear = Group::factory()->create([
            'name'       => "Bert's Super Bowl Bash",
            'created_by' => $owner->id,
            'event_id'   => Event::factory()->create([
                'created_by' => $owner->id,
                'name'       => 'Super Bowl LX',
                'event_date' => now()->subYear(),
            ])->id,
        ]);

        $returning = User::factory()->create([
            'first_name' => 'Hazel',
            'last_name'  => 'Reed',
            'role'       => 'guest',
        ]);
        $lastYear->users()->attach($returning->id, ['joined_at' => now()->subYear(), 'is_captain' => false]);

        $thisYear = Group::factory()->create([
            'name'              => "Bert's Super Bowl Bash",
            'created_by'        => $owner->id,
            'previous_group_id' => $lastYear->id,
            'event_id'          => Event::factory()->create([
                'created_by' => $owner->id,
                'name'       => 'Super Bowl LXI',
                'event_date' => now(),
            ])->id,
        ]);

        $invitation = EventInvitation::factory()->create([
            'event_id'  => $thisYear->event_id,
            'group_id'  => $thisYear->id,
            'is_active' => true,
        ]);

        return [$lastYear, $thisYear, $invitation, $returning];
    }

    public function test_last_years_roster_is_not_pre_attached(): void
    {
        [$lastYear, $thisYear] = $this->twoYears();

        // Copying members forward would put people on the leaderboard with zero
        // answers before anyone has turned up.
        $this->assertSame(1, $lastYear->users()->count());
        $this->assertSame(0, $thisYear->users()->count());
    }

    public function test_a_returning_player_is_recognised_across_the_year(): void
    {
        [, , $invitation] = $this->twoYears();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Hazel Reed'])
            ->assertSessionHas('step', 'verify');

        $entry = session('verifyEntry');
        $this->assertTrue($entry['from_previous_group']);
        $this->assertSame("Bert's Super Bowl Bash", $entry['previous_group_name']);
        $this->assertSame('Super Bowl LX', $entry['previous_event_name']);

        // Recognised, not duplicated.
        $this->assertSame(1, User::where('first_name', 'Hazel')->count());
    }

    public function test_claiming_across_the_year_reuses_the_same_person(): void
    {
        [, $thisYear, $invitation, $returning] = $this->twoYears();

        $this->post(route('propoff.guest.register', $invitation->token), [
            'name'          => 'Hazel Reed',
            'claim_user_id' => $returning->id,
        ])->assertRedirect();

        // Same identity, now in this year's group — which is what carries their
        // leaderboard history forward.
        $this->assertSame(1, User::where('first_name', 'Hazel')->count());
        $this->assertSame($returning->id, auth()->id());
        $this->assertTrue($thisYear->fresh()->users()->where('users.id', $returning->id)->exists());
    }

    public function test_a_newcomer_this_year_is_unaffected(): void
    {
        [, $thisYear, $invitation] = $this->twoYears();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Brand New'])
            ->assertRedirect();

        $this->assertSame(1, User::where('first_name', 'Brand')->count());
        $this->assertSame(1, $thisYear->fresh()->users()->count());
    }

    public function test_a_claim_for_someone_outside_the_lineage_is_refused(): void
    {
        [, $thisYear, $invitation] = $this->twoYears();
        $stranger = User::factory()->create(['first_name' => 'Stranger', 'last_name' => 'Danger', 'role' => 'guest']);

        // A forged id must not hand over another account; it falls through to
        // the normal path and creates a distinct person instead.
        $this->post(route('propoff.guest.register', $invitation->token), [
            'name'          => 'Someone Else',
            'claim_user_id' => $stranger->id,
        ])->assertRedirect();

        $this->assertNotSame($stranger->id, auth()->id());
        $this->assertFalse($thisYear->fresh()->users()->where('users.id', $stranger->id)->exists());
    }

    public function test_lineage_walk_survives_a_cycle(): void
    {
        [$lastYear, $thisYear] = $this->twoYears();

        // Defensive: a bad link must not spin the join request forever.
        $lastYear->update(['previous_group_id' => $thisYear->id]);

        $chain = $thisYear->fresh()->lineage();
        $this->assertCount(2, $chain);
    }

    public function test_the_code_join_flow_also_recognises_across_the_year(): void
    {
        [, $thisYear, , $returning] = $this->twoYears();

        // The code-join entry point carried its own narrower copy of this logic
        // and only ever searched the current group. Both entry points now share
        // ParticipantResolver, so both reach back through the lineage.
        $this->post(route('propoff.play.join.process', ['code' => $thisYear->code]), [
            'name' => 'Hazel Reed',
        ])->assertSessionHas('step', 'verify');

        $entry = session('verifyEntry');
        $this->assertTrue($entry['from_previous_group']);
        $this->assertSame($returning->id, $entry['user_id']);
        $this->assertSame(1, User::where('first_name', 'Hazel')->count());
    }

    public function test_the_code_join_flow_attaches_the_claimed_person_to_the_new_group(): void
    {
        [, $thisYear, , $returning] = $this->twoYears();

        $this->post(route('propoff.play.join.process', ['code' => $thisYear->code]), [
            'name'     => 'Hazel Reed',
            'verified' => true,
        ])->assertRedirect();

        // The verified branch only logged them in — correct while a match could
        // only come from the current group, since they were already a member.
        // Widening the search to the lineage makes the attach necessary.
        $this->assertTrue($thisYear->fresh()->users()->where('users.id', $returning->id)->exists());
        $this->assertSame($returning->id, auth()->id());
        $this->assertSame(1, User::where('first_name', 'Hazel')->count());
    }
}

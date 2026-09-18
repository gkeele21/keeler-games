<?php

namespace Tests\Feature\PropOff;

use App\Models\PropOff\Entry;
use App\Models\PropOff\Event;
use App\Models\PropOff\Group;
use App\Models\PropOff\Leaderboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The claim step trades a little risk for a lot of convenience: it offers a
 * name match back and lets the person confirm it. With three different Megans
 * in the live data, somebody will eventually confirm someone else's entry.
 *
 * Splitting is scoped to a single group, which is what makes it safe — every
 * other year's history stays with the original person.
 */
class ClaimCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function groupWithMisclaim(): array
    {
        // Names are pinned on every factory-made user: UserFactory uses
        // fake()->firstName() and will occasionally produce "Megan" by itself,
        // which would break the assertions that look up the separated person
        // by name.
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);

        $lastYearEvent = Event::factory()->create(['event_date' => now()->subYear(), 'created_by' => $owner->id]);
        $lastYear = Group::factory()->create([
            'event_id'   => $lastYearEvent->id,
            'created_by' => $owner->id,
        ]);

        $thisYearEvent = Event::factory()->create(['event_date' => now(), 'created_by' => $owner->id]);
        $thisYear = Group::factory()->create([
            'event_id'          => $thisYearEvent->id,
            'created_by'        => $owner->id,
            'previous_group_id' => $lastYear->id,
        ]);

        $captain = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Captain', 'role' => 'user']);
        $thisYear->addCaptain($captain);

        // One Megan, who played last year and has been claimed again this year.
        $megan = User::factory()->create(['first_name' => 'Megan', 'last_name' => '', 'role' => 'guest']);
        $lastYear->users()->attach($megan->id, ['joined_at' => now()->subYear(), 'is_captain' => false]);
        $thisYear->users()->attach($megan->id, ['joined_at' => now(), 'is_captain' => false]);

        $lastEntry = Entry::factory()->create([
            'user_id'  => $megan->id,
            'group_id' => $lastYear->id,
            'event_id' => $lastYearEvent->id,
        ]);
        $thisEntry = Entry::factory()->create([
            'user_id'  => $megan->id,
            'group_id' => $thisYear->id,
            'event_id' => $thisYearEvent->id,
        ]);

        return compact('captain', 'megan', 'thisYear', 'lastYear', 'thisEntry', 'lastEntry');
    }

    public function test_separating_moves_this_groups_entry_to_a_new_person(): void
    {
        ['captain' => $captain, 'megan' => $megan, 'thisYear' => $thisYear,
         'thisEntry' => $thisEntry, 'lastEntry' => $lastEntry] = $this->groupWithMisclaim();

        $this->actingAs($captain)
            ->post(route('propoff.groups.members.separate', ['group' => $thisYear, 'user' => $megan]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = User::where('first_name', 'Megan')->where('id', '!=', $megan->id)->firstOrFail();

        // This year's entry moved...
        $this->assertSame($fresh->id, $thisEntry->fresh()->user_id);
        // ...and last year's did not.
        $this->assertSame($megan->id, $lastEntry->fresh()->user_id);
    }

    public function test_separating_swaps_group_membership_only_here(): void
    {
        ['captain' => $captain, 'megan' => $megan, 'thisYear' => $thisYear,
         'lastYear' => $lastYear] = $this->groupWithMisclaim();

        $this->actingAs($captain)
            ->post(route('propoff.groups.members.separate', ['group' => $thisYear, 'user' => $megan]));

        $fresh = User::where('first_name', 'Megan')->where('id', '!=', $megan->id)->firstOrFail();

        $this->assertFalse($thisYear->fresh()->users()->where('users.id', $megan->id)->exists());
        $this->assertTrue($thisYear->fresh()->users()->where('users.id', $fresh->id)->exists());
        // The original keeps last year's membership.
        $this->assertTrue($lastYear->fresh()->users()->where('users.id', $megan->id)->exists());
    }

    public function test_separating_moves_this_groups_leaderboard_row(): void
    {
        ['captain' => $captain, 'megan' => $megan, 'thisYear' => $thisYear,
         'lastYear' => $lastYear] = $this->groupWithMisclaim();

        $here = Leaderboard::factory()->create([
            'user_id'  => $megan->id,
            'group_id' => $thisYear->id,
            'event_id' => $thisYear->event_id,
        ]);
        $there = Leaderboard::factory()->create([
            'user_id'  => $megan->id,
            'group_id' => $lastYear->id,
            'event_id' => $lastYear->event_id,
        ]);

        $this->actingAs($captain)
            ->post(route('propoff.groups.members.separate', ['group' => $thisYear, 'user' => $megan]));

        $fresh = User::where('first_name', 'Megan')->where('id', '!=', $megan->id)->firstOrFail();

        $this->assertSame($fresh->id, $here->fresh()->user_id);
        $this->assertSame($megan->id, $there->fresh()->user_id);
    }

    public function test_a_captain_cannot_be_separated_without_handing_over_the_role(): void
    {
        ['captain' => $captain, 'thisYear' => $thisYear] = $this->groupWithMisclaim();

        $this->actingAs($captain)
            ->post(route('propoff.groups.members.separate', ['group' => $thisYear, 'user' => $captain]))
            ->assertSessionHasErrors('member');

        $this->assertTrue($thisYear->fresh()->users()->where('users.id', $captain->id)->exists());
    }

    public function test_a_non_member_cannot_be_separated(): void
    {
        ['captain' => $captain, 'thisYear' => $thisYear] = $this->groupWithMisclaim();
        $stranger = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Stranger', 'role' => 'guest']);

        $this->actingAs($captain)
            ->post(route('propoff.groups.members.separate', ['group' => $thisYear, 'user' => $stranger]))
            ->assertSessionHasErrors('member');
    }

    public function test_only_a_captain_of_the_group_may_separate(): void
    {
        ['megan' => $megan, 'thisYear' => $thisYear] = $this->groupWithMisclaim();
        $outsider = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Outsider', 'role' => 'user']);

        $this->actingAs($outsider)
            ->post(route('propoff.groups.members.separate', ['group' => $thisYear, 'user' => $megan]))
            ->assertForbidden();

        $this->assertTrue($thisYear->fresh()->users()->where('users.id', $megan->id)->exists());
    }
}

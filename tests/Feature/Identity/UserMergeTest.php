<?php

namespace Tests\Feature\Identity;

use App\Models\PropOff\Entry;
use App\Models\PropOff\Event;
use App\Models\PropOff\Group;
use App\Models\User;
use App\Services\Identity\UserMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * PropOff mints a users row per party guest, so one human accumulates several —
 * 136 guest rows for roughly 120 people. Merging is driven by someone who knows
 * them; these cover that the merge is complete (all 22 FK columns), that it
 * refuses the cases only a human can settle, and that it is all-or-nothing.
 */
class UserMergeTest extends TestCase
{
    use RefreshDatabase;

    private function guest(string $first, string $last = ''): User
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => $last, 'role' => 'guest']);
    }

    private function account(string $first, string $last = ''): User
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => $last, 'role' => 'user']);
    }

    public function test_the_guest_row_is_removed_and_its_memberships_move(): void
    {
        $owner = $this->account('Fixture', 'Owner');
        $guest = $this->guest('Krista');
        $real = $this->account('Krista', 'Keele');

        $event = Event::factory()->create(['created_by' => $owner->id]);
        $group = Group::factory()->create(['event_id' => $event->id, 'created_by' => $owner->id]);
        $group->users()->attach($guest->id, ['joined_at' => now(), 'is_captain' => false]);

        app(UserMerger::class)->merge($guest, $real);

        $this->assertNull(User::find($guest->id));
        $this->assertTrue($group->fresh()->users()->where('users.id', $real->id)->exists());
    }

    public function test_entries_and_their_answers_follow_the_person(): void
    {
        $owner = $this->account('Fixture', 'Owner');
        $guest = $this->guest('Deegen');
        $real = $this->account('Deegen', 'Varney');

        $event = Event::factory()->create(['created_by' => $owner->id]);
        $group = Group::factory()->create(['event_id' => $event->id, 'created_by' => $owner->id]);
        $entry = Entry::factory()->create([
            'user_id' => $guest->id, 'group_id' => $group->id, 'event_id' => $event->id,
        ]);

        app(UserMerger::class)->merge($guest, $real);

        $this->assertSame($real->id, $entry->fresh()->user_id);
    }

    public function test_a_membership_the_target_already_has_is_dropped_not_duplicated(): void
    {
        $owner = $this->account('Fixture', 'Owner');
        $guest = $this->guest('Tara');
        $real = $this->account('Tara', 'Busenbark');

        $event = Event::factory()->create(['created_by' => $owner->id]);
        $group = Group::factory()->create(['event_id' => $event->id, 'created_by' => $owner->id]);
        // Both are already in the same group — the unique (user, group) key
        // means the guest's row cannot simply be repointed.
        $group->users()->attach($guest->id, ['joined_at' => now(), 'is_captain' => false]);
        $group->users()->attach($real->id, ['joined_at' => now(), 'is_captain' => false]);

        app(UserMerger::class)->merge($guest, $real);

        $this->assertSame(1, $group->fresh()->users()->where('users.id', $real->id)->count());
        $this->assertSame(1, DB::table('propoff_group_user')->where('group_id', $group->id)->count());
    }

    public function test_it_refuses_when_both_played_the_same_group(): void
    {
        $owner = $this->account('Fixture', 'Owner');
        $guest = $this->guest('Megan');
        $real = $this->account('Megan', 'Adams');

        $event = Event::factory()->create(['created_by' => $owner->id]);
        $group = Group::factory()->create(['event_id' => $event->id, 'created_by' => $owner->id]);
        Entry::factory()->create(['user_id' => $guest->id, 'group_id' => $group->id, 'event_id' => $event->id]);
        Entry::factory()->create(['user_id' => $real->id, 'group_id' => $group->id, 'event_id' => $event->id]);

        // Both actually played it, so either they are different people or one
        // entry is a duplicate whose answers someone must choose between.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/both have played/');

        app(UserMerger::class)->merge($guest, $real);
    }

    public function test_it_refuses_to_merge_away_a_real_account(): void
    {
        $account = $this->account('Bert', 'Keele');
        $other = $this->account('Robert', 'Keele');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/only guest credentials/');

        app(UserMerger::class)->merge($account, $other);
    }

    public function test_it_refuses_to_merge_someone_into_themselves(): void
    {
        $guest = $this->guest('Ben');

        $this->expectException(RuntimeException::class);
        app(UserMerger::class)->merge($guest, $guest);
    }

    public function test_a_refused_merge_writes_nothing(): void
    {
        $owner = $this->account('Fixture', 'Owner');
        $guest = $this->guest('Megan');
        $real = $this->account('Megan', 'Adams');

        $event = Event::factory()->create(['created_by' => $owner->id]);
        $clash = Group::factory()->create(['event_id' => $event->id, 'created_by' => $owner->id]);
        Entry::factory()->create(['user_id' => $guest->id, 'group_id' => $clash->id, 'event_id' => $event->id]);
        Entry::factory()->create(['user_id' => $real->id, 'group_id' => $clash->id, 'event_id' => $event->id]);

        // A membership elsewhere must survive the refusal untouched.
        $other = Group::factory()->create(['event_id' => $event->id, 'created_by' => $owner->id]);
        $other->users()->attach($guest->id, ['joined_at' => now(), 'is_captain' => false]);

        try {
            app(UserMerger::class)->merge($guest, $real);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotNull(User::find($guest->id));
        $this->assertTrue($other->fresh()->users()->where('users.id', $guest->id)->exists());
    }

    public function test_authorship_columns_move_so_the_guest_row_can_be_deleted(): void
    {
        $owner = $this->account('Fixture', 'Owner');
        $guest = $this->guest('Slarswick');
        $real = $this->account('Slars', 'Wick');

        $event = Event::factory()->create(['created_by' => $owner->id]);
        // created_by CASCADEs, so leaving it behind would delete the group with
        // the guest instead of moving it.
        $group = Group::factory()->create(['event_id' => $event->id, 'created_by' => $guest->id]);

        app(UserMerger::class)->merge($guest, $real);

        $this->assertSame($real->id, $group->fresh()->created_by);
        $this->assertNull(User::find($guest->id));
    }

    public function test_the_survivor_adopts_a_surname_it_lacks(): void
    {
        // The row that played is often not the row with the better name.
        $named = $this->guest('Nick', 'Williams');
        $played = $this->guest('Nick');
        $played->update(['last_name' => '']);

        app(UserMerger::class)->merge($named, $played);

        $this->assertSame('Williams', $played->fresh()->last_name);
    }

    public function test_the_survivor_adopts_an_email_it_lacks(): void
    {
        $withEmail = $this->guest('Nick', 'Williams');
        $withEmail->update(['email' => 'nick@example.com']);
        // Real PropOff guests have no email — UserFactory always assigns one,
        // so it has to be cleared for the fixture to match the data.
        $played = $this->guest('Nick');
        $played->update(['email' => null]);

        app(UserMerger::class)->merge($withEmail, $played);

        $this->assertSame('nick@example.com', $played->fresh()->email);
    }

    public function test_a_numeric_disambiguator_is_not_adopted_as_a_surname(): void
    {
        // People type "Megan 2" at the join screen to tell themselves apart,
        // and splitName files that "2" as a surname. Spreading it to the
        // survivor would make the data worse, not better.
        $source = $this->guest('Megan', '2');
        $target = $this->guest('Megan');
        $target->update(['last_name' => '']);

        app(UserMerger::class)->merge($source, $target);

        $this->assertSame('', $target->fresh()->last_name);
    }

    public function test_it_never_overwrites_details_the_survivor_already_has(): void
    {
        $source = $this->guest('Nick', 'Williams');
        $source->update(['email' => 'guest@example.com']);
        $target = $this->account('Nick', 'Keele');
        $target->update(['email' => 'real@example.com']);

        app(UserMerger::class)->merge($source, $target);

        $fresh = $target->fresh();
        $this->assertSame('Keele', $fresh->last_name);
        $this->assertSame('real@example.com', $fresh->email);
    }
}

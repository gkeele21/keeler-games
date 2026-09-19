<?php

namespace Tests\Feature\Identity;

use App\Models\Scorekeeper\Household;
use App\Models\Scorekeeper\Player;
use App\Models\User;
use App\Services\Identity\RosterLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A roster player and the account that plays as them live in different tables
 * on purpose: users is the credential record, players is a name on one
 * household's roster. One human is legitimately both, and is several players if
 * they play in several households.
 *
 * players.user_id is the join, and nothing populated it except the owner's own
 * player. These cover filling it in safely.
 */
class RosterLinkTest extends TestCase
{
    use RefreshDatabase;

    private function household(User $owner, string $name = 'Williams Family'): Household
    {
        return Household::create(['name' => $name, 'owner_user_id' => $owner->id]);
    }

    public function test_it_links_a_player_to_a_person(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $player = Player::create(['household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false]);
        $hazel = User::factory()->create(['first_name' => 'Hazel', 'last_name' => '', 'role' => 'guest']);

        app(RosterLinker::class)->link($player, $hazel);

        $this->assertSame($hazel->id, $player->fresh()->user_id);
    }

    public function test_the_same_person_can_be_on_two_household_rosters(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $a = $this->household($owner, 'Williams Family');
        $b = $this->household($owner, "Grant's Household");
        $hazel = User::factory()->create(['first_name' => 'Hazel', 'last_name' => '', 'role' => 'guest']);

        $onA = Player::create(['household_id' => $a->id, 'name' => 'Hazel', 'is_guest' => false]);
        $onB = Player::create(['household_id' => $b->id, 'name' => 'Hazel', 'is_guest' => false]);

        // One human, one roster entry per household — this is the normal shape,
        // not a duplicate.
        app(RosterLinker::class)->link($onA, $hazel);
        app(RosterLinker::class)->link($onB, $hazel);

        $this->assertSame($hazel->id, $onA->fresh()->user_id);
        $this->assertSame($hazel->id, $onB->fresh()->user_id);
    }

    public function test_it_refuses_to_put_the_same_person_on_one_roster_twice(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $hazel = User::factory()->create(['first_name' => 'Hazel', 'last_name' => '', 'role' => 'guest']);

        Player::create(['household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false, 'user_id' => $hazel->id]);
        $duplicate = Player::create(['household_id' => $house->id, 'name' => 'Hazel R', 'is_guest' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/twice/');

        app(RosterLinker::class)->link($duplicate, $hazel);
    }

    public function test_it_refuses_to_overwrite_an_existing_link(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $first = User::factory()->create(['first_name' => 'Hazel', 'last_name' => 'One', 'role' => 'guest']);
        $second = User::factory()->create(['first_name' => 'Hazel', 'last_name' => 'Two', 'role' => 'guest']);
        $player = Player::create(['household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false, 'user_id' => $first->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already linked to someone else/');

        app(RosterLinker::class)->link($player, $second);
    }

    public function test_unlinking_puts_it_back(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $hazel = User::factory()->create(['first_name' => 'Hazel', 'last_name' => '', 'role' => 'guest']);
        $player = Player::create(['household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false, 'user_id' => $hazel->id]);

        // The reason a wrong link is cheap: nothing was destroyed to make it.
        app(RosterLinker::class)->unlink($player);

        $this->assertNull($player->fresh()->user_id);
    }

    public function test_household_membership_raises_confidence(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $member = User::factory()->create(['first_name' => 'Ethan', 'last_name' => 'Keele']);
        $house->members()->attach($member->id, ['role' => 'guest']);

        Player::create(['household_id' => $house->id, 'name' => 'Ethan', 'is_guest' => false]);

        $match = collect(app(RosterLinker::class)->candidates())
            ->firstWhere(fn ($c) => $c['user']->id === $member->id);

        $this->assertSame('strong', $match['confidence']);
    }

    public function test_a_player_matching_two_people_is_marked_ambiguous(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        User::factory()->create(['first_name' => 'Addi', 'last_name' => '', 'role' => 'guest']);
        User::factory()->create(['first_name' => 'Addi', 'last_name' => '', 'role' => 'guest']);

        Player::create(['household_id' => $house->id, 'name' => 'Addi', 'is_guest' => false]);

        // Two unmerged guest rows for one human — the name cannot choose
        // between them, so the tool must not pretend it can.
        $matches = collect(app(RosterLinker::class)->candidates())
            ->filter(fn ($c) => mb_strtolower($c['player']->name) === 'addi');

        $this->assertCount(2, $matches);
        $this->assertTrue($matches->every(fn ($c) => $c['confidence'] === 'ambiguous'));
    }

    public function test_someone_already_on_the_roster_is_not_suggested_again(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $hazel = User::factory()->create(['first_name' => 'Hazel', 'last_name' => '', 'role' => 'guest']);

        Player::create(['household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false, 'user_id' => $hazel->id]);
        Player::create(['household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false]);

        $matches = collect(app(RosterLinker::class)->candidates())
            ->filter(fn ($c) => $c['user']->id === $hazel->id);

        $this->assertCount(0, $matches);
    }

    public function test_links_can_be_exported_and_replayed(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        $hazel = User::factory()->create(['first_name' => 'Hazel', 'last_name' => 'Reed', 'role' => 'guest']);
        $player = Player::create([
            'household_id' => $house->id, 'name' => 'Hazel', 'is_guest' => false, 'user_id' => $hazel->id,
        ]);

        $path = sys_get_temp_dir() . '/links-' . uniqid() . '.json';
        $this->artisan("players:link-users --export={$path}")->assertSuccessful();

        $decisions = json_decode((string) file_get_contents($path), true);
        $this->assertCount(1, $decisions);
        $this->assertSame($player->id, $decisions[0]['player']);
        $this->assertSame($hazel->id, $decisions[0]['user']);

        // Replaying where it already applies is a no-op, which is what makes it
        // safe to run on an environment that was linked by hand.
        $this->artisan("players:link-users --from={$path}")
            ->expectsOutputToContain('already linked')
            ->assertSuccessful();
    }

    public function test_export_skips_the_owners_own_player(): void
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $house = $this->household($owner);
        // ensureDefaultHousehold creates this link itself, so replaying it
        // would be noise rather than a decision anyone made.
        Player::create(['household_id' => $house->id, 'name' => 'Fixture Owner', 'is_guest' => false, 'user_id' => $owner->id]);

        $path = sys_get_temp_dir() . '/links-' . uniqid() . '.json';
        $this->artisan("players:link-users --export={$path}")->assertSuccessful();

        $this->assertFileDoesNotExist($path);
    }
}

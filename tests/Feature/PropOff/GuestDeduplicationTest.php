<?php

namespace Tests\Feature\PropOff;

use App\Mail\GuestMagicLink;
use App\Models\PropOff\Event;
use App\Models\PropOff\EventInvitation;
use App\Models\PropOff\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The invitation join flow used to call User::create unconditionally. It
 * carried 140 of the Super Bowl LX joins and produced 18 duplicated names —
 * the same people re-registering after losing their magic link.
 *
 * These cover the fix and, just as importantly, its limit: a name match is a
 * candidate to confirm, never a silent merge, because two real people sharing
 * a name is common ("Megan" appears three times in the live data).
 */
class GuestDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Names are pinned on every factory-made user throughout this file.
     * UserFactory uses fake()->firstName(), which will occasionally produce
     * "Ben" or "Megan" on its own and break assertions that count by name.
     */
    private function invitation(): EventInvitation
    {
        $owner = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Owner']);
        $event = Event::factory()->create(['created_by' => $owner->id]);
        $group = Group::factory()->create([
            'event_id'   => $event->id,
            'created_by' => $owner->id,
        ]);

        return EventInvitation::factory()->create([
            'event_id'  => $event->id,
            'group_id'  => $group->id,
            'is_active' => true,
        ]);
    }

    public function test_a_new_name_creates_exactly_one_guest(): void
    {
        $invitation = $this->invitation();

        $this->post(route('propoff.guest.register', $invitation->token), [
            'name' => 'Megan Adams',
        ])->assertRedirect();

        $this->assertSame(1, User::where('first_name', 'Megan')->count());
        $this->assertSame(1, $invitation->group->users()->count());
    }

    public function test_a_repeat_name_is_offered_back_instead_of_creating_a_second_user(): void
    {
        $invitation = $this->invitation();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Megan Adams']);
        $this->flushSession();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Megan Adams'])
            ->assertSessionHas('step', 'verify')
            ->assertSessionHas('verifyEntry');

        // The whole point: no second row was written.
        $this->assertSame(1, User::where('first_name', 'Megan')->count());
        $this->assertSame(1, $invitation->group->users()->count());
    }

    public function test_claiming_the_match_reuses_the_existing_person(): void
    {
        $invitation = $this->invitation();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Megan Adams']);
        $existing = User::where('first_name', 'Megan')->firstOrFail();
        $this->flushSession();

        $this->post(route('propoff.guest.register', $invitation->token), [
            'name'          => 'Megan Adams',
            'claim_user_id' => $existing->id,
        ])->assertRedirect();

        $this->assertSame(1, User::where('first_name', 'Megan')->count());
        $this->assertSame($existing->id, auth()->id());
        // Re-joining must not duplicate the pivot row either.
        $this->assertSame(1, $invitation->group->users()->count());
    }

    public function test_a_genuinely_different_person_with_the_same_name_can_still_join(): void
    {
        $invitation = $this->invitation();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Megan Adams']);
        $this->flushSession();

        $this->post(route('propoff.guest.register', $invitation->token), [
            'name'                 => 'Megan Adams',
            'allow_duplicate_name' => true,
        ])->assertRedirect();

        $this->assertSame(2, User::where('first_name', 'Megan')->count());
        $this->assertSame(2, $invitation->group->users()->count());
    }

    public function test_a_known_email_reuses_the_person_without_asking(): void
    {
        $invitation = $this->invitation();
        $existing = User::factory()->create([
            'first_name' => 'Bert',
            'last_name'  => 'Keele',
            'email'      => 'bert@example.com',
            'role'       => 'guest',
        ]);

        // Different name, same address — an email is an assertion of identity,
        // so it reuses the person and takes the new spelling.
        $this->post(route('propoff.guest.register', $invitation->token), [
            'name'  => 'Robert Keele',
            'email' => 'bert@example.com',
        ])->assertRedirect();

        $this->assertSame(1, User::where('email', 'bert@example.com')->count());
        $this->assertSame('Robert', $existing->fresh()->first_name);
        $this->assertSame($existing->id, auth()->id());
    }

    public function test_the_magic_link_is_emailed_when_an_address_is_given(): void
    {
        Mail::fake();
        $invitation = $this->invitation();

        $this->post(route('propoff.guest.register', $invitation->token), [
            'name'  => 'Hazel Reed',
            'email' => 'hazel@example.com',
        ])->assertRedirect();

        Mail::assertSent(GuestMagicLink::class, fn ($mail) => $mail->hasTo('hazel@example.com'));
    }

    public function test_no_email_is_sent_when_no_address_is_given(): void
    {
        Mail::fake();
        $invitation = $this->invitation();

        $this->post(route('propoff.guest.register', $invitation->token), ['name' => 'Hazel Reed'])
            ->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_captain_add_guest_warns_before_creating_a_duplicate(): void
    {
        $invitation = $this->invitation();
        $group = $invitation->group;
        $captain = User::factory()->create(['first_name' => 'Fixture', 'last_name' => 'Captain', 'role' => 'user']);
        $group->addCaptain($captain);

        $this->actingAs($captain)
            ->post(route('propoff.groups.members.addGuest', $group), ['name' => 'Ben'])
            ->assertRedirect();
        $this->assertSame(1, User::where('first_name', 'Ben')->count());

        // Second add of the same name is refused with an explanation...
        $this->actingAs($captain)
            ->post(route('propoff.groups.members.addGuest', $group), ['name' => 'Ben'])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, User::where('first_name', 'Ben')->count());

        // ...but the captain can override for a real second Ben.
        $this->actingAs($captain)
            ->post(route('propoff.groups.members.addGuest', $group), [
                'name'                 => 'Ben',
                'allow_duplicate_name' => true,
            ])->assertRedirect();
        $this->assertSame(2, User::where('first_name', 'Ben')->count());
    }
}

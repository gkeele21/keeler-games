<?php

namespace Tests\Feature\PropOff;

use App\Models\PropOff\Event;
use App\Models\PropOff\EventInvitation;
use App\Models\PropOff\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationSystemTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function captain_can_create_invitation_for_their_group()
    {
        $captain = User::factory()->create(['role' => 'user']);
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->addCaptain($captain);

        $response = $this->actingAs($captain)->get(route('propoff.groups.invitation', $group));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Groups/Invitation')
            ->has('invitation')
        );

        // Invitation should be auto-created
        $this->assertDatabaseHas('propoff_invitations', [
            'group_id' => $group->id,
            'event_id' => $event->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function admin_can_create_invitations()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $invitation = EventInvitation::create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'token' => EventInvitation::generateToken(),
            'is_active' => true,
            'max_uses' => 10,
            'times_used' => 0,
        ]);

        $this->assertDatabaseHas('propoff_invitations', [
            'id' => $invitation->id,
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function invitation_has_unique_token()
    {
        $event = Event::factory()->create();
        $group1 = Group::factory()->create(['event_id' => $event->id]);
        $group2 = Group::factory()->create(['event_id' => $event->id]);

        $invitation1 = EventInvitation::create([
            'event_id' => $event->id,
            'group_id' => $group1->id,
            'token' => EventInvitation::generateToken(),
            'is_active' => true,
        ]);

        $invitation2 = EventInvitation::create([
            'event_id' => $event->id,
            'group_id' => $group2->id,
            'token' => EventInvitation::generateToken(),
            'is_active' => true,
        ]);

        $this->assertNotEquals($invitation1->token, $invitation2->token);
        $this->assertEquals(32, strlen($invitation1->token));
        $this->assertEquals(32, strlen($invitation2->token));
    }

    /** @test */
    public function guest_can_use_valid_invitation()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
            'max_uses' => null,
            'expires_at' => null,
        ]);

        $response = $this->get(route('propoff.guest.join', $invitation->token));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Guest/Join')
            ->has('invitation')
            ->has('invitation.event')
            ->has('invitation.group')
        );
    }

    /** @test */
    public function invitation_expires_after_max_uses()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
            'max_uses' => 2,
            'times_used' => 2, // Already at max
        ]);

        $this->assertFalse($invitation->isValid());

        $response = $this->get(route('propoff.guest.join', $invitation->token));

        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Guest/InvitationExpired')
        );
    }

    /** @test */
    public function invitation_expires_after_expiration_date()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
            'expires_at' => now()->subDay(), // Expired yesterday
        ]);

        $this->assertFalse($invitation->isValid());

        $response = $this->get(route('propoff.guest.join', $invitation->token));

        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Guest/InvitationExpired')
        );
    }

    /** @test */
    public function cannot_use_inactive_invitation()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => false, // Inactive
        ]);

        $this->assertFalse($invitation->isValid());

        $response = $this->get(route('propoff.guest.join', $invitation->token));

        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Guest/InvitationExpired')
        );
    }

    /** @test */
    public function invitation_tracks_uses_count()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
            'max_uses' => 10,
            'times_used' => 0,
        ]);

        $this->assertEquals(0, $invitation->times_used);

        // Simulate using the invitation
        $invitation->incrementUsage();

        $invitation->refresh();
        $this->assertEquals(1, $invitation->times_used);

        $invitation->incrementUsage();
        $invitation->incrementUsage();

        $invitation->refresh();
        $this->assertEquals(3, $invitation->times_used);
    }

    /** @test */
    public function can_generate_shareable_url()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'token' => 'test-token-123',
        ]);

        $url = $invitation->getUrl();

        $this->assertStringContainsString('test-token-123', $url);
        $this->assertStringContainsString('/join/', $url);
    }

    /** @test */
    public function captain_can_toggle_invitation_active_status()
    {
        $captain = User::factory()->create(['role' => 'user']);
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->addCaptain($captain);

        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($captain)->post(
            route('propoff.groups.invitation.toggle', $group)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invitation->refresh();
        $this->assertFalse($invitation->is_active);
    }

    /** @test */
    public function captain_can_regenerate_invitation_token()
    {
        $captain = User::factory()->create(['role' => 'user']);
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->addCaptain($captain);

        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'token' => 'old-token',
            'times_used' => 5,
        ]);

        $oldToken = $invitation->token;

        $response = $this->actingAs($captain)->post(
            route('propoff.groups.invitation.regenerate', $group)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invitation->refresh();
        $this->assertNotEquals($oldToken, $invitation->token);
        $this->assertEquals(0, $invitation->times_used); // Reset usage count
    }

    /** @test */
    public function captain_can_update_invitation_settings()
    {
        $captain = User::factory()->create(['role' => 'user']);
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->addCaptain($captain);

        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'max_uses' => null,
            'expires_at' => null,
        ]);

        $expiresAt = now()->addDays(7);

        $response = $this->actingAs($captain)->patch(
            route('propoff.groups.invitation.update', $group),
            [
                'max_uses' => 50,
                'expires_at' => $expiresAt->toDateTimeString(),
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invitation->refresh();
        $this->assertEquals(50, $invitation->max_uses);
        $this->assertEquals($expiresAt->format('Y-m-d H:i'), $invitation->expires_at->format('Y-m-d H:i'));
    }

    /** @test */
    public function non_captain_cannot_manage_invitation()
    {
        $user = User::factory()->create(['role' => 'user']);
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->get(
            route('propoff.groups.invitation', $group)
        );

        $response->assertForbidden();
    }

    /** @test */
    public function guest_user_can_join_group_via_invitation()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
            'times_used' => 0,
        ]);

        $response = $this->post(route('propoff.guest.register', $invitation->token), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $response->assertRedirect();

        // User should be created
        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);

        // User should be added to group
        $this->assertTrue($group->users()->where('user_id', $user->id)->exists());

        // Invitation usage should be incremented
        $invitation->refresh();
        $this->assertEquals(1, $invitation->times_used);
    }

    /** @test */
    public function authenticated_user_can_join_group_via_invitation()
    {
        $this->markTestSkipped('Authenticated user join via invitation is not yet implemented in guest.register route');

        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(
            route('propoff.guest.register', $invitation->token)
        );

        $response->assertRedirect();

        // User should be added to group
        $this->assertTrue($group->users()->where('user_id', $user->id)->exists());
    }

    /** @test */
    public function invitation_with_unlimited_uses_never_expires_from_usage()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
            'max_uses' => null, // Unlimited
            'times_used' => 9999,
        ]);

        $this->assertTrue($invitation->isValid());
    }

    /** @test */
    public function invitation_scope_returns_only_valid_invitations()
    {
        $event = Event::factory()->create();
        $group1 = Group::factory()->create(['event_id' => $event->id]);
        $group2 = Group::factory()->create(['event_id' => $event->id]);
        $group3 = Group::factory()->create(['event_id' => $event->id]);
        $group4 = Group::factory()->create(['event_id' => $event->id]);

        // Valid invitation
        $validInvitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group1->id,
            'is_active' => true,
            'max_uses' => 10,
            'times_used' => 5,
            'expires_at' => now()->addDays(7),
        ]);

        // Expired by date
        EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group2->id,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        // Expired by max uses
        EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group3->id,
            'is_active' => true,
            'max_uses' => 5,
            'times_used' => 5,
        ]);

        // Inactive
        EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group4->id,
            'is_active' => false,
        ]);

        $validInvitations = EventInvitation::valid()->get();

        $this->assertCount(1, $validInvitations);
        $this->assertEquals($validInvitation->id, $validInvitations->first()->id);
    }

    /** @test */
    public function invitation_url_contains_token()
    {
        $invitation = EventInvitation::factory()->create([
            'token' => 'my-unique-token-abc123',
        ]);

        $url = $invitation->getUrl();

        $this->assertStringContainsString('my-unique-token-abc123', $url);
        $this->assertStringStartsWith('http', $url);
    }

    /** @test */
    public function invalid_invitation_token_returns_404()
    {
        $response = $this->get(route('propoff.guest.join', 'invalid-token-xyz'));

        $response->assertNotFound();
    }

    /** @test */
    public function user_already_in_group_cannot_join_again()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($user->id, ['joined_at' => now()]);

        $invitation = EventInvitation::factory()->create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(
            route('propoff.guest.register', $invitation->token)
        );

        // Should handle gracefully (redirect with message or prevent duplicate)
        $response->assertRedirect();

        // Should still only have one membership record
        $this->assertEquals(1, $group->users()->where('user_id', $user->id)->count());
    }
}

<?php

namespace Tests\Feature\PropOff;

use App\Models\PropOff\CaptainInvitation;
use App\Models\PropOff\Event;
use App\Models\PropOff\EventQuestion;
use App\Models\PropOff\Group;
use App\Models\PropOff\GroupQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptainInvitationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guest_can_access_captain_invitation_join_link()
    {
        // Create event with captain invitation
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        // Guest (unauthenticated) visits the join link
        $response = $this->get(route('propoff.captain.join', $invitation->token));

        // Should redirect to create group page (not to login!)
        $response->assertRedirect(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));
    }

    /** @test */
    public function guest_can_view_create_group_page()
    {
        // Create event with captain invitation
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        // Guest visits create group page directly
        $response = $this->get(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));

        // Should see the create group form (200 OK)
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/CreateGroup')
            ->has('event')
            ->has('invitation')
            ->where('isGuest', true)
        );
    }

    /** @test */
    public function guest_can_create_group_and_become_captain()
    {
        // Create event with questions
        $event = Event::factory()->create();
        $eventQuestion1 = EventQuestion::factory()->create(['event_id' => $event->id, 'display_order' => 1]);
        $eventQuestion2 = EventQuestion::factory()->create(['event_id' => $event->id, 'display_order' => 2]);

        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
            'max_uses' => null,
        ]);

        // Guest submits create group form
        $response = $this->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'description' => 'Test Description',
            'grading_source' => 'captain',
            'captain_name' => 'John Doe',
            'captain_email' => 'john@example.com',
        ]);

        // Should redirect to dashboard
        $response->assertRedirect(route('propoff.groups.index'));

        // Group should be created
        $this->assertDatabaseHas('propoff_groups', [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        // Guest user should be created
        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('guest', $user->role);
        $this->assertEquals('John Doe', $user->name);
        $this->assertNotNull($user->guest_token);

        // User should be captain of the group
        $group = Group::where('name', 'Test Group')->first();
        $this->assertTrue($user->isCaptainOf($group->id));

        // Group questions should be created from event questions
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'event_question_id' => $eventQuestion1->id,
        ]);
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'event_question_id' => $eventQuestion2->id,
        ]);

        // Invitation usage should be incremented
        $invitation->refresh();
        $this->assertEquals(1, $invitation->times_used);
    }

    /** @test */
    public function guest_can_create_group_without_email()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        // Guest submits create group form without email
        $response = $this->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
            'captain_name' => 'Anonymous User',
        ]);

        $response->assertRedirect(route('propoff.groups.index'));

        // Anonymous guest user should be created
        $user = User::where('first_name', 'Anonymous')->where('last_name', 'User')->first();
        $this->assertNotNull($user);
        $this->assertEquals('guest', $user->role);
        $this->assertNull($user->email);
        $this->assertNotNull($user->guest_token);
    }

    /** @test */
    public function authenticated_user_can_create_group_without_guest_fields()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        // Authenticated user visits create group page
        $response = $this->actingAs($user)->get(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('isGuest', false)
        );

        // Authenticated user creates group
        $response = $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'User Group',
            'grading_source' => 'admin',
        ]);

        $response->assertRedirect(route('propoff.groups.index'));

        // User should be captain of their own group
        $group = Group::where('name', 'User Group')->first();
        $this->assertTrue($user->isCaptainOf($group->id));
    }

    /** @test */
    public function returning_captain_with_same_email_reuses_existing_account()
    {
        // Create existing guest user
        $existingUser = User::factory()->create([
            'email' => 'returning@example.com',
            'name' => 'Original Name',
            'role' => 'guest',
            'guest_token' => 'existing-token',
        ]);

        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        // Guest with same email creates new group
        $response = $this->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'Second Group',
            'grading_source' => 'captain',
            'captain_name' => 'Updated Name',
            'captain_email' => 'returning@example.com',
        ]);

        $response->assertRedirect(route('propoff.groups.index'));

        // Should NOT create a new user
        $this->assertEquals(1, User::where('email', 'returning@example.com')->count());

        // Name should be updated
        $existingUser->refresh();
        $this->assertEquals('Updated Name', $existingUser->name);

        // User should be captain of new group
        $group = Group::where('name', 'Second Group')->first();
        $this->assertTrue($existingUser->isCaptainOf($group->id));
    }

    /** @test */
    public function expired_invitation_shows_expired_page()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
            'expires_at' => now()->subDay(), // Expired yesterday
        ]);

        $response = $this->get(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/InvitationExpired')
        );
    }

    /** @test */
    public function inactive_invitation_shows_expired_page()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => false,
        ]);

        $response = $this->get(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/InvitationExpired')
        );
    }

    /** @test */
    public function invitation_at_max_uses_shows_expired_page()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
            'max_uses' => 2,
            'times_used' => 2, // Already at max
        ]);

        $response = $this->get(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/InvitationExpired')
        );
    }

    /** @test */
    public function cannot_create_group_with_expired_invitation()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
            'captain_name' => 'John Doe',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This invitation has expired or reached its usage limit.');

        // Group should NOT be created
        $this->assertDatabaseMissing('propoff_groups', [
            'name' => 'Test Group',
        ]);
    }

    /** @test */
    public function invalid_invitation_token_returns_404()
    {
        $event = Event::factory()->create();

        $response = $this->get(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => 'invalid-token-xyz',
        ]));

        $response->assertNotFound();
    }

    /** @test */
    public function group_questions_are_auto_created_from_event_questions()
    {
        $event = Event::factory()->create();
        $question1 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Question 1',
            'question_type' => 'text',
            'points' => 10,
            'display_order' => 1,
        ]);
        $question2 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Question 2',
            'question_type' => 'multiple_choice',
            'points' => 15,
            'display_order' => 2,
        ]);

        $invitation = CaptainInvitation::factory()->create(['event_id' => $event->id]);

        $this->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
            'captain_name' => 'Captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();

        // Both questions should be copied
        $groupQuestions = GroupQuestion::where('group_id', $group->id)->orderBy('display_order')->get();
        $this->assertCount(2, $groupQuestions);

        // First question
        $this->assertEquals($question1->id, $groupQuestions[0]->event_question_id);
        $this->assertEquals('Question 1', $groupQuestions[0]->question_text);
        $this->assertEquals('text', $groupQuestions[0]->question_type);
        $this->assertEquals(10, $groupQuestions[0]->points);
        $this->assertTrue($groupQuestions[0]->is_active);
        $this->assertFalse($groupQuestions[0]->is_custom);

        // Second question
        $this->assertEquals($question2->id, $groupQuestions[1]->event_question_id);
        $this->assertEquals('Question 2', $groupQuestions[1]->question_text);
        $this->assertEquals('multiple_choice', $groupQuestions[1]->question_type);
        $this->assertEquals(15, $groupQuestions[1]->points);
    }

    /** @test */
    public function event_invitation_is_auto_created_for_new_group()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create(['event_id' => $event->id]);

        $this->post(route('propoff.captain.groups.store', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
            'captain_name' => 'Captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();

        // EventInvitation should be auto-created
        $this->assertDatabaseHas('propoff_invitations', [
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        $eventInvitation = $group->invitations()->first();
        $this->assertNull($eventInvitation->max_uses); // Unlimited
        $this->assertNull($eventInvitation->expires_at); // No expiration
        $this->assertEquals(0, $eventInvitation->times_used);
    }
}

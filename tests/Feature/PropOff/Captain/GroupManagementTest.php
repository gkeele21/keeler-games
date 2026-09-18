<?php

namespace Tests\Feature\PropOff\Captain;

use App\Models\PropOff\CaptainInvitation;
use App\Models\PropOff\Event;
use App\Models\PropOff\EventInvitation;
use App\Models\PropOff\EventQuestion;
use App\Models\PropOff\Group;
use App\Models\PropOff\GroupQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function captain_can_create_group_via_invitation_token()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Captain Group',
            'description' => 'My group description',
            'grading_source' => 'captain',
        ]);

        $response->assertRedirect(route('propoff.groups.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('propoff_groups', [
            'name' => 'Captain Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
            'created_by' => $user->id,
        ]);
    }

    /** @test */
    public function captain_can_join_as_captain_via_token()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('propoff.captain.join', $invitation->token));

        $response->assertRedirect(route('propoff.captain.groups.create', [
            'event' => $event->id,
            'token' => $invitation->token,
        ]));
    }

    /** @test */
    public function created_group_has_captain_assigned()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Captain Group',
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Captain Group')->first();

        // User should be a captain
        $this->assertTrue($user->isCaptainOf($group->id));

        // Check pivot table
        $pivot = $group->users()->where('users.id', $user->id)->first()->pivot;
        $this->assertEquals(1, $pivot->is_captain);
    }

    /** @test */
    public function created_group_belongs_to_correct_event()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['name' => 'Super Bowl']);
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Captain Group',
            'grading_source' => 'admin',
        ]);

        $group = Group::where('name', 'Captain Group')->first();
        $this->assertEquals($event->id, $group->event_id);
        $this->assertEquals('Super Bowl', $group->event->name);
    }

    /** @test */
    public function invalid_token_shows_404()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.captain.groups.create', [
            'event' => $event,
            'token' => 'invalid-token-xyz',
        ]));

        $response->assertNotFound();
    }

    /** @test */
    public function expired_invitation_cannot_be_used()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->expired()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('propoff.captain.groups.create', [
            'event' => $event,
            'token' => $invitation->token,
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/InvitationExpired')
        );
    }

    /** @test */
    public function inactive_invitation_cannot_be_used()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get(route('propoff.captain.groups.create', [
            'event' => $event,
            'token' => $invitation->token,
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/InvitationExpired')
        );
    }

    /** @test */
    public function maxed_out_invitation_cannot_be_used()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
            'max_uses' => 2,
            'times_used' => 2,
        ]);

        $response = $this->actingAs($user)->get(route('propoff.captain.groups.create', [
            'event' => $event,
            'token' => $invitation->token,
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Captain/InvitationExpired')
        );
    }

    /** @test */
    public function expired_invitation_prevents_group_creation()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->expired()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('propoff_groups', ['name' => 'Test Group']);
    }

    /** @test */
    public function captain_is_auto_added_as_member()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Captain Group',
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Captain Group')->first();

        // User should be in the members list
        $this->assertTrue($group->users->contains($user->id));
        $this->assertTrue($group->members->contains($user->id));
    }

    /** @test */
    public function group_name_is_validated()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            // Missing name
            'grading_source' => 'captain',
        ]);

        $response->assertSessionHasErrors('name');
    }

    /** @test */
    public function grading_source_is_validated()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'invalid',
        ]);

        $response->assertSessionHasErrors('grading_source');
    }

    /** @test */
    public function guest_can_create_group_and_become_captain()
    {
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $response = $this->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Guest Captain Group',
            'grading_source' => 'captain',
            'captain_name' => 'Guest Captain',
            'captain_email' => 'guest@example.com',
        ]);

        $response->assertRedirect(route('propoff.groups.index'));

        // Guest user should be created
        $user = User::where('email', 'guest@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('guest', $user->role);
        $this->assertEquals('Guest Captain', $user->name);

        // Group should exist
        $group = Group::where('name', 'Guest Captain Group')->first();
        $this->assertNotNull($group);

        // Guest should be captain
        $this->assertTrue($user->isCaptainOf($group->id));
    }

    /** @test */
    public function group_questions_are_copied_from_event()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $eventQuestion1 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Who will win?',
            'display_order' => 1,
        ]);
        $eventQuestion2 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'What will be the score?',
            'display_order' => 2,
        ]);

        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();

        // Check group questions were created
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'event_question_id' => $eventQuestion1->id,
            'question_text' => 'Who will win?',
        ]);

        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'event_question_id' => $eventQuestion2->id,
            'question_text' => 'What will be the score?',
        ]);

        $this->assertEquals(2, GroupQuestion::where('group_id', $group->id)->count());
    }

    /** @test */
    public function event_invitation_is_auto_created()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();

        // EventInvitation should be created
        $this->assertDatabaseHas('propoff_event_invitations', [
            'event_id' => $event->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        $eventInvitation = EventInvitation::where('group_id', $group->id)->first();
        $this->assertNull($eventInvitation->max_uses);
        $this->assertNull($eventInvitation->expires_at);
    }

    /** @test */
    public function invitation_usage_is_incremented()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
            'times_used' => 0,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $invitation->refresh();
        $this->assertEquals(1, $invitation->times_used);
    }

    /** @test */
    public function captain_can_view_their_group()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->get(route('propoff.groups.questions', $group));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Groups/Questions')
            ->has('group')
            ->has('stats')
        );
    }

    /** @test */
    public function captain_can_update_their_group()
    {
        $captain = User::factory()->create();
        // grading_source is only editable while the event is still draft/open
        // (see GroupController::canChangeGradingSource). EventFactory randomises
        // status across five values, so leaving it unpinned made this test fail
        // whenever it landed on locked/in_progress/completed.
        $event = Event::factory()->create(['status' => 'open']);
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'name' => 'Old Name',
        ]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->patch(route('propoff.groups.update', $group), [
            'name' => 'New Name',
            'description' => 'Updated description',
            'grading_source' => 'admin',
            'is_public' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('propoff_groups', [
            'id' => $group->id,
            'name' => 'New Name',
            'description' => 'Updated description',
            'grading_source' => 'admin',
        ]);
    }

    /** @test */
    public function captain_can_delete_their_group_without_entries()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->delete(route('propoff.groups.destroy', $group));

        $response->assertRedirect(route('propoff.groups.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function captain_cannot_delete_group_with_entries()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        // Create an entry
        $group->entries()->create([
            'user_id' => $captain->id,
            'event_id' => $event->id,
            'is_complete' => false,
        ]);

        $response = $this->actingAs($captain)->delete(route('propoff.groups.destroy', $group));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function non_captain_cannot_view_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->get(route('propoff.groups.questions', $group));

        $response->assertStatus(403);
    }

    /** @test */
    public function non_captain_cannot_update_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->patch(route('propoff.groups.update', $group), [
            'name' => 'Hacked Name',
            'grading_source' => 'captain',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('propoff_groups', ['name' => 'Hacked Name']);
    }

    /** @test */
    public function non_captain_cannot_delete_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->delete(route('propoff.groups.destroy', $group));

        $response->assertStatus(403);
        $this->assertDatabaseHas('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function admin_can_view_any_captain_group()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.groups.questions', $group));

        $response->assertStatus(200);
    }

    /** @test */
    public function created_group_has_unique_code()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $invitation = CaptainInvitation::factory()->create([
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('propoff.captain.groups.store', [
            'event' => $event,
            'token' => $invitation->token,
        ]), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();
        $this->assertNotNull($group->code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $group->code);
    }
}

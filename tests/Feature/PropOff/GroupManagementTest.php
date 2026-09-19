<?php

namespace Tests\Feature\PropOff;

use App\Models\PropOff\Entry;
use App\Models\PropOff\Event;
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
    public function user_can_view_create_group_form()
    {
        $user = User::factory()->create();
        Event::factory()->count(3)->create(['status' => 'open']);

        $response = $this->actingAs($user)->get(route('propoff.groups.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Groups/Create')
            ->has('events')
        );
    }

    /** @test */
    public function user_can_create_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'My Group',
            'description' => 'Group description',
            'event_id' => $event->id,
            'grading_source' => 'captain',
            'is_public' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('propoff_groups', [
            'name' => 'My Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
            'created_by' => $user->id,
            'is_public' => false,
        ]);
    }

    /** @test */
    public function user_becomes_captain_when_creating_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'My Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'My Group')->first();

        // User should be a captain
        $this->assertTrue($user->isCaptainOf($group->id));

        // Check pivot table
        $pivot = $group->users()->where('users.id', $user->id)->first()->pivot;
        $this->assertEquals(1, $pivot->is_captain);
    }

    /** @test */
    public function user_can_view_group_details_if_member()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user)->get(route('propoff.groups.questions', $group));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Groups/Questions')
            ->has('group')
        );
    }

    /** @test */
    public function user_cannot_view_group_if_not_member()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->get(route('propoff.groups.questions', $group));

        $response->assertStatus(403);
    }

    /** @test */
    public function authenticated_user_auto_joins_group_via_play_hub()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'code' => 'ABC123',
        ]);

        // Visiting join form auto-adds authenticated users
        $response = $this->actingAs($user)->get(route('propoff.play.join', ['code' => 'ABC123']));

        $response->assertRedirect(route('propoff.play.hub', ['code' => 'ABC123']));
        $response->assertSessionHas('success');

        $this->assertTrue($group->fresh()->users->contains($user->id));
    }

    /** @test */
    public function authenticated_user_already_member_goes_to_hub()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'code' => 'ABC123',
        ]);

        // Already a member
        $group->users()->attach($user->id, ['joined_at' => now()]);

        // Visiting join form redirects to hub
        $response = $this->actingAs($user)->get(route('propoff.play.join', ['code' => 'ABC123']));

        $response->assertRedirect(route('propoff.play.hub', ['code' => 'ABC123']));
    }

    /** @test */
    public function guest_join_form_shows_correctly()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'code' => 'ABC123',
        ]);

        $response = $this->get(route('propoff.play.join', ['code' => 'ABC123']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Play/Join')
            ->has('group')
        );
    }

    /** @test */
    public function captain_can_update_group_settings()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create(['status' => 'draft']);
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'name' => 'Old Name',
            'is_public' => false,
        ]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->put(route('propoff.groups.update', $group), [
            'name' => 'New Name',
            'description' => 'New description',
            'is_public' => true,
            'entry_cutoff' => null,
        ]);

        $response->assertRedirect(route('propoff.groups.questions', $group));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('propoff_groups', [
            'id' => $group->id,
            'name' => 'New Name',
            'description' => 'New description',
            'is_public' => true,
        ]);
    }

    /** @test */
    public function non_captain_cannot_update_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user)->put(route('propoff.groups.update', $group), [
            'name' => 'Hacked Name',
            'is_public' => true,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('propoff_groups', ['name' => 'Hacked Name']);
    }

    /** @test */
    public function captain_can_delete_their_own_group()
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
        Entry::factory()->create([
            'user_id' => $captain->id,
            'event_id' => $event->id,
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($captain)->delete(route('propoff.groups.destroy', $group));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function non_captain_cannot_delete_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user)->delete(route('propoff.groups.destroy', $group));

        $response->assertStatus(403);
        $this->assertDatabaseHas('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function group_code_is_unique()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'Group 1',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'Group 2',
            'event_id' => $event->id,
            'grading_source' => 'admin',
        ]);

        $group1 = Group::where('name', 'Group 1')->first();
        $group2 = Group::where('name', 'Group 2')->first();

        $this->assertNotEquals($group1->code, $group2->code);
    }

    /** @test */
    public function created_group_has_8_character_code()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();
        $this->assertNotNull($group->code);
        $this->assertEquals(8, strlen($group->code));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $group->code);
    }

    /** @test */
    public function non_member_cannot_edit_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->get(route('propoff.groups.edit', $group));

        $response->assertStatus(403);
    }

    /** @test */
    public function captain_can_view_edit_form()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->get(route('propoff.groups.edit', $group));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Groups/Edit')
            ->has('group')
        );
    }

    /** @test */
    public function user_can_leave_group_if_not_creator()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'created_by' => $creator->id,
        ]);

        // Add both users to group
        $group->users()->attach($creator->id, ['is_captain' => true, 'joined_at' => now()]);
        $group->users()->attach($member->id, ['joined_at' => now()]);

        // Member can leave
        $response = $this->actingAs($member)->post(route('propoff.groups.leave', $group));

        $response->assertRedirect(route('propoff.groups.index'));
        $response->assertSessionHas('success');
        $this->assertFalse($group->fresh()->users->contains($member->id));
    }

    /** @test */
    public function creator_cannot_leave_group()
    {
        $creator = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'created_by' => $creator->id,
        ]);
        $group->users()->attach($creator->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($creator)->post(route('propoff.groups.leave', $group));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertTrue($group->fresh()->users->contains($creator->id));
    }

    /** @test */
    public function play_hub_with_invalid_code_returns_404()
    {
        $user = User::factory()->create();

        // Trying to join with an invalid code returns 404
        $response = $this->actingAs($user)->get(route('propoff.play.join', ['code' => 'INVALID']));

        $response->assertStatus(404);
    }

    /** @test */
    public function group_creation_requires_name()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->post(route('propoff.groups.store'), [
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $response->assertSessionHasErrors('name');
    }

    /** @test */
    public function group_creation_requires_event()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $response->assertSessionHasErrors('event_id');
    }

    /** @test */
    public function group_creation_requires_valid_grading_source()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'invalid',
        ]);

        $response->assertSessionHasErrors('grading_source');
    }

    /** @test */
    public function guest_can_join_group_via_play_hub()
    {
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'code' => 'ABC123',
        ]);

        // Guest submits their name via Play Hub join flow
        $response = $this->post(route('propoff.play.join.process', ['code' => 'ABC123']), [
            'name' => 'Guest User',
        ]);

        // Should redirect to the Play Hub
        $response->assertRedirect(route('propoff.play.hub', ['code' => 'ABC123']));

        // Guest user should be created
        $user = User::where('first_name', 'Guest')->where('last_name', 'User')->first();
        $this->assertNotNull($user);
        $this->assertEquals('guest', $user->role);

        // User should be in group
        $this->assertTrue($group->fresh()->users->contains($user->id));
    }

    /** @test */
    public function group_questions_are_copied_from_event_on_creation()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $eventQuestion1 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Question 1',
            'display_order' => 1,
        ]);
        $eventQuestion2 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Question 2',
            'display_order' => 2,
        ]);

        $this->actingAs($user)->post(route('propoff.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $group = Group::where('name', 'Test Group')->first();

        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'event_question_id' => $eventQuestion1->id,
            'question_text' => 'Question 1',
            'is_active' => true,
            'is_custom' => false,
        ]);

        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'event_question_id' => $eventQuestion2->id,
            'question_text' => 'Question 2',
            'is_active' => true,
            'is_custom' => false,
        ]);

        $this->assertEquals(2, GroupQuestion::where('group_id', $group->id)->count());
    }


    /** @test */
    public function admin_can_view_any_group()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.groups.questions', $group));

        $response->assertStatus(200);
    }

    /** @test */
    public function group_update_validates_name()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->put(route('propoff.groups.update', $group), [
            // Missing name
            'is_public' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    /** @test */
    public function group_update_validates_is_public()
    {
        $captain = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $group->users()->attach($captain->id, ['is_captain' => true, 'joined_at' => now()]);

        $response = $this->actingAs($captain)->put(route('propoff.groups.update', $group), [
            'name' => 'Test',
            // Missing is_public
        ]);

        $response->assertSessionHasErrors('is_public');
    }
}

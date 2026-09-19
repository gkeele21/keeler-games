<?php

namespace Tests\Feature\PropOff\Admin;

use App\Models\PropOff\Entry;
use App\Models\PropOff\Event;
use App\Models\PropOff\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_can_view_groups_index()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        Group::factory()->count(5)->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Groups/Index')
            ->has('groups')
        );
    }

    /** @test */
    public function admin_can_view_create_group_form()
    {
        $admin = User::factory()->manager()->create();
        Event::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Groups/GroupForm')
            ->has('events')
        );
    }

    /** @test */
    public function admin_can_create_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Group created successfully!');

        $this->assertDatabaseHas('propoff_groups', [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
            'created_by' => $admin->id,
        ]);
    }

    /** @test */
    public function admin_created_group_has_unique_code()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();

        $this->actingAs($admin)->post(route('propoff.admin.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'admin',
        ]);

        $group = Group::where('name', 'Test Group')->first();
        $this->assertNotNull($group->code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $group->code);
    }

    /** @test */
    public function admin_can_view_group_details()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        // Add some members
        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            $group->users()->attach($user->id, ['joined_at' => now()]);
        }

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.show', $group));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Groups/Show')
            ->has('group')
            ->has('stats')
            ->has('recentEntries')
            ->has('leaderboardPositions')
        );
    }

    /** @test */
    public function admin_can_view_edit_group_form()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.edit', $group));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Groups/GroupForm')
            ->has('group')
        );
    }

    /** @test */
    public function admin_can_update_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create([
            'event_id' => $event->id,
            'name' => 'Old Name',
            'code' => 'ABC123',
        ]);

        $response = $this->actingAs($admin)->patch(route('propoff.admin.groups.update', $group), [
            'name' => 'New Name',
            'code' => 'XYZ789',
        ]);

        $response->assertRedirect(route('propoff.admin.groups.show', $group));
        $response->assertSessionHas('success', 'Group updated successfully!');

        $this->assertDatabaseHas('propoff_groups', [
            'id' => $group->id,
            'name' => 'New Name',
            'code' => 'XYZ789',
        ]);
    }

    /** @test */
    public function admin_can_delete_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->delete(route('propoff.admin.groups.destroy', $group));

        $response->assertRedirect(route('propoff.admin.groups.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function admin_can_bulk_delete_groups()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group1 = Group::factory()->create(['event_id' => $event->id]);
        $group2 = Group::factory()->create(['event_id' => $event->id]);
        $group3 = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.bulkDelete'), [
            'group_ids' => [$group1->id, $group2->id, $group3->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '3 groups deleted successfully!');
        $this->assertDatabaseMissing('propoff_groups', ['id' => $group1->id]);
        $this->assertDatabaseMissing('propoff_groups', ['id' => $group2->id]);
        $this->assertDatabaseMissing('propoff_groups', ['id' => $group3->id]);
    }

    /** @test */
    public function admin_can_add_user_to_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $user = User::factory()->create(['email' => 'test@example.com']);

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.addUser', $group), [
            'email' => 'test@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertTrue($group->fresh()->users->contains($user->id));
    }

    /** @test */
    public function admin_cannot_add_same_user_twice_to_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $user = User::factory()->create(['email' => 'test@example.com']);

        // Add user first time
        $group->users()->attach($user->id, ['joined_at' => now()]);

        // Try to add again
        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.addUser', $group), [
            'email' => 'test@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'User is already a member of this group!');
    }

    /** @test */
    public function admin_can_remove_user_from_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $user = User::factory()->create();

        // Add user to group
        $group->users()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($admin)->delete(route('propoff.admin.groups.removeUser', [
            'group' => $group,
            'user' => $user,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertFalse($group->fresh()->users->contains($user->id));
    }

    /** @test */
    public function admin_cannot_remove_non_member_from_group()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->delete(route('propoff.admin.groups.removeUser', [
            'group' => $group,
            'user' => $user,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'User is not a member of this group!');
    }

    /** @test */
    public function admin_can_export_groups_csv()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        Group::factory()->count(5)->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.exportCSV'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('groups_', $response->headers->get('Content-Disposition'));
    }

    /** @test */
    public function admin_can_search_groups_by_name()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        Group::factory()->create(['name' => 'Football Team', 'event_id' => $event->id]);
        Group::factory()->create(['name' => 'Basketball Squad', 'event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.index', ['search' => 'Football']));

        $response->assertStatus(200);
        $groups = $response->viewData('page')['props']['groups']['data'];
        $this->assertCount(1, $groups);
        $this->assertEquals('Football Team', $groups[0]['name']);
    }

    /** @test */
    public function admin_can_search_groups_by_code()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        Group::factory()->create(['name' => 'Group 1', 'code' => 'ABC123', 'event_id' => $event->id]);
        Group::factory()->create(['name' => 'Group 2', 'code' => 'XYZ789', 'event_id' => $event->id]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.groups.index', ['search' => 'ABC123']));

        $response->assertStatus(200);
        $groups = $response->viewData('page')['props']['groups']['data'];
        $this->assertCount(1, $groups);
        $this->assertEquals('ABC123', $groups[0]['code']);
    }

    /** @test */
    public function group_belongs_to_event()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create(['name' => 'Super Bowl']);

        $this->actingAs($admin)->post(route('propoff.admin.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'admin',
        ]);

        $group = Group::where('name', 'Test Group')->first();
        $this->assertEquals($event->id, $group->event_id);
        $this->assertEquals('Super Bowl', $group->event->name);
    }

    /** @test */
    public function group_creation_requires_name()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.store'), [
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $response->assertSessionHasErrors('name');
    }

    /** @test */
    public function group_creation_requires_event()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.store'), [
            'name' => 'Test Group',
            'grading_source' => 'captain',
        ]);

        $response->assertSessionHasErrors('event_id');
    }

    /** @test */
    public function group_creation_requires_valid_grading_source()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'invalid',
        ]);

        $response->assertSessionHasErrors('grading_source');
    }

    /** @test */
    public function group_update_validates_code_format()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->patch(route('propoff.admin.groups.update', $group), [
            'name' => 'Test Group',
            'code' => 'invalid code with spaces',
        ]);

        $response->assertSessionHasErrors('code');
    }

    /** @test */
    public function group_update_validates_code_uniqueness()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group1 = Group::factory()->create(['event_id' => $event->id, 'code' => 'ABC123']);
        $group2 = Group::factory()->create(['event_id' => $event->id, 'code' => 'XYZ789']);

        $response = $this->actingAs($admin)->patch(route('propoff.admin.groups.update', $group2), [
            'name' => 'Test Group',
            'code' => 'ABC123', // Already taken by group1
        ]);

        $response->assertSessionHasErrors('code');
    }

    /** @test */
    public function non_admin_cannot_access_groups_index()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.admin.groups.index'));

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_create_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->post(route('propoff.admin.groups.store'), [
            'name' => 'Test Group',
            'event_id' => $event->id,
            'grading_source' => 'captain',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('propoff_groups', ['name' => 'Test Group']);
    }

    /** @test */
    public function non_admin_cannot_delete_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->delete(route('propoff.admin.groups.destroy', $group));

        $response->assertStatus(403);
        $this->assertDatabaseHas('propoff_groups', ['id' => $group->id]);
    }

    /** @test */
    public function non_admin_cannot_add_user_to_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $targetUser = User::factory()->create(['email' => 'test@example.com']);

        $response = $this->actingAs($user)->post(route('propoff.admin.groups.addUser', $group), [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_remove_user_from_group()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);
        $targetUser = User::factory()->create();

        $response = $this->actingAs($user)->delete(route('propoff.admin.groups.removeUser', [
            'group' => $group,
            'user' => $targetUser,
        ]));

        $response->assertStatus(403);
    }


    /** @test */
    public function non_admin_cannot_export_groups_csv()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.admin.groups.exportCSV'));

        $response->assertStatus(403);
    }

    /** @test */
    public function guest_cannot_access_group_management()
    {
        $response = $this->get(route('propoff.admin.groups.index'));

        // Unauthenticated visitors land on the landing page with the auth
        // drawer open; route('login') only forwards there.
        $response->assertRedirect('/?auth=login');
    }

    /** @test */
    public function add_user_validates_email_exists()
    {
        $admin = User::factory()->manager()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.addUser', $group), [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    }

    /** @test */
    public function bulk_delete_validates_group_ids()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.bulkDelete'), [
            'group_ids' => [99999], // Non-existent group ID
        ]);

        $response->assertSessionHasErrors('group_ids.0');
    }

    /** @test */
    public function bulk_delete_requires_array_of_group_ids()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.groups.bulkDelete'), [
            'group_ids' => 'not-an-array',
        ]);

        $response->assertSessionHasErrors('group_ids');
    }

}

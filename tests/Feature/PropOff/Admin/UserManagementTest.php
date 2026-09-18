<?php

namespace Tests\Feature\PropOff\Admin;

use App\Models\PropOff\Entry;
use App\Models\PropOff\Event;
use App\Models\PropOff\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_can_view_users_index()
    {
        $admin = User::factory()->manager()->create();
        User::factory()->count(5)->create();

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Users/Index')
            ->has('users')
        );
    }

    /** @test */
    public function admin_can_view_user_statistics()
    {
        $this->markTestSkipped('Admin/Users/Statistics component not yet implemented');

        $admin = User::factory()->manager()->create();
        User::factory()->count(10)->create();

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.statistics'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Users/Statistics')
            ->has('stats')
            ->has('usersByMonth')
            ->has('topParticipants')
        );
    }

    /** @test */
    public function admin_can_view_individual_user()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        // Add user to group
        $group->users()->attach($user->id, ['joined_at' => now()]);

        // Create an entry
        Entry::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.show', $user));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Users/Show')
            ->has('user')
            ->has('stats')
            ->has('recentEntries')
            ->has('leaderboardPositions')
        );
    }

    /** @test */
    public function admin_can_delete_user()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->delete(route('propoff.admin.users.destroy', $user));

        $response->assertRedirect(route('propoff.admin.users.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /** @test */
    public function admin_cannot_delete_themselves()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->delete(route('propoff.admin.users.destroy', $admin));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You cannot delete yourself!');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /** @test */
    public function admin_can_bulk_delete_users()
    {
        $admin = User::factory()->manager()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.bulkDelete'), [
            'user_ids' => [$user1->id, $user2->id, $user3->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '3 users deleted successfully!');
        $this->assertDatabaseMissing('users', ['id' => $user1->id]);
        $this->assertDatabaseMissing('users', ['id' => $user2->id]);
        $this->assertDatabaseMissing('users', ['id' => $user3->id]);
    }

    /** @test */
    public function admin_cannot_bulk_delete_themselves()
    {
        $admin = User::factory()->manager()->create();
        $user1 = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.bulkDelete'), [
            'user_ids' => [$admin->id, $user1->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You cannot delete yourself!');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /** @test */
    public function admin_can_view_user_activity()
    {
        $this->markTestSkipped('Admin/Users/Activity component not yet implemented');

        $admin = User::factory()->manager()->create();
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $group = Group::factory()->create(['event_id' => $event->id]);

        // Add user to group
        $group->users()->attach($user->id, ['joined_at' => now()]);

        // Create an entry
        Entry::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.activity', $user));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('PropOff/Admin/Users/Activity')
            ->has('user')
            ->has('entries')
            ->has('groupActivity')
        );
    }

    /** @test */
    public function admin_can_update_user_role_to_admin()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.updateRole', $user), [
            'role' => 'admin',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User role updated to admin!');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'admin',
        ]);
    }

    /** @test */
    public function admin_can_update_user_role_to_user()
    {
        $admin = User::factory()->manager()->create();
        $anotherAdmin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.updateRole', $anotherAdmin), [
            'role' => 'user',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User role updated to user!');
        $this->assertDatabaseHas('users', [
            'id' => $anotherAdmin->id,
            'role' => 'user',
        ]);
    }

    /** @test */
    public function admin_cannot_demote_themselves()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.updateRole', $admin), [
            'role' => 'user',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You cannot change your own role!');
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => 'manager',
        ]);
    }

    /** @test */
    public function update_role_validates_role_input()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.updateRole', $user), [
            'role' => 'invalid_role',
        ]);

        $response->assertSessionHasErrors('role');
    }

    /** @test */
    public function admin_can_export_users_csv()
    {
        $admin = User::factory()->manager()->create();
        User::factory()->count(5)->create();

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.exportCSV'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('users_', $response->headers->get('Content-Disposition'));
    }

    /** @test */
    public function admin_can_filter_users_by_role()
    {
        $admin = User::factory()->manager()->create();
        User::factory()->count(3)->create(['role' => 'user']);
        User::factory()->count(2)->admin()->create();

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.index', ['role' => 'user']));

        $response->assertStatus(200);
        $users = $response->viewData('page')['props']['users']['data'];

        foreach ($users as $user) {
            // All filtered users should be regular users
            if ($user['id'] !== $admin->id) {
                $this->assertEquals('user', $user['role']);
            }
        }
    }

    /** @test */
    public function admin_can_search_users_by_name()
    {
        $admin = User::factory()->manager()->create();
        User::factory()->create(['name' => 'John Smith']);
        User::factory()->create(['name' => 'Jane Doe']);

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.index', ['search' => 'John']));

        $response->assertStatus(200);
        $users = $response->viewData('page')['props']['users']['data'];
        $this->assertCount(1, $users);
        $this->assertEquals('John Smith', $users[0]['name']);
    }

    /** @test */
    public function admin_can_search_users_by_email()
    {
        $admin = User::factory()->manager()->create();
        User::factory()->create(['email' => 'john@example.com']);
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.index', ['search' => 'john@']));

        $response->assertStatus(200);
        $users = $response->viewData('page')['props']['users']['data'];
        $this->assertCount(1, $users);
        $this->assertEquals('john@example.com', $users[0]['email']);
    }

    /** @test */
    public function non_admin_cannot_access_users_index()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.admin.users.index'));

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_view_user_details()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.admin.users.show', $otherUser));

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_delete_users()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->delete(route('propoff.admin.users.destroy', $otherUser));

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    }

    /** @test */
    public function non_admin_cannot_update_user_roles()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->post(route('propoff.admin.users.updateRole', $otherUser), [
            'role' => 'admin',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'role' => 'user',
        ]);
    }

    /** @test */
    public function non_admin_cannot_view_user_statistics()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.admin.users.statistics'));

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_export_users_csv()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('propoff.admin.users.exportCSV'));

        $response->assertStatus(403);
    }

    /** @test */
    public function guest_cannot_access_user_management()
    {
        $response = $this->get(route('propoff.admin.users.index'));

        // Unauthenticated visitors land on the landing page with the auth
        // drawer open; route('login') only forwards there.
        $response->assertRedirect('/?auth=login');
    }

    /** @test */
    public function deleted_user_data_is_removed_from_database()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User']);

        $this->assertDatabaseHas('users', ['first_name' => 'Test', 'last_name' => 'User']);

        $this->actingAs($admin)->delete(route('propoff.admin.users.destroy', $user));

        $this->assertDatabaseMissing('users', ['first_name' => 'Test', 'last_name' => 'User']);
    }

    /** @test */
    public function user_statistics_show_correct_counts()
    {
        $admin = User::factory()->manager()->create();

        // Create users with different roles
        User::factory()->count(5)->create(['role' => 'user']);
        User::factory()->count(2)->admin()->create();
        User::factory()->count(3)->create(['role' => 'user', 'email_verified_at' => null]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.users.statistics'));

        $response->assertStatus(200);
        $stats = $response->viewData('page')['props']['stats'];

        $this->assertGreaterThanOrEqual(8, $stats['total_users']); // Including the test admin
        // Actor is a manager in keeler (manager tier sits above admin)
        $this->assertGreaterThanOrEqual(2, $stats['admin_count']);
        $this->assertGreaterThanOrEqual(5, $stats['regular_users']);
    }

    /** @test */
    public function bulk_delete_validates_user_ids()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.bulkDelete'), [
            'user_ids' => [99999], // Non-existent user ID
        ]);

        $response->assertSessionHasErrors('user_ids.0');
    }

    /** @test */
    public function bulk_delete_requires_array_of_user_ids()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(route('propoff.admin.users.bulkDelete'), [
            'user_ids' => 'not-an-array',
        ]);

        $response->assertSessionHasErrors('user_ids');
    }
}

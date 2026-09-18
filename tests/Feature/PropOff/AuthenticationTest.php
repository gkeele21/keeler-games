<?php

namespace Tests\Feature\PropOff;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function users_can_view_login_page()
    {
        $response = $this->get(route('login'));

        // Auth is a drawer on the landing page, so this redirects rather than
        // rendering a page of its own.
        $response->assertRedirect('/?auth=login');
    }

    /** @test */
    public function users_can_authenticate_with_valid_credentials()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));
    }

    /** @test */
    public function users_cannot_authenticate_with_invalid_password()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /** @test */
    public function users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /** @test */
    public function users_can_view_registration_page()
    {
        $response = $this->get(route('register'));

        $response->assertRedirect('/?auth=register');
    }

    /** @test */
    public function users_can_register()
    {
        $response = $this->post(route('register'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role' => 'user',
        ]);
    }

    /** @test */
    public function registration_requires_name()
    {
        // Keeler registration takes first/last name instead of a single name
        $response = $this->post(route('register'), [
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors(['first_name', 'last_name']);
    }


    /** @test */
    public function registration_requires_valid_email()
    {
        $response = $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'not-an-email',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
    }

    /** @test */
    public function registration_requires_unique_email()
    {
        $existingUser = User::factory()->create(['email' => 'test@example.com']);

        $response = $this->post(route('register'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
    }

    /** @test */
    public function registration_requires_password_confirmation()
    {
        $response = $this->post(route('register'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
    }

    /** @test */
    public function authenticated_users_cannot_view_login_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    }

    /** @test */
    public function authenticated_users_cannot_view_registration_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('register'));

        $response->assertRedirect(route('dashboard'));
    }
}

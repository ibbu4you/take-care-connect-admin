<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_active_staff_member_can_sign_in(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR, [
            'email' => 'anchor@example.test',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->post('/login', [
            'email' => 'anchor@example.test',
            'password' => 'correct-horse',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_signing_in_records_the_last_login(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR, [
            'email' => 'anchor@example.test',
            'password' => Hash::make('correct-horse'),
            'last_login_at' => null,
        ]);

        $this->post('/login', [
            'email' => 'anchor@example.test',
            'password' => 'correct-horse',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_wrong_password_is_rejected(): void
    {
        $this->userWithRole(Roles::ANCHOR, [
            'email' => 'anchor@example.test',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->post('/login', [
            'email' => 'anchor@example.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * A former employee holding a still-valid password must not get in, and
     * must not be told that the account exists.
     */
    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $this->seedRoles();

        $user = User::factory()->inactive()->create([
            'email' => 'gone@example.test',
            'password' => Hash::make('correct-horse'),
        ]);
        $user->assignRole(Roles::ANCHOR);

        $this->post('/login', [
            'email' => 'gone@example.test',
            'password' => 'correct-horse',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_account_with_no_role_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'nobody@example.test',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'correct-horse',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * The failure message must be identical whether the account is missing,
     * deactivated, or the password is simply wrong — otherwise this form
     * becomes a way to enumerate who works here.
     */
    public function test_every_failure_gives_the_same_message(): void
    {
        $this->seedRoles();

        $deactivated = User::factory()->inactive()->create([
            'email' => 'gone@example.test',
            'password' => Hash::make('correct-horse'),
        ]);
        $deactivated->assignRole(Roles::ANCHOR);

        $wrongPassword = $this->post('/login', [
            'email' => 'gone@example.test',
            'password' => 'nope',
        ])->assertSessionHasErrors('email');

        $messages = session('errors')->get('email');
        session()->forget('errors');

        $this->post('/login', [
            'email' => 'never-existed@example.test',
            'password' => 'nope',
        ])->assertSessionHasErrors('email');

        $this->assertSame($messages, session('errors')->get('email'));
        $this->assertNotNull($wrongPassword);
    }

    /**
     * Deactivation has to bite mid-session, not at the next login — sessions
     * here last for days.
     */
    public function test_deactivating_an_account_ends_its_session_on_the_next_request(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_signed_in_user_can_sign_out(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_root_path_routes_by_authentication_state(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->get('/')
            ->assertRedirect('/dashboard');
    }
}

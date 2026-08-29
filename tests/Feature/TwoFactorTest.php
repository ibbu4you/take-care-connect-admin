<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Auth\TwoFactorAuthenticator;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function authenticator(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    /** A valid code for right now, as an authenticator app would produce. */
    private function currentCode(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_a_user_without_two_factor_reaches_the_dashboard(): void
    {

        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_enrolling_stores_the_secret_and_issues_recovery_codes(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);
        $secret = $this->authenticator()->generateSecret();

        $this->actingAs($user)
            ->withSession(['two_factor_pending_secret' => $secret])
            ->post('/two-factor/setup', ['code' => $this->currentCode($secret)])
            ->assertRedirect('/profile')
            ->assertSessionHas('recovery_codes');

        $user = $user->fresh();

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_a_wrong_code_does_not_enrol(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);
        $secret = $this->authenticator()->generateSecret();

        $this->actingAs($user)
            ->withSession(['two_factor_pending_secret' => $secret])
            ->post('/two-factor/setup', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    /**
     * The secret must not touch the user row until a code has been verified —
     * otherwise closing the tab mid-enrolment leaves an account demanding codes
     * from an app that was never set up.
     */
    public function test_the_secret_is_not_stored_before_a_code_is_verified(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($user)->get('/two-factor/setup')->assertOk();

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_an_enrolled_user_is_sent_to_the_challenge(): void
    {
        $user = $this->enrolled();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/two-factor/challenge');
    }

    public function test_a_valid_code_passes_the_challenge(): void
    {
        $user = $this->enrolled();
        $secret = $user->two_factor_secret;

        $this->actingAs($user)
            ->post('/two-factor/challenge', ['code' => $this->currentCode($secret)])
            ->assertRedirect('/dashboard');

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_a_wrong_code_does_not_pass_the_challenge(): void
    {
        $user = $this->enrolled();

        $this->actingAs($user)
            ->post('/two-factor/challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/challenge');
    }

    public function test_a_recovery_code_works_and_is_spent(): void
    {
        $user = $this->enrolled();
        $codes = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->post('/two-factor/challenge', ['code' => $codes[0]])
            ->assertRedirect('/dashboard');

        $remaining = $user->fresh()->two_factor_recovery_codes;

        $this->assertCount(count($codes) - 1, $remaining);
        $this->assertNotContains($codes[0], $remaining, 'A recovery code must work exactly once.');
    }

    public function test_a_spent_recovery_code_cannot_be_used_again(): void
    {
        $user = $this->enrolled();
        $codes = $user->two_factor_recovery_codes;

        $this->actingAs($user)->post('/two-factor/challenge', ['code' => $codes[0]]);

        // Fresh session, as if signing in again on another day.
        $this->app['auth']->guard('web')->logout();
        session()->flush();

        $this->actingAs($user->fresh())
            ->post('/two-factor/challenge', ['code' => $codes[0]])
            ->assertSessionHasErrors('code');
    }

    /*
     | ── Nobody is ever pushed into enrolling ───────────────────────────────
     |
     | Admins used to be redirected to the setup screen before they could reach
     | anything else. Being met by a QR code on the way to your own dashboard is
     | a poor way to be introduced to a security feature, and nobody asked for
     | it. Two-factor is opt-in now, and these assert that it stays that way.
     */

    public function test_an_admin_is_never_sent_to_enrol(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_nobody_of_any_role_is_sent_to_enrol(): void
    {
        foreach (Roles::names() as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/dashboard')
                ->assertOk("[{$role}] was redirected away from the dashboard.");
        }
    }

    public function test_turning_it_off_requires_the_current_password(): void
    {
        $user = $this->enrolled(['password' => Hash::make('correct-horse')]);

        $this->actingAs($user)
            ->withSession(['two_factor_passed' => true])
            ->delete('/two-factor', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->actingAs($user)
            ->withSession(['two_factor_passed' => true])
            ->delete('/two-factor', ['password' => 'correct-horse'])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    /** Anybody who turned it on can turn it off again, whatever their role. */
    public function test_an_admin_can_turn_their_own_second_factor_off(): void
    {
        $user = $this->enrolled(['password' => Hash::make('correct-horse')], Roles::ADMIN);

        $this->actingAs($user)
            ->withSession(['two_factor_passed' => true])
            ->delete('/two-factor', ['password' => 'correct-horse'])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_challenge_screen_is_reachable_without_having_passed_it(): void
    {
        $this->actingAs($this->enrolled())
            ->get('/two-factor/challenge')
            ->assertOk();
    }

    /** A locked-out person must always be able to get out of the challenge. */
    public function test_a_user_can_sign_out_from_the_challenge(): void
    {
        $this->actingAs($this->enrolled())
            ->post('/two-factor/abandon')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrolled(array $attributes = [], string $role = Roles::ANCHOR): User
    {
        $user = $this->userWithRole($role, $attributes);
        $authenticator = $this->authenticator();

        $user->forceFill([
            'two_factor_secret' => $authenticator->generateSecret(),
            'two_factor_recovery_codes' => $authenticator->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }
}

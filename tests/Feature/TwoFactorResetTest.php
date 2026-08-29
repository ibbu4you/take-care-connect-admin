<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The way back in after a lost phone.
 *
 * Two-factor is required for administrators and the enrolment lives on one
 * device, so "I dropped my phone" is a genuine lockout rather than an
 * inconvenience. There are two ways out — another administrator clearing it,
 * and a console command for the case where the locked-out person is the only
 * administrator — and the first removes a login factor from an account that is
 * not yours, so its guards matter.
 */
class TwoFactorResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_an_admin_can_clear_a_lost_enrolment(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $anchor = $this->enrolled($this->userWithRole(Roles::ANCHOR));

        $this->actingAs($admin)
            ->delete("/users/{$anchor->id}/two-factor")
            ->assertRedirect();

        $this->assertFalse($anchor->fresh()->hasTwoFactorEnabled());
    }

    /**
     * Admins are peers, so one can help another back in after a lost phone.
     * That is the ordinary case this button exists for.
     */
    public function test_an_admin_can_clear_another_admins_enrolment(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $other = $this->enrolled($this->userWithRole(Roles::ADMIN));

        $this->actingAs($admin)
            ->delete("/users/{$other->id}/two-factor")
            ->assertRedirect();

        $this->assertFalse($other->fresh()->hasTwoFactorEnabled());
    }

    /**
     * There is a better path for your own: Profile, which asks for the current
     * password first. This one does not, so it must not work on yourself.
     */
    public function test_nobody_can_clear_their_own_from_here(): void
    {
        $admin = $this->enrolled($this->userWithRole(Roles::ADMIN));

        /*
         | The session flag is needed because the actor here is enrolled, and
         | RequireTwoFactor would otherwise send them to the challenge before
         | the policy is ever consulted — a 302 that would pass for a refusal
         | while proving nothing about the rule under test.
         */
        $this->actingAs($admin)
            ->withSession(['two_factor_passed' => true])
            ->delete("/users/{$admin->id}/two-factor")
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_somebody_without_the_permission_cannot_clear_one(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $writer = $this->enrolled($this->userWithRole(Roles::CONTENT));

        $this->actingAs($anchor)
            ->delete("/users/{$writer->id}/two-factor")
            ->assertForbidden();

        $this->assertTrue($writer->fresh()->hasTwoFactorEnabled());
    }

    /**
     * A reset usually follows "somebody may have my phone", and a session that
     * already passed the gate keeps that flag for its lifetime.
     */
    public function test_clearing_ends_their_signed_in_sessions(): void
    {
        // Production stores sessions in the database; the test suite uses the
        // array driver, and this behaviour only exists for the former.
        config()->set('session.driver', 'database');

        $admin = $this->userWithRole(Roles::ADMIN);
        $anchor = $this->enrolled($this->userWithRole(Roles::ANCHOR));

        DB::table('sessions')->insert([
            'id' => 'a-live-session',
            'user_id' => $anchor->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->delete("/users/{$anchor->id}/two-factor");

        $this->assertDatabaseMissing('sessions', ['user_id' => $anchor->id]);
    }

    /** The offer is only made where it would do something. */
    public function test_the_list_offers_the_reset_only_for_an_enrolled_account(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $enrolled = $this->enrolled($this->userWithRole(Roles::ANCHOR));
        $plain = $this->userWithRole(Roles::CONTENT);

        $this->actingAs($admin)
            ->get("/users/{$enrolled->id}/edit")
            ->assertInertia(fn ($page) => $page->where('user.can.reset_two_factor', true));

        $this->actingAs($admin)
            ->get("/users/{$plain->id}/edit")
            ->assertInertia(fn ($page) => $page->where('user.can.reset_two_factor', false));
    }

    public function test_clearing_one_is_recorded_in_the_audit_log(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $anchor = $this->enrolled($this->userWithRole(Roles::ANCHOR));

        $this->actingAs($admin)->delete("/users/{$anchor->id}/two-factor");

        $this->assertDatabaseHas('activity_log', [
            'description' => 'two-factor reset',
            'subject_id' => $anchor->id,
            'causer_id' => $admin->id,
        ]);
    }

    // ── The console escape hatch ───────────────────────────────────────────

    /**
     * The case the UI cannot cover: the only administrator, locked out, with
     * nobody left who could click the button. This is why the console command
     * exists at all, and it is the recovery path the Super Admin role used to
     * be — except this one works when nobody can sign in.
     */
    public function test_the_console_command_clears_an_enrolment(): void
    {
        $owner = $this->enrolled($this->userWithRole(Roles::ADMIN));

        $this->artisan('tcc:two-factor-reset', ['email' => $owner->email])
            ->expectsConfirmation("Clear two-factor for {$owner->name} <{$owner->email}>?", 'yes')
            ->assertSuccessful();

        $this->assertFalse($owner->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_console_command_leaves_it_alone_when_declined(): void
    {
        $owner = $this->enrolled($this->userWithRole(Roles::ADMIN));

        $this->artisan('tcc:two-factor-reset', ['email' => $owner->email])
            ->expectsConfirmation("Clear two-factor for {$owner->name} <{$owner->email}>?", 'no')
            ->assertSuccessful();

        $this->assertTrue($owner->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_console_command_reports_an_unknown_address(): void
    {
        $this->artisan('tcc:two-factor-reset', ['email' => 'nobody@takecareconnect.com'])
            ->assertFailed();
    }

    private function enrolled(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['one', 'two'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }
}

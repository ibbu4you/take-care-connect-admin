<?php

namespace Tests\Feature;

use App\Models\Interview;
use App\Models\User;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Account administration.
 *
 * The interesting tests here are not "can an admin create a user" — they are the
 * guards that stop somebody locking the organisation out of itself, or handing
 * themselves more power than they were given.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Admins are obliged to carry a second factor, which would send
        // every request in this file to the enrolment screen. 2FA has its own
        // test file; here it is noise.
    }

    public function test_an_admin_can_see_the_staff_list(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get('/users')
            ->assertOk();
    }

    public function test_an_anchor_cannot_see_the_staff_list(): void
    {
        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->get('/users')
            ->assertForbidden();
    }

    public function test_an_admin_can_create_an_account(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/users', [
                'name' => 'Nikhil Sharma',
                'email' => 'nikhil@example.test',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
            ])
            ->assertRedirect('/users');

        $created = User::firstWhere('email', 'nikhil@example.test');

        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole(Roles::ANCHOR));
        $this->assertTrue($created->is_active);
    }

    public function test_an_account_cannot_be_created_without_a_role(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/users', [
                'name' => 'Nobody',
                'email' => 'nobody@example.test',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
                'roles' => [],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
    }

    /**
     * The escalation route that matters most: `users.update` must not be a way
     * to award yourself the role that bypasses every check.
     */
    /**
     * Admin is the top role, so granting it is exactly as consequential as
     * `users.update` itself — which is why holding that is the requirement.
     * Somebody trusted to edit accounts is trusted to say who administers them.
     */
    public function test_an_admin_can_appoint_another_admin(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/users', [
                'name' => 'Second Administrator',
                'email' => 'second@example.test',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
                'roles' => [Roles::ADMIN],
            ])
            ->assertRedirect('/users');

        $this->assertTrue(User::firstWhere('email', 'second@example.test')->isAdmin());
    }

    public function test_a_role_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/users', [
                'name' => 'Climber',
                'email' => 'climber@example.test',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
                'roles' => ['Super Admin'],
            ])
            // roles.0, not roles: the rule is on each element, and the role
            // that used to exist is now just an unknown string like any other.
            ->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('users', ['email' => 'climber@example.test']);
    }

    /**
     * Admins are peers now. There used to be a role above this one that an
     * Admin could not touch; it granted the same permissions, so it protected
     * a distinction nobody could see.
     */
    public function test_admins_can_edit_each_other(): void
    {
        $other = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get("/users/{$other->id}/edit")
            ->assertOk();
    }

    public function test_nobody_can_deactivate_their_own_account(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($admin)
            ->patch("/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'roles' => [Roles::ADMIN],
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_nobody_can_remove_their_own_administrator_role(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($admin)
            ->patch("/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
            ])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($admin->fresh()->hasRole(Roles::ADMIN));
    }

    public function test_nobody_can_delete_their_own_account(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($admin)
            ->delete("/users/{$admin->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($admin);
    }

    /*
     | ── The last administrator ─────────────────────────────────────────────
     |
     | This is what replaced the Super Admin. An organisation with no active
     | Admin has nobody who can create an account, fix a permission or promote
     | anybody, and no way back except editing the database by hand. Every door
     | to that state has to be held shut: deletion, demotion, deactivation and
     | revocation.
     */

    /**
     * Reachable because `users.delete` can be direct-granted to somebody who is
     * not an Admin — an office manager given account permissions, say. Without
     * the guard, they could remove the only administrator.
     */
    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole(Roles::ADMIN);

        $assistant = $this->userWithRole(Roles::CONTENT);
        $assistant->givePermissionTo(['users.view', 'users.delete']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($assistant->fresh())
            ->delete("/users/{$owner->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($owner);
    }

    public function test_the_last_admin_cannot_be_deactivated(): void
    {
        $owner = $this->userWithRole(Roles::ADMIN);

        $assistant = $this->userWithRole(Roles::CONTENT);
        $assistant->givePermissionTo(['users.view', 'users.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($assistant->fresh())
            ->patch("/users/{$owner->id}/active")
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $owner = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($owner)
            ->patch("/users/{$owner->id}", [
                'name' => $owner->name,
                'email' => $owner->email,
                'roles' => [Roles::CONTENT],
                'is_active' => true,
            ])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($owner->fresh()->isAdmin());
    }

    public function test_an_admin_can_be_demoted_when_another_active_one_exists(): void
    {
        $first = $this->userWithRole(Roles::ADMIN);
        $second = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($second)
            ->patch("/users/{$first->id}", [
                'name' => $first->name,
                'email' => $first->email,
                'roles' => [Roles::CONTENT],
                'is_active' => true,
            ])
            ->assertRedirect('/users');

        $this->assertFalse($first->fresh()->isAdmin());
    }

    /**
     * A deactivated Admin cannot sign in to fix anything, so they are not a
     * fallback — which makes the remaining active one the last.
     */
    public function test_a_deactivated_admin_does_not_count_as_a_fallback(): void
    {
        $active = $this->userWithRole(Roles::ADMIN);
        $this->userWithRole(Roles::ADMIN, ['is_active' => false]);

        $this->actingAs($active)
            ->patch("/users/{$active->id}", [
                'name' => $active->name,
                'email' => $active->email,
                'roles' => [Roles::CONTENT],
                'is_active' => true,
            ])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($active->fresh()->isAdmin());
    }

    /**
     * Demotion and revocation are the same door.
     *
     * Revocations apply to admins now, so without this, taking `users.update`
     * away from the only Admin would leave nobody able to give it back.
     */
    public function test_the_last_admin_cannot_have_administration_revoked(): void
    {
        $owner = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($owner)
            ->patch("/users/{$owner->id}", [
                'name' => $owner->name,
                'email' => $owner->email,
                'roles' => [Roles::ADMIN],
                'is_active' => true,
                'denied_permissions' => ['roles.update'],
            ])
            ->assertSessionHasErrors('denied_permissions');

        $this->assertTrue($owner->fresh()->can('roles.update'));
    }

    public function test_an_admin_can_grant_an_extra_permission_to_one_person(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/users/{$anchor->id}", [
                'name' => $anchor->name,
                'email' => $anchor->email,
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
                'direct_permissions' => ['interviews.view.all'],
            ])
            ->assertRedirect('/users');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($anchor->fresh()->can('interviews.view.all'));
    }

    public function test_an_admin_can_revoke_a_role_granted_permission_from_one_person(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/users/{$manager->id}", [
                'name' => $manager->name,
                'email' => $manager->email,
                'roles' => [Roles::RELATIONSHIP_MANAGER],
                'is_active' => true,
                'denied_permissions' => ['deals.close'],
            ])
            ->assertRedirect('/users');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $manager->fresh();

        $this->assertFalse($manager->can('deals.close'));
        $this->assertTrue($manager->can('deals.move'));
    }

    /**
     * A revocation for something no role grants would sit in the column
     * forever, waiting to silently block a permission granted years later for
     * an unrelated reason.
     */
    public function test_a_revocation_is_discarded_when_no_role_grants_it(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/users/{$anchor->id}", [
                'name' => $anchor->name,
                'email' => $anchor->email,
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
                // The Anchor role does not grant this, so revoking it is a no-op
                // and must not be stored.
                'denied_permissions' => ['settings.update'],
            ])
            ->assertRedirect('/users');

        $this->assertSame([], $anchor->fresh()->denied_permissions);
    }

    /**
     * `users.update` alone must not be a way to hand out permissions — that is
     * what the separate `users.permissions.manage` string is for.
     */
    public function test_permission_changes_are_ignored_without_the_permission_to_make_them(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $admin->update(['denied_permissions' => ['users.permissions.manage']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($admin->fresh())
            ->patch("/users/{$anchor->id}", [
                'name' => $anchor->name,
                'email' => $anchor->email,
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
                'direct_permissions' => ['settings.update'],
            ])
            ->assertRedirect('/users');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($anchor->fresh()->can('settings.update'));
    }

    public function test_deleting_an_account_soft_deletes_it_so_its_work_keeps_an_author(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->delete("/users/{$anchor->id}")
            ->assertRedirect('/users');

        $this->assertSoftDeleted($anchor);
    }

    public function test_an_account_can_be_deactivated_from_the_list(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/users/{$anchor->id}/active")
            ->assertRedirect();

        $this->assertFalse($anchor->fresh()->is_active);
    }

    public function test_editing_an_account_is_recorded_in_the_audit_log(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/users/{$anchor->id}", [
                'name' => 'Renamed Person',
                'email' => $anchor->email,
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
            ]);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $anchor->id,
            'event' => 'updated',
        ]);
    }

    /** The log is readable by every Admin, so credentials must never reach it. */
    public function test_the_audit_log_never_records_a_password(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/users/{$anchor->id}", [
                'name' => $anchor->name,
                'email' => $anchor->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
                'roles' => [Roles::ANCHOR],
                'is_active' => true,
            ]);

        foreach (Activity::all() as $entry) {
            $encoded = json_encode($entry->properties);

            $this->assertStringNotContainsString('password', (string) $encoded);
            $this->assertStringNotContainsString('a-brand-new-password', (string) $encoded);
        }
    }

    /**
     * The list must not offer a button the policy will refuse.
     *
     * An edit pencil that answers 403 on click reads as a broken application
     * rather than as a refused action, so the policy is asked per row and the
     * answer travels with the data.
     */
    public function test_the_list_reports_what_the_viewer_may_do_to_each_row(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $admin = $this->userWithRole(Roles::ADMIN);
        $second = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($admin)
            ->get('/users')
            ->assertInertia(function (AssertableInertia $page) use ($anchor, $admin, $second) {
                $rows = collect($page->toArray()['props']['users']['data'])->keyBy('id');

                // An ordinary colleague is fully manageable.
                $this->assertTrue($rows[$anchor->id]['can']['edit']);
                $this->assertTrue($rows[$anchor->id]['can']['delete']);
                $this->assertTrue($rows[$anchor->id]['can']['toggle']);

                // Admins are peers: another one is manageable like anybody else,
                // because this is not the last one left.
                $this->assertTrue($rows[$second->id]['can']['edit']);
                $this->assertTrue($rows[$second->id]['can']['delete']);
                $this->assertTrue($rows[$second->id]['can']['toggle']);

                // Their own row: editable, but not deletable or deactivatable.
                $this->assertTrue($rows[$admin->id]['can']['edit']);
                $this->assertFalse($rows[$admin->id]['can']['delete']);
                $this->assertFalse($rows[$admin->id]['can']['toggle']);
            });
    }

    /** The last administrator is offered no destructive button at all. */
    public function test_the_list_offers_nothing_destructive_against_the_last_admin(): void
    {
        $owner = $this->userWithRole(Roles::ADMIN);

        $assistant = $this->userWithRole(Roles::CONTENT);
        $assistant->givePermissionTo(['users.view', 'users.update', 'users.delete']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($assistant->fresh())
            ->get('/users')
            ->assertInertia(function (AssertableInertia $page) use ($owner) {
                $rows = collect($page->toArray()['props']['users']['data'])->keyBy('id');

                $this->assertTrue($rows[$owner->id]['can']['edit'], 'Editing a name is harmless.');
                $this->assertFalse($rows[$owner->id]['can']['delete']);
                $this->assertFalse($rows[$owner->id]['can']['toggle']);
            });
    }

    /**
     * The audit log has to survive every shape a model can store.
     *
     * It does not: an interview's `qa` is a list of question/answer pairs, and
     * flattening it hit strval() on an array and took the whole page down. This
     * asserts the page renders with a nested-array value in the log.
     */
    public function test_the_audit_log_renders_records_holding_nested_values(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $interview = Interview::create([
            'code' => 'INT-000999',
            'kind' => InterviewKind::BusinessArticle,
            'title' => 'A nested-value interview',
            'subject_name' => 'Somebody',
            'anchor_id' => $anchor->id,
            'scheduled_by' => $manager->id,
            'scheduled_at' => now(),
            'mode' => 'google_meet',
            'stage' => InterviewStage::Scheduled,
            'priority' => 'normal',
        ]);

        // A list of arrays, and a list of strings — the two shapes that broke it.
        $interview->update(['qa' => [['question' => 'Q', 'answer' => 'A']]]);
        $anchor->update(['denied_permissions' => ['interviews.conduct']]);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get('/audit')
            ->assertOk();
    }
}

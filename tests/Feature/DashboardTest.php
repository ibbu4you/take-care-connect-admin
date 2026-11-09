<?php

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dashboard is composed from permissions rather than role names, so these
 * assert that each role is offered exactly the panels its work needs.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_renders_for_a_signed_in_user(): void
    {
        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('greeting')
                ->has('panels'),
            );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function rolePanels(): array
    {
        return [
            'anchor' => [Roles::ANCHOR, ['interviews'], ['sales', 'organisation']],
            'content' => [Roles::CONTENT, ['content', 'interviews'], ['sales']],
            'creative' => [Roles::CREATIVE, ['creative', 'interviews'], ['sales', 'content', 'organisation']],
            'relationship manager' => [Roles::RELATIONSHIP_MANAGER, ['sales', 'interviews'], ['content']],
            'telecaller' => [Roles::TELECALLER, ['leads', 'interviews'], ['sales', 'content', 'creative', 'organisation']],
            'admin' => [Roles::ADMIN, ['sales', 'interviews', 'content', 'creative', 'organisation'], []],
        ];
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $forbidden
     */
    #[DataProvider('rolePanels')]
    public function test_each_role_sees_the_right_panels(string $role, array $expected, array $forbidden): void
    {
        $response = $this->actingAs($this->userWithRole($role))->get('/dashboard');

        $response->assertOk()->assertInertia(function (AssertableInertia $page) use ($expected, $forbidden) {
            foreach ($expected as $panel) {
                $page->where("panels.{$panel}", true);
            }

            foreach ($forbidden as $panel) {
                $page->where("panels.{$panel}", false);
            }
        });
    }

    /**
     * The frontend gate reads this list, so if it ever disagreed with the
     * backend the sidebar would offer pages that answer 403.
     */
    public function test_the_effective_permission_list_is_shared_with_every_page(): void
    {
        $user = $this->userWithRole(Roles::RELATIONSHIP_MANAGER, [
            'denied_permissions' => ['deals.close'],
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.name', $user->name)
                ->where('auth.user.roles', [Roles::RELATIONSHIP_MANAGER])
                ->has('auth.permissions')
                ->whereContains('auth.permissions', 'deals.move')
                ->where('auth.permissions', fn ($permissions) => ! collect($permissions)->contains('deals.close')),
            );
    }
}

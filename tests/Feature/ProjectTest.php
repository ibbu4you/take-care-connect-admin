<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Interview;
use App\Models\Project;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use App\Support\Workflow\ProjectKind;
use App\Support\Workflow\ProjectStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Projects — the package a client bought.
 *
 * ── What is actually being protected ───────────────────────────────────────
 * Two things carry this module. Delivery is *counted*, never stored, so it
 * cannot go stale; and an interview's client and project have to agree, or the
 * count is of the wrong work.
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    // ── Delivery is counted ────────────────────────────────────────────────

    public function test_a_project_with_no_bookings_reports_no_progress_rather_than_zero(): void
    {
        $project = $this->project();

        $progress = $project->progress();

        $this->assertNull(
            $progress['percent'],
            'A project sold this morning is not 0% delivered — it has not started.',
        );
        $this->assertSame(0, $progress['total']);
    }

    public function test_delivery_counts_handed_over_bookings(): void
    {
        $project = $this->project();

        $this->interviewFor($project, InterviewStage::Scheduled);
        $this->interviewFor($project, InterviewStage::HandedOver);
        $this->interviewFor($project, InterviewStage::Published);

        $progress = $project->fresh()->progress();

        $this->assertSame(2, $progress['done']);
        $this->assertSame(3, $progress['total']);
        $this->assertSame(67, $progress['percent']);
    }

    /**
     * A subject who cancels makes the package smaller, not less complete.
     * Counting them in the denominator would leave a project permanently stuck
     * below 100% through nobody's fault.
     */
    public function test_a_cancelled_booking_is_left_out_of_the_count_entirely(): void
    {
        $project = $this->project();

        $this->interviewFor($project, InterviewStage::HandedOver);
        $this->interviewFor($project, InterviewStage::Cancelled);

        $progress = $project->fresh()->progress();

        $this->assertSame(1, $progress['done']);
        $this->assertSame(1, $progress['total']);
        $this->assertSame(100, $progress['percent']);
    }

    /** A delivered project past its date is finished, not late. */
    public function test_only_an_open_project_can_be_overdue(): void
    {
        $late = $this->project(['due_on' => now()->subWeek()]);
        $delivered = $this->project([
            'due_on' => now()->subWeek(),
            'status' => ProjectStatus::Delivered,
        ]);

        $this->assertTrue($late->isOverdue());
        $this->assertFalse($delivered->isOverdue());
    }

    // ── The client and the project have to agree ───────────────────────────

    /**
     * Both fields are optional and independently valid, so nothing else catches
     * this — and the result is an interview counted towards a package sold to
     * somebody else.
     */
    public function test_an_interview_cannot_be_booked_against_another_clients_project(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $project = $this->project();
        $otherClient = $this->client();

        $this->actingAs($manager)
            ->post('/interviews', [
                ...$this->interviewPayload($anchor),
                'client_id' => $otherClient->id,
                'project_id' => $project->id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertSame(0, $project->interviews()->count());
    }

    public function test_an_interview_can_be_booked_against_its_own_clients_project(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $project = $this->project(['owner_id' => $manager->id]);

        $this->actingAs($manager)
            ->post('/interviews', [
                ...$this->interviewPayload($anchor),
                'client_id' => $project->client_id,
                'project_id' => $project->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $project->interviews()->count());
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_a_manager_sees_the_list(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->project(['owner_id' => $manager->id]);

        $this->actingAs($manager)
            ->get('/projects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Index')
                ->has('projects.data', 1));
    }

    /** Without `.all`, a manager's list is their own book. */
    public function test_the_list_is_scoped_to_what_somebody_owns(): void
    {
        $mine = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $theirs = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->project(['owner_id' => $mine->id]);
        $this->project(['owner_id' => $theirs->id]);

        $this->actingAs($mine)
            ->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('projects.data', 1));
    }

    public function test_an_admin_sees_every_project(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);

        $this->project();
        $this->project();

        $this->actingAs($admin)
            ->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('projects.data', 2));
    }

    /**
     * An anchor reads the package behind their booking — "this is the fourth of
     * six" changes how they run the conversation — but cannot change what was
     * sold.
     */
    public function test_an_anchor_can_open_a_project_they_are_booked_on(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $project = $this->project();

        $this->interviewFor($project, InterviewStage::Scheduled, $anchor);

        $this->actingAs($anchor)->get("/projects/{$project->id}")->assertOk();
    }

    public function test_an_anchor_cannot_open_a_project_they_are_not_on(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $project = $this->project();

        $this->actingAs($anchor)->get("/projects/{$project->id}")->assertForbidden();
    }

    public function test_an_anchor_cannot_create_a_project(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($anchor)->get('/projects/create')->assertForbidden();
        $this->actingAs($anchor)
            ->post('/projects', [
                'client_id' => $this->client()->id,
                'title' => 'Sneaking one in',
                'kind' => ProjectKind::Other->value,
                'status' => ProjectStatus::Open->value,
            ])
            ->assertForbidden();
    }

    /** Creative holds no project permission at all — deliberately. */
    public function test_a_creative_cannot_reach_projects(): void
    {
        $this->actingAs($this->userWithRole(Roles::CREATIVE))
            ->get('/projects')
            ->assertForbidden();
    }

    // ── Creating and editing ───────────────────────────────────────────────

    public function test_a_manager_creates_a_project(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $client = $this->client($manager);

        $this->actingAs($manager)
            ->post('/projects', [
                'client_id' => $client->id,
                'title' => 'Six founder interviews',
                'kind' => ProjectKind::InterviewFeature->value,
                'status' => ProjectStatus::Open->value,
                'value_amount' => 145000,
                'due_on' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect();

        $project = Project::firstWhere('title', 'Six founder interviews');

        $this->assertNotNull($project);
        $this->assertSame($manager->id, $project->owner_id);
        $this->assertStringStartsWith('PRJ-', $project->code);
    }

    /*
     | ── Handing a project to somebody else ─────────────────────────────────
     |
     | A manager sets one up and gives it to the colleague who will actually
     | run it. The rule that makes that safe is that the creator keeps sight of
     | it: scoped on the owner alone, they would watch it vanish the moment
     | they saved, which reads as having lost the work rather than delegated it.
     */

    public function test_a_manager_can_assign_a_project_to_somebody_else(): void
    {
        $creator = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $colleague = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($creator)
            ->post('/projects', [
                'client_id' => $this->client($creator)->id,
                'title' => 'Handed over at birth',
                'kind' => ProjectKind::InterviewFeature->value,
                'status' => ProjectStatus::Open->value,
                'owner_id' => $colleague->id,
            ])
            ->assertRedirect();

        $project = Project::firstWhere('title', 'Handed over at birth');

        $this->assertSame($colleague->id, $project->owner_id);
        $this->assertSame($creator->id, $project->created_by);
    }

    public function test_the_creator_still_sees_a_project_they_gave_away(): void
    {
        $creator = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $colleague = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($creator)->post('/projects', [
            'client_id' => $this->client($creator)->id,
            'title' => 'Still mine to watch',
            'kind' => ProjectKind::InterviewFeature->value,
            'status' => ProjectStatus::Open->value,
            'owner_id' => $colleague->id,
        ]);

        $project = Project::firstWhere('title', 'Still mine to watch');

        $this->actingAs($creator)
            ->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('projects.data', 1));

        $this->actingAs($creator)->get("/projects/{$project->id}")->assertOk();

        // And the person it was given to sees it too, obviously.
        $this->actingAs($colleague)->get("/projects/{$project->id}")->assertOk();
    }

    /**
     * Validated against the people who can work on one, not against `users,id`.
     * Any id would pass that, and the result is a project owned by somebody who
     * cannot open it.
     */
    public function test_a_project_cannot_be_assigned_to_somebody_who_cannot_run_one(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($manager)
            ->post('/projects', [
                'client_id' => $this->client()->id,
                'title' => 'Filed against a stranger',
                'kind' => ProjectKind::InterviewFeature->value,
                'status' => ProjectStatus::Open->value,
                'owner_id' => $anchor->id,
            ])
            ->assertSessionHasErrors('owner_id');

        $this->assertNull(Project::firstWhere('title', 'Filed against a stranger'));
    }

    public function test_an_owner_can_be_changed_later(): void
    {
        $creator = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $colleague = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $project = $this->project(['owner_id' => $creator->id]);

        $this->actingAs($creator)
            ->patch("/projects/{$project->id}", [
                'client_id' => $project->client_id,
                'title' => $project->title,
                'kind' => $project->kind->value,
                'status' => $project->status->value,
                'owner_id' => $colleague->id,
            ])
            ->assertRedirect();

        $this->assertSame($colleague->id, $project->fresh()->owner_id);
    }

    /** A project with no owner is one nobody is answerable for. */
    public function test_leaving_the_owner_blank_falls_back_to_whoever_created_it(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($manager)->post('/projects', [
            'client_id' => $this->client($manager)->id,
            'title' => 'Nobody chosen',
            'kind' => ProjectKind::InterviewFeature->value,
            'status' => ProjectStatus::Open->value,
        ]);

        $this->assertSame($manager->id, Project::firstWhere('title', 'Nobody chosen')->owner_id);
    }

    /**
     * A project can be set up before anybody has agreed a price.
     *
     * ── The bug this exists to stop coming back ────────────────────────────
     * `value_amount` was NOT NULL with a default of 0.00 while the form and the
     * validator both treated Value as optional — and Laravel turns the blank
     * input into an explicit null on the way in, so a default never applied.
     * Leaving the field empty returned a 500 instead of a project.
     *
     * Every test until now passed a value, which is exactly why nothing caught
     * it: the helper that builds a project for the other tests sets one.
     */
    public function test_a_project_can_be_created_with_no_value(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($manager)
            ->post('/projects', [
                'client_id' => $this->client($manager)->id,
                'title' => 'Price still being discussed',
                'kind' => ProjectKind::InterviewFeature->value,
                'status' => ProjectStatus::Open->value,
                'value_amount' => null,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $project = Project::firstWhere('title', 'Price still being discussed');

        $this->assertNotNull($project);
        $this->assertNull($project->value_amount, 'Unknown is not the same as zero.');
    }

    /** The open-value tile must not count an unpriced project as anything. */
    public function test_a_project_with_no_value_does_not_distort_the_open_total(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->project(['owner_id' => $manager->id, 'value_amount' => 50000]);
        $this->project(['owner_id' => $manager->id, 'value_amount' => null]);

        $this->actingAs($manager)
            ->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.open_value', 50000));
    }

    /** A project that ends before it starts makes every duration nonsense. */
    public function test_a_due_date_cannot_precede_the_start_date(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($manager)
            ->post('/projects', [
                'client_id' => $this->client()->id,
                'title' => 'Backwards',
                'kind' => ProjectKind::Other->value,
                'status' => ProjectStatus::Open->value,
                'starts_on' => now()->toDateString(),
                'due_on' => now()->subWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('due_on');
    }

    public function test_the_status_can_be_changed_on_its_own(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $project = $this->project(['owner_id' => $manager->id]);

        $this->actingAs($manager)
            ->patch("/projects/{$project->id}/status", ['status' => ProjectStatus::Delivered->value])
            ->assertRedirect();

        $this->assertSame(ProjectStatus::Delivered, $project->fresh()->status);
    }

    // ── Deleting ───────────────────────────────────────────────────────────

    /**
     * Deleting is for a project created by mistake. One with work behind it is
     * cancelled instead, so the history survives.
     */
    public function test_a_project_with_interviews_cannot_be_deleted(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $project = $this->project();

        $this->interviewFor($project, InterviewStage::Scheduled);

        $this->actingAs($admin)->delete("/projects/{$project->id}")->assertForbidden();
        $this->assertNotSoftDeleted($project);
    }

    public function test_an_empty_project_can_be_deleted(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $project = $this->project();

        $this->actingAs($admin)->delete("/projects/{$project->id}")->assertRedirect();
        $this->assertSoftDeleted($project);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function project(array $attributes = []): Project
    {
        $owner = $attributes['owner_id'] ?? $this->userWithRole(Roles::RELATIONSHIP_MANAGER)->id;

        return Project::create([
            'code' => app(CodeGenerator::class)->next('PRJ'),
            'client_id' => $attributes['client_id'] ?? $this->client(User::find($owner))->id,
            'title' => 'Project '.fake()->unique()->words(2, true),
            'kind' => ProjectKind::InterviewFeature,
            'status' => ProjectStatus::Open,
            'owner_id' => $owner,
            'value_amount' => 100000,
            ...array_diff_key($attributes, ['owner_id' => null, 'client_id' => null]),
            'owner_id' => $owner,
        ]);
    }

    /** A client — belonging to the given manager, since a manager may only file work under their own. */
    private function client(?User $owner = null): Client
    {
        $manager = $owner ?? $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        return Client::create([
            'name' => 'Client '.fake()->unique()->words(2, true),
            'owner_id' => $manager->id,
            'created_by' => $manager->id,
            'source' => 'manual',
            'status' => 'active',
        ]);
    }

    private function interviewFor(Project $project, InterviewStage $stage, ?User $anchor = null): Interview
    {
        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => InterviewKind::BusinessArticle,
            'title' => 'Interview '.fake()->unique()->words(2, true),
            'subject_name' => fake()->name(),
            'client_id' => $project->client_id,
            'project_id' => $project->getKey(),
            'anchor_id' => ($anchor ?? $this->userWithRole(Roles::ANCHOR))->id,
            'scheduled_at' => now()->addDays(2),
            'mode' => 'google_meet',
            'stage' => $stage,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function interviewPayload(User $anchor): array
    {
        return [
            'kind' => InterviewKind::BusinessArticle->value,
            'title' => 'A weaving business',
            'subject_name' => 'Kamala Devi',
            'anchor_id' => $anchor->id,
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'mode' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            'priority' => 'normal',
        ];
    }
}

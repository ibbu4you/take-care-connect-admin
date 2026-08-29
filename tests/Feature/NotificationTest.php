<?php

namespace Tests\Feature;

use App\Models\Interview;
use App\Models\InterviewAsset;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\HandoverService;
use App\Support\Notifier;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The bell.
 *
 * Two rules carry this: nobody is told about their own actions, and nobody is
 * told twice about one event. Both are trivial to state and impossible to
 * remember at twenty call sites, which is why they live in Notifier and are
 * asserted here rather than at each of them.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    // ── The two rules ──────────────────────────────────────────────────────

    public function test_the_actor_is_never_notified_of_their_own_action(): void
    {
        $actor = $this->userWithRole(Roles::ANCHOR);
        $other = $this->userWithRole(Roles::CONTENT);

        $sent = Notifier::send([$actor, $other], 'assigned', 'Something happened', actor: $actor);

        $this->assertSame(1, $sent);
        $this->assertSame(0, $actor->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    /** Somebody who is both writer and reviewer gets one, not two. */
    public function test_the_same_person_is_not_notified_twice_for_one_event(): void
    {
        $person = $this->userWithRole(Roles::CONTENT);

        $sent = Notifier::send([$person, $person, $person], 'review', 'Ready to review');

        $this->assertSame(1, $sent);
        $this->assertSame(1, $person->unreadNotifications()->count());
    }

    /** A deactivated account cannot sign in to read it. */
    public function test_a_deactivated_account_is_not_notified(): void
    {
        $person = $this->userWithRole(Roles::CONTENT);
        $person->update(['is_active' => false]);

        $this->assertSame(0, Notifier::send([$person->fresh()], 'review', 'Ready'));
    }

    public function test_nulls_in_the_recipient_list_are_ignored(): void
    {
        $person = $this->userWithRole(Roles::CONTENT);

        $this->assertSame(1, Notifier::send([null, $person, null], 'review', 'Ready'));
    }

    /**
     * Reaching "whoever can approve this" without naming a role, so granting
     * somebody a permission also starts including them.
     */
    public function test_notifying_by_permission_reaches_everybody_who_holds_it(): void
    {
        $this->seedRoles();

        $writerOne = $this->userWithRole(Roles::CONTENT);
        $writerTwo = $this->userWithRole(Roles::CONTENT);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $sent = Notifier::toPermission('articles.approve', 'review', 'Ready to review');

        $this->assertSame(2, $sent);
        $this->assertSame(1, $writerOne->unreadNotifications()->count());
        $this->assertSame(1, $writerTwo->unreadNotifications()->count());
        $this->assertSame(0, $anchor->unreadNotifications()->count());
    }

    /**
     * The effective permission set includes per-user revocations, which live in
     * a JSON column no join can see — so the fan-out has to respect them.
     */
    public function test_a_revoked_permission_excludes_somebody_from_the_fan_out(): void
    {
        $this->seedRoles();

        $included = $this->userWithRole(Roles::CONTENT);
        $excluded = $this->userWithRole(Roles::CONTENT);

        $excluded->update(['denied_permissions' => ['articles.approve']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Notifier::toPermission('articles.approve', 'review', 'Ready to review');

        $this->assertSame(1, $included->unreadNotifications()->count());
        $this->assertSame(0, $excluded->fresh()->unreadNotifications()->count());
    }

    // ── Wired into the workflow ────────────────────────────────────────────

    public function test_booking_an_interview_tells_the_anchor(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($manager)->post('/interviews', [
            'kind' => InterviewKind::BusinessArticle->value,
            'title' => 'A weaving business',
            'subject_name' => 'Kamala Devi',
            'anchor_id' => $anchor->id,
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'mode' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            'priority' => 'normal',
        ]);

        $this->assertSame(1, $anchor->unreadNotifications()->count());
        $this->assertSame(0, $manager->unreadNotifications()->count());
    }

    public function test_a_handover_tells_the_writer(): void
    {
        $interview = $this->readyInterview();
        $writer = $this->userWithRole(Roles::CONTENT);

        app(HandoverService::class)->handOver($interview, User::find($interview->anchor_id), $writer);

        $this->assertSame(1, $writer->unreadNotifications()->count());

        $data = $writer->unreadNotifications()->first()->data;

        $this->assertSame('handover', $data['kind']);
        $this->assertStringContainsString($interview->code, $data['title']);
    }

    /**
     * An unassigned handover must reach somebody, or it sits in a shared queue
     * nobody is watching.
     */
    public function test_an_unassigned_handover_tells_everybody_who_can_review(): void
    {
        $this->seedRoles();
        $reviewer = $this->userWithRole(Roles::CONTENT);

        $interview = $this->readyInterview();

        app(HandoverService::class)->handOver($interview, User::find($interview->anchor_id));

        $this->assertSame(1, $reviewer->unreadNotifications()->count());
    }

    // ── The bell and the page ──────────────────────────────────────────────

    /** Shared with every page, so the bell needs no request of its own. */
    public function test_the_unread_count_is_shared_with_every_page(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        Notifier::send([$user], 'assigned', 'Something happened');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.unread', 1));
    }

    public function test_opening_one_marks_it_read(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);
        Notifier::send([$user], 'assigned', 'Something happened');

        $id = $user->unreadNotifications()->first()->id;

        $this->actingAs($user)->post("/notifications/{$id}/read")->assertRedirect();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_all_can_be_marked_read_at_once(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        Notifier::send([$user], 'assigned', 'One');
        Notifier::send([$user], 'assigned', 'Two');

        $this->actingAs($user)->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    /** Clearing must not lose something never looked at. */
    public function test_clearing_removes_only_the_read_ones(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        Notifier::send([$user], 'assigned', 'Read this');
        Notifier::send([$user], 'assigned', 'Unread');

        $user->notifications()->first()->markAsRead();

        $this->actingAs($user)->delete('/notifications')->assertRedirect();

        $this->assertSame(1, $user->fresh()->notifications()->count());
        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());
    }

    /** Read state is per-person: one reader must not clear it for the other. */
    public function test_reading_is_per_person(): void
    {
        $one = $this->userWithRole(Roles::CONTENT);
        $two = $this->userWithRole(Roles::CONTENT);

        Notifier::send([$one, $two], 'review', 'Ready to review');

        $this->actingAs($one)->post('/notifications/read-all');

        $this->assertSame(0, $one->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $two->fresh()->unreadNotifications()->count());
    }

    public function test_somebody_cannot_dismiss_another_persons_notification(): void
    {
        $owner = $this->userWithRole(Roles::CONTENT);
        $other = $this->userWithRole(Roles::CONTENT);

        Notifier::send([$owner], 'review', 'Ready');
        $id = $owner->unreadNotifications()->first()->id;

        $this->actingAs($other)->delete("/notifications/{$id}");

        $this->assertSame(1, $owner->fresh()->notifications()->count());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function readyInterview(): Interview
    {
        $interview = Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => InterviewKind::BusinessArticle,
            'title' => 'Interview '.fake()->unique()->word(),
            'subject_name' => fake()->name(),
            'anchor_id' => $this->userWithRole(Roles::ANCHOR)->id,
            'scheduled_by' => $this->userWithRole(Roles::RELATIONSHIP_MANAGER)->id,
            'scheduled_at' => now()->subDay(),
            'mode' => 'google_meet',
            'stage' => InterviewStage::Conducted,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);

        InterviewAsset::create([
            'interview_id' => $interview->id,
            'kind' => 'document',
            'path' => 'interviews/1/write-up.docx',
            'uploaded_by' => $interview->anchor_id,
        ]);

        $interview->update([
            'recording_url' => 'https://drive.google.com/file/d/x/view',
            'qa' => [['question' => 'How did you start?', 'answer' => 'With one loom.']],
        ]);

        return $interview->fresh();
    }
}

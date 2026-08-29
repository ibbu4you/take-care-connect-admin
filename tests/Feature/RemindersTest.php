<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The morning digest.
 *
 * ── What is being protected here ───────────────────────────────────────────
 * One notification a day per person, however much is waiting. The obvious
 * implementation sends one per overdue record, and on a Monday that is fourteen
 * notifications for one person — which is how a bell becomes something people
 * clear without reading.
 *
 * Everything else in this file is the phrasing, which matters more than it
 * looks: "1 follow-ups due" reads as a bug and quietly undermines every other
 * number the system shows.
 */
class RemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_somebody_with_nothing_waiting_is_not_notified(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->artisan('tcc:reminders')->assertSuccessful();

        $this->assertSame(0, $manager->unreadNotifications()->count());
    }

    public function test_one_overdue_thing_produces_one_notification(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);

        $this->artisan('tcc:reminders')->assertSuccessful();

        $this->assertSame(1, $manager->unreadNotifications()->count());
    }

    /** The whole point: however much is waiting, it arrives as one line. */
    public function test_many_overdue_things_still_produce_one_notification(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->followUpDueFor($manager);
        $this->followUpDueFor($manager);
        $this->followUpDueFor($manager);
        $this->idleDealFor($manager);

        $this->artisan('tcc:reminders')->assertSuccessful();

        $this->assertSame(1, $manager->unreadNotifications()->count());

        $data = $manager->unreadNotifications()->first()->data;

        $this->assertSame('4 things need you today', $data['title']);
        $this->assertSame('3 follow-ups due and a deal has gone quiet.', $data['body']);
    }

    /**
     * A single kind of thing is fully described by the title, so repeating it
     * underneath reads as a rendering fault rather than as detail.
     */
    public function test_a_single_kind_carries_no_body(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);

        $this->artisan('tcc:reminders');

        $data = $manager->unreadNotifications()->first()->data;

        $this->assertSame('A follow-up is due', $data['title']);
        $this->assertNull($data['body']);
    }

    /** "1 follow-ups due" is the sort of thing people stop trusting a system over. */
    public function test_counts_of_one_are_written_as_words(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);
        $this->idleDealFor($manager);

        $this->artisan('tcc:reminders');

        $body = $manager->unreadNotifications()->first()->data['body'];

        $this->assertSame('A follow-up is due and a deal has gone quiet.', $body);
        $this->assertStringNotContainsString('1 follow-ups', $body);
    }

    /**
     * The scheduler runs every minute and somebody will run this by hand to see
     * what it does. Neither may produce a second digest.
     */
    public function test_running_it_twice_in_a_day_sends_one_digest(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);

        $this->artisan('tcc:reminders');
        $this->artisan('tcc:reminders');

        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_the_next_day_gets_its_own_digest(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);

        $this->artisan('tcc:reminders');

        $this->travel(1)->days();
        $this->artisan('tcc:reminders');

        $this->assertSame(2, $manager->notifications()->count());
    }

    /** A deactivated account cannot sign in to read it. */
    public function test_a_deactivated_account_is_not_reminded(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);
        $manager->update(['is_active' => false]);

        $this->artisan('tcc:reminders');

        $this->assertSame(0, $manager->fresh()->notifications()->count());
    }

    /** Somebody else's overdue work is not your problem. */
    public function test_a_digest_covers_only_your_own_work(): void
    {
        $mine = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $theirs = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->followUpDueFor($mine);

        $this->artisan('tcc:reminders');

        $this->assertSame(1, $mine->unreadNotifications()->count());
        $this->assertSame(0, $theirs->unreadNotifications()->count());
    }

    /** A dry run reports and sends nothing, so it is safe to try on production. */
    public function test_a_dry_run_sends_nothing(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->followUpDueFor($manager);

        $this->artisan('tcc:reminders', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $manager->notifications()->count());
    }

    /** Content that is not yet due is not a reminder, it is a plan. */
    public function test_work_that_is_not_yet_late_is_left_alone(): void
    {
        $writer = $this->userWithRole(Roles::CONTENT);

        Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'title' => 'Not late yet',
            'stage' => 'drafting',
            'status' => 'draft',
            'writer_id' => $writer->getKey(),
            'due_on' => now()->addWeek(),
        ]);

        $this->artisan('tcc:reminders');

        $this->assertSame(0, $writer->notifications()->count());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function followUpDueFor(User $user): FollowUp
    {
        return FollowUp::create([
            'client_id' => $this->clientFor($user)->getKey(),
            'user_id' => $user->getKey(),
            'due_on' => now()->subDay(),
            'channel' => 'call',
            'note' => 'Chase the quote',
            'state' => 'pending',
        ]);
    }

    private function idleDealFor(User $user): Deal
    {
        return Deal::create([
            'code' => app(CodeGenerator::class)->next('DEA'),
            'client_id' => $this->clientFor($user)->getKey(),
            'title' => 'A quiet deal',
            'stage' => 'proposal',
            'owner_id' => $user->getKey(),
            'expected_value' => 50000,
            'currency' => 'INR',
            'last_activity_at' => now()->subMonths(2),
        ]);
    }

    private function clientFor(User $user): Client
    {
        return Client::create([
            'name' => 'Client '.fake()->unique()->word(),
            'owner_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'source' => 'manual',
            'status' => 'active',
        ]);
    }
}

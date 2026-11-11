<?php

namespace Database\Seeders;

use App\Models\Deliverable;
use App\Models\DeliverableFile;
use App\Models\Interview;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\StageMachine;
use App\Support\Roles;
use App\Support\Workflow\DeliverableKind;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Database\Seeder;

/**
 * Creative work across the whole pipeline, so the board has something on it.
 *
 * Deliberately spread over every column, including the two states worth being
 * able to look at: one piece sent back with notes, and one published with its
 * link recorded.
 */
class DemoDeliverableSeeder extends Seeder
{
    public function run(CodeGenerator $codes, StageMachine $stages): void
    {
        if (app()->environment('production')) {
            $this->command?->error(static::class.' will not run in production — it creates demo data.');

            return;
        }

        $creative = User::role(Roles::CREATIVE)->first();
        $manager = User::role(Roles::RELATIONSHIP_MANAGER)->first();
        $anchor = User::role(Roles::ANCHOR)->first();

        if (! $creative || ! $manager || ! $anchor) {
            $this->command?->warn('Staff accounts missing — run UserSeeder first.');

            return;
        }

        // The celebrity interview: no article, so this work is its whole output.
        $celebrity = Interview::where('kind', 'celebrity_social')->first();
        $business = Interview::where('kind', 'business_article')
            ->where('stage', 'handed_over')
            ->first();

        if (! $celebrity) {
            return;
        }

        $definitions = [
            [$celebrity, DeliverableKind::Reel, 1, DeliverableStage::Published, 'The one-minute cut'],
            [$celebrity, DeliverableKind::Reel, 2, DeliverableStage::Review, 'The behind-the-scenes cut'],
            [$celebrity, DeliverableKind::Poster, 1, DeliverableStage::Rejected, null],
            [$celebrity, DeliverableKind::Thumbnail, 1, DeliverableStage::Pending, null],
        ];

        if ($business) {
            $definitions[] = [$business, DeliverableKind::Poster, 1, DeliverableStage::Approved, 'Quote card'];
            $definitions[] = [$business, DeliverableKind::Reel, 1, DeliverableStage::InProgress, null];
        }

        foreach ($definitions as [$parent, $kind, $slot, $target, $title]) {
            $this->create($parent, $kind, $slot, $target, $title, $creative, $manager, $anchor, $codes, $stages);
        }

        $this->command?->info(sprintf('Seeded %d deliverables across the board.', count($definitions)));
    }

    private function create(
        Interview $parent,
        DeliverableKind $kind,
        int $slot,
        DeliverableStage $target,
        ?string $title,
        User $creative,
        User $manager,
        User $anchor,
        CodeGenerator $codes,
        StageMachine $stages,
    ): void {
        $exists = Deliverable::where('deliverable_of_type', $parent->getMorphClass())
            ->where('deliverable_of_id', $parent->getKey())
            ->where('kind', $kind->value)
            ->where('slot_number', $slot)
            ->exists();

        if ($exists) {
            return;
        }

        $deliverable = Deliverable::create([
            'code' => $codes->next('DLV'),
            'deliverable_of_type' => $parent->getMorphClass(),
            'deliverable_of_id' => $parent->getKey(),
            'kind' => $kind,
            'title' => $title,
            'slot_number' => $slot,
            'stage' => DeliverableStage::Pending,
            'stage_entered_at' => now(),
            'assigned_to' => $creative->id,
            'assigned_by' => $anchor->id,
            'due_on' => now()->addDays(random_int(2, 12)),
            'priority' => 'normal',
            'platforms' => $kind->defaultPlatforms(),
            'brief' => 'Pull the strongest thirty seconds. Keep the lower third clean.',
        ]);

        $stages->recordCreation($deliverable, $anchor, "Requested from {$parent->code}");

        if ($target === DeliverableStage::Pending) {
            return;
        }

        // Everything past pending has had a cut uploaded — that is what moves
        // it off the first column in the first place.
        DeliverableFile::create([
            'deliverable_id' => $deliverable->id,
            'version' => 1,
            'url' => 'https://drive.google.com/file/d/demo-cut/view',
            'duration_seconds' => $kind->isVideo() ? random_int(28, 75) : null,
            'uploaded_by' => $creative->id,
            'note' => 'First cut.',
        ]);

        $deliverable->update([
            'caption' => 'Thirty years at the wheel, and still learning. #handmade #india',
            'hashtags' => '#handmade #india #craft',
        ]);

        // Walked through the real machine, so the history reads exactly as it
        // would have if somebody had done it.
        $path = match ($target) {
            DeliverableStage::InProgress => [DeliverableStage::InProgress],
            DeliverableStage::Review => [DeliverableStage::InProgress, DeliverableStage::Review],
            DeliverableStage::Rejected => [
                DeliverableStage::InProgress, DeliverableStage::Review, DeliverableStage::Rejected,
            ],
            DeliverableStage::Approved => [
                DeliverableStage::InProgress, DeliverableStage::Review, DeliverableStage::Approved,
            ],
            DeliverableStage::Published => [
                DeliverableStage::InProgress, DeliverableStage::Review,
                DeliverableStage::Approved, DeliverableStage::Published,
            ],
            default => [],
        };

        foreach ($path as $stage) {
            $by = in_array($stage, [DeliverableStage::Review, DeliverableStage::InProgress], true)
                ? $creative
                : $manager;

            $stages->transition($deliverable, $stage, $by);
        }

        if ($target === DeliverableStage::Rejected) {
            $deliverable->update([
                'rejection_note' => 'The lower third covers her name. Move it up and resend.',
                'approved_by' => $manager->id,
            ]);
        }

        if ($target === DeliverableStage::Approved) {
            $deliverable->update(['approved_by' => $manager->id, 'approved_at' => now()->subDay()]);
        }

        if ($target === DeliverableStage::Published) {
            /*
             | Backdate created_at along with the rest.
             |
             | Turnaround is measured from creation to publication, so a record
             | created "now" and published two days ago has a negative duration
             | — which the reports correctly refuse to measure and therefore
             | show as no data at all. Seeding a shape the application cannot
             | read makes the demo a bad example of the thing it demonstrates.
             */
            $deliverable->forceFill(['created_at' => now()->subDays(9)])->save();

            $deliverable->update([
                'approved_by' => $manager->id,
                'approved_at' => now()->subDays(3),
                'published_at' => now()->subDays(2),
            ]);

            SocialPost::create([
                'deliverable_id' => $deliverable->id,
                'platform' => 'instagram',
                'url' => 'https://www.instagram.com/p/'.substr(md5((string) $deliverable->id), 0, 11).'/',
                'posted_at' => now()->subDays(2),
                'posted_by' => $creative->id,
            ]);
        }
    }
}

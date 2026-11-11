<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Interview;
use App\Models\InterviewAsset;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\HandoverService;
use App\Services\StageMachine;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Database\Seeder;

/**
 * A worked example of the SOP, so every screen has something honest on it.
 *
 * The interviews below deliberately sit at different points in the workflow,
 * including the two states that matter most for testing: one conducted business
 * interview that is ready to hand over, and one that is missing pieces. Between
 * them they are the acceptance test for the handover rule.
 */
class DemoInterviewSeeder extends Seeder
{
    public function run(CodeGenerator $codes, StageMachine $stages, HandoverService $handovers): void
    {
        if (app()->environment('production')) {
            $this->command?->error(static::class.' will not run in production — it creates demo data.');

            return;
        }

        $anchor = User::role(Roles::ANCHOR)->first();
        $writer = User::role(Roles::CONTENT)->first();
        $creative = User::role(Roles::CREATIVE)->first();
        $manager = User::role(Roles::RELATIONSHIP_MANAGER)->first();

        if (! $anchor || ! $manager) {
            $this->command?->warn('Staff accounts missing — run UserSeeder first.');

            return;
        }

        $clients = collect([
            ['name' => 'Kamala Handlooms', 'city' => 'Kanchipuram', 'trade' => 'Handloom weaving'],
            ['name' => 'Nilgiri Bean Co.', 'city' => 'Coonoor', 'trade' => 'Coffee roasting'],
            ['name' => 'Anand Pottery Works', 'city' => 'Puducherry', 'trade' => 'Terracotta'],
        ])->map(fn (array $attributes) => Client::firstOrCreate(
            ['name' => $attributes['name']],
            [
                ...$attributes,
                'state' => 'Tamil Nadu',
                'source' => 'manual',
                'owner_id' => $manager->id,
                'created_by' => $manager->id,
                'status' => 'active',
            ],
        ));

        $definitions = [
            [
                'kind' => InterviewKind::BusinessArticle,
                'title' => 'How Kamala rebuilt a weaving business from one loom',
                'subject_name' => 'Kamala Devi',
                'subject_designation' => 'Founder',
                'client' => $clients[0],
                'stage' => InterviewStage::Conducted,
                'scheduled_at' => now()->subDays(3)->setTime(11, 0),
                // Everything present: this one is ready to hand over.
                'assets' => ['document', 'recording'],
                'conducted' => true,
            ],
            [
                'kind' => InterviewKind::BusinessArticle,
                'title' => 'Roasting at 6,000 feet: the Nilgiri Bean story',
                'subject_name' => 'Suresh Kumar',
                'subject_designation' => 'Co-founder',
                'client' => $clients[1],
                'stage' => InterviewStage::Conducted,
                'scheduled_at' => now()->subDays(1)->setTime(15, 30),
                // Deliberately incomplete: no document, no recording. This is
                // the row that proves the handover rule refuses.
                'assets' => [],
                'conducted' => true,
            ],
            [
                'kind' => InterviewKind::BusinessArticle,
                'title' => 'Terracotta, and the patience it takes',
                'subject_name' => 'Anand Raj',
                'subject_designation' => 'Master potter',
                'client' => $clients[2],
                'stage' => InterviewStage::HandedOver,
                'scheduled_at' => now()->subDays(9)->setTime(10, 0),
                'assets' => ['document', 'recording'],
                'conducted' => true,
                'content_owner' => $writer,
            ],
            [
                'kind' => InterviewKind::CelebritySocial,
                'title' => 'Five minutes with Divya Menon',
                'subject_name' => 'Divya Menon',
                'subject_designation' => 'Playback singer',
                'client' => null,
                'stage' => InterviewStage::Conducted,
                'scheduled_at' => now()->subDays(2)->setTime(18, 0),
                'assets' => ['recording'],
                'conducted' => true,
                'creative_owner' => $creative,
            ],
            [
                'kind' => InterviewKind::MeetSocial,
                'title' => 'Quickfire: what keeps a small business going',
                'subject_name' => 'Ravi Shankar',
                'subject_designation' => 'Owner, Ravi Tailors',
                'client' => null,
                'stage' => InterviewStage::Scheduled,
                'scheduled_at' => now()->addDays(2)->setTime(14, 0),
                'assets' => [],
                'conducted' => false,
            ],
            [
                'kind' => InterviewKind::BusinessArticle,
                'title' => 'The jasmine growers of Madurai',
                'subject_name' => 'Meenakshi Sundaram',
                'subject_designation' => 'Cooperative lead',
                'client' => null,
                'stage' => InterviewStage::Scheduled,
                'scheduled_at' => now()->addDays(5)->setTime(9, 30),
                'assets' => [],
                'conducted' => false,
            ],
        ];

        foreach ($definitions as $definition) {
            $this->createInterview($definition, $anchor, $manager, $codes, $stages, $handovers);
        }

        $this->command?->info(sprintf('Seeded %d interviews across the workflow.', count($definitions)));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createInterview(
        array $definition,
        User $anchor,
        User $manager,
        CodeGenerator $codes,
        StageMachine $stages,
        HandoverService $handovers,
    ): void {
        if (Interview::where('title', $definition['title'])->exists()) {
            return;
        }

        /** @var InterviewKind $kind */
        $kind = $definition['kind'];

        $interview = Interview::create([
            'code' => $codes->next('INT'),
            'kind' => $kind,
            'title' => $definition['title'],
            'subject_name' => $definition['subject_name'],
            'subject_designation' => $definition['subject_designation'],
            'subject_company' => $definition['client']?->name,
            'client_id' => $definition['client']?->id,
            'anchor_id' => $anchor->id,
            'scheduled_by' => $manager->id,
            'content_owner_id' => $definition['content_owner']?->id ?? null,
            'creative_owner_id' => $definition['creative_owner']?->id ?? null,
            'scheduled_at' => $definition['scheduled_at'],
            'duration_minutes' => 45,
            'mode' => $kind->defaultMode(),
            'meeting_url' => $kind->defaultMode() === 'google_meet' ? 'https://meet.google.com/abc-defg-hij' : null,
            'location' => $kind->defaultMode() === 'in_person' ? 'Studio, Chennai' : null,
            'stage' => InterviewStage::Scheduled,
            'stage_entered_at' => $definition['scheduled_at'],
            'due_on' => $definition['scheduled_at']->copy()->addWeek(),
            'priority' => 'normal',
            'language' => 'English',
            'requires_social' => true,
        ]);

        $stages->recordCreation($interview, $manager, 'Interview booked');

        if ($definition['conducted']) {
            $interview->update([
                'qa_summary' => 'A patient, practical account of building a small craft business without outside money. The best material is in the second half, once she stops giving the polished version.',
                'conducted_at' => $definition['scheduled_at']->copy()->addHour(),
                'consent_to_publish' => true,
            ]);
        }

        foreach ($definition['assets'] as $assetKind) {
            $isRecording = $assetKind === 'recording';

            InterviewAsset::create([
                'interview_id' => $interview->id,
                'kind' => $assetKind,
                'label' => $isRecording ? 'Meet recording' : 'Turboscript transcript',
                'url' => $isRecording ? 'https://drive.google.com/file/d/demo-recording/view' : null,
                'path' => $isRecording ? null : 'interviews/demo/transcript.pdf',
                'original_filename' => $isRecording ? null : 'transcript.pdf',
                'mime_type' => $isRecording ? null : 'application/pdf',
                'size_bytes' => $isRecording ? null : 148_512,
                'uploaded_by' => $anchor->id,
            ]);

            if ($isRecording) {
                $interview->update(['recording_url' => 'https://drive.google.com/file/d/demo-recording/view']);
            }
        }

        /*
         | Walk the record to its intended stage through the real code paths.
         |
         | The handover goes through HandoverService rather than the stage
         | machine directly, so the demo data has the article that a real
         | handover would have produced. Seeding the stage by hand would leave
         | an interview claiming to be with the content team and nothing in the
         | content queue to show for it — which is exactly the inconsistency the
         | service exists to prevent.
         */
        if (in_array($definition['stage'], [InterviewStage::Conducted, InterviewStage::HandedOver], true)) {
            $stages->transition($interview, InterviewStage::Conducted, $anchor, null, $interview->allowedNextStages());
        }

        if ($definition['stage'] === InterviewStage::HandedOver) {
            $handovers->handOver(
                $interview->refresh(),
                $anchor,
                $definition['content_owner'] ?? null,
                'Strongest material is in the second half.',
            );

            $interview->update(['handed_over_at' => now()->subDays(7)]);
        }
    }
}

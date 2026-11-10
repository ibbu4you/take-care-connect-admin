<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Note;
use App\Models\Target;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\StageMachine;
use App\Support\Roles;
use App\Support\Workflow\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * A pipeline with something in every column, plus a target to measure it
 * against.
 *
 * Includes the two states worth being able to look at: a deal that has gone
 * quiet, and one closed as won that the attainment bar is a sum of.
 */
class DemoSalesSeeder extends Seeder
{
    public function run(CodeGenerator $codes, StageMachine $stages): void
    {
        $manager = User::role(Roles::RELATIONSHIP_MANAGER)->first();

        if (! $manager) {
            $this->command?->warn('No relationship manager — run UserSeeder first.');

            return;
        }

        $clients = collect([
            ['name' => 'Madurai Jasmine Collective', 'city' => 'Madurai', 'trade' => 'Flower growing'],
            ['name' => 'Thanjavur Bronze Works', 'city' => 'Thanjavur', 'trade' => 'Metalwork'],
            ['name' => 'Coimbatore Cotton Mills', 'city' => 'Coimbatore', 'trade' => 'Textiles'],
        ])->map(fn (array $attributes) => Client::firstOrCreate(
            ['name' => $attributes['name']],
            [
                ...$attributes,
                'state' => 'Tamil Nadu',
                'contact_person' => fake()->name(),
                'phone' => '+91 '.fake()->numerify('##### #####'),
                'source' => 'referral',
                'owner_id' => $manager->id,
                'created_by' => $manager->id,
                'status' => 'active',
            ],
        ));

        // Existing clients from the interviews seeder belong in the pipeline too.
        $all = Client::where('owner_id', $manager->id)->orWhereNull('owner_id')->get();

        $definitions = [
            ['Three-interview feature package', PipelineStage::Proposal, 145000, 0],
            ['Annual social media retainer', PipelineStage::Negotiation, 320000, 0],
            ['Single craftsman profile', PipelineStage::Meeting, 45000, 0],
            ['Festival campaign films', PipelineStage::Contacted, 210000, 40],
            ['Website story series', PipelineStage::New, 90000, 0],
            ['Launch video and posters', PipelineStage::Won, 175000, 0],
            ['Rebrand shoot', PipelineStage::Lost, 260000, 0],
        ];

        foreach ($definitions as $index => [$title, $stage, $value, $quietDays]) {
            if (Deal::where('title', $title)->exists()) {
                continue;
            }

            $client = $all[$index % max($all->count(), 1)] ?? $clients->first();

            $deal = Deal::create([
                'code' => $codes->next('DEA'),
                'client_id' => $client->id,
                'title' => $title,
                'stage' => PipelineStage::New,
                'stage_entered_at' => now()->subDays(random_int(5, 40)),
                'owner_id' => $manager->id,
                'source' => 'referral',
                'expected_value' => $value,
                'expected_close_on' => now()->addDays(random_int(5, 45)),
                'board_order' => $index,
                // Backdated so the "gone quiet" list has something honest on it.
                'last_activity_at' => now()->subDays($quietDays ?: random_int(0, 5)),
            ]);

            $stages->recordCreation($deal, $manager, 'Deal opened');

            if ($stage !== PipelineStage::New) {
                $stages->transition($deal, $stage, $manager);
            }

            if ($stage === PipelineStage::Won) {
                $deal->forceFill([
                    'outcome' => 'won',
                    'actual_value' => $value * 0.9,
                    'closed_at' => now()->subDays(6),
                ])->save();
            }

            if ($stage === PipelineStage::Lost) {
                $deal->forceFill([
                    'outcome' => 'lost',
                    'lost_reason' => 'Went with a cheaper local studio.',
                    'closed_at' => now()->subDays(11),
                ])->save();
            }

            Note::create([
                'notable_type' => $deal->getMorphClass(),
                'notable_id' => $deal->id,
                'user_id' => $manager->id,
                'body' => 'Spoke to the owner. They want to see a sample reel before committing.',
            ]);
        }

        // A target for this month, so the attainment bar has something to
        // measure the won deal against.
        Target::updateOrCreate(
            ['user_id' => $manager->id, 'period' => Target::currentPeriod()],
            [
                'revenue_target' => 400000,
                'deals_target' => 4,
                'interviews_target' => 8,
                'set_by' => $manager->id,
            ],
        );

        $followUps = [
            [-3, 'call', 'Chase the signed quote.'],
            [0, 'whatsapp', 'Send the sample reel.'],
            [4, 'call', 'Check in after the festival.'],
        ];

        foreach ($followUps as [$offset, $channel, $note]) {
            FollowUp::firstOrCreate(
                ['user_id' => $manager->id, 'note' => $note],
                [
                    'client_id' => $all->first()?->id,
                    'due_on' => now()->addDays($offset),
                    'channel' => $channel,
                    'state' => 'pending',
                ],
            );
        }

        $this->command?->info(sprintf('Seeded %d deals, a target and %d follow-ups.', count($definitions), count($followUps)));
    }
}

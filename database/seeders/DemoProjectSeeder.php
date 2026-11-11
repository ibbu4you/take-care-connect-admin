<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Interview;
use App\Models\Project;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\ProjectKind;
use App\Support\Workflow\ProjectStatus;
use Illuminate\Database\Seeder;

/**
 * Packages of work, in the three states worth looking at.
 *
 * One part-delivered and running to time, one overdue with bookings behind it,
 * and one delivered and closed — so the progress bar, the red date and the
 * finished state are all on screen without anybody having to create them.
 *
 * Existing demo interviews are attached to the first project, because a project
 * with no bookings shows "Not booked yet" and would leave the one thing this
 * page exists to show untested by eye.
 */
class DemoProjectSeeder extends Seeder
{
    public function run(CodeGenerator $codes): void
    {
        if (app()->environment('production')) {
            $this->command?->error(static::class.' will not run in production — it creates demo data.');

            return;
        }

        $manager = User::role(Roles::RELATIONSHIP_MANAGER)->first();

        if (! $manager) {
            $this->command?->warn('No relationship manager — run UserSeeder first.');

            return;
        }

        $clients = Client::orderBy('id')->get();

        if ($clients->isEmpty()) {
            $this->command?->warn('No clients — run DemoSalesSeeder first.');

            return;
        }

        $definitions = [
            [
                'title' => 'Three-interview feature package',
                'kind' => ProjectKind::InterviewFeature,
                'status' => ProjectStatus::Open,
                'value' => 145000,
                'starts' => -30,
                'due' => 25,
                'notes' => 'Three founder interviews, written up and published. Client wants one a month.',
                'attach' => 3,
            ],
            [
                'title' => 'Diwali campaign films',
                'kind' => ProjectKind::SocialCampaign,
                'status' => ProjectStatus::Open,
                'value' => 210000,
                'starts' => -60,
                // Deliberately in the past: the overdue treatment is one of the
                // things somebody should be able to see working.
                'due' => -8,
                'notes' => 'Six reels and a set of posters across the festival fortnight.',
                'attach' => 1,
            ],
            [
                'title' => 'Launch video and posters',
                'kind' => ProjectKind::Sponsorship,
                'status' => ProjectStatus::Closed,
                'value' => 175000,
                'starts' => -120,
                'due' => -45,
                'notes' => 'Delivered and settled.',
                'attach' => 0,
            ],
        ];

        $unassigned = Interview::whereNull('project_id')->orderBy('id')->get();
        $taken = 0;

        foreach ($definitions as $index => $definition) {
            $client = $clients[$index % $clients->count()];

            $project = Project::firstOrCreate(
                ['title' => $definition['title']],
                [
                    'code' => $codes->next('PRJ'),
                    'client_id' => $client->id,
                    'kind' => $definition['kind'],
                    'status' => $definition['status'],
                    'owner_id' => $manager->id,
                    'starts_on' => now()->addDays($definition['starts'])->toDateString(),
                    'due_on' => now()->addDays($definition['due'])->toDateString(),
                    'value_amount' => $definition['value'],
                    'notes' => $definition['notes'],
                ],
            );

            // Tie the deal of the same name to it, so the project page shows
            // where the money came from.
            Deal::where('title', $definition['title'])
                ->whereNull('project_id')
                ->update(['project_id' => $project->id]);

            /*
             | Attach existing bookings, and move them to this project's client.
             |
             | An interview whose client and project disagree is exactly what
             | StoreInterviewRequest refuses, and seeding a state the form would
             | reject makes the demo data a bad example to read.
             */
            for ($i = 0; $i < $definition['attach']; $i++) {
                $interview = $unassigned[$taken] ?? null;

                if ($interview === null) {
                    break;
                }

                $interview->update([
                    'project_id' => $project->id,
                    'client_id' => $project->client_id,
                ]);

                $taken++;
            }
        }

        $this->command?->info('Demo projects created.');
    }
}

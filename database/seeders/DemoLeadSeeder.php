<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Database\Seeder;

/**
 * A prospect list in every state worth looking at.
 *
 * One overdue, one due today, one qualified and ready to convert, one written
 * off with a reason, and one already converted — so the red date, the chase
 * count, the convert button and the "how did we find our clients" trail are all
 * on screen without anybody having to create them.
 *
 * Deliberately more leads than clients. That is the shape of the real work, and
 * a demo where every prospect becomes a client teaches the wrong thing about
 * what this list is for.
 */
class DemoLeadSeeder extends Seeder
{
    public function run(CodeGenerator $codes): void
    {
        $manager = User::role(Roles::RELATIONSHIP_MANAGER)->first();

        if (! $manager) {
            $this->command?->warn('No relationship manager — run UserSeeder first.');

            return;
        }

        $definitions = [
            [
                'name' => 'Chettinad Spice Traders',
                'contact' => 'Lakshmi Narayanan',
                'trade' => 'Spice trading',
                'city' => 'Karaikudi',
                'source' => LeadSource::Referral,
                'status' => LeadStatus::Interested,
                'next' => -4,
                'contacted' => -6,
                'value' => 120000,
                'log' => 'Spoke to Lakshmi. They want to see a sample reel before committing to anything.',
            ],
            [
                'name' => 'Kodaikanal Honey Works',
                'contact' => 'Suresh Babu',
                'trade' => 'Beekeeping',
                'city' => 'Kodaikanal',
                'source' => LeadSource::Field,
                'status' => LeadStatus::Contacted,
                'next' => 0,
                'contacted' => -11,
                'value' => 60000,
                'log' => 'Left a message with the office. Try the mobile next time — mornings are better.',
            ],
            [
                'name' => 'Srivilliputhur Palm Crafts',
                'contact' => 'Meena Rajan',
                'trade' => 'Palm leaf weaving',
                'city' => 'Srivilliputhur',
                'source' => LeadSource::Event,
                'status' => LeadStatus::Qualified,
                'next' => 3,
                'contacted' => -2,
                'value' => 185000,
                'log' => 'Met at the crafts expo. Budget agreed in principle, waiting on their board.',
            ],
            [
                'name' => 'Ooty Tea Estates',
                'contact' => 'Ravi Menon',
                'trade' => 'Tea growing',
                'city' => 'Ooty',
                'source' => LeadSource::WebsiteForm,
                'status' => LeadStatus::New,
                'next' => 1,
                'contacted' => null,
                'value' => 90000,
                'log' => null,
            ],
            [
                'name' => 'Tirunelveli Halwa House',
                'contact' => 'Kamala Iyer',
                'trade' => 'Confectionery',
                'city' => 'Tirunelveli',
                'source' => LeadSource::Social,
                'status' => LeadStatus::Unqualified,
                'next' => null,
                'contacted' => -30,
                'value' => 25000,
                'lost' => 'Too small a budget this year. Worth another call after Pongal.',
                'log' => 'Not this year — they have just taken on a second shop and money is tight.',
            ],
        ];

        foreach ($definitions as $definition) {
            if (Lead::where('name', $definition['name'])->exists()) {
                continue;
            }

            $lead = Lead::create([
                'code' => $codes->next('LED'),
                'name' => $definition['name'],
                'contact_person' => $definition['contact'],
                'phone' => '+91 '.fake()->numerify('##### #####'),
                'email' => null,
                'trade' => $definition['trade'],
                'city' => $definition['city'],
                'state' => 'Tamil Nadu',
                'source' => $definition['source'],
                'status' => $definition['status'],
                'owner_id' => $manager->id,
                'created_by' => $manager->id,
                'next_action_on' => $definition['next'] === null
                    ? null
                    : now()->addDays($definition['next'])->toDateString(),
                'last_contacted_at' => $definition['contacted'] === null
                    ? null
                    : now()->addDays($definition['contacted']),
                'estimated_value' => $definition['value'],
                'lost_reason' => $definition['lost'] ?? null,
            ]);

            if ($definition['log'] !== null) {
                Note::create([
                    'notable_type' => $lead->getMorphClass(),
                    'notable_id' => $lead->id,
                    'user_id' => $manager->id,
                    'body' => $definition['log'],
                ]);
            }
        }

        $this->command?->info('Demo leads created.');
    }
}

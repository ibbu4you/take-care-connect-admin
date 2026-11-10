<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Support\Workflow\LeadStatus;
use App\Support\Workflow\PipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * Turning a lead into a client.
 *
 * ── Why the lead survives ──────────────────────────────────────────────────
 * The obvious implementation moves the row: copy the fields onto a client and
 * delete the lead. That throws away the two things worth keeping — where this
 * client came from, and how long they took to say yes — and it makes the
 * conversion rate unanswerable, because the numerator has been deleted.
 *
 * So conversion creates a client, points the lead at it, and marks the lead
 * converted. Nothing is lost, and "how many of my referrals became clients" is
 * one query.
 *
 * ── Why it is one transaction ──────────────────────────────────────────────
 * Three writes, and every partial outcome is worse than a failure: a client
 * with no lead pointing at it looks like it appeared from nowhere, and a lead
 * marked converted with no client is a dead end nobody can undo from the
 * interface.
 */
class LeadConverter
{
    public function __construct(private readonly CodeGenerator $codes) {}

    /**
     * @param  array{title?: string|null, expected_value?: float|string|null}  $deal
     */
    public function convert(Lead $lead, User $by, array $deal = []): Client
    {
        return DB::transaction(function () use ($lead, $by, $deal): Client {
            $client = Client::create([
                'name' => $lead->name,
                'contact_person' => $lead->contact_person,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'whatsapp' => $lead->whatsapp,
                'website' => $lead->website,
                'instagram' => $lead->instagram,
                'city' => $lead->city,
                'state' => $lead->state,
                'trade' => $lead->trade,

                // The client keeps the lead's source, so "where do our clients
                // come from" is answerable from the client list alone.
                'source' => $lead->source->value,

                // The manager who worked the lead keeps it. Handing a fresh
                // client to nobody is how a new account goes quiet in week one.
                'owner_id' => $lead->owner_id ?? $by->getKey(),
                'created_by' => $by->getKey(),
                'status' => 'active',
                'notes' => $lead->notes,
            ]);

            $lead->forceFill([
                'status' => LeadStatus::Converted,
                'converted_client_id' => $client->getKey(),
                'converted_at' => now(),
            ])->save();

            /*
             | The contact log follows the lead, not the client.
             |
             | Those notes are about winning the work, and they stay attached to
             | the record that describes winning it. The client page links back
             | to the lead, so nothing is out of reach.
             */
            Note::create([
                'notable_type' => $lead->getMorphClass(),
                'notable_id' => $lead->getKey(),
                'user_id' => $by->getKey(),
                'body' => "Converted to client: {$client->name}.",
            ]);

            /*
             | The optional first deal.
             |
             | A client with nothing in the pipeline is a name in a directory,
             | and the moment somebody agrees to become a client is exactly when
             | the value is known — so it is offered here rather than left as a
             | second job that gets forgotten.
             */
            if (filled($deal['title'] ?? null)) {
                Deal::create([
                    'code' => $this->codes->next('DEA'),
                    'client_id' => $client->getKey(),
                    'title' => $deal['title'],
                    // They have just agreed to become a client, so they have certainly
                    // been spoken to.
                    'stage' => PipelineStage::Contacted,
                    'stage_entered_at' => now(),
                    'owner_id' => $client->owner_id,
                    'source' => $lead->source->value,
                    'expected_value' => $deal['expected_value'] ?? $lead->estimated_value ?? 0,
                    'last_activity_at' => now(),
                ]);
            }

            return $client;
        });
    }
}

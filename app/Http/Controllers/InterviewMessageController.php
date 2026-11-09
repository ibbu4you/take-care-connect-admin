<?php

namespace App\Http\Controllers;

use App\Models\Interview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Recording that a message went to the business.
 *
 * The message itself is sent from the team member's own WhatsApp — the page
 * opens a wa.me link with the text written — so the app cannot see it go. What
 * it can do is remember that somebody pressed Send, and who, so the next person
 * does not send it again and nobody wonders whether it was sent at all.
 */
class InterviewMessageController extends Controller
{
    /** The three messages a business gets, in the order it gets them. */
    public const MESSAGES = ['confirmation', 'reminder', 'story'];

    public function store(Request $request, Interview $interview, string $message): RedirectResponse
    {
        abort_unless(in_array($message, self::MESSAGES, true), 404);

        // Whoever books or runs the interview talks to the business about it.
        abort_unless(
            $request->user()->can('schedule', $interview) || $request->user()->can('update', $interview),
            403,
        );

        $interview->forceFill([
            "{$message}_sent_at" => now(),
            "{$message}_sent_by" => $request->user()->id,
        ])->save();

        return back()->with('success', match ($message) {
            'confirmation' => 'Confirmation marked as sent.',
            'reminder' => 'Reminder marked as sent.',
            default => 'Story link marked as sent.',
        });
    }
}

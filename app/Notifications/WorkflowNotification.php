<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification class for the whole application.
 *
 * ── Why not a class per event ──────────────────────────────────────────────
 * A class per event (InterviewHandedOver, ArticleApproved, DeliverableRejected,
 * …) would be twenty near-identical files whose only difference is a sentence
 * and a URL. They are all the same shape — somebody did something to a record
 * you care about, here is the link — so the shape is the class and the sentence
 * is data.
 *
 * Database channel only. Email would need a mail service the shared hosting
 * does not reliably have, and an admin tool that emails on every stage change
 * teaches people to filter it.
 */
class WorkflowNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  what happened, used to pick an icon
     * @param  string  $title  the sentence somebody reads
     * @param  string|null  $body  optional detail — a reviewer's note, a reason
     * @param  string|null  $url  where to go to act on it
     * @param  string|null  $actor  who did it
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $title,
        private readonly ?string $body = null,
        private readonly ?string $url = null,
        private readonly ?string $actor = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'actor' => $this->actor,
        ];
    }
}

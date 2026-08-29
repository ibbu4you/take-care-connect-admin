<?php

/*
|--------------------------------------------------------------------------
| Configurable workflow values
|--------------------------------------------------------------------------
|
| The defaults, the labels, and the bounds for everything on the Settings
| page. One definition drives three things that would otherwise drift apart:
| what a missing row reads as, what the form renders, and what the validator
| accepts.
|
| ── Why min and max are here and not just in the request ──────────────────
| Every one of these drives something that runs on a schedule or fires a
| notification. A handover grace of 0 days would nag an anchor about an
| interview they finished ten minutes ago; a deal idle threshold of 3650 days
| turns the "gone quiet" list off without anybody realising they turned it off.
| The bounds are what keeps a settings page from being a way to quietly break
| the system.
|
*/

return [

    'defaults' => [
        'deals.idle_days' => 14,
        'interviews.handover_grace_days' => 3,
        'articles.turnaround_days' => 7,
        'deliverables.turnaround_days' => 5,
    ],

    /*
     | What the Settings page renders, in the order it renders them.
     |
     | `help` is the sentence under the field. It says what the number *does*,
     | not what it is called — "Deals with no activity for this long" tells
     | somebody what changing it will change; "Idle days" does not.
     */
    'fields' => [
        [
            'key' => 'deals.idle_days',
            'label' => 'A deal has gone quiet after',
            'unit' => 'days',
            'help' => 'Deals with no logged activity for this long appear on the dashboard and in the morning digest.',
            'min' => 3,
            'max' => 120,
            'group' => 'Sales',
        ],
        [
            'key' => 'interviews.handover_grace_days',
            'label' => 'Chase a handover after',
            'unit' => 'days',
            'help' => 'How long an anchor has after conducting an interview before the digest reminds them to hand it over.',
            'min' => 1,
            'max' => 30,
            'group' => 'Interviews',
        ],
        [
            'key' => 'articles.turnaround_days',
            'label' => 'Articles are due',
            'unit' => 'days after handover',
            'help' => 'The deadline put on a new article when the interview it came from carries no date of its own.',
            'min' => 1,
            'max' => 90,
            'group' => 'Content',
        ],
        [
            'key' => 'deliverables.turnaround_days',
            'label' => 'Creative work is due',
            'unit' => 'days after it is commissioned',
            'help' => 'The deadline suggested on the form when a poster or reel is commissioned.',
            'min' => 1,
            'max' => 90,
            'group' => 'Creative',
        ],
    ],

];

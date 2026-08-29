/*
 | Every workflow stage, and how it looks.
 |
 | ── Why these are literal class strings ─────────────────────────────────────
 | Tailwind builds its stylesheet by scanning source files for class names as
 | plain text. A class assembled at runtime — `bg-stage-${stage}` — is invisible
 | to that scan, so the rule is never generated and the chip renders unstyled.
 | Every class below is therefore written out in full. It is repetitive on
 | purpose; the alternative silently breaks.
 |
 | ── Why the families are separate ──────────────────────────────────────────
 | `published` means something different on an article than on a reel, and
 | `scheduled` on an interview is not `scheduled` on a social post. Keeping one
 | map per workflow means a caller has to say which workflow it is asking about,
 | so a deliverable can never be labelled with an article's vocabulary.
 |
 | The colours themselves are defined in resources/css/app.css: the pipeline is
 | one hue ramp cooling toward the brand navy, while the interview/article and
 | creative workflows use a separate violet-to-teal family so a chip is never
 | ambiguous about which process it belongs to.
 */

export interface StageMeta {
    label: string;
    /** Chip: tinted background, solid text, hairline border. */
    className: string;
    /** Just the swatch, for legends, kanban column headers and bar lists. */
    dot: string;
}

export const PIPELINE_STAGES = {
    intake: {
        label: 'Intake',
        className: 'bg-stage-intake/15 text-stage-intake border-stage-intake/30',
        dot: 'bg-stage-intake',
    },
    cold: {
        label: 'Cold',
        className: 'bg-stage-cold/15 text-stage-cold border-stage-cold/30',
        dot: 'bg-stage-cold',
    },
    warm: {
        label: 'Warm',
        className: 'bg-stage-warm/15 text-stage-warm border-stage-warm/30',
        dot: 'bg-stage-warm',
    },
    qualified: {
        label: 'Qualified',
        className: 'bg-stage-qualified/15 text-stage-qualified border-stage-qualified/30',
        dot: 'bg-stage-qualified',
    },
    proposal: {
        label: 'Proposal',
        className: 'bg-stage-proposal/15 text-stage-proposal border-stage-proposal/30',
        dot: 'bg-stage-proposal',
    },
    won: {
        label: 'Won',
        className: 'bg-stage-won/15 text-stage-won border-stage-won/30',
        dot: 'bg-stage-won',
    },
    lost: {
        label: 'Lost',
        className: 'bg-stage-lost/15 text-stage-lost border-stage-lost/30',
        dot: 'bg-stage-lost',
    },
} as const satisfies Record<string, StageMeta>;

/** The board columns, in order. Won and lost are outcomes, not columns. */
export const PIPELINE_COLUMNS = ['intake', 'cold', 'warm', 'qualified', 'proposal'] as const;

export const INTERVIEW_STAGES = {
    scheduled: {
        label: 'Scheduled',
        className: 'bg-stage-scheduled/15 text-stage-scheduled border-stage-scheduled/30',
        dot: 'bg-stage-scheduled',
    },
    rescheduled: {
        label: 'Rescheduled',
        className: 'bg-stage-rescheduled/15 text-stage-rescheduled border-stage-rescheduled/30',
        dot: 'bg-stage-rescheduled',
    },
    conducted: {
        label: 'Conducted',
        className: 'bg-stage-conducted/15 text-stage-conducted border-stage-conducted/30',
        dot: 'bg-stage-conducted',
    },
    handed_over: {
        label: 'Handed over',
        className: 'bg-stage-handed-over/15 text-stage-handed-over border-stage-handed-over/30',
        dot: 'bg-stage-handed-over',
    },
    in_content: {
        label: 'With content',
        className: 'bg-stage-in-content/15 text-stage-in-content border-stage-in-content/30',
        dot: 'bg-stage-in-content',
    },
    published: {
        label: 'Published',
        className: 'bg-stage-published/15 text-stage-published border-stage-published/30',
        dot: 'bg-stage-published',
    },
    cancelled: {
        label: 'Cancelled',
        className: 'bg-stage-cancelled/15 text-stage-cancelled border-stage-cancelled/30',
        dot: 'bg-stage-cancelled',
    },
    no_show: {
        label: 'No show',
        className: 'bg-stage-no-show/15 text-stage-no-show border-stage-no-show/30',
        dot: 'bg-stage-no-show',
    },
} as const satisfies Record<string, StageMeta>;

export const ARTICLE_STAGES = {
    assigned: {
        label: 'Assigned',
        className: 'bg-stage-assigned/15 text-stage-assigned border-stage-assigned/30',
        dot: 'bg-stage-assigned',
    },
    drafting: {
        label: 'Drafting',
        className: 'bg-stage-drafting/15 text-stage-drafting border-stage-drafting/30',
        dot: 'bg-stage-drafting',
    },
    internal_review: {
        label: 'In review',
        className: 'bg-stage-internal-review/15 text-stage-internal-review border-stage-internal-review/30',
        dot: 'bg-stage-internal-review',
    },
    revisions: {
        label: 'Revisions',
        className: 'bg-stage-revisions/15 text-stage-revisions border-stage-revisions/30',
        dot: 'bg-stage-revisions',
    },
    approved: {
        label: 'Approved',
        className: 'bg-stage-approved/15 text-stage-approved border-stage-approved/30',
        dot: 'bg-stage-approved',
    },
    published: {
        label: 'Published',
        className: 'bg-stage-published/15 text-stage-published border-stage-published/30',
        dot: 'bg-stage-published',
    },
} as const satisfies Record<string, StageMeta>;

export const DELIVERABLE_STAGES = {
    pending: {
        label: 'Pending',
        className: 'bg-stage-pending/15 text-stage-pending border-stage-pending/30',
        dot: 'bg-stage-pending',
    },
    in_progress: {
        label: 'In progress',
        className: 'bg-stage-in-progress/15 text-stage-in-progress border-stage-in-progress/30',
        dot: 'bg-stage-in-progress',
    },
    review: {
        label: 'In review',
        className: 'bg-stage-review/15 text-stage-review border-stage-review/30',
        dot: 'bg-stage-review',
    },
    approved: {
        label: 'Approved',
        className: 'bg-stage-approved/15 text-stage-approved border-stage-approved/30',
        dot: 'bg-stage-approved',
    },
    scheduled: {
        label: 'Scheduled',
        className: 'bg-stage-scheduled/15 text-stage-scheduled border-stage-scheduled/30',
        dot: 'bg-stage-scheduled',
    },
    published: {
        label: 'Published',
        className: 'bg-stage-published/15 text-stage-published border-stage-published/30',
        dot: 'bg-stage-published',
    },
    rejected: {
        label: 'Rejected',
        className: 'bg-stage-rejected/15 text-stage-rejected border-stage-rejected/30',
        dot: 'bg-stage-rejected',
    },
} as const satisfies Record<string, StageMeta>;

/*
 | Where a project stands.
 |
 | Its own family rather than borrowed pipeline keys, for the same reason every
 | other workflow has one: a chip should never be ambiguous about which thing it
 | describes. "Closed" on a project and "Won" on a deal are different claims,
 | and a reader should not have to work out which board they are looking at.
 |
 | The colours are reused deliberately — a project is what a won deal becomes,
 | so ending green next to a green deal is the right rhyme.
 */
export const PROJECT_STATUSES = {
    open: {
        label: 'Open',
        className: 'bg-stage-qualified/15 text-stage-qualified border-stage-qualified/30',
        dot: 'bg-stage-qualified',
    },
    delivered: {
        label: 'Delivered',
        className: 'bg-stage-handed-over/15 text-stage-handed-over border-stage-handed-over/30',
        dot: 'bg-stage-handed-over',
    },
    closed: {
        label: 'Closed',
        className: 'bg-stage-won/15 text-stage-won border-stage-won/30',
        dot: 'bg-stage-won',
    },
    cancelled: {
        label: 'Cancelled',
        className: 'bg-stage-cancelled/15 text-stage-cancelled border-stage-cancelled/30',
        dot: 'bg-stage-cancelled',
    },
} as const satisfies Record<string, StageMeta>;

/*
 | How far a conversation with a prospect has got.
 |
 | Its own family, like every other workflow, so a chip is never ambiguous about
 | what it describes — "Qualified" means something different to a lead and to a
 | deal, and a reader should not have to work out which list they are on.
 |
 | The colours deliberately echo the pipeline: a lead is what a deal grows out
 | of, so the two read as one journey when they sit near each other.
 */
export const LEAD_STATUSES = {
    new: {
        label: 'New',
        className: 'bg-stage-intake/15 text-stage-intake border-stage-intake/30',
        dot: 'bg-stage-intake',
    },
    contacted: {
        label: 'Contacted',
        className: 'bg-stage-cold/15 text-stage-cold border-stage-cold/30',
        dot: 'bg-stage-cold',
    },
    interested: {
        label: 'Interested',
        className: 'bg-stage-warm/15 text-stage-warm border-stage-warm/30',
        dot: 'bg-stage-warm',
    },
    qualified: {
        label: 'Qualified',
        className: 'bg-stage-qualified/15 text-stage-qualified border-stage-qualified/30',
        dot: 'bg-stage-qualified',
    },
    unqualified: {
        label: 'Not a fit',
        className: 'bg-stage-lost/15 text-stage-lost border-stage-lost/30',
        dot: 'bg-stage-lost',
    },
    converted: {
        label: 'Converted',
        className: 'bg-stage-won/15 text-stage-won border-stage-won/30',
        dot: 'bg-stage-won',
    },
} as const satisfies Record<string, StageMeta>;

export type StageFamily = 'pipeline' | 'interview' | 'article' | 'deliverable' | 'project' | 'lead';

const FAMILIES: Record<StageFamily, Record<string, StageMeta>> = {
    pipeline: PIPELINE_STAGES,
    interview: INTERVIEW_STAGES,
    article: ARTICLE_STAGES,
    deliverable: DELIVERABLE_STAGES,
    project: PROJECT_STATUSES,
    lead: LEAD_STATUSES,
};

/** An unknown stage still renders, in neutral grey, rather than crashing a table. */
const UNKNOWN: StageMeta = {
    label: 'Unknown',
    className: 'bg-muted text-muted-foreground border-border',
    dot: 'bg-muted-foreground',
};

export function stageMeta(family: StageFamily, stage: string | null | undefined): StageMeta {
    if (!stage) {
        return UNKNOWN;
    }

    return (
        FAMILIES[family][stage] ?? {
            ...UNKNOWN,
            // Better to show "handed_over" than "Unknown" if a stage is ever
            // added server-side before this file catches up.
            label: stage.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()),
        }
    );
}

/** The three kinds of interview an Anchor runs. */
export const INTERVIEW_KINDS = {
    celebrity_social: {
        label: 'Celebrity · social',
        description: 'Interviewed in person. Output is social only — no website article.',
    },
    business_article: {
        label: 'Business · article',
        description: 'Over Google Meet. Handed to the content team and written up for the website.',
    },
    meet_social: {
        label: 'Meet · social',
        description: 'Over Google Meet. Output is social only — no website article.',
    },
} as const;

export type InterviewKind = keyof typeof INTERVIEW_KINDS;

/** Only the business interview produces a website article. */
export function requiresArticle(kind: string): boolean {
    return kind === 'business_article';
}

import { CalendarClock, Globe, Send, type LucideIcon } from 'lucide-react';
import type { Tone } from '@/Components/dashboard/parts';
import { due } from '@/lib/format';

const dayMonth = new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' });

/**
 * What the date on a deliverable should say, given where the work is.
 *
 * Shared by the board and the list so the two views never disagree.
 *
 * "70d overdue" on something already posted is noise, and on approved work it
 * blames the creative for a wait that is now somebody else's — so only work
 * still being made is held to its due date.
 */
export function deliverableTiming(item: {
    stage: string;
    due_on: string | null;
    published_at: string | null;
}): { label: string; tone: Tone; icon: LucideIcon } {
    if (item.stage === 'published') {
        return {
            label: item.published_at ? `Posted ${dayMonth.format(new Date(item.published_at))}` : 'Posted',
            tone: 'success',
            icon: Globe,
        };
    }

    if (item.stage === 'approved' || item.stage === 'scheduled') {
        return { label: item.stage === 'scheduled' ? 'Queued to post' : 'Ready to post', tone: 'info', icon: Send };
    }

    const deadline = due(item.due_on);

    return {
        label: deadline.label,
        tone: deadline.tone === 'muted' ? 'neutral' : deadline.tone,
        icon: CalendarClock,
    };
}

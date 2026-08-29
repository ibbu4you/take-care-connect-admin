/*
 | Formatting helpers.
 |
 | Centralised so a figure looks the same on a dashboard tile, in a table cell
 | and inside a chart tooltip. Everything is India-facing: INR, and dates the
 | way people here write them.
 */

const CURRENCY = 'INR';
const LOCALE = 'en-IN';

/** ₹1,20,000 — full precision, for a single prominent figure. */
export function money(value: number | string | null | undefined): string {
    const amount = Number(value ?? 0);

    return new Intl.NumberFormat(LOCALE, {
        style: 'currency',
        currency: CURRENCY,
        maximumFractionDigits: 0,
    }).format(Number.isFinite(amount) ? amount : 0);
}

/**
 * ₹1.2L — for KPI tiles and axis labels, where the exact rupee is noise.
 *
 * Lakh/crore rather than Intl's `notation: 'compact'`, which renders "12L" as
 * "1.2M" and reads as somebody else's money.
 */
export function moneyCompact(value: number | string | null | undefined): string {
    const amount = Number(value ?? 0);

    if (!Number.isFinite(amount)) {
        return '₹0';
    }

    const abs = Math.abs(amount);
    const sign = amount < 0 ? '-' : '';

    if (abs >= 10_000_000) return `${sign}₹${trim(abs / 10_000_000)}Cr`;
    if (abs >= 100_000) return `${sign}₹${trim(abs / 100_000)}L`;
    if (abs >= 1_000) return `${sign}₹${trim(abs / 1_000)}K`;

    return `${sign}₹${Math.round(abs)}`;
}

/** One decimal place, but only when it says something: 1.2L, not 2.0L. */
function trim(value: number): string {
    return value % 1 === 0 ? String(value) : value.toFixed(1);
}

export function number(value: number | null | undefined): string {
    return new Intl.NumberFormat(LOCALE).format(Number(value ?? 0));
}

export function percent(value: number | null | undefined, digits = 0): string {
    return `${(Number(value ?? 0) * 100).toFixed(digits)}%`;
}

/** 27 Aug 2026 */
export function date(value: string | Date | null | undefined): string {
    const parsed = toDate(value);

    return parsed
        ? new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' }).format(parsed)
        : '—';
}

/** 27 Aug 2026, 3:30 pm */
export function dateTime(value: string | Date | null | undefined): string {
    const parsed = toDate(value);

    return parsed
        ? new Intl.DateTimeFormat(LOCALE, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          }).format(parsed)
        : '—';
}

/** 3:30 pm */
export function time(value: string | Date | null | undefined): string {
    const parsed = toDate(value);

    return parsed
        ? new Intl.DateTimeFormat(LOCALE, { hour: 'numeric', minute: '2-digit' }).format(parsed)
        : '—';
}

/**
 * "3 days ago", "in 2 weeks".
 *
 * Uses Intl.RelativeTimeFormat so the wording is not hand-rolled, and steps up
 * through the units so a fortnight reads as "2 weeks" rather than "14 days".
 */
export function relative(value: string | Date | null | undefined): string {
    const parsed = toDate(value);

    if (!parsed) {
        return '—';
    }

    const seconds = (parsed.getTime() - Date.now()) / 1000;
    const formatter = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' });

    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['year', 60 * 60 * 24 * 365],
        ['month', 60 * 60 * 24 * 30],
        ['week', 60 * 60 * 24 * 7],
        ['day', 60 * 60 * 24],
        ['hour', 60 * 60],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(Math.round(seconds), 'second');
}

/**
 * How a due date should read and how alarmed it should look.
 *
 * Returns the tone alongside the label so a caller cannot show "3 days overdue"
 * in a calm grey by forgetting to branch.
 */
export function due(value: string | Date | null | undefined): {
    label: string;
    tone: 'muted' | 'warning' | 'danger';
} {
    const parsed = toDate(value);

    if (!parsed) {
        return { label: 'No due date', tone: 'muted' };
    }

    const days = Math.ceil((startOfDay(parsed).getTime() - startOfDay(new Date()).getTime()) / 86_400_000);

    if (days < 0) return { label: `${Math.abs(days)}d overdue`, tone: 'danger' };
    if (days === 0) return { label: 'Due today', tone: 'warning' };
    if (days === 1) return { label: 'Due tomorrow', tone: 'warning' };
    if (days <= 3) return { label: `Due in ${days}d`, tone: 'warning' };

    return { label: `Due ${date(parsed)}`, tone: 'muted' };
}

export function bytes(value: number | null | undefined): string {
    const size = Number(value ?? 0);

    if (!Number.isFinite(size) || size <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);

    return `${trim(size / 1024 ** index)} ${units[index]}`;
}

/** mm:ss for short clips, h:mm:ss once it runs past an hour. */
export function duration(seconds: number | null | undefined): string {
    const total = Math.max(0, Math.round(Number(seconds ?? 0)));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/** A calendar date, with no time and therefore no timezone: "2026-08-25". */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

function toDate(value: string | Date | null | undefined): Date | null {
    if (!value) return null;

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    /*
     | A due date is a date, not a moment.
     |
     | `new Date('2026-08-25')` is specified to parse as UTC midnight, so west
     | of Greenwich it renders as the 24th — the classic off-by-one that only
     | shows up for some viewers. Splitting the parts builds it in local time,
     | where a date means the same day to everybody looking at it.
     |
     | Timestamps still go through Date, which is right: those genuinely are
     | moments, and the server sends them with an offset.
     */
    if (DATE_ONLY.test(value)) {
        const [year, month, day] = value.split('-').map(Number);

        return new Date(year, month - 1, day);
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function startOfDay(value: Date): Date {
    return new Date(value.getFullYear(), value.getMonth(), value.getDate());
}

/**
 * "a and b", "a, b and c" — a list a person would read aloud.
 *
 * A bare join leaves "missing the document, the recording." which reads as a
 * sentence somebody forgot to finish.
 */
export function sentenceList(items: string[]): string {
    if (items.length === 0) return '';
    if (items.length === 1) return items[0];

    return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

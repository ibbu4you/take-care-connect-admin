import { cn } from '@/lib/utils';

/*
 | The TCIF seal.
 |
 | ── Why this is a component and not two <img> tags ─────────────────────────
 | It appears in the sidebar and on the sign-in page, and those are the two
 | places a brand asset drifts: one gets resized, the other keeps a stale path,
 | and nobody notices because they are never on screen at the same time.
 |
 | ── Why there is no background or border-radius ────────────────────────────
 | The artwork is a circle on transparent corners, so it needs no plate behind
 | it and sits correctly on the white sidebar, on the sign-in canvas, and on
 | the dark theme — where its white outer ring is what separates it from the
 | background.
 |
 | The name is always rendered as text beside or beneath it, so the image is
 | decorative: empty alt, so a screen reader announces the name once rather
 | than twice.
 */
export function Logo({ className, size = 36 }: { className?: string; size?: number }) {
    return (
        <img
            src="/img/logo.webp"
            alt=""
            width={size}
            height={size}
            // Reserved rather than intrinsic: the file is 394x384, very
            // slightly wider than tall, and letting it settle after load
            // nudges the whole sidebar header on first paint.
            style={{ width: size, height: size }}
            className={cn('shrink-0 select-none object-contain', className)}
            draggable={false}
        />
    );
}

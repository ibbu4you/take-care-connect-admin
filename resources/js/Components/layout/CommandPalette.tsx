import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { NAV, type NavItem } from '@/config/nav';
import { usePermissions } from '@/hooks/usePermissions';
import { cn } from '@/lib/utils';

interface CommandPaletteProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Ctrl/Cmd-K navigation.
 *
 * Searches the same manifest the sidebar renders, filtered by the same
 * permissions — so the palette can never jump somebody to a page their menu
 * deliberately hides.
 */
export function CommandPalette({ open, onClose }: CommandPaletteProps) {
    const { can } = usePermissions();
    const [query, setQuery] = useState('');
    const [highlighted, setHighlighted] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    const results = useMemo(() => {
        const available = NAV.filter((item) => !item.soon)
            .filter((item) => !item.permissions || can(item.permissions));

        const term = query.trim().toLowerCase();

        if (!term) {
            return available.slice(0, 8);
        }

        return available
            .filter(
                (item) =>
                    item.label.toLowerCase().includes(term) ||
                    item.section.toLowerCase().includes(term),
            )
            .slice(0, 10);
    }, [can, query]);

    // Reset between openings, so reopening never shows the last search with a
    // stale highlight pointing at a row that is no longer there.
    useEffect(() => {
        if (open) {
            setQuery('');
            setHighlighted(0);
            // After paint, or the field is not in the document yet to focus.
            requestAnimationFrame(() => inputRef.current?.focus());
        }
    }, [open]);

    /*
     | Grouped for rendering, flat for the keyboard.
     |
     | Arrow keys walk one list, so the highlight index has to stay an index
     | into `results`. Each grouped row carries its position in that flat list
     | rather than being re-numbered per section — which is how a palette ends
     | up highlighting the wrong row after the second heading.
     */
    const groups = useMemo(() => {
        const bySection = new Map<string, Array<{ item: NavItem; index: number }>>();

        results.forEach((item, index) => {
            const list = bySection.get(item.section) ?? [];

            list.push({ item, index });
            bySection.set(item.section, list);
        });

        return [...bySection.entries()].map(([section, items]) => ({ section, items }));
    }, [results]);

    // Clamp rather than reset when the result set shrinks under the cursor.
    useEffect(() => {
        setHighlighted((index) => Math.min(index, Math.max(results.length - 1, 0)));
    }, [results.length]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();

                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setHighlighted((index) => (results.length ? (index + 1) % results.length : 0));

                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                setHighlighted((index) =>
                    results.length ? (index - 1 + results.length) % results.length : 0,
                );

                return;
            }

            if (event.key === 'Enter') {
                const target = results[highlighted];

                if (target) {
                    event.preventDefault();
                    onClose();
                    router.visit(target.href);
                }
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [highlighted, onClose, open, results]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    /*
     | Portalled to <body>.
     |
     | The layout uses `sticky` and `backdrop-blur`, both of which create
     | containing blocks — a fixed overlay rendered inside them positions
     | against the wrong ancestor and lands halfway down the page.
     */
    return createPortal(
        <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[12vh]">
            <div
                className="absolute inset-0 bg-overlay/60 backdrop-blur-sm"
                onClick={onClose}
                aria-hidden="true"
            />

            <div
                role="dialog"
                aria-modal="true"
                aria-label="Search"
                className="relative w-full max-w-lg overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-card-hover"
            >
                <div className="flex items-center gap-3 border-b border-border px-4">
                    <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Jump to a page…"
                        /*
                         | No focus ring on this one.
                         |
                         | The global :focus-visible rule is right everywhere
                         | else, but here it draws a heavy rounded box around a
                         | borderless field that fills the top of a modal — and
                         | it is telling a keyboard user something they already
                         | know, since this is auto-focused, has a caret, and is
                         | the only place typing could go.
                        */
                        className="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-0 focus-visible:ring-offset-0"
                    />
                </div>

                {results.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                        Nothing matches “{query}”.
                    </p>
                ) : (
                    <ul className="scrollbar-slim max-h-[22rem] overflow-y-auto p-2">
                        {groups.map((group) => (
                            <li key={group.section}>
                                {/*
                                  | The section is a heading, not a column.
                                  |
                                  | It used to sit right-aligned on every row,
                                  | which put the word "Sales" down the edge five
                                  | times for five consecutive rows — noise that
                                  | said nothing the grouping does not say once.
                                */}
                                <p className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    {group.section}
                                </p>

                                <ul>
                                    {group.items.map(({ item, index }) => (
                                        <PaletteRow
                                            key={item.href}
                                            item={item}
                                            active={index === highlighted}
                                            onHover={() => setHighlighted(index)}
                                            onSelect={() => {
                                                onClose();
                                                router.visit(item.href);
                                            }}
                                        />
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}

                {/*
                  | The keys, where a command palette always puts them.
                  |
                  | Esc used to sit in a little box beside the search field,
                  | which reads as part of the input rather than as a hint about
                  | the dialog — and left the other two shortcuts undiscoverable.
                */}
                <div className="flex items-center gap-4 border-t border-border bg-muted/40 px-4 py-2 text-[11px] text-muted-foreground">
                    <span className="flex items-center gap-1.5">
                        <Key>↑</Key>
                        <Key>↓</Key>
                        to move
                    </span>
                    <span className="flex items-center gap-1.5">
                        <Key>↵</Key>
                        to open
                    </span>
                    <span className="ml-auto flex items-center gap-1.5">
                        <Key>esc</Key>
                        to close
                    </span>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function PaletteRow({
    item,
    active,
    onHover,
    onSelect,
}: {
    item: NavItem;
    active: boolean;
    onHover: () => void;
    onSelect: () => void;
}) {
    const Icon = item.icon;

    return (
        <li>
            <button
                type="button"
                onMouseMove={onHover}
                onClick={onSelect}
                className={cn(
                    'flex w-full items-center gap-3 rounded-control px-3 py-1.5 text-left text-sm transition-colors',
                    active ? 'bg-primary/10 text-primary' : 'hover:bg-muted',
                )}
            >
                <Icon className="size-4 shrink-0" aria-hidden="true" />
                <span className="truncate font-medium">{item.label}</span>
                {active && (
                    <CornerDownLeft className="ml-auto size-3.5 shrink-0 text-primary" aria-hidden="true" />
                )}
            </button>
        </li>
    );
}

/** A key cap. Small, quiet, and the same shape wherever it appears. */
function Key({ children }: { children: React.ReactNode }) {
    return (
        <kbd className="inline-flex min-w-5 items-center justify-center rounded border border-border bg-card px-1 py-0.5 font-sans text-[10px] leading-none">
            {children}
        </kbd>
    );
}

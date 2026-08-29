import { ChevronDown } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Checkbox } from '@/Components/ui/checkbox';
import { cn } from '@/lib/utils';

export interface PermissionGroup {
    key: string;
    label: string;
    permissions: Array<{ name: string; label: string }>;
}

/**
 * Where a ticked permission came from.
 *
 * · role    — granted by one of the roles currently selected
 * · direct  — granted to this person specifically
 * · revoked — the role grants it, but it has been taken away from this person
 *
 * Showing the provenance is the whole point of the matrix. Without it, an
 * administrator unticking a box cannot tell whether they are about to remove a
 * direct grant or create a revocation — two very different acts that look
 * identical as a bare checkbox.
 */
export type PermissionOrigin = 'role' | 'direct' | 'revoked' | null;

interface PermissionMatrixProps {
    catalog: PermissionGroup[];
    checked: (permission: string) => boolean;
    onToggle: (permission: string) => void;
    /** Omit for a plain on/off grid, as on the Roles page. */
    origin?: (permission: string) => PermissionOrigin;
    disabled?: boolean;
    className?: string;
}

const ORIGIN_TAGS: Record<Exclude<PermissionOrigin, null>, { label: string; className: string }> = {
    role: { label: 'role', className: 'bg-primary/10 text-primary' },
    direct: { label: 'direct', className: 'bg-info/15 text-info' },
    revoked: { label: 'revoked', className: 'bg-destructive/15 text-destructive' },
};

export function PermissionMatrix({
    catalog,
    checked,
    onToggle,
    origin,
    disabled = false,
    className,
}: PermissionMatrixProps) {
    // Everything open by default. These groups are short, and a matrix that
    // starts collapsed hides the thing the page exists to show.
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

    return (
        <div className={cn('space-y-2', className)}>
            {catalog.map((group) => (
                <Group
                    key={group.key}
                    group={group}
                    checked={checked}
                    onToggle={onToggle}
                    origin={origin}
                    disabled={disabled}
                    collapsed={collapsed.has(group.key)}
                    onToggleCollapse={() =>
                        setCollapsed((current) => {
                            const next = new Set(current);
                            next.has(group.key) ? next.delete(group.key) : next.add(group.key);

                            return next;
                        })
                    }
                />
            ))}
        </div>
    );
}

function Group({
    group,
    checked,
    onToggle,
    origin,
    disabled,
    collapsed,
    onToggleCollapse,
}: {
    group: PermissionGroup;
    checked: (permission: string) => boolean;
    onToggle: (permission: string) => void;
    origin?: (permission: string) => PermissionOrigin;
    disabled: boolean;
    collapsed: boolean;
    onToggleCollapse: () => void;
}) {
    const names = useMemo(() => group.permissions.map((p) => p.name), [group.permissions]);
    const on = names.filter(checked).length;

    const allOn = on === names.length;
    const someOn = on > 0 && !allOn;

    /*
     | The header checkbox toggles the whole module.
     |
     | Partially-on means "turn the rest on" rather than "turn everything off":
     | the administrator is clearly mid-way through granting a module, and
     | wiping their work would be the more surprising of the two.
     */
    function toggleGroup() {
        const target = !allOn;

        names.forEach((name) => {
            if (checked(name) !== target) {
                onToggle(name);
            }
        });
    }

    return (
        <div className="overflow-hidden rounded-control border border-border">
            <div className="flex items-center gap-3 bg-muted/40 px-3 py-2">
                <label className="flex cursor-pointer items-center gap-2.5">
                    <Checkbox
                        checked={allOn}
                        indeterminate={someOn}
                        disabled={disabled}
                        onChange={toggleGroup}
                        aria-label={`All ${group.label} permissions`}
                    />
                    <span className="text-sm font-semibold">{group.label}</span>
                </label>

                <span className="text-xs text-muted-foreground tabular">
                    {on}/{names.length}
                </span>

                <button
                    type="button"
                    onClick={onToggleCollapse}
                    className="ml-auto rounded-control p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    aria-expanded={!collapsed}
                    aria-label={collapsed ? `Expand ${group.label}` : `Collapse ${group.label}`}
                >
                    <ChevronDown className={cn('size-4 transition-transform', collapsed && '-rotate-90')} />
                </button>
            </div>

            {!collapsed && (
                <ul className="divide-y divide-border">
                    {group.permissions.map((permission) => {
                        const isOn = checked(permission.name);
                        const tag = origin?.(permission.name) ?? null;

                        return (
                            <li key={permission.name}>
                                <label
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2.5 px-3 py-2 transition-colors hover:bg-muted/40',
                                        disabled && 'cursor-not-allowed',
                                    )}
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={isOn}
                                        disabled={disabled}
                                        onChange={() => onToggle(permission.name)}
                                    />

                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'block text-sm',
                                                // Struck through only when the role grants it and
                                                // it has been taken away — the state that most
                                                // needs to be obvious at a glance.
                                                tag === 'revoked'
                                                    ? 'text-muted-foreground line-through'
                                                    : 'text-foreground',
                                            )}
                                        >
                                            {permission.label}
                                        </span>
                                        <code className="block text-[11px] text-muted-foreground">
                                            {permission.name}
                                        </code>
                                    </span>

                                    {tag && (
                                        <span
                                            className={cn(
                                                'mt-0.5 shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                ORIGIN_TAGS[tag].className,
                                            )}
                                        >
                                            {ORIGIN_TAGS[tag].label}
                                        </span>
                                    )}
                                </label>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

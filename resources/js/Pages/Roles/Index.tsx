import { Head, router } from '@inertiajs/react';
import { Info, RotateCcw, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PermissionMatrix, type PermissionGroup } from '@/Components/data/PermissionMatrix';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ConfirmDialog } from '@/Components/ui/dialog';
import { adminLayout } from '@/Layouts/AdminLayout';
import { cn } from '@/lib/utils';

interface RoleRow {
    id: number;
    name: string;
    description: string;
    permissions: string[];
    users_count: number;
}

interface RolesIndexProps {
    roles: RoleRow[];
    catalog: PermissionGroup[];
    canEdit: boolean;
}

export default function RolesIndex({ roles, catalog, canEdit }: RolesIndexProps) {
    const [selectedId, setSelectedId] = useState(roles[0]?.id ?? 0);
    const [draft, setDraft] = useState<string[] | null>(null);
    const [confirmReset, setConfirmReset] = useState(false);
    const [saving, setSaving] = useState(false);

    const selected = useMemo(
        () => roles.find((role) => role.id === selectedId) ?? roles[0],
        [roles, selectedId],
    );

    // `draft` is null until something is changed, so switching between roles
    // shows the saved state rather than a half-edited one carried across.
    const current = draft ?? selected?.permissions ?? [];
    const dirty = draft !== null;

    function selectRole(id: number) {
        setSelectedId(id);
        setDraft(null);
    }

    /*
     | Built on the latest draft, not this render's copy: the module checkbox
     | calls this once per permission in a row, and reading `current` made each
     | call undo the one before — so "all" changed a single permission.
     */
    function toggle(permission: string) {
        const base = selected?.permissions ?? [];

        setDraft((previous) => {
            const list = previous ?? base;

            return list.includes(permission) ? list.filter((name) => name !== permission) : [...list, permission];
        });
    }

    function save() {
        if (!selected) return;

        setSaving(true);
        router.patch(
            `/roles/${selected.id}`,
            { permissions: current },
            {
                preserveScroll: true,
                onSuccess: () => setDraft(null),
                onFinish: () => setSaving(false),
            },
        );
    }

    if (!selected) {
        return null;
    }

    return (
        <div className="space-y-6">
            <Head title="Roles" />

            <PageHeader
                title="Roles"
                subtitle="What each role grants by default. Individual people can be given more, or have something taken away, from their own account."
            />

            <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
                <Card className="h-fit">
                    <CardHeader>
                        <CardTitle>The five roles</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1 p-2">
                        {roles.map((role) => (
                            <button
                                key={role.id}
                                type="button"
                                onClick={() => selectRole(role.id)}
                                className={cn(
                                    'w-full rounded-control px-3 py-2.5 text-left transition-colors',
                                    role.id === selectedId ? 'bg-primary/10' : 'hover:bg-muted',
                                )}
                            >
                                <span className="flex items-center gap-1.5">
                                    <span
                                        className={cn(
                                            'text-sm font-semibold',
                                            role.id === selectedId && 'text-primary',
                                        )}
                                    >
                                        {role.name}
                                    </span>
                                </span>
                                <span className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                    <span className="tabular">{role.permissions.length} permissions</span>
                                    <span aria-hidden="true">·</span>
                                    <span className="inline-flex items-center gap-1 tabular">
                                        <Users className="size-3" aria-hidden="true" />
                                        {role.users_count}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="min-w-0">
                            <CardTitle className="flex items-center gap-2">
                                {selected.name}
                                {dirty && <Badge tone="warning">Unsaved</Badge>}
                            </CardTitle>
                            <p className="mt-0.5 text-sm text-muted-foreground">{selected.description}</p>
                        </div>

                        {canEdit && (
                            <div className="flex shrink-0 items-center gap-2">
                                <Button variant="outline" size="sm" onClick={() => setConfirmReset(true)}>
                                    <RotateCcw />
                                    Reset to default
                                </Button>
                                <Button size="sm" onClick={save} loading={saving} disabled={!dirty}>
                                    Save changes
                                </Button>
                            </div>
                        )}
                    </CardHeader>

                    <div className="flex items-start gap-2 border-b border-border bg-muted/40 px-5 py-3 text-xs text-muted-foreground">
                        <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        <p>
                            Changes here affect everybody holding this role. For a single person, edit their
                            account instead — that is what direct grants and revocations are for. Re-seeding the
                            database resets a role to its definition in code.
                        </p>
                    </div>

                    <CardContent>
                        <PermissionMatrix
                            catalog={catalog}
                            checked={(permission) => current.includes(permission)}
                            onToggle={toggle}
                            disabled={!canEdit}
                        />
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                open={confirmReset}
                onClose={() => setConfirmReset(false)}
                destructive={false}
                onConfirm={() => {
                    router.post(
                        `/roles/${selected.id}/reset`,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => setDraft(null),
                            onFinish: () => setConfirmReset(false),
                        },
                    );
                }}
                title={`Reset ${selected.name}?`}
                description="This puts the role back to the permissions defined in code, discarding any changes made here. Everybody holding the role is affected."
                confirmLabel="Reset role"
            />
        </div>
    );
}

RolesIndex.layout = adminLayout;

import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Search, ShieldCheck, Trash2, UserCheck, UserRound, Users as UsersIcon, UserX } from 'lucide-react';
import { useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { ConfirmDialog } from '@/Components/ui/dialog';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { Switch } from '@/Components/ui/checkbox';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { relative } from '@/lib/format';
import type { Paginated } from '@/types';

interface StaffRow {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    initials: string;
    avatar_url: string | null;
    is_active: boolean;
    roles: string[];
    last_login_at: string | null;
    /** Answered by UserPolicy, per row — never re-derived here. */
    can: { edit: boolean; delete: boolean; toggle: boolean };
}

interface UsersIndexProps {
    users: Paginated<StaffRow>;
    filters: { search: string; role: string; status: string };
    roles: string[];
    counts: { total: number; active: number; inactive: number };
}

export default function UsersIndex({ users, filters, roles, counts }: UsersIndexProps) {
    const { can, user: me } = usePermissions();
    const [search, setSearch] = useState(filters.search);
    const [pendingDelete, setPendingDelete] = useState<StaffRow | null>(null);

    /*
     | Filters go through the server, not through client-side array filtering.
     |
     | The list is paginated, so filtering in the browser would only ever search
     | the twenty rows on screen — which looks like it works right up until
     | somebody's colleague is on page two.
     */
    function applyFilters(next: Partial<UsersIndexProps['filters']>) {
        router.get(
            '/users',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="People" />

            <PageHeader
                title="People"
                subtitle="Staff accounts, their roles, and what each person can reach."
                actions={
                    can('users.create') && (
                        <Button asChild>
                            <Link href="/users/create">
                                <Plus />
                                Add person
                            </Link>
                        </Button>
                    )
                }
            />

            <StatGrid count={3}>
                <StatCard label="Total accounts" value={counts.total} icon={UsersIcon} tone="primary" />
                <StatCard label="Active" value={counts.active} icon={UserCheck} tone="success" sub="Can sign in" />
                <StatCard
                    label="Deactivated"
                    value={counts.inactive}
                    icon={UserX}
                    tone="warning"
                    sub="Kept for their history"
                />
            </StatGrid>

            <Card>
                <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                    <form
                        className="min-w-0 flex-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({});
                        }}
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, email or job title…"
                            icon={<Search />}
                            className="max-w-md"
                            aria-label="Search people"
                        />
                    </form>

                    <Select
                        value={filters.role}
                        onChange={(event) => applyFilters({ role: event.target.value })}
                        className="w-auto min-w-40"
                        aria-label="Filter by role"
                    >
                        <option value="">All roles</option>
                        {roles.map((role) => (
                            <option key={role} value={role}>
                                {role}
                            </option>
                        ))}
                    </Select>

                    <Select
                        value={filters.status}
                        onChange={(event) => applyFilters({ status: event.target.value })}
                        className="w-auto min-w-36"
                        aria-label="Filter by status"
                    >
                        <option value="">Any status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Deactivated</option>
                    </Select>
                </div>

                {users.data.length === 0 ? (
                    <EmptyState
                        icon={UserRound}
                        title="Nobody matches those filters"
                        description="Try a different search, or clear the filters to see everyone."
                    />
                ) : (
                    <>
                        {/* A real table from md: up. Below that it becomes a stacked list —
                            a six-column table on a phone hides the column that matters. */}
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Person</TH>
                                        <TH>Roles</TH>
                                        <TH>Last seen</TH>
                                        <TH>Active</TH>
                                        <TH className="text-right">Actions</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {users.data.map((person) => (
                                        <TR key={person.id}>
                                            <TD>
                                                <div className="flex items-center gap-3">
                                                    <Avatar
                                                        name={person.name}
                                                        initials={person.initials}
                                                        src={person.avatar_url}
                                                    />
                                                    <CellStack
                                                        primary={
                                                            <span className="flex items-center gap-2">
                                                                {person.name}
                                                                {me?.id === person.id && (
                                                                    <span className="text-xs font-normal text-muted-foreground">
                                                                        (you)
                                                                    </span>
                                                                )}
                                                            </span>
                                                        }
                                                        secondary={person.job_title ?? person.email}
                                                    />
                                                </div>
                                            </TD>
                                            <TD>
                                                <RoleChips roles={person.roles} />
                                            </TD>
                                            <TD className="text-sm text-muted-foreground">
                                                {person.last_login_at ? relative(person.last_login_at) : 'Never'}
                                            </TD>
                                            <TD>
                                                <ActiveToggle person={person} />
                                            </TD>
                                            <TD>
                                                <div className="flex justify-end gap-1">
                                                    {person.can.edit && (
                                                        <Button asChild variant="ghost" size="icon" aria-label={`Edit ${person.name}`}>
                                                            <Link href={`/users/${person.id}/edit`}>
                                                                <Pencil />
                                                            </Link>
                                                        </Button>
                                                    )}
                                                    {person.can.delete && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Remove ${person.name}`}
                                                            className="text-destructive hover:bg-destructive/10"
                                                            onClick={() => setPendingDelete(person)}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    )}
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {users.data.map((person) => (
                                <li key={person.id} className="flex items-start gap-3 p-4">
                                    <Avatar name={person.name} initials={person.initials} src={person.avatar_url} />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium">{person.name}</p>
                                        <p className="truncate text-xs text-muted-foreground">{person.email}</p>
                                        <div className="mt-2">
                                            <RoleChips roles={person.roles} />
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-2">
                                        <ActiveToggle person={person} />
                                        {person.can.edit && (
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/users/${person.id}/edit`}>Edit</Link>
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={users} />
                    </>
                )}
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/users/${pendingDelete.id}`, {
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                title={`Remove ${pendingDelete?.name}?`}
                description="They will no longer be able to sign in. The interviews, articles and deals they own keep their name — nothing is deleted with them."
                confirmLabel="Remove account"
            />
        </div>
    );
}

function RoleChips({ roles }: { roles: string[] }) {
    if (roles.length === 0) {
        return (
            <Badge tone="warning" title="This account cannot sign in until it has a role.">
                No role
            </Badge>
        );
    }

    return (
        <div className="flex flex-wrap gap-1">
            {roles.map((role) => (
                <Badge key={role} tone={role.includes('Admin') ? 'primary' : 'default'}>
                    {role === 'Admin' && <ShieldCheck className="size-3" aria-hidden="true" />}
                    {role}
                </Badge>
            ))}
        </div>
    );
}

/**
 * Deactivate straight from the list.
 *
 * Joining and leaving are the two most common administrative acts, so this is
 * one click rather than opening the whole form. Disabled on your own row: the
 * request would be refused by the policy anyway, and a control that looks live
 * and then errors is worse than one that is visibly unavailable.
 */
function ActiveToggle({ person }: { person: StaffRow }) {
    // `can.toggle` already accounts for "not yourself" and "not the last
    // administrator", so this only has to render the answer.
    if (!person.can.toggle) {
        return (
            <Badge tone={person.is_active ? 'success' : 'default'}>
                {person.is_active ? 'Active' : 'Deactivated'}
            </Badge>
        );
    }

    return (
        <Switch
            checked={person.is_active}
            aria-label={`${person.is_active ? 'Deactivate' : 'Activate'} ${person.name}`}
            onChange={() =>
                router.patch(
                    `/users/${person.id}/active`,
                    {},
                    { preserveScroll: true, preserveState: false },
                )
            }
        />
    );
}

UsersIndex.layout = adminLayout;

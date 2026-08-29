import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Info, ShieldCheck, ShieldOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PermissionMatrix, type PermissionGroup, type PermissionOrigin } from '@/Components/data/PermissionMatrix';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox, Switch } from '@/Components/ui/checkbox';
import { ConfirmDialog } from '@/Components/ui/dialog';
import { Field, Input } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';
import { cn } from '@/lib/utils';

interface RoleOption {
    name: string;
    description: string;
    assignable: boolean;
}

interface UserFormProps {
    user: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        job_title: string | null;
        team: string | null;
        is_active: boolean;
        roles: string[];
        direct_permissions: string[];
        denied_permissions: string[];
        two_factor_enabled: boolean;
        can: { reset_two_factor: boolean };
    } | null;
    catalog: PermissionGroup[];
    roles: RoleOption[];
    rolePermissions: Record<string, string[]>;
    canManagePermissions: boolean;
}

export default function UserForm({ user, catalog, roles, rolePermissions, canManagePermissions }: UserFormProps) {
    const editing = user !== null;
    const [resetting, setResetting] = useState(false);

    const { data, setData, post, patch, processing, errors } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        password_confirmation: '',
        phone: user?.phone ?? '',
        job_title: user?.job_title ?? '',
        team: user?.team ?? '',
        is_active: user?.is_active ?? true,
        roles: user?.roles ?? [],
        direct_permissions: user?.direct_permissions ?? [],
        denied_permissions: user?.denied_permissions ?? [],
    });

    /*
     | What the selected roles grant, recomputed as roles are ticked.
     |
     | The server sends the role→permissions map with the page so this can be
     | worked out in the browser. Without it, ticking a role would need a round
     | trip before the matrix could show what it had just granted, and the
     | administrator would be choosing roles blind.
     */
    const fromRoles = useMemo(() => {
        const granted = new Set<string>();

        data.roles.forEach((role) => {
            (rolePermissions[role] ?? []).forEach((permission) => granted.add(permission));
        });

        return granted;
    }, [data.roles, rolePermissions]);

    const direct = useMemo(() => new Set(data.direct_permissions), [data.direct_permissions]);
    const denied = useMemo(() => new Set(data.denied_permissions), [data.denied_permissions]);

    /** Effective state: a role grant that has not been revoked, or a direct grant. */
    const isChecked = (permission: string) =>
        (fromRoles.has(permission) && !denied.has(permission)) || direct.has(permission);

    const originOf = (permission: string): PermissionOrigin => {
        if (fromRoles.has(permission)) {
            return denied.has(permission) ? 'revoked' : 'role';
        }

        return direct.has(permission) ? 'direct' : null;
    };

    /**
     * One checkbox, four possible meanings.
     *
     * Turning something off means "revoke" when a role granted it and "remove
     * the direct grant" when it did not. Turning it on means "undo the
     * revocation" or "add a direct grant". Collapsing those into one control is
     * what makes the matrix usable; the origin tag beside it is what keeps the
     * result legible.
     */
    function togglePermission(permission: string) {
        const grantedByRole = fromRoles.has(permission);

        if (isChecked(permission)) {
            if (grantedByRole) {
                setData('denied_permissions', [...data.denied_permissions, permission]);
            } else {
                setData(
                    'direct_permissions',
                    data.direct_permissions.filter((name) => name !== permission),
                );
            }

            return;
        }

        if (grantedByRole) {
            setData(
                'denied_permissions',
                data.denied_permissions.filter((name) => name !== permission),
            );
        } else {
            setData('direct_permissions', [...data.direct_permissions, permission]);
        }
    }

    function toggleRole(role: string) {
        setData(
            'roles',
            data.roles.includes(role) ? data.roles.filter((name) => name !== role) : [...data.roles, role],
        );
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();

        if (editing) {
            patch(`/users/${user.id}`);
        } else {
            post('/users');
        }
    }

    const revokedCount = data.denied_permissions.length;
    const directCount = data.direct_permissions.filter((name) => !fromRoles.has(name)).length;

    return (
        <div className="space-y-6">
            <Head title={editing ? `Edit ${user.name}` : 'Add person'} />

            <PageHeader
                title={editing ? user.name : 'Add a person'}
                subtitle={
                    editing
                        ? 'Change their details, roles, or exactly what they can reach.'
                        : 'Create an account and choose what it can do. They sign in with the email and password you set here.'
                }
                actions={
                    <Button asChild variant="outline">
                        <Link href="/users">
                            <ArrowLeft />
                            Back to people
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <Field label="Full name" htmlFor="name" error={errors.name} required className="sm:col-span-2">
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    invalid={Boolean(errors.name)}
                                    autoFocus
                                />
                            </Field>

                            <Field label="Email address" htmlFor="email" error={errors.email} required>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(event) => setData('email', event.target.value)}
                                    invalid={Boolean(errors.email)}
                                />
                            </Field>

                            <Field label="Phone" htmlFor="phone" error={errors.phone}>
                                <Input
                                    id="phone"
                                    value={data.phone}
                                    onChange={(event) => setData('phone', event.target.value)}
                                    invalid={Boolean(errors.phone)}
                                />
                            </Field>

                            <Field label="Job title" htmlFor="job_title" error={errors.job_title}>
                                <Input
                                    id="job_title"
                                    value={data.job_title}
                                    onChange={(event) => setData('job_title', event.target.value)}
                                    placeholder="Anchor"
                                />
                            </Field>

                            <Field label="Team" htmlFor="team" error={errors.team}>
                                <Input
                                    id="team"
                                    value={data.team}
                                    onChange={(event) => setData('team', event.target.value)}
                                    placeholder="Content"
                                />
                            </Field>

                            <Field
                                label={editing ? 'New password' : 'Password'}
                                htmlFor="password"
                                error={errors.password}
                                required={!editing}
                                hint={editing ? 'Leave blank to keep their current password.' : undefined}
                            >
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.password}
                                    onChange={(event) => setData('password', event.target.value)}
                                    invalid={Boolean(errors.password)}
                                />
                            </Field>

                            <Field label="Confirm password" htmlFor="password_confirmation">
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.password_confirmation}
                                    onChange={(event) => setData('password_confirmation', event.target.value)}
                                />
                            </Field>
                        </CardContent>
                    </Card>

                    {canManagePermissions && (
                        <Card>
                            <CardHeader>
                                <div>
                                    <CardTitle>Permissions</CardTitle>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        Roles set the baseline. Tick to grant something extra, untick to take
                                        something away from this person alone.
                                    </p>
                                </div>
                            </CardHeader>

                            {(revokedCount > 0 || directCount > 0) && (
                                <div className="flex items-start gap-2 border-b border-border bg-info/5 px-5 py-3 text-xs text-muted-foreground">
                                    <Info className="mt-0.5 size-3.5 shrink-0 text-info" aria-hidden="true" />
                                    <p>
                                        This account differs from its roles:{' '}
                                        {directCount > 0 && (
                                            <span className="font-medium text-foreground">
                                                {directCount} extra {directCount === 1 ? 'permission' : 'permissions'}
                                            </span>
                                        )}
                                        {directCount > 0 && revokedCount > 0 && ', '}
                                        {revokedCount > 0 && (
                                            <span className="font-medium text-foreground">
                                                {revokedCount} revoked
                                            </span>
                                        )}
                                        .
                                    </p>
                                </div>
                            )}

                            <CardContent>
                                <PermissionMatrix
                                    catalog={catalog}
                                    checked={isChecked}
                                    onToggle={togglePermission}
                                    origin={originOf}
                                />
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Roles</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {errors.roles && <p className="text-xs font-medium text-destructive">{errors.roles}</p>}

                            {roles.map((role) => (
                                <label
                                    key={role.name}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2.5 rounded-control border p-3 transition-colors',
                                        data.roles.includes(role.name)
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-border hover:bg-muted/50',
                                        !role.assignable && 'cursor-not-allowed opacity-60',
                                    )}
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={data.roles.includes(role.name)}
                                        disabled={!role.assignable}
                                        onChange={() => toggleRole(role.name)}
                                    />
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-1.5 text-sm font-medium">
                                            {role.name === 'Admin' && (
                                                <ShieldCheck className="size-3.5 text-primary" aria-hidden="true" />
                                            )}
                                            {role.name}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {role.description}
                                        </span>
                                        {!role.assignable && (
                                            <span className="mt-1 block text-xs text-warning">
                                                You cannot assign this role.
                                            </span>
                                        )}
                                    </span>
                                </label>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Access</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Switch
                                checked={data.is_active}
                                onChange={(event) => setData('is_active', event.target.checked)}
                                label="Account is active"
                                description="Switching this off signs them out on their next click, and keeps everything they own."
                            />
                            {errors.is_active && (
                                <p className="text-xs font-medium text-destructive">{errors.is_active}</p>
                            )}

                            {editing && (
                                <div className="rounded-control border border-border bg-muted/40 p-3">
                                    <p className="text-xs text-muted-foreground">
                                        Two-factor authentication is{' '}
                                        <span className="font-medium text-foreground">
                                            {user.two_factor_enabled ? 'on' : 'off'}
                                        </span>
                                        .{' '}
                                        {user.two_factor_enabled
                                            ? 'Clear it if they have lost or replaced the phone it is set up on.'
                                            : 'They can set it up themselves from their profile.'}
                                    </p>

                                    {user.can.reset_two_factor && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="mt-3"
                                            onClick={() => setResetting(true)}
                                        >
                                            <ShieldOff />
                                            Clear two-factor
                                        </Button>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" loading={processing} className="flex-1">
                            {editing ? 'Save changes' : 'Create account'}
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/users">Cancel</Link>
                        </Button>
                    </div>
                </div>
            </form>

            <ConfirmDialog
                open={resetting}
                onClose={() => setResetting(false)}
                onConfirm={() => {
                    router.delete(`/users/${user?.id}/two-factor`, {
                        preserveScroll: true,
                        onFinish: () => setResetting(false),
                    });
                }}
                title={`Clear two-factor for ${user?.name}?`}
                description="Their current authenticator app will stop working and they will be signed out everywhere. They can set it up again on a new device — and will be asked to, on their next sign-in, if their role requires it."
                confirmLabel="Clear two-factor"
            />
        </div>
    );
}

UserForm.layout = adminLayout;

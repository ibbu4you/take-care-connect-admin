import { Head, router, useForm } from '@inertiajs/react';
import { Copy, KeyRound, ShieldCheck, ShieldOff } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Textarea } from '@/Components/ui/input';
import { Avatar } from '@/Components/ui/misc';
import { useToast } from '@/Components/ui/toast';
import { adminLayout } from '@/Layouts/AdminLayout';
import { relative } from '@/lib/format';

interface ProfileEditProps {
    profile: {
        name: string;
        email: string;
        phone: string | null;
        job_title: string | null;
        team: string | null;
        bio: string | null;
        avatar_url: string | null;
        initials: string;
        roles: string[];
        two_factor_enabled: boolean;
        recovery_codes_left: number;
        last_login_at: string | null;
    };
    recovery_codes: string[] | null;
}

export default function ProfileEdit({ profile, recovery_codes }: ProfileEditProps) {
    // Shown exactly once, straight after enrolment. They are encrypted at rest
    // and never rendered again — that is the point of them.
    const [showCodes, setShowCodes] = useState(recovery_codes !== null);
    const [disabling, setDisabling] = useState(false);

    return (
        <div className="space-y-6">
            <Head title="My profile" />

            <PageHeader
                title="My profile"
                subtitle="Your details and how you sign in. Your roles and permissions are set by an administrator."
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <DetailsCard profile={profile} />
                    <PasswordCard />
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Account</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-3">
                                <Avatar
                                    name={profile.name}
                                    initials={profile.initials}
                                    src={profile.avatar_url}
                                    size="lg"
                                />
                                <div className="min-w-0">
                                    <p className="truncate font-semibold">{profile.name}</p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {profile.job_title ?? profile.email}
                                    </p>
                                </div>
                            </div>

                            <div className="flex flex-wrap gap-1">
                                {profile.roles.map((role) => (
                                    <Badge key={role} tone="primary">
                                        {role}
                                    </Badge>
                                ))}
                            </div>

                            {profile.last_login_at && (
                                <p className="text-xs text-muted-foreground">
                                    Last signed in {relative(profile.last_login_at)}.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Two-factor authentication</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-2">
                                {profile.two_factor_enabled ? (
                                    <Badge tone="success">
                                        <ShieldCheck className="size-3" aria-hidden="true" />
                                        On
                                    </Badge>
                                ) : (
                                    <Badge tone="warning">
                                        <ShieldOff className="size-3" aria-hidden="true" />
                                        Off
                                    </Badge>
                                )}
                            </div>

                            <p className="text-xs text-muted-foreground">
                                {profile.two_factor_enabled
                                    ? `A code from your authenticator app is needed each time you sign in. ${profile.recovery_codes_left} recovery ${profile.recovery_codes_left === 1 ? 'code remains' : 'codes remain'}.`
                                    : 'Optional. Adds a second step to signing in, using an authenticator app on your phone.'}
                            </p>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant={profile.two_factor_enabled ? 'outline' : 'primary'}
                                    size="sm"
                                    onClick={() => router.get('/two-factor/setup')}
                                >
                                    <KeyRound />
                                    {profile.two_factor_enabled ? 'Regenerate' : 'Turn on'}
                                </Button>

                                {profile.two_factor_enabled && (
                                    <Button variant="ghost" size="sm" onClick={() => setDisabling(true)}>
                                        Turn off
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <RecoveryCodesDialog
                open={showCodes && recovery_codes !== null}
                codes={recovery_codes ?? []}
                onClose={() => setShowCodes(false)}
            />

            <DisableTwoFactorDialog open={disabling} onClose={() => setDisabling(false)} />
        </div>
    );
}

function DetailsCard({ profile }: { profile: ProfileEditProps['profile'] }) {
    const { data, setData, post, processing, errors } = useForm({
        // Inertia cannot PATCH multipart, so the method is spoofed and the route
        // accepts PATCH. Without this the avatar file silently never arrives.
        _method: 'patch' as const,
        name: profile.name,
        email: profile.email,
        phone: profile.phone ?? '',
        bio: profile.bio ?? '',
        avatar: null as File | null,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/profile', { forceFormData: true, preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Full name" htmlFor="name" error={errors.name} required>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            invalid={Boolean(errors.name)}
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
                        />
                    </Field>

                    <Field label="Photo" htmlFor="avatar" error={errors.avatar} hint="JPG or PNG, up to 2 MB.">
                        <Input
                            id="avatar"
                            type="file"
                            accept="image/*"
                            className="h-auto py-1.5 file:mr-3 file:rounded-control file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-xs file:font-medium"
                            onChange={(event) => setData('avatar', event.target.files?.[0] ?? null)}
                        />
                    </Field>

                    <Field label="About" htmlFor="bio" error={errors.bio} className="sm:col-span-2">
                        <Textarea
                            id="bio"
                            value={data.bio}
                            onChange={(event) => setData('bio', event.target.value)}
                            rows={3}
                        />
                    </Field>

                    <div className="sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Save details
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function PasswordCard() {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        put('/profile/password', {
            preserveScroll: true,
            onSuccess: () => reset(),
            // Clear the fields even on failure — a rejected password should not
            // stay sitting in the DOM.
            onError: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>Password</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Changing this signs you out everywhere else.
                    </p>
                </div>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Current password"
                        htmlFor="current_password"
                        error={errors.current_password}
                        required
                        className="sm:col-span-2"
                    >
                        <Input
                            id="current_password"
                            type="password"
                            autoComplete="current-password"
                            value={data.current_password}
                            onChange={(event) => setData('current_password', event.target.value)}
                            invalid={Boolean(errors.current_password)}
                        />
                    </Field>

                    <Field label="New password" htmlFor="new_password" error={errors.password} required>
                        <Input
                            id="new_password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            invalid={Boolean(errors.password)}
                        />
                    </Field>

                    <Field label="Confirm new password" htmlFor="new_password_confirmation" required>
                        <Input
                            id="new_password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                        />
                    </Field>

                    <div className="sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Change password
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function RecoveryCodesDialog({
    open,
    codes,
    onClose,
}: {
    open: boolean;
    codes: string[];
    onClose: () => void;
}) {
    const { notify } = useToast();

    async function copy() {
        try {
            await navigator.clipboard.writeText(codes.join('\n'));
            notify('Recovery codes copied.', 'success');
        } catch {
            // The clipboard API needs a secure context and a user gesture, and
            // fails outright in some browsers. The codes are on screen anyway.
            notify('Could not copy — select and copy them by hand.', 'error');
        }
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Save your recovery codes"
            description="These are shown once and cannot be retrieved later. Each one works a single time, if you lose your phone."
            footer={
                <>
                    <Button variant="outline" onClick={copy}>
                        <Copy />
                        Copy all
                    </Button>
                    <Button onClick={onClose}>I have saved them</Button>
                </>
            }
        >
            <ul className="grid grid-cols-2 gap-2">
                {codes.map((code) => (
                    <li
                        key={code}
                        className="rounded-control border border-border bg-muted px-3 py-2 text-center font-mono text-sm tabular"
                    >
                        {code}
                    </li>
                ))}
            </ul>
        </Dialog>
    );
}

function DisableTwoFactorDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { data, setData, delete: destroy, processing, errors, reset } = useForm({ password: '' });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Turn off two-factor authentication"
            description="Your account will be protected by its password alone."
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        loading={processing}
                        onClick={() =>
                            destroy('/two-factor', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Turn it off
                    </Button>
                </>
            }
        >
            <Field label="Confirm your password" htmlFor="disable_password" error={errors.password} required>
                <Input
                    id="disable_password"
                    type="password"
                    autoComplete="current-password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    invalid={Boolean(errors.password)}
                />
            </Field>
        </Dialog>
    );
}

ProfileEdit.layout = adminLayout;

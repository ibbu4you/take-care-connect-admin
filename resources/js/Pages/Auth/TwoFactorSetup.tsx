import { Head, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Input } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';

interface TwoFactorSetupProps {
    secret: string;
    /** Inline SVG, generated server-side — the QR encodes a shared secret and
     *  must not be fetched from anywhere. */
    qr_code: string;
    already_enabled: boolean;
}

export default function TwoFactorSetup({ secret, qr_code, already_enabled }: TwoFactorSetupProps) {
    const { data, setData, post, processing, errors } = useForm({ code: '', password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/two-factor/setup');
    }

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <Head title="Two-factor authentication" />

            <PageHeader
                title="Set up two-factor authentication"
                subtitle={
                    already_enabled
                        ? 'Scanning this new code replaces your existing setup and issues fresh recovery codes.'
                        : 'Add a second step to signing in, so a stolen password is not enough on its own.'
                }
            />

            <Card>
                <CardHeader>
                    <CardTitle>1. Scan this with your authenticator app</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                        <div
                            className="shrink-0 rounded-xl border border-border bg-white p-3 [&_svg]:block"
                            // Server-generated from the otpauth URI; no user input reaches it.
                            dangerouslySetInnerHTML={{ __html: qr_code }}
                        />

                        <div className="min-w-0 space-y-2 text-sm">
                            <p className="text-muted-foreground">
                                Use Google Authenticator, Authy, 1Password, or any other TOTP app.
                            </p>
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Cannot scan? Enter this key by hand:
                                </p>
                                <code className="mt-1 block break-all rounded-control border border-border bg-muted px-3 py-2 font-mono text-xs">
                                    {secret}
                                </code>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>2. Enter the six-digit code it shows</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <Field label="Verification code" htmlFor="code" error={errors.code} required>
                            <Input
                                id="code"
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value)}
                                // A TOTP code is digits, and `one-time-code`
                                // lets a phone offer it from the SMS/keychain.
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={7}
                                placeholder="000000"
                                className="w-40 text-center font-mono text-lg tracking-[0.3em] tabular"
                                autoFocus
                                invalid={Boolean(errors.code)}
                            />
                        </Field>

                        {/* Replacing a working setup costs the password, as switching it off does. */}
                        {already_enabled && (
                            <Field label="Your password" htmlFor="password" error={errors.password} required>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={data.password}
                                    onChange={(event) => setData('password', event.target.value)}
                                    className="w-56"
                                    invalid={Boolean(errors.password)}
                                />
                            </Field>
                        )}

                        <Button type="submit" loading={processing}>
                            <ShieldCheck />
                            {already_enabled ? 'Replace' : 'Turn on'}
                        </Button>
                    </form>

                    <p className="mt-4 text-xs text-muted-foreground">
                        You will be given eight recovery codes once this is confirmed. Save them somewhere safe —
                        they are the only way back in if you lose your phone, and they are shown only once.
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}

TwoFactorSetup.layout = adminLayout;

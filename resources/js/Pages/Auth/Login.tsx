import { Head, useForm, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { Eye, EyeOff, Lock, Mail } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Field, Input } from '@/Components/ui/input';
import { Logo } from '@/Components/layout/Logo';

export default function Login() {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [revealed, setRevealed] = useState(false);

    // There is no toast layer outside the admin shell, so a flashed message
    // ("That page expired", from a 419) would otherwise vanish and the form
    // would just quietly reappear.
    const flash = usePage<SharedProps>().props.flash;
    const notice = flash?.error ?? flash?.info ?? null;

    function submit(event: FormEvent) {
        event.preventDefault();

        post('/login', {
            // Never leave a password in component state after the round trip,
            // even a failed one.
            onFinish: () => reset('password'),
        });
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-app px-4 py-12">
            <Head title="Sign in" />

            {/*
              | Two soft brand washes behind the card.
              |
              | Purely decorative, so aria-hidden, and `motion-safe` only —
              | there is no animation here, but the blur is heavy enough to be
              | worth keeping off the paint path on low-end machines.
            */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                <div className="absolute -left-24 -top-24 size-72 rounded-full bg-primary/10 blur-3xl" />
                <div className="absolute -bottom-24 -right-24 size-72 rounded-full bg-chart-5/10 blur-3xl" />
            </div>

            <div className="relative w-full max-w-sm">
                <div className="mb-6 flex flex-col items-center text-center">
                    <Logo size={96} className="mb-4" />
                    <h1 className="text-xl font-bold tracking-tight">Take Care Connect</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Sign in to the operations workspace.
                    </p>
                </div>

                <Card className="p-6">
                    {notice && (
                        <p
                            role="alert"
                            className="mb-4 rounded-control border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {notice}
                        </p>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <Field label="Email address" htmlFor="email" error={errors.email}>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                autoComplete="username"
                                autoFocus
                                required
                                invalid={Boolean(errors.email)}
                                icon={<Mail />}
                                placeholder="you@takecareconnect.com"
                            />
                        </Field>

                        <Field label="Password" htmlFor="password" error={errors.password}>
                            <div className="relative">
                                <Input
                                    id="password"
                                    type={revealed ? 'text' : 'password'}
                                    name="password"
                                    value={data.password}
                                    onChange={(event) => setData('password', event.target.value)}
                                    autoComplete="current-password"
                                    required
                                    invalid={Boolean(errors.password)}
                                    icon={<Lock />}
                                    className="pr-10"
                                />
                                <button
                                    type="button"
                                    onClick={() => setRevealed((value) => !value)}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded-control p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    aria-label={revealed ? 'Hide password' : 'Show password'}
                                >
                                    {revealed ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            </div>
                        </Field>

                        <label className="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(event) => setData('remember', event.target.checked)}
                                className="size-4 rounded border-input text-primary focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            Keep me signed in
                        </label>

                        <Button type="submit" className="w-full" loading={processing} size="lg">
                            Sign in
                        </Button>
                    </form>
                </Card>

                <p className="mt-6 text-center text-xs text-muted-foreground">
                    Accounts are created by an administrator. Contact your manager if you cannot get in.
                </p>
            </div>
        </div>
    );
}

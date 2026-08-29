import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Field, Input } from '@/Components/ui/input';

/**
 * The gate between a correct password and the application.
 *
 * Rendered without the admin shell on purpose: at this point the person is
 * authenticated but not yet trusted, and showing them the sidebar — the whole
 * menu of what the account can reach — before the second factor is answered
 * would leak exactly what an attacker with a stolen password wants to know.
 */
export default function TwoFactorChallenge() {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });
    const [usingRecovery, setUsingRecovery] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();

        post('/two-factor/challenge', {
            onError: () => reset('code'),
        });
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-app px-4 py-12">
            <Head title="Two-factor authentication" />

            <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                <div className="absolute -left-24 -top-24 size-72 rounded-full bg-primary/10 blur-3xl" />
                <div className="absolute -bottom-24 -right-24 size-72 rounded-full bg-chart-5/10 blur-3xl" />
            </div>

            <div className="relative w-full max-w-sm">
                <div className="mb-6 flex flex-col items-center text-center">
                    <span
                        className="mb-3 inline-flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                        aria-hidden="true"
                    >
                        <ShieldCheck className="size-6" />
                    </span>
                    <h1 className="text-xl font-bold tracking-tight">One more step</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {usingRecovery
                            ? 'Enter one of the recovery codes you saved.'
                            : 'Enter the code from your authenticator app.'}
                    </p>
                </div>

                <Card className="p-6">
                    <form onSubmit={submit} className="space-y-4">
                        <Field
                            label={usingRecovery ? 'Recovery code' : 'Verification code'}
                            htmlFor="code"
                            error={errors.code}
                            required
                        >
                            <Input
                                id="code"
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value)}
                                inputMode={usingRecovery ? 'text' : 'numeric'}
                                autoComplete="one-time-code"
                                placeholder={usingRecovery ? 'abcde-fghij' : '000000'}
                                className="text-center font-mono text-lg tracking-[0.2em] tabular"
                                autoFocus
                                invalid={Boolean(errors.code)}
                            />
                        </Field>

                        <Button type="submit" className="w-full" size="lg" loading={processing}>
                            Verify
                        </Button>
                    </form>

                    <div className="mt-4 flex flex-col gap-2 text-center text-xs">
                        <button
                            type="button"
                            onClick={() => {
                                setUsingRecovery((value) => !value);
                                reset('code');
                            }}
                            className="text-primary hover:underline"
                        >
                            {usingRecovery ? 'Use a code from my app instead' : 'I have lost my phone'}
                        </button>

                        <button
                            type="button"
                            onClick={() => router.post('/two-factor/abandon')}
                            className="text-muted-foreground hover:underline"
                        >
                            Sign out
                        </button>
                    </div>
                </Card>
            </div>
        </div>
    );
}

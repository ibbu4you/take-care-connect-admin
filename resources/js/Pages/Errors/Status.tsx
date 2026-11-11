import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Lock, SearchX, ServerCrash, TimerOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';

const STATUSES = {
    403: {
        icon: Lock,
        title: 'You do not have access to that',
        description:
            'Your account does not hold the permission this page needs. If you think it should, ask an administrator to check your role.',
    },
    404: {
        icon: SearchX,
        title: 'That page does not exist',
        description: 'The link may be out of date, or the record it pointed at may have been deleted.',
    },
    419: {
        icon: TimerOff,
        title: 'That page expired',
        description: 'You were away long enough for the session token to lapse. Sign in again to carry on.',
    },
    500: {
        icon: ServerCrash,
        title: 'Something went wrong',
        description: 'The error has been logged. Try again in a moment, and tell an administrator if it persists.',
    },
    503: {
        icon: ServerCrash,
        title: 'Down for maintenance',
        description: 'The workspace is being updated and will be back shortly.',
    },
} as const;

type KnownStatus = keyof typeof STATUSES;

/**
 * Errors rendered inside the shell rather than as a bare Laravel page.
 *
 * Somebody who follows a stale link keeps the sidebar and a way back, which
 * turns a dead end into a wrong turn.
 */
export default function Status({ status }: { status: number }) {
    const known = (status in STATUSES ? status : 500) as KnownStatus;
    const { icon: Icon, title, description } = STATUSES[known];
    const { user } = usePermissions();

    return (
        <div className="mx-auto max-w-lg py-12">
            <Head title={String(status)} />

            <Card className="flex flex-col items-center px-6 py-12 text-center">
                <span
                    className="mb-4 inline-flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <Icon className="size-6" />
                </span>

                <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground tabular">
                    Error {status}
                </p>
                <h1 className="mt-1 text-xl font-bold tracking-tight">{title}</h1>
                <p className="mt-2 max-w-sm text-sm text-muted-foreground">{description}</p>

                <Button asChild className="mt-6" variant="outline">
                    <Link href={user ? '/dashboard' : '/login'}>
                        <ArrowLeft />
                        {user ? 'Back to dashboard' : 'Back to sign in'}
                    </Link>
                </Button>
            </Card>
        </div>
    );
}

/*
 | The shell only when somebody is signed in.
 |
 | An error raised before HandleInertiaRequests — a 419 on the login form, a
 | 503 during a deploy — arrives with no user and no permissions. The sidebar
 | has nothing to show then, so stand the card on its own instead.
 */
function ErrorShell({ children }: { children: ReactNode }) {
    const { user } = usePermissions();

    if (user) {
        return <AdminLayout>{children}</AdminLayout>;
    }

    return <div className="flex min-h-screen items-center justify-center bg-app px-4">{children}</div>;
}

Status.layout = (page: ReactNode) => <ErrorShell>{page}</ErrorShell>;

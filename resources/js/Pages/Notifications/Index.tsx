import { Head, Link, router } from '@inertiajs/react';
import { AlarmClock, Bell, CheckCheck, Clapperboard, FileText, Mic, Trash2, Undo2, UserPlus } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { adminLayout } from '@/Layouts/AdminLayout';
import { dateTime, relative } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface NotificationRow {
    id: string;
    kind: string;
    title: string;
    body: string | null;
    url: string | null;
    actor: string | null;
    read: boolean;
    created_at: string | null;
}

interface IndexProps {
    notifications: Paginated<NotificationRow>;
    unread: number;
}

/** Icon and tint by what happened, not by which module it happened in. */
const KINDS: Record<string, { icon: typeof Bell; className: string }> = {
    assigned: { icon: UserPlus, className: 'bg-primary/10 text-primary' },
    handover: { icon: FileText, className: 'bg-info/15 text-info' },
    review: { icon: Mic, className: 'bg-warning/15 text-warning' },
    approved: { icon: CheckCheck, className: 'bg-success/15 text-success' },
    revisions: { icon: Undo2, className: 'bg-destructive/15 text-destructive' },
    published: { icon: Clapperboard, className: 'bg-success/15 text-success' },
    reminder: { icon: AlarmClock, className: 'bg-muted text-muted-foreground' },
};


export default function NotificationsIndex({ notifications, unread }: IndexProps) {
    return (
        <div className="space-y-6">
            <Head title="Notifications" />

            <PageHeader
                title="Notifications"
                subtitle="What has happened to work you are on. You are never notified about your own actions."
                actions={
                    <>
                        {unread > 0 && (
                            <Button variant="outline" onClick={() => router.post('/notifications/read-all')}>
                                <CheckCheck />
                                Mark all read
                            </Button>
                        )}
                        <Button variant="ghost" onClick={() => router.delete('/notifications')}>
                            <Trash2 />
                            Clear read
                        </Button>
                    </>
                }
            />

            <Card>
                {notifications.data.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title="Nothing yet"
                        description="You will hear when an interview is booked for you, when work arrives, and when something you sent is approved or sent back."
                    />
                ) : (
                    <>
                        <ul className="divide-y divide-border">
                            {notifications.data.map((notification) => {
                                const kind = KINDS[notification.kind] ?? {
                                    icon: Bell,
                                    className: 'bg-muted text-muted-foreground',
                                };
                                const Icon = kind.icon;

                                const body = (
                                    <div
                                        className={cn(
                                            'flex items-start gap-3 px-5 py-4 transition-colors',
                                            notification.url && 'hover:bg-muted/40',
                                            // Unread carries a brand-tinted rail
                                            // rather than a bold font: it reads at
                                            // a glance down a long list, where
                                            // weight alone does not.
                                            !notification.read && 'border-l-2 border-primary bg-primary/[0.03]',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'inline-flex size-9 shrink-0 items-center justify-center rounded-control',
                                                kind.className,
                                            )}
                                            aria-hidden="true"
                                        >
                                            <Icon className="size-4" />
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <p
                                                className={cn(
                                                    'text-sm',
                                                    notification.read ? 'text-foreground' : 'font-medium',
                                                )}
                                            >
                                                {notification.title}
                                            </p>
                                            {notification.body && (
                                                <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                    {notification.body}
                                                </p>
                                            )}
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {notification.actor ? `${notification.actor} · ` : ''}
                                                <span title={dateTime(notification.created_at)}>
                                                    {relative(notification.created_at)}
                                                </span>
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={(event) => {
                                                // The row is a link; dismissing
                                                // must not also navigate.
                                                event.preventDefault();
                                                event.stopPropagation();
                                                router.delete(`/notifications/${notification.id}`, {
                                                    preserveScroll: true,
                                                });
                                            }}
                                            className="shrink-0 rounded-control p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                            aria-label="Dismiss"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                );

                                return (
                                    <li key={notification.id}>
                                        {notification.url ? (
                                            <Link
                                                href={notification.url}
                                                // Opening it is what marks it read — a
                                                // separate "mark read" button is a
                                                // second click for something the first
                                                // click already proved.
                                                onClick={() =>
                                                    !notification.read &&
                                                    router.post(
                                                        `/notifications/${notification.id}/read`,
                                                        {},
                                                        { preserveScroll: true, preserveState: true },
                                                    )
                                                }
                                            >
                                                {body}
                                            </Link>
                                        ) : (
                                            body
                                        )}
                                    </li>
                                );
                            })}
                        </ul>

                        <Pagination paginator={notifications} />
                    </>
                )}
            </Card>
        </div>
    );
}

NotificationsIndex.layout = adminLayout;
